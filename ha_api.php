<?php
/*
 * OkoVision – Home Assistant REST API
 *
 * Endpoint dédié à l'intégration Home Assistant (polling REST / HACS).
 * Authentification : même token que api.php (12 premiers caractères de TOKEN).
 *
 * Actions disponibles :
 *   ?token=XXXX&action=today
 *       → Données live du jour en cours (calculées depuis oko_historique_full)
 *         + état du silo et du cendrier
 *
 *   ?token=XXXX&action=daily&date=YYYY-MM-DD
 *       → Résumé d'une journée précise (depuis oko_resume_day)
 *
 *   ?token=XXXX&action=monthly&month=MM&year=YYYY
 *       → Tableau journalier complet du mois + totaux mensuels
 *
 *   ?token=XXXX&action=status
 *       → Niveau silo + cendrier uniquement
 *
 *   ?token=XXXX&action=live
 *       → Dernier snapshot temps-réel stocké en base (dernière ligne de
 *         oko_historique_full, quelle que soit sa date).
 *         Mise à jour à chaque appel de cron.php (fréquence : 1 min).
 */

include_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

/* ── Authentification ─────────────────────────────────────────────────────── */

function ha_is_valid()
{
    $apiToken = substr(TOKEN, 0, 12);
    $provided = '';
    if (isset($_GET['token'])) {
        $provided = $_GET['token'];
    } elseif (isset($_POST['token'])) {
        $provided = $_POST['token'];
    }
    return hash_equals($apiToken, $provided);
}

if (!ha_is_valid()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'code' => 401]);
    exit;
}

/* ── Classe helper ────────────────────────────────────────────────────────── */

/**
 * HaRendu étend rendu pour exposer les données brutes (sans sendResponse)
 * et ajoute les méthodes spécifiques à Home Assistant.
 */
class HaRendu extends rendu
{
    /* ── Données journalières (live depuis oko_historique_full) ──────────── */

