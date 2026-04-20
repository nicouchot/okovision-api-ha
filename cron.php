<?php
/*
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 *
 * Modes (GET_CHAUDIERE_DATA_BY_IP) :
 *   0 = USB        → synthèse veille uniquement
 *   1 = IP V3      → téléchargement fichiers CSV via HTTP scraping
 *   2 = JSON V4    → import log0 + snapshot live + fallback mail
 */

include_once __DIR__ . '/config.php';

$oko = new okofen();
$adm = new administration();
$log = new logger();

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$hour      = (int) date('H');
$minute    = (int) date('i');

/* ══════════════════════════════════════════════════════════════════════════
 *  MODE 0 — USB : synthèse veille uniquement
 * ══════════════════════════════════════════════════════════════════════════ */
if (GET_CHAUDIERE_DATA_BY_IP === 0) {

    $log->info('Cron | Mode USB — synthèse '.$yesterday);
    $oko->makeSyntheseByDay($yesterday, false);

/* ══════════════════════════════════════════════════════════════════════════
 *  MODE 1 — IP V3 : scraping HTTP des fichiers CSV
 * ══════════════════════════════════════════════════════════════════════════ */
} elseif (GET_CHAUDIERE_DATA_BY_IP === 1) {

    $files = $oko->getAvailableBoilerDataFiles();

    foreach ($files as $fileToDownload) {
        $date = $oko->getDateFromFilename($fileToDownload);

        if (!$oko->isDayComplete($date)) {
            $log->info('Cron | V3 '.$fileToDownload.' → téléchargement + import');
            $oko->getChaudiereData('http://'.CHAUDIERE.URL.'/'.$fileToDownload);
            $oko->csv2bdd();
            $oko->makeSyntheseByDay($date, true);
        } else {
            $log->info('Cron | V3 '.$fileToDownload.' → jour complet, synthèse si besoin');
            $oko->makeSyntheseByDay($date, false);
        }
    }

/* ══════════════════════════════════════════════════════════════════════════
 *  MODE 2 — JSON V4 : log0 + snapshot live + fallback mail
 * ══════════════════════════════════════════════════════════════════════════ */
} elseif (GET_CHAUDIERE_DATA_BY_IP === 2) {

    $urlLog0               = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/log0';
    $yesterdaySyntheseDone = false;

    // ── ÉTAPE 1 : import log0 (fenêtre glissante 24h) ────────────────────
    // log0 contient les dernières 24h : hier + début d'aujourd'hui.
    $log->info('Cron | V4 Étape 1 — téléchargement log0');
    if ($oko->getChaudiereData($urlLog0)) {
        $oko->csv2bdd();
        $oko->makeSyntheseByDay($yesterday, true);
        $oko->makeSyntheseByDay($today, true);
        $yesterdaySyntheseDone = true;
        $log->info('Cron | V4 log0 importé — synthèses '.$yesterday.' et '.$today.' recalculées');
    } else {
        $log->info('Cron | V4 log0 indisponible');
    }

    // ── ÉTAPE 1b : snapshot live /all? → ligne courante pour aujourd'hui ─
    // log0 est écrit une fois à minuit : ne contient pas les données de la
    // journée courante. On complète avec un snapshot /all? par appel du cron.
    sleep(2); // respecte le rate-limit de l'API V4 (2500ms entre requêtes)
    $log->info('Cron | V4 Étape 1b — snapshot live');
    if ($oko->storeLiveSnapshot()) {
        $oko->csv2bdd();
        $log->info('Cron | V4 Snapshot live importé pour '.$today);
    } else {
        $log->info('Cron | V4 Snapshot live indisponible');
    }

    // ── ÉTAPE 2 : vérification complétude veille (à partir de 00h01) ─────
    if ($hour > 0 || ($hour === 0 && $minute >= 1)) {
        if (!$oko->isDayComplete($yesterday)) {
            $log->info('Cron | V4 Étape 2 — veille incomplète, nouvelle tentative log0 ('.$yesterday.')');
            sleep(2);
            if ($oko->getChaudiereData($urlLog0)) {
                $oko->csv2bdd();
                $oko->makeSyntheseByDay($yesterday, true);
                $log->info('Cron | V4 log0 re-importé — veille complétée ('.$yesterday.')');
            } else {
                $log->info('Cron | V4 log0 indisponible — retry au prochain appel');
            }
        } elseif (!$yesterdaySyntheseDone) {
            $log->info('Cron | V4 Veille complète ('.$yesterday.')');
            $oko->makeSyntheseByDay($yesterday, false);
        }
    }

    // ── ÉTAPE 3 : fallback mail si veille toujours incomplète ────────────
    if (($hour > 0 || ($hour === 0 && $minute >= 1)) && !$oko->isDayComplete($yesterday)) {
        $log->info('Cron | V4 Étape 3 — fallback mail pour '.$yesterday);

        if (!function_exists('imap_open')) {
            $log->error('Cron | V4 Extension IMAP non disponible — fallback mail impossible');
        } else {
            $imapConn = imap_open(URL_MAIL, LOGIN_MAIL, PASSWORD_MAIL);

            if (!$imapConn) {
                $log->error('Cron | V4 Connexion IMAP impossible : '.imap_last_error());
            } else {
                $emails = imap_search($imapConn, 'ALL');

                if (!$emails) {
                    $log->info('Cron | V4 Aucun mail dans la boîte');
                    imap_close($imapConn);
                } else {
                    $tmpDir       = CONTEXT . '/_tmp/';
                    $nb_mail_last = null;

                    // Recherche le dernier email ayant une pièce jointe CSV
                    foreach (array_reverse($emails) as $emailNumber) {
                        $structure = imap_fetchstructure($imapConn, $emailNumber);
                        if (!isset($structure->parts)) {
                            continue;
                        }
                        for ($i = 0; $i < count($structure->parts); $i++) {
                            $part       = $structure->parts[$i];
                            $attachName = '';
                            if ($part->ifparameters) {
                                foreach ($part->parameters as $obj) {
                                    if (strtolower($obj->attribute) === 'name') {
                                        $attachName = $obj->value;
                                    }
                                }
                            }
                            if ($attachName !== '' && strtolower(pathinfo($attachName, PATHINFO_EXTENSION)) === 'csv') {
                                $body = imap_fetchbody($imapConn, $emailNumber, $i + 1);
                                if ($part->encoding == 3) {
                                    $body = base64_decode($body);
                                } elseif ($part->encoding == 4) {
                                    $body = quoted_printable_decode($body);
                                }
                                file_put_contents($tmpDir . $attachName, $body);
                                $nb_mail_last = $attachName;
                                $log->info('Cron | V4 Mail CSV téléchargé : '.$attachName);
                                break 2;
                            }
                        }
                    }

                    imap_close($imapConn);

                    if ($nb_mail_last !== null) {
                        $date = $oko->getDateFromFilename($nb_mail_last);
                        if ($date && !$oko->isDayComplete($date)) {
                            $adm->importFileFromTmp($nb_mail_last);
                            $oko->makeSyntheseByDay($date, true);
                            $log->info('Cron | V4 Mail import : '.$nb_mail_last.' → '.$date);
                        }
                    } else {
                        $log->info('Cron | V4 Aucun CSV trouvé dans les mails');
                    }
                }
            }
        }
    }
}

echo 'done';
