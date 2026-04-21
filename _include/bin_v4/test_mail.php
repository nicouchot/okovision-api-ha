<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Teste la connexion IMAP à la boîte mail configurée
 * Retourne 'success' si la connexion est établie, ou le message d'erreur sinon
 */

require_once __DIR__ . '/../../config.php';

if (!function_exists('imap_open')) {
    echo 'Extension PHP IMAP non disponible sur ce serveur.';
    exit;
}

$mailHost = !empty($_GET['host']) ? trim($_GET['host']) : (defined('URL_MAIL')      ? URL_MAIL      : '');
$mailLog  = !empty($_GET['log'])  ? trim($_GET['log'])  : (defined('LOGIN_MAIL')    ? LOGIN_MAIL    : '');
$mailPwd  = !empty($_GET['mdp'])  ? trim($_GET['mdp'])  : (defined('PASSWORD_MAIL') ? PASSWORD_MAIL : '');

$conn = imap_open($mailHost, $mailLog, $mailPwd, OP_HALFOPEN);

if ($conn) {
    imap_close($conn);
    echo 'success';
} else {
    $errors = imap_errors();
    echo $errors ? implode(' / ', $errors) : 'Connexion impossible (erreur inconnue)';
}
