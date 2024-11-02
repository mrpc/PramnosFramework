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
class Request extends Base
{
    /**
     * Current controller
     * @var string
     */
    protected static $_controller = '';
    protected static $action = '';
    /**
     * Original $_GET request
     * @var string
     */
    public static $originalRequest='';

    /**
     * Raw input stream content
     * @var string|null
     */
    protected static $rawInput = null;

    /**
     * Original $_GET request that should never change
     * @var string
     */
    public static $originalRequestNoChange='';

    /**
     * The URI which was given in order to access this page;
     * for instance, '/index.html'.
     * @var string
     */
    public static $requestUri='';

    public static $requestMethod='GET';

    public static $putData = array();
    public static $deleteData = array();

    public static function &getInstance()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = new Request();
        }

        return $instance;
    }

    /**
     * Create a request object
     * @param string $uri
     * @param string $method
     * @return \Pramnos\Http\Request
     */
    public static function create($uri, $method="GET")
    {
        $request = new Request();
        $request->requestUri = $uri;
        $request->requestMethod = strtoupper($method);
        return $request;
    }

    /**
     * Set raw input content for testing
     * @param string|null $content
     */
    public static function setRawInput($content)
    {
        self::$rawInput = $content;
    }

    /**
     * Get raw input content
     * @return string
     */
    protected function getRawInput()
    {
        if (self::$rawInput !== null) {
            return self::$rawInput;
        }
        return file_get_contents("php://input");
    }

    /**
     * Calculate the parameters of request
     * @param type $requestParam
     */
    public function calcParams($requestParam=null)
    {
        $_GET = array();
        if ($requestParam == null){
            $requestParam=self::$originalRequest;
        }
        self::$_controller = '';
        $request = rtrim($requestParam, '/');
        $parsedUrl = parse_url($_SERVER['REQUEST_URI']);
        if (is_array($parsedUrl) && isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $_GET);
        }
        unset($parsedUrl);
        $slashes = substr_count($request, '/');
        if (isset($_SERVER['REQUEST_URI'])
            && strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $request = $request . substr(
                $_SERVER['REQUEST_URI'],
                strpos($_SERVER['REQUEST_URI'], '?')
            );
            $slashes = substr_count($request, '/');
            $request = str_replace("=", "/", $request);
            $request = str_replace("?", "/", $request);
            $request = str_replace("&", "/", $request);
            $request = str_replace('//', '/', $request);
        }
        $parts = explode("/", $request);
        if (isset($parts[0]) && $parts[0] !== '') {
            self::$_controller = $parts[0];
        }
        if ($slashes > 0 && isset($parts[1]) && $parts[1] !== '') {
            self::$action = $parts[1];
        }

        if (count($parts) > 2) {
            if ($slashes == 2) {
                $_GET['_option'] = $parts[2];
                unset($parts[2]);
            } elseif ($slashes > 0) {
                unset($parts[0], $parts[1]);
            } else {
                unset($parts[0]);
            }
            foreach ($parts as $part) {

                if (isset($varname)) {
                    $_GET[$varname] = $part;
                    $_REQUEST[$varname] = $part;
                    unset($varname);
                } else {
                    $varname = $part;
                }
            }
            if (isset($varname)) {
                $_GET[$varname] = null;
                if (!isset($_GET['_option'])){
                    $_GET['_option'] = $varname;
                }
                unset($varname);
            }
            unset($part);
        }

    }

    /**
     * Class constructor
     */
    public function __construct()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            self::$requestUri = trim($_SERVER['REQUEST_URI'], '/');
            /**
             * Clean up the request url in case the app runs under a
             * subdirectory
             */
            if (isset($_SERVER['PHP_SELF'])) {
                self::$requestUri = trim(
                    substr(
                        $_SERVER['REQUEST_URI'],
                        strlen(dirname($_SERVER['PHP_SELF']))
                    ),
                    "/"
                );
            }
        }
        self::$requestUri = str_replace('?{}', '', self::$requestUri);
        if (isset($_GET['r'])) {
            self::$originalRequest=$_GET['r'];
            $this->calcParams();
        }
        unset($_GET['r']);
        if (isset($_SERVER['REQUEST_METHOD'])) {
            self::$requestMethod = $_SERVER['REQUEST_METHOD'];
        } else {
            if (isset($_POST) && count($_POST) != 0) {
                self::$requestMethod = 'POST';
            }
        }
        if (self::$requestMethod == 'PUT') {
            $rawInput = $this->getRawInput();
            if (\Pramnos\General\Helpers::checkJSON($rawInput)) {
                $putArray = (array)json_decode($rawInput);
                self::$putData = array_merge($putArray, self::$putData);
                unset($putArray);
            } else {
                parse_str($rawInput, self::$putData);
            }
        }

        if (self::$requestMethod == 'DELETE') {
            $rawInput = $this->getRawInput();
            parse_str($rawInput, self::$deleteData);
        }

        
        
        if (self::$requestMethod == 'POST' && count($_POST) == 0) {
            $rawInput = $this->getRawInput();
            if (\Pramnos\General\Helpers::checkJSON($rawInput)) {
                $postArray = (array)json_decode($rawInput);
                $_POST = array_merge($postArray, $_POST);
                unset($postArray);
            }
        }

        
        if (self::$requestMethod == 'GET') {
            if (isset($_GET['{}'])) {
                unset($_GET['{}']);
            }
            
            foreach (array_keys($_GET) as $key) {
                if (\Pramnos\General\Helpers::checkJSON(str_replace("_", " ", $key))) {
                    $getArray = (array)json_decode(
                        str_replace("_", " ", $key)
                    );
                    $_GET = array_merge($getArray, $_GET);
                    unset($getArray);
                }
            }
        }

        parent::__construct();
    }

    /**
     * Return the last option of URL
     * @return mixed
     */
    public function getOption()
    {
        return Request::staticGetOption();

    }

    /**
     * Return the last option of URL
     * @return mixed
     */
    public static function staticGetOption()
    {
        if (isset($_GET['_option'])) {
            return $_GET['_option'];

        } else {
            return null;

        }
    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,
     *                         files,cookie,env,session,server
     * @param  string $type    Variable type for casting. Example: int
     * @return string
     */
    public function get($varname, $default = null,
        $method = 'request', $type = '')
    {
        return Request::staticGet($varname, $default, $method, $type);

    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,
     *                         files,cookie,env,session,server
     * @param  string $type    Variable type for casting. Example: int
     * @return string
     */
    public function getArray($varname, $default = null,
        $method = 'request', $type = '')
    {
        $var = Request::staticGet($varname, $default, $method, $type);
        if (is_array($var)) {
            return (object)$var;
        } else {
            return $var;
        }

    }

    /**
     * Get a user request
     * @param  string $varname name of the request
     * @param  mixed  $default Default value, if variable is not set
     * @param  string $method  Request method. request, post,get,files,
     *                         cookie,env,session,server
     * @param  string $type    Variable type for casting. Example: int
     * @return string
     */
    public static function staticGet($varname, $default = null,
        $method = 'request', $type = '')
    {
        $method = strtoupper($method);
        switch ($method) {
            case 'REQUEST':
                $input = &$_REQUEST;
                break;
            case 'GET':
                $input = &$_GET;
                break;
            case 'POST':
                $input = &$_POST;
                break;
            case 'FILES':
                $input = &$_FILES;
                break;
            case 'COOKIE':
                $input = &$_COOKIE;
                break;
            case 'ENV':
                $input = &$_ENV;
                break;
            case 'SESSION':
                $input = &$_SESSION;
                break;
            case 'SERVER':
                $input = &$_SERVER;
                break;
            case 'DELETE':
                $input = &self::$deleteData;
                break;
            case 'PUT':
                $input = &self::$putData;
                break;
            default:
                $input = &$_REQUEST;
                break;
        }
        if (isset($input[$varname])) {
            $return = $input[$varname];
        } else {
            $return = $default;
        }
        if ($type == 'int') {
            $return = (int) $return;
        }

        return $return;
    }



    /**
     * Get the requested controller
     * @return string
     */
    public function getController()
    {
        return self::$_controller;
    }



    /**
     * Set the controller to whatever you want
     * @param  string           $module
     * @return Request
     */
    public function setController($module)
    {
        self::$_controller = $module;

        return $this;
    }

    /**
     * Get the requested action
     * @return string
     */
    public function getAction()
    {
        return self::$action;

    }



    /**
     * Set request action
     * @param string $action
     */
    public function setAction($action = "display")
    {
        self::$action = $action;

        return $this;

    }

    /**
     * Get request URL
     * @param  boolean $relative
     * @return string
     */
    public function getURL($relative = true)
    {
        if ($relative == false) {
            $pageURL = 'http';
            if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
                $pageURL .= "s";
            }
            $pageURL .= "://";
            if (isset($_SERVER["SERVER_PORT"])
                && $_SERVER["SERVER_PORT"] != "80") {
                $pageURL .= $_SERVER["SERVER_NAME"] . ":"
                    . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
            } elseif (isset($_SERVER['SERVER_NAME'])) {
                $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
            }
        } else {
            $pageURL = $_SERVER["REQUEST_URI"];
        }

        return $pageURL;
    }


    /**
     * Sets a hashed cookie
     * @param string $cookiename
     * @param string $value
     * @param integer $time
     * @return boolean
     */
    public function cookieset($cookiename, $value, $time = 0)
    {
        $realCookiename = str_rot13($cookiename);

        $prefix = substr(md5('pcms'), 0, 10);
        $name = $prefix . '[' . $realCookiename . ']';
        if ($time == 0) {
            $time = time() + 3600 * 24 * 14; //2 weeks
        }
        #str_replace('index.php', '', $_SERVER['PHP_SELF'])
        if (!headers_sent()) {
            return setcookie($name, $value, $time, '/');
        } else {
            return false;
        }
    }

    /**
     * Retreives a hashed cookie
     * @param  string $cookiename
     * @return string
     */
    public function cookieget($cookiename)
    {
        $realCookiename = str_rot13($cookiename);
        $prefix = substr(md5('pcms'), 0, 10);
        #$realCookiename = $prefix . '[' . $realCookiename . ']'; //WTF?
        if (isset($_COOKIE[$prefix])
            && isset($_COOKIE[$prefix][$realCookiename])) {
            return $_COOKIE[$prefix][$realCookiename];
        } else {
            return null;
        }
    }

    /**
     * Get request controller
     * @deprecated since version 1.0
     * @return string
     */
    public function getModule()
    {
        return $this->getController();
    }

    /**
     * Set request controller
     * @deprecated since version 1.0
     * @param string $module
     * @return string
     */
    public function setModule($module)
    {
        return $this->setController($module);
    }

    /**
     * Get the request method
     * @return string
     */
    public function getRequestMethod()
    {
        return self::$requestMethod;
    }

    /**
     * Get ther request URI
     * @return string
     */
    public function getRequestUri()
    {
        return self::$requestUri;
    }

}
