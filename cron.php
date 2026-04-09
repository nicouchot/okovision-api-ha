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
$yesterdaySyntheseDone = false;
$log->info("Cron | Téléchargement log0");
if ($oko->getChaudiereData($urlLog0)) {
    $oko->csv2bdd();
    $oko->makeSyntheseByDay($yesterday, true);
    $oko->makeSyntheseByDay($today, true);
    $yesterdaySyntheseDone = true;
    $log->info("Cron | log0 importé — synthèses {$yesterday} et {$today} recalculées");
} else {
    $log->info("Cron | log0 indisponible");
}

// ── ÉTAPE 1b : snapshot temps réel — /all? → aujourd'hui ─────────────────
// log0 est écrit une fois à minuit : il ne contient pas les données de la
// journée courante. On complète avec un snapshot /all? qui insère une ligne
// par appel du cron dans oko_historique_full pour aujourd'hui.
// col_startCycle est toujours NULL à l'import (csv2bdd) ; recalcStartCycleForDay()
// recalcule les fronts montants depuis toutes les données du jour en base, lors
// de chaque makeSyntheseByDay() — élimine la dépendance à $old_status=0.
sleep(2); // respecte le rate-limit de l'API (2500ms entre requêtes)
if ($oko->storeLiveSnapshot()) {
    $oko->csv2bdd();
    $log->info("Cron | Snapshot live importé pour {$today}");
} else {
    $log->info("Cron | Snapshot live indisponible");
}

// ── ÉTAPE 2 : vérification veille à partir de 00h01 ───────────────────────
// log0 = fichier complet de la veille écrit par la chaudière à minuit.
// Si la veille est toujours incomplète après l'étape 1 (log0 pas encore
// entièrement écrit, ou téléchargement raté), on retente log0 à chaque
// appel du cron jusqu'à ce que isDayComplete(yesterday) soit vrai.
// Note : INSERT IGNORE dans csv2bdd() garantit qu'un re-import est sans effet
// sur les lignes déjà présentes — seuls les points manquants sont ajoutés.
if ($hour > 0 || ($hour === 0 && $minute >= 1)) {
    if (!$oko->isDayComplete($yesterday)) {
        $log->info("Cron | Veille incomplète — nouvelle tentative log0 ({$yesterday})");
        sleep(2); // rate-limit API
        if ($oko->getChaudiereData($urlLog0)) {
            $oko->csv2bdd();
            $oko->makeSyntheseByDay($yesterday, true);
            $log->info("Cron | log0 re-importé — veille complétée ({$yesterday})");
        } else {
            $log->info("Cron | log0 indisponible — nouvelle tentative au prochain appel");
        }
    } elseif (!$yesterdaySyntheseDone) {
        // synthèse uniquement si pas déjà calculée à l'étape 1
        $log->info("Cron | Veille complète ({$yesterday})");
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
                    $adm->importFileFromTmp($fileToDownload); // appelle csv2bdd() en interne
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
