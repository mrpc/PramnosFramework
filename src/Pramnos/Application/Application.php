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
    /**
     * Database object
     * @var \Pramnos\Database\Database
     */
    public $database;
    /**
     * Default controller to run. Defaults to "home"
     * @var string
     */
    public $defaultController = 'home';
    /**
     * Current controller name
     * @var string
     */
    public $controller = '';
    /**
     * Current action to run
     * @var string
     */
    public $action;
    /**
     * Controller information
     * @var string
     */
    private $controllerInfo = array(
        "type" => '',
        "title" => '',
        "id" => null
    );
    private $_isStartPage = true;
    /**
     * Redirect address
     * @var string
     */
    private $_redirect = null;
    /**
     * Did the application already initialize?
     * @var bool
     */
    protected $initialized = false;
    /**
     * Application instances
     * @var Application[]
     */
    protected static $appInstances = array();
    /**
     * Last used application name
     * @var string
     */
    protected static $lastUsedApplication = null;

    /**
     * Extra paths to look when getting models or views
     * @var array
     */
    protected $extraPaths = array();

    /**
     * Application class constructor
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
        if (!defined('URL')) {
            define('URL', getUrl());
        }
        if (!defined('sURL')) {
            if ($appName == '') {
                define('sURL', URL);
            } else {
                define('sURL', basename(URL));
            }
        }

        parent::__construct();
        if ($appName == '') {
            self::$appInstances['default'] = $this;
        } else {
            self::$appInstances[$appName] = $this;
        }
        self::$lastUsedApplication = $appName;
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
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', VAR_PATH);
        }
        if (!defined('DB_USERSTABLE')) {
            define('DB_USERSTABLE', "#PREFIX#users");
        }
        if (!defined('DB_USERGROUPSTABLE')) {
            define('DB_USERGROUPSTABLE', "#PREFIX#groups");
        }
        if (!defined('DB_USERGROUPSUBSCRIPTIONS')) {
            define('DB_USERGROUPSUBSCRIPTIONS', "#PREFIX#groupmembers");
        }
        if (!defined('DB_USERDETAILSTABLE')) {
            define('DB_USERDETAILSTABLE', "#PREFIX#userdetails");
        }
        if (!defined('DB_PERMISSIONSTABLE')) {
            define('DB_PERMISSIONSTABLE', "#PREFIX#permissions");
        }
        ini_set(
            'error_log', LOG_PATH . DS . 'logs' . DS . 'php_error.log'
        );
        define('PRAMNOS_DEFINES', true);
    }

    /**
     * Load the database, session and settings classes
     */
    public function init($settingsFile = '')
    {
        if ($this->initialized === true) {
            return;
        }
        $this->settings = Settings::getInstance($settingsFile);
        $this->database = \Pramnos\Database\Database::getInstance(
            $this->settings
        );
        try {
            $this->database->connect();
        } catch (Exception $ex) {
            $this->showError($ex->getMessage());
        }
        $this->initialized = true;
        /**
         * Start Session
         */
        $this->session = \Pramnos\Http\Session::getInstance();
        $this->session->start();


        $request = new \Pramnos\Http\Request();
        if ($request->getController() !== '') {
            $this->controller = $request->getController();
        }
        $this->action = $request->getAction();
        if (isset($_SESSION['language'])) {
            $this->language = $_SESSION['language'];
        }
        //End of set session defaults
        if (isset($_GET['lang']) == true) {
            $_SESSION['language'] = $_GET['lang'];
            $this->language = $_GET['lang'];
        }
        $lang = \Pramnos\Translator\Language::getInstance($this->language);
        $lang->load($this->language);
        \Pramnos\Addon\Addon::triger('AppInit', 'system');

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
     * Display an error
     * @param string $msg Message to add
     */
    public function showError($msg='')
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT == true) {
            $database=&pramnos_factory::getDatabase();
            $error = pramnos_general::varDumpToString($database->sql_error());
        } else {
            $error = '';
        }
        if ($msg != '') {
            $error .= "<br />" . $msg;
        }
        $this->close(
            '<html><head><title>Maintenance Mode</title>'
            . '<style>body {background-color: #cccccc;font-family: '
            . 'verdana;color: midnightblue;}div {margin: 100px auto 0 auto;'
            . 'width:500px;background-color: #ffffff;height: 400px;'
            . 'text-align: center;padding: 20px;}.powered {font-size: 10px;}'
            . '</style></head><body><div><h1>Database Unavailable</h1>'
            . $error
            . '<p>Our website is currently unavailable.Please come back in a '
            . 'few minutes.</p><br /><br /><br /><br /><br /><br /><br />'
            . '<br /><p class="powered">Website is powered by '
            . 'PramnosFramework,<br />created by<br />'
            . '<a href="http://www.pramhost.com">Pramnos Hosting</a>.'
            . '</p></div></body></html>'
        );
    }

    /**
     * Force redirect of the page to another url
     * @param string  $url  Url to redirect to
     * @param boolean $quit If you want to quit after redirecting.
     * @param string  $code Forces HTTP response code to the specified value.
     */
    public function redirect($url = null, $quit = true, $code='302')
    {
        //@codeCoverageIgnoreStart
        if (defined('DEVELOPMENT') && DEVELOPMENT && $url != '') {
            $backtrace = debug_backtrace();
            $back = '';
            $comma = " - ";
            foreach ($backtrace as $backTraceObject) {
                if (isset($backTraceObject['file'])
                    && isset($backTraceObject['line'])) {
                    $back .= $comma
                        . $backTraceObject['file']
                        . " :: "
                        . $backTraceObject['line'];
                    $comma = "\n - ";
                }
            }

            $request = new \Pramnos\Http\Request();
            \Pramnos\Logs\Logs::log(
                "\n"
                . 'Redirect from: '
                . $request->getURL(false)
                . ' to '
                .
                $url .
                "\nBacktrace:\n"
                . $back,
                'redirects'
            );
        }
        //@codeCoverageIgnoreEnd
        if ($url !== null) {
            if (!headers_sent()) {
                header("Location: " . $url, true, $code);
            }
            echo '<script>window.location="'
                . $url
                . '"</script>Redirecting. If your browser doesn\'t '
                . 'redirect, please click '
                . '<a href="' . $url . '">here</a>.';
            if ($quit == true) {
                $this->close();
            }
            return true;
        }
        if ($this->_redirect !== null) {
            if (!headers_sent()) {
                header("Location: " . $this->_redirect, true, $code);
            }
            echo '<script>window.location="'
                . $this->_redirect
                . '"</script>Redirecting. If your browser doesn\'t '
                . 'redirect, please click '
                . '<a href="' . $this->_redirect . '">here</a>.';
            if ($quit == true) {
                $this->close();
            }
            return true;
        }
        return false;
    }

    public function setControllerInfo($controllerInfo = array())
    {
        $this->controllerInfo = $controllerInfo;
    }

    public function isStartPage()
    {
        return $this->_isStartPage;
    }

    public function setStartPage($bool)
    {
        $this->_isStartPage = $bool;
    }

    public function getControllerInfo()
    {
        return $this->controllerInfo;
    }

    /**
     * Get a controller
     * @param string $controller
     * @return \Pramnos\Application\Controller
     */
    public function getController($controller)
    {
        $className = ucfirst($controller);
        $namespace = 'Pramnos';
        if (isset($this->applicationInfo['namespace'])) {
            $namespace = $this->applicationInfo['namespace'];
        }
        $nameSpacedClass = '\\' . $namespace . '\\Controllers\\' . $className;
        if (class_exists($nameSpacedClass)) {
            return new $nameSpacedClass();
        }
        $controllerObject = $this->getFrameworkController($controller);
        if ($controllerObject) {
            return $controllerObject;
        }

        throw new \Exception('No controller found: ' . $controller);
    }



    /**
     * Executes a controller
     * @param string $coontrollerName
     */
    public function exec($coontrollerName = '')
    {
        /*
         * Find the right controller to load
         */
        $controller = strtolower($coontrollerName);
        if ($controller === '' && $this->controller === '') {
            if ($this->defaultController !== "") {
                $this->controller = $this->defaultController;
            } else {
                $this->close('There is no controller to run...');
            }
        } elseif ($controller != '') {
            $this->controller = $controller;
        }
        /*
         * If there is a setting for ssl, enforce it
         */
        if (\Pramnos\Application\Settings::getSetting('forcessl') == '1') {
            if (strpos(sURL, 'https') !== 0) {
                $this->redirect(
                    str_replace('http://', 'https://', sURL), true, 301
                );
            }
        }
        /*
         * Get a document to fill with content
         */
        $doc = \Pramnos\Framework\Factory::getDocument();


        /*
         * Try to load the controller
         */
        try {
            $controllerObject = $this->getController($this->controller);
        } catch (\Exception $Exception) {
            \Pramnos\Logs\Logs::log($Exception->getMessage());
            $this->close('There is no controller to run...');
        }
        $this->activeController = $controllerObject;

        /*
         * Check for theme in the application configuration. If set, load it.
         */
        if (isset($this->applicationInfo['theme'])
            && $this->applicationInfo['theme'] != ''
            && $this->applicationInfo['theme'] != null) {
            $doc->loadtheme($this->applicationInfo['theme']);
        }

        /*
         * Execute the controller and add content to the document
         */
        try {
            $doc->addContent($controllerObject->exec($this->action));
        } catch (Exception $exception) {
            $message = $exception->getMessage();
            if (strpbrk($message, 'SQL') !== false) {
                \Pramnos\Logs\Logs::log(
                    $message
                    . "\nLine:\n"
                    . $exception->getFile()
                    . " -> "
                    . $exception->getLine()
                    . "\nTrace:\n"
                    . $exception->getTraceAsString()
                );
            }
            $this->redirect(URL);
        }
    }

    /**
     * Factory method
     * @param string $app
     * @return \Pramnos\Application\Application
     * @throws Exception
     */
    public static function &getInstance($app = '')
    {
        if ($app == '' && self::$lastUsedApplication !== null) {
            $app = self::$lastUsedApplication;
        }
        if ($app == '') {
            $app = 'default';

        }
        if (!isset(self::$appInstances[$app])) {
            if (!defined('DS')) {
                define('DS', DIRECTORY_SEPARATOR);
            }
            if (!defined('APP_PATH')) {
                define('APP_PATH', ROOT . DS . 'app');
            }
            try {
                if ($app == 'default') {
                    $tmpConfig = require APP_PATH . DS . 'app.php';
                } else {
                    $tmpConfig = require APP_PATH . DS . $app . '.php';
                }

                if (isset($tmpConfig['namespace'])) {
                    $class = '\\' . $tmpConfig['namespace'] . '\\Application';
                } else {
                    $class = '\\Pramnos\\Application';
                }
                if (class_exists($class)) {
                    if ($app == 'default') {
                        self::$appInstances['default'] = new $class();
                    } else {
                        self::$appInstances['default'] = new $class($app);
                    }
                }

            } catch (\Exception $Exception) {
                \Pramnos\Logs\Logs::log(
                    'Cannot start ' . $app . ' application: '
                    . $Exception->getMessage()
                );
            }
        }
        return self::$appInstances[$app];
    }

    /**
     * This should be called for default pramnos_factory controllers when no
     * controller is found
     * @param string $controller
     * @return \pramnos_application_controller
     */
    protected function getFrameworkController($controller)
    {
        $className = ucfirst($controller);
        $nameSpacedClass = '\\Pramnos\\Application\\Controllers\\' . $className;
        if (class_exists($nameSpacedClass)) {
            return $controller = new $nameSpacedClass();
        }

        return false;
    }

    /**
     * Render the application and return the content
     * @return string
     */
    public function render()
    {
        $this->redirect(); //Redirect if it's needed
        $doc = \Pramnos\Framework\Factory::getDocument();
        return $doc->render();
    }

    /**
     * Exit the application
     * @param string $msg Message to show before quiting
     */
    public function close($msg = "")
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT == true) {
            \Pramnos\Logs\Logs::log(
                \Pramnos\General\Helpers::varDumpToString(debug_backtrace()),
                'exitAppLog'
            );
        }
        exit($msg);
    }


    /**
     * Adds an extra path to the application to look for models and views
     * @param string $path
     * @return pramnos_application
     */
    public function addExtraPath($path)
    {
        $this->extraPaths[$path] = $path;
        return $this;
    }

    /**
     * Return the array with all paths
     * @return array
     */
    public function getExtraPaths()
    {
        return $this->extraPaths;
    }




}
