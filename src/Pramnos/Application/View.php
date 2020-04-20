<?php
namespace Pramnos\Application;
/**
 * @package     PramnosFramework
 * @subpackage  Application
 * @copyright   2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class View extends \Pramnos\Framework\Base
{
    /**
     * Array of models
     * @var \Pramnos\Application\Model[]
     */
    protected $models = array();
    /**
     * Default model name
     * @var string
     */
    protected $defaultModel = '';
    /**
     * View path
     * @var string
     */
    protected $path = '';
    /**
     * View name
     * @var string
     */
    protected $name = '';
    /**
     * View type
     * @var string
     */
    protected $type = 'html';
    /**
     * Model output
     * @var string
     */
    public $output = '';
    /**
     * Current Model
     * @var \Pramnos\Application\Model
     */
    public $model = false;
    /**
     * Current Controller
     * @var \Pramnos\Application\Controller
     */
    public $controller = null;

    /**
     * Render and return the view contents
     * @param string $tpl template file to load
     * @param bool $render if is set to true, output will not buffered
     * @return string
     */
    public function display($tpl='', $render=false)
    {
        $this->model =& $this->getModel();
        if ($render == true){
            return $this->getTpl($tpl, '', $render);
        }

        $this->getTpl($tpl, '', $render);
        return $this->output;
    }

    /**
     * View constructor
     * @param \Pramnos\Application\Controller $controller Current controller
     * @param string $path
     * @param string $name
     * @param string $type
     */
    public function __construct(\Pramnos\Application\Controller $controller,
        $path='', $name='', $type='html')
    {
        $this->controller = $controller;
        $this->path=$path;
        $this->name=$name;
        $this->type=$type;
        $this->defaultModel=$name;
        parent::__construct();
    }

    /**
     * Adds a model to the view
     * @param \Pramnos\Application\Model $model
     * @param boolean $default Is this model the main used for this view?
     */
    public function addModel(\Pramnos\Application\Model &$model, $default=true)
    {
        if (is_object($model)){
            $this->models[$model->name] = $model;
            if ($default !== false) {
                $this->defaultModel = $model->name;
                $this->model =& $this->getModel($this->defaultModel);
            }
        }
    }

    /**
     * Gets a model, if it exists
     * @param string $model Model name
     * @return boolean|\Pramnos\Application\Model
     */
    public function &getModel($model='')
    {
        if ($model === ''){
            $model = $this->defaultModel;
        }
        if (isset($this->models[$model])
            && is_object($this->models[$model])) {
            return $this->models[$model];
        }
        else {
            $model = false;
            return $model;
        }
    }

    /**
     * Get view type
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }




    /**
     * Gets a tpl file for the current view. Tpl file can be placed in
     * current theme's directory to overide the normal tpl file
     * @param string $tpl
     * @param string $type
     * @param boolean $render
     * @return boolean
     */
    public function getTpl($tpl='', $type='', $render=false)
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        if ($tpl === '') {
            $tpl = $this->name;
        }
        if ($type === '') {
            $type = $this->type;
        }
        $_url = URL . $this->controllerName . '/';
        $model=$this->model;

        $tplfile = $this->path
            . DS . $tpl . '.' . $type . '.php';

        if (is_object($doc->themeObject)
            && $doc->themeObject->allowsViewOverrides()) {
            $viewTplFile=$doc->themeObject->fullpath . DS . 'views' . DS
                . $this->name . DS . $tpl
                . '.' . $type . '.php';
            if (file_exists($viewTplFile)) {
                $tplfile = $viewTplFile;
            }
        }

        if (file_exists($tplfile)) {
            ob_start();
            try {
                $lang = \Pramnos\Framework\Factory::getLanguage();
                include $tplfile;
            } catch (Exception $ex) {
                \Pramnos\Logs\Logger::log(
                    'Error in view: ' . $this->name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
                throw new \Exception(
                    'Error rendering template file. '
                    . 'View: ' . $this->name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
            }
            $tplInformation = '';
            if ($this->type == 'html') {
                $tplInformation = "\n<!-- \n"
                    . "View Rendered at: "
                    . date('d/m/Y H:i:s')
                    . "\nView Path: "
                    . str_replace(ROOT, '', $tplfile)
                    . "\n-->";
            }
            if ($render == true){
                return ob_get_clean() . $tplInformation;
            }
            $this->output .= ob_get_clean() . $tplInformation;
            return true;
        } else {
            if (\Pramnos\Http\Request::staticGet(
                'format', '', 'get'
            ) == 'json') {
                if (isset($this->model)){
                    if (method_exists($this->model, 'getJsonList')){
                        $this->output = $this->model->getJsonList();
                        return true;
                    }
                }
            }
            if ($this->type != 'raw' && $this->type != 'json') {
                \Pramnos\Logs\Logger::log(
                    'Cannot find view template. View:'
                    . $this->name . ', template: '
                    . $tpl . ", type: " . $this->type . "\n"
                    . \Pramnos\General\Helpers::varDumpToString(debug_backtrace())
                );
            }
            return false;
        }
    }


}