    /**
     * Retourne le résumé live d'une journée calculé depuis les données brutes.
     * Utile pour le jour en cours avant la synthèse quotidienne.
     */
    public function getLiveDayData(string $jour): array
    {
        $max   = $this->getTcMaxByDay($jour);
        $min   = $this->getTcMinByDay($jour);
        $conso = $this->getConsoByday($jour);
        $ecs   = $this->getConsoByday($jour, null, null, 'hotwater');
        $cycle = $this->getNbCycleByDay($jour);

        $tcMax   = isset($max->tcExtMax) ? (float) $max->tcExtMax : null;
        $tcMin   = isset($min->tcExtMin) ? (float) $min->tcExtMin : null;
        $consoKg = isset($conso->consoPellet) ? (float) $conso->consoPellet : null;
        $nbCycle = isset($cycle->nbCycle)     ? (int)   $cycle->nbCycle     : 0;
        $consoKwh = $consoKg !== null
            ? round($consoKg * PCI_PELLET * (RENDEMENT_CHAUDIERE / 100), 2)
            : null;

        /* ── Dernier enregistrement connu (veille ou antérieur) ──────── */
        // Sert à la fois de base pour les cumulatifs ET de fallback pour les
        // champs non encore disponibles (entre 00h00 et l'import ~6h).
        $prevQ = "SELECT
                      IFNULL(cumul_kg,    0) AS c_kg,
                      IFNULL(cumul_kwh,   0) AS c_kwh,
                      IFNULL(cumul_cycle, 0) AS c_cycle,
                      prix_kg,
                      prix_kwh,
                      tc_ext_max,
                      tc_ext_min
                  FROM oko_resume_day
                  WHERE jour < '{$jour}'
                  ORDER BY jour DESC
                  LIMIT 1";
        $prevR = $this->query($prevQ);
        $prev  = $prevR ? $prevR->fetch_object() : null;

        /* ── Cumulatifs (base = veille + consommation du jour si dispo) ─ */
        $cumulKg    = round(($prev ? (float)$prev->c_kg    : 0) + ($consoKg   ?? 0), 2);
        $cumulKwh   = round(($prev ? (float)$prev->c_kwh   : 0) + ($consoKwh  ?? 0), 2);
        $cumulCycle = ($prev ? (int)$prev->c_cycle : 0) + $nbCycle;

        /* ── Prix au kg FIFO (estimation sur cumulatif live) ─────────── */
        $prixKgQ = "SELECT ROUND(e.price / e.quantity, 4) AS prix_kg
                    FROM (
                        SELECT e1.price, e1.quantity,
                               (SELECT SUM(e2.quantity) FROM oko_silo_events e2
                                WHERE e2.event_type='PELLET' AND e2.event_date <= e1.event_date
                               ) AS cumul_livraison
                        FROM oko_silo_events e1
                        WHERE e1.event_type = 'PELLET' AND e1.quantity > 0
                    ) e
                    WHERE e.cumul_livraison >= {$cumulKg}
                    ORDER BY e.cumul_livraison ASC
                    LIMIT 1";
        $prixKgR   = $this->query($prixKgQ);
        $prixKgRow = $prixKgR ? $prixKgR->fetch_object() : null;

        if (!$prixKgRow) {
            $lastQ     = "SELECT ROUND(price / quantity, 4) AS prix_kg FROM oko_silo_events
                          WHERE event_type='PELLET' AND quantity>0 ORDER BY event_date DESC LIMIT 1";
            $lastR     = $this->query($lastQ);
            $prixKgRow = $lastR ? $lastR->fetch_object() : null;
        }

        $prixKg       = $prixKgRow ? (float)$prixKgRow->prix_kg : null;
        $energieParKg = PCI_PELLET * RENDEMENT_CHAUDIERE / 100;
        $prixKwh      = ($prixKg !== null && $energieParKg > 0)
            ? round($prixKg / $energieParKg, 4)
            : null;

        /* ── Fallbacks pour les champs absents avant l'import du jour ── */
        // Champs cumulatifs / prix / températures : dernière valeur connue
        // Champs de consommation du jour          : 0 (rien consommé encore)
        $prevPrixKg  = ($prev && $prev->prix_kg  !== null) ? (float)$prev->prix_kg  : $prixKg;
        $prevPrixKwh = ($prev && $prev->prix_kwh !== null) ? (float)$prev->prix_kwh : $prixKwh;
        $prevTcMax   = ($prev && $prev->tc_ext_max !== null) ? (float)$prev->tc_ext_max : null;
        $prevTcMin   = ($prev && $prev->tc_ext_min !== null) ? (float)$prev->tc_ext_min : null;

        /* ── Coût cumulé (historique stocké + contribution du jour) ──── */
        $cumulCoutQ   = "SELECT ROUND(SUM(conso_kg * prix_kg), 2) AS cumul_cout
                         FROM oko_resume_day
                         WHERE jour < '{$jour}'";
        $cumulCoutR   = $this->query($cumulCoutQ);
        $cumulCoutRow = $cumulCoutR ? $cumulCoutR->fetch_object() : null;
        $cumulCoutHisto = ($cumulCoutRow && $cumulCoutRow->cumul_cout !== null)
            ? (float) $cumulCoutRow->cumul_cout : 0;
        $prixKgEffectif = $prixKg ?? $prevPrixKg;
        $cumulCout = round($cumulCoutHisto + ($consoKg ?? 0) * ($prixKgEffectif ?? 0), 2);

        return [
            'date'         => $jour,
            // dju : 0 si températures pas encore disponibles
            'dju'          => ($tcMax !== null && $tcMin !== null)
                                ? $this->getDju($tcMax, $tcMin)
                                : 0,
            // consommations du jour : 0 avant le premier import
            'conso_kg'     => $consoKg     ?? 0,
            'conso_ecs_kg' => isset($ecs->consoPellet) ? (float)$ecs->consoPellet : 0,
            'conso_kwh'    => $consoKwh    ?? 0,
            'nb_cycle'     => $nbCycle,
            // cumulatifs : base veille (inchangés tant qu'il n'y a pas d'import)
            'cumul_kg'     => $cumulKg,
            'cumul_kwh'    => $cumulKwh,
            'cumul_cycle'  => $cumulCycle,
            'cumul_cout'   => $cumulCout,
            // prix : FIFO live si conso disponible, sinon dernière valeur connue
            'prix_kg'      => $prixKgEffectif,
            'prix_kwh'     => $prixKwh     ?? $prevPrixKwh,
            // températures : mesure du jour si dispo, sinon dernière connue
            'tc_ext_max'   => $tcMax       ?? $prevTcMax,
            'tc_ext_min'   => $tcMin       ?? $prevTcMin,
        ];
    }

