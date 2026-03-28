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
        $q = "SELECT jour, dju, conso_kg, conso_ecs_kg, conso_kwh,
                     cumul_kg, cumul_kwh, cumul_cycle, prix_kg, prix_kwh,
                     nb_cycle, tc_ext_max, tc_ext_min
              FROM oko_resume_day
              WHERE MONTH(jour) = {$month} AND YEAR(jour) = {$year}
              ORDER BY jour ASC";

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

            $days[] = [
                'date'         => $r->jour,
                'dju'          => isset($r->dju)          ? (float) $r->dju          : null,
                'conso_kg'     => isset($r->conso_kg)     ? (float) $r->conso_kg     : null,
                'conso_ecs_kg' => isset($r->conso_ecs_kg) ? (float) $r->conso_ecs_kg : null,
                'conso_kwh'    => isset($r->conso_kwh)    ? (float) $r->conso_kwh    : null,
                'cumul_kg'     => isset($r->cumul_kg)     ? (float) $r->cumul_kg     : null,
                'cumul_kwh'    => isset($r->cumul_kwh)    ? (float) $r->cumul_kwh    : null,
                'cumul_cycle'  => isset($r->cumul_cycle)  ? (int)   $r->cumul_cycle  : null,
                'prix_kg'      => isset($r->prix_kg)      ? (float) $r->prix_kg      : null,
                'prix_kwh'     => isset($r->prix_kwh)     ? (float) $r->prix_kwh     : null,
                'nb_cycle'     => isset($r->nb_cycle)     ? (int)   $r->nb_cycle     : null,
                'tc_ext_max'   => $tcMax,
                'tc_ext_min'   => $tcMin,
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
        'error'           => 'Missing action parameter',
        'available_actions' => ['today', 'daily', 'monthly', 'status'],
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

    default:
        http_response_code(400);
        echo json_encode([
            'error'            => 'Unknown action: ' . $action,
            'available_actions' => ['today', 'daily', 'monthly', 'status'],
        ]);
        break;
}
