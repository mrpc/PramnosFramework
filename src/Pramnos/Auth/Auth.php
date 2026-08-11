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
     * The authentication method for the next login the built-in lifecycle
     * establishes — recorded in the activity log so a password login, a
     * two-factor step-up and a passkey step-up are distinguishable rather than
     * all logged as a generic `login`.
     *
     * Set by {@see self::setLoginMethod()} (from {@see LoginFlow}) BEFORE the
     * session is established, consumed by {@see self::buildLoginResponse()} and
     * reset to null by {@see self::triggerLogin()} so it never leaks into a
     * subsequent login on the same instance. A null value means "password" —
     * the default for the plain {@see self::auth()} path, which never sets it.
     *
     * @var string|null
     */
    private ?string $loginMethod = null;

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

        // Capture the user id before the session is reset below, so the
        // logout can still be attributed in the activity log.
        $userId = (int) ($_SESSION['uid'] ?? 0);

        // Deactivate the web-session token (usertokens) before the session is
        // torn down, so it no longer counts as an active session/device.
        if ($userId > 1) {
            try {
                (new \Pramnos\User\User($userId))->invalidateWebSessionToken();
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::log('Web-session token invalidation failed: ' . $ex->getMessage());
            }
        }

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

        // Record the logout (built-in path only; see executeDefaultLogin).
        ActivityLog::record($userId, 'logout');
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
     * Verify user credentials without performing login actions.
     *
     * @param string $username          Username or email address
     * @param string $password          Plain-text password
     * @param boolean $encryptedPassword The password is already a bcrypt hash
     * @param boolean $remember          Include remember flag in driver response array
     * @return array|false The verification response array on success, or false on failure.
     */
    public function verifyCredentials(
        string $username,
        string $password,
        bool   $encryptedPassword = false,
        bool   $remember = false,
        bool   $validate = true
    ): array|false {
        // 1. Try legacy addon system first
        $addons = \Pramnos\Addon\Addon::getaddons('auth');
        if (!empty($addons)) {
            foreach ($addons as $addon) {
                if (method_exists($addon, 'onAuth')) {
                    $response = $addon->onAuth(
                        $username, $password, $remember, $encryptedPassword, $validate
                    );
                    $this->lastResponse = $response;
                    if ($response && !empty($response['status']) && $response['status'] == true) {
                        return $response;
                    }
                }
            }
            return false;
        }

        // 2. Try registered drivers (or default DatabaseAuthDriver)
        $drivers = $this->drivers ?? [new DatabaseAuthDriver()];

        if (empty($drivers)) {
            \Pramnos\Logs\Logger::log(
                'Auth::verifyCredentials() — no auth handlers registered. '
                . 'Add an auth addon (e.g. Pramnos\\Addon\\Auth\\UserDatabase) '
                . "to your app.php 'addons' array.",
                'auth'
            );
            return false;
        }

        foreach ($drivers as $driver) {
            $result = $driver->verify($username, $password, $encryptedPassword);
            $response = $result->toArray($remember);
            $this->lastResponse = $response;
            if ($result->success) {
                return $response;
            }
        }
        return false;
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
        $response = $this->verifyCredentials(
            (string) $username,
            (string) $password,
            (bool) $encryptedPassword,
            (bool) $remember,
            (bool) $validate
        );

        if ($response === false) {
            return false;
        }

        $this->lastResponse = $response;
        $this->triggerLogin($response);
        return true;
    }

    /**
     * Tag the authentication method for the next login established through the
     * built-in lifecycle, so the activity log can tell a password login from a
     * two-factor or passkey step-up.
     *
     * This is the BC-safe mechanism required by CLAUDE.md §6: rather than adding
     * a parameter to the public {@see self::loginById()} signature (which
     * subclasses override), the caller — normally {@see LoginFlow} — sets the
     * method on the Auth instance just before establishing the session. The
     * value is consumed by the very next login and then reset, so a stale tag
     * can never mislabel a later login.
     *
     * @param string|null $method 'password' | 'twofactor' | 'passkey' | custom,
     *                             or null to fall back to the 'password' default.
     */
    public function setLoginMethod(?string $method): void
    {
        $this->loginMethod = $method;
    }

    /**
     * Establish a login session for an already-verified user, WITHOUT a password.
     *
     * This is the passwordless counterpart to {@see self::auth()}: the caller has
     * already proven the user's identity by some other means (a passkey/WebAuthn
     * assertion, a completed second-factor step-up, an SSO assertion, …) and only
     * needs the same session bootstrap that a password login performs.
     *
     * It reuses the identical post-login path as {@see self::auth()}
     * ({@see self::triggerLogin()}): the user addon's Login handler when one is
     * registered, otherwise the built-in lifecycle, then the afterLogin
     * callbacks. Nothing about the existing password path changes — this is a
     * purely additive entry point (BC, CLAUDE.md §6).
     *
     * @param int  $userId   users.userid of the already-verified user.
     * @param bool $remember Set a persistent login cookie (built-in path only).
     * @return bool True when the user exists and is active and the session was
     *              established; false otherwise.
     */
    public function loginById(int $userId, bool $remember = true): bool
    {
        $response = $this->buildLoginResponse($userId, $remember);
        if ($response === false) {
            return false;
        }

        $this->lastResponse = $response;
        $this->triggerLogin($response);
        return true;
    }

    /**
     * Build the login-response array for a user id, mirroring the shape a driver
     * produces ({@see AuthResult::toArray()}) so {@see self::triggerLogin()} and
     * the user addon's onLogin() accept it unchanged.
     *
     * Honours the same active-status gate as the password path (0/2/5 blocked).
     *
     * @return array<string,mixed>|false
     */
    protected function buildLoginResponse(int $userId, bool $remember): array|false
    {
        if ($userId <= 0) {
            return false;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "SELECT `userid`, `username`, `email`, `password`, `active` "
            . "FROM `#PREFIX#users` WHERE `userid` = %d LIMIT 1",
            $userId
        );
        $result = $database->query($sql);

        if (!$result || !isset($result->numRows) || $result->numRows == 0) {
            return false;
        }

        $row    = $result->fields;
        $active = $row['active'] ?? 1;
        // Blocked states: 0 inactive, 2 deleted, 5 banned ('t' = active in PG).
        if (($active == 0 && $active != 't') || $active == 2 || $active == 5) {
            return false;
        }

        return [
            'status'   => true,
            'uid'      => (int) $row['userid'],
            'username' => (string) $row['username'],
            'email'    => (string) ($row['email'] ?? ''),
            'auth'     => (string) ($row['password'] ?? ''),
            'remember' => $remember,
            // Carry the caller-tagged method (set via setLoginMethod() just
            // before establishSession) into the response so executeDefaultLogin
            // records it; null falls back to 'password'.
            'method'   => $this->loginMethod ?? 'password',
        ];
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

        // Consume the one-shot login-method tag: reset so it can never leak
        // into a subsequent login established on the same Auth instance.
        $this->loginMethod = null;
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

        // Session fixation: the id that carried the anonymous visitor must not
        // carry the authenticated one. Regenerating here — before anything is
        // written — means everything below lands on the new session, and an id
        // planted beforehand is worthless. `session.use_strict_mode` blocks only
        // the version of this attack where the attacker invents an id; this
        // blocks the version where they first get a real one from the server.
        \Pramnos\Http\Session::getInstance()->regenerateId();

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

        // Record the login in the activity log. This lives in the built-in
        // lifecycle only (not triggerLogin), so apps that bring their own
        // Addon\User login handler take the addon path and are never
        // double-logged. Self-guarding: a no-op when the table is absent.
        ActivityLog::record((int) $info['uid'], 'login', [
            'method'   => $info['method'] ?? 'password',
            'remember' => (bool) ($info['remember'] ?? false),
        ]);

        // Create the web-session token so per-request activity is attributed in
        // usertokens/tokenactions (Application::exec() logs each request against
        // $_SESSION['usertoken']). Built-in path only; best-effort.
        if ((int) $info['uid'] > 1) {
            try {
                (new \Pramnos\User\User((int) $info['uid']))
                    ->createWebSessionToken($remoteIp !== '' ? $remoteIp : null);
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::log('Web-session token creation failed: ' . $ex->getMessage());
            }
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
        $permissions = Permissions::getInstance();
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
        $permissions = Permissions::getInstance();
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
        $permissions = Permissions::getInstance();
        return $permissions->isAllowed(
            $groupid, $moduleid, $what, $elementid, $moduletype, 'group'
        );
    }

}