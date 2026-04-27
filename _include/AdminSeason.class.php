<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion des saisons de chauffe (CRUD).
* Extrait de administration.class.php — Phase 5 sous-commit 5.3.
*/
class AdminSeason extends connectDb
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
     * Return all seasons ordered by start date.
     */
    public function getSaisons(): void
    {
        $r = [];
        $q = "select id, saison,"
           . " DATE_FORMAT(date_debut,'%d/%m/%Y') as date_debut, date_debut as startDate,"
           . " DATE_FORMAT(date_fin,'%d/%m/%Y') as date_fin, date_fin as endDate"
           . " from oko_saisons order by date_debut";

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
     * Check if a season already starts on the given date.
     *
     * @param string $day Date in YYYY-MM-DD format
     */
    public function existSaison(string $day): void
    {
        $r = [];

        $q = "select count(*) from oko_saisons where date_debut = '".$day."'";

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result        = $this->query($q);
        $r['response'] = false;

        if ($result) {
            $res = $result->fetch_row();

            if ($res[0] > 0) {
                $r['response'] = true;
            }
        }

        $this->sendResponse($r);
    }

    /**
     * Create a new season from its start date.
     *
     * @param array $s $_POST with $s['startDate'] (YYYY-MM-DD)
     */
    public function setSaison(array $s): void
    {
        $r      = [];
        $dates  = $this->getDateSaison($s['startDate']);

        $r['response'] = $this->prepare(
            'INSERT INTO oko_saisons (saison, date_debut, date_fin) VALUES(?, ?, ?)',
            [$dates['saison'], $dates['start'], $dates['end']]
        );

        $this->sendResponse($r);
    }

    /**
     * Delete an existing season.
     *
     * @param array $s $_POST with $s['idSaison']
     */
    public function deleteSaison(array $s): void
    {
        $r             = [];
        $r['response'] = $this->prepare('DELETE FROM oko_saisons WHERE id=?', [(int) $s['idSaison']]);
        $this->sendResponse($r);
    }

    /**
     * Update an existing season's dates.
     *
     * @param array $s $_POST with $s['startDate'] and $s['idSaison']
     */
    public function updateSaison(array $s): void
    {
        $r     = [];
        $dates = $this->getDateSaison($s['startDate']);

        $r['response'] = $this->prepare(
            'UPDATE oko_saisons SET saison=?, date_debut=?, date_fin=? WHERE id=?',
            [$dates['saison'], $dates['start'], $dates['end'], (int) $s['idSaison']]
        );

        $this->sendResponse($r);
    }

    /**
     * Compute season label, start and end dates from a start date.
     *
     * @param  string $startDate Date in YYYY-MM-DD format
     * @return array{start: string, end: string, saison: string}
     */
    private function getDateSaison(string $startDate): array
    {
        $date  = DateTime::createFromFormat('Y-m-d', $startDate);
        $start = $date->format('Y-m-d');
        $saison = $date->format('Y');

        $date->add(new DateInterval('P1Y'));
        $date->sub(new DateInterval('P1D'));

        $end     = $date->format('Y-m-d');
        $saison .= '-'.$date->format('Y');

        return [
            'start'  => $start,
            'end'    => $end,
            'saison' => $saison,
        ];
    }
}
