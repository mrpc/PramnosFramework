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
     * SQL error if any
     * @var string
     */
    protected $sqlError = null;

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
                    $sql = "SELECT column_name as \"Field\", "
                    . " CASE WHEN data_type = 'USER-DEFINED' THEN udt_name ELSE data_type END as \"Type\", "
                    . " is_nullable as \"Null\" "
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
            try {
                $result = $database->query($sql, true, 600, $this->_cacheKey);
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::logError(
                    'Error executing getCount query: '
                     . $sql
                     . ' - '
                     . $e->getMessage(),
                    $e
                );
                return 0;
            }
            
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
     * @param  string  $join   Join statement for database query
     * @param  string  $queryFields Fields to select in query. If $queryFields is NULL, all fields are selected
     * @param  string  $group  Group by statement for database query
     * @param  boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param  boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array  $addedfields If is set, these fields will not be filtered out
     * @return array           Three keys: total, pages, items
     */
    protected function _getPaginated($items=10, $page=1,
        $filter = NULL, $order = NULL, $table = NULL,
        $key = NULL, $debug=false,
        $join = '',
        $queryFields = NULL,
        $group = '', $returnAsModels = true, $useGetData = false,
        $customGetListMethod = false, $addedfields = array())
    {
        if (!is_array($addedfields)) {
            $addedfields = array();
        }  
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
            if ($filter === NULL) {
                $filter = "";
            } else {
                if ($database->type == 'postgresql') {
                    $filter = str_replace('`', '"', $filter);
                }
            }
            if ($database->type == 'postgresql') {
                $group = str_replace('`', '"', $group);
                $join = str_replace('`', '"', $join);
                $prefix = $database->prefix;
                if ($prefix != '') {
                    $prefix = $prefix . '_';
                }
                $join = str_replace('#PREFIX#', $database->prefix, $join);


            }
            if ($order === NULL || $order === '') {
                if ($join != '') {
                    $order  = " order by a." . $primarykey . " DESC ";
                } else {
                    $order  = " order by " . $primarykey . " DESC ";
                }
            }

            if ($queryFields != NULL) {
                $fields = $queryFields;
            } else {
                $fields = '*';
            }

            if ($database->type == 'postgresql') {
                $order = str_replace('`', '"', $order);
            }

            if (trim($order) != '' && stripos($order, 'order by') === false) {
                $order = ' order by ' . $order;
            }
            $orderArray = explode(';', $order);
            $order = $database->prepareQuery($orderArray[0]);
            if ($database->type == 'postgresql') {
                if ($this->_dbschema !== null) {
                    $sql = "select $fields from " . $this->_dbschema . '.'
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order . ' limit '
                        . $items . ' offset ' . $page;
                    if ($group != '') {
                        $countSql = "select count(*) as \"itemsCount\" from ("
                            . "select 1 from " . $this->_dbschema . '.'
                            . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group
                            . ") as grouped_query";
                    } else {
                        $countSql = "select count(a." . $primarykey . ") "
                            . "as \"itemsCount\"  from " . $this->_dbschema . '.'
                            . $this->_dbtable . " " . "  a " . $join . $filter;
                    }
                } elseif ($database->schema != '') {
                    $sql = "select $fields from " . $database->schema . '.'
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order . ' limit '
                        . $items . ' offset ' . $page;
                    if ($group != '') {
                        $countSql = "select count(*) as \"itemsCount\" from ("
                            . "select 1 from " . $database->schema . '.'
                            . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group
                            . ") as grouped_query";
                    } else {
                        $countSql = "select count(a." . $primarykey . ") "
                            . "as \"itemsCount\"  from " . $database->schema . '.'
                            . $this->_dbtable . " " . "  a " . $join . $filter;
                    }
                } else {
                    $sql = "select $fields from "
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order . ' limit '
                        . $items . ' offset ' . $page;
                    if ($group != '') {
                        $countSql = "select count(*) as \"itemsCount\" from ("
                            . "select 1 from "
                            . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group
                            . ") as grouped_query";
                    } else {
                        $countSql = "select count(a." . $primarykey . ") "
                            . "as \"itemsCount\"  from "
                            . $this->_dbtable . " " . "  a " . $join . $filter;
                    }
                }
            } else {
                $sql = "select $fields from `"
                    . $this->_dbtable . "` " . "  a " . $join . $filter . ' ' . $group . ' ' . $order . ' limit '
                    . $page . ', ' . $items;
                if ($group != '') {
                    $countSql = "select count(*) as 'itemsCount' from ("
                        . "select 1 from `"
                        . $this->_dbtable . "` " . "  a " . $join . $filter . ' ' . $group
                        . ") as grouped_query";
                } else {
                    $countSql = "select count(a.`" . $primarykey . "`) "
                        . "as 'itemsCount'  from `"
                        . $this->_dbtable . "` " . "  a " . $join . $filter;
                }
            }
            $countResult = $database->query(
                $countSql, true, 600, $this->_cacheKey
            );
            $totalItems = $countResult->fields['itemsCount'];

            if ($totalItems == 0 | $items == 0) {
                $totalPages = 1;
            } else {
                $totalPages = ceil($totalItems / $items);
            }

            if ($debug==true) {
                die($sql);
            }

            $result = $database->query($sql, true, 600, $this->_cacheKey);

            $class = get_class($this);
            
            if ($returnAsModels == false && $useGetData == false) {
                $objects = array();
                while ($result->fetch()) {
                    $objects[] = $result->fields;
                }
                return array(
                    'total'=>$totalItems,
                    'pages'=>$totalPages,
                    'items'=>$objects
                );
            }

            while ($result->fetch()) {
                $objects[$result->fields[$primarykey]] = new $class(
                    $this->controller
                );
                
                // Reset initial data array for this object
                $objects[$result->fields[$primarykey]]->_initialData = array();
                
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                    // Store initial value
                    $objects[$result->fields[$primarykey]]->_initialData[$field] = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                if ($useGetData == true) {
                    if ($customGetListMethod !== false) {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->{$customGetListMethod}();
                    } else {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->getData();
                    }    

                    // if queryfields is not null (or *), anything not in queryfields should not be returned
                    if ($queryFields !== NULL && $queryFields != '*' && $queryFields != '' 
                        && is_array($objects[$result->fields[$primarykey]])) {
                        $fieldsArray = explode(',', $queryFields);
                        $fieldsArray = array_map('trim', $fieldsArray);
                        // remove all quotes from fields
                        $fieldsArray = array_map(function($field) {
                            return trim($field, '"');
                        }, $fieldsArray);
                        foreach ($objects[$result->fields[$primarykey]] as $key => $value) {
                            if (!in_array($key, $fieldsArray) && !is_array($value) && !in_array($key, $addedfields)) {
                                unset($objects[$result->fields[$primarykey]][$key]);
                            }
                        }
                    }
                }
            }
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
     * @param string $table
     * @param string $key
     * @param boolean $debug Show debug information
     * @param string $join Join statement for database query
     * @param string $queryFields Fields to select in query. If $queryFields is NULL, all fields are selected
     * @param string $group Group by statement for database query
     * @param boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param boolean $displayerroroutput if true, display error output on database query failure
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array  $addedfields If is set, these fields will not be filtered out
     * @return array
     */
    public function _getList($filter = NULL, $order = NULL,
        $table = NULL, $key = NULL, $debug=false,
        $join = '',
        $queryFields = NULL,
        $group = '', $returnAsModels = true, $useGetData = false, $displayerroroutput = true,
        $customGetListMethod = false,
        $addedfields = false)
    {
        if ($table === NULL && $this->_dbtable === NULL) {
            $table = '#PREFIX#' . $this->prefix . '_' . $this->modelname;
        }
        if (!is_array($addedfields)) {
            $addedfields = array();
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
            if ($database->type == 'postgresql') {
                $group = str_replace('`', '"', $group);
                $join = str_replace('`', '"', $join);
                $prefix = $database->prefix;
                if ($prefix != '') {
                    $prefix = $prefix . '_';
                }
                $join = str_replace('#PREFIX#', $database->prefix, $join);
            }
            if ($order === NULL) {
                if ($join != '') {
                    $order  = " order by a." . $primarykey . " DESC ";
                } else {
                    $order  = " order by " . $primarykey . " DESC ";
                }
            }

            if ($queryFields != NULL) {
                $fields = $queryFields;
            } else {
                $fields = '*';
            }

            if ($database->type == 'postgresql') {
                $order = str_replace('`', '"', $order);
            }
            if (trim($order) != '' && stripos($order, 'order by') === false) {
                $order = ' order by ' . $order;
            }
            $orderArray = explode(';', $order);
            $order = $database->prepareQuery($orderArray[0]);
            

            if ($database->type == 'postgresql') {
                if ($this->_dbschema != null) {
                    $sql = "select $fields from " . $this->_dbschema . '.'
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order;
                } elseif ($database->schema != '') {
                    $sql = "select $fields from " . $database->schema . '.'
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order;
                } else {
                    $sql = "select $fields from "
                        . $this->_dbtable . " " . "  a " . $join . $filter . ' ' . $group . ' ' . $order;
                }
            } else {
                $sql = "select $fields from `"
                    . $this->_dbtable . "` " . "  a " . $join . $filter . ' ' . $group . ' ' . $order;
            }
            if ($debug==true) {
                die($sql);
            }
            try {
                $result = $database->query($sql, true, 600, $this->_cacheKey);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::logError("Error in getList query: " . $sql . " - " . $ex->getMessage(), $ex);
                if ($displayerroroutput == true) {
                    $this->controller->application->showError($ex->getMessage());
                } else {
                    $this->sqlError = $ex->getMessage();
                    return array();
                }
                
            }
            if ($returnAsModels == false && $useGetData == false) {
                $objects = array();
                while ($result->fetch()) {
                    $objects[] = $result->fields;
                }
                return $objects;
            }
            $class = get_class($this);
            while ($result->fetch()) {

                $objects[$result->fields[$primarykey]]
                    = new $class($this->controller);
                
                // Reset initial data array for this object
                $objects[$result->fields[$primarykey]]->_initialData = array();
                
                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                    // Store initial value
                    $objects[$result->fields[$primarykey]]->_initialData[$field] = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                if ($useGetData == true) {

                    if ($customGetListMethod !== false) {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->{$customGetListMethod}();
                    } else {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->getData();
                    }

                    // if queryfields is not null (or *), anything not in queryfields should not be returned
                    if ($queryFields !== NULL && $queryFields != '*' && $queryFields != '' 
                        && is_array($objects[$result->fields[$primarykey]])) {
                        $fieldsArray = explode(',', $queryFields);
                        $fieldsArray = array_map('trim', $fieldsArray);
                        // remove all quotes from fields
                        $fieldsArray = array_map(function($field) {
                            return trim($field, '"');
                        }, $fieldsArray);
                        foreach ($objects[$result->fields[$primarykey]] as $key => $value) {
                            if (!in_array($key, $fieldsArray) && !is_array($value) && !in_array($key, $addedfields)) {
                                unset($objects[$result->fields[$primarykey]][$key]);
                            }
                        }
                    }
                }
                
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
            case "geometry":
                return "geometry";
            case "boolean":
            case "bool":
                return "boolean";
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
                || $key == '_dbschema'
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
            if (property_exists($this, $field)) {
                $currentValue = $this->$field;
                
                // For numeric values, compare using loose comparison to handle type casting
                if (is_numeric($initialValue) && is_numeric($currentValue)) {
                    // Convert both to float for comparison to handle int/float differences
                    if ((float)$initialValue !== (float)$currentValue) {
                        $changes[$field] = array(
                            'old' => $initialValue,
                            'new' => $currentValue
                        );
                    }
                } else {
                    // For non-numeric values, use strict comparison
                    if ($currentValue !== $initialValue) {
                        $changes[$field] = array(
                            'old' => $initialValue,
                            'new' => $currentValue
                        );
                    }
                }
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
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array $fields Array of field names to include in response. If empty, includes all fields
     * @param string|array $search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string $order Order by clause (e.g., "field ASC" or "field DESC")
     * @param string $filter Additional WHERE clause filter
     * @param string $join JOIN clause for complex queries
     * @param string $group GROUP BY clause
     * @param string $table Database table
     * @param string $key Database primary key
     * @param int $page Current page number (1-based, 0 = no pagination)
     * @param int $itemsPerPage Number of items per page (ignored if $page = 0)
     * @param bool $debug Show debug information
     * @param boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array $addedfields If is set, these fields will not be filtered out
     * @return array API response with pagination info and data
     */
    public function _getApiList($fields = array(), $search = '', 
        $order = '', $filter = '', $join = '', $group = '', 
        $table = null, $key = null,
        $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false, $useGetData = false,
        $customGetListMethod = false, $addedfields = false)
    {
        // Handle unified search parameter
        $globalSearch = '';
        $fieldSearches = array();
        
        if (is_string($search)) {
            // Check if the string is a JSON object with field-specific searches
            $decodedSearch = json_decode(urldecode($search), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSearch)) {
                $fieldSearches = $decodedSearch;
            } else {
                $globalSearch = $search;
            }
        } elseif (is_array($search)) {
            $fieldSearches = $search;
        }

        if (is_string($fields) && trim($fields) != '') {
            // check if it's a json array
            $decodedFields = json_decode(urldecode($fields), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedFields)) {
                $fields = $decodedFields;
            } else {
                // If not JSON, assume it's a comma-separated string
                $fields = array_map('trim', explode(',', $fields));
            }
        } 
        
        $availableFields = $this->_getAllTableFields($join);
        // Get all available fields if none specified
        if (empty($fields)) {
            $fields = $availableFields;
        }
        
        // Validate and sanitize fields
        
        $validFields = array();
        foreach ($fields as $field) {
            $field = trim($field);
            if (!empty($field) && in_array($field, $availableFields)) {
                $validFields[] = $field;
            }
        }
        
        if (empty($validFields)) {
            $validFields = $availableFields;

            


        }

        $returnedFields = array();

        // remove table prefixes from validFields
        foreach ($validFields as $field) {
            if (strpos($field, '.') !== false) {
                $returnedFields[substr($field, strpos($field, '.') + 1)] = substr($field, strpos($field, '.') + 1);
            } else {
                $returnedFields[$field] = $field;
            }
        }
        $returnedFields = array_values($returnedFields);
        
        // Always ensure primary key is included
        if ($key !== null && $key != "") {
            $primaryKey = $key;
        } else {
            $primaryKey = $this->_primaryKey;
        }
        
        if (!in_array($primaryKey, $validFields)) {
            array_unshift($validFields, $primaryKey);
        }
        
        // Build field selection for query
        $selectFields = $this->_buildSelectFields($validFields, $join);
        
        // Build search conditions
        $searchConditions = $this->_buildSearchConditions($validFields, $globalSearch, $fieldSearches, $join);
        
        // Validate and build order clause
        $validatedOrder = $this->_validateAndBuildOrder($order, $validFields, $join);
        
        // Combine filter and search conditions
        $finalFilter = ' ' . $this->_combineFilters($filter, $searchConditions);
        
        // Check if pagination is requested
        if ($page > 0) {

            try {
                $result = $this->_getPaginated(
                    $itemsPerPage, $page, $finalFilter, $validatedOrder, $table, $key, $debug,
                    $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
                );
            } catch (\Exception $ex) {
                return array(
                    'error' => 'Database query failed: ' . $ex->getMessage(),
                    'data' => array(),
                    'pagination' => null,
                    'fields' => $returnedFields,
                    'debug' => array(
                        'filter' => $finalFilter,
                        'order' => $validatedOrder,
                        'selectFields' => $selectFields
                    )
                );
            }

            // Get paginated results
            
            
            // Format response for API with pagination

            
            if (isset($result['items']) && is_array($result['items'])) {
                $result['items'] = array_values($result['items']);
            }
            


            return array(
                'data' => $result['items'],
                'pagination' => array(
                    'currentpage' => $page,
                    'itemsperpage' => $itemsPerPage,
                    'totalitems' => $result['total'],
                    'totalpages' => $result['pages'],
                    'hasnext' => $page < $result['pages'],
                    'hasprevious' => $page > 1
                ),
                'fields' => $returnedFields,
                'debug' => array(
                    'filter' => $finalFilter,
                    'order' => $validatedOrder,
                    'selectFields' => $selectFields
                )
            );
        } else {
            // Get all results without pagination
            
            $result = $this->_getList(
                $finalFilter, $validatedOrder, $table, $key, $debug,
                $join, $selectFields, $group, $returnAsModels, $useGetData, false,
                $customGetListMethod, $addedfields
            );
            if (empty($result) && $this->sqlError) {
                return array(
                    'error' => $this->sqlError,
                    'data' => array(),
                    'pagination' => null,
                    'fields' => $returnedFields,
                    'debug' => array(
                        'filter' => $finalFilter,
                        'order' => $validatedOrder,
                        'selectFields' => $selectFields
                    )
                );
            }
            
            if (isset($result) && is_array($result)) {
                $result = array_values($result);
            }
            
            // Format response for API without pagination
            return array(
                'data' => $result,
                'pagination' => null,
                'fields' => $returnedFields,
                'debug' => array(
                    'filter' => $finalFilter,
                    'order' => $validatedOrder,
                    'selectFields' => $selectFields
                )
            );
        }
    }
    
    /**
     * Get all table fields for the current model
     * @param string $join Optional JOIN clause to include fields from joined tables
     * @return array Array of field names (includes table.field format for joined tables)
     */
    private function _getAllTableFields($join = '')
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        
        // Get main table fields
        if (isset(self::$columnCache[$this->getFullTableName()])) {
            foreach (self::$columnCache[$this->getFullTableName()] as $fieldInfo) {
                $fields[] = $fieldInfo['Field'];
            }
        } else {
            if ($database->type == 'postgresql') {
                if ($this->_dbschema != null) {
                    $schema = $this->_dbschema;
                } else {
                    $schema = $database->schema;
                }
                $sql = "SELECT column_name as \"Field\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '"
                    . $schema
                    . "' AND table_name = '"
                    . str_replace('#PREFIX#', $database->prefix, $this->_dbtable)
                    . "';";
            } else {
                $sql = "SHOW COLUMNS FROM `" . $this->getFullTableName() . "`";
            }
            
            $result = $database->query($sql);
            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
                // Cache the results
                if (!isset(self::$columnCache[$this->getFullTableName()])) {
                    self::$columnCache[$this->getFullTableName()] = array();
                }
                self::$columnCache[$this->getFullTableName()][] = $result->fields;
            }
        }
        
        // If join is provided, extract and get fields from joined tables
        if (!empty(trim($join))) {
            $joinedFields = $this->_getJoinedTableFields($join);
            $fields = array_merge($fields, $joinedFields);
        }
        
        return $fields;
    }
    
    /**
     * Extract table names from JOIN clause and get their fields
     * @param string $join JOIN clause
     * @return array Array of field names without table prefixes (e.g., 'field_name')
     */
    private function _getJoinedTableFields($join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        
        // Parse JOIN clause to extract table names and aliases
        // Support various JOIN types: INNER JOIN, LEFT JOIN, RIGHT JOIN, etc.
        $joinPattern = '/(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|JOIN)\s+([`"\w.#]+)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)/i';
        
        preg_match_all($joinPattern, $join, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $tableName = trim($match[1], '`"');
            $tableAlias = $match[2];
            
            // Replace prefixes
            $fullTableName = str_replace('#PREFIX#', $database->prefix, $tableName);
            
            // Get fields for this joined table
            $tableFields = $this->_getTableFields($fullTableName);
            
            // Add fields without table alias prefix
            foreach ($tableFields as $field) {
                $fields[] = $tableAlias . '.' . $field;
            }
        }
        
        return $fields;
    }
    
    /**
     * Get fields for a specific table
     * @param string $tableName Full table name
     * @return array Array of field names
     */
    private function _getTableFields($tableName)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        
        // Check cache first
        $cacheKey = $tableName;
        if (isset(self::$columnCache[$cacheKey])) {
            foreach (self::$columnCache[$cacheKey] as $fieldInfo) {
                $fields[] = $fieldInfo['Field'];
            }
            return $fields;
        }
        
        try {
            if ($database->type == 'postgresql') {
                // For PostgreSQL, we need to handle schema
                $parts = explode('.', $tableName);
                if (count($parts) === 2) {
                    $schema = $parts[0];
                    $table = $parts[1];
                } else {
                    $schema = $this->_dbschema ?: $database->schema;
                    $table = $tableName;
                }
                
                $sql = "SELECT column_name as \"Field\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '" . $schema . "'"
                    . " AND table_name = '" . $table . "';";
            } else {
                $sql = "SHOW COLUMNS FROM `" . $tableName . "`";
            }
            
            $result = $database->query($sql);
            
            // Initialize cache for this table
            self::$columnCache[$cacheKey] = array();
            
            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
                self::$columnCache[$cacheKey][] = $result->fields;
            }
        } catch (\Exception $e) {
            // If we can't get fields for joined table, log error and continue
            \Pramnos\Logs\Logger::logError(
                'Could not get fields for joined table: ' . $tableName . ' - ' . $e->getMessage(),
                $e
            );
        }
        
        return $fields;
    }
    
    /**
     * Build SELECT fields clause with proper table aliases
     * @param array $fields Array of field names
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string Comma-separated field list for SELECT
     */
    private function _buildSelectFields($fields, $join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $selectFields = array();
        $hasJoin = !empty(trim($join));
        $fieldNames = array(); // Track field names to detect duplicates
        
        foreach ($fields as $field) {
            $originalField = $field;
            $fieldAlias = '';
            
            // Check if field already has an AS clause
            if (stripos($field, ' as ') !== false) {
                $selectFields[] = $field;
                continue;
            }
            
            if (strpos($field, '.') === false && $hasJoin) {
                // Add table alias for fields without explicit table reference when using joins
                if ($database->type == 'postgresql') {
                    $selectFields[] = 'a."' . $field . '"';
                } else {
                    $selectFields[] = 'a.`' . $field . '`';
                }
                $fieldNames[] = $field;
            } elseif (strpos($field, '.') === false) {
                // No join, no alias needed
                if ($database->type == 'postgresql') {
                    $selectFields[] = '"' . $field . '"';
                } else {
                    $selectFields[] = '`' . $field . '`';
                }
                $fieldNames[] = $field;
            } else {
                // Field already has table reference (e.g., a.status, b.status)
                $parts = explode('.', $field);
                if (count($parts) == 2) {
                    $tableAlias = $parts[0];
                    $fieldName = $parts[1];
                    
                    // Check if this field name already exists
                    if (in_array($fieldName, $fieldNames)) {
                        // Create alias to avoid duplicate field names
                        $alias = $tableAlias . '_' . $fieldName;
                        if ($database->type == 'postgresql') {
                            $selectFields[] = $field . ' AS "' . $alias . '"';
                        } else {
                            $selectFields[] = $field . ' AS `' . $alias . '`';
                        }
                    } else {
                        $selectFields[] = $field;
                        $fieldNames[] = $fieldName;
                    }
                } else {
                    $selectFields[] = $field;
                }
            }
        }

        return implode(', ', $selectFields);
    }
    
    /**
     * Build search conditions for WHERE clause
     * @param array $fields Available fields for searching
     * @param string $globalSearch Global search term
     * @param array $fieldSearches Field-specific searches
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string WHERE conditions for search
     */
    private function _buildSearchConditions($fields, $globalSearch, $fieldSearches, $join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $conditions = array();
        $hasJoin = !empty(trim($join));
        
        // Create a mapping of field names without table prefixes to their full references
        $fieldMapping = array();
        foreach ($fields as $field) {
            if (strpos($field, '.') !== false) {
                // Extract field name after the dot
                $fieldName = substr($field, strrpos($field, '.') + 1);
                $fieldMapping[$fieldName] = $field;
            } else {
                $fieldMapping[$field] = $field;
            }
        }
        
        // Global search across all fields
        if (!empty($globalSearch) && trim($globalSearch) != '') {
            $globalConditions = array();
            foreach ($fields as $field) {
                $fieldRef = $field;
                if (strpos($field, '.') === false && $hasJoin) {
                    $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $field . '"' : '`' . $field . '`');
                } elseif (strpos($field, '.') === false) {
                    $fieldRef = ($database->type == 'postgresql' ? '"' . $field . '"' : '`' . $field . '`');
                }

                if (strpos($globalSearch, '%') !== false) {
                    // If search term already contains wildcards, use it directly
                    $globalSearch = $database->prepareInput($globalSearch);
                } else {
                    // Otherwise, prepare it for LIKE search
                    $globalSearch = $database->prepareInput('%' . $globalSearch . '%');
                }
                if (is_string($globalSearch)) {
                    // Split search term into words
                    $words = preg_split('/\s+/', $globalSearch, -1, PREG_SPLIT_NO_EMPTY);
                    $processedWords = array();
                    foreach ($words as $word) {
                        // Detect if word has % at start/end
                        $prefix = (strpos($word, '%') === 0) ? '%' : '';
                        $suffix = (strrpos($word, '%') === strlen($word) - 1) ? '%' : '';
                        // Remove % for processing
                        $cleanWord = trim($word, '%');
                        $lastChar = mb_substr($cleanWord, -1, 1, 'UTF-8');
                        if ($lastChar === 'ς' || $lastChar === 'σ') {
                            $cleanWord = mb_substr($cleanWord, 0, mb_strlen($cleanWord, 'UTF-8') - 1, 'UTF-8');
                        }
                        // Re-add % only where they were
                        $processedWords[] = $prefix . $cleanWord . $suffix;
                    }
                    $globalSearch = implode(' ', $processedWords);
                }
                
                if ($database->type == 'postgresql') {
                    if (\Pramnos\General\StringHelper::containsGreekCharacters($globalSearch)) {
                        $globalConditions[] = 'unaccent(CAST(' . $fieldRef . ' AS TEXT)) ILIKE unaccent(\'' . $database->prepareInput($globalSearch) . '\')';
                    } else {
                        $globalConditions[] = 'CAST(' . $fieldRef . ' AS TEXT) ILIKE \'' . $database->prepareInput($globalSearch) . '\'';
                    }
                } else {
                    $globalConditions[] = $fieldRef . ' LIKE \'' . $database->prepareInput($globalSearch) . '\'';
                }
            }
            if (!empty($globalConditions)) {
                $conditions[] = '(' . implode(' OR ', $globalConditions) . ')';
            }
        }
        
        // Field-specific searches
        foreach ($fieldSearches as $searchField => $searchTerm) {
            if (is_string($searchTerm) && trim($searchTerm) == '') {
                continue;
            }
            
            
            $targetField = null;
            
            // First try exact match
            if (in_array($searchField, $fields)) {
                $targetField = $searchField;
            } else {
                // Try to find field by name without table prefix
                if (isset($fieldMapping[$searchField])) {
                    $targetField = $fieldMapping[$searchField];
                }
            }
            
            if ($targetField === null) {
                continue; // Skip fields that don't exist
            }
            
            $fieldRef = $targetField;
            if (strpos($targetField, '.') === false && $hasJoin) {
                $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
            } elseif (strpos($targetField, '.') === false) {
                $fieldRef = ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
            }
            $bool = false;
            $boolValue = 0;
            if (is_bool($searchTerm) || $searchTerm == 'true' || $searchTerm == 'false') {
                $bool = true;
                $boolValue = ($searchTerm === true || $searchTerm === 'true') ? 1 : 0;
            } else {
                if (strpos($searchTerm, '%') !== false) {
                    // If search term already contains wildcards, use it directly
                    $searchTerm = $database->prepareInput($searchTerm);
                } else {
                    // Otherwise, prepare it for LIKE search
                    $searchTerm = $database->prepareInput('%' . $searchTerm . '%');
                }
                if (is_string($searchTerm)) {
                    // Split search term into words
                    $words = preg_split('/\s+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
                    $processedWords = array();
                    foreach ($words as $word) {
                        // Detect if word has % at start/end
                        $prefix = (strpos($word, '%') === 0) ? '%' : '';
                        $suffix = (strrpos($word, '%') === strlen($word) - 1) ? '%' : '';
                        // Remove % for processing
                        $cleanWord = trim($word, '%');
                        $lastChar = mb_substr($cleanWord, -1, 1, 'UTF-8');
                        if ($lastChar === 'ς' || $lastChar === 'σ') {
                            $cleanWord = mb_substr($cleanWord, 0, mb_strlen($cleanWord, 'UTF-8') - 1, 'UTF-8');
                        }
                        // Re-add % only where they were
                        $processedWords[] = $prefix . $cleanWord . $suffix;
                    }
                    $searchTerm = implode(' ', $processedWords);
                }
            }
            
            

            if ($database->type == 'postgresql') {
                if ($bool) {
                    $conditions[] = $fieldRef . ' = '. $boolValue;
                } elseif (\Pramnos\General\StringHelper::containsGreekCharacters($searchTerm)) {
                    $conditions[] = 'unaccent(CAST(' . $fieldRef . ' AS TEXT)) ILIKE unaccent(\'' . $database->prepareInput($searchTerm) . '\')';
                } else {
                    $conditions[] = 'CAST(' . $fieldRef . ' AS TEXT) ILIKE \'' . $database->prepareInput($searchTerm) . '\'';
                }
            } else {
                $conditions[] = $fieldRef . ' LIKE \'' . $database->prepareInput($searchTerm) . '\'';
            }
        }
        
        return implode(' AND ', $conditions);
    }
    
    /**
     * Validate and build ORDER BY clause with field validation and ASC/DESC handling
     * @param string $order Order specification (e.g., "field1,-field2,+field3")
     * @param array $availableFields Array of valid field names
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string Validated ORDER BY clause
     */
    private function _validateAndBuildOrder($order, $availableFields, $join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $orderParts = array();
        $hasJoin = !empty(trim($join));
        
        // Create a mapping of field names without table prefixes to their full references
        $fieldMapping = array();
        foreach ($availableFields as $field) {
            if (strpos($field, '.') !== false) {
                // Extract field name after the dot
                $fieldName = substr($field, strrpos($field, '.') + 1);
                $fieldMapping[$fieldName] = $field;
            } else {
                $fieldMapping[$field] = $field;
            }
        }
        
        if (empty(trim($order))) {
            // Default order by primary key DESC
            $primaryKey = $this->_primaryKey;
            if ($hasJoin) {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY a."' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY a.`' . $primaryKey . '` DESC';
                }
            } else {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY "' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY `' . $primaryKey . '` DESC';
                }
            }
        }
        
        // Split by comma and process each field
        $fields = array_map('trim', explode(',', $order));
        
        foreach ($fields as $field) {
            $field = trim($field);
            if (empty($field)) {
                continue;
            }
            
            $direction = 'ASC';
            $fieldName = $field;
            
            // Check for +/- prefix
            if (substr($field, 0, 1) === '+') {
                $direction = 'ASC';
                $fieldName = substr($field, 1);
            } elseif (substr($field, 0, 1) === '-') {
                $direction = 'DESC';
                $fieldName = substr($field, 1);
            } else {
                // Check for explicit ASC/DESC suffix
                $parts = preg_split('/\s+/', $field);
                if (count($parts) >= 2) {
                    $fieldName = $parts[0];
                    $lastPart = strtoupper(end($parts));
                    if ($lastPart === 'ASC' || $lastPart === 'DESC') {
                        $direction = $lastPart;
                    }
                }
            }
            
            $fieldName = trim($fieldName);
            
            // Sanitize field name - only allow alphanumeric, underscore, and dot
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $fieldName)) {
                continue; // Skip invalid field names
            }
            
            // Use field mapping to validate and resolve field names
            $targetField = null;
            
            // First try exact match in available fields
            if (in_array($fieldName, $availableFields)) {
                $targetField = $fieldName;
            } else {
                // Try to find field by name without table prefix using field mapping
                if (isset($fieldMapping[$fieldName])) {
                    $targetField = $fieldMapping[$fieldName];
                }
            }
            
            if ($targetField !== null) {
                $fieldRef = $targetField;
                if (strpos($targetField, '.') === false && $hasJoin) {
                    // Field from main table, add table alias
                    $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
                } elseif (strpos($targetField, '.') === false) {
                    // Field from main table, no join
                    $fieldRef = ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
                } else {
                    // Field already has table reference (joined table), validate and quote properly
                    $parts = explode('.', $targetField);
                    if (count($parts) === 2) {
                        $tableAlias = $parts[0];
                        $field = $parts[1];
                        
                        if ($database->type == 'postgresql') {
                            $fieldRef = $tableAlias . '."' . $field . '"';
                        } else {
                            $fieldRef = $tableAlias . '.`' . $field . '`';
                        }
                    }
                }
                
                $orderParts[] = $fieldRef . ' ' . $direction;
            }
        }
        
        if (empty($orderParts)) {
            // If no valid fields found, use default primary key order
            $primaryKey = $this->_primaryKey;
            if ($hasJoin) {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY a."' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY a.`' . $primaryKey . '` DESC';
                }
            } else {
                if ($database->type == 'postgresql') {
                    return 'ORDER BY "' . $primaryKey . '" DESC';
                } else {
                    return 'ORDER BY `' . $primaryKey . '` DESC';
                }
            }
        }
        
        return 'ORDER BY ' . implode(', ', $orderParts);
    }
    
    

    /**
     * Combine base filter with search conditions
     * @param string $baseFilter Base WHERE filter
     * @param string $searchConditions Search conditions
     * @return string Combined filter
     */
    private function _combineFilters($baseFilter, $searchConditions)
    {
        $baseFilter = trim($baseFilter);
        $searchConditions = trim($searchConditions);
        
        // Remove 'where' keyword if present
        if (stripos($baseFilter, 'where') === 0) {
            $baseFilter = trim(substr($baseFilter, 5));
        }
        
        if (empty($baseFilter) && empty($searchConditions)) {
            return '';
        } elseif (empty($baseFilter)) {
            return 'where ' . $searchConditions;
        } elseif (empty($searchConditions)) {
            return 'where ' . $baseFilter;
        } else {
            return 'where ' . $baseFilter . ' AND ' . $searchConditions;
        }
    }

}
