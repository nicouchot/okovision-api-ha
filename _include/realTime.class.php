<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class realTime extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getOkoValue(array $data = []): array
    {
        $o = new okofen();
        $o->requestBoilerInfo($data);

        $r = [];

        $dataBoiler = json_decode($o->getResponseBoiler());

        if ($o->isConnected()) {
            foreach ($dataBoiler as $capt) {
                if ('' != $capt->formatTexts) {
                    $value = 'null';

                    if ('???' != $capt->value) {
                        $s = explode('|', $capt->formatTexts);
                        $value = $s[$capt->value];
                    }

                    $r[$capt->name] = (object) [
                        'value'    => $value,
                        'unitText' => '',
                    ];
                } else {
                    $r[$capt->name] = (object) [
                        'value'      => ('' != $capt->divisor && '???' != $capt->divisor)
                            ? ($capt->value / $capt->divisor)
                            : ($capt->value),
                        'unitText'   => ('???' == $capt->unitText) ? '' : (('K' == $capt->unitText) ? '°C' : $capt->unitText),
                        'divisor'    => $capt->divisor,
                        'lowerLimit' => $capt->lowerLimit,
                        'upperLimit' => $capt->upperLimit,
                    ];
                }
            }
        }

        return $r;
    }

    public function getIndic(int $way = 1): void
    {
        $json = ['response' => false];
        $hk = $way - 1;

        $indic = [
            'CAPPL:FA[0].L_mittlere_laufzeit',
            'CAPPL:FA[0].L_brennerstarts',
            'CAPPL:FA[0].L_brennerlaufzeit_anzeige',
            'CAPPL:FA[0].L_anzahl_zuendung',
            'CAPPL:LOCAL.touch[0].version',
            'CAPPL:LOCAL.L_aussentemperatur_ist',
            "CAPPL:LOCAL.L_hk[{$hk}].raumtemp_ist",
            "CAPPL:LOCAL.L_hk[{$hk}].raumtemp_soll",
            "CAPPL:LOCAL.hk[{$hk}].raumtemp_heizen",
            "CAPPL:LOCAL.hk[{$hk}].raumtemp_absenken",
            "CAPPL:LOCAL.hk[{$hk}].heizkurve_steigung",
            "CAPPL:LOCAL.hk[{$hk}].heizkurve_fusspunkt",
            "CAPPL:LOCAL.hk[{$hk}].heizgrenze_heizen",
            "CAPPL:LOCAL.hk[{$hk}].heizgrenze_absenken",
            "CAPPL:LOCAL.hk[{$hk}].vorlauftemp_max",
            "CAPPL:LOCAL.hk[{$hk}].vorlauftemp_min",
            "CAPPL:LOCAL.hk[{$hk}].ueberhoehung",
            "CAPPL:LOCAL.hk[{$hk}].mischer_max_auf_zeit",
            "CAPPL:LOCAL.hk[{$hk}].mischer_max_aus_zeit",
            "CAPPL:LOCAL.hk[{$hk}].mischer_max_zu_zeit",
            "CAPPL:LOCAL.hk[{$hk}].mischer_regelbereich_quelle",
            "CAPPL:LOCAL.hk[{$hk}].mischer_regelbereich_vorlauf",
            "CAPPL:LOCAL.hk[{$hk}].quellentempverlauf_anstiegstemp",
            "CAPPL:LOCAL.hk[{$hk}].quellentempverlauf_regelbereich",
            'CAPPL:FA[0].pe_kesseltemperatur_soll',
            'CAPPL:FA[0].pe_abschalttemperatur',
            'CAPPL:FA[0].pe_einschalthysterese_smart',
            'CAPPL:FA[0].pe_kesselleistung',
        ];

        $r = $this->getOkoValue($indic);

        if (!empty($r)) {
            $tmp = [];
            foreach ($indic as $key) {
                $tmp[$key] = trim($r[$key]->value.' '.$r[$key]->unitText);
            }
            $json['data'] = $tmp;
            $json['response'] = true;
        }

        $this->sendResponse(json_encode($json));
    }

    public function setOkoLogin(string $user, string $pass): void
    {
        $passEncoded = base64_encode($pass);
        $userId = (int) session::getInstance()->getVar('userId');

        $r = ['response' => false];

        if ($this->prepare(
            'UPDATE oko_user SET login_boiler=?, pass_boiler=? WHERE id=?',
            [$user, $passEncoded, $userId]
        )) {
            $o = new okofen();
            $o->boilerDisconnect();
            $r['response'] = true;
        }

        $this->sendResponse(json_encode($r));
    }

    public function getdata(int $id): void
    {
        $result = $this->prepare(
            "SELECT capteur.boiler AS boiler, capteur.name AS name, capteur.id AS id, asso.correction_effect AS coeff FROM oko_asso_capteur_graphe AS asso LEFT JOIN oko_capteur AS capteur ON capteur.id = asso.oko_capteur_id WHERE asso.oko_graphe_id=? AND capteur.boiler <> '' ORDER BY asso.position",
            [$id]
        );

        $sensor = [];

        if ($result instanceof \mysqli_result) {
            while ($c = $result->fetch_object()) {
                $sensor[$c->boiler] = [
                    'name'  => $c->name,
                    'coeff' => $c->coeff,
                ];
            }
        }

        $r = $this->getOkoValue($sensor);
        $resultat = '';

        foreach ($sensor as $boiler => $param) {
            $resultat .= '{ "name": "'.e($param['name']).'",';
            $data = '['.substr($r['CAPPL:LOCAL.L_fernwartung_datum_zeit_sek']->value, 0, -7).'000,'.$r[$boiler]->value * $param['coeff'].']';
            $resultat .= '"data": '.$data.'},';
        }

        $resultat = rtrim($resultat, ',');
        $this->sendResponse('['.$resultat.']');
    }

    public function getSensorInfo(string $sensor): void
    {
        $sensor = session::getInstance()->getSensorName($sensor);
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$sensor);

        $r = $this->getOkoValue([$sensor]);

        $this->sendResponse(json_encode($r[$sensor]));
    }

    public function saveBoilerConfig(mixed $config, string $description, string $dateChoisen = ''): void
    {
        if ($dateChoisen !== '') {
            $date = DateTime::createFromFormat('d/m/Y H:i:s', $dateChoisen);
        } else {
            $date = new DateTime();
        }

        $utc = $date->getTimestamp() + $date->getOffset();
        $configJson = json_encode($config);

        $r = ['response' => $this->prepare(
            'INSERT INTO oko_boiler SET timestamp=?, description=?, config=?',
            [$utc, $description, $configJson]
        ) !== false];

        $this->sendResponse(json_encode($r));
    }

    public function deleteConfigBoiler(int $timestamp): void
    {
        $r = ['response' => $this->prepare(
            'DELETE FROM oko_boiler WHERE timestamp=?',
            [$timestamp]
        ) !== false];

        $this->sendResponse(json_encode($r));
    }

    public function getListConfigBoiler(): void
    {
        $q = "SELECT timestamp, DATE_FORMAT(FROM_UNIXTIME(timestamp), '%d/%m/%Y %H:%i:%s') AS date, description, config FROM oko_boiler ORDER BY timestamp DESC";
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
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

    public function getConfigBoiler(int $timestamp): void
    {
        $result = $this->prepare(
            'SELECT config FROM oko_boiler WHERE timestamp=?',
            [$timestamp]
        );

        if ($result instanceof \mysqli_result) {
            $res = $result->fetch_object();
            $this->sendResponse('{"response":true,"data":'.$res->config.'}');
        } else {
            $this->sendResponse('{"response":false}');
        }
    }

    public function applyBoilerConfig(mixed $config): void
    {
        $sensors = [];
        $param = [];

        foreach ($config as $key => $value) {
            $t = explode(' ', $value);
            $name = session::getInstance()->getSensorName($key);
            $param[$name] = $t[0];
            $sensors[] = $name;
        }

        $sensorsInfo = $this->getOkoValue($sensors);

        foreach ($param as $name => $value) {
            $c = $sensorsInfo[$name];
            $param[$name] = $value * $c->divisor;
        }

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.json_encode($param));

        $o = new okofen();
        $o->applyConfiguration($param);

        $this->sendResponse($o->getResponseBoiler());
    }

    public function getBoilerMode(int $way = 1): void
    {
        $json = ['response' => false];
        $hk = $way - 1;

        $sensor = ["CAPPL:LOCAL.hk[{$hk}].betriebsart[1]"];
        $r = $this->getOkoValue($sensor);

        if (!empty($r)) {
            $tmp = [];
            foreach ($sensor as $key) {
                $tmp[$key] = trim($r[$key]->value.' '.$r[$key]->unitText);
            }
            $json['data'] = $tmp;
            $json['response'] = true;
        }

        $this->sendResponse(json_encode($json));
    }

    public function setBoilerMode(int $mode = 0, int $way = 1): void
    {
        $hk = $way - 1;
        $o = new okofen();
        $o->applyConfiguration(
            ["CAPPL:LOCAL.hk[{$hk}].betriebsart[1]" => $mode]
        );

        $this->sendResponse($o->getResponseBoiler());
    }

    /**
     * Override : les méthodes de realTime construisent déjà le JSON elles-mêmes
     * (json_encode à la main). On se contente d'émettre la chaîne telle quelle
     * sans re-encoder comme le ferait connectDb::sendResponse(). Visibilité
     * protected + signature mixed pour respecter la compatibilité LSP.
     */
    protected function sendResponse(mixed $t): void
    {
        header('Content-type: text/json; charset=utf-8');
        echo (string) $t;
    }
}