    /* ── Données journalières (résumé depuis oko_resume_day) ────────────── */

    /**
     * Résumé d'un jour précis issu de oko_resume_day (synthèse déjà calculée).
     */
    public function getResumeDay(string $jour): ?array
    {
        $jourEscaped = $this->escape($jour);
        $q = "SELECT d.jour, d.dju, d.conso_kg, d.conso_ecs_kg, d.conso_kwh,
                     d.cumul_kg, d.cumul_kwh, d.cumul_cycle, d.prix_kg, d.prix_kwh,
                     d.nb_cycle, d.tc_ext_max, d.tc_ext_min,
                     (SELECT ROUND(SUM(h.conso_kg * h.prix_kg), 2)
                      FROM oko_resume_day h
                      WHERE h.jour <= '{$jourEscaped}') AS cumul_cout
              FROM oko_resume_day d
              WHERE d.jour = '{$jourEscaped}'
              LIMIT 1";

        $this->log->debug('HaRendu::getResumeDay | ' . $q);
        $result = $this->query($q);
        $r = $result->fetch_object();

        if (!$r) {
            return null;
        }

        return [
            'date'         => $r->jour,
            'dju'          => isset($r->dju)          ? (float) $r->dju          : null,
            'conso_kg'     => isset($r->conso_kg)     ? (float) $r->conso_kg     : null,
            'conso_ecs_kg' => isset($r->conso_ecs_kg) ? (float) $r->conso_ecs_kg : null,
            'conso_kwh'    => isset($r->conso_kwh)    ? (float) $r->conso_kwh    : null,
            'cumul_kg'     => isset($r->cumul_kg)     ? (float) $r->cumul_kg     : null,
            'cumul_kwh'    => isset($r->cumul_kwh)    ? (float) $r->cumul_kwh    : null,
            'cumul_cycle'  => isset($r->cumul_cycle)  ? (int)   $r->cumul_cycle  : null,
            'cumul_cout'   => isset($r->cumul_cout)   ? (float) $r->cumul_cout   : null,
            'prix_kg'      => isset($r->prix_kg)      ? (float) $r->prix_kg      : null,
            'prix_kwh'     => isset($r->prix_kwh)     ? (float) $r->prix_kwh     : null,
            'nb_cycle'     => isset($r->nb_cycle)     ? (int)   $r->nb_cycle     : null,
            'tc_ext_max'   => isset($r->tc_ext_max)   ? (float) $r->tc_ext_max   : null,
            'tc_ext_min'   => isset($r->tc_ext_min)   ? (float) $r->tc_ext_min   : null,
        ];
    }

    /* ── Résumé journalier avec fallback si synthèse absente ────────────── */

