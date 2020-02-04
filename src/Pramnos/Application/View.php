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

    protected $_models = array();
    protected $_defaultModel = '';
    protected $_path = '';
    protected $_name = '';
    protected $_type = 'html';
    public $output = '';
    public $model = '';
    public $controllerName = '';

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
     * @param string $_path
     * @param string $_name
     * @param string $_type
     */
    public function __construct($_path='', $_name='', $_type='html')
    {
        $this->_path=$_path;
        $this->_name=$_name;
        $this->_type=$_type;
        $this->_defaultModel=$_name;
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
            $this->_models[$model->_name] = $model;
            if ($default !== false) {
                $this->_defaultModel = $model->_name;
                $this->model =& $this->getModel($this->_defaultModel);
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
            $model = $this->_defaultModel;
        }
        if (isset($this->_models[$model])
            && is_object($this->_models[$model])) {
            return $this->_models[$model];
        }
        else {
            $model = false;
            return $model;
        }
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
        if (!function_exists('l')) {
            /**
             * Alias of echo $lang->_('string');
             */
            function l(){
                $lang = \Pramnos\Framework\Factory::getLanguage();
                $params = func_get_args();
                echo call_user_func_array(array($lang,'_'), $params);
            }
        }
        if ($tpl === '') {
            $tpl = $this->_name;
        }
        if ($type === '') {
            $type = $this->_type;
        }
        $_url = URL . $this->modulename . '/';
        $model=$this->model;



        $tplfile = $this->_path . DS . 'tpl'
            . DS . $tpl . '.' . $type . '.php';

        if (is_object($doc->themeObject)
            && $doc->themeObject->allowsViewOverrides()) {
            $viewTplFile=$doc->themeObject->fullpath . DS . 'views' . DS
                . $this->_name . DS . 'tpl' . DS . $tpl
                . '.' . $type . '.php';
            if (file_exists($viewTplFile)) {
                $tplfile = $viewTplFile;
            }
        }

        if (file_exists($tplfile)) {
            ob_start();
            try {
                include $tplfile;
            } catch (Exception $ex) {
                \Pramnos\Logs\Logs::log(
                    'Error in view: ' . $this->_name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
                throw new \Exception(
                    'Error rendering template file. '
                    . 'View: ' . $this->_name . ' and template file: '
                    . $tplfile . '. ' . $ex->getMessage()
                    . ' at line ' . $ex->getLine()
                );
            }
            $tplInformation = '';
            if ($this->_type == 'html') {
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
        }
        else {
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
            if ($this->_type != 'raw' && $this->_type != 'json') {
                \Pramnos\Logs\Logs::log(
                    'Cannot find view template. View:'
                    . $this->_name . ', template: '
                    . $tpl . ", type: ".$this->_type."\n"
                    . \Pramnos\General\Helpers::varDumpToString(debug_backtrace())
                );
            }
            return false;
        }
    }


}
