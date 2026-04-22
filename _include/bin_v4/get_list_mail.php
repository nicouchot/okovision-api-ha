<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Liste les emails IMAP contenant des pièces jointes CSV.
 * Format de retour (calqué sur skydarc/okovision_v2) :
 *   { response: true, mailArray: "{\"1\":\"fichier.csv\", ...}" }   (mailArray est une string JSON)
 *   { response: 'noMail', mailArray: 'nc' }
 *   chaîne vide si connexion IMAP échoue
 */

require_once __DIR__ . '/../../config.php';

error_reporting(0);

if (!function_exists('imap_open')) {
    echo '';
    exit;
}

$tmpDir = CONTEXT . '/_tmp/';
$files  = is_dir($tmpDir) ? scandir($tmpDir) : [];
$lang   = $config['lang'] ?? 'fr';

$imapConn = @imap_open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL);

if (!$imapConn) {
    echo '';
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
                $ext = strtolower(pathinfo($attachName, PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    if (array_search($attachName, $files) !== false) {
                        $label = ($lang === 'fr')
                            ? $attachName . ' <b class="red">déjà présent</b>'
                            : $attachName . ' <b class="red">already present</b>';
                    } else {
                        $label = $attachName;
                    }
                    // Clé = emailNumber (format attendu par le JS : double JSON parse)
                    $mailArray[$emailNumber] = $label;
                }
            }
        }
    }

    $output['mailArray'] = json_encode($mailArray, JSON_UNESCAPED_UNICODE);
    echo json_encode($output, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['response' => 'noMail', 'mailArray' => 'nc'], JSON_UNESCAPED_UNICODE);
}

imap_close($imapConn);
