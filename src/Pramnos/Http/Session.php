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

    public function checkToken($method = 'request', $prefix = '')
    {
        $request = new Request();
        $token = $request->get($prefix . $this->_lastToken, false, $method);
        if ($token == '1') {
            return true;
        } else {
            return false;
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
        if (defined('UNITTESTING') && UNITTESTING == true) {
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
     * @return type
     */
    public function isLogged()
    {
        return self::staticIsLogged();
    }

    /**
     * Factory method
     * @staticvar null $instance
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
            @session_start();
        }
        $this->_token = substr(md5(time() . getUrl()), 0, 10);
        if (isset($_SESSION['token'])) {
            $this->_lastToken = $_SESSION['token'];
        }
        $_SESSION['token'] = $this->_token;
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

    }


    /**
     * Sets a hashed cookie
     * @deprecated since version 1.0
     * @param string $cookiename
     * @param string $value
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