    /**
     * Retourne le résumé d'un jour depuis oko_resume_day.
     * Si la synthèse n'existe pas encore (typiquement : hier avant ~6h),
     * retourne un placeholder à zéro avec les cumulatifs/prix/températures
     * du dernier jour connu, et is_new = false.
     * Si la synthèse existe, is_new = true.
     */
    public function getResumeDayWithFallback(string $jour): array
    {
        $data = $this->getResumeDay($jour);

        if ($data !== null) {
            $data['is_new'] = true;
            return $data;
        }

        // Synthèse absente : charger le dernier jour connu avant $jour
        $jourEscaped = $this->escape($jour);
        $prevQ = "SELECT d.jour,
                         IFNULL(d.cumul_kg,    0) AS cumul_kg,
                         IFNULL(d.cumul_kwh,   0) AS cumul_kwh,
                         IFNULL(d.cumul_cycle, 0) AS cumul_cycle,
                         d.prix_kg, d.prix_kwh, d.tc_ext_max, d.tc_ext_min,
                         (SELECT ROUND(SUM(h.conso_kg * h.prix_kg), 2)
                          FROM oko_resume_day h
                          WHERE h.jour <= d.jour) AS cumul_cout
                  FROM oko_resume_day d
                  WHERE d.jour < '{$jourEscaped}'
                  ORDER BY d.jour DESC
                  LIMIT 1";

        $this->log->debug('HaRendu::getResumeDayWithFallback (prev) | ' . $prevQ);
        $prevR = $this->query($prevQ);
        $prev  = $prevR ? $prevR->fetch_object() : null;

        return [
            'date'         => $jour,
            'dju'          => 0,
            'conso_kg'     => 0,
            'conso_ecs_kg' => 0,
            'conso_kwh'    => 0,
            'nb_cycle'     => 0,
            'cumul_kg'     => $prev ? (float) $prev->cumul_kg    : 0,
            'cumul_kwh'    => $prev ? (float) $prev->cumul_kwh   : 0,
            'cumul_cycle'  => $prev ? (int)   $prev->cumul_cycle : 0,
            'cumul_cout'   => ($prev && $prev->cumul_cout !== null) ? (float) $prev->cumul_cout : 0,
            'prix_kg'      => ($prev && $prev->prix_kg   !== null) ? (float) $prev->prix_kg    : null,
            'prix_kwh'     => ($prev && $prev->prix_kwh  !== null) ? (float) $prev->prix_kwh   : null,
            'tc_ext_max'   => ($prev && $prev->tc_ext_max !== null) ? (float) $prev->tc_ext_max : null,
            'tc_ext_min'   => ($prev && $prev->tc_ext_min !== null) ? (float) $prev->tc_ext_min : null,
            'is_new'       => false,
        ];
    }

    /* ── Données mensuelles ──────────────────────────────────────────────── */

