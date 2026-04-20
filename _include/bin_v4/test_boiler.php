<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Retourne le snapshot JSON complet /all? de la chaudière V4
 * Utilisé par rt_v4.js pour la page temps réel
 * Retourne le JSON brut de la chaudière ou {"error":"..."} en cas d'échec
 */

require_once __DIR__ . '/../../config.php';

$url = 'http://' . CHAUDIERE . ':' . PORT_JSON . '/' . PASSWORD_JSON . '/all?';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($raw === false || $err) {
    echo json_encode(['error' => $err ?: 'Connexion chaudière impossible']);
    exit;
}

echo mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
