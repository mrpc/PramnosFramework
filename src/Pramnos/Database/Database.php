<?php

namespace Pramnos\Database;

/**
 * This class contains all database classes.
 * Inspired by many popular frameworks.
 * @static
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   (C) 2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 */
class Database extends \Pramnos\Framework\Base
{

    public $server = "localhost";
    public $user = "root";
    public $password = "";
    public $database = "";
    public $persistency = false;
    public $new_link = true;
    public $db_connected = false;
    public $db_collation = false;
    public $query_result;
    public $row = array();
    public $rowset = array();
    public $total_query_time = 0;
    public $num_queries = 0;
    public $in_transaction = 0;
    public $sql = "";
    public $prefix = "";
    public $modulePrefix = "";
    public $driver = 'mysql';

    private $_queryLogHandler = NULL;
    private $_duplicateQueryLogHandler = NULL;
    private $_slowQueryLogHandler = NULL;
    private $_duplicateQueries = NULL;
    private $_duplicateQueriesCounter = 0;
    public $long_query_time=1;
    private $_logSlowQueries=false;
    private $_customLogSlowQueries=false;
    private $_querieslog='';
    private $_slowquerieslog='';
    private $_numSlowqueries = 0;

    /**
     * Database connection link
     * @var resource
     */
    private $_dbConnection;

    /**
     * Instead of connecting, add an external mysql link
     * @param resource $link
     * @return \Database
     */
    public function addExternalConnection($link)
    {
        $this->_dbConnection = $link;
        $this->db_connected = true;
        return $this;
    }

    //Factory method to return new database objects
    public static function &getInstance($name = 'default')
    {
        static $instance = array();
        if (!isset($instance[$name])) {
            $instance[$name] = new Database;
        }
        return $instance[$name];
    }

    private function _createLogFile($filename)
    {
        try {
            touch($filename);
            chmod($filename, 0666);
        } catch (Exception $ex) {
            \Pramnos\Logs\Logs::log($ex->getMessage());
        }
    }

    private function _renameLogFile($filename)
    {
        $secondFileName = "$filename.old";
        if (file_exists($secondFileName)) {
            try {
                @unlink($secondFileName);
            } catch (Exception $ex) {
                \Pramnos\Logs\Logs::log($ex->getMessage());
            }
        }
        try {
            $rename = @rename($filename, $secondFileName);
        } catch (Exception $ex) {
            \Pramnos\Logs\Logs::log($ex->getMessage());
            $rename = false;
        }
        if ($rename !== false) {
            try {
                touch($filename);
                chmod($filename, 0666);
            } catch (Exception $ex) {
                \Pramnos\Logs\Logs::log($ex->getMessage());
            }
        }
    }

    /**
     * If a log file is larger than 2 MB, remove it
     * @param string $filename
     */
    private function rotateLog($filename)
    {
        if (!file_exists($filename)) {
            $this->_createLogFile($filename);
        }
        try {
            if (is_readable($filename)) {
                $filesize = filesize($filename);
            }
        } catch (Exception $ex) {
            \Pramnos\Logs\Logs::log($ex->getMessage());
            $filesize = 0;
        }
        if (isset($filesize) && $filesize > ((1024*1024)/2)) {
            $this->_renameLogFile($filename);
        }
    }

    /**
     * Start query logging
     */
    private function startLogs()
    {
        $queryLogHandler = LOG_PATH . DS . 'logs' . DS
             . 'databaseQueries.log';
        $dplQueryLogHandler = LOG_PATH . DS . 'logs'
            . DS . 'duplicateQueries.log';
        $this->rotateLog($queryLogHandler);
        $this->rotateLog($dplQueryLogHandler);
        $this->_queryLogHandler = fopen($queryLogHandler, 'a+');
        $this->_duplicateQueries = array();
        $this->_duplicateQueryLogHandler = fopen($dplQueryLogHandler, 'a+');
    }