    /**
     * Retourne tous les jours d'un mois depuis oko_resume_day + totaux.
     */
    public function getMonthData(int $month, int $year): array
    {
        $eventType   = HAS_SILO ? 'PELLET' : 'BAG';
        $siloSize    = HAS_SILO && SILO_SIZE ? (float) SILO_SIZE : 0;
        $ashtraySize = ASHTRAY !== '' ? (float) ASHTRAY : 0;

        /* Sous-requêtes corrélées pour silo et cendrier au jour J :
         * - silo    : dernière livraison <= J → (qty+remaining) − conso depuis cette livraison
         * - cendrier: dernier vidage     <= J → ASHTRAY − conso depuis ce vidage            */
        $q = "SELECT d.jour, d.dju, d.conso_kg, d.conso_ecs_kg, d.conso_kwh,
                     d.cumul_kg, d.cumul_kwh, d.cumul_cycle, d.prix_kg, d.prix_kwh,
                     d.nb_cycle, d.tc_ext_max, d.tc_ext_min,
                     (SELECT GREATEST(0, ROUND(
                              (e.quantity + e.remaining) -
                              IFNULL((SELECT SUM(r2.conso_kg) FROM oko_resume_day r2
                                      WHERE r2.jour > e.event_date AND r2.jour <= d.jour), 0)))
                      FROM oko_silo_events e
                      WHERE e.event_type = '{$eventType}' AND e.event_date <= d.jour
                      ORDER BY e.event_date DESC LIMIT 1) AS silo_restants_kg,
                     (SELECT GREATEST(0, ROUND(
                              {$ashtraySize} -
                              IFNULL((SELECT SUM(r2.conso_kg) FROM oko_resume_day r2
                                      WHERE r2.jour > e.event_date AND r2.jour <= d.jour), 0), 2))
                      FROM oko_silo_events e
                      WHERE e.event_type = 'ASHES' AND e.event_date <= d.jour
                      ORDER BY e.event_date DESC LIMIT 1) AS cendrier_restant_kg
              FROM oko_resume_day d
              WHERE MONTH(d.jour) = {$month} AND YEAR(d.jour) = {$year}
              ORDER BY d.jour ASC";

        $this->log->debug('HaRendu::getMonthData | ' . $q);
        $result = $this->query($q);

        $days        = [];
        $totalDju    = 0;
        $totalConso  = 0;
        $totalEcs    = 0;
        $totalKwh    = 0;
        $totalCycles = 0;
        $maxTcMax    = null;
        $minTcMin    = null;

        while ($r = $result->fetch_object()) {
            $tcMax = isset($r->tc_ext_max) ? (float) $r->tc_ext_max : null;
            $tcMin = isset($r->tc_ext_min) ? (float) $r->tc_ext_min : null;

            $siloRestant  = isset($r->silo_restants_kg)   ? (float) $r->silo_restants_kg   : null;
            $cendRestant  = isset($r->cendrier_restant_kg) ? (float) $r->cendrier_restant_kg : null;
            $siloNiveau   = ($siloRestant !== null && $siloSize > 0)
                ? min(100, (int) round(100 * $siloRestant / $siloSize)) : null;
            $cendNiveau   = ($cendRestant !== null && $ashtraySize > 0)
                ? min(100, (int) round(100 * ($ashtraySize - $cendRestant) / $ashtraySize)) : null;

            $days[] = [
                'date'                          => $r->jour,
                'dju'                           => isset($r->dju)          ? (float) $r->dju          : null,
                'conso_kg'                      => isset($r->conso_kg)     ? (float) $r->conso_kg     : null,
                'conso_ecs_kg'                  => isset($r->conso_ecs_kg) ? (float) $r->conso_ecs_kg : null,
                'conso_kwh'                     => isset($r->conso_kwh)    ? (float) $r->conso_kwh    : null,
                'cumul_kg'                      => isset($r->cumul_kg)     ? (float) $r->cumul_kg     : null,
                'cumul_kwh'                     => isset($r->cumul_kwh)    ? (float) $r->cumul_kwh    : null,
                'cumul_cycle'                   => isset($r->cumul_cycle)  ? (int)   $r->cumul_cycle  : null,
                'prix_kg'                       => isset($r->prix_kg)      ? (float) $r->prix_kg      : null,
                'prix_kwh'                      => isset($r->prix_kwh)     ? (float) $r->prix_kwh     : null,
                'nb_cycle'                      => isset($r->nb_cycle)     ? (int)   $r->nb_cycle     : null,
                'tc_ext_max'                    => $tcMax,
                'tc_ext_min'                    => $tcMin,
                'silo_pellets_restants'         => $siloRestant,
                'silo_niveau'                   => $siloNiveau,
                'cendrier_capacite_restante'    => $cendRestant,
                'cendrier_niveau_de_remplissage'=> $cendNiveau,
            ];

            $totalDju    += isset($r->dju)          ? (float) $r->dju          : 0;
            $totalConso  += isset($r->conso_kg)     ? (float) $r->conso_kg     : 0;
            $totalEcs    += isset($r->conso_ecs_kg) ? (float) $r->conso_ecs_kg : 0;
            $totalKwh    += isset($r->conso_kwh)    ? (float) $r->conso_kwh    : 0;
            $totalCycles += isset($r->nb_cycle)     ? (int)   $r->nb_cycle     : 0;

            if ($tcMax !== null && ($maxTcMax === null || $tcMax > $maxTcMax)) {
                $maxTcMax = $tcMax;
            }
            if ($tcMin !== null && ($minTcMin === null || $tcMin < $minTcMin)) {
                $minTcMin = $tcMin;
            }
        }

        return [
            'month'  => $month,
            'year'   => $year,
            'totals' => [
                'dju'          => round($totalDju, 2),
                'conso_kg'     => round($totalConso, 2),
                'conso_ecs_kg' => round($totalEcs, 2),
                'conso_kwh'    => round($totalKwh, 2),
                'nb_cycle'     => $totalCycles,
                'tc_ext_max'   => $maxTcMax,
                'tc_ext_min'   => $minTcMin,
            ],
            'days' => $days,
        ];
    }

    /* ── Silo ────────────────────────────────────────────────────────────── */

