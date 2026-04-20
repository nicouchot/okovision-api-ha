<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Lit les limites et la valeur actuelle d'un capteur V4 via l'API JSON
 * GET id : identifiant du capteur au format "system.capteur" (ex: "pe1.L_avg_runtime")
 * Retourne le JSON brut du capteur
 */

require_once __DIR__ . '/../../config.php';

$idParts = explode('.', isset($_GET['id']) ? $_GET['id'] : '');

if (count($idParts) < 2) {
    echo json_encode(['error' => 'Paramètre id invalide']);
    exit;
}

$system = $idParts[0];
$captor = $idParts[1];

$url = 'http://' . CHAUDIERE . ':' . PORT_JSON . '/' . PASSWORD_JSON . '/' . $system . '?';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$raw  = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false || $err) {
    echo json_encode(['error' => $err ?: 'Curl failed']);
    exit;
}

$json = json_decode(mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'), true);

if (!$json || !isset($json[$system][$captor])) {
    echo json_encode(['error' => 'Capteur introuvable']);
    exit;
}

echo json_encode($json[$system][$captor], JSON_UNESCAPED_UNICODE);
