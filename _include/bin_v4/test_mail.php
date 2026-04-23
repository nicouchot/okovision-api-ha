<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 *
 * Teste la connexion IMAP avec les paramètres fournis.
 * Méthode : POST (credentials hors URL).
 * Requiert une session authentifiée.
 *
 * Retourne JSON :
 *   { "success": true }
 *   { "success": false, "error": { "code": "...", "message": "...", "diagnose": {...} } }
 */

chdir(__DIR__ . '/../../');
require_once 'config.php';

mail::requireLoggedSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mail::errorResponse('bad_request', 'POST requis', 405);
}

$host  = trim($_POST['host']  ?? '');
$login = trim($_POST['login'] ?? '');
$mdp   = $_POST['mdp']        ?? '';

if ($host === '' || $login === '' || $mdp === '') {
    mail::errorResponse('missing_param', 'Paramètres host, login et mdp requis');
}

if (!mail::isAvailable()) {
    mail::errorResponse('ext_missing', 'Extension IMAP non chargée sur ce serveur PHP');
}

$conn = mail::open($host, $login, $mdp);

if (!$conn) {
    $code = mail::classifyOpenFailure();
    $messages = [
        'auth_failed'       => 'Identifiants IMAP refusés par le serveur',
        'connection_failed' => 'Impossible de se connecter au serveur IMAP',
    ];
    mail::errorResponse($code, $messages[$code] ?? 'Échec connexion IMAP');
}

mail::close($conn);
mail::respond(['success' => true]);
