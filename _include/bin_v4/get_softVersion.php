<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Vérifie la connexion à la chaudière V4 et retourne version firmware + snapshot /all?
 * GET ip   : adresse IP de la chaudière
 * GET port : port JSON
 * GET mdp  : mot de passe JSON
 * Retourne JSON : { version: "X.XX", data: "<json brut>" }
 *              ou '' si l'IP ne répond pas sur le port 80
 */

$ip   = isset($_GET['ip'])   ? $_GET['ip']   : '';
$port = isset($_GET['port']) ? $_GET['port'] : '';
$mdp  = isset($_GET['mdp'])  ? $_GET['mdp']  : '';

if ($ip === '' || $port === '' || $mdp === '') {
    echo json_encode(['error' => 'Paramètres ip, port et mdp requis']);
    exit;
}

// Test de connectivité réseau sur port 80
$fp = fsockopen($ip, 80, $errCode, $errStr, 1);

if (!$fp) {
    echo '';
    exit;
}
fclose($fp);

// Récupération de la version firmware (endpoint racine)
$ch = curl_init('http://' . $ip . ':' . $port . '/' . $mdp . '/');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$versionRaw = curl_exec($ch);
curl_close($ch);

$version = '';
if ($versionRaw !== false) {
    $pos = strpos($versionRaw, '  V');
    if ($pos !== false) {
        $version = substr($versionRaw, $pos + 3, 5);
    }
}

// Pause nécessaire entre les deux requêtes (latence firmware V4)
sleep(3);

// Récupération du snapshot complet /all?
$ch = curl_init('http://' . $ip . ':' . $port . '/' . $mdp . '/all?');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$allRaw = curl_exec($ch);
curl_close($ch);

$resp = [
    'version' => $version,
    'data'    => $allRaw !== false ? mb_convert_encoding($allRaw, 'UTF-8', 'ISO-8859-1') : '',
];

echo json_encode($resp, JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