    public function getSiloData(): array
    {
        if (HAS_SILO && !SILO_SIZE) {
            return ['error' => 'no_silo_size'];
        }

        $eventType = HAS_SILO ? 'PELLET' : 'BAG';
        $q = "SELECT event_date as date_last_fill, (quantity + remaining) as pellet_quantity
              FROM oko_silo_events
              WHERE event_type='{$eventType}'
              ORDER BY event_date DESC LIMIT 1";

        $this->log->debug('HaRendu::getSiloData | ' . $q);
        $result = $this->query($q);
        $r = $result->fetch_object();

        if (empty($r->date_last_fill)) {
            return ['error' => 'no_fill_date'];
        }

        $pelletQty = (float) $r->pellet_quantity;

        $q2 = "SELECT sum(conso_kg) as consoPellet
               FROM oko_resume_day
               WHERE oko_resume_day.jour > '" . $r->date_last_fill . "'";

        $this->log->debug('HaRendu::getSiloData | ' . $q2);
        $result2 = $this->query($q2);
        $r2 = $result2->fetch_object();

        $consumed   = isset($r2->consoPellet) ? (float) $r2->consoPellet : 0;
        $remains    = round($pelletQty - $consumed);
        $totalMax   = HAS_SILO ? (float) SILO_SIZE : $pelletQty;
        $percent    = $totalMax > 0 ? round(100 * $remains / $totalMax) : 0;

        return [
            'remains_kg'     => $remains,
            'capacity_kg'    => $totalMax,
            'percent'        => $percent,
            'last_fill_date' => $r->date_last_fill,
        ];
    }

    /* ── Cendrier ────────────────────────────────────────────────────────── */

    public function getAshtrayData(): array
    {
        if (ASHTRAY == '') {
            return ['error' => 'no_ashtray_info'];
        }

        $q = "SELECT max(event_date) as date_emptied FROM oko_silo_events WHERE event_type='ASHES'";

        $this->log->debug('HaRendu::getAshtrayData | ' . $q);
        $result = $this->query($q);
        $r = $result->fetch_object();

        if (empty($r->date_emptied)) {
            return ['error' => 'no_date_emptied'];
        }

        $q2 = "SELECT sum(conso_kg) as consoPellet
               FROM oko_resume_day
               WHERE oko_resume_day.jour > '" . $r->date_emptied . "'";

        $this->log->debug('HaRendu::getAshtrayData | ' . $q2);
        $result2 = $this->query($q2);
        $r2 = $result2->fetch_object();

        $consumed      = isset($r2->consoPellet) ? (float) $r2->consoPellet : 0;
        $capacity      = (float) ASHTRAY;
        $remains       = round($capacity - $consumed, 2);
        $percent       = $capacity > 0 ? round(100 * $consumed / $capacity) : 0;
        $needsEmptying = $remains <= 0;

        return [
            'remains_kg'     => max($remains, 0),
            'capacity_kg'    => $capacity,
            'percent'        => max($percent, 0),
            'needs_emptying' => $needsEmptying,
            'last_empty_date'=> $r->date_emptied,
        ];
    }

    /* ── Maintenance ─────────────────────────────────────────────────────── */

    /**
     * Retourne la date du dernier ramonage (SWEEP) et du dernier entretien (MAINT).
     */
    public function getMaintenanceData(): array
    {
        $q = "SELECT
                MAX(CASE WHEN event_type='SWEEP' THEN event_date END) as last_sweep,
                MAX(CASE WHEN event_type='MAINT'  THEN event_date END) as last_maintenance
              FROM oko_silo_events";

        $this->log->debug('HaRendu::getMaintenanceData | ' . $q);
        $result = $this->query($q);
        $r = $result->fetch_object();

        return [
            'last_sweep'       => $r->last_sweep       ?? null,
            'last_maintenance' => $r->last_maintenance ?? null,
        ];
    }

    /* ── Snapshot temps-réel (dernière ligne de oko_historique_full) ───────── */

