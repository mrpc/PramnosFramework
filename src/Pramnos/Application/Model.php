<?php
namespace Pramnos\Application;
/**
 * @package      PramnosFramework
 * @subpackage   Application
 * @copyright    2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author       Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Model extends \Pramnos\Framework\Base
{
    /**
     * Model name
     * @var string
     */
    public $name = '';
    /**
     * Database table
     * @var string
     */
    protected $_dbtable = null;
    /**
     * Cache key
     * @var string
     */
    protected $_cacheKey = null;
    /**
     * Primary key in database
     * @var string
     */
    protected $_primaryKey  = 'id';
    /**
     * Is the model new?
     * @var boolean
     */
    protected $_isnew       = true;
    /**
     * Array onf json actions, used in getJsonList
     * @var type
     */
    private   $_jsonactions = array();
    /**
     * Database prefix used for this model
     * @var string
     */
    public $prefix = '';
    /**
     * Reference to the controller calling this model for better communication
     * @var \Pramnos\Application\Controller
     */
    public $controller = null;


    public static $columnCache = array();

    /**
     * Class constructor. Sets the model name and the database table
     * @param \Pramnos\Application\Controller Current controller
     * @param string $name Name of model - used automatic table discover
     */
    public function __construct(\Pramnos\Application\Controller $controller,
        $name = '')
    {
        if ($name == '') {
            $name = (new \ReflectionClass($this))->getShortName();
        }
        $this->name = $name;

        $this->controller = $controller;
        if ($this->_dbtable === null) {
            $this->_dbtable = '#PREFIX#' . $name . 's';
        }
        $database = \Pramnos\Database\Database::getInstance();
        $this->_dbtable=str_ireplace(
            '#PREFIX#', $database->prefix, $this->_dbtable
        );

        parent::__construct();
    }


    /**
     * This function can run after initial variable setups
     */
    public function __init()
    {
        $this->_dbtable=str_ireplace(
            '#THISPREFIX#', $this->prefix . '_', $this->_dbtable
        );
    }

    /**
     * Get another model (gets it from module)
     * @param string $model
     * @return Model
     */
    public function &getModel($model)
    {
        return $this->controller->getModel($model);
    }

    /**
     * Function to automate saving an object to the database
     * @param string    $table
     * @param string    $key
     * @param boolean   $autoGetValues If true, get all values from $_REQUEST
     * @param boolean   $debug Show debug information (and die)
     * @return          Model
     */
    protected function _save($table = NULL, $key = NULL,
        $autoGetValues = false, $debug = false)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($autoGetValues == true) {
            $request = new \Pramnos\Http\Request();
        }
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }

        if ($debug==true) {
            var_dump($_POST, $this);
        }

        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $itemdata = array();

            if (isset(self::$columnCache[$this->_dbtable])) {
                foreach (self::$columnCache[$this->_dbtable] as $fields) {
                    if ($fields['Field'] != $this->_primaryKey) {
                        $field = $fields['Field'];
                        if ($fields['Null'] == "NO") {
                            if ($this->$field === NULL) {
                                $this->$field = "";
                            }
                        }
                        if ($autoGetValues == true) {
                            if ($debug == true) {
                                echo "<br />" . $this->$field
                                    . ': '
                                    . $request->get(
                                        $field, $this->$field, 'post'
                                    );
                            }
                            $this->$field = $request->get(
                                $field, $this->$field, 'post'
                            );
                        }
                        $itemdata[] = array(
                            'fieldName' => $fields['Field'],
                            'value'     => $this->$field,
                            'type'      => $this->fieldtype($fields['Type'])
                        );
                    }
                }
            } else {
                $sql    = "SHOW COLUMNS FROM `" . $this->_dbtable . "`";
                $result = $database->Execute($sql);
                self::$columnCache[$this->_dbtable] = array();
                while (!$result->eof) {
                    self::$columnCache[$this->_dbtable][] = $result->fields;
                    if ($result->fields['Field'] != $this->_primaryKey) {
                        $field = $result->fields['Field'];
                        if ($result->fields['Null'] == "NO") {
                            if ($this->$field === NULL) {
                                $this->$field = "";
                            }
                        }
                        if ($autoGetValues == true) {
                            if ($debug == true) {
                                echo "<br />" . $this->$field
                                    . ': '
                                    . $request->get(
                                        $field, $this->$field, 'post'
                                    );
                            }
                            $this->$field = $request->get(
                                $field, $this->$field, 'post'
                            );
                        }
                        $itemdata[] = array(
                            'fieldName' => $result->fields['Field'],
                            'value'     => $this->$field,
                            'type'      => $this->fieldtype(
                                $result->fields['Type']
                            )
                        );
                    }
                    $result->MoveNext();
                }
            }
            $primarykey = $this->_primaryKey;
            if ($debug==true) {
                var_dump($itemdata);
            }

            if ($this->_isnew == true) {

                $this->_isnew = false;
                $result = $database->perform(
                    $this->_dbtable, $itemdata, 'insert', '', $debug
                );
                if ($result==false) {
                    $error = $database->sql_error();
                    throw new Exception($error['message']);
                }
                $this->$primarykey = $database->sql_nextid();
            } else {
                $database->perform(
                    $this->_dbtable, $itemdata,
                    'update', "`" . $primarykey . "` = '"
                    . (int) $this->$primarykey . "'", $debug
                );
            }
            $database->sql_cache_flush_cache($this->_cacheKey);
        }

        return $this;
    }



    /**
     * Function to automate loading an object from the database
     * @param string $primaryKey
     * @param string $table
     * @param string $key
     * @param boolean   $debug
     * @return Model
     */
    protected function _load($primaryKey, $table = NULL,
        $key = NULL, $debug=false)
    {

        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $sql = $database->prepare(
                "select * from "
                . $this->_dbtable
                . " where `"
                . $this->_primaryKey
                . "` = %s limit 1",
                $primaryKey
            );
            if ($debug === true) {
                die($sql);
            }
            $result = $database->Execute($sql, true, 600, $this->_cacheKey);
            if ($result->numRows != 0) {
                foreach (array_keys($result->fields) as $field) {
                    $this->$field = $result->fields[$field];
                }
                $this->_isnew = false;
            }
        }
        return $this;
    }

    /**
     * Function to automate deleting an object from the database
     * @param integer $primaryKey
     * @param string $table
     * @param string $key
     * @return Model
     */
    protected function _delete($primaryKey, $table = NULL, $key = NULL)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $sql = "delete from `" . $this->_dbtable
                . "` where `" . $this->_primaryKey
                . "` = " . (int) $primaryKey . " limit 1";
            $database->Execute($sql);
            $database->sql_cache_flush_cache($this->_cacheKey);
        }
        $this->_isnew = true;
        return $this;
    }

    /**
     * Similar to getList(), good for pagination
     * @param  int     $items  Number of items by page
     * @param  int     $page   Current page number
     * @param  string  $filter Filter for where statement in database query
     * @param  string  $order  Order for database query
     * @param  string  $table  Database table
     * @param  string  $key    Database primary key
     * @param  boolean $debug  Show debug information
     * @return array           Three keys: total, pages, items
     */
    protected function _getPaginated($items=10, $page=1,
        $filter = NULL, $order = NULL, $table = NULL,
        $key = NULL, $debug=false)
    {
        $items = abs((int)$items);
        $page-=1;
        $page = abs((int)$page);
        $page = $items * $page;
        if ($table === NULL && $this->_dbtable === NULL) {
            $table = '#PREFIX#' . $this->prefix . '_' . $this->name;
        }
        $objects = array();
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            $this->load(0);
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $primarykey = $this->_primaryKey;
            if ($filter === NULL) {
                $filter = "";
            }
            if ($order === NULL || $order === '') {
                $order  = " order by `" . $primarykey . "` DESC ";
            }
            $sql = "select * from `" . $this->_dbtable
                . "` " . $filter . ' ' . $order . ' limit '
                . $page . ', ' . $items;

            $countSql = "select count(`" . $primarykey . "`) "
                . "as 'itemsCount'  from `"
                . $this->_dbtable . "` " . $filter . ' ' . $order ;
            $countResult = $database->Execute(
                $countSql, true, 600, $this->_cacheKey
            );
            $totalItems = $countResult->fields['itemsCount'];

            if ($debug==true) {
                die($sql);
            }
            $result = $database->Execute($sql, true, 600, $this->_cacheKey);
            while (!$result->eof) {
                $objects[$result->fields[$primarykey]] = $this->getModel(
                    $this->name
                );
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                $result->MoveNext();
            }
        }

        if ($totalItems == 0 | $items == 0) {
            $totalPages = 1;
        } else {
            $totalPages = ceil($totalItems / $items);
        }


        return array(
            'total'=>$totalItems,
            'pages'=>$totalPages,
            'items'=>$objects
            );
    }

    /**
     * Get an array of objects from database
     * @param string $filter Filter for where statement in database query
     * @param string $order Order for database query
     * @param type $table
     * @param type $key
     * @return array
     */
    public function _getList($filter = NULL, $order = NULL,
        $table = NULL, $key = NULL, $debug=false)
    {
        if ($table === NULL && $this->_dbtable === NULL) {
            $table = '#PREFIX#' . $this->prefix . '_' . $this->name;
        }
        $objects = array();
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            $this->load(0);
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $primarykey = $this->_primaryKey;
            if ($filter === NULL) {
                $filter = "";
            }
            if ($order === NULL) {
                $order  = " order by `" . $primarykey . "` DESC ";
            }
            $sql = "select * from `"
                . $this->_dbtable . "` " . $filter . ' ' . $order;
            if ($debug==true) {
                die($sql);
            }
            $result = $database->Execute($sql, true, 600, $this->_cacheKey);
            while (!$result->eof) {
                $objects[$result->fields[$primarykey]]
                    = $this->getModel($this->name);
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                $result->MoveNext();
            }
        }
        return $objects;
    }

    /**
     * Get a list of objects as a json encoded string
     * @param string $filter Filter for sql statement (where)
     * @param string $table Database table
     * @param string $key Primary key in database
     * @return string json encoded string
     */
    public function _getJsonList($filter = NULL, $table = NULL, $key = NULL)
    {
        $lang = \Pramnos\Translator\Language::getInstance();
        $database = \Pramnos\Database\Database::getInstance();
        $objects = array();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            $this->load(0);
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            #$primarykey = $this->_primaryKey;


            if ($filter === NULL) {
                $filter = "";
            }

            $fields = array();


            $sql    = "SHOW COLUMNS FROM `" . $this->_dbtable . "`";
            $result = $database->Execute($sql);

            while (!$result->eof) {
                $fields[] = $result->fields['Field'];
                $result->MoveNext();
            }

            $objects = pramnos_html_datatable_datasource::getList(
                $this->_dbtable, $fields, false, $filter
            );

            if (is_array($this->_jsonactions)
                && count($this->_jsonactions) != 0) {

                if (isset($objects['aaData'])) {
                    if (is_array($objects['aaData'])) {
                        $loop = 0;


                        foreach ($objects['aaData'] as $data) {

                            foreach ($this->_jsonactions as $action) {
                                $targetfield=0;
                                foreach ($fields as $fieldcount=>$field) {
                                    if ($field == $action['field']) {
                                        $targetfield=$fieldcount;
                                    }
                                }

                                if (strpos(
                                    $action['action'], 'http'
                                ) === false) {
                                    $url = sURL .$this->prefix
                                        . '/' . $action['action']
                                        . '/' . $data[$targetfield];
                                } else {
                                    $url = $action['action'] . '/'
                                        . $data[$targetfield];
                                }

                                $confirm = '';
                                if ($action['confirm'] == true) {
                                    $confirm=" onclick=\"return "
                                        . "confirm("
                                        . "'".$lang->_('Are you sure?')
                                        ."');\" ";
                                }
                                if ($action['column'] == '') {
                                    if ($action['title'] == '') {
                                        $a= '<a '.$confirm.' href="'.$url.'">'
                                            . $action['action'].'</a>';
                                    } else {
                                        $a= '<a '.$confirm.' href="'.$url.'">'
                                            . $action['title'].'</a>';
                                    }

                                    $data[]=$a;
                                } else {

                                    foreach ($fields as $fieldcount=>$field) {
                                        if ($field == $action['column']) {
                                            $a= '<a '.$confirm.' href="'
                                                . $url.'">'
                                                . $data[$fieldcount].'</a>';
                                            $data[$fieldcount]=$a;
                                        }
                                    }
                                }
                            }


                            $objects['aaData'][$loop] = $data;
                            $loop+=1;
                        }
                    }
                }
            }



            return json_encode($objects);

        }
        return $objects;
    }

    /**
     * Try to find the best _cacheKey to automate database caching
     * @return Model
     */
    private function _fixDb()
    {
        $database = \Pramnos\Database\Database::getInstance();
        $this->_cacheKey = str_replace(
            $database->prefix, '', $this->_dbtable
        );
        if ($this->prefix != "") {
            $this->_cacheKey = str_replace(
                "_" . $this->prefix, '', $this->_cacheKey
            );
            if ($this->_cacheKey != $this->prefix) {
                $this->_cacheKey = str_replace(
                    $this->prefix, '', $this->_cacheKey
                );
            }
        }
        return $this;
    }


    /**
     * "Translate" database table field types to types used in Database
     * @param string $type
     * @return string
     */
    private function fieldtype($type)
    {
        $type = explode("(", $type);
        $type = $type[0];
        switch ($type) {

            case "int":
            case "tinyint";
            case "bigint":
                return "integer";
            case "double":
            case "float":
                return "float";
            default:
                return "string";
        }
    }

    /**
     * Add a json action for getJsonList
     * @param string  $action
     * @param string  $field
     * @param string  $column
     * @param string  $title
     * @param boolean $confirm
     */
    protected function addJsonAction($action, $field='',
        $column='', $title='',$confirm=false)
    {
        $this->_jsonactions[$action]=array(
            'action'=>$action,
            'field'=>$field,
            'column'=>$column,
            'title'=>$title,
            'confirm'=>$confirm
            );
    }

    /**
     * Returns an array with all useful object data for json encoding
     * @return array
     */
    public function getData()
    {
        $data = array();
        foreach (get_object_vars($this) as $key=>$value) {
            if (is_numeric($value) || is_string($value)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

}
