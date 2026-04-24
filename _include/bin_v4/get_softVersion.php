<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : skydarc
* Utilisation commerciale interdite sans mon accord
*/

declare(strict_types=1);

$ip   = isset($_GET['ip'])   ? (string) $_GET['ip']   : '';
$port = isset($_GET['port']) ? (string) $_GET['port'] : '';
$mdp  = isset($_GET['mdp'])  ? (string) $_GET['mdp']  : '';

if ($ip === '' || $port === '' || $mdp === '') {
    echo json_encode(['response' => false, 'error' => 'missing_params']);
    exit;
}

$errCode = 0;
$errStr  = '';
$fp = @fsockopen($ip, 80, $errCode, $errStr, 1);

if ($fp === false) {
    echo json_encode(['response' => false, 'error' => 'unreachable']);
    exit;
}
fclose($fp);

$resp = ['response' => false];

$ch = curl_init('http://'.$ip.':'.$port.'/'.$mdp.'/');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$raw = curl_exec($ch);
curl_close($ch);

if ($raw === false) {
    echo json_encode(['response' => false, 'error' => 'curl_failed']);
    exit;
}

$json = mb_convert_encoding((string) $raw, 'UTF-8', 'ISO-8859-1');
$posV = strpos($json, '  V');

if ($posV === false) {
    echo json_encode(['response' => false, 'error' => 'bad_response']);
    exit;
}

$resp['version']  = substr($json, $posV + 3, 5);
$resp['response'] = true;

sleep(3);

$ch = curl_init('http://'.$ip.':'.$port.'/'.$mdp.'/all');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$raw = curl_exec($ch);
curl_close($ch);

$resp['data'] = ($raw === false) ? '' : mb_convert_encoding((string) $raw, 'UTF-8', 'ISO-8859-1');

echo json_encode($resp, JSON_HEX_AMP);
