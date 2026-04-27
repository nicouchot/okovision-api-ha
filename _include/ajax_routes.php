<?php

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Table de routes ajax — remplace le switch imbriqué de ajax.php.
*
* Format : ['type' => ['action' => closure(): void, ...], ...].
* Chaque closure encapsule l'instanciation de la classe et l'appel
* avec les paramètres requis (lecture directe de $_GET/$_POST/$_FILES).
*/
return [
    'admin' => [
        'testIp' => static function (): void {
            if (isset($_GET['ip'])) {
                (new administration())->ping($_GET['ip']);
            }
        },
        'saveInfoGe' => static function (): void {
            (new administration())->saveInfoGenerale($_POST);
        },
        'getFileFromChaudiere' => static function (): void {
            (new administration())->getFileFromChaudiere();
        },
        'importFileFromChaudiere' => static function (): void {
            (new administration())->importFileFromChaudiere($_POST);
        },
        'uploadCsv' => static function (): void {
            (new administration())->uploadCsv($_POST, $_FILES);
        },
        'getHeaderFromOkoCsv' => static function (): void {
            (new administration())->getHeaderFromOkoCsv();
        },
        'statusMatrice' => static function (): void {
            (new administration())->statusMatrice();
        },
        'deleteMatrice' => static function (): void {
            (new administration())->deleteMatrice();
        },
        'importcsv' => static function (): void {
            (new administration())->importcsv();
        },
        'getSaisons' => static function (): void {
            (new administration())->getSaisons();
        },
        'existSaison' => static function (): void {
            if (isset($_GET['date'])) {
                (new administration())->existSaison($_GET['date']);
            }
        },
        'setSaison' => static function (): void {
            (new administration())->setSaison($_POST);
        },
        'deleteSaison' => static function (): void {
            (new administration())->deleteSaison($_POST);
        },
        'updateSaison' => static function (): void {
            (new administration())->updateSaison($_POST);
        },
        'getEvents' => static function (): void {
            (new administration())->getEvents();
        },
        'setEvent' => static function (): void {
            (new administration())->setEvent($_POST);
        },
        'deleteEvent' => static function (): void {
            (new administration())->deleteEvent($_POST);
        },
        'updateEvent' => static function (): void {
            (new administration())->updateEvent($_POST);
        },
        'makeSyntheseByDay' => static function (): void {
            (new administration())->makeSyntheseByDay($_GET['date']);
        },
        'getDayWithoutSynthese' => static function (): void {
            (new administration())->getDayWithoutSynthese();
        },
        'checkUpdate' => static function (): void {
            (new AdminUpdate())->checkUpdate();
        },
        'makeUpdate' => static function (): void {
            (new AdminUpdate())->makeUpdate();
        },
        'getVersion' => static function (): void {
            (new AdminUpdate())->getVersion();
        },
        'getFileFromTmp' => static function (): void {
            (new administration())->getFileFromTmp();
        },
        'importFileFromTmp' => static function (): void {
            if (isset($_GET['file'])) {
                (new administration())->importFileFromTmp($_GET['file']);
            }
        },
        'login' => static function (): void {
            (new AdminAuth())->login($_POST['user'], $_POST['pass']);
        },
        'logout' => static function (): void {
            (new AdminAuth())->logout();
        },
        'changePassword' => static function (): void {
            (new AdminAuth())->changePassword($_POST['pass']);
        },
    ],

    'graphique' => [
        'getLastGraphePosition' => static function (): void {
            (new gstGraphique())->getLastGraphePosition();
        },
        'grapheNameExist' => static function (): void {
            if (isset($_GET['name'])) {
                (new gstGraphique())->grapheNameExist($_GET['name']);
            }
        },
        'addGraphe' => static function (): void {
            (new gstGraphique())->addGraphe($_POST);
        },
        'getGraphe' => static function (): void {
            (new gstGraphique())->getGraphe();
        },
        'updateGraphe' => static function (): void {
            (new gstGraphique())->updateGraphe($_POST);
        },
        'updateGraphePosition' => static function (): void {
            (new gstGraphique())->updateGraphePosition($_POST);
        },
        'deleteGraphe' => static function (): void {
            (new gstGraphique())->deleteGraphe($_POST);
        },
        'getCapteurs' => static function (): void {
            (new gstGraphique())->getCapteurs();
        },
        'grapheAssoCapteurExist' => static function (): void {
            (new gstGraphique())->grapheAssoCapteurExist($_GET['graphe'], $_GET['capteur']);
        },
        'addGrapheAsso' => static function (): void {
            (new gstGraphique())->addGrapheAsso($_POST);
        },
        'getGrapheAsso' => static function (): void {
            (new gstGraphique())->getGrapheAsso($_GET['graphe']);
        },
        'updateGrapheAsso' => static function (): void {
            (new gstGraphique())->updateGrapheAsso($_POST);
        },
        'updateGrapheAssoPosition' => static function (): void {
            (new gstGraphique())->updateGrapheAssoPosition($_POST);
        },
        'deleteAssoGraphe' => static function (): void {
            (new gstGraphique())->deleteAssoGraphe($_POST);
        },
    ],

    'rendu' => [
        'getGraphe' => static function (): void {
            (new gstGraphique())->getGraphe();
        },
        'getGrapheData' => static function (): void {
            (new rendu())->getGrapheData($_GET['id'], $_GET['jour']);
        },
        'getIndicByDay' => static function (): void {
            $r = new rendu();
            if (isset($_GET['timeStart'], $_GET['timeEnd'])) {
                $r->getIndicByDay($_GET['jour'], $_GET['timeStart'], $_GET['timeEnd']);
            } else {
                $r->getIndicByDay($_GET['jour']);
            }
        },
        'getIndicByMonth' => static function (): void {
            (new rendu())->getIndicByMonth($_GET['month'], $_GET['year']);
        },
        'getStockStatus' => static function (): void {
            (new rendu())->getStockStatus();
        },
        'getAshtrayStatus' => static function (): void {
            (new rendu())->getAshtrayStatus();
        },
        'getHistoByMonth' => static function (): void {
            (new rendu())->getHistoByMonth($_GET['month'], $_GET['year']);
        },
        'getTotalSaison' => static function (): void {
            (new rendu())->getTotalSaison($_GET['saison']);
        },
        'getSyntheseSaison' => static function (): void {
            (new rendu())->getSyntheseSaison($_GET['saison']);
        },
        'getSyntheseSaisonTable' => static function (): void {
            (new rendu())->getSyntheseSaisonTable($_GET['saison']);
        },
        'getAnnotationByDay' => static function (): void {
            (new rendu())->getAnnotationByDay($_GET['jour']);
        },
    ],

    'rt' => [
        'getIndic' => static function (): void {
            (new realTime())->getIndic();
        },
        'setOkoLogin' => static function (): void {
            (new realTime())->setOkoLogin($_POST['user'], $_POST['pass']);
        },
        'getData' => static function (): void {
            if (isset($_GET['id'])) {
                (new realTime())->getData($_GET['id']);
            }
        },
        'getSensorInfo' => static function (): void {
            (new realTime())->getSensorInfo($_POST['sensor']);
        },
        'saveBoilerConfig' => static function (): void {
            (new realTime())->saveBoilerConfig($_POST['config'], $_POST['description'], $_POST['date']);
        },
        'getListConfigBoiler' => static function (): void {
            (new realTime())->getListConfigBoiler();
        },
        'deleteConfigBoiler' => static function (): void {
            (new realTime())->deleteConfigBoiler($_POST['timestamp']);
        },
        'getConfigBoiler' => static function (): void {
            (new realTime())->getConfigBoiler($_POST['timestamp']);
        },
        'applyBoilerConfig' => static function (): void {
            (new realTime())->applyBoilerConfig($_POST['config']);
        },
    ],
];
