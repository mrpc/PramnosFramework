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

    public $language = '';
    public $session;
    public $appName = ''; //Application Name
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
    public function __construct($appName)
    {
        $this->appName = $appName;
        parent::__construct();
    }

    /**
     * Load the database, session and settings classes
     */
    public function init()
    {

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
    public function redirect($url = null, $quit = true, $code='302')
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
