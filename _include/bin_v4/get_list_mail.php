<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 *
 * Liste les emails IMAP contenant des pièces jointes CSV.
 * Requiert une session authentifiée.
 *
 * Retourne JSON :
 *   { "success": true, "mailArray": "{\"1\":\"fichier.csv\", ...}" }
 *   { "success": true, "mailArray": "{}" }   (boîte vide)
 *   { "success": false, "error": { "code": "...", "message": "...", "diagnose": {...} } }
 */

chdir(__DIR__ . '/../../');
require_once 'config.php';

mail::requireLoggedSession();

if (!mail::isAvailable()) {
    mail::errorResponse('ext_missing', 'Extension IMAP non chargée sur ce serveur PHP');
}

$conn = mail::open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL);

if (!$conn) {
    $code = mail::classifyOpenFailure();
    $messages = [
        'auth_failed'       => 'Identifiants IMAP refusés par le serveur',
        'connection_failed' => 'Impossible de se connecter au serveur IMAP',
    ];
    mail::errorResponse($code, $messages[$code] ?? 'Échec connexion IMAP');
}

$tmpDir = CONTEXT . '/_tmp/';
$files  = is_dir($tmpDir) ? scandir($tmpDir) : [];
$lang   = $config['lang'] ?? 'fr';

$emails = @imap_search($conn, 'ALL');

if (!$emails) {
    mail::close($conn);
    mail::respond(['success' => true, 'mailArray' => '{}']);
}

$mailArray = [];

foreach ($emails as $emailNumber) {
    $parts = mail::listCsvParts($conn, $emailNumber);
    foreach ($parts as $part) {
        $mailArray[$emailNumber] = mail::decorateName($part['name'], $files, $lang);
    }
}

mail::close($conn);
mail::respond([
    'success'   => true,
    'mailArray' => json_encode($mailArray, JSON_UNESCAPED_UNICODE),
]);
