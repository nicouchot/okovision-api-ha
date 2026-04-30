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
     * Le firmware V4 expose 4 slots (/log0../log3) en buffer tournant. Un slot
     * peut renvoyer du CSV (entête "Datum") ou la page d'aide (HTTP 200) ou
     * "Wait 2500ms" (HTTP 401) s'il est vide ou rate-limité. On affiche les
     * 4 slots avec, pour chacun, la date extraite du CSV ou "(vide)".
     */
    public function getFileFromChaudiere(): void
    {
        $r    = ['response' => true, 'listefiles' => []];
        $base = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON;

        for ($i = 0; $i < 4; $i++) {
            if ($i > 0) {
                usleep(2_700_000); // rate-limit V4 ≥ 2500 ms entre requêtes
            }

            $url = $base.'/log'.$i;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $logDate = '';
            $isCsv   = $code === 200 && is_string($resp) && str_starts_with($resp, 'Datum');

            if ($isCsv) {
                $csv   = mb_convert_encoding($resp, 'UTF-8', 'ISO-8859-1');
                $lines = preg_split("/\r?\n/", $csv, 3) ?: [];
                $cells = isset($lines[1]) ? str_getcsv($lines[1], ';') : [];
                $parts = isset($cells[0]) ? explode('.', $cells[0]) : [];

                if (count($parts) === 3) {
                    $ts = strtotime($parts[2].'-'.$parts[1].'-'.$parts[0]);
                    if ($ts !== false) {
                        $logDate = date('Ymd', $ts);
                    }
                }
            }

            $r['listefiles'][] = [
                'file' => $isCsv
                    ? 'log'.$i.' : touch_'.$logDate.'.csv'
                    : 'log'.$i.' : (slot vide)',
                'url'  => $url,
            ];
        }

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
