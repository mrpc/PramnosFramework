<?php
namespace Pramnos\Application;
/**
 * @package     PramnosFramework
 * @subpackage  Application
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Controller extends \Pramnos\Framework\Base
{
    /**
     * Actions allowed to be executed
     * @var array
     */
    public $actions = array();
    /**
     * Actions allowed to be executed if user has permission
     * @var array
     */
    public $actions_auth = array();

    /**
     * Permissions required for an action
     * @var array
     */
    protected $action_permissions = array();

    /**
     * User permissions
     * @var array
     */
    protected $user_permissions = array();

    /**
     * Controller Title
     * @var string
     */
    public $title = '';
    /**
     * Controller Name
     * @var string
     */
    public $controllerName = '';
    /**
     * Array of breadcrumbs used in the controller
     * @var array
     */
    public $breadcrumbs = array();
    /**
     * Application
     * @var \Pramnos\Application\Application
     */
    public $application = null;

    /**
     * Extra paths to check for views
     * BEFORE looking on normal path
     * @var array
     */
    protected $_priorityPaths = array();


    /**
     * Extra paths to check for views
     * if main paths are not found
     * @var array
     */
    protected $_extraPaths = array();

    /**
     * Extra paths to check for views
     * if main paths after application
     * @var array
     */
    protected $_lastPaths = array();

    /**
     * When a controller extends another controller
     * @var string
     */
    protected $_extends=NULL;

    /**
     * Adds a public action to the controller
     * @param string $action It should be a public method of the object
     */
    public function addaction($action)
    {
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->actions[] = $act;
            }
        } else {
            $this->actions[] = $action;
        }
    }

    /**
     * Adds an action to the controller for logged in users
     * @param string $action It should be a public method of the object
     */
    public function addAuthAction($action){
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->actions_auth[] = $act;
            }
        } else {
            $this->actions_auth[] = $action;
        }
    }

    /**
     * Adds a required permission to an action
     * @param string|array $action
     * @param string|array $permissions
     */
    public function addActionPermission($action, $permissions)
    {
        if (is_array($action)) {
            foreach ($action as $act) {
                $this->addActionPermission($act, $permissions);
            }
            return;
        }

        if (!isset($this->action_permissions[$action])) {
            $this->action_permissions[$action] = [];
        }

        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        $this->action_permissions[$action] = array_merge($this->action_permissions[$action], $permissions);
    }

    public function getBreadcrumbs()
    {
        return $this->breadcrumbs;
    }

    public function addBreadcrumb($item, $url = NULL)
    {
        $this->breadcrumbs[] = array('item' => $item, 'url' => $url);
        return $this;
    }

    /**
     * Force redirect of the page to another url
     * @param string  $url Url to redirect to
     * @param boolean $quit If you want to quit after redirecting.
     * @param string  $code Forces HTTP response code to the specified value.
     */
    public function redirect($url = null, $quit = true, $code='302')
    {
        $this->application->redirect($url, $quit, $code);
    }

    /**
     * Controller constructor
     * @param \Pramnos\Application\Application $application
     * @param array|string $userPermissions
     */
    public function __construct(
        ?\Pramnos\Application\Application $application = null,
        $userPermissions = []
    )
    {
        $this->application = $application;
        if ($application == null) {
            $this->application
                = \Pramnos\Application\Application::getInstance();
        }
        $this->user_permissions = $this->_auth_normalizePermissions($userPermissions);
        $this->controllerName = (new \ReflectionClass($this))->getShortName();
        $this->actions[] = 'display';
        parent::__construct();
    }

    /**
     * Execute a controller action if user is authorized.
     * Default action is display.
     * @param string $action
     * @param array $args
     */
    public function exec($action = '', $args = array())
    {
        if ($action === '') {
            $action = 'display';
        }
        if ($action == 'display') {
            $this->addBreadcrumb($this->title);
        }
        if (\Pramnos\Http\Request::$requestMethod != 'GET') {
            $actionWithMethod = strtolower(
                \Pramnos\Http\Request::$requestMethod . ucfirst($action)
            );
            if (method_exists($this, $actionWithMethod)
                && $this->auth($action)
                && $this->auth($actionWithMethod)) {
                return $this->$actionWithMethod($args);
            }
        }
        if (array_search($action, $this->actions) !== false
                || array_search($action, $this->actions_auth) !== false) {
            if ($this->auth($action)) {
                return $this->$action($args);
            } else {
                throw new \Exception(
                    'Not authenticated users cannot do that.',
                    403
                );
            }
        } elseif (array_search('display', $this->actions) !== false) {
            if ($this->auth('display')) {
                return $this->display($args);
            } else {
                throw new \Exception(
                    'Not authenticated users cannot do that.',
                    403
                );
            }
        }
    }

    /**
     * Default action
     */
    function display()
    {

    }

    /**
     * Check if a user can execute a controller action
     * @param string $action
     * @return boolean
     * @throws \Exception
     */
    public function auth($action)
    {
        $session = \Pramnos\Http\Session::getInstance();
        if (array_search($action, $this->actions_auth) !== false) {
            if ($session->isLogged() != true) {
                return false;
            }
        }

        // If we have user permissions, check them
        if (!empty($this->user_permissions)) {
            if (isset($this->action_permissions[$action])) {
                $required_permissions = $this->action_permissions[$action];
                if (!$this->_auth_hasPermissions($required_permissions, $this->user_permissions)) {
                    throw new \Exception(
                        'You do not have the required permissions to perform this action.',
                        403
                    );
                }
            }
        }

        return true;
    }


    /**
     * Get a model
     * @param string $name Model name
     * @return \Pramnos\Application\Model
     * @throws \Exception
     */
    public function &getModel($name = '')
    {
        if (isset($this->application->applicationInfo['namespace'])) {
            if ($this->application->appName == '') {
                $class = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\Models\\'
                    . $name;
            } else {
                $class = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\'
                    . $this->application->appName
                    . '\\Models\\'
                    . $name;
            }
        } elseif ($this->application->appName == '') {
            $class = '\\Pramnos\\Models\\'
                . $name;
        } else {
            $class = '\\Pramnos\\'
                . $this->application->appName
                . '\\Models\\'
                . $name;
        }
        if (class_exists($class)) {
            $model = new $class($this, $name);
            return $model;
        }
        if (class_exists(str_replace($name, ucfirst($name), $class))) {
            $class = str_replace($name, ucfirst($name), $class);
            $model = new $class($this, ucfirst($name));
            return $model;
        }
        throw new \Exception(
            'Cannot find model: ' . $name . ' (Class: ' . $class . ')'
        );


    }





    /**
     * Check if a user has the required permissions.
     *
     * @param array $requiredPermissions The permissions required by the route
     * @param array $userPermissions The permissions that the current user has
     * @return bool True if the user has the required permissions, false otherwise
     */
    protected function _auth_hasPermissions($requiredPermissions, $userPermissions = array())
    {
        $requiredPermissions = $this->_auth_normalizePermissions($requiredPermissions);
        $userPermissions = $this->_auth_normalizePermissions($userPermissions);

        if (empty($requiredPermissions)) {
            return true;
        }

        foreach ($requiredPermissions as $requiredScope) {
            if ($this->_auth_hasScope($requiredScope, $userPermissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize permissions to an array.
     *
     * @param array|string $permissions The permissions to normalize
     * @return array The normalized permissions
     */
    protected function _auth_normalizePermissions($permissions)
    {
        if (is_string($permissions)) {
            return explode(' ', $permissions);
        }
        return (array) $permissions;
    }

    /**
     * Check if a user has a specific scope.
     *
     * @param string $requiredScope The required scope
     * @param array $userScopes The scopes that the user has
     * @return bool True if the user has the required scope, false otherwise
     */
    protected function _auth_hasScope($requiredScope, $userScopes)
    {
        if (in_array($requiredScope, $userScopes)) {
            return true;
        }

        // Check for wildcard matches
        foreach ($userScopes as $userScope) {
            if ($this->_auth_wildcardMatch($requiredScope, $userScope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a required scope matches a user scope with wildcards.
     *
     * @param string $requiredScope The required scope (e.g., "posts:edit")
     * @param string $userScope The user's scope (e.g., "posts:*")
     * @return bool True if the scopes match, false otherwise
     */
    protected function _auth_wildcardMatch($requiredScope, $userScope)
    {
        if (strpos($userScope, '*') === false) {
            return false;
        }

        $pattern = '/^' . str_replace('\*', '.*', preg_quote($userScope, '/')) . '$/';
        return preg_match($pattern, $requiredScope) === 1;
    }

    /**
     *
     * @param string $path
     * @param string $name
     * @param string $type
     * @return \pramnos_application_view|\classname|boolean
     * @throws \Exception
     */
    private function _getView($path, $name, $type)
    {
        if ($type === '') {
            $doc = \Pramnos\Framework\Factory::getDocument();
            $type = $doc->type;
        }
        $tp = $path . DS . 'Views' . DS . $name;
        if (!file_exists($tp)) { // Check if template path exists
            $tp = $path . DS . 'views' . DS . $name;
            if (!file_exists($tp)) { // Check if template path exists
                return false;
            }
        }

        if (!is_dir($tp)){
            throw new \Exception('View is not a directory');
        }

        /**
         * Search for the right view class
         */

        if (isset($this->application->applicationInfo['namespace'])) {
            if ($this->application->appName != '') {
                $className = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\'
                    . $this->application->appName
                    . '\\Views\\'
                    . $name;
            } else {
                $className = '\\'
                    . $this->application->applicationInfo['namespace']
                    . '\\Views\\'
                    . $name;
            }
        } else {
            if ($this->application->appName != '') {
                $className = '\\Pramnos\\'
                    . $this->application->appName
                    . '\\Views\\'
                    . $name;
            } else {
                $className = '\\Pramnos\\Views\\'
                    . $name;
            }
        }
        if (class_exists($className)) {
            $view = new $className($this);
            return $view;
        }
        if (file_exists($tp . DS . $name . "." . $type . ".php")
            || file_exists($tp . DS  . "view." . $type . ".php")) {
            $view = new \Pramnos\Application\View($this, $tp, $name, $type);
            return $view;
        }
        return new \Pramnos\Application\View($this, $path, $name, $type);
    }



    /**
     * Gets a pramnos_application_view object
     * @param string $name
     * @param string $type
     * @param array $args
     * @return \Pramnos\Application\View
     */
    function &getView($name = '', $type = '', $args = array())
    {
        $paths = array_merge(
            $this->_priorityPaths,
            $this->_extraPaths
        );
        foreach ($paths as $path){
            $view = $this->_getView($path, $name, $type, $args);
            if ($view){
                return $view;
            }
        }
        // In case we can't find the view, we search in Application path.
        // Check for app extra paths
        if ($this->application->appName == '') {
            $appPaths = array_merge(
                array(
                    ROOT . DS . INCLUDES
                ),
                $this->application->getExtraPaths(),
                $this->_lastPaths
            );
        } else {
            $appPaths = array_merge(
                array(
                    ROOT . DS . INCLUDES . DS . $this->application->appName
                ),
                $this->application->getExtraPaths(),
                $this->_lastPaths
            );
        }

        foreach ($appPaths as $path) {
            $view = $this->_getView($path, $name, $type, $args);
            if ($view){
                return $view;
            }
        }
        if ($type == '') {
            $doc = \Pramnos\Framework\Factory::getDocument();
            $type = $doc->type;
        }
        \Pramnos\Logs\Logger::log(
            'Cannot find view: '
            . $name
            . ' (type: ' . $type . ', class: ' . $name . ')'
        );
        throw new \Exception(
            'Cannot find view: '
            . $name
            . ' (type: ' . $type . ', class: ' . $name . ')'
        );
    }


    /**
     * Set content type for the theme
     * @param string $contentType
     */
    public function setContentType($contentType)
    {
        $document = \Pramnos\Document\Document::getInstance();
        if ($document->themeObject !== NULL) {
            $document->themeObject->setContentType($contentType);
        }
    }

}
