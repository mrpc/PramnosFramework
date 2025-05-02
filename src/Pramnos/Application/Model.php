<?php
namespace Pramnos\Application;
/**
 * @package      PramnosFramework
 * @subpackage   Application
 * @copyright    2005 - 2025 Yannis - Pastis Glaros
 * @author       Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Model extends \Pramnos\Framework\Base
{
    /**
     * Model name
     * @var string
     */
    protected $modelname = '';
    /**
     * Database table
     * @var string
     */
    protected $_dbtable = null;
    /**
     * Database schema
     * @var string
     */
    protected $_dbschema = null;
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
     * Initial data loaded from database
     * @var array
     */
    protected $_initialData = array();

    /**
     * Array of last changes
     * @var array
     */
    protected $_lastChanges = array();

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
        $this->modelname = $name;

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
     * @param boolean   $force Force the save operation
     * @return          Model
     */
    protected function _save($table = NULL, $key = NULL,
        $autoGetValues = false, $debug = false, $force = false)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($autoGetValues == true) {
            $request = new \Pramnos\Http\Request();
        }
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }

        if ($debug==true) {
            var_dump($_POST, $this);
        }

        // For existing records, check if there are any changes before saving
        if (!$this->_isnew && !empty($this->_initialData) && $force == false) {
            $changes = $this->getChanges();
            if (empty($changes)) {
                // No changes detected, no need to save
                return $this;
            }
        }

        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $itemdata = array();

            if (isset(self::$columnCache[$this->getFullTableName()])) {
                foreach (self::$columnCache[$this->getFullTableName()] as $fields) {
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

                if ($database->type == 'postgresql') {
                    if ($this->_dbschema != null) {
                        $schema = $this->_dbschema;
                    } else {
                        $schema = $database->schema;
                    }
                    $sql = "SELECT column_name as \"Field\", data_type as \"Type\", is_nullable as \"Null\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '"
                    . $schema
                    . "' AND table_name = '"
                    . str_replace('#PREFIX#', $database->prefix, $this->_dbtable)
                    . "';";
                } else {
                    $sql    = "SHOW COLUMNS FROM `" . $this->getFullTableName() . "`";
                }

                
                $result = $database->query($sql);
                self::$columnCache[$this->getFullTableName()] = array();
                while ($result->fetch()) {
                    self::$columnCache[$this->getFullTableName()][] = $result->fields;
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
                            'rawtype'  => $result->fields['Type'],
                            'type'      => $this->fieldtype(
                                $result->fields['Type']
                            )
                        );
                    }
                }
            }
            $primarykey = $this->_primaryKey;
            if ($debug==true) {
                var_dump($itemdata);
            }

            if ($this->_isnew == true) {

                $this->_isnew = false;
                $result = $database->insertDataToTable(
                    $this->getFullTableName(), $itemdata, $primarykey, $debug
                );
                if ($result==false) {
                    $error = $database->getError();
                    throw new \Exception($error['message']);
                }
                if ($database->type == 'postgresql') {
                    $this->$primarykey = pg_fetch_result($result, 0, $primarykey);
                } else {
                    $this->$primarykey = $database->getInsertId();
                }
                
            } else {
                $database->updateTableData(
                    $this->getFullTableName(), $itemdata,
                    "`" . $primarykey . "` = '" . $this->$primarykey . "'",
                    $debug
                );
            }
            $database->cacheflush($this->_cacheKey);
            
            // After successful save, update the initial data to match current state
            $this->_initialData = array();
            foreach ($itemdata as $item) {
                $field = $item['fieldName'];
                $this->_initialData[$field] = $this->$field;
            }
            // Also make sure primary key is in initial data
            if (isset($this->$primarykey)) {
                $this->_initialData[$primarykey] = $this->$primarykey;
            }
        }
        if (!isset($changes)) {
            $changes = array();
            foreach ($itemdata as $item) {
                $field = $item['fieldName'];
                $changes[$field] = array(
                    'old' => null,
                    'new' => $this->$field
                );
            }
        }
        $this->_lastChanges = $changes;
        return $this;
    }

    /**
     * Function to get the count of items based on the provided filter, table, and key.
     * @param string $filter
     * @param string $table
     * @param string $key
     * @return integer
     */
    public function getCount($filter = NULL, $table = NULL, $key = NULL)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            return 0;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $primarykey = $this->_primaryKey;
            if ($filter === NULL) {
                $filter = "";
            }
            if ($database->type == 'postgresql') {                
                $sql = "select count(*) as \"itemsCount\" from "
                    . $this->getFullTableName() . " " . $filter;
            } else {
                $sql = "select count(*) as 'itemsCount' from `"
                    . $this->getFullTableName() . "` " . $filter;
            }
            $result = $database->query($sql, true, 600, $this->_cacheKey);
            return $result->fields['itemsCount'];
        }
        return 0;
    }



    /**
     * Function to automate loading an object from the database
     * @param string $primaryKey
     * @param string $table
     * @param string $key
     * @param boolean   $debug
     * @param boolean   $useCache Use cache?
     * @return Model
     */
    protected function _load($primaryKey, $table = NULL,
        $key = NULL, $debug=false, $useCache = true)
    {

        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            if ($database->type == 'postgresql') {
                
                $sql = $database->prepareQuery(
                    "select * from "
                    . $this->getFullTableName()
                    . " where `"
                    . $this->_primaryKey
                    . "` = %s limit 1",
                    $primaryKey
                );
            
            } else {
                $sql = $database->prepareQuery(
                    "select * from "
                    . $this->getFullTableName()
                    . " where `"
                    . $this->_primaryKey
                    . "` = %s limit 1",
                    $primaryKey
                );
            }
            
            if ($debug === true) {
                die($sql);
            }
            $result = $database->query($sql, $useCache, 600, $this->_cacheKey);
            if ($result->numRows != 0) {
                // Reset initial data array
                $this->_initialData = array();
                
                foreach (array_keys($result->fields) as $field) {
                    $this->$field = $result->fields[$field];
                    // Store initial value
                    $this->_initialData[$field] = $result->fields[$field];
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
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $sql = "delete from " . $this->getFullTableName()
                . " where " . $this->_primaryKey
                . " = " . (int) $primaryKey;
            $database->query($sql);
            $database->cacheflush($this->_cacheKey);
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
            $table = '#PREFIX#' . $this->prefix . '_' . $this->modelname;
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
            $sql = "select * from `" . $this->getFullTableName()
                . "` " . $filter . ' ' . $order . ' limit '
                . $page . ', ' . $items;

            $countSql = "select count(`" . $primarykey . "`) "
                . "as 'itemsCount'  from `"
                . $this->getFullTableName() . "` " . $filter . ' ' . $order ;
            $countResult = $database->query(
                $countSql, true, 600, $this->_cacheKey
            );
            $totalItems = $countResult->fields['itemsCount'];

            if ($debug==true) {
                die($sql);
            }

            $class = get_class($this);
            

            $result = $database->query($sql, true, 600, $this->_cacheKey);
            while ($result->fetch()) {
                $objects[$result->fields[$primarykey]] = new $class(
                    $this->controller
                );
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
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
            $table = '#PREFIX#' . $this->prefix . '_' . $this->modelname;
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
            } else {
                if ($database->type == 'postgresql') {
                    $filter = str_replace('`', '"', $filter);
                }
            }
            if ($order === NULL) {
                $order  = " order by " . $primarykey . " DESC ";
            }
            if ($database->type == 'postgresql') {
                $order = str_replace('`', '"', $order);
            }

            
            if ($database->type == 'postgresql') {
                
                $sql = "select * from " 
                    . $this->getFullTableName() . " " . $filter . ' ' . $order;
            
            } else {
                $sql = "select * from `"
                    . $this->getFullTableName() . "` " . $filter . ' ' . $order;
            }
            if ($debug==true) {
                die($sql);
            }
            try {
                $result = $database->query($sql, true, 600, $this->_cacheKey);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::logError("Error in getList query: " . $sql . " - " . $ex->getMessage(), $ex);
                $this->controller->application->showError($ex->getMessage());
            }
            $class = get_class($this);
            while ($result->fetch()) {

                $objects[$result->fields[$primarykey]]
                    = new $class($this->controller);
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
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


            $sql    = "SHOW COLUMNS FROM `" . $this->getFullTableName() . "`";
            $result = $database->query($sql);

            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
            }

            $objects = \Pramnos\Html\Datatable\Datasource::getList(
                $this->getFullTableName(), $fields, false, $filter
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
            case "tinyint":
            case "integer":
            case "smallint":
            case "bigint":
                return "integer";
            case "double":
            case "float":
            case "real":
            case "double precision":
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
            if ($key == '_primaryKey' || $key == '_dbtable'
                || $key == 'modelname' || $key == 'prefix'
                || $key == '_cacheKey') {
                continue;
            }
            if (is_numeric($value) || is_string($value)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /**
     * Get the last changes made to the model
     * @return array Array of changed fields with their old and new values
     * @example array('field1' => array('old' => 'old_value', 'new' => 'new_value'))
     * @note This function returns the last changes made to the model after saving it to the database.
     *       It provides an array of fields that have changed, along with their old and new values.
     *       This is useful for tracking changes made to the model during the last save operation.
     *       It can be used to determine what fields were modified and their corresponding values before and after the save.
     *       Note that this function only returns the changes from the last save operation.
     *       If you want to get changes made since the model was loaded from the database,
     *       you should use the getChanges() function instead.
     */
    public function  getLastSaveChanges()
    {
        return $this->_lastChanges;
    }

    /**
     * Get changes between current state and initial data
     * @return array Array of changed fields with their old and new values
     * @example array('field1' => array('old' => 'old_value', 'new' => 'new_value'))
     * @note This function compares the current state of the model with the initial data loaded from the database.
     *       It returns an array of fields that have changed, along with their old and new values.
     *       If the model is new or has no initial data, it returns an empty array.
     *       This is useful for tracking changes made to the model after it has been loaded from the database.
     *       It can be used to determine what fields have been modified before saving the model back to the database.
     */
    public function getChanges()
    {
        $changes = array();
        
        // If this is a new model with no initial data, return empty array
        if ($this->_isnew || empty($this->_initialData)) {
            return $changes;
        }
        
        foreach ($this->_initialData as $field => $initialValue) {
            // Check if the field exists and has changed
            if (property_exists($this, $field) && $this->$field !== $initialValue) {
                $changes[$field] = array(
                    'old' => $initialValue,
                    'new' => $this->$field
                );
            }
        }
        
        return $changes;
    }

    /**
     * Get the fully qualified table name with schema if needed
     * @return string
     */
    protected function getFullTableName($tableName = null)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($tableName === null) {
            $tableName = $this->_dbtable;
        }
        
        // For PostgreSQL with schema defined, prepend the schema
        if ($database->type == 'postgresql' && $this->_dbschema !== null) {
            return str_replace(
                '#PREFIX#', $database->prefix, $this->_dbschema . '.' . $tableName
            );
        } elseif ($database->type == 'postgresql' && $database->schema != '') {
            return str_replace(
                '#PREFIX#', $database->prefix, $database->schema . '.' . $tableName
            );
        }
        
        return str_replace(
            '#PREFIX#', $database->prefix, $tableName
        );
    }


    /**
     * Get columns from a table
     * @param string $tableName Table name
     * @return \Pramnos\Database\Result
     */
    protected function getColumns($tableName)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($database->type == 'postgresql') {
            $sql = $database->prepareQuery(
                "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                . "(SELECT col_description((SELECT oid FROM pg_class WHERE relname = '" . $this->getFullTableName($tableName, false) . "'), a.ordinal_position)) AS \"Comment\", "
                . "column_name in ( "
                . "    SELECT column_name "
                . "    FROM information_schema.table_constraints tc "
                . "    JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name) "
                . "    WHERE constraint_type = 'PRIMARY KEY' "
                . "    AND tc.table_name = '" . $this->getFullTableName($tableName, false) . "'"
                . "    AND tc.table_schema = '" . ($this->schema ?? $database->schema) . "'"
                . ") as \"PrimaryKey\", "
                . "column_name in ( "
                . "    SELECT column_name "
                . "    FROM information_schema.key_column_usage "
                . "    WHERE table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "    AND table_schema = '" . ($this->schema ?? $database->schema) . "' "
                . "    AND column_name = a.column_name "
                . "    AND constraint_name in ( "
                . "        SELECT constraint_name "
                . "        FROM information_schema.table_constraints "
                . "        WHERE table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "        AND table_schema = '" . ($this->schema ?? $database->schema) . "' "
                . "        AND constraint_type = 'FOREIGN KEY' "
                . "    ) "
                . ") as \"ForeignKey\", "
                . "( "
                . "    SELECT kcu2.table_name "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name "
                . "    WHERE kcu.table_schema = '" . ($this->schema ?? $database->schema) . "' "
                . "    AND kcu.table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1 "
                . ") as \"ForeignTable\", "
                . "( "
                . "    SELECT kcu2.table_schema "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name "
                . "    WHERE kcu.table_schema = '" . ($this->schema ?? $database->schema) . "' "
                . "    AND kcu.table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1 "
                . ") as \"ForeignSchema\", "
                . "( "
                . "    SELECT kcu2.column_name "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name "
                . "    WHERE kcu.table_schema = '" . ($this->schema ?? $database->schema) . "' "
                . "    AND kcu.table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1 "
                . ") as \"ForeignColumn\" "
                . "FROM information_schema.columns a "
                . "WHERE table_name = '" . $this->getFullTableName($tableName, false) . "' "
                . "AND table_schema = '" . ($this->schema ?? $database->schema) . "'"
            );

        } else {
            // MySQL query
            $database_name = $database->database;
            $sql = $database->prepareQuery(
                "SELECT c.COLUMN_NAME as 'Field', c.DATA_TYPE as 'Type', c.CHARACTER_MAXIMUM_LENGTH, "
                . "c.IS_NULLABLE as 'Null', c.COLUMN_DEFAULT, c.COLUMN_COMMENT as 'Comment', "
                . "IF(k.COLUMN_NAME IS NOT NULL, 'PRI', '') as 'Key', "
                . "IF(fk.COLUMN_NAME IS NOT NULL, 1, 0) as 'ForeignKey', "
                . "fk.REFERENCED_TABLE_NAME as 'ForeignTable', "
                . "fk.REFERENCED_TABLE_SCHEMA as 'ForeignSchema', "
                . "fk.REFERENCED_COLUMN_NAME as 'ForeignColumn' "
                . "FROM INFORMATION_SCHEMA.COLUMNS c "
                . "LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k "
                . "ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME AND k.CONSTRAINT_NAME = 'PRIMARY' "
                . "LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE fk "
                . "ON c.TABLE_SCHEMA = fk.TABLE_SCHEMA AND c.TABLE_NAME = fk.TABLE_NAME AND c.COLUMN_NAME = fk.COLUMN_NAME AND fk.REFERENCED_TABLE_NAME IS NOT NULL "
                . "WHERE c.TABLE_NAME = '{$tableName}' AND c.TABLE_SCHEMA = '{$database_name}'"
            );
        }
        
        return $database->query($sql);
    }

}