    /**
     * Connect function - Connects to the database using all
     * database data
     */
    function connect()
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT == true) {
            $this->startLogs();
        }
        try {
            if ($this->persistency) {
                $this->_dbConnection = mysqli_connect(
                    'p:' . $this->server, $this->user, $this->password,
                    $this->database
                );
            } else {
                $this->_dbConnection = mysqli_connect(
                    $this->server, $this->user, $this->password, $this->database
                );
            }
        } catch (Exception $ex) {
            return false;
        }

        if ($this->_dbConnection) {
            $this->db_connected = true;

            /**
             * If db_collation is set, change connection collation
             * to get the data in the right format.
             */
            if ($this->db_collation <> false) {
                mysqli_query(
                    $this->_dbConnection,
                    "SET NAMES "
                    . $this->db_collation . ";"
                );
            }
            if ((defined('DEVELOPMENT') && DEVELOPMENT == true)
                    || (defined('LOG_SLOW_QUERIES')
                        && LOG_SLOW_QUERIES == true) ) {
                $this->logSlowQueries();


            }

            return true;
        } else {
            return false;
        }
    }


    /**
     * Start logging slow queries
     * @param   int $time   Time in seconds for slow query logs
     * @param   int $mode   0: PHP mode 1: Native MySQL mode
     */
    public function logSlowQueries($time=0, $mode=0)
    {
        if ((int)$time != 0) {
            $this->long_query_time = (int)$time;
        }
        $lngPthQueryOriginal=LOG_PATH . DS . 'logs' . DS . 'slowQueries.log';
        $this->rotateLog($lngPthQueryOriginal);
        $longpathquery = str_replace('\\', '/', $lngPthQueryOriginal);
        $this->_logSlowQueries=true;
        if ($mode === 1) {
            mysqli_query(
                $this->_dbConnection,
                "set global slow_query_log_file = '"
                . $longpathquery
                . "';"
            );
            mysqli_query(
                $this->_dbConnection,
                "SET GLOBAL long_query_time = "
                . $this->long_query_time
                . ";"
            );
            mysqli_query(
                $this->_dbConnection,
                "SET GLOBAL LOG_SLOW_QUERIES = ON;"
            );
            mysqli_query(
                $this->_dbConnection,
                "SET GLOBAL SLOW_QUERY_LOG = ON;"
            );

            $resultOne = $this->Execute('select @@global.long_query_time');
            $resultTwo = $this->Execute('select @@global.LOG_SLOW_QUERIES');
            $resultThree = $this->Execute('select @@global.SLOW_QUERY_LOG');
            $resultFour = $this->Execute('select @@global.slow_query_log_file');

            $longQueryTime
                =(int)$resultOne->fields['@@global.long_query_time'];
            $LOG_SLOW_QUERIES=$resultTwo->fields['@@global.LOG_SLOW_QUERIES'];
            $SLOW_QUERY_LOG=$resultThree->fields['@@global.SLOW_QUERY_LOG'];
            $slow_query_log_file
                =$resultFour->fields['@@global.slow_query_log_file'];
            if ($longQueryTime != $this->long_query_time
                    || $LOG_SLOW_QUERIES != 1
                    || $SLOW_QUERY_LOG != 1
                    || $slow_query_log_file != $longpathquery) {
                mysqli_query(
                    $this->_dbConnection,
                    "SET GLOBAL LOG_SLOW_QUERIES = OFF;"
                );
                mysqli_query(
                    $this->_dbConnection,
                    "SET GLOBAL SLOW_QUERY_LOG = OFF;"
                );
                $this->_customLogSlowQueries=true;
                $this->_slowQueryLogHandler = fopen(
                    $lngPthQueryOriginal, 'a+'
                );
            }
        } else {
            $this->_customLogSlowQueries=true;
            $this->_slowQueryLogHandler = fopen($lngPthQueryOriginal, 'a+');
        }


    }

    /**
     * Close the database connection
     * @return boolean
     */
    function sql_close()
    {
        if ($this->_dbConnection) {
        //
        // Commit any remaining transactions
        //
            if ($this->in_transaction) {
                mysqli_query($this->_dbConnection, "COMMIT");
            }
            return mysqli_close($this->_dbConnection);
        } else {
            return false;
        }
    }

    /**
     * Basic query method
     * @param string $query sql query
     * @param boolean $cache Do you want to use the cache?
     * @param int $cachetime Time of result cache
     * @return array
     */
    function sql_query($query = "")
    {
        if (pramnos_settings::baseget("debuglevel") > 1) {
            echo "$query <br />";
        }
        unset($this->query_result);
        if ($query != "") {
            $this->num_queries++;
            $time = -microtime(true);
            $this->query_result = mysqli_query($this->_dbConnection, $query);
            $time += microtime(true);
            if ($this->_customLogSlowQueries == true
                    && $this->_slowQueryLogHandler !== NULL
                    && $time > $this->long_query_time) {
                $this->_slowquerieslog .= "\n\n"
                    . date('H:i:s') . ": "
                    . $query . "\nTime: "
                    . number_format($time, 4)
                    . ' > '.$this->long_query_time ;
                $this->_numSlowqueries+=1;
            }
            if ($this->_queryLogHandler !== NULL) {
                $this->_querieslog .= "\n\n"
                    . $this->num_queries . '.: '
                    . date('H:i:s') . ": "
                    . $query . "\nTime: " . number_format($time, 4);
                if (isset($this->_duplicateQueries[$query])
                    && $this->_duplicateQueryLogHandler !== NULL
                    && is_resource($this->_duplicateQueryLogHandler)) {
                    $this->duplicateQueryHeader();
                    $this->_duplicateQueriesCounter++;
                    fwrite($this->_duplicateQueryLogHandler, "\n" . $query);
                }
                $this->_duplicateQueries[$query] = true;
            }
        }

        if ($this->query_result) {

            if (count($this->row) > 0
                && $this->query_result !== true
                && isset($this->row[$this->query_result])) {
                unset($this->row[$this->query_result]);
            }
            if (count($this->rowset) > 0
                && $this->query_result !== true
                && isset($this->rowset[$this->query_result])) {
                unset($this->rowset[$this->query_result]);
            }

            return $this->query_result;
        } else {
            return false;
        }
    }

    /**
     * Append a header on duplicate query log
     */
    private function duplicateQueryHeader()
    {
        if ($this->_duplicateQueriesCounter == 0) {
            $request = new \Pramnos\Http\Request();
            fwrite(
                $this->_duplicateQueryLogHandler,
                "\n\n"
                . "==================================="
                . "==================================\n"
                . date('d/m/Y') . ' :: ' . $request->getURL(false)."\n"
                . "==================================="
                . "=================================="
            );
        }
    }

    /**
     * Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
     * Copied from Wordpress
     *
     * The following directives can be used in the query format string:
     *   %d (decimal number)
     *   %s (string)
     *   %% (literal percentage sign - no argument needed)
     *
     * Both %d and %s are to be left unquoted in the query
     * string and they need an argument passed for them.
     * Literals (%) as parts of the query must be properly written as %%.
     *
     * This function only supports a small subset of the
     * sprintf syntax; it only supports %d (decimal number), %s (string).
     * Does not support sign, padding, alignment,
     * width or precision specifiers.
     * Does not support argument numbering/swapping.
     *
     * May be called like {@link http://php.net/sprintf sprintf()}
     * or like {@link http://php.net/vsprintf vsprintf()}.
     *
     * Both %d and %s should be left unquoted in the query string.
     *
     * <code>
     * pramnos_database::prepare( "SELECT * FROM `table`
     * WHERE `column` = %s AND `field` = %d", 'foo', 1337 )
     * pramnos_database::prepare( "SELECT DATE_FORMAT(`field`, '%%c')
     * FROM `table` WHERE `column` = %s", 'foo' );
     * </code>
     *
     * @link http://php.net/sprintf Description of syntax.
     *
     * @param string $query Query statement with sprintf()-like placeholders
     * @param array|mixed $args The array of variables to substitute
     * into the query's placeholders if being called like
     * 	{@link http://php.net/vsprintf vsprintf()}, or the first variable
     * to substitute into the query's placeholders if
     * 	being called like {@link http://php.net/sprintf sprintf()}.
     * @param mixed $args,... further variables to substitute into the
     * query's placeholders if being called like
     * 	{@link http://php.net/sprintf sprintf()}.
     * @return null|false|string Sanitized query string, null if there is
     * no query, false if there is an error and string
     * 	if there was something to prepare
     */
    function prepare($query = null)
    { // ( $query, *$args )
        if (is_null($query))
            return;

        $query = str_replace("#PREFIX#", $this->prefix, $query);
        $query = str_replace("#MP#", $this->modulePrefix, $query);

        $args = func_get_args();
        array_shift($args);
    // If args were passed as an array (as in vsprintf), move them up
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        // in case someone mistakenly already singlequoted it
        $query = str_replace("'%s'", '%s', $query);
        // doublequote unquoting
        $query = str_replace('"%s"', '%s', $query);
        // quote the strings, avoiding escaped strings like %%s
        $query = preg_replace('|(?<!%)%s|', "'%s'", $query);
        // START replace NULLs
        preg_match_all(
            '|\'?%(?:(\d+)\$)?[dfs]\'?|', $query, $positions,
            PREG_OFFSET_CAPTURE
        );
        if (!empty($positions)) {
            $values = array_values($args);
            $index = 0;
            $str_offset = 0;
            foreach ($positions[0] as $ref => $pattern) {
                $locIndex = 0;
                if (!empty($positions[1][$ref])) {
                    $locIndex = ( (int) $positions[1][$ref][0] ) - 1;
                } else {
                    $locIndex = $index++;
                }
                if (!isset($values[$locIndex])) {
                    unset($values[$locIndex]); // NULL is not set, but present
                    $format_length = strlen($pattern[0]);
                    $query = substr(
                        $query, 0, $pattern[1] + $str_offset
                    ) . ' NULL ' . substr(
                        $query, $pattern[1] + $format_length + $str_offset
                    );
                    if ($format_length != 6) {
                        $str_offset += 6 - $format_length;
                    }
                }
            }
            $args = array_values($values);
        }
        // END replace NULLs

        array_walk($args, array(&$this, 'escape_by_ref'));
        return @vsprintf($query, $args);
    }

    function escape_by_ref(&$string)
    {
        $string = $this->prepare_input($string);
    }

    /**
     * Get number of rows in result. This command is only valid for
     * statements like SELECT or SHOW that return an actual result set.
     * @param resource $query_id
     * @return int
     */
    function sql_numrows($query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        return ( $query_id ) ? mysqli_num_rows($query_id) : false;
    }

    /**
     * Get number of affected rows in previous database operation
     * @return int
     */
    function sql_affectedrows()
    {
        return ( $this->_dbConnection )
        ? mysqli_affected_rows($this->_dbConnection)
        : false;
    }

    /**
     * Get number of fields in result
     * @param resource $query_id The result resource that is being evaluated.
     * @return int
     */
    function sql_numfields($query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        return ( $query_id ) ? mysqli_num_fields($query_id) : false;
    }

    /**
     * Get the name of the specified field in a result
     * @param int $offset The numerical field offset
     * @param resource $query_id The result resource that is being evaluated.
     * @return int
     */
    function sql_fieldname($offset, $query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        return ( $query_id ) ? mysqli_field_name($query_id, $offset) : false;
    }

    /**
     * Get the type of the specified field in a result
     * @param int $offset The numerical field offset
     * @param resource $query_id The result resource that is being evaluated.
     * @return int
     */
    function sql_fieldtype($offset, $query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        return ( $query_id ) ? mysqli_field_type($query_id, $offset) : false;
    }

    /**
     * Fetch a result row as an associative array, a numeric array, or both.
     * @param resource $query_id The result resource that is being evaluated.
     * @return array
     */
    function sql_fetchrow($query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        if ($query_id) {
            if (!is_object($query_id)) {
                $this->row[(int) $query_id] = mysqli_fetch_array(
                    $query_id, MYSQLI_ASSOC
                );
                return $this->row[(int) $query_id];
            } else {
                return mysqli_fetch_array(
                    $query_id, MYSQLI_ASSOC
                );
            }
        } else {
            return false;
        }
    }

    /**
     * Performs an INSERT or UPDATE to a table
     * @param string $tableName
     * @param array $tableData
     * @param string $performType insert or update
     * @param string $performFilter filter for update (EX: where x=x)
     * @param boolean $debug
     */
    function perform($tableName, $tableData, $performType = 'insert',
        $performFilter = '', $debug = false)
    {
        switch (strtolower($performType)) {
            case 'insert':
                $insertString = "";
                $insertString = "INSERT INTO " . $tableName . " (";
                foreach ($tableData as $key => $value) {
                    if ($debug === true) {
                        echo $value['fieldName'] . '#' . "\n";
                    }
                    $insertString .= "`" . $value['fieldName'] . "`, ";
                }
                $insertString = substr(
                    $insertString, 0, strlen($insertString) - 2
                ) . ') VALUES (';
                reset($tableData);
                foreach ($tableData as $key => $value) {
                    $bindVarValue = $this->getBindVarValue(
                        $value['value'], $value['type']
                    );
                    $insertString .= $bindVarValue . ", ";
                }
                $insertString = substr(
                    $insertString, 0, strlen($insertString) - 2
                ) . ')';
                if ($debug === true) {
                    echo $insertString;
                } else {
                    return $this->sql_query($insertString);
                }
                break;
            case 'update':

                $updateString = 'UPDATE ' . $tableName . ' SET ';
                foreach ($tableData as $key => $value) {
                    $bindVarValue = $this->getBindVarValue(
                        $value['value'], $value['type']
                    );
                    $updateString .= "`" . $value['fieldName']
                        . '`=' . $bindVarValue . ', ';
                }
                $updateString = substr(
                    $updateString, 0, strlen($updateString) - 2
                );
                if ($performFilter != '') {
                    $updateString .= ' WHERE ' . $performFilter;
                }
                if ($debug === true) {
                    echo $updateString;
                } else {
                    return $this->sql_query($updateString);
                }
                break;
        }
    }

    function getBindVarValue($value, $type)
    {
        $typeArray = explode(':', $type);
        $type = $typeArray[0];
        if ($value === NULL) {
            return 'NULL';
        }
        switch ($type) {
            case 'csv':
                return $value;
                break;
            case 'passthru':
                return $value;
                break;
            case 'float':
                if ($value === 'NULL' or $value === NULL or $value === "") {
                    return 'NULL';
                }
                if (strpos($value, ",") !== false) {
                    $value = str_replace(",", ".", $value);
                }

                return (is_null($value) || $value == '' || $value == 0)
                ? 0
                : (float) $value;
                break;
            case 'integer':
                if ($value === 'NULL' or $value === NULL) {
                    return 'NULL';
                }
                return (int) $value;
                break;
            case 'string':
                if (isset($typeArray[1])) {
                    $regexp = $typeArray[1];
                }
                return '\'' . $this->prepare_input($value) . '\'';
                break;
            case 'noquotestring':
                return $this->prepare_input($value);
                break;
            case 'currency':
                return '\'' . $this->prepare_input($value) . '\'';
                break;
            case 'date':
                return '\'' . $this->prepare_input($value) . '\'';
                break;
            case 'enum':
                if (isset($typeArray[1])) {
                    $enumArray = explode('|', $typeArray[1]);
                }
                return '\'' . $this->prepare_input($value) . '\'';
            case 'regexp':
                $searchArray = array(
                    '[', ']', '(', ')', '{', '}', '|', '*', '?',
                    '.', '$', '^'
                );
                foreach ($searchArray as $searchTerm) {
                    $value = str_replace(
                        $searchTerm, '\\' . $searchTerm, $value
                    );
                }
                return $this->prepare_input($value);
            default:
                throw new Exception(
                    'var-type undefined: ' . $type . '(' . $value . ')'
                );
        }
    }

    /**
     * method to do bind variables to a query
     * */
    function bindVars($sql, $bindVarString, $bindVarValue, $bindVarType)
    {
        #$bindVarTypeArray = explode(':', $bindVarType);
        $sqlNew = $this->getBindVarValue($bindVarValue, $bindVarType);
        $sqlNew = str_replace($bindVarString, $sqlNew, $sql);
        return $sqlNew;
    }

    function prepareInput($string)
    {
        return mysqli_real_escape_string($this->_dbConnection, $string);
    }

    function sql_fetchrowset($queryId = 0)
    {
        if (!$queryId) {
            $queryId = $this->query_result;
        }

        if ($queryId) {
            unset($this->rowset[$queryId]);
            unset($this->row[$queryId]);

            while ($this->rowset[$queryId] = mysqli_fetch_array(
                $queryId, MYSQLI_ASSOC
            )) {
                $result[] = $this->rowset[$queryId];
            }

            return $result;
        } else {
            return false;
        }
    }

    function sql_fetchfield($field, $rownum = -1, $query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        if ($query_id) {
            if ($rownum > -1) {
                $result = mysqli_result($query_id, $rownum, $field);
            } else {
                if (empty($this->row[$query_id])
                    && empty($this->rowset[$query_id])) {
                    if ($this->sql_fetchrow()) {
                        $result = $this->row[$query_id][$field];
                    }
                } else {
                    if ($this->rowset[$query_id]) {
                        $result = $this->rowset[$query_id][$field];
                    } elseif ($this->row[$query_id]) {
                        $result = $this->row[$query_id][$field];
                    }
                }
            }

            return $result;
        } else {
            return false;
        }
    }

    function sql_rowseek($rownum, $query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        return ( $query_id ) ? mysqli_data_seek($query_id, $rownum) : false;
    }

    function sql_nextid()
    {
        return ( $this->_dbConnection )
        ? mysqli_insert_id($this->_dbConnection)
        : false;
    }

    function sql_freeresult($query_id = 0)
    {
        if (!$query_id) {
            $query_id = $this->query_result;
        }

        if ($query_id) {
            unset($this->row[$query_id]);
            unset($this->rowset[$query_id]);

            mysqli_free_result($query_id);

            return true;
        } else {
            return false;
        }
    }

    function sql_error()
    {
        $result['message'] = mysqli_error($this->_dbConnection);
        $result['code'] = mysqli_errno($this->_dbConnection);

        return $result;
    }

    /**
     * Prepare user input for SQL insert / select
     * @param string $string
     * @return string
     */
    function prepare_input($string)
    {
        if (function_exists('mysqli_real_escape_string')) {
            return mysqli_real_escape_string($this->_dbConnection, $string);
        } elseif (function_exists('mysqli_escape_string')) {
            return mysqli_escape_string($string);
        } else {
            return addslashes($string);
        }
    }



    public function sql_cache_expire_now($query, $category = NULL)
    {
        $cache = pramnos_factory::getCache($category, 'sql');
        $cache->prefix = $this->prefix;
        return $cache->remove($this->cache_generate_cache_name($query));
    }


    /**
     * Store query data to cache
     * @param string $query
     * @param array $resultArray
     * @param string $category
     * @param integer $cachetime
     * @return boolean
     */
    function sql_cache_store($query, $resultArray,
        $category = NULL, $cachetime=3600)
    {
        $cache = pramnos_factory::getCache($category, 'sql');
        $cache->prefix = $this->prefix;
        $cache_name = $this->cache_generate_cache_name($query);
        $cache->extradata=$query;
        $cache->timeout=$cachetime;
        return $cache->save(serialize($resultArray), $cache_name);
    }

    /**
     * Load query data from cache
     * @param string $query
     * @param string $category
     * @return string
     */
    function sql_cache_read($query, $category = "")
    {
        $cache = pramnos_factory::getCache($category, 'sql');
        $cache->prefix = $this->prefix;
        $cache_name = $this->cache_generate_cache_name($query);
        return $cache->load($cache_name);
    }

    /**
     * Clear cache
     * @param string $category
     * @return type
     */
    function sql_cache_flush_cache($category = "")
    {
        $cache = pramnos_factory::getCache($category, 'sql');
        $cache->prefix = $this->prefix;
        return $cache->clear($category);
    }

    /**
     * Generate cache name
     * @param string $query
     * @return string
     */
    function cache_generate_cache_name($query)
    {
        return pramnos_addon::applyFilters(
            'pramnos_database_generate_cache_name', md5($query)
        );
    }

    function set_error($err_num, $err_text, $fatal = true)
    {
        $this->error_number = $err_num;
        $this->error_text = $err_text;
        // error 1141 is okay ... should not die on 1141,
        // but just continue on instead
        if ($fatal && $err_num != 1141) {
            $this->show_error();
            throw new Exception($err_text, $err_num);
        } elseif ($fatal == false && $err_num != 1141) {
            throw new Exception(
                $err_num . ':' . $err_text . ' ::: SQL QUERY: ' . $this->sql
            );
        }
    }

    function show_error()
    {
        echo $this->error_number . ' ' . $this->error_text;
    }

    /**
     *
     * @param string $sql
     * @param boolean $cache
     * @param int $cachetime
     * @param string $category
     * @param boolean $dieOnFatalError
     * @return \pramnos_database_result
     */
    public function Execute($sql, $cache = false,
        $cachetime = 60, $category = "", $dieOnFatalError = false)
    {
        $cacheData = false;
        // eof: collect products_id queries
        if ($cache) {
            $cache = pramnos_factory::getCache($category, 'sql');
            $cache->prefix = $this->prefix;
            $cache_name = $this->cache_generate_cache_name($sql);
            $cache->timeout=$cachetime;
            $cacheData = $cache->load($cache_name);
        }
        $this->sql = $sql;

        if ($cache && $cacheData) {
            $obj = new pramnos_database_result;
            $obj->cursor = 0;
            $obj->isCached = true;
            $obj->sql_query = $sql;
            $result_array = unserialize($cacheData);
            $obj->result = $result_array;
            $obj->numRows = sizeof($result_array);
            if (sizeof($result_array) > 0) {
                $obj->eof = false;
                while (list($key, $value) = each($result_array[0])) {
                    $obj->fields[$key] = $value;
                }
                return $obj;
            } else {
                $obj->eof = true;
                return $obj;
            }
        } elseif ($cache) {
            $this->sql_cache_expire_now($sql, $category);
            $timeStart = explode(' ', microtime());
            $obj = new pramnos_database_result;
            $obj->sql_query = $sql;
            if (!$this->db_connected)
                $this->set_error('0', "Not Connected to database");
            $dbResource = @$this->query($sql, $this->_dbConnection);
            if (!$dbResource)
                $this->set_error(
                    @mysqli_errno(), @mysqli_error(), $dieOnFatalError
                );
            $obj->resource = $dbResource;
            $obj->numRows = mysqli_num_rows($dbResource);
            $obj->cursor = 0;
            $obj->isCached = true;
            if ($obj->RecordCount() > 0) {
                $obj->eof = false;
                $iiCount = 0;
                while (!$obj->eof) {
                    $result_array = @mysqli_fetch_array($dbResource);
                    if ($result_array) {
                        while (list($key, $value) = each($result_array)) {
                            if (!preg_match('/^[0-9]/', $key)) {
                                $obj->result[$iiCount][$key] = $value;
                            }
                        }
                    } else {
                        $obj->Limit = $iiCount;
                        $obj->eof = true;
                    }
                    $iiCount++;
                }
                while (list($key, $value) = each($obj->result[$obj->cursor])) {
                    if (!preg_match('/^[0-9]/', $key)) {
                        $obj->fields[$key] = $value;
                    }
                }
                $obj->eof = false;
            } else {
                $obj->eof = true;
            }
            #var_dump($obj);
            $this->sql_cache_store($sql, $obj->result, $category, $cachetime);
            $timeEnd = explode(' ', microtime());
            $queryTime = $timeEnd[1] + $timeEnd[0]
                - $timeStart[1] - $timeStart[0];
            $this->total_query_time += $queryTime;

            return($obj);
        } else {
            $timeStart = explode(' ', microtime());
            $obj = new pramnos_database_result;
            if (!$this->db_connected)
                $this->set_error('0', "Database is not connected");
            $dbResource = @$this->query($sql, $this->_dbConnection);
            if (!$dbResource)
                $this->set_error(
                    @mysqli_errno($this->_dbConnection),
                    @mysqli_error($this->_dbConnection),
                    $dieOnFatalError
                );
            $obj->resource = $dbResource;
            $obj->cursor = 0;

            $obj->numRows = @mysqli_num_rows($dbResource);

            if ($obj->RecordCount() > 0) {
                $obj->eof = false;
                $result_array = @mysqli_fetch_array($dbResource);
                if ($result_array) {
                    while (list($key, $value) = each($result_array)) {
                        if (!preg_match('/^[0-9]/', $key)) {
                            $obj->fields[$key] = $value;
                        }
                    }
                    $obj->eof = false;
                } else {
                    $obj->eof = true;
                }
            } else {
                $obj->eof = true;
            }

            $timeEnd = explode(' ', microtime());
            $queryTime = $timeEnd[1] + $timeEnd[0]
                - $timeStart[1] - $timeStart[0];
            $this->total_query_time += $queryTime;

            return($obj);
        }
    }

    /**
     *
     * @param string $query
     * @param resource $link
     * @return type
     */
    function query($query, $link)
    {
        $this->num_queries++;
        $time = -microtime(true);
        $result = mysqli_query($link, $query);
        $time += microtime(true);
        if ($this->_customLogSlowQueries == true
                && $this->_slowQueryLogHandler !== NULL
                && $time > $this->long_query_time) {
            $this->_slowquerieslog .= "\n\n"
                . date('H:i:s') . ": "
                . $query . "\nTime: " . number_format($time, 4)
                . ' > '.$this->long_query_time ;
            $this->_numSlowqueries+=1;
        }
        if ($this->_queryLogHandler !== NULL) {
            $this->_querieslog .= "\n\n" . $this->num_queries
                . '.: ' . date('H:i:s') . ": " . $query
                . "\nTime: " . number_format($time, 4);
            if (isset($this->_duplicateQueries[$query])) {
                fwrite($this->_duplicateQueryLogHandler, "\n" . $query);
            }
            $this->_duplicateQueries[$query] = true;
        }
        return($result);
    }

    /**
     * Check if a table exists in the database
     * @param string $table table to check
     * @return bool true if table exists
     */
    function table_exists($table)
    {
        $exists = $this->sql_query(
            "SHOW TABLES FROM `"
            . $this->database . "` LIKE '" . $table . "'"
        );
        if ($this->sql_numrows($exists) == 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Create database table, if it doesn't already exist.
     * @param string $tableName Database table name.
     * @param string $createSql Create database table SQL.
     * @return bool False on error, true if already exists or success.
     */
    function create_table($tableName, $createSql)
    {
        $tablename = $this->prefix . $tableName;
        if ($this->table_exists($tablename)) {
            return true;
        }
        $lines = explode("\n", $createSql);
        $firstline = $lines[0];
        $newline = str_ireplace($tableName, $tablename, $firstline);
        $createDDl = str_ireplace($firstline, $newline, $createSql);
        try {
            $this->Execute($createDDl);
        } catch (Exception $ex) {
            \Pramnos\Logs\Logs::log($ex->getMessage());
        }

        if ($this->table_exists($tablename)) {
            return true;
        } else {
            return false;
        }
    }

    public function select($fields = "*")
    {
        $database = new pramnos_database_statement_select($fields);
        $database->database = $this->driver;
        return $database;
    }

    /**
     * Stop logging
     */
    public function stopLogs()
    {
        $request=&pramnos_factory::getRequest();
        if (is_resource($this->_queryLogHandler)) {
            $this->_querieslog = "\n\n"
                . "=============================="
                . "=======================================\n"
                . date('d/m/Y') . ' :: ' . $this->num_queries
                . ' queries :: ' . $request->getURL(false)."\n"
                . "================================"
                . "====================================="
                . $this->_querieslog;

            fwrite($this->_queryLogHandler, $this->_querieslog);
            fclose($this->_queryLogHandler);
        }
        if (is_resource($this->_duplicateQueryLogHandler)) {
            fclose($this->_duplicateQueryLogHandler);
        }
        if (is_resource($this->_slowQueryLogHandler)) {
            $this->_slowquerieslog = "\n\n"
                . "=============================="
                . "=======================================\n"
                . date('d/m/Y') . ' :: '
                . $this->_numSlowqueries . ' queries :: '
                . $request->getURL(false)."\n"
                . "============================="
                . "========================================"
                . $this->_slowquerieslog;
            if ($this->_numSlowqueries != 0) {
                fwrite($this->_slowQueryLogHandler, $this->_slowquerieslog);
            }
            fclose($this->_slowQueryLogHandler);
        }
        unset($request);
    }

    /**
     * fclose for all log files
     */
    public function __destruct()
    {
        $this->stopLogs();
        $this->sql_close();
    }

}
