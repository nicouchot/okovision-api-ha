<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Applique une valeur sur un capteur de la chaudière V4 via l'API JSON
 * GET id  : identifiant du capteur (ex: "pe1.L_avg_runtime")
 * GET val : valeur à appliquer
 * Retourne la réponse JSON de la chaudière
 */

require_once __DIR__ . '/../../config.php';

$id  = isset($_GET['id'])  ? $_GET['id']  : '';
$val = isset($_GET['val']) ? $_GET['val'] : '';

if ($id === '' || $val === '') {
    echo json_encode(['error' => 'Paramètres id et val requis']);
    exit;
}

$url = 'http://' . CHAUDIERE . ':' . PORT_JSON . '/' . PASSWORD_JSON . '/' . $id . '=' . $val;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = curl_exec($ch);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false || $err) {
    echo json_encode(['error' => $err ?: 'Curl failed']);
    exit;
}

echo mb_convert_encoding($response, 'UTF-8', 'ISO-8859-1');
