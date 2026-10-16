<?php
namespace Pramnos\Addon\Auth;
/**
 * Addon-based database authentication handler.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 *
 * @deprecated  Since v1.2 — the equivalent functionality is built into the
 *              framework as Pramnos\Auth\Drivers\DatabaseAuthDriver, which is
 *              used automatically by Auth::auth() when no addon is registered.
 *              Applications that rely on this class continue to work unchanged
 *              (backward-compatible); you may remove it from app.php when ready.
 * @license    MIT
 */

class UserDatabase extends \Pramnos\Addon\Addon
{

    /**
     *
     * @param string $username
     * @param string $password
     * @param boolean $remember
     * @param boolean $encryptedPassword
     * @return array
     */
    public function onAuth($username, $password,
        $remember = true, $encryptedPassword = false)
    {
        $driver = new \Pramnos\Auth\Drivers\DatabaseAuthDriver();
        $result = $driver->verify((string) $username, (string) $password, (bool) $encryptedPassword);
        return $result->toArray((bool) $remember);
    }

    /**
     * Αν ο χρήστης έχει cookie αλλά δεν έχει κάνει login
     */
    public function onAuthCheck()
    {
        $session = \Pramnos\Framework\Factory::getSession();
        $auth = \Pramnos\Framework\Factory::getAuth();
        if ($session->cookieget('auth') !== null
            && $session->cookieget('username') !== null) {
            // A remember token signs in through Auth::rememberLogin(); a cookie from before the
            // tokens, which held the password hash, the old way — once: the sign-in replaces it.
            if (\Pramnos\Auth\RememberTokens::isToken((string) $session->cookieget('auth'))) {
                $auth->rememberLogin();

                return;
            }
            $app = \Pramnos\Application\Application::currentInstance();
            if (is_object($app) && ($app->applicationInfo['auth']['legacy_remember'] ?? true) === false) {
                return;
            }

            $auth->auth(
                $session->cookieget('username'),
                $session->cookieget('auth'), true, true
            );
        }
    }

}
