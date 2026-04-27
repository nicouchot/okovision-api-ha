<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion de la matrice capteurs et des synthèses journalières.
* Extrait de administration.class.php — Phase 5 sous-commit 5.3.
*/
class AdminMatrix extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /**
     * Handle CSV file upload for matrix creation/update or manual import.
     *
     * @param array $s $_POST variables
     * @param array $f $_FILES variables
     */
    public function uploadCsv(array $s, array $f): void
    {
        $upload_handler = new UploadHandler();

        if (isset($s['actionFile'])) {
            if ('matrice' === $s['actionFile']) {
                $matrice = 'matrice.csv';
                $opt     = $upload_handler->getOption();
                $rep     = $opt['upload_dir'];

                if (file_exists($rep.$matrice)) {
                    unlink($rep.$matrice);
                }

                if (rename($rep.$f['files']['name'][0], $rep.$matrice)) {
                    if (!isset($s['update'])) {
                        $this->initMatriceFromFile();
                    } else {
                        $this->updateMatriceFromFile();
                    }
                }
            }

            if ('majusb' === $s['actionFile']) {
                $matrice = 'import.csv';
                $opt     = $upload_handler->getOption();
                $rep     = $opt['upload_dir'];

                if (file_exists($rep.$matrice)) {
                    unlink($rep.$matrice);
                }

                rename($rep.$f['files']['name'][0], $rep.$matrice);
            }
        }

        $upload_handler->generate_response_manual();
    }

    /**
     * Get all sensors from oko_capteur for the matrix page.
     */
    public function getHeaderFromOkoCsv(): void
    {
        $r = [];
        $q = 'select id, name, original_name, type, boiler from oko_capteur where position_column_csv <> -1 order by position_column_csv';
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        if ($result) {
            $r['response'] = true;
            $tmp           = [];
            while ($res = $result->fetch_object()) {
                array_push($tmp, $res);
            }
            $r['data'] = $tmp;
        } else {
            $r['response'] = false;
        }

        $this->sendResponse($r);
    }

    /**
     * Test if the sensor matrix has been initialised (count > 1).
     */
    public function statusMatrice(): void
    {
        $q      = 'select count(*) from oko_capteur';
        $result = $this->query($q);

        $r['response'] = false;

        if ($result) {
            $res = $result->fetch_row();
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$res[0]);

            if ($res[0] > 1) {
                $r['response'] = true;
            }
        }

        $this->sendResponse($r);
    }

    /**
     * Delete all rows in oko_capteur and recreate oko_historique_full.
     */
    public function deleteMatrice(): void
    {
        $r['response'] = false;

        $truncCapteur = 'TRUNCATE TABLE oko_capteur;';
        $drop         = 'DROP TABLE IF EXISTS oko_historique_full;';

        if ($this->query($truncCapteur) && $this->query($drop)) {
            $create = 'CREATE TABLE IF NOT EXISTS `oko_historique_full` ('
                        .'jour DATE NOT NULL,'
                        .'heure TIME NOT NULL,'
                        .'timestamp int(11) unsigned NOT NULL,'
                        .'PRIMARY KEY (jour, heure)'
                        .') ENGINE=MYISAM DEFAULT CHARSET=utf8;';

            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$create);
            $r['response'] = $this->query($create);
        }

        $this->sendResponse($r);
    }

    /**
     * Return days that have data in oko_historique_full but no entry in oko_resume_day.
     */
    public function getDayWithoutSynthese(): void
    {
        $now = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d'), date('Y')));

        $q = 'SELECT a.jour as jour FROM oko_historique_full as a '
           . 'LEFT OUTER JOIN oko_resume_day as b ON a.jour = b.jour '
           . "WHERE b.jour is NULL AND a.jour <> '".$now."' group by a.jour;";

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result     = $this->query($q);
        $r['data']  = [];

        if ($result) {
            $tmp = [];
            while ($res = $result->fetch_object()) {
                array_push($tmp, $res);
            }
            $r['data'] = $tmp;
        }

        $this->sendResponse($r);
    }

    /**
     * Force computation of the daily summary for a given day.
     *
     * @param string $day Date in YYYY-MM-DD format
     */
    public function makeSyntheseByDay(string $day): void
    {
        $oko           = new okofen();
        $r['response'] = $oko->makeSyntheseByDay($day);
        $this->sendResponse($r);
    }

    /**
     * Initialise the matrix after the first CSV upload.
     *
     * Creates one column in oko_historique_full and one row in oko_capteur
     * for each sensor found in the CSV header.
     */
    private function initMatriceFromFile(): void
    {
        $dico  = json_decode(file_get_contents('_langs/'.session::getInstance()->getLang().'.matrice.json'), true);
        $line  = trim(fgets(fopen('_tmp/matrice.csv', 'r')));

        $string = substr($line, 0, strlen($line) - 2);
        $line   = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string, 'UTF-8, ISO-8859-1, ISO-8859-15', true));

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | CSV First Line | '.$line);

        $query       = '';
        $positionOko = 2;
        $column      = explode(CSV_SEPARATEUR, $line);

        foreach ($column as $position => $t) {
            if ($position > 1) {
                $title = trim($t);

                if (isset($dico[$title])) {
                    $name   = $dico[$title]['name'];
                    $type   = $dico[$title]['type'];
                    $boiler = $dico[$title]['boiler'];
                } else {
                    $name   = $title;
                    $type   = '';
                    $boiler = '';
                }

                $addColumn = "ALTER TABLE oko_historique_full ADD COLUMN col_{$positionOko} DECIMAL(6,2) NULL DEFAULT NULL;";
                $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Create oko_capteur | '.$addColumn);
                $query .= $addColumn;

                $q = "INSERT INTO oko_capteur(name,position_column_csv,column_oko, original_name,type,boiler)"
                   . " VALUE ('".$this->realEscapeString($name)."',{$position},{$positionOko},"
                   . "'".$this->realEscapeString($title)."','".$this->realEscapeString($type)."','".$this->realEscapeString($boiler)."');";

                ++$positionOko;

                $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Create oko_capteur | '.$q);
                $query .= $q;
            }
        }

        $nbColumnCsv = count($column);
        $addColumn   = "ALTER TABLE oko_historique_full ADD COLUMN col_{$positionOko} DECIMAL(6,2) NULL DEFAULT NULL;";
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Create oko_capteur | '.$addColumn);
        $query .= $addColumn;

        $query .= "INSERT INTO oko_capteur(name,position_column_csv,column_oko,original_name,type)"
               . " VALUES ('Start Cycle',{$nbColumnCsv},{$positionOko},'Start Cycle','startCycle');";

        $this->multi_query($query);
        while ($this->flush_multi_queries()) {
        }
    }

    /**
     * Update oko_capteur from a new CSV upload (detect moves/additions/removals).
     */
    private function updateMatriceFromFile(): void
    {
        $dico = json_decode(file_get_contents('_langs/'.session::getInstance()->getLang().'.matrice.json'), true);
        $line = fgets(fopen('_tmp/matrice.csv', 'r'));
        $line = mb_convert_encoding(substr($line, 0, strlen($line) - 2), 'UTF-8');

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | CSV First Line | '.$line);

        $c             = new capteur();
        $capteurs      = $c->getMatrix();
        $capteursCsv   = [];
        $lastColumnOko = $c->getLastColumnOko();

        $query  = '';
        $column = explode(CSV_SEPARATEUR, $line);

        foreach ($column as $position => $t) {
            if ($position > 1) {
                $title               = trim($t);
                $capteursCsv[$title] = $position;

                if (array_key_exists($title, $capteurs)) {
                    if ($capteurs[$title]->position_column_csv !== $position) {
                        $q = 'UPDATE oko_capteur set position_column_csv='.$position.' where id='.$capteurs[$title]->id.';';
                        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Update oko_capteur | '.$q);
                    }
                } else {
                    if (isset($dico[$title])) {
                        $name   = $dico[$title]['name'];
                        $type   = $dico[$title]['type'];
                        $boiler = $dico[$title]['boiler'];
                    } else {
                        $name   = $title;
                        $type   = '';
                        $boiler = '';
                    }

                    ++$lastColumnOko;

                    $addColumn = "ALTER TABLE oko_historique_full ADD COLUMN col_{$lastColumnOko} DECIMAL(6,2) NULL DEFAULT NULL;";
                    $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Create New oko_capteur | '.$addColumn);
                    $query .= $addColumn;

                    $q = "INSERT INTO oko_capteur(name,position_column_csv,column_oko, original_name,type,boiler)"
                       . " VALUE ('".$this->realEscapeString($name)."',{$position},{$lastColumnOko},"
                       . "'".$this->realEscapeString($title)."','".$this->realEscapeString($type)."','".$this->realEscapeString($boiler)."');";

                    $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Create New oko_capteur | '.$q);
                }

                $query .= $q;
            }
        }

        $forbidenCapteurs = array_diff_key($capteurs, $capteursCsv);

        foreach ($forbidenCapteurs as $t => $position) {
            $title  = trim($t);
            $q      = 'UPDATE oko_capteur set position_column_csv=-1 where id='.$capteurs[$title]->id.';';
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | Disable oko_capteur | '.$q);
            $query .= $q;
        }

        $nbColumnCsv = count($column);
        $query      .= "UPDATE oko_capteur set position_column_csv={$nbColumnCsv} where type = 'startCycle';";

        $this->multi_query($query);
        while ($this->flush_multi_queries()) {
        }
    }
}
