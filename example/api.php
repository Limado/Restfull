<?php
header('Content-Type: text/html; charset=utf-8');
header("Access-Control-Allow-Origin:*");
ini_set("display_errors", 1);
require_once('../vendor/autoload.php');
ob_start(); //output buffering

use RestEnApi\renapi;
/**
 *  Configuracion del servidor, models y actions
 * 
*/
$pathToConfig = realpath(dirname(__FILE__)).'/api.config.json';
$configJson = json_decode(file_get_contents($pathToConfig));

$server = new Renapi($configJson);

/**
 * Definions contiene las funciones que se que se configuraron para cada model
 */
require_once "./definitions/" . $server->model . ".php";

/***************************************************************/
$server->start();
/***************************************************************
****************************************************************/
ob_end_flush();