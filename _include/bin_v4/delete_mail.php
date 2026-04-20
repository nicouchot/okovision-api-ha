<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Supprime les emails IMAP sélectionnés et archive leurs pièces jointes CSV
 * GET list :
 *   - indices séparés par ":" (ex: "2:5" = emails 1 à 5)
 *   - ou liste d'un seul index (ex: "3")
 * Retourne 'true' si OK, '' sinon
 */

require_once __DIR__ . '/../../config.php';

if (!function_exists('imap_open')) {
    echo '';
    exit;
}

$list = isset($_GET['list']) ? $_GET['list'] : '';

if ($list === '') {
    echo '';
    exit;
}

$archiveDir = CONTEXT . '/archives/';
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0755, true);
}

// Parsing de la liste : "N:M" = tous les indices de 1 à M ; "N" = index unique
$listArray = explode(':', $list);

if (count($listArray) === 2) {
    // Format "first:last" — reconstruction de la liste complète
    $total     = (int)$listArray[1];
    $listArray = range(1, $total);
}

$imapConn = imap_open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL);

if (!$imapConn) {
    echo '';
    exit;
}

$emails = imap_search($imapConn, 'ALL');

if (!$emails) {
    imap_close($imapConn);
    echo '';
    exit;
}

$idx = 0;

foreach ($emails as $emailNumber) {
    if ($idx >= count($listArray)) {
        break;
    }

    if ($emailNumber != $listArray[$idx]) {
        continue;
    }

    $structure = imap_fetchstructure($imapConn, $emailNumber);

    if (isset($structure->parts) && count($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            $part       = $structure->parts[$i];
            $isAttach   = false;
            $attachName = '';

            if ($part->ifdparameters) {
                foreach ($part->dparameters as $obj) {
                    if (strtolower($obj->attribute) === 'filename') {
                        $isAttach   = true;
                        $attachName = $obj->value;
                    }
                }
            }

            if ($part->ifparameters) {
                foreach ($part->parameters as $obj) {
                    if (strtolower($obj->attribute) === 'name') {
                        $isAttach   = true;
                        $attachName = $obj->value;
                    }
                }
            }

            if ($isAttach && $attachName !== '') {
                $body = imap_fetchbody($imapConn, $emailNumber, $i + 1);

                if ($part->encoding == 3) {
                    $body = base64_decode($body);
                } elseif ($part->encoding == 4) {
                    $body = quoted_printable_decode($body);
                }

                file_put_contents($archiveDir . $attachName, $body);
            }
        }
    }

    imap_delete($imapConn, $emailNumber);
    $idx++;
}

imap_expunge($imapConn);
imap_close($imapConn);

echo 'true';
