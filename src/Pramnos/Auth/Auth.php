<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Auth\Drivers\AuthDriverInterface;
use Pramnos\Auth\Drivers\AuthResult;
use Pramnos\Auth\Drivers\DatabaseAuthDriver;

/**
 * Authentication class
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Auth extends \Pramnos\Framework\Base
{

    /**
     * Last addon response (or driver result converted to array).
     * @var mixed
     */
    public $lastResponse = null;

    /**
     * Registered authentication drivers.
     *
     * null  = "use default DatabaseAuthDriver" (lazy-init on first use)
     * []    = "no drivers; log warning and fail"
     * [...] = explicitly registered drivers
     *
     * @var AuthDriverInterface[]|null
     */
    private ?array $drivers = null;

    /**
     * Callbacks invoked after every successful login.
     * @var callable[]
     */
    private array $afterLoginCallbacks = [];

    /**
     * Callbacks invoked after every logout.
     * @var callable[]
     */
    private array $afterLogoutCallbacks = [];

    /**
     * Factory method
     * @staticvar \pramnos_auth $instance
     * @return \pramnos_auth
     */
    public static function &getInstance()
    {
        static $instance=NULL;
        if (!is_object($instance)) {
            $instance = new Auth();
        }
        return $instance;
    }

    /**
     * Register a single authentication driver, replacing any previously set.
     *
     * Calling this method disables the automatic DatabaseAuthDriver fallback.
     * Use Auth::addDriver() to chain multiple drivers instead.
     *
     * @param AuthDriverInterface $driver
     * @return static
     */
    public function setDriver(AuthDriverInterface $driver): static
    {
        $this->drivers = [$driver];
        return $this;
    }

    /**
     * Append an authentication driver to the chain.
     *
     * Drivers are tried in registration order; the first successful result
     * wins.  Calling this method disables the automatic DatabaseAuthDriver
     * fallback — register DatabaseAuthDriver explicitly if it is still needed.
     *
     * @param AuthDriverInterface $driver
     * @return static
     */
    public function addDriver(AuthDriverInterface $driver): static
    {
        if ($this->drivers === null) {
            $this->drivers = [];
        }
        $this->drivers[] = $driver;
        return $this;
    }

    /**
     * Remove all registered drivers.
     *
     * After this call Auth::auth() will log a warning and return false when no
     * addon-based auth handlers are registered either.  Mainly useful in tests.
     *
     * @return static
     */
    public function clearDrivers(): static
    {
        $this->drivers = [];
        return $this;
    }

    /**
     * Register a callback to be invoked after every successful login.
     *
     * Callbacks receive the login-response array (same shape as Auth::$lastResponse).
     * Multiple callbacks are called in registration order after the built-in
     * session/cookie lifecycle completes.
     *
     * @param callable(array): void $callback
     * @return static
     */
    public function afterLogin(callable $callback): static
    {
        $this->afterLoginCallbacks[] = $callback;
        return $this;
    }

    /**
     * Register a callback to be invoked after every logout.
     *
     * Callbacks receive no arguments — logout clears the session before calling them.
     *
     * @param callable(): void $callback
     * @return static
     */
    public function afterLogout(callable $callback): static
    {
        $this->afterLogoutCallbacks[] = $callback;
        return $this;
    }

    /**
     * Logout current user.
     *
     * Resolution order:
     *   1. User addon handlers (Addon\User\*) — for BC with existing apps
     *   2. Built-in logout lifecycle (session reset + cookie clear) when no addon
     *   3. afterLogout callbacks
     */
    public function logout()
    {
        $userAddons = \Pramnos\Addon\Addon::getaddons('user');
        if (!empty($userAddons)) {
            \Pramnos\Addon\Addon::triger('Logout', 'user');
        } else {
            $this->executeDefaultLogout();
        }

        $_SESSION['logged'] = false;

        foreach ($this->afterLogoutCallbacks as $fn) {
            $fn();
        }
    }

    /**
     * Built-in logout lifecycle — equivalent to Addon\User\User::onLogout().
     *
     * Deletes the session DB record and clears auth cookies. Runs only when no
     * Addon\User\* logout handler is registered (Phase 25.4).
     */
    private function executeDefaultLogout(): void
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $request  = \Pramnos\Http\Request::getInstance();
        $session  = \Pramnos\Framework\Factory::getSession();

        if (isset($_SESSION['username'])) {
            try {
                $sql = $database->prepareQuery(
                    "DELETE FROM `#PREFIX#sessions` WHERE `uname` = %s",
                    $_SESSION['username']
                );
                $database->query($sql);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }
        }

        $past = time() - 1;
        $request->cookieset('logged',    '', $past);
        $request->cookieset('uid',       '', $past);
        $request->cookieset('username',  '', $past);
        $request->cookieset('auth',      '', $past);
        $request->cookieset('language',  '', $past);
        $session->reset();
    }

    /**
     * Runs authentication checks on every authentication module to set user
     * as logged if needed.
     */
    public function authCheck()
    {
        \Pramnos\Addon\Addon::triger('AuthCheck', 'auth');
    }

    /**
     * Authenticate and login.
     *
     * Resolution order:
     *   1. Addon-based handlers (Addon\Auth\*) — for BC with existing apps
     *   2. Registered AuthDriverInterface drivers (or default DatabaseAuthDriver)
     *   3. If neither is available, log a warning and return false
     *
     * @param string  $username          Username or email address
     * @param string  $password          Plain-text password
     * @param boolean $remember          Set a persistent login cookie
     * @param boolean $encryptedPassword The password is already a bcrypt hash
     * @param boolean $validate          Reserved (unused, kept for BC)
     * @return boolean True on successful authentication
     */
    public function auth($username, $password = '',
        $remember = true, $encryptedPassword = false, $validate = true)
    {
        // Try legacy addon system first — existing apps with UserDatabase addon
        // registered in app.php continue to work without any changes.
        $addons = \Pramnos\Addon\Addon::getaddons('auth');
        if (!empty($addons)) {
            foreach ($addons as $addon) {
                if (method_exists($addon, 'onAuth')) {
                    $response = $addon->onAuth(
                        $username, $password, $remember, $encryptedPassword, $validate
                    );
                    $this->lastResponse = $response;
                    if ($response['status'] == true) {
                        $this->triggerLogin($response);
                        return true;
                    }
                }
            }
            return false;
        }

        // No addons — resolve drivers (null = auto-create default DatabaseAuthDriver)
        $drivers = $this->drivers ?? [new DatabaseAuthDriver()];

        if (empty($drivers)) {
            \Pramnos\Logs\Logger::log(
                'Auth::auth() — no auth handlers registered. '
                . 'Add an auth addon (e.g. Pramnos\\Addon\\Auth\\UserDatabase) '
                . "to your app.php 'addons' array.",
                'auth'
            );
            return false;
        }

        foreach ($drivers as $driver) {
            $result = $driver->verify($username, $password, (bool) $encryptedPassword);
            $this->lastResponse = $result->toArray((bool) $remember);
            if ($result->success) {
                $this->triggerLogin($this->lastResponse);
                return true;
            }
        }
        return false;
    }

    /**
     * Orchestrate the post-login sequence:
     *   1. User addon (if registered) — for BC with apps that have Addon\User\User
     *   2. Built-in session/cookie lifecycle — when no user addon is present (Phase 25.4)
     *   3. afterLogin callbacks
     *
     * @param array $response Legacy login-response array (status, uid, username, auth, …)
     */
    private function triggerLogin(array $response): void
    {
        $userAddons = \Pramnos\Addon\Addon::getaddons('user');
        if (!empty($userAddons)) {
            \Pramnos\Addon\Addon::triger('Login', 'user', $response);
        } else {
            $this->executeDefaultLogin($response);
        }

        foreach ($this->afterLoginCallbacks as $fn) {
            $fn($response);
        }
    }

    /**
     * Built-in login lifecycle — equivalent to Addon\User\User::onLogin().
     *
     * Sets session variables, writes auth cookies (uid > 1 only), updates the
     * sessions table, and records lastlogin in the users table. Runs only when
     * no Addon\User\* login handler is registered (Phase 25.4).
     *
     * @param array $info Login-response array (status, uid, username, auth, email, remember)
     */
    private function executeDefaultLogin(array $info): void
    {
        if (empty($info['status']) || empty($info['username'])
            || !isset($info['uid']) || !isset($info['email']) || !isset($info['auth'])) {
            return;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();
        $lang     = \Pramnos\Framework\Factory::getLanguage();
        $request  = \Pramnos\Http\Request::getInstance();

        $_SESSION['logged']   = true;
        $_SESSION['uid']      = $info['uid'];
        $_SESSION['username'] = $info['username'];
        $_SESSION['auth']     = $info['auth'];

        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $remember = $info['remember'] ?? true;

        if ((int) $info['uid'] > 1) {
            if ($remember) {
                $request->cookieset('logged',   true);
                $request->cookieset('uid',      $info['uid']);
                $request->cookieset('username', $info['username']);
                $request->cookieset('auth',     $info['auth']);
                $request->cookieset(
                    'language',
                    \Pramnos\Application\Settings::getSetting('default_language')
                );
            }
        }

        try {
            $sql = $database->prepareQuery(
                "UPDATE `#PREFIX#sessions` "
                . "SET `uname` = %s, `time` = %s, `host_addr` = %s, `guest` = '0' "
                . "WHERE `host_addr` = %s",
                $info['username'], (string) time(), $remoteIp, $remoteIp
            );
            $database->query($sql);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log($ex->getMessage());
        }

        try {
            $sqlLastLogin = $database->prepareQuery(
                "UPDATE `#PREFIX#users` SET `lastlogin` = %d, `language` = %s WHERE `userid` = %d",
                time(), $lang->currentlang(), (int) $info['uid']
            );
            $database->query($sqlLastLogin);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log($ex->getMessage());
        }
    }

    /**
     * Set a user or a group permitions for an action
     * @todo  Upgrade code to use $db->prepare stuff
     * @param int $id User or Group id
     * @param string $moduletype Type of the module (module/admin)
     * @param string $moduleid id of the module
     * @param string $what Action to set permitions for
     * @param int $elementid Mostly unused
     * @param string $onwhat User/Group
     * @param string $extraflag DEPRECATED
     * @param bool $value The value - 1: Allowed 2: Denied
     * @return int 1: updated permition, 2: inserted permition 0: Error
     */
    function setaccess($id, $moduletype, $moduleid, $what,
        $elementid, $onwhat, $extraflag, $value)
    {
        $permissions = pramnos_factory::getPermissions();
        if ($value == 1) {
            $permissions->allow(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
        elseif ($value == 2) {
            $permissions->removePermission(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
        else {
            $permissions->deny(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
    }

    /**
     * Check if a user or a group is permited for an action.
     * @see groupaccess
     * @global array $config
     * @param int $userid User id or Group id
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what (what action to check for)
     * @param int $elementid Mostly unused
     * @param string $check User/Group
     * @return bool True if user has access
     * @todo Some caching to avoid multiple database queries
     */
    function useraccess($userid, $moduletype, $moduleid,
        $what = 'read', $elementid = '', $check = 'user')
    {
        $permissions = & pramnos_factory::getPermissions();
        return $permissions->isAllowed(
            $userid, $moduleid, $what, $elementid, $moduletype, $check
        );
    }

    /**
     * Check a group's permitions
     * @global array $config
     * @param int $groupid
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what
     * @param int $elementid
     * @return int 0=deny 1=grand 2=not specified
     */
    function groupaccess($groupid, $moduletype, $moduleid,
        $what = 'read', $elementid = '')
    {
        $permissions = & pramnos_factory::getPermissions();
        return $permissions->isAllowed(
            $groupid, $moduleid, $what, $elementid, $moduletype, 'group'
        );
    }

}