    /**
     * Retourne le dernier snapshot stocké en base, quelle que soit sa date.
     * Les colonnes sont résolues dynamiquement via capteur::getForImportCsv()
     * pour être indépendant de la configuration de l'installation.
     *
     * Positions CSV utilisées (cf. storeLiveSnapshot / matrice.csv) :
     *   2=AT  4=PE1_BR1  5=HK1_VL_Ist  6=HK1_VL_Soll  7=HK1_RT_Ist
     *   8=HK1_RT_Soll  9=HK1_Pompe  12=HK1_Status  13=PE1_KT  14=PE1_KT_SOLL
     *   15=UW_Freigabe  16=Modulation  17=FRT_Ist  18=FRT_Soll  19=FRT_End
     *   20=Einschublaufzeit  21=Pausenzeit  22=Luefterdrehzahl  23=Saugzugdrehzahl
     *   24=Unterdruck_Ist  25=Unterdruck_Soll  26=Fuellstand  27=Fuellstand_ZWB
     *   28=PE1_Status  43=PE1_AK
     *
     * @return array|null  null si aucune donnée en base
     */
    public function getLiveSnapshot(): ?array
    {
        $ob_capteur = new capteur();
        $capteurs   = $ob_capteur->getForImportCsv(); // indexé par position CSV
        unset($ob_capteur);

        // Résolution dynamique : position CSV → nom de colonne DB (col_X)
        $col = function (int $csvPos) use ($capteurs): ?string {
            return isset($capteurs[$csvPos]) ? 'col_'.$capteurs[$csvPos]['column_oko'] : null;
        };

        // Correspondance alias → position CSV (d'après matrice.csv / storeLiveSnapshot)
        $posMap = [
            'outdoor'        => 2,   // AT [°C]
            'pe1_br'         => 4,   // PE1 BR1 — allumage (bool)
            'flow_act'       => 5,   // HK1 VL Ist [°C]
            'flow_set'       => 6,   // HK1 VL Soll [°C]
            'room_act'       => 7,   // HK1 RT Ist [°C]
            'room_set'       => 8,   // HK1 RT Soll [°C]
            'pump_on'        => 9,   // HK1 Pompe (bool)
            'circuit_state'  => 12,  // HK1 Status
            'boiler_act'     => 13,  // PE1 KT [°C]
            'boiler_set'     => 14,  // PE1 KT_SOLL [°C]
            'uw_release'     => 15,  // PE1 UW Freigabe [°C]
            'modulation'     => 16,  // PE1 Modulation [%]
            'frt_act'        => 17,  // PE1 FRT Ist [°C]
            'frt_set'        => 18,  // PE1 FRT Soll [°C]
            'frt_end'        => 19,  // PE1 FRT End [°C]
            'feed_time'      => 20,  // PE1 Einschublaufzeit [s]
            'pause_time'     => 21,  // PE1 Pausenzeit [s]
            'fan_speed'      => 22,  // PE1 Luefterdrehzahl [%]
            'flue_speed'     => 23,  // PE1 Saugzugdrehzahl [%]
            'draft_act'      => 24,  // PE1 Unterdruck Ist [EH]
            'draft_set'      => 25,  // PE1 Unterdruck Soll [EH]
            'storage_fill'   => 26,  // PE1 Fuellstand [kg]
            'storage_popper' => 27,  // PE1 Fuellstand ZWB [kg]
            'pe1_state'      => 28,  // PE1 Status (code brut)
            'pe1_ak'         => 43,  // PE1 AK
        ];

        // Construction du SELECT dynamique
        $selectParts = ['jour', 'heure', 'timestamp'];
        foreach ($posMap as $alias => $csvPos) {
            $colName = $col($csvPos);
            if ($colName !== null) {
                $selectParts[] = $colName.' AS '.$alias;
            }
        }

        $q = 'SELECT '.implode(', ', $selectParts).'
              FROM oko_historique_full
              ORDER BY timestamp DESC
              LIMIT 1';

        $this->log->debug('HaRendu::getLiveSnapshot | '.$q);
        $result = $this->query($q);
        if (!$result) {
            return null;
        }
        $r = $result->fetch_object();
        if (!$r) {
            return null;
        }

        // Timestamp ISO 8601 (avec fuseau horaire serveur)
        $isoTs = null;
        if (!empty($r->timestamp)) {
            $dt = new \DateTime('@'.(int) $r->timestamp);
            $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $isoTs = $dt->format(\DateTime::ATOM);
        }

        $fv = function ($prop) use ($r): ?float {
            return isset($r->{$prop}) ? (float) $r->{$prop} : null;
        };
        $iv = function ($prop) use ($r): ?int {
            return isset($r->{$prop}) ? (int) $r->{$prop} : null;
        };

        return [
            'timestamp'      => $isoTs,
            'boiler_running' => isset($r->pe1_br)    ? ((int) $r->pe1_br !== 0)    : null,
            'boiler_state'   => $iv('pe1_state'),
            'temperatures'   => [
                'outdoor'       => $fv('outdoor'),
                'boiler_actual' => $fv('boiler_act'),
                'boiler_target' => $fv('boiler_set'),
                'flow_actual'   => $fv('flow_act'),
                'flow_target'   => $fv('flow_set'),
                'room_actual'   => $fv('room_act'),
                'room_target'   => $fv('room_set'),
                'frt_actual'    => $fv('frt_act'),
                'frt_target'    => $fv('frt_set'),
                'frt_end'       => $fv('frt_end'),
                'uw_release'    => $fv('uw_release'),
            ],
            'combustion'     => [
                'modulation'            => $iv('modulation'),
                'fan_speed'             => $iv('fan_speed'),
                'flue_draft_speed'      => $iv('flue_speed'),
                'draft_pressure_actual' => $fv('draft_act'),
                'draft_pressure_target' => $fv('draft_set'),
                'feed_time'             => $fv('feed_time'),
                'pause_time'            => $fv('pause_time'),
            ],
            'circuit'        => [
                'pump_on' => isset($r->pump_on)      ? ((int) $r->pump_on !== 0)  : null,
                'state'   => $iv('circuit_state'),
            ],
            'pellets'        => [
                'storage_fill_kg'   => $iv('storage_fill'),
                'storage_popper_kg' => $iv('storage_popper'),
            ],
            'status'         => [
                'pe1_ak' => $iv('pe1_ak'),
            ],
        ];
    }

