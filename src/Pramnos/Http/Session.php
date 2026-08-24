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
     * @param mixed $value The token value to verify
     * @param bool $useIpHash Whether to verify the IP fingerprint (IP pinning)
     * @return bool
     */
    public function checkTokenValue($value, $useIpHash = false): bool
    {
        $this->ensureStarted();
        return $value === $this->getFingerprint($useIpHash);
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
        if (isset($_SESSION['logged'])
            && isset($_SESSION['uid']) && $_SESSION['uid'] > 1) {
            return true;
        } else {
            return false;
        }
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
     * Returns true when the current request was made over HTTPS.
     * Checks HTTPS server variable accepting both 'on' and '1' values
     * (different web servers use different representations).
     */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return $https === 'on' || $https === '1';
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
}
