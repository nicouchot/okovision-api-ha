<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

include_once __DIR__.'/config.php';


// Import du fichier csv de la veille depuis mail vers _tmp
// http://okovision.ruemoll.com/_include/bin_v4/download_csv.php?list=45


/*
$_GET['list'] = round(time() / 86400)-19270 ; 
echo 'Numéro du jour :'.$_GET['list'].'     ';
*/

chdir('_include/bin_v4/');



ob_start();
include __DIR__.'/_include/bin_v4/get_list_mail.php';
$list_mail = ob_get_clean();
/*

$mailArray_decode = json_decode($list_mail, true)['mailArray'];

// var_dump(json_decode($mailArray, true));

$mailArray_decode = json_decode($mailArray, true);
*/

foreach ($mailArray as $nb_mail => $titre_mail){
	$nb_mail_last = $nb_mail;
}

var_dump($nb_mail_last);

$_GET['list'] = $nb_mail_last;


include_once __DIR__.'/_include/bin_v4/download_csv.php';
chdir('../..');





function getFileFromTmpNew()
{
	$files = scandir('_tmp');
	$r = [];
	foreach ($files as $f) {
	    if ('.' != $f && '..' != $f && 'matrice.csv' != $f && 'import.csv' != $f && 'readme.md' != $f && 'cookies_boiler.txt' != $f) {
	        $r[] = $f;
	    }
	}
	
	return $r;
}




$oko = new okofen();
$log = new logger();
$adm = new administration();

$files = getFileFromTmpNew();
// var_dump($files);

	
foreach ($files as $fileToDownload) {
    $date = $oko->getDateFromFilename($fileToDownload);

    if (!$oko->isDayComplete($date)) {
        $log->info("Cron | {$fileToDownload} --> need to download again");

        //$oko->getChaudiereData('http://'.CHAUDIERE.URL.'/'.$fileToDownload);
        
        $adm->importFileFromTmp($fileToDownload);
        $oko->csv2bdd();

        // Force the synthese in case it has been built already
        $oko->makeSyntheseByDay($date, true);
    } else {
        $log->info("Cron | {$fileToDownload} --> Day is complete - building synthese if required");

        // The synthese will be rebuilt only if needed
        $oko->makeSyntheseByDay($date, false);
    }
}



$s['url'] = "http://192.168.86.28:4321/r18n/log3";
$adm->importFileFromChaudiere($s);



// Import du fichier depuis _tmp vers bdd
// http://okovision.ruemoll.com/ajax.php?sid=241f9186&type=admin&action=getFileFromTmp


/*
echo '     Import du fichier depuis _tmp vers bdd...     ';

$a = new administration();

$FilesFromTmp = $a->getFileFromTmp();
var_dump($FilesFromTmp);


foreach($a->getFileFromTmp() as $FileFromTmp){
	echo $FileFromTmp;
	$a->importFileFromTmp($FileFromTmp);
}
*/




// Calcul des synthèses journalières
// http://okovision.ruemoll.com/ajax.php?sid=e29a17a3&type=admin&action=getDayWithoutSynthese
// http://okovision.ruemoll.com/ajax.php?sid=e29a17a3&type=admin&action=makeSyntheseByDay&date=2022-11-20


/*
echo '     Calcul des synthèses journalières...     ';

$DayWithoutSynthese=date("Y-m-").(date("d")-1);

echo '     Calcul de la synthèse du jour :     '.$DayWithoutSynthese;

$a->makeSyntheseByDay($DayWithoutSynthese);
*/



   
   
   
   
   
   
   



/*
	
	
http://okovision.ruemoll.com/ajax.php?sid=c53e2075&type=admin&action=importFileFromChaudiere
	
Historique V1

//http://okovision.ruemoll.com/ajax.php?sid=241f9186&type=admin&action=getFileFromTmp

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest' ;
$_GET['sid'] = session::getInstance()->getVar('sid');
$_GET['type'] = admin;
$_GET['action'] = 'getFileFromTmp';

include __DIR__.'/ajax.php';



//http://okovision.ruemoll.com/ajax.php?sid=241f9186&type=admin&action=getDayWithoutSynthese


$_GET['sid'] = session::getInstance()->getVar('sid');
$_GET['type'] = admin;
$_GET['action'] = 'getDayWithoutSynthese';

include __DIR__.'/ajax.php';
*/