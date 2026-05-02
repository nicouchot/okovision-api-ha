<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class rendu extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getGrapheData(int $id, string $jour): void
    {
        $result = $this->prepare(
            'SELECT capteur.name AS name, capteur.id AS id, asso.correction_effect AS coeff FROM oko_asso_capteur_graphe AS asso LEFT JOIN oko_capteur AS capteur ON capteur.id = asso.oko_capteur_id WHERE asso.oko_graphe_id=? ORDER BY asso.position',
            [$id]
        );

        $resultat = '';
        $cap = new capteur();
        $jourSafe = $this->realEscapeString($jour);

        if ($result instanceof \mysqli_result) {
            while ($c = $result->fetch_object()) {
                $capteur = $cap->get((int) $c->id);

                $q = 'SELECT timestamp * 1000 AS timestamp, ROUND((col_'.$capteur['column_oko'].' * '.$c->coeff.'),2) AS value FROM oko_historique_full '
                     ."WHERE jour = '".$jourSafe."'";

                $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$c->name.' | '.$q);
                $res = $this->query($q);

                $data = '';
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_object()) {
                        if ($r->value !== null) {
                            $data .= '['.$r->timestamp.','.$r->value.'],';
                        }
                    }
                    $data = rtrim($data, ',');
                }

                $resultat .= '{ "name": "'.e($c->name).'",';
                $resultat .= '"data": ['.$data.']';
                $resultat .= '},';
            }
        }

        $resultat = rtrim($resultat, ',');
        $this->sendResponse('{ "grapheData": ['.$resultat.']}');
    }

    public function getIndicByDay(string $jour, mixed $timeStart = null, mixed $timeEnd = null): void
    {
        if ($timeStart !== null && $timeEnd !== null) {
            $timeStart = (int) ($timeStart / 1000);
            $timeEnd = (int) ($timeEnd / 1000);
        }

        $c = $this->getConsoByday($jour, $timeStart, $timeEnd);
        $c_ecs = $this->getConsoByday($jour, $timeStart, $timeEnd, 'hotwater');
        $min = $this->getTcMinByDay($jour, $timeStart, $timeEnd);
        $max = $this->getTcMaxByDay($jour, $timeStart, $timeEnd);

        $this->sendResponse(json_encode([
            'consoPellet'        => $c->consoPellet,
            'consoPelletHotwater' => $c_ecs->consoPellet,
            'tcExtMax'           => $max->tcExtMax,
            'tcExtMin'           => $min->tcExtMin,
        ], JSON_NUMERIC_CHECK));
    }

    public function getConsoByday(string $jour, mixed $timeStart = null, mixed $timeEnd = null, ?string $type = null): mixed
    {
        $coeff = POIDS_PELLET_PAR_MINUTE / 1000;
        $c = new capteur();
        $capteur_vis = $c->getByType('tps_vis');
        $capteur_vis_pause = $c->getByType('tps_vis_pause');

        $intervalle = '';
        if ($timeStart !== null && $timeEnd !== null) {
            $intervalle = 'AND timestamp BETWEEN '.(int) $timeStart.' AND '.(int) $timeEnd;
        }

        $usage = '';
        if ($type === 'hotwater') {
            $capteur_ecs = $c->getByType('hotwater[0]');
            if ($capteur_ecs === null) {
                return (object) ['consoPellet' => null];
            }
            $usage = ' AND a.col_'.$capteur_ecs['column_oko'].' = 1';
        }

        $jourSafe = $this->realEscapeString($jour);
        $q = 'SELECT ROUND(SUM((1/(a.col_'.$capteur_vis['column_oko'].' + a.col_'.$capteur_vis_pause['column_oko'].')) * a.col_'.$capteur_vis['column_oko'].')*('.$coeff.'),2) AS consoPellet FROM oko_historique_full AS a '
             ."WHERE a.jour = '".$jourSafe."' ".$usage.' '.$intervalle;

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        return ($result instanceof \mysqli_result) ? $result->fetch_object() : (object) ['consoPellet' => null];
    }

    public function getTcMaxByDay(string $jour, mixed $timeStart = null, mixed $timeEnd = null): mixed
    {
        $c = new capteur();
        $capteur = $c->getByType('tc_ext');

        $intervalle = '';
        if ($timeStart !== null && $timeEnd !== null) {
            $intervalle = 'AND timestamp BETWEEN '.(int) $timeStart.' AND '.(int) $timeEnd;
        }

        $jourSafe = $this->realEscapeString($jour);
        $q = 'SELECT ROUND(MAX(a.col_'.$capteur['column_oko'].'),2) AS tcExtMax FROM oko_historique_full AS a '
             ."WHERE a.jour = '".$jourSafe."' ".$intervalle;

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        return ($result instanceof \mysqli_result) ? $result->fetch_object() : (object) ['tcExtMax' => null];
    }

    public function getTcMinByDay(string $jour, mixed $timeStart = null, mixed $timeEnd = null): mixed
    {
        $c = new capteur();
        $capteur = $c->getByType('tc_ext');

        $intervalle = '';
        if ($timeStart !== null && $timeEnd !== null) {
            $intervalle = 'AND timestamp BETWEEN '.(int) $timeStart.' AND '.(int) $timeEnd;
        }

        $jourSafe = $this->realEscapeString($jour);
        $q = 'SELECT ROUND(MIN(a.col_'.$capteur['column_oko'].'),2) AS tcExtMin FROM oko_historique_full AS a '
             ."WHERE a.jour = '".$jourSafe."' ".$intervalle;

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        return ($result instanceof \mysqli_result) ? $result->fetch_object() : (object) ['tcExtMin' => null];
    }

    public function getDju(mixed $tcMax, mixed $tcMin): float
    {
        $tcMoy = ((float) $tcMax + (float) $tcMin) / 2;

        if (TC_REF <= $tcMoy) {
            return 0.0;
        }

        return round(TC_REF - $tcMoy, 2);
    }

    public function getNbCycleByDay(string $jour): mixed
    {
        $c = new capteur();
        $capteur = $c->getByType('startCycle');

        $jourSafe = $this->realEscapeString($jour);
        $q = 'SELECT SUM(a.col_'.$capteur['column_oko'].') AS nbCycle FROM oko_historique_full AS a '
             ."WHERE a.jour = '".$jourSafe."'";

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        return ($result instanceof \mysqli_result) ? $result->fetch_object() : (object) ['nbCycle' => null];
    }

    public function getIndicByMonth(int $month, int $year): void
    {
        $q = 'SELECT MAX(Tc_ext_max) AS tcExtMax, MIN(Tc_ext_min) AS tcExtMin, '
           . 'SUM(conso_kg) AS consoPellet, SUM(conso_ecs_kg) AS consoEcsPellet, SUM(dju) AS dju, SUM(nb_cycle) AS nbCycle, '
           . 'SUM(conso_kwh) AS consoKwh, ROUND(SUM(conso_kg * prix_kg), 2) AS coutMois '
           . 'FROM oko_resume_day '
           . 'WHERE MONTH(oko_resume_day.jour) = '.$month.' AND YEAR(oko_resume_day.jour) = '.$year;

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
        $r = ($result instanceof \mysqli_result) ? $result->fetch_object() : null;

        $this->sendResponse(json_encode([
            'tcExtMax'       => $r->tcExtMax      ?? null,
            'tcExtMin'       => $r->tcExtMin      ?? null,
            'consoPellet'    => $r->consoPellet   ?? null,
            'consoEcsPellet' => $r->consoEcsPellet ?? null,
            'dju'            => $r->dju           ?? null,
            'nbCycle'        => $r->nbCycle       ?? null,
            'consoKwh'       => $r->consoKwh      ?? null,
            'coutMois'       => $r->coutMois      ?? null,
        ], JSON_NUMERIC_CHECK));
    }

    public function getStockStatus(): void
    {
        if (HAS_SILO && !SILO_SIZE) {
            $this->sendResponse(json_encode(['no_silo_size' => true]));

            return;
        }

        $eventType = HAS_SILO ? 'PELLET' : 'BAG';
        $result = $this->prepare(
            'SELECT event_date AS date_last_fill, (quantity + remaining) AS pellet_quantity FROM oko_silo_events WHERE event_type=? ORDER BY event_date DESC LIMIT 1',
            [$eventType]
        );

        if (!$result instanceof \mysqli_result) {
            return;
        }

        $r = $result->fetch_object();

        if (empty($r->date_last_fill)) {
            $this->sendResponse(json_encode(['no_fill_date' => true]));

            return;
        }

        $pelletQuantity = (float) $r->pellet_quantity;

        $result2 = $this->prepare(
            'SELECT SUM(conso_kg) AS consoPellet FROM oko_resume_day WHERE oko_resume_day.jour > ?',
            [$r->date_last_fill]
        );

        $r2 = ($result2 instanceof \mysqli_result) ? $result2->fetch_object() : null;
        $remains = round($pelletQuantity - (float) ($r2->consoPellet ?? 0));
        $totalStockMax = HAS_SILO ? (float) SILO_SIZE : $pelletQuantity;
        $percent = round(100 * $remains / $totalStockMax);

        $this->sendResponse(json_encode(['remains' => $remains, 'percent' => $percent], JSON_NUMERIC_CHECK));
    }

    public function getAshtrayStatus(): void
    {
        if (ASHTRAY == '') {
            $this->sendResponse(json_encode(['no_ashtray_info' => true]));

            return;
        }

        $result = $this->prepare("SELECT MAX(event_date) AS date_emptied_ashtray FROM oko_silo_events WHERE event_type='ASHES'");

        if (!$result instanceof \mysqli_result) {
            return;
        }

        $r = $result->fetch_object();

        if (empty($r->date_emptied_ashtray)) {
            $this->sendResponse(json_encode(['no_date_emptied_ashtray' => true]));

            return;
        }

        $result2 = $this->prepare(
            'SELECT SUM(conso_kg) AS consoPellet FROM oko_resume_day WHERE oko_resume_day.jour > ?',
            [$r->date_emptied_ashtray]
        );

        $r2 = ($result2 instanceof \mysqli_result) ? $result2->fetch_object() : null;
        $remain = (float) ASHTRAY - (float) ($r2->consoPellet ?? 0);

        if ($remain <= 0) {
            $this->sendResponse(json_encode(['emptying_ashtrey' => true]));
        }
    }

    public function getHistoByMonth(int $month, int $year): void
    {
        $categorie = [
            session::getInstance()->getLabel('lang.text.graphe.label.tcmax')   => 'tc_ext_max',
            session::getInstance()->getLabel('lang.text.graphe.label.tcmin')   => 'tc_ext_min',
            session::getInstance()->getLabel('lang.text.graphe.label.conso')   => 'conso_kg',
            session::getInstance()->getLabel('lang.text.graphe.label.dju')     => 'dju',
            session::getInstance()->getLabel('lang.text.graphe.label.nbcycle') => 'nb_cycle',
            'kWh'                                                               => 'conso_kwh',
        ];

        $where = 'FROM oko_resume_day '
               . 'RIGHT JOIN oko_dateref ON oko_resume_day.jour = oko_dateref.jour '
               . 'WHERE MONTH(oko_dateref.jour) = '.$month.' AND YEAR(oko_dateref.jour) = '.$year.' '
               . 'ORDER BY oko_dateref.jour ASC';

        $resultat = [];

        foreach ($categorie as $label => $colonneSql) {
            $q = 'SELECT '.$colonneSql.' '.$where;
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

            $result = $this->query($q);
            $data = [];
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_row()) {
                    $data[] = $r[0];
                }
            }

            $resultat[] = ['name' => $label, 'data' => $data];
        }

        $this->sendResponse(json_encode($resultat, JSON_NUMERIC_CHECK));
    }

    public function getTotalSaison(int $idSaison): void
    {
        $result = $this->prepare(
            'SELECT MAX(Tc_ext_max) AS tcExtMax, MIN(Tc_ext_min) AS tcExtMin, SUM(conso_kg) AS consoPellet, SUM(conso_ecs_kg) AS consoEcsPellet, SUM(dju) AS dju, SUM(nb_cycle) AS nbCycle, SUM(conso_kwh) AS consoKwh, ROUND(SUM(conso_kg * prix_kg), 2) AS coutSaison FROM oko_resume_day, oko_saisons WHERE oko_saisons.id=? AND oko_resume_day.jour BETWEEN oko_saisons.date_debut AND oko_saisons.date_fin',
            [$idSaison]
        );

        $r = ($result instanceof \mysqli_result) ? $result->fetch_object() : null;

        $this->sendResponse(json_encode([
            'tcExtMax'       => $r->tcExtMax       ?? null,
            'tcExtMin'       => $r->tcExtMin       ?? null,
            'consoPellet'    => $r->consoPellet    ?? null,
            'consoEcsPellet' => $r->consoEcsPellet ?? null,
            'dju'            => $r->dju            ?? null,
            'nbCycle'        => $r->nbCycle        ?? null,
            'consoKwh'       => $r->consoKwh       ?? null,
            'coutSaison'     => $r->coutSaison     ?? null,
        ], JSON_NUMERIC_CHECK));
    }

    public function getSyntheseSaison(int $idSaison): void
    {
        $categorie = [
            session::getInstance()->getLabel('lang.text.graphe.label.tcmax')     => 'MAX(Tc_ext_max)',
            session::getInstance()->getLabel('lang.text.graphe.label.tcmin')     => 'MIN(Tc_ext_min)',
            session::getInstance()->getLabel('lang.text.graphe.label.conso')     => 'SUM(conso_kg)',
            session::getInstance()->getLabel('lang.text.graphe.label.dju')       => 'SUM(dju)',
            session::getInstance()->getLabel('lang.text.graphe.label.nbcycle')   => 'SUM(nb_cycle)',
            session::getInstance()->getLabel('lang.text.graphe.label.conso.ecs') => 'SUM(conso_ecs_kg)',
            'kWh'                                                                 => 'SUM(conso_kwh)',
        ];

        $where = ", DATE_FORMAT(oko_dateref.jour,'%Y-%m-01 00:00:00') FROM oko_saisons, oko_resume_day "
               . 'RIGHT JOIN oko_dateref ON oko_dateref.jour = oko_resume_day.jour '
               . 'WHERE oko_saisons.id='.$idSaison.' AND oko_dateref.jour BETWEEN oko_saisons.date_debut AND oko_saisons.date_fin '
               . 'GROUP BY MONTH(oko_dateref.jour) ORDER BY YEAR(oko_dateref.jour), MONTH(oko_dateref.jour) ASC';

        $resultat = '';

        foreach ($categorie as $label => $colonneSql) {
            $q = 'SELECT IF(MONTH(oko_dateref.jour) = MONTH(NOW()) AND YEAR(oko_dateref.jour) = YEAR(NOW()), NULL, '.$colonneSql.') '.$where;
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

            $result = $this->query($q);
            $data = '';

            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_row()) {
                    $date = new DateTime($r[1], new DateTimeZone('Europe/Paris'));
                    $utc = ($date->getTimestamp() + $date->getOffset()) * 1000;
                    $data .= '['.$utc.','.($r[0] !== '' && $r[0] !== null ? $r[0] : 'null').'],';
                }
            }

            $data = rtrim($data, ',');
            $resultat .= '{ "name": "'.e($label).'",';
            $resultat .= '"data": ['.$data.']},';
        }

        $resultat = rtrim($resultat, ',');
        $this->sendResponse('{ "grapheData": ['.$resultat.']}');
    }

    public function getSyntheseSaisonTable(int $idSaison): void
    {
        $q = "SELECT DATE_FORMAT(oko_dateref.jour,'%m-%Y') AS mois, "
           . "IFNULL(SUM(oko_resume_day.nb_cycle),'-') AS nbCycle, "
           . "IFNULL(SUM(oko_resume_day.conso_kg),'-') AS conso, "
           . "IFNULL(SUM(oko_resume_day.conso_ecs_kg),'-') AS conso_ecs, "
           . "IFNULL(SUM(oko_resume_day.dju),'-') AS dju, "
           . 'IFNULL(ROUND(((SUM(oko_resume_day.conso_kg) * 1000) / SUM(oko_resume_day.dju) / '.SURFACE_HOUSE."),2),'-') AS g_dju_m, "
           . "IFNULL(ROUND(SUM(oko_resume_day.conso_kwh),2),'-') AS conso_kwh, "
           . "IFNULL(ROUND(SUM(oko_resume_day.conso_kg * oko_resume_day.prix_kg),2),0) AS cout "
           . 'FROM oko_saisons, oko_resume_day '
           . 'RIGHT JOIN oko_dateref ON oko_dateref.jour = oko_resume_day.jour '
           . 'WHERE oko_saisons.id='.$idSaison.' AND oko_dateref.jour BETWEEN oko_saisons.date_debut AND oko_saisons.date_fin '
           . 'GROUP BY MONTH(oko_dateref.jour) ORDER BY YEAR(oko_dateref.jour), MONTH(oko_dateref.jour) ASC';

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
        $data = [];

        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_object()) {
                $data[] = $r;
            }
        }

        $this->sendResponse(json_encode($data, JSON_NUMERIC_CHECK));
    }

    public function getAnnotationByDay(string $day): void
    {
        $result = $this->prepare(
            "SELECT timestamp * 1000 AS timestamp, description FROM oko_boiler WHERE DATE_FORMAT(FROM_UNIXTIME(timestamp), '%Y-%m-%d') = ?",
            [$day]
        );

        $r = ['response' => false];
        if ($result instanceof \mysqli_result) {
            $tmp = [];
            while ($res = $result->fetch_object()) {
                $tmp[] = $res;
            }
            $r = ['response' => true, 'data' => $tmp];
        }

        $this->sendResponse(json_encode($r));
    }

    /**
     * Override : les méthodes de rendu construisent déjà le JSON elles-mêmes
     * (json_encode à la main, ou fragments concaténés type '{"grapheData":...}').
     * On se contente d'émettre la chaîne telle quelle, sans re-encoder comme
     * le ferait connectDb::sendResponse(). Visibilité protected + signature
     * mixed pour respecter la compatibilité LSP avec le parent.
     */
    protected function sendResponse(mixed $t): void
    {
        header('Content-type: text/json; charset=utf-8');
        echo (string) $t;
    }

    private function getDataWithTime(string $q): string
    {
        $result = $this->query($q);
        $data = '';

        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_object()) {
                if ($r->value !== null) {
                    $date = new DateTime($r->jour.' '.$r->heure);
                    $utc = ($date->getTimestamp() + $date->getOffset()) * 1000;
                    $data .= '['.$utc.','.$r->value.'],';
                }
            }
        }

        return '['.rtrim($data, ',').']';
    }
}
