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
if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('SP')) {
    define('SP', 1); //Start point - to avoid running files without one.
}
if (!defined('DB_USERSTABLE')) {
    define('DB_USERSTABLE', '#PREFIX#users');
}
if (!defined('DB_USERGROUPSTABLE')) {
    define('DB_USERGROUPSTABLE', '#PREFIX#usergroups');
}
if (!defined('DB_USERGROUPSUBSCRIPTIONS')) {
    define('DB_USERGROUPSUBSCRIPTIONS', '#PREFIX#userstogroups');
}
if (!defined('DB_USERDETAILSTABLE')) {
    define('DB_USERDETAILSTABLE', '#PREFIX#userdetails');
}
if (!defined('DB_PERMISSIONSTABLE')) {
    define('DB_PERMISSIONSTABLE', '#PREFIX#permissions');
}
/**
* EOF REQUIRED DEFINES
*/