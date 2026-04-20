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

$conn = imap_open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL, OP_HALFOPEN);

if ($conn) {
    imap_close($conn);
    echo 'success';
} else {
    $errors = imap_errors();
    echo $errors ? implode(' / ', $errors) : 'Connexion impossible (erreur inconnue)';
}
