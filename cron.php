<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

include_once __DIR__.'/config.php';

$oko = new okofen();
$log = new logger();
$adm = new administration();

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$hour      = (int) date('H');
$minute    = (int) date('i');

// ── ÉTAPE 1 : import temps réel — log0 = fenêtre glissante 24h ───────────
// log0 contient les dernières 24h : typiquement hier + début d'aujourd'hui.
// On importe toutes les lignes en base puis on recalcule les synthèses
// pour hier ET pour aujourd'hui.
$urlLog0 = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/log0';
$log->info("Cron | Téléchargement log0");
if ($oko->getChaudiereData($urlLog0)) {
    $oko->csv2bdd();
    $oko->makeSyntheseByDay($yesterday, true);
    $oko->makeSyntheseByDay($today, true);
    $log->info("Cron | log0 importé — synthèses {$yesterday} et {$today} recalculées");
} else {
    $log->info("Cron | log0 indisponible");
}

// ── ÉTAPE 2 : vérification veille à partir de 00h01 ───────────────────────
if ($hour > 0 || ($hour === 0 && $minute >= 1)) {
    if (!$oko->isDayComplete($yesterday)) {
        $urlLog1 = 'http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/log1';
        $log->info("Cron | Veille incomplète — téléchargement log1 ({$yesterday})");
        if ($oko->getChaudiereData($urlLog1)) {
            $oko->csv2bdd();
            $oko->makeSyntheseByDay($yesterday, true);
            $log->info("Cron | log1 importé");
        } else {
            $log->info("Cron | log1 indisponible");
        }
    } else {
        $log->info("Cron | Veille déjà complète ({$yesterday})");
        $oko->makeSyntheseByDay($yesterday, false);
    }
}

// ── ÉTAPE 3 : fallback mail si veille toujours incomplète ─────────────────
if ($hour > 0 || ($hour === 0 && $minute >= 1)) {
    if (!$oko->isDayComplete($yesterday)) {
        $log->info("Cron | Fallback mail pour {$yesterday}");
        chdir('_include/bin_v4/');
        ob_start();
        include __DIR__.'/_include/bin_v4/get_list_mail.php';
        ob_end_clean();
        chdir('../..');

        // Récupère le dernier mail disponible
        $nb_mail_last = null;
        foreach ($mailArray as $nb_mail => $titre_mail) {
            $nb_mail_last = $nb_mail;
        }

        if ($nb_mail_last !== null) {
            $_GET['list'] = $nb_mail_last;
            include_once __DIR__.'/_include/bin_v4/download_csv.php';

            // Importe les fichiers déposés dans _tmp/
            $files = array_diff(
                scandir('_tmp'),
                ['.', '..', 'matrice.csv', 'import.csv', 'readme.md', 'cookies_boiler.txt']
            );
            foreach ($files as $fileToDownload) {
                $date = $oko->getDateFromFilename($fileToDownload);
                if ($date && !$oko->isDayComplete($date)) {
                    $adm->importFileFromTmp($fileToDownload);
                    $oko->csv2bdd();
                    $oko->makeSyntheseByDay($date, true);
                    $log->info("Cron | Mail import : {$fileToDownload} → {$date}");
                }
            }
        } else {
            $log->info("Cron | Aucun mail trouvé pour le fallback");
        }
    }
}

echo 'done';
