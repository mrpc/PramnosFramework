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
     * Breadcrumbs
     * @var \Pramnos\Html\Breadcrumb
     */
    protected $breadcrumbs;

    /**
     * Application class constructor
     * @param string $appName Application Name used for namespaces
     */
    public function __construct($appName = '')
    {
        if ($this->breadcrumbs === null) {
            $this->breadcrumbs = new \Pramnos\Html\Breadcrumb();
        }
        if (file_exists(ROOT . '/var/MAINTENANCE')) {
            $this->showError();
        }
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
        } catch (\Exception $ex) {
            $this->showError($ex->getMessage());
        }
        \Pramnos\Application\Settings::setDatabase($this->database);
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

        //End of set session defaults
        if (isset($_GET['lang']) == true) {
            $_SESSION['language'] = $_GET['lang'];
            $this->language = $_GET['lang'];
        }

        if (
            isset($this->applicationInfo['addons'])
            && is_array($this->applicationInfo['addons'])
        ) {
            foreach ($this->applicationInfo['addons'] as $addon) {
                if (!\Pramnos\Addon\Addon::load(
                    $addon['addon'], $addon['type']
                )) {
                    $this->showError('Cannot load addon: ' . $addon['addon']);
                }
            }
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
     * Add a breadcrumb to navigation
     * @param string $text
     * @param string $link
     * @param string $title Title property
     * @return $this
     */
    public function addbreadcrumb($text, $link = '#', $title = '')
    {
        $this->breadcrumbs->addItem($text, $link, $title);
        return $this;
    }

    /**
     * Render the breadcrumbs
     * @return string
     */
    public function renderBreadcrumbs()
    {
        return $this->breadcrumbs->render();
    }


    /**
     * Display an error
     * @param string $msg Message to add
     */
    public function showError($msg='', $title='Maintenance Mode')
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT == true) {
            $database = \Pramnos\Framework\Factory::getDatabase();
            $error = \Pramnos\General\Helpers::varDumpToString($database->getError());
        } else {
            $error = '';
        }
        if ($msg != '') {
            $error .= "<br />" . $msg;
        }
        $this->close(
            '<html><head><title>'
            . $title
            . '</title>'
            . '<style>body {background-color: #cccccc;font-family: '
            . 'verdana;color: midnightblue;}div {margin: 100px auto 0 auto;'
            . 'width:500px;background-color: #ffffff;height: 400px;'
            . 'text-align: center;padding: 20px;}.powered {font-size: 10px;}'
            . '</style></head><body><div><h1>'
            . $title
            . '</h1>'
            . '<p>'
            . $error
            . '</p><br /><br /><br /><br /><br /><br /><br />'
            . '</div></body></html>'
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
            \Pramnos\Logs\Logger::log(
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
     * @param array|string $userPermissions
     * @return \Pramnos\Application\Controller
     */
    public function getController($controller, $userPermissions = [])
    {
        $className = ucfirst($controller);
        $namespace = 'Pramnos';
        if (isset($this->applicationInfo['namespace'])) {
            $namespace = $this->applicationInfo['namespace'];
        }
        $nameSpacedClass = '\\' . $namespace . '\\Controllers\\' . $className;
        if (class_exists($nameSpacedClass)) {
            return new $nameSpacedClass($this, $userPermissions);
        }
        $controllerObject = $this->getFrameworkController($controller, $userPermissions);
        if ($controllerObject) {
            return $controllerObject;
        }


        $errorMessage = 'Cannot find controller: ' . $controller;
        // check current called url
        if (isset($_SERVER['REQUEST_URI'])) {
            $errorMessage .= "\n"
                . 'Current URL: ' . $_SERVER['REQUEST_URI'];  
        } 
        if (isset($_SESSION['user']) && is_object($_SESSION['user'])) {
            $errorMessage .= "\n"
                . 'User: ' . $_SESSION['user']->username;
        }

        

        throw new \Exception(
            $errorMessage
        );
    }



    /**
     * Executes a controller
     * @param string $coontrollerName
     */
    public function exec($coontrollerName = '')
    {
        /*
         * Run any needed updates
         */
        if ($this->checkversion() !== true) {
            $this->upgrade();
        }

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

        if (isset($this->applicationInfo['scripts'])) {
            foreach ($this->applicationInfo['scripts'] as $script) {
                $doc->registerScript(
                    $script['script'],
                    sURL . $script['src'],
                    $script['deps'],
                    $script['version'],
                    $script['footer']
                );
            }
        }

        if (isset($this->applicationInfo['css'])) {
            foreach ($this->applicationInfo['css'] as $css) {
                $doc->registerStyle(
                    $css['name'],
                    sURL . $css['src'],
                    $css['deps'],
                    $css['version'],
                    $css['media']
                );
            }
        }
        $lang = \Pramnos\Framework\Factory::getLanguage();

        if ($doc->getType() == 'html') {
            $this->addbreadcrumb($lang->_('Home'), sURL);
        }

        /*
         * Try to load the controller
         */
        try {
            $controllerObject = $this->getController($this->controller);
        } catch (\Exception $Exception) {
            //\Pramnos\Logs\Logger::log($Exception->getMessage());
            $this->close('There is no controller to run...');
        }
        $this->activeController = $controllerObject;

        /*
         * Check for theme in the application configuration. If set, load it.
         */
        if (isset($this->applicationInfo['theme'])
            && $this->applicationInfo['theme'] != ''
            && $this->applicationInfo['theme'] != null) {
            $doc->loadtheme($this->applicationInfo['theme'], '', $this);
        }

        /*
         * Execute the controller and add content to the document
         */
        try {
            $doc->addContent($controllerObject->exec($this->action));
        } catch (\Exception $exception) {
            $message = $exception->getMessage();
            if (strpbrk($message, 'SQL') !== false) {
                \Pramnos\Logs\Logger::log(
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
                \Pramnos\Logs\Logger::log(
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
     * @param array|string $userPermissions
     * @return \pramnos_application_controller
     */
    protected function getFrameworkController($controller, $userPermissions = [])
    {
        $className = ucfirst($controller);
        $nameSpacedClass = '\\Pramnos\\Application\\Controllers\\' . $className;
        if (class_exists($nameSpacedClass)) {
            return $controller = new $nameSpacedClass($this, $userPermissions);
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
            \Pramnos\Logs\Logger::log(
                \Pramnos\General\Helpers::varDumpToString(debug_backtrace()),
                'exitAppLog'
            );
        }
        session_write_close();
        exit($msg);
    }


    /**
     * Should be called if there is a new version update
     * Return true if upgrade is done
     */
    public function upgrade()
    {
        $migrations = array();
        $migrationsFile = APP_PATH . DS . 'migrations.php';
        if (file_exists($migrationsFile)) {
            $migrations = require($migrationsFile);
        }
        foreach ($migrations as $version => $class) {
            if (!$this->checkversion($version)) {
                $this->runMigration($class);
            }
        }
    }

    /**
     * Run a migration
     * @param string $class Class name to run
     */
    public function runMigration($class)
    {
        $path = APP_PATH . DS . 'Migrations' . DS;
        $namespace = 'Pramnos';
        if (isset($this->applicationInfo['namespace'])) {
            $namespace = $this->applicationInfo['namespace'];
        }
        if ($this->appName != '') {
            $namespace .= '\\' . $this->appName;
            $path .= $this->appName . DS;
        }
        if (file_exists($path . $class . '.php')) {
            require_once $path . $class . '.php';

            $nameSpacedClass = '\\' . $namespace . '\\Migrations\\' . $class;
            if (!class_exists($nameSpacedClass)) {
                throw new \Exception('Cannot find ' . $class . ' migration');
            }
            $object = new $nameSpacedClass($this);
            if ($object->autoExecute == true) {
                $this->startMaintenance();
                $object->up();
                $sql = $this->database->prepareQuery(
                    "insert into `#PREFIX#schemaversion` (`key`) values (%s);",
                    $object->version
                );
                $this->database->query($sql);
                \Pramnos\Logs\Logger::log("\n" . $sql . "\n\n", 'upgrades');
                $this->stopMaintenance();
            }
        }
    }

    /**
     * Check if there is a new version of the database available
     * Return true if we are in current version
     * @var string $version Version to check. Leave empty for latest
     */
    public function checkversion($version = null)
    {
        if ($version == null) {
            if (isset($this->applicationInfo['database_version'])) {
                $version = $this->applicationInfo['database_version'];
            }
        }
        if ($version == null) {
            return true;
        }
        if (!$this->database) {
            return true;
        }

        $sql = $this->database->prepareQuery(
            "select * from `#PREFIX#schemaversion` "
            . " where `key` = %s limit 1",
            $version
        );
        $result = $this->database->query($sql);
        if ($result->numRows == 0) {
            return false;
        }
        return true;
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


    /**
     * Switch to maintenance mode. Mostly used by the upgrade script
     * @param   string  $reason Reason of maintainance mode
     */
    public function startMaintenance($reason = '')
    {
        if (file_exists(ROOT . DS . 'var' . DS . "MAINTENANCE")) {
            return;
        }
        if (!file_exists(ROOT . DS . 'var')) {
            mkdir(ROOT . DS . 'var');
        }
        $file = fopen(ROOT . DS . 'var' . DS . "MAINTENANCE", "w+");
        if ($reason != '') {
            fwrite(
                $file,
                "Maintenance started at: "
                . date('d/m/Y H:i')
                . ". Reason: " . $reason
            );
        } else {
            fwrite($file, "Maintenance started at: " . date('d/m/Y H:i') . ".");
        }
        fclose($file);
    }

    /**
     * Stop the maintenance mode
     */
    public function stopMaintenance()
    {
        if (file_exists(ROOT . DS . 'var' . DS . "MAINTENANCE")) {
                unlink(ROOT . DS . 'var' . DS . "MAINTENANCE");
        }
        //@codeCoverageIgnoreStart
        if (file_exists(ROOT . DS . 'var' . DS . "MAINTENANCE")) {
            sleep(2);
            $this->stopMaintenance();
        }
        //@codeCoverageIgnoreEnd
    }


}
