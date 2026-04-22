<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Teste la connexion IMAP avec les paramètres fournis en GET.
 * Retourne 'success' si OK, chaîne vide sinon.
 */

error_reporting(0);

$host  = $_GET['host']  ?? '';
$login = $_GET['login'] ?? '';
$mdp   = $_GET['mdp']   ?? '';

if (!function_exists('imap_open') || $host === '' || $login === '' || $mdp === '') {
    echo '';
    exit;
}

$conn = @imap_open($host, $login, $mdp);

if ($conn) {
    imap_close($conn);
    echo 'success';
} else {
    echo '';
}
