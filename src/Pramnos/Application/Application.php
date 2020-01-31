<?php

namespace Pramnos\Application;
use Pramnos\Framework\Base;
/**
 * @package     PramnosFramework
 * @subpackage  Application
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Application extends Base
{
    /**
     * Current Active Controller
     * @var mixed
     */
    public $activeController = null;
    public $currentUser = null;
    /**
     * Main Application Information
     * @var string
     */
    public $applicationInfo = array();
    /**
     * Current Language name
     * @var string
     */
    public $language = '';
    /**
     * Session object
     * @var \Pramnos\Http\Session
     */
    public $session;
    /**
     * Application Name
     * @var string
     */
    public $appName = '';
    /**
     * Settings Object
     * @var Settings
     */
    public $settings;
    public $database;
    public $_defaultModule;
    public $module = '';
    public $action;
    private $_moduleinfo = array(
        "moduletype" => '',
        "title" => '',
        "id" => null
    );
    private $_isStartPage = true;
    private $_redirect = null;

    /**
     * Extra paths to look when getting models or views
     * @var array
     */
    private $_extraPath = array();

    /**
     *
     * @param string $appName Application Name used for namespaces
     */
    public function __construct($appName = '')
    {
        if (!defined('PRAMNOS_DEFINES')) {
            $this->setDefines();
        }
        $this->appName = $appName;
        if ($appName == '') {
            $this->applicationInfo = require APP_PATH . DS . 'app.php';
        } else {
            $this->applicationInfo = require APP_PATH . DS . $appName . '.php';
        }

        parent::__construct();
    }

    /**
     * Setup initial defines for the application
     */
    protected function setDefines()
    {
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', ROOT . DS . 'app');
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
        if (!defined('CONFIG')) {
            define('CONFIG', basename(APP_PATH) . DS . 'config');
        }
        if (!defined('VAR_PATH')) {
            define('VAR_PATH', ROOT . DS . 'var');
        }
        if (!defined('CACHE_PATH')) {
            define('CACHE_PATH', VAR_PATH . DS . 'cache');
        }
        if (!defined('VAR_PATH')) {
            define('VAR_PATH', ROOT . DS . 'var');
        }
        if (!defined('ADDONS_PATH')) {
            define('ADDONS_PATH', APP_PATH . DS . 'addons');
        }
        define('PRAMNOS_DEFINES', true);
    }

    /**
     * Load the database, session and settings classes
     */
    public function init($settingsFile = '')
    {
        $this->settings = Settings::getInstance($settingsFile);
        $this->database = new \Pramnos\Database\Database($this->settings);
        try {
            $this->database->connect();
        } catch (Exception $ex) {
            die($ex->getMessage());
        }
        
        /**
         * Start Session
         */
        $this->session = \Pramnos\Http\Session::getInstance();
        $this->session->start();
    }

    /**
     * Set a redirect location
     * @param string $url
     */
    public function setRedirect($url = '')
    {
        $this->_redirect = $url;
    }

    /**
     * Force redirect of the page to another url
     * @param string  $url  Url to redirect to
     * @param boolean $quit If you want to quit after redirecting.
     * @param string  $code Forces HTTP response code to the specified value.
     */
    public function redirect($url = null, $quit = true, $code='301')
    {

    }

    public function setmoduleinfo($moduleinfo = array())
    {
        $this->_moduleinfo = $moduleinfo;
    }

    public function isStartPage()
    {
        return $this->_isStartPage;
    }

    public function setStartPage($bool)
    {
        $this->_isStartPage = $bool;
    }

    public function getmoduleinfo()
    {
        return $this->_moduleinfo;
    }

    /**
     * Executes a module
     * @param string $controller
     */
    public function exec($controller = '')
    {

    }

    /**
     * This should be called for default pramnos_factory controllers when no
     * controller is found
     * @param string $module
     * @return \pramnos_application_controller
     */
    protected function getFrameworkController($module)
    {

    }

    public function render()
    {

    }

    /**
     * Exit the application
     * @param string $msg Message to show before quiting
     */
    public function close($msg = "")
    {

    }

    /**
     * Adds an extra path to the application to look for models and views
     * @param string $path
     * @return pramnos_application
     */
    public function _addExtraPath($path)
    {
        $this->_extraPath[] = $path;
        return $this;
    }

    /**
     * Return the array with all paths
     * @return array
     */
    public function _getExtraPaths()
    {
        return $this->_extraPath;
    }



}
