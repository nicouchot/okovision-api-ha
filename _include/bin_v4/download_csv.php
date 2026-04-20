<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Télécharge les pièces jointes CSV des emails sélectionnés vers _tmp/
 * GET list : indices email séparés par virgule (ex: "1,3,5")
 * Retourne 'true' si OK, '' sinon
 */

require_once __DIR__ . '/../../config.php';

if (!function_exists('imap_open')) {
    echo '';
    exit;
}

$list      = isset($_GET['list']) ? $_GET['list'] : '';
$listArray = array_filter(array_map('intval', explode(',', $list)));

if (empty($listArray)) {
    echo '';
    exit;
}

$tmpDir   = CONTEXT . '/_tmp/';
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

    if (!isset($structure->parts) || !count($structure->parts)) {
        $idx++;
        continue;
    }

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

            if ($part->encoding == 3) {        // BASE64
                $body = base64_decode($body);
            } elseif ($part->encoding == 4) {  // QUOTED-PRINTABLE
                $body = quoted_printable_decode($body);
            }

            file_put_contents($tmpDir . $attachName, $body);
        }
    }

    $idx++;
}

imap_close($imapConn);
echo 'true';
