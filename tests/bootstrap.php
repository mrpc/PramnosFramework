<?php
//Include composer loader
require dirname(__DIR__) . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
date_default_timezone_set('UTC');
/**
* The following are REQUIRED by Pramnos Framework
*/

/**
* Define Paths if paths are not defined.
* It auto-defines based on where this file is placed.
*/
if (!defined('URL')) {
    define('URL', ''); //URL (in case of secondary applications)
}
if (!defined('sURL')) {
    define('sURL', ''); //URL (in case of secondary applications)
}
if (!defined('ROOT')) {
    define('ROOT', dirname(dirname(__FILE__)));
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('SP')) {
    define('SP', 1); //Start point - to avoid running files without one.
}
/**
* EOF REQUIRED DEFINES
*/