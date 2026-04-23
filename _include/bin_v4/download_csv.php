<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 *
 * Télécharge les pièces jointes CSV des emails sélectionnés
 * et les dépose dans _tmp/.
 * Requiert une session authentifiée.
 *
 * Paramètre GET : list = "1,2,3"  (numéros de message IMAP, séparés par virgule)
 *
 * Retourne JSON :
 *   { "success": true }
 *   { "success": false, "error": { ... } }
 */

chdir(__DIR__ . '/../../');
require_once 'config.php';

mail::requireLoggedSession();

$listRaw = $_GET['list'] ?? '';
if ($listRaw === '') {
    mail::errorResponse('missing_param', 'Paramètre list requis');
}

$listArray = array_filter(array_map('intval', explode(',', $listRaw)));
if (empty($listArray)) {
    mail::errorResponse('missing_param', 'Liste de numéros de messages invalide');
}

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

foreach ($listArray as $emailNumber) {
    $parts = mail::listCsvParts($conn, $emailNumber);
    foreach ($parts as $part) {
        $body = mail::fetchPartBody($conn, $emailNumber, $part['partIndex'], $part['encoding']);
        if ($body !== '') {
            file_put_contents($tmpDir . $part['name'], $body);
        }
    }
}

mail::close($conn);
mail::respond(['success' => true]);
