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
    private $_controller = '';
    private $_action = '';
    /**
     * Original $_GET request
     * @var string
     */
    public static $originalRequest='';
    /**
     * The URI which was given in order to access the app;
     * for instance, '/index.html'.
     * @var string
     */
    public $requestUri='';

    public $requestMethod='GET';

    public $putData = array();
    public $deleteData = array();

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
     * Calculate the parameters of request
     * @param type $requestParam
     */
    public function calcParams($requestParam=null)
    {
        $_GET = array();
        if ($requestParam == null){
            $requestParam=self::$originalRequest;
        }
        $this->_controller='';
        $this->_action='';
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
            $this->_controller = $parts[0];
        }
        if ($slashes > 0 && isset($parts[1]) && $parts[1] !== '') {
            $this->_action = $parts[1];
        }
        if (count($parts > 2)) {
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
            $this->requestUri=$_SERVER['REQUEST_URI'];
        }
        if (isset($_GET['r'])) {
            self::$originalRequest=$_GET['r'];
            $this->calcParams();
        }
        unset($_GET['r']);
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        } else {
            if (isset($_POST) && count($_POST) != 0) {
                $this->requestMethod = 'POST';
            }
        }
        if ($this->requestMethod == 'PUT') {
             parse_str(file_get_contents("php://input"), $this->putData);
        }

        if ($this->requestMethod == 'DELETE') {
            parse_str(file_get_contents("php://input"), $this->deleteData);
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
                $input = &$this->deleteData;
                break;
            case 'PUT':
                $input = &$this->putData;
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
     * Get the requested module
     * @return string
     */
    public function getModule()
    {
        return $this->_controller;
    }

    /**
     * Set the module to whatever you want
     * @param  string           $module
     * @return Request
     */
    public function setModule($module)
    {
        $this->_controller=$module;

        return $this;
    }

    /**
     * Get the requested action
     * @return string
     */
    public function getAction()
    {
        return $this->_action;

    }



    /**
     * Set request action
     * @param string $action
     */
    public function setAction($action = "display")
    {
        $this->_action = $action;

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
            } else {
                $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
            }
        } else {
            $pageURL = $_SERVER["REQUEST_URI"];
        }

        return $pageURL;
    }
}
