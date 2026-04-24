<?php 
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : skydarc
* Utilisation commerciale interdite sans mon accord
*/

	$config = json_decode(file_get_contents("../../config.json"), true);
	DEFINE('CHAUDIERE',$config['chaudiere']); 
	DEFINE('PORT_JSON',$config['port_json']); 
	DEFINE('PASSWORD_JSON',$config['password_json']);
	
	$ch = curl_init('http://'.CHAUDIERE.':'.PORT_JSON.'/'.PASSWORD_JSON.'/all?');

	// Return response instead of outputting
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$raw = curl_exec($ch);
	$capt = ($raw === false) ? '' : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');

	if ($capt) {
		$json['data'] = $capt;
		$json['response'] = true;

		echo json_encode($json, JSON_HEX_AMP);

	} else {
		$json['response'] = false;
		echo json_encode($json);
	}
?>