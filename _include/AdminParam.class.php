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
            'pci_pellet'              => $s['pci_pellet']           ?? '',
            'rendement_chaudiere'     => $s['rendement_chaudiere']  ?? '',
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
}
