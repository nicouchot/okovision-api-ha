<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class okofen extends connectDb
{
    private $_loginUrl = '';
    private $_cookies = '';
    private $_responseBoiler = '';
    private $_response = '';
    private $_connected = true;

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

    // Fonction pour recuperer les fichiers csv present sur la chaudiere
    public function getChaudiereData($url)
    {
        $link = $url;

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' |  Recuperation du fichier '.$link);
        //on lance le dl
        $result = $this->download($link, CSVFILE);

        if (!$result) {
            //throw new Exception('Download error...');
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | Données chaudiere non recupérées');

            return false;
        }
        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS - données chaudiere récupérées');

        return true;
    }

    /**
     * Look into the DB to check if we have the data for the last minute of the data.
     * If the minute is missing, then we need to download the file again.
     *
     * @param type  $dataFilename
     * @param mixed $dateChoosen
     */
    public function isDayComplete($dateChoosen)
    {
        if (empty($dateChoosen)) {
            return false;
        }
        $sql = "SELECT COUNT(*) FROM oko_historique_full WHERE jour = '{$dateChoosen}' AND heure = '23:59:00'";

        $result = $this->query($sql);

        if ($result) {
            if ($res = $result->fetch_row()) {
                return 1 == $res[0];
            }
        }

        return false;
    }

    /**
     * Converts a filename of the form 'touch_20161016.csv' to the corresponding
     * date - ie : '2016-10-16'.
     *
     * @param type $dataFilename
     */
    public function getDateFromFilename($dataFilename)
    {
        $matches = [];
        if (preg_match('@touch_([0-9]{4})([0-9]{2})([0-9]{2})\.csv@', $dataFilename, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];

            return "{$year}-{$month}-{$day}";
        }

        return false;
    }

    // integration du fichier csv dans okovision
    //V1.3.0
    /**
     * Importe le contenu de CSVFILE dans oko_historique_full.
     *
     * @param bool $liveSnapshot  true = import d'un snapshot /all? (1 seule ligne).
     *   Dans ce mode :
     *   - col_startCycle est forcé à NULL : impossible de détecter un front montant
     *     sur une ligne isolée (old_status serait toujours 0, ce qui provoquerait un
     *     faux démarrage de cycle si la chaudière est déjà en statut 4).
     *   - INSERT IGNORE : ne remplace pas une ligne existante.
     *   false (défaut) = import d'un fichier complet (log0, mail) :
     *   - col_startCycle est calculé via la détection du front montant statut=4.
     *   - ON DUPLICATE KEY UPDATE col_startCycle = VALUES(col_startCycle) : corrige
     *     les éventuels col_startCycle = 1 erronément insérés par des snapshots
     *     antérieurs sur les mêmes lignes (même jour + même heure).
     */
    public function csv2bdd(bool $liveSnapshot = false)
    {
        ini_set('max_execution_time', 120);
        $t = new timeExec();

        $ob_capteur = new capteur();
        $capteurs = $ob_capteur->getForImportCsv(); //l'index du tableau correspond a la colonne du capteur dans le fichier csv
        $capteurStatus = $ob_capteur->getByType('status');
        $startCycle = $ob_capteur->getByType('startCycle');
        unset($ob_capteur);

        $file = fopen(CSVFILE, 'r');
        $ln = 0;
        $old_status = 0;
        $nbColCsv = count($capteurs);

        // Build column list once — fixed order: jour, heure, timestamp, col_startCycle, then sensor cols.
        // col_startCycle est calculé (détection front montant statut=4), pas lu directement dans le CSV.
        // On l'exclut du loop pour éviter un doublon si position_column_csv != -1.
        $startCycleCol = 'col_'.$startCycle['column_oko'];
        $colNames = ['jour', 'heure', 'timestamp', $startCycleCol];
        for ($i = 2; $i <= $nbColCsv; ++$i) {
            $col = 'col_'.$capteurs[$i]['column_oko'];
            if ($col === $startCycleCol) {
                continue; // déjà ajouté explicitement ci-dessus — évite "Column specified twice"
            }
            $colNames[] = $col;
        }
        $columnList = implode(', ', $colNames);

        $valueRows = [];

        while (!feof($file)) {
            $ligne = fgets($file);
            //ne pas prendre en compte la derniere colonne vide
            $ligne = substr($ligne, 0, strlen($ligne) - 2);

            if (0 != $ln) { //pour ne pas lire la premiere ligne d'entete du fichier csv
                $colCsv = explode(CSV_SEPARATEUR, $ligne);

                if (isset($colCsv[1])) { //test si ligne non vide
                    $jour = $colCsv[0];
                    $heure = $colCsv[1];

                    // Round to the minute, since in some cases it is possible to
                    // import two files with the same data but not the same seconds
                    // Case of an import on the same day of the web files and the USB files
                    $heure = preg_replace('/:[0-9]{2}$/', ':00', $heure);

                    //Detection demarrage d'un cycle //Statut 4 = Debut d'un cycle sur le front montant du statut
                    //Enregistrement de 1 si nous commençons un cycle d'allumage, NULL sinon.
                    //En mode snapshot, on ne peut pas détecter le front montant (old_status=0 toujours) :
                    //on force NULL pour éviter de comptabiliser un faux démarrage de cycle.
                    if ($liveSnapshot) {
                        $stVal = 'NULL';
                    } else {
                        $stVal = ('4' == $colCsv[$capteurStatus['position_column_csv']] && $colCsv[$capteurStatus['position_column_csv']] != $old_status)
                            ? '1' : 'NULL';
                    }

                    $row = "(STR_TO_DATE('".$jour."','%d.%m.%Y'), '".$heure."', UNIX_TIMESTAMP(CONCAT(STR_TO_DATE('".$jour."','%d.%m.%Y'),' ','".$heure."')), ".$stVal;

                    //creation des valeurs pour les capteurs
                    //on commence à la deuxieme colonne de la ligne du csv
                    for ($i = 2; $i <= $nbColCsv; ++$i) {
                        if ('col_'.$capteurs[$i]['column_oko'] === $startCycleCol) {
                            continue; // valeur calculée, pas lue depuis le CSV
                        }
                        $row .= ', '.$this->cvtDec($colCsv[$i]);
                    }
                    $row .= ')';
                    $valueRows[] = $row;

                    $old_status = $colCsv[$capteurStatus['position_column_csv']];
                }
            }
            ++$ln;
        }
        fclose($file);

        if (!empty($valueRows)) {
            if ($liveSnapshot) {
                // Snapshot : INSERT IGNORE — ne remplace pas une ligne déjà présente
                $sql = 'INSERT IGNORE INTO oko_historique_full ('.$columnList.') VALUES '.implode(', ', $valueRows);
            } else {
                // Import complet (log0, mail) : corrige col_startCycle sur les lignes déjà
                // présentes (snapshots antérieurs ayant pu enregistrer un faux col_startCycle=1)
                $sql = 'INSERT INTO oko_historique_full ('.$columnList.') VALUES '.implode(', ', $valueRows)
                      .' ON DUPLICATE KEY UPDATE '.$startCycleCol.' = VALUES('.$startCycleCol.')';
            }
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | batch INSERT '.(count($valueRows)).' lignes (liveSnapshot='.($liveSnapshot ? 'true' : 'false').')');
            if (!$this->query($sql)) {
                $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | Échec batch INSERT — vérifier max_allowed_packet ou schéma colonnes');

                return false;
            }
        }

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS - import du CSV dans la BDD - '.$ln.' lignes en '.$t->getTime().' sec ');

        return true;
    }

    /**
     * Fonction lancant les requettes de synthèse du jour, elle ne s'active
     * que si la date demandée est dans le passé.
     *
     * @param string $dateChoosen A date of the form '2015-10-25'
     * @param bool   $bForce      If true, the synthese will be rebuilt even if it
     *                            exists already
     *
     * @return bool
     */
    public function makeSyntheseByDay($dateChoosen = null, $bForce = true)
    {
        //on ne fait rien si la date choisie est la date du jour
        if ($dateChoosen == date('Y-m-d', mktime(0, 0, 0, date('m'), date('d'), date('Y')))) {
            return false;
        }

        if (!$bForce && $this->isSyntheseDone($dateChoosen)) {
            return true;
        }
        // On supprime les data éventuels
        if (!$this->deleteSyntheseDay($dateChoosen)) {
            return false;
        }

        return $this->insertSyntheseDay($dateChoosen);
    }

    /**
     * Function for changing in live a boiler configuration.
     *
     * @param array list of value boiler to change
     * @param mixed $data
     */
    public function applyConfiguration($data = [])
    {
        $this->_formdata = json_encode($data);

        if (!$this->curlGet('set')) {
            $this->curlConnect();
            $this->curlGet('set');
        }
    }

    public function requestBoilerInfo($data = [])
    {
        $this->setFormData($data);
        $this->_responseBoiler = '';
        $this->sendRequest();
    }

    public function getResponseBoiler()
    {
        return $this->_responseBoiler;
    }

    public function isConnected()
    {
        return $this->_connected;
    }

    public function boilerDisconnect()
    {
        return @unlink($this->_cookies);
    }

    /**
     * Look at the boiler data repository, and returns a list of the data
     * files that are available.
     */
    public function getAvailableBoilerDataFiles()
    {
        $rh = fopen('http://'.CHAUDIERE.URL, 'rb');
        while (!feof($rh)) {
            $dirData = fread($rh, 4096);
        }
        fclose($rh);

        $matches = [];
        if (preg_match_all('@touch_[0-9]{8}\.csv@sm', $dirData, $matches)) {
            return array_unique($matches[0]);
        }

        return $matches;
    }

    //fonction de telechargement de fichier sur internet
    // download('http://xxx','/usr/var/tmp)');
    // Utilise cURL avec 3 tentatives espacées de 3s pour absorber
    // le rate-limit de l'API V4 (HTTP 401 si < 2500ms entre requêtes).
    private function download($file_source, $file_target)
    {
        $maxAttempts = 3;
        $retryDelay  = 3; // secondes

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($file_source);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $body    = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr || $httpCode !== 200 || $body === false) {
                if ($attempt < $maxAttempts) {
                    sleep($retryDelay);
                    continue;
                }
                return false;
            }

            $wh = fopen($file_target, 'w+b');
            if (!$wh) {
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
     *
     * The column order matches matrice.csv / oko_capteur.position_column_csv.
     *
     * @return bool true on success
     */
    public function storeLiveSnapshot()
    {
        $url = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/all?';
        $maxAttempts = 3;
        $retryDelay  = 3;
        $data = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $body && !$curlErr) {
                // The boiler sends ISO-8859-1 (°C symbols break json_decode on UTF-8 systems)
                $decoded = json_decode(utf8_encode($body), true);
                if (is_array($decoded)) {
                    $data = $decoded;
                    break;
                }
                // HTTP 200 but not valid JSON = rate-limit message ("Wait at least 2500ms…")
                $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Tentative '.$attempt.' — réponse non-JSON : '.substr($body, 0, 60));
            } else {
                $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Tentative '.$attempt.' — HTTP '.$httpCode.' err='.$curlErr);
            }
            if ($attempt < $maxAttempts) {
                sleep($retryDelay);
            }
        }

        if (!$data) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | /all? non disponible');
            return false;
        }

        // Helpers
        $gv = function (array $section, string $key): float {
            return isset($section[$key]['val']) ? (float) $section[$key]['val'] : 0.0;
        };
        // Format float with comma decimal separator (CSV_DECIMAL)
        $fmt = function (float $v, int $decimals = 1): string {
            return number_format($v, $decimals, CSV_DECIMAL, '');
        };
        $int = function (float $v): int { return (int) round($v); };

        $sys  = $data['system'] ?? [];
        $hk1  = $data['hk1']    ?? [];
        $pe1  = $data['pe1']    ?? [];

        $now   = new \DateTime();
        $datum = $now->format('d.m.Y');
        $zeit  = $now->format('H:i:00'); // seconds rounded to :00 (matches csv2bdd behaviour)

        $at = $fmt($gv($sys, 'L_ambient') * 0.1);

        // Build ordered value array — columns 0..49 matching matrice.csv
        $cols = [
            $datum,                                                              // 0  Datum
            $zeit,                                                               // 1  Zeit
            $at,                                                                 // 2  AT [°C]
            $at,                                                                 // 3  ATakt [°C]  (same sensor)
            $int($gv($pe1, 'L_br')),                                            // 4  PE1_BR1
            $fmt($gv($hk1, 'L_flowtemp_act') * 0.1),                           // 5  HK1 VL Ist
            $fmt($gv($hk1, 'L_flowtemp_set') * 0.1),                           // 6  HK1 VL Soll
            $fmt($gv($hk1, 'L_roomtemp_act') * 0.1),                           // 7  HK1 RT Ist
            $fmt($gv($hk1, 'L_roomtemp_set') * 0.1),                           // 8  HK1 RT Soll
            $int($gv($hk1, 'L_pump')),                                          // 9  HK1 Pumpe
            0,                                                                   // 10 HK1 Mischer (not in /all?)
            $fmt(0.0),                                                           // 11 HK1 Fernb
            $int($gv($hk1, 'L_state')),                                         // 12 HK1 Status
            $fmt($gv($pe1, 'L_temp_act') * 0.1),                               // 13 PE1 KT
            $fmt($gv($pe1, 'L_temp_set') * 0.1),                               // 14 PE1 KT_SOLL
            $fmt($gv($pe1, 'L_uw_release') * 0.1),                             // 15 PE1 UW Freigabe
            $int($gv($pe1, 'L_modulation')),                                    // 16 PE1 Modulation
            $fmt($gv($pe1, 'L_frt_temp_act') * 0.1),                           // 17 PE1 FRT Ist
            $fmt($gv($pe1, 'L_frt_temp_set') * 0.1),                           // 18 PE1 FRT Soll
            $fmt($gv($pe1, 'L_frt_temp_end') * 0.1),                           // 19 PE1 FRT End
            $fmt($gv($pe1, 'L_runtimeburner') * 0.01, 2),                      // 20 PE1 Einschublaufzeit [zs]
            $fmt($gv($pe1, 'L_resttimeburner') * 0.01, 2),                     // 21 PE1 Pausenzeit [zs]
            $int($gv($pe1, 'L_currentairflow')),                                // 22 PE1 Luefterdrehzahl
            $int($gv($pe1, 'L_fluegas')),                                       // 23 PE1 Saugzugdrehzahl
            $fmt($gv($pe1, 'L_lowpressure') * 0.1),                            // 24 PE1 Unterdruck Ist
            $fmt($gv($pe1, 'L_lowpressure_set') * 0.1),                        // 25 PE1 Unterdruck Soll
            $int($gv($pe1, 'L_storage_fill')),                                  // 26 PE1 Fuellstand [kg]
            $int($gv($pe1, 'L_storage_popper')),                                // 27 PE1 Fuellstand ZWB [kg]
            $int($gv($pe1, 'L_state')),                                         // 28 PE1 Status
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,                                  // 29-39 Motor states (not in /all?)
            $fmt(0.0),                                                           // 40 PE1 Res1 Temp.
            0, 0,                                                                // 41-42 PE1 CAP RA/ZB
            $int($gv($pe1, 'L_ak')),                                            // 43 PE1 AK
            0,                                                                   // 44 PE1 Saug-Int
            1, 0,                                                                // 45 DigIn1, 46 DigIn2
            0, 0, 0,                                                             // 47-49 Fehler1-3
        ];

        // Write header + single data row to CSVFILE (same format as log0)
        $header  = 'Datum ;Zeit ;AT [°C];ATakt [°C];PE1_BR1 ;HK1 VL Ist[°C];HK1 VL Soll[°C];HK1 RT Ist[°C];HK1 RT Soll[°C];HK1 Pumpe;HK1 Mischer;HK1 Fernb[°C];HK1 Status;PE1 KT[°C];PE1 KT_SOLL[°C];PE1 UW Freigabe[°C];PE1 Modulation[%];PE1 FRT Ist[°C];PE1 FRT Soll[°C];PE1 FRT End[°C];PE1 Einschublaufzeit[zs];PE1 Pausenzeit[zs];PE1 Luefterdrehzahl[%];PE1 Saugzugdrehzahl[%];PE1 Unterdruck Ist[EH];PE1 Unterdruck Soll[EH];PE1 Fuellstand[kg];PE1 Fuellstand ZWB[kg];PE1 Status;PE1 Motor ES;PE1 Motor RA;PE1 Motor RES1;PE1 Motor TURBINE;PE1 Motor ZUEND;PE1 Motor UW[%];PE1 Motor AV;PE1 Motor RES2;PE1 Motor MA;PE1 Motor RM;PE1 Motor SM;PE1 Res1 Temp.[°C];PE1 CAP RA;PE1 CAP ZB;PE1 AK;PE1 Saug-Int[min];PE1 DigIn1;PE1 DigIn2;Fehler1 ;Fehler2 ;Fehler3 ;';
        $dataRow = implode(';', $cols).';';

        if (false === file_put_contents(CSVFILE, $header."\n".$dataRow."\n")) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | Écriture CSVFILE impossible');
            return false;
        }

        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Snapshot live écrit ('.$datum.' '.$zeit.')');
        return true;
    }

    //function de convertion du format decimal de l'import au format bdd
    private function cvtDec($n)
    {
        return str_replace(CSV_DECIMAL, BDD_DECIMAL, $n);
    }

    private function deleteSyntheseDay($day)
    {
        $q = "DELETE FROM oko_resume_day where jour = '".$day."'";
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        return $this->query($q);
    }

    /**
     * Checks if a synthese already exists for this date.
     *
     * @param type $day
     */
    private function isSyntheseDone($day)
    {
        $sql = "SELECT COUNT(*) FROM oko_resume_day WHERE jour = '{$day}'";

        $result = $this->query($sql);

        if ($result) {
            if ($res = $result->fetch_row()) {
                return 1 == $res[0];
            }
        }

        return false;
    }

    private function insertSyntheseDay($day)
    {
        $rendu = new rendu();
        $max = $rendu->getTcMaxByDay($day);
        $min = $rendu->getTcMinByDay($day);
        $conso = $rendu->getConsoByday($day);
        $conso_ecs = $rendu->getConsoByday($day, null, null, 'hotwater');
        $dju = $rendu->getDju($max->tcExtMax, $min->tcExtMin);
        $cycle = $rendu->getNbCycleByDay($day);

        $consoPellet    = (null == $conso->consoPellet)     ? 0 : (float) $conso->consoPellet;
        $consoEcsPellet = (null == $conso_ecs->consoPellet) ? 0 : (float) $conso_ecs->consoPellet;
        $nbCycle        = (null == $cycle->nbCycle)         ? 0 : (int) $cycle->nbCycle;
        $consoKwh       = round($consoPellet * PCI_PELLET * (RENDEMENT_CHAUDIERE / 100), 2);

        /* ── Cumulatifs (cumul du jour précédent + valeurs du jour) ── */
        $prevQ  = "SELECT IFNULL(cumul_kg, 0) as c_kg, IFNULL(cumul_kwh, 0) as c_kwh,
                          IFNULL(cumul_cycle, 0) as c_cycle
                   FROM oko_resume_day WHERE jour < '{$day}' ORDER BY jour DESC LIMIT 1";
        $prevR  = $this->query($prevQ);
        $prev   = $prevR ? $prevR->fetch_object() : null;

        $cumulKg    = round(($prev ? (float) $prev->c_kg    : 0) + $consoPellet, 2);
        $cumulKwh   = round(($prev ? (float) $prev->c_kwh   : 0) + $consoKwh,   2);
        $cumulCycle = ($prev ? (int) $prev->c_cycle : 0) + $nbCycle;

        /* ── Prix au kg – logique FIFO sur les livraisons PELLET ──────────
         * Pour chaque jour, on cherche le premier lot dont le cumul livré
         * (depuis le début) >= cumul consommé ce jour ($cumulKg).
         * Cela garantit qu'on n'utilise le prix d'un nouveau lot que
         * lorsque tout le lot précédent a été physiquement consommé. */
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
            // Fallback : conso cumulée dépasse toutes les livraisons connues → dernier lot
            $lastQ     = "SELECT ROUND(price / quantity, 4) AS prix_kg
                          FROM oko_silo_events
                          WHERE event_type='PELLET' AND quantity > 0
                          ORDER BY event_date DESC LIMIT 1";
            $lastR     = $this->query($lastQ);
            $prixKgRow = $lastR ? $lastR->fetch_object() : null;
        }

        $prixKg  = $prixKgRow ? (float) $prixKgRow->prix_kg : null;
        $energieParKg = PCI_PELLET * RENDEMENT_CHAUDIERE / 100;
        $prixKwh = ($prixKg !== null && $energieParKg > 0) ? round($prixKg / $energieParKg, 4) : null;

        $prixKgSql  = ($prixKg  !== null) ? $prixKg  : 'NULL';
        $prixKwhSql = ($prixKwh !== null) ? $prixKwh : 'NULL';

        /* ── INSERT ──────────────────────────────────────────────────── */
        $query  = 'INSERT INTO oko_resume_day ';
        $query .= '( jour, tc_ext_max, tc_ext_min, conso_kg, conso_ecs_kg, conso_kwh, ';
        $query .= '  cumul_kg, cumul_kwh, cumul_cycle, prix_kg, prix_kwh, dju, nb_cycle ) VALUE ';
        $query .= "('{$day}', {$max->tcExtMax}, {$min->tcExtMin}, {$consoPellet}, {$consoEcsPellet}, {$consoKwh}, ";
        $query .= " {$cumulKg}, {$cumulKwh}, {$cumulCycle}, {$prixKgSql}, {$prixKwhSql}, {$dju}, {$nbCycle} );";

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$query);

        $n = $this->query($query);

        if (!$n) {
            $this->log->error('Class '.__CLASS__.' | '.__FUNCTION__.' | creation synthèse du '.$day.' impossible');

            return false;
        }
        $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | SUCCESS | creation synthèse du '.$day);

        return true;
    }

    /**
     * Function making live connection whit boiler.
     */
    private function curlConnect()
    {
        $q = "select login_boiler as login, pass_boiler as pass from oko_user where user='admin';";
        $result = $this->query($q);
        $boiler = $result->fetch_object();

        $code = false;
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_VERBOSE => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $this->_loginUrl,
            CURLOPT_USERAGENT => 'Okovision Agent',
            CURLOPT_POST => 1,
            CURLOPT_COOKIEJAR => $this->_cookies,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $boiler->login,
                'password' => base64_decode($boiler->pass),
                'language' => 'en',
                'submit' => 'Login',
            ]),
        ]);
        // Send the request & save response to $resp
        $resp = curl_exec($curl);

        $info = curl_getinfo($curl);
        //var_dump($info);exit;
        if ('303' == $info['http_code']) {
            $code = true;
        } else {
            $this->log->info('Class '.__CLASS__.' | '.__FUNCTION__.' | Open Session impossible in'.CHAUDIERE);
            $this->_connected = false;
        }
        curl_close($curl);
    }

    /**
     * Function getting/sending live value into boiler.
     *
     * @param mixed $action
     *
     * @return json
     */
    private function curlGet($action = 'get&attr=1')
    {
        $code = false;
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_VERBOSE => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $this->_loginUrl.'?action='.$action,
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept-Language: en', ],
            CURLOPT_COOKIEFILE => $this->_cookies,
            CURLOPT_POSTFIELDS => $this->_formdata,
        ]);

        $resp = curl_exec($curl);

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

    /**
     * Function sending live resquest into boiler, and make connection if it doesn't exist.
     */
    private function sendRequest()
    {
        if (!$this->curlGet()) {
            $this->curlConnect();
            $this->curlGet();
        }
    }

    private function setFormData($a)
    {
        $d = '';

        foreach ($a as $key => $capteur) {
            //var_dump($capteur);
            if (!is_array($capteur)) {
                $d .= ',"'.$capteur.'"';
            } else {
                $d .= ',"'.$key.'"';
            }
        }

        $this->_formdata = '["CAPPL:LOCAL.L_fernwartung_datum_zeit_sek"'.$d.']';
    }
}
