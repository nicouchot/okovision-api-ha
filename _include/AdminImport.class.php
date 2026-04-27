<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion des imports CSV (depuis la chaudière ou depuis _tmp/).
* Extrait de administration.class.php — Phase 5 sous-commit 5.3.
*/
class AdminImport extends connectDb
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
     * Get file list from boiler (V4 firmware — JSON API).
     *
     * Fetches the current day's CSV (log0) from the V4 JSON endpoint,
     * extracts the log date, and builds a list of the 4 most recent
     * daily log URLs that the client can then import individually.
     */
    public function getFileFromChaudiere(): void
    {
        $r['response'] = true;

        ini_set('auto_detect_line_endings', true);

        $ch = curl_init('http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/log0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $csv = mb_convert_encoding(curl_exec($ch), 'UTF-8', 'ISO-8859-1');

        $Data = str_getcsv($csv, "\n");

        foreach ($Data as $key => $Row) {
            $Row         = str_getcsv($Row, ';');
            $array[$key] = $Row;
        }
        $dateArray = explode('.', $array[1][0]);

        $t_href = [];
        for ($i = 0; $i < 4; $i++) {
            $logDate = date('Ymd', strtotime($dateArray[2].'-'.$dateArray[1].'-'.$dateArray[0].' +'.$i.' day'));

            array_push(
                $t_href,
                [
                    'file' => 'log'.$i.' : touch_'.$logDate.'.csv',
                    'url'  => 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/log'.$i,
                ]
            );
        }
        $r['listefiles'] = $t_href;

        $this->sendResponse($r);
    }

    /**
     * Get file from boiler and import data into DB.
     *
     * @param array $s $_POST with $s['url']
     */
    public function importFileFromChaudiere(array $s): void
    {
        $r             = [];
        $r['response'] = true;
        $import        = false;

        $oko    = new okofen();
        $status = $oko->getChaudiereData($s['url']);

        if ($status) {
            $import = $oko->csv2bdd();
        } else {
            $r['response'] = false;
        }

        if (!$import) {
            $r['response'] = false;
        }

        $this->sendResponse($r);
    }

    /**
     * Return all files present in _tmp/ (excluding reserved filenames).
     */
    public function getFileFromTmp(): void
    {
        $excluded = ['.', '..', 'matrice.csv', 'import.csv', 'readme.md', 'cookies_boiler.txt'];
        $files    = scandir('_tmp');
        $r        = [];

        foreach ($files as $f) {
            if (!in_array($f, $excluded, true)) {
                $r[] = $f;
            }
        }

        $this->sendResponse($r);
    }

    /**
     * Rename a file from _tmp/ to import.csv, then import it.
     *
     * @param string $file Filename inside _tmp/ (no path)
     */
    public function importFileFromTmp(string $file): void
    {
        if (file_exists('_tmp/import.csv')) {
            unlink('_tmp/import.csv');
        }

        rename('_tmp/'.$file, '_tmp/import.csv');

        $this->importcsv();
    }

    /**
     * Force import of _tmp/import.csv into the database.
     */
    public function importcsv(): void
    {
        $oko           = new okofen();
        $r['response'] = $oko->csv2bdd();
        $this->sendResponse($r);
    }
}
