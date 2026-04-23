<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 *
 * Archive la pièce jointe CSV de chaque message sélectionné dans archives/,
 * puis supprime le ou les messages de la boîte IMAP.
 * Requiert une session authentifiée.
 *
 * Paramètre GET : list — deux formats acceptés :
 *   "1,2,3"  → liste explicite de numéros de messages
 *   "1:N"    → plage (supprime les N premiers messages, de 1 à N)
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

// Résolution des deux formats ─────────────────────────────────────────────
// Format plage "1:N" → génère la liste [1, 2, …, N]
if (preg_match('/^\d+:\d+$/', $listRaw)) {
    [$from, $to] = explode(':', $listRaw);
    $listArray = range((int) $from, (int) $to);
} else {
    $listArray = array_filter(array_map('intval', explode(',', $listRaw)));
}

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

$archiveDir = CONTEXT . '/archives/';

foreach ($listArray as $emailNumber) {
    $parts = mail::listCsvParts($conn, $emailNumber);
    foreach ($parts as $part) {
        $body = mail::fetchPartBody($conn, $emailNumber, $part['partIndex'], $part['encoding']);
        if ($body !== '') {
            file_put_contents($archiveDir . $part['name'], $body);
        }
    }
    @imap_delete($conn, (string) $emailNumber);
}

@imap_expunge($conn);
mail::close($conn);
mail::respond(['success' => true]);
