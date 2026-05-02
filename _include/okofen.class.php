<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class okofen extends connectDb
{
    private string $_loginUrl = '';
    private string $_cookies = '';
    private string $_responseBoiler = '';
    private string $_response = '';
    private bool $_connected = true;
    private string $_formdata = '';

    public function __construct()
    {
        parent::__construct();

        $this->_loginUrl = 'http://'.CHAUDIERE.'/index.cgi';
        $this->_cookies = CONTEXT.'/_tmp/cookies_boiler.txt';
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getChaudiereData(string $url): bool
    {
        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' |  Recuperation du fichier '.$url);

        if (!$this->download($url, CSVFILE)) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | Données chaudiere non recupérées');

            return false;
        }

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS - données chaudiere récupérées');

        return true;
    }

    public function isDayComplete(string $dateChoosen): bool
    {
        if (empty($dateChoosen)) {
            return false;
        }

        $result = $this->prepare(
            "SELECT COUNT(*) FROM oko_historique_full WHERE jour = ? AND heure = '23:59:00'",
            [$dateChoosen]
        );

        if ($result instanceof \mysqli_result) {
            if ($res = $result->fetch_row()) {
                return $res[0] == 1;
            }
        }

        return false;
    }

    public function getDateFromFilename(string $dataFilename): string|false
    {
        $matches = [];
        if (preg_match('@touch_([0-9]{4})([0-9]{2})([0-9]{2})\.csv@', $dataFilename, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return false;
    }

    public function csv2bdd(): bool
    {
        ini_set('max_execution_time', '120');
        $t = new timeExec();

        $ob_capteur = new capteur();
        $capteurs = $ob_capteur->getForImportCsv();
        $capteurStatus = $ob_capteur->getByType('status');
        $startCycle = $ob_capteur->getByType('startCycle');
        unset($ob_capteur);

        // Index de la colonne status dans le CSV ; null si capteur non configuré
        // → on saute alors la détection de début de cycle plutôt que d'émettre
        // des warnings sur chaque ligne (qui corromperaient la réponse JSON).
        $statusColIdx = isset($capteurStatus['position_column_csv'])
            ? (int) $capteurStatus['position_column_csv']
            : null;

        $file = fopen(CSVFILE, 'r');
        $ln = 0;
        $old_status = 0;
        $nbColCsv = count($capteurs);

        $insert = 'INSERT IGNORE INTO oko_historique_full SET ';
        while (($ligne = fgets($file)) !== false) {
            $ligne = rtrim($ligne, "\r\n");

            if ($ln != 0) {
                $colCsv = explode(CSV_SEPARATEUR, $ligne);

                if (isset($colCsv[1])) {
                    $jour = $colCsv[0];
                    $heure = $colCsv[1];
                    $heure = preg_replace('/:[0-9]{2}$/', ':00', $heure);

                    $beginValue = "jour = STR_TO_DATE('".$jour."','%d.%m.%Y'),"
                                . "heure = '".$heure."',"
                                . "timestamp = UNIX_TIMESTAMP(CONCAT(STR_TO_DATE('".$jour."','%d.%m.%Y'),' ','".$heure."'))";

                    $query = $insert.$beginValue;

                    if ($statusColIdx !== null && isset($colCsv[$statusColIdx])) {
                        $statusVal = $colCsv[$statusColIdx];
                        if ('4' === $statusVal && $statusVal !== $old_status && isset($startCycle['column_oko'])) {
                            $query .= ', col_'.$startCycle['column_oko'].'=1';
                        }
                        $old_status = $statusVal;
                    }

                    for ($i = 2; $i <= $nbColCsv; ++$i) {
                        $query .= ', col_'.$capteurs[$i]['column_oko'].'='.$this->cvtDec($colCsv[$i]);
                    }

                    $query .= ';';
                    $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$query);
                    $this->query($query);
                }
            }
            ++$ln;
        }
        fclose($file);

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS - import du CSV dans la BDD - '.$ln.' lignes en '.$t->getTime().' sec ');

        return true;
    }

    public function makeSyntheseByDay(?string $dateChoosen = null, bool $bForce = true): bool
    {
        if ($dateChoosen == date('Y-m-d', mktime(0, 0, 0, (int) date('m'), (int) date('d'), (int) date('Y')))) {
            return false;
        }

        if (!$bForce && $this->isSyntheseDone((string) $dateChoosen)) {
            return true;
        }

        if (!$this->deleteSyntheseDay((string) $dateChoosen)) {
            return false;
        }

        return $this->insertSyntheseDay((string) $dateChoosen);
    }

    public function applyConfiguration(array $data = []): void
    {
        $this->_formdata = json_encode($data);

        if (!$this->curlGet('set')) {
            $this->curlConnect();
            $this->curlGet('set');
        }
    }

    public function requestBoilerInfo(array $data = []): void
    {
        $this->setFormData($data);
        $this->_responseBoiler = '';
        $this->sendRequest();
    }

    public function getResponseBoiler(): string
    {
        return $this->_responseBoiler;
    }

    public function isConnected(): bool
    {
        return $this->_connected;
    }

    public function boilerDisconnect(): bool
    {
        if (file_exists($this->_cookies)) {
            return unlink($this->_cookies);
        }

        return true;
    }

    public function getAvailableBoilerDataFiles(): array
    {
        $rh = fopen('http://'.CHAUDIERE.URL, 'rb');
        $dirData = '';
        while (!feof($rh)) {
            $dirData .= fread($rh, 4096);
        }
        fclose($rh);

        $matches = [];
        if (preg_match_all('@touch_[0-9]{8}\.csv@sm', $dirData, $matches)) {
            return array_unique($matches[0]);
        }

        return [];
    }

    /**
     * Télécharge $file_source vers $file_target avec retry x3 espacé de 3s
     * pour absorber le rate-limit de l'API V4 (HTTP 401 si < 2500ms entre
     * deux requêtes successives).
     */
    private function download(string $file_source, string $file_target): bool
    {
        $maxAttempts = 3;
        $retryDelay  = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($file_source);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $body     = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr !== '' || $httpCode !== 200 || !is_string($body)) {
                if ($attempt < $maxAttempts) {
                    sleep($retryDelay);
                    continue;
                }
                return false;
            }

            $wh = @fopen($file_target, 'w+b');
            if ($wh === false) {
                return false;
            }
            fwrite($wh, $body);
            fclose($wh);

            return true;
        }

        return false;
    }

    /**
     * Fetches current sensor values from the boiler /all? endpoint and writes
     * a synthetic CSV row to CSVFILE so that csv2bdd() can import it.
     * Called by cron.php to populate oko_historique_full for today in real time
     * (the USB log file log0 is only written once at midnight by the boiler).
     */
    public function storeLiveSnapshot(): bool
    {
        $url         = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/all?';
        $maxAttempts = 3;
        $retryDelay  = 3;
        $data        = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $body     = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && is_string($body) && $curlErr === '') {
                $decoded = json_decode(utf8_encode($body), true);
                if (is_array($decoded)) {
                    $data = $decoded;
                    break;
                }
                $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Tentative '.$attempt.' — réponse non-JSON : '.substr($body, 0, 60));
            } else {
                $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Tentative '.$attempt.' — HTTP '.$httpCode.' err='.$curlErr);
            }
            if ($attempt < $maxAttempts) {
                sleep($retryDelay);
            }
        }

        if ($data === null) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | /all? non disponible');
            return false;
        }

        $gv = function (array $section, string $key): float {
            return isset($section[$key]['val']) ? (float) $section[$key]['val'] : 0.0;
        };
        $fmt = function (float $v, int $decimals = 1): string {
            return number_format($v, $decimals, CSV_DECIMAL, '');
        };
        $int = function (float $v): int { return (int) round($v); };

        $sys = $data['system'] ?? [];
        $hk1 = $data['hk1']    ?? [];
        $pe1 = $data['pe1']    ?? [];

        $now   = new \DateTime();
        $datum = $now->format('d.m.Y');
        $zeit  = $now->format('H:i:00');

        $at = $fmt($gv($sys, 'L_ambient') * 0.1);

        $cols = [
            $datum,                                          // 0  Datum
            $zeit,                                           // 1  Zeit
            $at,                                             // 2  AT [°C]
            $at,                                             // 3  ATakt [°C]
            $int($gv($pe1, 'L_br')),                        // 4  PE1_BR1
            $fmt($gv($hk1, 'L_flowtemp_act') * 0.1),       // 5  HK1 VL Ist
            $fmt($gv($hk1, 'L_flowtemp_set') * 0.1),       // 6  HK1 VL Soll
            $fmt($gv($hk1, 'L_roomtemp_act') * 0.1),       // 7  HK1 RT Ist
            $fmt($gv($hk1, 'L_roomtemp_set') * 0.1),       // 8  HK1 RT Soll
            $int($gv($hk1, 'L_pump')),                      // 9  HK1 Pumpe
            0,                                               // 10 HK1 Mischer
            $fmt(0.0),                                       // 11 HK1 Fernb
            $int($gv($hk1, 'L_state')),                     // 12 HK1 Status
            $fmt($gv($pe1, 'L_temp_act') * 0.1),           // 13 PE1 KT
            $fmt($gv($pe1, 'L_temp_set') * 0.1),           // 14 PE1 KT_SOLL
            $fmt($gv($pe1, 'L_uw_release') * 0.1),         // 15 PE1 UW Freigabe
            $int($gv($pe1, 'L_modulation')),                // 16 PE1 Modulation
            $fmt($gv($pe1, 'L_frt_temp_act') * 0.1),       // 17 PE1 FRT Ist
            $fmt($gv($pe1, 'L_frt_temp_set') * 0.1),       // 18 PE1 FRT Soll
            $fmt($gv($pe1, 'L_frt_temp_end') * 0.1),       // 19 PE1 FRT End
            $fmt($gv($pe1, 'L_runtimeburner') * 0.01, 2),  // 20 PE1 Einschublaufzeit
            $fmt($gv($pe1, 'L_resttimeburner') * 0.01, 2), // 21 PE1 Pausenzeit
            $int($gv($pe1, 'L_currentairflow')),            // 22 PE1 Luefterdrehzahl
            $int($gv($pe1, 'L_fluegas')),                   // 23 PE1 Saugzugdrehzahl
            $fmt($gv($pe1, 'L_lowpressure') * 0.1),        // 24 PE1 Unterdruck Ist
            $fmt($gv($pe1, 'L_lowpressure_set') * 0.1),    // 25 PE1 Unterdruck Soll
            $int($gv($pe1, 'L_storage_fill')),              // 26 PE1 Fuellstand
            $int($gv($pe1, 'L_storage_popper')),            // 27 PE1 Fuellstand ZWB
            $int($gv($pe1, 'L_state')),                     // 28 PE1 Status
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,               // 29-39 Motor states
            $fmt(0.0),                                       // 40 PE1 Res1 Temp.
            0, 0,                                            // 41-42 PE1 CAP
            $int($gv($pe1, 'L_ak')),                        // 43 PE1 AK
            0,                                               // 44 PE1 Saug-Int
            1, 0,                                            // 45-46 DigIn
            0, 0, 0,                                         // 47-49 Fehler
        ];

        $header  = 'Datum ;Zeit ;AT [°C];ATakt [°C];PE1_BR1 ;HK1 VL Ist[°C];HK1 VL Soll[°C];HK1 RT Ist[°C];HK1 RT Soll[°C];HK1 Pumpe;HK1 Mischer;HK1 Fernb[°C];HK1 Status;PE1 KT[°C];PE1 KT_SOLL[°C];PE1 UW Freigabe[°C];PE1 Modulation[%];PE1 FRT Ist[°C];PE1 FRT Soll[°C];PE1 FRT End[°C];PE1 Einschublaufzeit[zs];PE1 Pausenzeit[zs];PE1 Luefterdrehzahl[%];PE1 Saugzugdrehzahl[%];PE1 Unterdruck Ist[EH];PE1 Unterdruck Soll[EH];PE1 Fuellstand[kg];PE1 Fuellstand ZWB[kg];PE1 Status;PE1 Motor ES;PE1 Motor RA;PE1 Motor RES1;PE1 Motor TURBINE;PE1 Motor ZUEND;PE1 Motor UW[%];PE1 Motor AV;PE1 Motor RES2;PE1 Motor MA;PE1 Motor RM;PE1 Motor SM;PE1 Res1 Temp.[°C];PE1 CAP RA;PE1 CAP ZB;PE1 AK;PE1 Saug-Int[min];PE1 DigIn1;PE1 DigIn2;Fehler1 ;Fehler2 ;Fehler3 ;';
        $dataRow = implode(';', $cols).';';

        if (file_put_contents(CSVFILE, $header."\n".$dataRow."\n") === false) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | Écriture CSVFILE impossible');
            return false;
        }

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Snapshot live écrit ('.$datum.' '.$zeit.')');
        return true;
    }

    private function cvtDec(string $n): string
    {
        return str_replace(CSV_DECIMAL, BDD_DECIMAL, $n);
    }

    private function deleteSyntheseDay(string $day): bool
    {
        $result = $this->prepare('DELETE FROM oko_resume_day WHERE jour = ?', [$day]);
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | jour='.$day);

        return $result !== false;
    }

    private function isSyntheseDone(string $day): bool
    {
        $result = $this->prepare('SELECT COUNT(*) FROM oko_resume_day WHERE jour = ?', [$day]);

        if ($result instanceof \mysqli_result) {
            if ($res = $result->fetch_row()) {
                return $res[0] == 1;
            }
        }

        return false;
    }

    private function insertSyntheseDay(string $day): bool
    {
        $rendu = new rendu();
        $max       = $rendu->getTcMaxByDay($day);
        $min       = $rendu->getTcMinByDay($day);
        $conso     = $rendu->getConsoByday($day);
        $conso_ecs = $rendu->getConsoByday($day, null, null, 'hotwater');
        $dju       = $rendu->getDju($max->tcExtMax, $min->tcExtMin);
        $cycle     = $rendu->getNbCycleByDay($day);

        $consoPellet    = ($conso->consoPellet === null)     ? 0 : (float) $conso->consoPellet;
        $consoEcsPellet = ($conso_ecs->consoPellet === null) ? 0 : (float) $conso_ecs->consoPellet;
        $nbCycle        = ($cycle->nbCycle === null)         ? 0 : (int)   $cycle->nbCycle;
        $consoKwh       = round($consoPellet * PCI_PELLET * (RENDEMENT_CHAUDIERE / 100), 2);

        /* ── Cumulatifs (cumul du jour précédent + valeurs du jour) ── */
        $prevR = $this->query(
            "SELECT IFNULL(cumul_kg, 0) AS c_kg, IFNULL(cumul_kwh, 0) AS c_kwh,
                    IFNULL(cumul_cycle, 0) AS c_cycle
             FROM oko_resume_day WHERE jour < '{$day}' ORDER BY jour DESC LIMIT 1"
        );
        $prev = ($prevR instanceof \mysqli_result) ? $prevR->fetch_object() : null;

        $cumulKg    = round(($prev ? (float) $prev->c_kg    : 0) + $consoPellet, 2);
        $cumulKwh   = round(($prev ? (float) $prev->c_kwh   : 0) + $consoKwh,   2);
        $cumulCycle = ($prev ? (int) $prev->c_cycle : 0) + $nbCycle;

        /* ── Prix au kg — logique FIFO sur les livraisons PELLET ── */
        $prixKgR = $this->query(
            "SELECT ROUND(e.price / e.quantity, 4) AS prix_kg
             FROM (
                 SELECT e1.price, e1.quantity,
                        (SELECT SUM(e2.quantity) FROM oko_silo_events e2
                         WHERE e2.event_type='PELLET' AND e2.event_date <= e1.event_date) AS cumul_livraison
                 FROM oko_silo_events e1
                 WHERE e1.event_type = 'PELLET' AND e1.quantity > 0
             ) e
             WHERE e.cumul_livraison >= {$cumulKg}
             ORDER BY e.cumul_livraison ASC
             LIMIT 1"
        );
        $prixKgRow = ($prixKgR instanceof \mysqli_result) ? $prixKgR->fetch_object() : null;

        if ($prixKgRow === null) {
            $lastR     = $this->query(
                "SELECT ROUND(price / quantity, 4) AS prix_kg
                 FROM oko_silo_events WHERE event_type='PELLET' AND quantity > 0
                 ORDER BY event_date DESC LIMIT 1"
            );
            $prixKgRow = ($lastR instanceof \mysqli_result) ? $lastR->fetch_object() : null;
        }

        $prixKg       = ($prixKgRow !== null) ? (float) $prixKgRow->prix_kg : null;
        $energieParKg = PCI_PELLET * RENDEMENT_CHAUDIERE / 100;
        $prixKwh      = ($prixKg !== null && $energieParKg > 0) ? round($prixKg / $energieParKg, 4) : null;
        $prixKgSql    = ($prixKg  !== null) ? $prixKg  : 'NULL';
        $prixKwhSql   = ($prixKwh !== null) ? $prixKwh : 'NULL';

        /* ── INSERT ── */
        $query  = 'INSERT INTO oko_resume_day ';
        $query .= '(jour, tc_ext_max, tc_ext_min, conso_kg, conso_ecs_kg, conso_kwh, ';
        $query .= ' cumul_kg, cumul_kwh, cumul_cycle, prix_kg, prix_kwh, dju, nb_cycle) VALUE ';
        $query .= "('{$day}', {$max->tcExtMax}, {$min->tcExtMin}, {$consoPellet}, {$consoEcsPellet}, {$consoKwh}, ";
        $query .= " {$cumulKg}, {$cumulKwh}, {$cumulCycle}, {$prixKgSql}, {$prixKwhSql}, {$dju}, {$nbCycle});";

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$query);

        if (!$this->query($query)) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | creation synthèse du '.$day.' impossible');

            return false;
        }

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS | creation synthèse du '.$day);

        return true;
    }

    private function curlConnect(): void
    {
        $result = $this->query("SELECT login_boiler AS login, pass_boiler AS pass FROM oko_user WHERE user='admin'");
        if (!$result instanceof \mysqli_result) {
            return;
        }
        $boiler = $result->fetch_object();

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_VERBOSE        => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL            => $this->_loginUrl,
            CURLOPT_USERAGENT      => 'Okovision Agent',
            CURLOPT_POST           => 1,
            CURLOPT_COOKIEJAR      => $this->_cookies,
            CURLOPT_POSTFIELDS     => http_build_query([
                'username' => $boiler->login,
                'password' => base64_decode($boiler->pass),
                'language' => 'en',
                'submit'   => 'Login',
            ]),
        ]);

        curl_exec($curl);
        $info = curl_getinfo($curl);

        if ('303' != $info['http_code']) {
            $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Open Session impossible in'.CHAUDIERE);
            $this->_connected = false;
        }

        curl_close($curl);
    }

    private function curlGet(string $action = 'get&attr=1'): bool
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_VERBOSE        => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL            => $this->_loginUrl.'?action='.$action,
            CURLOPT_POST           => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept-Language: en',
            ],
            CURLOPT_COOKIEFILE     => $this->_cookies,
            CURLOPT_POSTFIELDS     => $this->_formdata,
        ]);

        $resp = curl_exec($curl);
        $code = false;

        if (!curl_errno($curl)) {
            $info = curl_getinfo($curl);
            if ('200' == $info['http_code']) {
                $this->_responseBoiler = $resp;
                $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$resp);
                $code = true;
            }
        }

        curl_close($curl);

        return $code;
    }

    private function sendRequest(): void
    {
        if (!$this->curlGet()) {
            $this->curlConnect();
            $this->curlGet();
        }
    }

    private function setFormData(array $a): void
    {
        $d = '';
        foreach ($a as $key => $capteur) {
            $d .= !is_array($capteur) ? ',"'.$capteur.'"' : ',"'.$key.'"';
        }

        $this->_formdata = '["CAPPL:LOCAL.L_fernwartung_datum_zeit_sek"'.$d.']';
    }
}