    /* ── Escape helper ───────────────────────────────────────────────────── */

    private function escape(string $value): string
    {
        return $this->realEscapeString($value);
    }
}

/* ── Routing ──────────────────────────────────────────────────────────────── */

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if (empty($action)) {
    http_response_code(400);
    echo json_encode([
        'error'             => 'Missing action parameter',
        'available_actions' => ['today', 'daily', 'monthly', 'status', 'live'],
    ]);
    exit;
}

$ha = new HaRendu();

switch ($action) {
    /* ── today ──────────────────────────────────────────────────────────── */
    // L'application ne connaît jamais les données du jour courant (import ~6h).
    // "today" retourne donc la synthèse d'hier avec fallback si absent.
    case 'today':
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $dayData   = $ha->getResumeDayWithFallback($yesterday);

        echo json_encode(array_merge($dayData, [
            'silo'        => $ha->getSiloData(),
            'ashtray'     => $ha->getAshtrayData(),
            'maintenance' => $ha->getMaintenanceData(),
        ]), JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        break;

    /* ── daily ───────────────────────────────────────────────────────────── */
    case 'daily':
        if (empty($_GET['date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing date parameter (YYYY-MM-DD)']);
            exit;
        }

        $date = preg_replace('/[^0-9\-]/', '', $_GET['date']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format, expected YYYY-MM-DD']);
            exit;
        }

        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($date >= $yesterday) {
            // Aujourd'hui ou hier : fallback si synthèse absente
            $data = $ha->getResumeDayWithFallback($yesterday);
        } else {
            // Jour passé : données archivées uniquement
            $data = $ha->getResumeDay($date);
            if ($data === null) {
                http_response_code(404);
                echo json_encode(['error' => 'No data found for ' . $date]);
                exit;
            }
            $data['is_new'] = true;
        }

        echo json_encode($data, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        break;

    /* ── monthly ─────────────────────────────────────────────────────────── */
    case 'monthly':
        $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
        $year  = isset($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y');

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid month or year']);
            exit;
        }

        echo json_encode($ha->getMonthData($month, $year), JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        break;

    /* ── status ──────────────────────────────────────────────────────────── */
    case 'status':
        echo json_encode([
            'silo'        => $ha->getSiloData(),
            'ashtray'     => $ha->getAshtrayData(),
            'maintenance' => $ha->getMaintenanceData(),
        ], JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        break;

    /* ── live ───────────────────────────────────────────────────────────── */
    case 'live':
        $snapshot = $ha->getLiveSnapshot();
        if ($snapshot === null) {
            http_response_code(404);
            echo json_encode(['error' => 'No live data available yet']);
            exit;
        }
        echo json_encode($snapshot, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'error'             => 'Unknown action: ' . $action,
            'available_actions' => ['today', 'daily', 'monthly', 'status', 'live'],
        ]);
        break;
}
