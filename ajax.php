<?php

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord.
*
* Dispatcher ajax minimal : valide la requête, résout la route via
* _include/ajax_routes.php, exécute la closure correspondante.
*/

include_once 'config.php';


function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && 'xmlhttprequest' === strtolower($_SERVER['HTTP_X_REQUESTED_WITH']);
}

function is_valid(): bool
{
    return isset($_GET['sid'])
        && 0 === strcmp(session::getInstance()->getVar('sid'), $_GET['sid']);
}

if (!is_ajax()) {
    echo '<pre>xmlhttprequest needed ! </pre>';

    return;
}

if (!is_valid()) {
    header('Content-type: text/json; charset=utf-8');
    echo '{"response": false,"sessionToken": "invalid"}';

    return;
}

if (!isset($_GET['type'], $_GET['action'])) {
    return;
}

$routes = require __DIR__.'/_include/ajax_routes.php';
$type = $_GET['type'];
$action = $_GET['action'];

if (isset($routes[$type][$action]) && $routes[$type][$action] instanceof Closure) {
    $routes[$type][$action]();
}
