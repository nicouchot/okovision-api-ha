<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Liste les emails IMAP contenant des pièces jointes CSV
 * Retourne JSON : { response: true, mailArray: "{num: nom, ...}" }
 *              ou { response: 'noMail', mailArray: 'nc' }
 */

require_once __DIR__ . '/../../config.php';

if (!function_exists('imap_open')) {
    echo json_encode(['response' => false, 'error' => 'Extension IMAP non disponible']);
    exit;
}

$tmpDir = CONTEXT . '/_tmp/';
$files  = is_dir($tmpDir) ? scandir($tmpDir) : [];

$imapConn = imap_open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL);

if (!$imapConn) {
    echo json_encode(['response' => false, 'error' => imap_last_error()]);
    exit;
}

$emails = imap_search($imapConn, 'ALL');

if ($emails) {
    $output    = ['response' => true];
    $mailArray = [];

    foreach ($emails as $emailNumber) {
        $structure = imap_fetchstructure($imapConn, $emailNumber);

        if (!isset($structure->parts) || !count($structure->parts)) {
            continue;
        }

        for ($i = 0; $i < count($structure->parts); $i++) {
            $part        = $structure->parts[$i];
            $isAttach    = false;
            $attachName  = '';

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
                $ext = strtolower(pathinfo($attachName, PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    if (array_search($attachName, $files) !== false) {
                        $label = (LANG === 'fr')
                            ? $attachName . ' <b class="red">déjà présent</b>'
                            : $attachName . ' <b class="red">already present</b>';
                    } else {
                        $label = $attachName;
                    }
                    $mailArray[$emailNumber] = $label;
                }
            }
        }
    }

    $output['mailArray'] = json_encode($mailArray, JSON_UNESCAPED_UNICODE);
    echo json_encode($output, JSON_UNESCAPED_UNICODE);

} else {
    $output = ['response' => 'noMail', 'mailArray' => 'nc'];
    echo json_encode($output, JSON_UNESCAPED_UNICODE);
}

imap_close($imapConn);
