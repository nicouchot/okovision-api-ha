<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion des paramètres généraux (ping, config.json).
* Extrait de administration.class.php — Phase 5 sous-commit 5.3.
*/
class AdminParam extends connectDb
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
     * Test with a ping if the boiler is visible/online.
     *
     * @param string $address IP address (optionally with port: ip:port)
     */
    public function ping(string $address): void
    {
        $waitTimeoutInSeconds = 1;

        $r  = [];
        $tmp  = explode(':', $address);
        $ip   = $tmp[0];
        $port = $tmp[1] ?? 80;

        if ($fp = fsockopen($ip, (int) $port, $errCode, $errStr, $waitTimeoutInSeconds)) {
            $r['response'] = true;
            $r['url']      = 'http://'.$address.URL;
        } else {
            $r['response'] = false;
        }
        @fclose($fp);

        $this->sendResponse($r);
    }

    /**
     * Save general configuration into config.json.
     *
     * @param array $s $_POST variables from the admin form
     */
    public function saveInfoGenerale(array $s): void
    {
        $param = [
            'chaudiere'               => $s['oko_ip'],
            'port_json'               => $s['oko_json_port']        ?? '',
            'password_json'           => $s['oko_json_pwd']         ?? '',
            'url_mail'                => $s['mail_host']            ?? '',
            'login_mail'              => $s['mail_log']             ?? '',
            'password_mail'           => $s['mail_pwd']             ?? '',
            'tc_ref'                  => $s['param_tcref'],
            'poids_pellet'            => $s['param_poids_pellet'],
            'surface_maison'          => $s['surface_maison'],
            'get_data_from_chaudiere' => $s['oko_typeconnect'],
            'timezone'                => $s['timezone'],
            'send_to_web'             => $s['send_to_web'],
            'has_silo'                => $s['has_silo'],
            'silo_size'               => $s['silo_size'],
            'ashtray'                 => $s['ashtray'],
            'pci_pellet'              => $s['param_pci_pellet']     ?? '',
            'rendement'               => $s['param_rendement']      ?? '',
            'lang'                    => $s['lang'],
        ];

        $r             = [];
        $r['response'] = true;

        $ok = file_put_contents(CONTEXT.'/config.json', json_encode($param));

        if (!$ok) {
            $r['response'] = false;
        }

        $this->sendResponse($r);
    }

    /**
     * Recalcule toutes les colonnes dérivées de oko_resume_day sur l'ensemble de l'historique.
     * Utile après modification du PCI ou du rendement chaudière.
     */
    public function recalcHistorique(): void
    {
        $start        = microtime(true);
        $pci          = (float) PCI_PELLET;
        $rendement    = (float) RENDEMENT_CHAUDIERE;
        $energieParKg = $pci * $rendement / 100;

        /* ── 1. Recalcul de conso_kwh ── */
        $this->query(
            "UPDATE oko_resume_day
             SET conso_kwh = ROUND(conso_kg * {$pci} * ({$rendement} / 100), 2)
             WHERE conso_kg IS NOT NULL"
        );

        /* ── 2. Recalcul des cumulatifs (lecture séquentielle) ── */
        $result = $this->query(
            'SELECT jour, conso_kg, conso_kwh, nb_cycle
             FROM oko_resume_day ORDER BY jour ASC'
        );

        $cumulKg    = 0.0;
        $cumulKwh   = 0.0;
        $cumulCycle = 0;
        $rowsCumul  = 0;

        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_object()) {
                $cumulKg    = round($cumulKg  + (float) ($r->conso_kg  ?? 0), 2);
                $cumulKwh   = round($cumulKwh + (float) ($r->conso_kwh ?? 0), 2);
                $cumulCycle = $cumulCycle + (int) ($r->nb_cycle ?? 0);
                $this->query(
                    "UPDATE oko_resume_day
                     SET cumul_kg = {$cumulKg}, cumul_kwh = {$cumulKwh}, cumul_cycle = {$cumulCycle}
                     WHERE jour = '{$r->jour}'"
                );
                ++$rowsCumul;
            }
        }

        /* ── 3. Chargement des lots PELLET avec leur cumul livré ── */
        $lotResult = $this->query(
            "SELECT e1.event_date, e1.quantity, e1.price,
                    (SELECT SUM(e2.quantity) FROM oko_silo_events e2
                     WHERE e2.event_type='PELLET' AND e2.event_date <= e1.event_date) AS cumul_livraison
             FROM oko_silo_events e1
             WHERE e1.event_type = 'PELLET' AND e1.quantity > 0
             ORDER BY e1.event_date ASC"
        );

        $lots = [];
        if ($lotResult instanceof \mysqli_result) {
            while ($l = $lotResult->fetch_object()) {
                $lots[] = [
                    'prix_kg'         => round((float) $l->price / (float) $l->quantity, 4),
                    'cumul_livraison' => (float) $l->cumul_livraison,
                ];
            }
        }
        $lastPrixKg = !empty($lots) ? $lots[count($lots) - 1]['prix_kg'] : null;

        /* ── 4. Affectation FIFO du prix par jour ── */
        $rowsPrix = 0;
        if (!empty($lots)) {
            $rows2 = $this->query(
                'SELECT jour, cumul_kg FROM oko_resume_day
                 WHERE cumul_kg IS NOT NULL ORDER BY jour ASC'
            );
            if ($rows2 instanceof \mysqli_result) {
                while ($r = $rows2->fetch_object()) {
                    $prixKg = $lastPrixKg;
                    foreach ($lots as $lot) {
                        if ($lot['cumul_livraison'] >= (float) $r->cumul_kg) {
                            $prixKg = $lot['prix_kg'];
                            break;
                        }
                    }
                    $prixKwh    = ($prixKg !== null && $energieParKg > 0)
                        ? round($prixKg / $energieParKg, 4) : null;
                    $prixKwhSql = ($prixKwh !== null) ? $prixKwh : 'NULL';
                    $this->query(
                        "UPDATE oko_resume_day
                         SET prix_kg = {$prixKg}, prix_kwh = {$prixKwhSql}
                         WHERE jour = '{$r->jour}'"
                    );
                    ++$rowsPrix;
                }
            }
        }

        $this->sendResponse([
            'response'  => true,
            'rows'      => $rowsCumul,
            'rows_prix' => $rowsPrix,
            'lots'      => count($lots),
            'pci'       => $pci,
            'rendement' => $rendement,
            'elapsed'   => round(microtime(true) - $start, 2),
        ]);
    }
}
