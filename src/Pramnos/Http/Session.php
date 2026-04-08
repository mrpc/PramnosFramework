<?php

namespace Pramnos\Http;

use Pramnos\Framework\Base;

/**
 * Get user request and translate it
 * @package     PramnosFramework
 * @subpackage  Request
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
    private function getFingerprint(bool $useIp = false): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'none';
        $ip = $useIp ? ($_SERVER['REMOTE_ADDR'] ?? 'none') : '';
        return md5($ua . $ip . $this->_token);
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
        
        if ($token === $this->getFingerprint($useIpHash)) {
            return true;
        } else {
            return false;
        }
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
        $_SESSION['token'] = bin2hex(random_bytes(5));
        $this->_token = $_SESSION['token'];
        $this->_lastToken = $_SESSION['token'];
    }

    /**
     * Ensure the session is started and the CSRF token is initialized.
     * This keeps the Session public API safe even if callers did not invoke
     * start() explicitly before using token helpers.
     * @return void
     */
    private function ensureStarted()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($this->_token)) {
            $this->start();
        }
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
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            @session_start();
        }

        // Generate a stable token per session to support multiple tabs
        if (!isset($_SESSION['token'])) {
            $_SESSION['token'] = bin2hex(random_bytes(5));
        }
        
        $this->_token = $_SESSION['token'];
        $this->_lastToken = $_SESSION['token'];
        
        return session_id();
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
        $this->regenerateToken();
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
