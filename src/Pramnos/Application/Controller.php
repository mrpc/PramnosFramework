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
    public $_breadcrumbs = array();

    /**
     * Extra paths to check for models and views
     * BEFORE looking on normal path
     * @var array
     */
    protected $_priorityPaths = array();


    /**
     * Extra paths to check for models and views
     * if main paths are not found
     * @var array
     */
    protected $_extraPaths = array();

    /**
     * Extra paths to check for models and views
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

    public function _getBreadcrumbs()
    {
        return $this->_breadcrumbs;
    }

    public function _addBreadcrumb($item, $url = NULL)
    {
        $this->_breadcrumbs[] = array('item' => $item, 'url' => $url);
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
        $app = \Pramnos\Application\Application::getInstance();
        $app->redirect($url, $quit, $code);
    }

    public function __construct()
    {
        $this->controllerName = get_class($this);
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
            $this->_addBreadcrumb($this->title);
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

    function auth($action)
    {
        $session = \Pramnos\Http\Session::getInstance();
        if (array_search($action, $this->actions_auth) !== false) {
            if ($session->isLogged() == true) {
                return true;
            } else {
                return false;
            }
        }
        return true;
    }



    /**
     * Check if a model exists and load it
     * It should be used to search for models and views.
     * @param string $filename
     * @param string $classname
     * @param string $name
     * @param array $args
     * @return \classname|boolean
     */
    private function _getModel($path, $name, $args)
    {
        if ($this->_name !== "") {
            $classname = $this->_name . '_' . $name . 'model';
        } else {
            $classname = $classname = $name . 'model';
        }
        $filename = $path . DS . 'models' . DS . $name . '.' . 'php';
        #echo "<br />$filename";
        if (file_exists($filename)) {
                include_once($filename);
                if (class_exists($classname)) {
                    $model = new $classname($name, $args);
                    $model->controller = $this;
                    return $model;
                } elseif ($this->_name !== "" && class_exists($name . 'model') ){
                    $classname = $name . 'model';
                    $model = new $classname($name, $args);
                    $model->controller = $this;
                    return $model;
                } elseif ($this->_extends){
                    $classname = $this->_extends . '_' . $name . 'model';
                    $model = new $classname($name, $args);
                    $model->controller = $this;
                    return $model;
                }
            }
            return false;
    }


    /**
     * Get a model
     * @param string $name
     * @param array $args
     * @return object A model object
     */
    public function &getModel($name = '', $args = array())
    {
        static $modelPaths = array(); // Cache the correct array path
        if (isset($modelPaths[$name])){
            $model=$this->_getModel($modelPaths[$name], $name, $args);
            if ($model){
                return $model;
            } else {
                unset($modelPaths[$name]);
            }
        }
        $paths = array_merge(
                $this->_priorityPaths,
                array($this->_path . DS . $this->_name, $this->_path),
                $this->_extraPaths
                );
        foreach ($paths as $path){
            $model = $this->_getModel($path, $name, $args);
            if ($model){
                $modelPaths[$name]=$path;
                return $model;
            }
        }
        $app = \Pramnos\Application\Application::getInstance();
        // In case we can't find the model, we search in Application path.
        // Check for app extra paths
        $appPaths = array_merge(
            array(APPS_PATH . DS . $app->appName),
            $app->getExtraPaths(),
            $this->_lastPaths
        );
        foreach ($appPaths as $path) {
            $model = $this->_getModel($path, $name, $args);
            if ($model){
                $modelPaths[$name]=$path;
                return $model;
            }
        }
        $return = new pramnos_application_model();
        die ('Cannot find model: ' . $name . ' (Class: ' . $this->_name . ')');
        \Pramnos\Logs\Logs::log('Cannot find model: ' . $name . ' (Class: ' . $this->_name . ')');
        $app->setRedirect(URL);
        return $return;
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
        $tp = $path . DS . 'views' . DS . $name;
        if (!file_exists($tp)) { // Check if template path exists
            return false;
        }

        if (!is_dir($tp)){
            throw new \Exception('View is not a directory');
        }

        /**
         * Search for the right view class
         */
        $app = \Pramnos\Application\Application::getInstance();
        if (isset($app->applicationInfo['namespace'])) {
            if ($app->appName != '') {
                $className = '\\'
                    . $app->applicationInfo['namespace']
                    . '\\'
                    . $app->appName
                    . '\\Views\\'
                    . $name;
            } else {
                $className = '\\'
                    . $app->applicationInfo['namespace']
                    . '\\Views\\'
                    . $name;
            }
        } else {
            if ($app->appName != '') {
                $className = '\\Pramnos\\'
                    . $app->appName
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
        return false;
    }



    /**
     * Gets a pramnos_application_view object
     * @param string $name
     * @param string $type
     * @param array $args
     * @return \pramnos_application_view|\classname
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

        $app = \Pramnos\Application\Application::getInstance();
        // In case we can't find the view, we search in Application path.
        // Check for app extra paths
        if ($app->appName == '') {
            $appPaths = array_merge(
                array(
                    ROOT . DS . INCLUDES
                ),
                $app->getExtraPaths(),
                $this->_lastPaths
            );
        } else {
            $appPaths = array_merge(
                array(
                    ROOT . DS . INCLUDES . DS . $app->appName
                ),
                $app->getExtraPaths(),
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
        \Pramnos\Logs\Logs::log(
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
