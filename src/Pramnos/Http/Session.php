<?php

namespace Pramnos\Http;

use Pramnos\Framework\Base;

/**
 * Get user request and translate it
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Session extends Base
{
    private $_token;
    private $_lastToken = null;

    /**
     * Returns a secret token created on session start
     * @return string
     */
    public function getToken()
    {
        $this->ensureStarted();
        return $this->_token;
    }

    /**
     * Creates a URL snapshot in the session. Example of use: return to a url
     * after authentication
     * @param string $url
     * @return $this
     */
    public function snapshot($url)
    {
        $_SESSION['_snapshot'] = $url;
        return $this;
    }

    /**
     * Return and reset the saved snapshot (if set), or false
     * in case it's not set.
     * @return string|boolean
     */
    public function getSnapshot()
    {
        if (isset($_SESSION['_snapshot'])) {
            $snapshot = $_SESSION['_snapshot'];
            unset($_SESSION['_snapshot']);
            return $snapshot;
        } else {
            return false;
        }
    }

    public function deleteSnapshot()
    {
        self::staticDeleteSnapshot();
        return $this;
    }

    public static function staticDeleteSnapshot()
    {
        if (isset($_SESSION['_snapshot'])) {
            unset($_SESSION['_snapshot']);
        }
    }

    /**
     * Get a unique fingerprint for the current user's browser environment.
     * Used as the value for CSRF tokens to prevent token reuse in different environments.
     * @param bool $useIp Whether to include the IP address in the fingerprint (IP pinning)
     * @return string
     */
    public function getFingerprint(bool $useIp = false): string
    {
        // The siblings that call this — checkTokenValue(), getTokenField() —
        // already start the session first, so this is a no-op for them. Called
        // directly it is not: `$this->_token` would be null and the HMAC key
        // with it, which PHP 8.5 deprecates and a later version will reject.
        $this->ensureStarted();

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'none';
        // Behind a proxy REMOTE_ADDR is the proxy, identical for every visitor,
        // so pinning to it would pin to nothing. clientIp() is the real client
        // wherever the application has declared its proxies.
        //
        // The fallback reproduces the original expression exactly, and that
        // precision matters: this value is hashed into a token issued by one
        // request and verified by the next, so any change to it invalidates
        // every form in flight. `REMOTE_ADDR` set to an empty string is not the
        // same as `REMOTE_ADDR` absent — `?? 'none'` never fired for the former
        // — and collapsing the two broke a reference application's login.
        $ip = '';
        if ($useIp) {
            $ip = \Pramnos\Http\Request::clientIp();
            if ($ip === '') {
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'none';
            }
        }
        return hash_hmac('sha256', $ua . $ip, $this->_token);
    }

    /**
     * Return the synchronizer CSRF token for the current session.
     * Generates a new 256-bit token on first call per session.
     * Use with CsrfMiddleware::tokenField() to protect forms.
     */
    public function getCsrfToken(): string
    {
        $this->ensureStarted();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token against the session token using a
     * timing-safe comparison. Returns false if no token has been generated yet.
     */
    public function verifyCsrfToken(string $submitted): bool
    {
        $this->ensureStarted();
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }

    /**
     * Regenerate the synchronizer CSRF token.
     * Call after login/logout or any privilege-level change.
     */
    public function regenerateCsrfToken(): void
    {
        $this->ensureStarted();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Check if the CSRF token provided in the request is valid.
     * @param string $method Request method (request, post, get, etc.)
     * @param string $prefix Optional prefix for the token field name
     * @param bool $useIpHash Whether to verify the IP fingerprint (IP pinning)
     * @return bool
     */
    public function checkToken($method = 'request', $prefix = '', $useIpHash = false)
    {
        $this->ensureStarted();
        $request = new Request();
        $token = $request->get($prefix . $this->_token, false, $method);
        
        return $this->checkTokenValue($token, $useIpHash);
    }

    /**
     * Check if a given token value matches the session fingerprint.
     *
     * `hash_equals()` rather than `===`, so the comparison takes the same time
     * whatever the submitted value is. The token itself is sound — an HMAC-SHA256
     * keyed by the session's own 256-bit random token, so it cannot be predicted —
     * but `===` returns as soon as two bytes differ, and a comparison that leaks how
     * far it got is not something to leave in a security check on the argument that
     * exploiting it would be difficult.
     *
     * This is the legacy CSRF path, still used by the account controllers, the
     * settings form and the scaffolded templates. The synchronizer-token path
     * ({@see verifyCsrfToken()}) has always been constant-time; the two are now
     * equivalent in that respect, so neither is the weaker choice.
     *
     * A non-string value is refused rather than coerced: `hash_equals()` requires
     * strings, and a request that sent an array where a token belongs has not
     * submitted a token.
     *
     * @param mixed $value The token value to verify
     * @param bool $useIpHash Whether to verify the IP fingerprint (IP pinning)
     * @return bool
     */
    public function checkTokenValue($value, $useIpHash = false): bool
    {
        $this->ensureStarted();

        if (!is_string($value)) {
            return false;
        }

        return hash_equals($this->getFingerprint($useIpHash), $value);
    }

    /**
     * Returns a hidden input field for CSRF protection
     * @param bool $useIpHash Whether to include the IP address in the fingerprint (IP pinning)
     * @return string
     */
    public function getTokenField($useIpHash = false)
    {
        $this->ensureStarted();
        return '<input type="hidden" name="' . $this->_token . '" value="' . $this->getFingerprint($useIpHash) . '" />';
    }

    /**
     * The CSRF field this session expects, as `[name => value]`.
     *
     * What {@see getTokenField()} renders, without the markup — for a test that has to
     * POST a form, and for any caller building a request body rather than a page.
     *
     * Both halves were unreachable: the field's **name** is the private `$_token`, and
     * its **value** is `getFingerprint()`, which is also not public. So a test that
     * wanted to submit the register form had to render the hidden input and parse it —
     *
     * ```php
     * preg_match('/name="([^"]+)" value="([^"]+)"/', $session->getTokenField(), $found);
     * ```
     *
     * — a regular expression over generated HTML, in every application that tests a
     * form. Those are the highest-value tests in an application with accounts in it, and
     * the ones most likely to be skipped, because the first hour of writing one goes on
     * this rather than on the behaviour.
     *
     * Merge it into the body:
     *
     * ```php
     * $client->post('/register', ['username' => 'x'] + $session->tokenParameters());
     * ```
     *
     * @param  bool $useIpHash Pin the token to the client IP, as `getTokenField()` does
     * @return array<string, string> One entry: the field name, and the fingerprint
     */
    public function tokenParameters(bool $useIpHash = false): array
    {
        $this->ensureStarted();

        return [$this->_token => $this->getFingerprint($useIpHash)];
    }

    /**
     * Manually regenerates the CSRF token.
     * Useful after login, logout, or other sensitive operations.
     * @return void
     */
    public function regenerateToken(): void
    {
        $this->ensureStarted();
        $_SESSION['token'] = bin2hex(random_bytes(32));
        $this->_token = $_SESSION['token'];
        $this->_lastToken = $_SESSION['token'];
    }

    /**
     * Ensure the session is started and the CSRF token is initialized.
     * This keeps the Session public API safe even if callers did not invoke
     * start() explicitly before using token helpers.
     *
     * Public since lazy mode: anything that is about to write to `$_SESSION` on a
     * request that may not have a session yet calls this first. Idempotent, and one
     * `session_status()` check when there is already one.
     *
     * @return void
     */
    public function ensureStarted()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($this->_token)) {
            $this->start();
        }
    }

    /**
     * Is the visitor already carrying a session, or would starting one create it?
     *
     * The distinction is what makes lazy mode safe. A request arriving with a session
     * cookie has state waiting for it, and the two hundred-odd places in this framework
     * that read `$_SESSION` directly must find it — `staticIsLogged()` above all, which
     * would otherwise report every signed-in visitor as anonymous. A request arriving
     * without one has nothing to lose by not being given a session it never asked for.
     *
     * Reads the cookie rather than `session_status()`, because the point is to answer
     * *before* anything has started a session.
     *
     * @return bool
     */
    public static function hasExistingCookie(): bool
    {
        $name = session_name();

        return $name !== false && isset($_COOKIE[$name]) && $_COOKIE[$name] !== '';
    }

    /**
     * Start the session, but only for a visitor who already has one.
     *
     * What lazy mode calls instead of {@see start()}. A returning visitor gets exactly
     * what they got before; a first-time anonymous one gets no session and therefore no
     * `Set-Cookie`, which is the whole reason the page cache could never store anything.
     *
     * @return bool Whether a session was started
     */
    public function startIfPresent(): bool
    {
        if (!static::hasExistingCookie()) {
            return false;
        }

        $this->start();

        return true;
    }



    /**
     * Check if user is logged in or not
     * @global boolean $unittesting_logged If is set to true in PHPUNIT tests,
     * assume we are logged in.
     * @return boolean
     */
    public static function staticIsLogged()
    {
        //Override the normal session status if we are in unit testing
        //and set the global $unittesting_logged to true
        if (defined('UNITTESTING') && constant('UNITTESTING') == true) {
            global $unittesting_logged;
            if (isset($unittesting_logged)
                    && $unittesting_logged == true) {
                return true;
            }
        }
        if (!isset($_SESSION['logged'])
            || !isset($_SESSION['uid']) || $_SESSION['uid'] <= 1) {
            return false;
        }

        /**
         * Has this session been valid too long, or been left alone too long?
         *
         * Checked here rather than in a middleware because this is the one function every
         * "is somebody signed in" path goes through — `getCurrentUser()`, the controllers'
         * auth guard, the API's session exchange. A timeout enforced in a middleware is a
         * timeout the paths that skip the middleware do not have.
         *
         * Both limits are off unless an application asks for them
         * ({@see \Pramnos\Auth\SecurityPolicy}), because a session that ends while
         * somebody is working is a support call, and only the application knows whether
         * that trade is worth making.
         */
        if (!self::withinLifetime()) {
            return false;
        }

        // Touched only after the checks, so idleness is measured from the previous request
        // rather than from this one.
        $_SESSION['last_activity'] = time();

        return true;
    }

    /**
     * Is the current session still inside the configured lifetimes?
     *
     * Two questions, and they are not the same one. *Idle* is "nobody is there": a session
     * left on a shared screen. *Absolute* is "this has been valid long enough": a session
     * used every day for a year, which no amount of activity should keep alive for ever.
     *
     * A session with no recorded start or activity is treated as starting now rather than
     * as infinitely old — otherwise switching either limit on would sign out every
     * existing session at once, which is how a security setting gets switched back off.
     */
    protected static function withinLifetime(): bool
    {
        $idle     = \Pramnos\Auth\SecurityPolicy::sessionIdleTimeout();
        $absolute = \Pramnos\Auth\SecurityPolicy::sessionAbsoluteTimeout();

        if ($idle === 0 && $absolute === 0) {
            return true;
        }

        $now = time();

        if ($idle > 0) {
            $last = (int) ($_SESSION['last_activity'] ?? 0);
            if ($last === 0) {
                $_SESSION['last_activity'] = $now;
            } elseif ($now - $last > $idle) {
                return false;
            }
        }

        if ($absolute > 0) {
            $started = (int) ($_SESSION['login_time'] ?? 0);
            if ($started === 0) {
                $_SESSION['login_time'] = $now;
            } elseif ($now - $started > $absolute) {
                return false;
            }
        }

        return true;
    }

    /**
     * Put the session in the store the application asked for, before it starts.
     *
     * ## Why this is here rather than in `php.ini`
     *
     * PHP keeps sessions in local files unless told otherwise, and on **one** server that
     * is correct and free. On two it is the quietest failure in a deployment: a visitor
     * whose next request lands on the other node has no session, so they are signed out at
     * random — and it looks like an expiry, a cookie problem, anything but a load
     * balancer. The framework never said anything about this, and the only remedies were
     * sticky sessions at the balancer or a `php.ini` nobody deploying an application
     * necessarily controls.
     *
     * The handler **is** settable at run time, as long as it is set before
     * `session_start()` — which is exactly the window this method runs in, next to
     * `use_strict_mode` and for the same reason. Verified rather than assumed:
     * `session_module_name('redis')` returns the previous handler and the session lands in
     * Redis.
     *
     * ```php
     * // app/config/app.php
     * 'session' => [
     *     'handler' => 'redis',                  // any handler PHP has registered
     *     'path'    => 'tcp://redis:6379',       // optional — see below
     * ],
     * ```
     *
     * `APP_SESSION_HANDLER` and `APP_SESSION_PATH` in the environment win over both, so a
     * deployment can switch stores without an edit to a committed file.
     *
     * **With no `path`, the cache's own host is used.** An application that has configured
     * Redis for its cache has already said where Redis is, and a second copy of a hostname
     * is a second thing to get wrong — and one that only shows up as random sign-outs.
     *
     * ## What it does not do
     *
     * It does not fail. A handler PHP has not registered, a store that is down, a
     * misspelling — any of those leave the session on files, which is a working single
     * server rather than a site that will not boot. {@see \Pramnos\Health\Checks\SessionStorageCheck}
     * is what says so, because "it works on one node" is precisely the state nobody
     * notices.
     *
     * @return void
     */
    protected static function applyConfiguredStore(): void
    {
        $handler = static::configuredSessionValue('handler', 'APP_SESSION_HANDLER');

        if ($handler === '' || $handler === ini_get('session.save_handler')) {
            // Nothing asked for, or already there.
            return;
        }

        // `session_module_name()` rather than `ini_set()`: it is the documented way to
        // change the handler, it returns the previous one, and it warns rather than
        // failing silently when the module is unknown.
        if (@session_module_name($handler) === false) {
            \Pramnos\Logs\Logger::log(
                'Session store: PHP has no "' . $handler . '" save handler registered, so '
                . 'sessions stay on ' . ini_get('session.save_handler') . '. '
                . 'Registered handlers are listed by `php -i | grep "save handlers"`.',
                'auth'
            );

            return;
        }

        $path = static::configuredSessionValue('path', 'APP_SESSION_PATH');

        if ($path === '') {
            $path = static::sessionPathFromCache($handler);
        }

        if ($path !== '') {
            ini_set('session.save_path', $path);
        }
    }

    /**
     * One session setting, from the environment first and `app.php` second.
     *
     * The environment wins because that is where a deployment differs from a checkout —
     * the same reason `envvar()` is what the scaffolded `app.php` reads everything through.
     *
     * @param string $key    Key under the `session` settings array
     * @param string $envVar Environment variable that overrides it
     */
    protected static function configuredSessionValue(string $key, string $envVar): string
    {
        $fromEnv = getenv($envVar);
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        if (isset($_ENV[$envVar]) && trim((string) $_ENV[$envVar]) !== '') {
            return trim((string) $_ENV[$envVar]);
        }

        $configured = \Pramnos\Application\Settings::getSetting('session');
        if (!is_array($configured)) {
            return '';
        }

        return trim((string) ($configured[$key] ?? ''));
    }

    /**
     * Where the cache says its Redis or Memcached lives, as a session save path.
     *
     * So an application that has configured one host does not configure it twice. The two
     * are the same server in every deployment that has either, and a second copy is a
     * second thing to get wrong — silently, because a wrong session host is a site that
     * signs people out rather than one that errors.
     *
     * Returns `''` when the cache has nothing to say, in which case PHP's own default for
     * that handler applies — which for Redis is `tcp://127.0.0.1:6379` and right on a
     * single-host installation.
     */
    protected static function sessionPathFromCache(string $handler): string
    {
        $cache = \Pramnos\Application\Settings::getSetting('cache');
        if (!is_array($cache)) {
            return '';
        }

        $host = trim((string) ($cache['hostname'] ?? ''));
        if ($host === '') {
            return '';
        }

        $port = (int) ($cache['port'] ?? 0);

        return match ($handler) {
            'redis'     => 'tcp://' . $host . ':' . ($port > 0 ? $port : 6379),
            // Memcached's save path is a plain host:port list, with no scheme.
            'memcached',
            'memcache'  => $host . ':' . ($port > 0 ? $port : 11211),
            default     => '',
        };
    }

    /**
     * Check if user is logged in or not
     * @return boolean
     */
    public function isLogged()
    {
        return self::staticIsLogged();
    }

    /**
     * Factory method
     * @staticvar Session|null $instance
     * @return Session
     */
    public static function &getInstance()
    {
        static $instance=NULL;
        if (!is_object($instance)) {
            $instance = new Session();
        }
        return $instance;
    }

    /**
     * Start the session and set a secret token
     * @return string the Session ID
     */
    function start()
    {
        if (session_id() == '' && !headers_sent()) {
            // Must be called before session_start() — PHP ignores session
            // ini changes on an already-active session. Rejects session IDs
            // not generated by the server (prevents URL/cookie fixation).
            ini_set('session.use_strict_mode', '1');

            // And the store, for the same reason and in the same window.
            static::applyConfiguredStore();

            $secure = static::isHttps();
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            @session_start();
        }

        // Generate a stable token per session to support multiple tabs.
        // Upgrade existing short tokens (pre-8.1 sessions) to 256-bit entropy.
        if (!isset($_SESSION['token']) || strlen($_SESSION['token']) < 32) {
            $_SESSION['token'] = bin2hex(random_bytes(32));
        }

        $this->_token = $_SESSION['token'];
        $this->_lastToken = $_SESSION['token'];
        
        return session_id();
    }

    /**
     * Give the current session a new id, keeping its contents.
     *
     * Called at every privilege change. On **logout** it is part of wiping the
     * session; on **login** it is what stops session fixation — an attacker who
     * planted a session id the victim then logs in with must not end up sharing
     * the authenticated session.
     *
     * `session.use_strict_mode` (set in {@see start()}) already refuses an id
     * the server never issued, which blocks the naive version of that attack.
     * It does not block the version where the attacker first obtains a valid id
     * from the server and plants that.
     *
     * Guarded on both sides: without an active session there is nothing to
     * regenerate, and after headers are sent PHP cannot set the new cookie —
     * in both cases doing nothing is better than a warning, and the caller is
     * not in a position to do anything about it either.
     *
     * @return bool Whether the id was actually replaced
     */
    public function regenerateId(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return false;
        }

        return session_regenerate_id(true);
    }

    /**
     * Resets all session data for authentication
     */
    function reset()
    {
        $_SESSION['logged'] = false;
        $_SESSION['uid'] = 1;
        $_SESSION['username'] = '';
        $_SESSION['cookie'] = 0;
        $_SESSION['remember'] = false;
        if (isset($_SESSION['language']) == false) {
            $_SESSION['language'] = "english";
        }
        // Invalidate the old session ID to prevent session fixation after a
        // privilege change (login / logout). delete_old_session=true ensures
        // the previous session file is removed immediately.
        $this->regenerateId();

        $this->regenerateToken();
        $this->regenerateCsrfToken();
    }

    /**
     * Returns true when the current request reached the client over HTTPS.
     *
     * `$_SERVER['HTTPS']` answers for the connection this process received, which
     * behind a TLS-terminating proxy is the plaintext hop between the proxy and PHP.
     * The user's browser is on HTTPS; this variable is empty. Everything keyed off
     * this answer then behaves as though the site were plaintext — and the one that
     * matters is the session cookie, which loses its `secure` flag and starts
     * travelling on any http:// request to the domain.
     *
     * So `X-Forwarded-Proto` is consulted too, but **only when the peer is a declared
     * trusted proxy** ({@see \Pramnos\Http\ClientIpResolver}, `trusted_proxies` in
     * the application config). The header is client-supplied: trusting it
     * unconditionally would let any visitor assert `https` and hand themselves a
     * secure cookie over a plaintext connection, which is the opposite of the fix.
     *
     * With no `trusted_proxies` configured the answer is `$_SERVER['HTTPS']` alone,
     * exactly as before.
     *
     * Both `on` and `1` are accepted for HTTPS, and `X-Forwarded-Proto` may carry a
     * list — `https, http` — when a request crossed more than one proxy, in which
     * case the first entry is the one the client spoke.
     */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https === 'on' || $https === '1') {
            return true;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded === '') {
            return false;
        }

        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($peer === ''
            || !\Pramnos\Http\ClientIpResolver::fromApplication()->isTrusted($peer)
        ) {
            return false;
        }

        $first = trim(explode(',', (string) $forwarded)[0]);

        return strtolower($first) === 'https';
    }


    /**
     * Sets a hashed cookie
     * @deprecated since version 1.0
     * @param string $cookiename
     * @param mixed $value
     * @param integer $time
     * @return boolean
     */
    public function cookieset($cookiename, $value, $time = 0)
    {
        $request = \Pramnos\Http\Request::getInstance();
        return $request->cookieset($cookiename, $value, $time);
    }

    /**
     * Retreives a hashed cookie
     * @deprecated since version 1.0
     * @param  string $cookiename
     * @return string
     */
    public function cookieget($cookiename)
    {
        $request = \Pramnos\Http\Request::getInstance();
        return $request->cookieget($cookiename);
    }


    /**
     * Get a session variable or NULL if it's not set, to avoid warnings
     * @param string $key
     * @return null
     */
    function get($key)
    {
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        } else {
            return null;
        }
    }

    /**
     * Store a session variable.
     *
     * The other half of `get()`, and it was missing for years. That is the kind of absence
     * nothing catches: a caller writes `$session->set(...)` **because `get()` exists**, and
     * gets `Call to undefined method` — a fatal at runtime, on whichever request happens to
     * reach that line, not an error anything sees earlier.
     *
     * `ensureStarted()` first, because in lazy-session mode a write can be the first thing
     * that needs a session at all — the same reason `getCsrfToken()` and `regenerateToken()`
     * call it. Without that, the value would be assigned to a `$_SESSION` array that is never
     * persisted, and read back as null on the next request: a write that silently does
     * nothing, which is worse than the fatal it replaced.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Is this key present?
     *
     * Distinct from `get($key) !== null`, which cannot tell a stored null from an absent
     * key — and does not, on its own, say anything about a session that was never started.
     *
     * Deliberately does *not* start one. Asking whether something is stored must not create
     * the session cookie that lazy mode exists to avoid handing to visitors with no state.
     *
     * @param  string $key
     * @return bool
     */
    public function has($key)
    {
        return isset($_SESSION) && array_key_exists($key, $_SESSION);
    }

    /**
     * Remove a session variable.
     *
     * Like `has()`, it starts nothing: there is nothing to remove from a session that does
     * not exist, and creating one in order to unset a key in it would be absurd.
     *
     * @param  string $key
     * @return void
     */
    public function remove($key)
    {
        if (isset($_SESSION)) {
            unset($_SESSION[$key]);
        }
    }
}
