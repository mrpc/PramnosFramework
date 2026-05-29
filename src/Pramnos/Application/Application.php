<?php

namespace Pramnos\Application;
use Pramnos\Framework\Base;
/**
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
     * Per-request CSP nonce. Generated once in exec() and used in render()
     * @var string
     */
    public $cspNonce = '';
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
     * Guards against running the auto-migration check more than once per
     * Application instance (e.g. if exec() is called multiple times).
     * Protected so test subclasses can inspect or reset the flag.
     * @var bool
     */
    protected bool $autoMigrationsChecked = false;
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
     * Service providers queued for bootstrap.
     *
     * Populated by addProvider() before init() and by bootServiceProviders()
     * from FeatureRegistry during init().
     *
     * @var ServiceProvider[]
     */
    protected array $serviceProviders = [];

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
            self::$appInstances['default'] = $this;
            self::$lastUsedApplication = 'default';
            $this->applicationInfo = require APP_PATH . DS . 'app.php';
        } else {
            self::$appInstances[$appName] = $this;
            self::$lastUsedApplication = $appName;
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
            define('DB_USERGROUPSTABLE', "#PREFIX#usergroups");
        }
        if (!defined('DB_USERGROUPSUBSCRIPTIONS')) {
            define('DB_USERGROUPSUBSCRIPTIONS', "#PREFIX#userstogroups");
        }
        if (!defined('DB_USERDETAILSTABLE')) {
            define('DB_USERDETAILSTABLE', "#PREFIX#userdetails");
        }
        if (!defined('DB_PERMISSIONSTABLE')) {
            define('DB_PERMISSIONSTABLE', "#PREFIX#permissions");
        }
        ini_set('error_log', LOG_PATH . DS . 'logs' . DS . 'php_error.log');
        ini_set('log_errors', '1');
        define('PRAMNOS_DEFINES', true);
    }

    /**
     * Queues a service provider for bootstrapping.
     *
     * Must be called before init(). The provider will be registered and booted
     * alongside feature-registry providers during init().
     *
     * @param ServiceProvider $provider
     */
    public function addProvider(ServiceProvider $provider): void
    {
        $this->serviceProviders[] = $provider;
    }

    /**
     * Instantiates providers from enabled FeatureRegistry features, merges
     * them with any manually-added providers, then runs register() on all
     * followed by boot() on all.
     */
    protected function bootServiceProviders(): void
    {
        foreach (FeatureRegistry::getEnabled() as $feature) {
            $class = FeatureRegistry::getProvider($feature);
            if ($class !== null && class_exists($class)) {
                $this->serviceProviders[] = new $class($this);
            }
        }

        // Auto-activate the DebugBar in development/debug mode even when the
        // app has not listed 'debug' in its features array.  Mirrors the
        // Laravel Debugbar experience: just set APP_DEBUG or development=true
        // and the toolbar appears on every HTML page.
        if (!FeatureRegistry::isEnabled('debug') && $this->isDebugMode()) {
            $class = FeatureRegistry::getProvider('debug');
            if ($class !== null && class_exists($class)) {
                $this->serviceProviders[] = new $class($this);
            }
        }

        foreach ($this->serviceProviders as $provider) {
            $provider->register();
        }
        foreach ($this->serviceProviders as $provider) {
            $provider->boot();
        }
    }

    /**
     * Returns true when the application is running in debug / development mode.
     *
     * Checks (in order): APP_DEBUG env var, DEVELOPMENT constant, 'debug'
     * setting, 'development' setting.
     */
    private function isDebugMode(): bool
    {
        $env = getenv('APP_DEBUG');
        if ($env !== false && $env !== '' && $env !== '0' && $env !== 'false') {
            return true;
        }
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            return true;
        }
        $debug = Settings::getSetting('debug');
        if ($debug === true || $debug === '1' || $debug === 'true' || $debug === 'yes') {
            return true;
        }
        $dev = Settings::getSetting('development');
        return $dev === true || $dev === '1' || $dev === 'true' || $dev === 'yes';
    }

    /**
     * Load the database, session and settings classes
     */
    public function init($settingsFile = '')
    {
        if ($this->initialized === true) {
            return;
        }
        if (PHP_VERSION_ID < 80100) {
            $this->showError("Pramnos Framework requires PHP 8.1.0 or greater. You are running PHP " . PHP_VERSION . ".");
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
        FeatureRegistry::loadFromConfig($this->applicationInfo['features'] ?? []);
        $this->bootServiceProviders();
        $this->registerBuiltInHealthChecks();
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
        $this->database->setTrackingInfo();
        $this->registerDefaultNavItems($this->applicationInfo['features'] ?? []);
    }

    /**
     * Registers the framework's built-in navigation items into NavRegistry.
     *
     * Called automatically at the end of init().  Applications may call
     * NavRegistry::remove() after init() to suppress unwanted items, or
     * NavRegistry::register() to add their own.
     *
     * @param string[] $features Enabled feature keys from applicationInfo['features'].
     */
    public function registerDefaultNavItems(array $features): void
    {
        $base = defined('sURL') ? \sURL : '/';

        // Home — always visible
        NavRegistry::register(new NavItem(
            'main.home', 'Home', $base,
            NavSection::Main, 0,
        ));

        // User section — auth-aware links
        NavRegistry::register(new NavItem(
            'user.login', 'Login', $base . 'login',
            NavSection::User, 0, requireAuth: false, guestOnly: true,
        ));
        NavRegistry::register(new NavItem(
            'user.account', 'My Account', $base . 'account',
            NavSection::User, 10, requireAuth: true, minUserType: 1,
        ));
        NavRegistry::register(new NavItem(
            'user.logout', 'Logout', $base . 'login/logout',
            NavSection::User, 99, requireAuth: true, minUserType: 1,
        ));

        // Admin section — these are always registered; visibility filtered by minUserType at runtime
        NavRegistry::register(new NavItem(
            'admin.users', 'Users', $base . 'users',
            NavSection::Admin, 5, requireAuth: true, minUserType: 80,
        ));
        NavRegistry::register(new NavItem(
            'admin.settings', 'Settings', $base . 'settings',
            NavSection::Admin, 8, requireAuth: true, minUserType: 80,
        ));
        NavRegistry::register(new NavItem(
            'admin.logs', 'Logs', $base . 'logs',
            NavSection::Admin, 10, requireAuth: true, minUserType: 80,
            parent: 'admin.dashboard',
        ));
        NavRegistry::register(new NavItem(
            'admin.health', 'Health', $base . 'health',
            NavSection::Admin, 11, requireAuth: true, minUserType: 80,
            parent: 'admin.dashboard',
        ));

        // Admin ops dashboard — always
        NavRegistry::register(new NavItem(
            'admin.dashboard', 'Dashboard', $base . 'Dashboard',
            NavSection::Admin, 1, requireAuth: true, minUserType: 80,
        ));

        // Services / Workers — always
        NavRegistry::register(new NavItem(
            'admin.services', 'Services', $base . 'Services',
            NavSection::Admin, 12, requireAuth: true, minUserType: 80,
        ));

        // Organizations — always
        NavRegistry::register(new NavItem(
            'admin.organizations', 'Organizations', $base . 'Organizations',
            NavSection::Admin, 14, requireAuth: true, minUserType: 80,
        ));

        // Emails — always, grouped under Dashboard
        NavRegistry::register(new NavItem(
            'admin.emails', 'Emails', $base . 'Emails',
            NavSection::Admin, 16, requireAuth: true, minUserType: 80,
            parent: 'admin.dashboard',
        ));

        // OAuth Apps — authserver feature
        if (in_array('authserver', $features, true)) {
            NavRegistry::register(new NavItem(
                'admin.applications', 'Applications', $base . 'Applications',
                NavSection::Admin, 20, requireAuth: true, minUserType: 90,
                feature: 'authserver',
            ));
            NavRegistry::register(new NavItem(
                'admin.tokens', 'Tokens', $base . 'Tokens',
                NavSection::Admin, 22, requireAuth: true, minUserType: 90,
                feature: 'authserver',
            ));
            NavRegistry::register(new NavItem(
                'admin.permissions', 'Permissions', $base . 'Permissions',
                NavSection::Admin, 24, requireAuth: true, minUserType: 90,
                feature: 'authserver',
            ));
        }

        // Token Actions audit log — auth feature, grouped under Users
        if (in_array('auth', $features, true)) {
            NavRegistry::register(new NavItem(
                'admin.tokenactions', 'Token Actions', $base . 'TokenActions',
                NavSection::Admin, 26, requireAuth: true, minUserType: 80,
                feature: 'auth',
                parent: 'admin.users',
            ));
        }

        // Queue — queue feature
        if (in_array('queue', $features, true)) {
            NavRegistry::register(new NavItem(
                'admin.queue', 'Queue', $base . 'Queue',
                NavSection::Admin, 30, requireAuth: true, minUserType: 80,
                feature: 'queue',
            ));
        }
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
        $this->cspNonce = base64_encode(random_bytes(16));
        /*
         * Run any needed updates (legacy app migration system)
         */
        if ($this->checkversion() !== true) {
            $this->upgrade();
        }

        /*
         * Run pending framework-level migrations (new MigrationRunner system).
         * Only autoExecute=true migrations run here; autoExecute=false require
         * an explicit `pramnos migrate` or DevPanel trigger.
         */
        $this->runAutoMigrations();

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
        \Pramnos\Debug\DebugBar::startTimer('routing');
        try {
            $controllerObject = $this->getController($this->controller);
        } catch (\Exception $Exception) {
            \Pramnos\Debug\DebugBar::stopTimer('routing');
            try {
                $ec = \Pramnos\Debug\DebugBar::getInstance()->getCollector('exceptions');
                if ($ec instanceof \Pramnos\Debug\Collectors\ExceptionsCollector) {
                    $ec->record($Exception);
                }
            } catch (\Throwable) {
            }
            //\Pramnos\Logs\Logger::log($Exception->getMessage());
            $this->close('There is no controller to run...');
        }
        \Pramnos\Debug\DebugBar::stopTimer('routing');
        $this->activeController = $controllerObject;

        // Feed resolved route into DebugBar RouteCollector when debug toolbar is active.
        try {
            $routeCollector = \Pramnos\Debug\DebugBar::getInstance()->getCollector('route');
            if ($routeCollector instanceof \Pramnos\Debug\Collectors\RouteCollector) {
                $routeCollector->setRoute([
                    'uri'        => $_SERVER['REQUEST_URI'] ?? '/',
                    'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'controller' => $this->controller,
                    'action'     => $this->action ?: 'display',
                    'class'      => get_class($controllerObject),
                ]);
            }
        } catch (\Throwable) {
        }

        /*
         * Check for theme in the application configuration. If set, load it.
         */
        if (isset($this->applicationInfo['theme'])
            && $this->applicationInfo['theme'] != ''
            && $this->applicationInfo['theme'] != null) {
            $doc->loadtheme($this->applicationInfo['theme'], '', $this);
        }

        // Track the web request in tokenactions when a web-session token is present.
        // This mirrors Api::_executeCore() so that both web and API paths appear
        // in the same audit log.
        if (isset($_SESSION['usertoken']) && is_object($_SESSION['usertoken'])
            && $_SESSION['usertoken']->tokentype === \Pramnos\User\Token::TYPE_WEB_SESSION) {
            try {
                $_SESSION['usertoken']->addAction();
            } catch (\Exception $ex) {
                unset($_SESSION['usertoken']);
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }
        }

        /*
         * Execute the controller and add content to the document
         */
        \Pramnos\Debug\DebugBar::startTimer('controller');
        try {
            $doc->addContent($controllerObject->exec($this->action));
            \Pramnos\Debug\DebugBar::stopTimer('controller');
        } catch (\Pramnos\Http\RedirectException $exception) {
            \Pramnos\Debug\DebugBar::stopTimer('controller');
            $this->redirect($exception->getUrl(), true, $exception->getStatusCode());
        } catch (\Pramnos\Validation\ValidationException $exception) {
            \Pramnos\Debug\DebugBar::stopTimer('controller');
            try {
                $ec = \Pramnos\Debug\DebugBar::getInstance()->getCollector('exceptions');
                if ($ec instanceof \Pramnos\Debug\Collectors\ExceptionsCollector) {
                    $ec->record($exception);
                }
            } catch (\Throwable) {
            }
            $request = new \Pramnos\Http\Request();
            $_SESSION['_validation_errors'] = $exception->errors();
            $_SESSION['_old_input'] = $request->allCurrent();

            $redirectTo = $_SERVER['HTTP_REFERER'] ?? URL;
            $this->redirect($redirectTo);
        } catch (\Exception $exception) {
            \Pramnos\Debug\DebugBar::stopTimer('controller');
            try {
                $ec = \Pramnos\Debug\DebugBar::getInstance()->getCollector('exceptions');
                if ($ec instanceof \Pramnos\Debug\Collectors\ExceptionsCollector) {
                    $ec->record($exception);
                }
            } catch (\Throwable) {
            }
            $format = isset($doc) && $doc->getType() === 'json' ? 'json' : 'html';
            $debug  = defined('DEVELOPMENT') && DEVELOPMENT === true;
            \Pramnos\Http\ExceptionHandler::log($exception);
            \Pramnos\Http\ExceptionHandler::render($exception, $format, $debug)->send();
            $this->close();
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
                    $configFile = APP_PATH . DS . 'app.php';
                    $tmpConfig = file_exists($configFile) ? require $configFile : ['namespace' => 'Pramnos'];
                } else {
                    $configFile = APP_PATH . DS . $app . '.php';
                    $tmpConfig = file_exists($configFile) ? require $configFile : ['namespace' => 'Pramnos'];
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
     * Build and send the Content-Security-Policy header.
     * 
     * This method constructs a CSP header string based on the application's
     * configuration and the per-request nonce. It includes directives for
     * script-src, style-src, img-src, etc., allowing for both secure
     * defaults and application-specific overrides.
     * 
     * @return void
     */
    protected function sendCspHeader()
    {
        if (headers_sent()) {
            return;
        }

        $csp = $this->applicationInfo['csp'] ?? [];

        $scriptDomains = $this->getCspDomains($csp, 'script-src');
        $styleDomains = $this->getCspDomains($csp, 'style-src');

        $scriptNonce = strpos($scriptDomains, "'unsafe-inline'") === false ? " 'nonce-{$this->cspNonce}'" : "";
        $styleNonce = strpos($styleDomains, "'unsafe-inline'") === false ? " 'nonce-{$this->cspNonce}'" : "";

        $policy = [
            "default-src 'none'",
            "manifest-src 'self'",
            "script-src 'self'{$scriptNonce}" . $scriptDomains,
            "style-src 'self'{$styleNonce}" . $styleDomains,
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:" . $this->getCspDomains($csp, 'img-src'),
            "font-src 'self' data:" . $this->getCspDomains($csp, 'font-src'),
            "connect-src 'self'" . $this->getCspDomains($csp, 'connect-src'),
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "worker-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests"
        ];

        header("Content-Security-Policy: " . implode('; ', $policy));
    }

    /**
     * Helper to get domains from config for a specific CSP directive.
     * 
     * Extracts an array of domains from the 'csp' configuration array
     * and joins them into a space-separated string.
     * 
     * @param array  $csp       The CSP configuration array.
     * @param string $directive The directive name (e.g., 'script-src').
     * @return string A space-prefixed string of domains, or empty string if none.
     */
    protected function getCspDomains(array $csp, string $directive): string
    {
        if (isset($csp[$directive]) && is_array($csp[$directive]) && !empty($csp[$directive])) {
            return ' ' . implode(' ', $csp[$directive]);
        }
        return '';
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
        $this->sendCspHeader();
        $doc = \Pramnos\Framework\Factory::getDocument();
        return $doc->render();
    }

    /**
     * Dynamically allow unsafe-inline for a specific CSP directive.
     * 
     * @param string $directive The CSP directive (e.g., 'script-src' or 'style-src')
     * @return void
     */
    public function allowUnsafeInline(string $directive)
    {
        if (!isset($this->applicationInfo['csp'])) {
            $this->applicationInfo['csp'] = [];
        }
        if (!isset($this->applicationInfo['csp'][$directive])) {
            $this->applicationInfo['csp'][$directive] = [];
        }
        if (!in_array("'unsafe-inline'", $this->applicationInfo['csp'][$directive])) {
            $this->applicationInfo['csp'][$directive][] = "'unsafe-inline'";
        }
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
     * Returns the name of the migrations history table.
     *
     * Defaults to 'schemaversion' (same as the old legacy migration system).
     * Override in a test subclass to use an isolated table and avoid
     * contaminating the real history.
     *
     * @return string
     */
    protected function getMigrationHistoryTable(): string
    {
        return 'schemaversion';
    }

    /**
     * Returns the framework-level migration directories to scan for auto-run.
     *
     * Includes one sub-directory per registered feature under
     * database/migrations/framework/.  Override in a subclass to add
     * application-specific framework migration directories.
     *
     * @return string[] Absolute directory paths.
     */
    protected function getFrameworkMigrationDirs(): array
    {
        $base = \Pramnos\Database\MigrationLoader::resolveFrameworkMigrationsBase();
        if ($base === null || !is_dir($base)) {
            return [];
        }
        return glob($base . '/*', GLOB_ONLYDIR) ?: [];
    }

    /**
     * Runs pending framework-level migrations with autoExecute=true.
     *
     * Called once per Application instance from exec() (guarded by
     * $autoMigrationsChecked).  Uses a three-phase approach:
     *
     *  Phase 1 — fingerprint check (filesystem + one PK lookup, no PHP loading):
     *    Derives a fingerprint from the migration filenames (count + latest
     *    timestamp).  Looks up this fingerprint in the history table with a
     *    single primary-key SELECT — identical in cost to the old checkversion().
     *    If the fingerprint is found: nothing changed since last check → return.
     *
     *  Phase 2 — pending check (one full-table SELECT, no PHP loading):
     *    Only reached when the fingerprint is absent (new migrations may exist).
     *    Reads all ran slugs from the history table and compares with the file-
     *    derived slug list.  If nothing is actually pending: records the
     *    fingerprint and returns.
     *
     *  Phase 3 — full load + run (only when Phase 2 confirms something pending):
     *    Loads migration PHP files, applies autoExecute and cutoff filters, runs
     *    via MigrationRunner, then records the fingerprint for next time.
     *
     * The fingerprint key format is:
     *   __fw_auto_{count}_{latestTimestamp}[_{cutoff}]
     * It changes only when new migration files are added or the cutoff changes,
     * ensuring a clean re-check without false positives.
     *
     * Protected so test subclasses can override getFrameworkMigrationDirs() or
     * call this method directly to verify the wiring.
     */
    protected function runAutoMigrations(): void
    {
        if ($this->autoMigrationsChecked || $this->database === null) {
            return;
        }
        $this->autoMigrationsChecked = true;

        $dirs = $this->getFrameworkMigrationDirs();
        if (empty($dirs)) {
            return;
        }

        $cutoff = $this->normalizeMigrationCutoff(
            $this->applicationInfo['migration_cutoff'] ?? ''
        );

        // Phase 1: build slug→timestamp map from filenames (no PHP loading).
        $slugTimestamps = \Pramnos\Database\MigrationLoader::slugsFromDirectories($dirs);
        if (empty($slugTimestamps)) {
            return;
        }

        // Apply cutoff filter at the filename level so the fingerprint only
        // covers migrations that are actually eligible to run.
        if ($cutoff !== '') {
            $slugTimestamps = array_filter(
                $slugTimestamps,
                static fn(string $ts) => $ts === '' || strcmp($ts, $cutoff) > 0
            );
            if (empty($slugTimestamps)) {
                return; // All migrations are pre-cutoff
            }
        }

        // Compute fingerprint: count + latest timestamp of eligible files.
        $timestamps  = array_filter(array_values($slugTimestamps)); // drop empty-ts entries
        $latestTs    = !empty($timestamps) ? max($timestamps) : '0';
        $count       = count($slugTimestamps);
        $fingerprint = "__fw_auto_{$count}_{$latestTs}" . ($cutoff !== '' ? "_{$cutoff}" : '');

        $histTable = $this->getMigrationHistoryTable();
        $quote     = $this->database->type === 'postgresql' ? '"' : '`';

        // Phase 1b: one PK lookup — same pattern as old checkversion().
        try {
            $sql    = $this->database->prepareQuery(
                "SELECT 1 FROM {$quote}{$histTable}{$quote} WHERE {$quote}key{$quote} = %s LIMIT 1",
                $fingerprint
            );
            $result = $this->database->query($sql);
            if ($result && $result->numRows > 0) {
                return; // Fingerprint found → nothing changed since last check
            }
        } catch (\Throwable) {
            // History table does not yet exist — fall through to full run
        }

        // Phase 2: fingerprint absent → check which slugs are genuinely pending.
        $runner = new \Pramnos\Database\MigrationRunner($this->database, $histTable, $this);
        if (!$runner->hasPendingFromSlugs($slugTimestamps, $cutoff)) {
            // No pending migrations — record fingerprint for future fast-path.
            $this->insertFingerprintRow($fingerprint, $histTable, $quote);
            return;
        }

        // Phase 3: load PHP files and run pending autoExecute=true migrations.
        $migrations = \Pramnos\Database\MigrationLoader::loadFromDirectories($dirs, $this);
        $options    = [];
        if ($cutoff !== '') {
            $options['cutoff'] = $cutoff;
        }

        $runner->run($migrations, $options, static function(string $event, string $slug, string $error, float $ms = 0.0): void {
            \Pramnos\Debug\DebugBar::recordMigration($slug, $ms, $event === 'ran' ? 'ran' : 'failed');
        });

        // Record fingerprint so the next request uses the fast path.
        $this->insertFingerprintRow($fingerprint, $histTable, $quote);
    }

    /**
     * Inserts the "all-up-to-date" fingerprint row into the history table.
     * Uses INSERT IGNORE (MySQL) / INSERT … ON CONFLICT DO NOTHING (PG) so
     * concurrent requests never cause duplicate-key errors.
     */
    private function insertFingerprintRow(string $fingerprint, string $histTable, string $quote): void
    {
        try {
            if ($this->database->type === 'postgresql') {
                $sql = $this->database->prepareQuery(
                    "INSERT INTO {$quote}{$histTable}{$quote} ({$quote}key{$quote}, {$quote}scope{$quote}, {$quote}result{$quote})
                     VALUES (%s, 'framework', 1)
                     ON CONFLICT ({$quote}key{$quote}) DO NOTHING",
                    $fingerprint
                );
            } else {
                $sql = $this->database->prepareQuery(
                    "INSERT IGNORE INTO {$quote}{$histTable}{$quote} ({$quote}key{$quote}, {$quote}scope{$quote}, {$quote}result{$quote})
                     VALUES (%s, 'framework', 1)",
                    $fingerprint
                );
            }
            $this->database->query($sql);
        } catch (\Throwable) {
            // Non-fatal: the next request will simply redo the check.
        }
    }

    /**
     * Converts a datetime string from app.php format ('YYYY-MM-DD HH:mm:ss')
     * to the YYYY_MM_DD_HHmmss format used by MigrationRunner::filterCutoff().
     * Returns an empty string when the input is empty or unparseable.
     */
    private function normalizeMigrationCutoff(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTime($raw))->format('Y_m_d_His');
        } catch (\Throwable) {
            return '';
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
        $file = @fopen(ROOT . DS . 'var' . DS . "MAINTENANCE", "w+");
        if ($file === false) {
            return; // Cannot write maintenance flag (e.g. permission denied) — skip silently
        }
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

    /**
     * Registers the framework's built-in health checks with HealthRegistry.
     *
     * Called once during init().  Uses HealthRegistry::register() which is
     * idempotent — re-registering a check with the same name replaces it.
     *
     * Database connectivity check is skipped when the database failed to connect
     * (to avoid a redundant "not connected" error on top of the real error).
     */
    protected function registerBuiltInHealthChecks(): void
    {
        $registry = \Pramnos\Health\HealthRegistry::class;

        if ($this->database !== null && $this->database->connected) {
            $registry::register(
                new \Pramnos\Health\Checks\DatabaseConnectivityCheck($this->database),
            );
        }

        $registry::register(new \Pramnos\Health\Checks\DiskSpaceCheck());
        $registry::register(new \Pramnos\Health\Checks\MemoryLimitCheck());
    }


}
