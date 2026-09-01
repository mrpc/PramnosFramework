<?php
//Include composer loader
require dirname(__DIR__) . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
date_default_timezone_set('UTC');
// Redirect error_log() to /dev/null so Database::displayError() calls (which
// use error_log when no Application instance is running) do not pollute the
// PHPUnit progress output.  The code-path is still executed — coverage is
// unaffected — but the noise is discarded.
ini_set('error_log', '/dev/null');

if (!defined('PRAMNOS_TESTING')) {
    define('PRAMNOS_TESTING', true);
}

/*
 * `UNITTESTING`, here rather than in whichever test needs it first.
 *
 * It gates test-only seams in the framework — `MediaObject::move_uploaded_file()` uses `copy()`
 * under it, because `move_uploaded_file()` refuses any file that did not arrive over HTTP and no
 * test can produce one that did.
 *
 * It used to be defined inside a test's `setUp()`. A constant is process-global and cannot be
 * undefined, so whether the seam engaged depended on **which test ran first**: a later test using
 * the same seam passed when run after that one and failed when run alone or reordered. Defining it
 * with the rest of the environment makes the answer the same for every test.
 */
if (!defined('UNITTESTING')) {
    define('UNITTESTING', true);
}
/**
* The following are REQUIRED by Pramnos Framework
*/

/**
* Define Paths if paths are not defined.
* It auto-defines based on where this file is placed.
*/
/*
 * The site's base URL, and the administration area's.
 *
 * The convention: **`sURL` is the front end, `URL` is the admin panel.** An installation with no
 * separate admin area has them coincide, which is what happens below — `AdminArea::url()` derives
 * `URL` from `sURL` and the `admin` config in a real request, and there is no config here.
 *
 * `sURL` was `''`, and that quietly cost the suite a whole class of assertion. Every absolute URL
 * the framework builds is `sURL . 'something'`, so with an empty base a test could not tell a
 * correct absolute URL from a relative one — and code that must emit an absolute URL or nothing
 * (`Sitemap:` in robots.txt, `resource` in the RFC 9728 document, `hreflang` alternates) took the
 * "or nothing" branch in every test that touched it.
 *
 * With a trailing slash, because that is what production gives: `sURL . 'oauth/authorize'` is how
 * the framework composes throughout, and a base without one produces `…testoauth/authorize`.
 */
if (!defined('sURL')) {
    define('sURL', 'https://pramnosframework.test/');
}

// URL is the administration area's base, what sURL is to the site. Defined by
// Application's constructor in a real request, from the `admin` config; here so a
// template that carries it renders under test without constructing an application.
if (!defined('URL')) {
    define('URL', sURL);
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
if (!defined('LOG_PATH')) {
    define('LOG_PATH', sys_get_temp_dir());
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


require __DIR__ . '/stubs/broadcasting_shadows.php';
require __DIR__ . '/stubs/storage_shadows.php';
require __DIR__ . '/stubs/console_shadows.php';
require __DIR__ . '/stubs/console_test_shadows.php';
require __DIR__ . '/stubs/log_controller_shadows.php';

/*
 * Hash at bcrypt's cheapest cost for the duration of the test run.
 *
 * PASSWORD_DEFAULT on PHP 8.5 is bcrypt at cost 12 — 143 ms per hash, deliberately, and
 * correct everywhere except here. Enabling 2FA hashes ten backup codes, so a single call
 * cost 1.4 s, and the two TwoFactorAuthService integration classes spent 42 s between them
 * inside bcrypt. What those tests assert is replay protection, consumption and storage;
 * none of it is a property of the cost.
 *
 * Cost 4 is 0.71 ms. The algorithm is unchanged, so a hash made here is still verified by
 * the same password_verify() call the application uses — see Pramnos\Auth\PasswordHash.
 */
if (getenv(\Pramnos\Auth\PasswordHash::COST_ENV) === false) {
    putenv(\Pramnos\Auth\PasswordHash::COST_ENV . '=4');
}
