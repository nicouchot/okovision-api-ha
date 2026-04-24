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

        $file = fopen(CSVFILE, 'r');
        $ln = 0;
        $old_status = 0;
        $nbColCsv = count($capteurs);

        $insert = 'INSERT IGNORE INTO oko_historique_full SET ';
        while (!feof($file)) {
            $ligne = fgets($file);
            $ligne = substr($ligne, 0, strlen($ligne) - 2);

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

                    if ('4' == $colCsv[$capteurStatus['position_column_csv']] && $colCsv[$capteurStatus['position_column_csv']] != $old_status) {
                        $query .= ', col_'.$startCycle['column_oko'].'=1';
                    }

                    for ($i = 2; $i <= $nbColCsv; ++$i) {
                        $query .= ', col_'.$capteurs[$i]['column_oko'].'='.$this->cvtDec($colCsv[$i]);
                    }

                    $query .= ';';
                    $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$query);
                    $this->query($query);
                    $old_status = $colCsv[$capteurStatus['position_column_csv']];
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

    private function download(string $file_source, string $file_target): bool
    {
        $rh = fopen($file_source, 'rb');
        $wh = fopen($file_target, 'w+b');
        if (!$rh || !$wh) {
            return false;
        }

        while (!feof($rh)) {
            if (false === fwrite($wh, fread($rh, 4096))) {
                return false;
            }
        }

        fclose($rh);
        fclose($wh);

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
        $max = $rendu->getTcMaxByDay($day);
        $min = $rendu->getTcMinByDay($day);
        $conso = $rendu->getConsoByday($day);
        $conso_ecs = $rendu->getConsoByday($day, null, null, 'hotwater');
        $dju = $rendu->getDju($max->tcExtMax, $min->tcExtMin);
        $cycle = $rendu->getNbCycleByDay($day);

        $consoPellet = ($conso->consoPellet === null) ? 0 : $conso->consoPellet;
        $consoEcsPellet = ($conso_ecs->consoPellet === null) ? 0 : $conso_ecs->consoPellet;
        $nbCycle = ($cycle->nbCycle === null) ? 0 : $cycle->nbCycle;

        $result = $this->prepare(
            'INSERT INTO oko_resume_day (jour, tc_ext_max, tc_ext_min, conso_kg, conso_ecs_kg, dju, nb_cycle) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$day, $max->tcExtMax, $min->tcExtMin, $consoPellet, $consoEcsPellet, $dju, $nbCycle]
        );

        if (!$result) {
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
