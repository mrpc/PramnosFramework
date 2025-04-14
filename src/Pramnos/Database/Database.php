<?php

namespace Pramnos\Database;

/**
 * This class contains all database classes.
 * Inspired by many popular frameworks.
 * @static
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   (C) 2020 Yannis - Pastis Glaros, Pramnos Hosting
 */
class Database extends \Pramnos\Framework\Base
{
    /**
     * Database hostname
     * @var string
     */
    public $server = "localhost";
    /**
     * Username
     * @var string
     */
    public $user = "root";
    /**
     * Password
     * @var string
     */
    public $password = "";
    /**
     * Database to connect
     * @var string
     */
    public $database = "";
    /**
     * Database port
     * @var int
     */
    public $port = null;
    /**
     * Database schema (For postgresql)
     * @var string
     */
    public $schema = "";
    /**
     * Use persistent connection
     * @var bool
     */
    public $persistency = false;
    /**
     * Is the database connected?
     * @var bool
     */
    public $connected = false;
    /**
     * Database collation
     * @var string
     */
    public $collation = false;
    /**
     * Current query result
     * @var type
     */
    protected $queryResult;
    /**
     * Rows
     * @var array
     */
    protected $row = array();
    /**
     * Rowset
     * @var array
     */
    public $rowset = array();
    /**
     * Total database queries execution time
     * @var int
     */
    public $totalQueryTime = 0;
    /**
     * Queries counter
     * @var int
     */
    public $queriesCount = 0;
    /**
     * Current sql query
     * @var string
     */
    protected $currentQuery = "";
    /**
     * Database prefix
     * @var string
     */
    public $prefix = "";
    /**
     * Extra controller prefix for tables
     * @var string
     */
    public $controllerPrefix = "";
    /**
     * Database type. Accepted values: mysql, postgresql
     * @var string
     */
    public $type = "mysql";

    /**
     * Query log handler (fopen resource)
     * @var resource
     */
    private $_queryLogHandler = NULL;
    /**
     * Duplicate query log handler
     * @var resource
     */
    private $_duplicateQueryLogHandler = NULL;
    /**
     * Slow query log handler
     * @var resource
     */
    private $_slowQueryLogHandler = NULL;
    /**
     * Array with all duplicate queries
     * @var array
     */
    private $_duplicateQueries = NULL;
    /**
     * Total duplicate queries counter
     * @var int
     */
    private $_duplicateQueriesCounter = 0;
    /**
     * Long query time in seconds to calculate long queries
     * @var type
     */
    public $longQueryTime=1;
    /**
     * Log slow queries?
     * @var bool
     */
    private $_logSlowQueries=false;
    /**
     * Custom logging of slow queries
     * @var bool
     */
    private $_customLogSlowQueries=false;
    /**
     * Queries log
     * @var string
     */
    private $_querieslog = '';
    /**
     * Slow queries log
     * @var string
     */
    private $_slowquerieslog = '';
    /**
     * Slow queries counter
     * @var int
     */
    private $_numSlowqueries = 0;

    /**
     * Database connection link
     * @var \mysqli|\pgsql\connection
     */
    private $_dbConnection;

    protected $statements = array();

    /**
     * Return current database connection link
     * @return resource
     */
    public function getConnectionLink()
    {
        return $this->_dbConnection;
    }

    /**
     * Initialize the database
     * @param mixed $settingsObject Settings object or a database resource
     */
    public function __construct($settingsObject = null)
    {
        if (is_resource($settingsObject)) {
            $this->addExternalConnection($settingsObject);
        }
        if ($settingsObject instanceof \Pramnos\Application\Settings) {
            $dbSettings = $settingsObject->database;
            $this->server = $dbSettings->hostname;
            $this->database = $dbSettings->database;
            $this->user = $dbSettings->user;
            $this->password = $dbSettings->password;
            $this->collation = $dbSettings->collation;
            $this->prefix = $dbSettings->prefix . '_';
            if ($this->prefix == '_') {
                $this->prefix = '';
            }
            if (isset($dbSettings->port)) {
                $this->port = $dbSettings->port;
            }
            
            if (isset($dbSettings->type)) {
                if ($dbSettings->type == 'postgresql') {
                    if (!extension_loaded('pgsql') 
                        || !function_exists('pg_connect')) {
                        die('Postgresql extension is not installed');
                    }
                    if (isset($dbSettings->schema) && $dbSettings->schema != '') {
                        $this->schema = $dbSettings->schema;
                    } else {
                        $this->schema = 'public';
                    }
                }
                $this->type = $dbSettings->type;
            }
        }
        parent::__construct();
    }

    /**
     * Instead of connecting, add an external mysql link
     * @param resource $link
     * @return $this
     */
    public function addExternalConnection($link)
    {
        $this->_dbConnection = $link;
        $this->connected = true;
        return $this;
    }

    /**
     * Factory method to return a database instance
     * @staticvar array $instance
     * @param \Pramnos\Application\Settings $settingsObject
     * @param string $name Instance name if you want to have multiple instances
     * @return \Pramnos\Database\Database Database instance
     */
    public static function &getInstance($settingsObject = null,
        $name = null)
    {
        static $instance = array();
        if ($name === null) {
            $name = 'default';
        }
        if (!isset($instance[$name])) {
            $instance[$name] = new Database($settingsObject);
        }
        return $instance[$name];
    }

    /**
     * Creates a log file if it doesn't exist
     * @param string $filename
     */
    private function _createLogFile($filename)
    {
        try {
            touch($filename);
            chmod($filename, 0666);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    /**
     * Renames a log file
     * @param string $filename
     */
    private function _renameLogFile($filename)
    {
        $secondFileName = "$filename.old";
        if (file_exists($secondFileName)) {
            try {
                @unlink($secondFileName);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            }
        }
        try {
            $rename = @rename($filename, $secondFileName);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            $rename = false;
        }
        if ($rename !== false) {
            try {
                touch($filename);
                chmod($filename, 0666);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
    public function connect()
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT == true) {
            $this->startLogs();
        }
        switch ($this->type) {
            default:
                try {
                    if ($this->persistency) {
                        $host = 'p:' . $this->server; 
                    } else {
                        $host = $this->server;
                    }
                    $this->_dbConnection = mysqli_connect(
                        $host, $this->user, $this->password, $this->database, $this->port
                    );
                } catch (\Exception $ex) {
                    die($ex->getMessage());
                    return false;
                }
                break;
            case "postgresql":
                try {
                    if ($this->port === null ){
                        $this->_dbConnection = pg_connect(
                            "host=" 
                            . $this->server 
                            . " dbname=" 
                            . $this->database 
                            . " user=" 
                            . $this->user 
                            . " password=" 
                            . $this->password
                        ) or die('Could not connect: ' . pg_last_error());
                    } else {
                        $this->_dbConnection = pg_connect(
                            "host=" 
                            . $this->server 
                            . ' port=' 
                            . $this->port
                            . " dbname=" 
                            . $this->database 
                            . " user=" 
                            . $this->user 
                            . " password=" 
                            . $this->password
                        ) or die('Could not connect: ' . pg_last_error());
                    }
                    
                    
                } catch (\Exception $ex) {
                    die($ex->getMessage());
                    return false;
                }
                break;
        }
        

        if ($this->_dbConnection) {
            $this->connected = true;

            /**
             * If collation is set, change connection collation
             * to get the data in the right format.
             */
            if ($this->collation <> false && $this->type == 'mysql') {
                mysqli_query(
                    $this->_dbConnection,
                    "SET NAMES "
                    . $this->collation . ";"
                );
            }
            if ((defined('DEVELOPMENT') && DEVELOPMENT == true)
                || (defined('LOG_SLOW_QUERIES') && LOG_SLOW_QUERIES == true)) {
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
            $this->longQueryTime = (int)$time;
        }
        $lngPthQueryOriginal=LOG_PATH . DS . 'logs' . DS . 'slowQueries.log';
        $this->rotateLog($lngPthQueryOriginal);
        $longpathquery = str_replace('\\', '/', $lngPthQueryOriginal);
        $this->_logSlowQueries=true;
        if ($mode === 1 && $this->type != 'postgresql') {
            mysqli_query(
                $this->_dbConnection,
                "set global slow_query_log_file = '"
                . $longpathquery
                . "';"
            );
            mysqli_query(
                $this->_dbConnection,
                "SET GLOBAL long_query_time = "
                . $this->longQueryTime
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

            $resultOne = $this->query('select @@global.long_query_time');
            $resultTwo = $this->query('select @@global.LOG_SLOW_QUERIES');
            $resultThree = $this->query('select @@global.SLOW_QUERY_LOG');
            $resultFour = $this->query('select @@global.slow_query_log_file');

            $longQueryTime
                =(int)$resultOne->fields['@@global.long_query_time'];
            $LOG_SLOW_QUERIES=$resultTwo->fields['@@global.LOG_SLOW_QUERIES'];
            $SLOW_QUERY_LOG=$resultThree->fields['@@global.SLOW_QUERY_LOG'];
            $slow_query_log_file
                =$resultFour->fields['@@global.slow_query_log_file'];
            if ($longQueryTime != $this->longQueryTime
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
    public function close()
    {
        if ($this->_dbConnection) {

            foreach ($this->statements as $key=>$statement) {
                try {
                    @$statement['statement']->close();
                    unset($this->statements[$key]);
                } catch (\Exception $ex) {
                    \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                }
            }

            if ($this->type == 'postgresql') {
                return pg_close($this->_dbConnection);
            } else {
                return mysqli_close($this->_dbConnection);
            }
            
        } else {
            return false;
        }
    }

    /**
     * Run the actual query on database
     * @param string $query sql query
     * @return \mysqli_result|bool|\PgSql\Result
     */
    protected function runQuery($query = "")
    {
        unset($this->queryResult);
        if ($query != "") {
            $this->queriesCount++;
            $time = -microtime(true);
            if ($this->type == 'postgresql') {
                $this->queryResult = @pg_query($this->_dbConnection, $query);
                if ($this->queryResult === false) {
                    \Pramnos\Logs\Logger::logError('Postgres error: ' . pg_last_error($this->_dbConnection) . ' for query: ' . $query, null);
                }
            } else {
                $this->queryResult = mysqli_query($this->_dbConnection, $query);
            }
            
            $time += microtime(true);
            if ($this->_customLogSlowQueries == true
                    && $this->_slowQueryLogHandler !== NULL
                    && $time > $this->longQueryTime) {
                $this->_slowquerieslog .= "\n\n"
                    . date('H:i:s') . ": "
                    . $query . "\nTime: "
                    . number_format($time, 4)
                    . ' > '.$this->longQueryTime ;
                $this->_numSlowqueries+=1;
            }
            if ($this->_queryLogHandler !== NULL) {
                $this->_querieslog .= "\n\n"
                    . $this->queriesCount . '.: '
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

        if ($this->queryResult) {

            if (count($this->row) > 0
                && $this->queryResult !== true
                && isset($this->row[$this->queryResult])) {
                unset($this->row[$this->queryResult]);
            }
            if (count($this->rowset) > 0
                && $this->queryResult !== true
                && isset($this->rowset[$this->queryResult])) {
                unset($this->rowset[$this->queryResult]);
            }

            return $this->queryResult;
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
     * Prepare an SQL statement for execution (as a prepared statement)
     * Used mostly to run a query multiple times
     * @param string $sql
     * @return \mysqli_stmt class
     */
    public function prepare($sql)
    {
        if ($this->type == 'postgresql') {
            if ($this->schema != '') {
                $schema = $this->schema . '.';
            }
            $query = str_replace(
                array('"#PREFIX#', '#PREFIX#', "#CP#"),
                array($schema . '"' . $this->prefix, $schema . $this->prefix, $this->controllerPrefix),
                $sql
            );
        } else {
            $query = str_replace(
                array("#PREFIX#", "#CP#"),
                array($this->prefix, $this->controllerPrefix),
                $sql
            );
        }
        
        $types = array();
        $numOfTypes = preg_match_all('/\%(i|d|s|b)/i', $query, $types);
        if ($numOfTypes > 0) {
            $query = str_replace(array('%d', '%i', '%s', '%b'), '?', $query);
            $types = implode($types[1]);
        }
        if (is_array($types)) {
            $types = '';
        }
        $statement = $this->_dbConnection->prepare($query);
        if ($statement) {
            $this->statements[$statement->id] = array(
                'statement' => $statement,
                'types' => $types,
                'query' => $query
            );
        }

        return $statement;
    }


    /**
     * Execute a query as prepared statement<br>
     * Example: <br>
     * <code>
     * $userid = 2;<br>
     * $database->execute(<br>
     *     "select * from `#PREFIX#users` where `userid` = %i",<br>
     *     $userid<br>
     * );
     * </code>
     * @param string|mysqli_stmt $sql An sql query, either as a string
     *                                or as a prepared statement object
     * @param mixed $arguments
     * @return \pramnos_database_result
     */
    public function execute($sql, &...$arguments)
    {
        $free = false;
        $statement = $sql;

        if (is_string($sql)) {
            $statement = $this->prepare($sql);
            $free = true;
        }
        if (isset($this->statements[$statement->id])) {
            $arguments = array_merge(
                array($this->statements[$statement->id]['types']),
                $arguments
            );
        }

        $timeStart = explode(' ', microtime());
        $obj = new Result($this);
        if (!$this->connected) {
            $this->setError('0', "Database is not connected");
        }


        if (count($arguments) > 1) {
            call_user_func_array(
                array($statement, 'bind_param'), $arguments
            );
        }
        if ($statement->execute()) {
            $dbResource = $statement->get_result();
        } else {
            $dbResource = null;
        }
        if ($free) {
            unset($this->statements[$statement->id]);
            $statement->close();
        }




        if (!$dbResource) {
            $this->setError(
                @mysqli_errno($this->_dbConnection),
                @mysqli_error($this->_dbConnection),
                false
            );
        }

        $obj->mysqlResult = $dbResource;

        $obj->numRows = $obj->getNumRows();

        if ($obj->getNumRows() > 0) {
            $obj->eof = false;
            $resultArray = mysqli_fetch_array($dbResource, MYSQLI_ASSOC);
            mysqli_data_seek($dbResource, 0);
            if ($resultArray) {
                foreach($resultArray as $key=>$value) {
                    $obj->fields[$key] = $value;
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
        $this->totalQueryTime += $queryTime;

        return($obj);

    }


    /**
     * Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
     * Inspired from Wordpress
     *
     * The following directives can be used in the query format string:
     *   %d (decimal number)
     *   %s (string)
     *   %% (literal percentage sign - no argument needed)
     *
     * Replaces #PREFIX# with database global table prefix
     * and #CP# with controller table prefix
     *
     * Both %d and %s are to be left unquoted in the query
     * string and they need an argument passed for them.
     * Literals (%) as parts of the query must be properly written as %%.
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
     *
     * @return null|false|string Sanitized query string, null if there is
     * no query, false if there is an error and string
     * 	if there was something to prepare
     */
    public function prepareQuery($sqlQueryString = null)
    {
        if (is_null($sqlQueryString)) {
            return;
        }
        if ($this->type == 'postgresql') {
            $sqlQueryString = str_replace("`", '"', $sqlQueryString);

            $sqlQueryString = preg_replace("/\bas\s+'([^']+)'/i", 'AS "$1"', $sqlQueryString);



            $schema = '';
            if ($this->schema != '') {
                $schema = $this->schema . '.';
            }
            $query = preg_replace(
                '|(?<!%)%s|',
                "'%s'",
                str_replace(
                    array('"#PREFIX#', "#PREFIX#", "#CP#", "'%s'", '"%s"'),
                    array($schema . '"' . $this->prefix, $schema . $this->prefix, $this->controllerPrefix, '%s', '%s'),
                    $sqlQueryString
                )
            );
        } else {
            $query = preg_replace(
                '|(?<!%)%s|',
                "'%s'",
                str_replace(
                    array("#PREFIX#", "#CP#", "'%s'", '"%s"'),
                    array($this->prefix, $this->controllerPrefix, '%s', '%s'),
                    $sqlQueryString
                )
            );
        }
        
        $args = func_get_args();
        array_shift($args);
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        /*
         * Replace nulls
         */
        $positions = array();
        preg_match_all(
            '|\'?%(?:(\d+)\$)?[dfs]\'?|', $query, $positions,
            PREG_OFFSET_CAPTURE
        );
        if (!empty($positions)) {
            $values = array_values($args);
            $extraLetters = 0;
            foreach ($values as $index => $value) {
                if ($value === null) {
                    $match = $positions[0][$index][0];
                    $offset = $positions[0][$index][1] + $extraLetters;
                    $extraLetters += (6 - strlen($match));
                    $offsetEnd = $offset + strlen($match);
                    unset($values[$index]);
                    $query = substr(
                        $query, 0, $offset
                    ) . ' null ' . substr(
                        $query, $offsetEnd
                    );
                }
            }
            $args = array_values($values);
        }
        /*
         * EOF Replace nulls
         */
        foreach ($args as $key => $arg) {
            $args[$key] = $this->prepareInput($arg);
        }

        return @vsprintf($query, $args);
    }









    /**
     * Insert data to a table
     * @param string $table
     * @param array $data
     * @param string $primarykey
     * @param bool $debug
     */
    public function insertDataToTable($table, $data, $primarykey = '', $debug = false)
    {
        $insertString = "";
        if ($this->type == 'postgresql' && $this->schema != '') {
            $insertString = "INSERT INTO " . $this->schema . '.' . $table . " (";
        } else {
            $insertString = "INSERT INTO " . $table . " (";
        }
        
        foreach ($data as $key => $value) {
            if ($this->type == 'postgresql') {
                $insertString .= '"' . $value['fieldName'] . '", ';
            } else {
                $insertString .= "`" . $value['fieldName'] . "`, ";
            }
            
        }
        $insertString = substr(
            $insertString, 0, strlen($insertString) - 2
        ) . ') VALUES (';
        reset($data);
        foreach ($data as $key => $value) {
            $bindVarValue = $this->prepareValue(
                $value['value'], $value['type']
            );
            $insertString .= $bindVarValue . ", ";
        }
        $insertString = substr(
            $insertString, 0, strlen($insertString) - 2
        ) . ')';
        if ($this->type == 'postgresql' && $primarykey != '') {
            $insertString .= " RETURNING " . $primarykey;
        }
        if ($debug) {
            echo "\n\n" . $insertString . "\n\n";
        }
        return $this->runQuery($insertString);

    }

    /**
     * Update data on a table
     * @param string $table
     * @param array $data
     * @param string $filter filter for update (EX: where x=x)
     * @param bool $debug
     */
    public function updateTableData($table, $data, $filter = '', $debug = false)
    {
        if ($this->type == 'postgresql' && $this->schema != '') {
            $updateString = "UPDATE " . $this->schema . '.' . $table . ' SET ';
        } else {
            $updateString = 'UPDATE ' . $table . ' SET ';
        }
        foreach ($data as $value) {
            $bindVarValue = $this->prepareValue(
                $value['value'], $value['type']
            );
            if ($this->type == 'postgresql') {
                $updateString .= '"' . $value['fieldName']
                    . '"=' . $bindVarValue . ', ';
            } else {
                $updateString .= "`" . $value['fieldName']
                    . '`=' . $bindVarValue . ', ';
            }
            
        }
        $updateString = substr(
            $updateString, 0, strlen($updateString) - 2
        );
        if ($filter != '') {
            if ($this->type == 'postgresql') {
                $updateString .= ' WHERE ' . str_replace('`', '"', $filter);
            } else {
                $updateString .= ' WHERE ' . $filter;
            }
            
        }
        if ($debug) {
            echo "\n\n" . $updateString . "\n\n";
        }
        return $this->runQuery($updateString);

    }

    /**
     * Clean up a value to use in insert or update methods
     * @param mixed $value
     * @param string $type
     * @return string|int
     * @throws \Exception
     */
    protected function prepareValue($value, $type)
    {
        if ($value === NULL) {
            return 'NULL';
        }
        switch ($type) {
            case 'float':
                if ($value === 'NULL' or $value === NULL or $value === "") {
                    return 'NULL';
                }
                if (strpos($value, ",") !== false) {
                    $value = str_replace(",", ".", $value);
                }
                if ($value == '' || $value == 0) {
                    return 0;
                } else {
                    return (float) $value;
                }
            case 'integer':
                if ($value === 'NULL' or $value === NULL) {
                    return 'NULL';
                }
                return (int) $value;
            case 'string':
            case 'currency':
            case 'date':
                return '\'' . $this->prepareInput($value) . '\'';
            default:
                throw new \Exception(
                    'var-type undefined: ' . $type . '(' . $value . ')'
                );
        }
    }


    /**
     * Returns the auto generated id used in the latest query
     * @return mixed The value of the AUTO_INCREMENT field that was updated by
     * the previous query. Returns zero if there was no previous query
     * on the connection or if the query did not update an AUTO_INCREMENT value.
     * Returns false when database is not connected
     */
    public function getInsertId()
    {
        if ($this->_dbConnection && $this->type != 'postgresql') {
            return mysqli_insert_id($this->_dbConnection);
        } elseif ($this->type == 'postgresql') {
            $result = $this->query('SELECT LASTVAL()');
            if ($result) {
                return $result->fields['lastval'];
            }
        }

        return false;
    }

    /**
     * Get the last database error
     * @return array Array with message and error code
     */
    public function getError()
    {
        if ($this->type == 'postgresql') {
            $result['message'] = pg_last_error($this->_dbConnection);
            $result['code'] = 0;
        } else {
            $result['message'] = mysqli_error($this->_dbConnection);
            $result['code'] = mysqli_errno($this->_dbConnection);
        }
        return $result;
    }

    /**
     * Prepare user input for SQL insert / select
     * @param string $string
     * @return string
     */
    public function prepareInput($string)
    {
        if (function_exists('mysqli_real_escape_string') && $this->type == 'mysql') {
            return mysqli_real_escape_string($this->_dbConnection, $string);
        } elseif (function_exists('pg_escape_string') && $this->type == 'postgresql') {
            return pg_escape_string($this->_dbConnection, $string);
        } elseif (function_exists('mysqli_escape_string')) {
            return mysqli_real_escape_string($this->_dbConnection, $string);
        } else {
            return addslashes($string ?? '');
        }
    }



    public function cacheExpire($query, $category = NULL)
    {
        #$cache = pramnos_factory::getCache($category, 'sql');
        #$cache->prefix = $this->prefix;
        #return $cache->remove($this->cache_generate_cache_name($query));
    }


    /**
     * Store query data to cache
     * @param string $query
     * @param array $resultArray
     * @param string $category
     * @param integer $cachetime
     * @return boolean
     */
    function cacheStore($query, $resultArray,
        $category = NULL, $cachetime=3600)
    {
        #$cache = pramnos_factory::getCache($category, 'sql');
        #$cache->prefix = $this->prefix;
        #$cache_name = $this->cache_generate_cache_name($query);
        #$cache->extradata=$query;
        #$cache->timeout=$cachetime;
        #return $cache->save(serialize($resultArray), $cache_name);
    }

    /**
     * Load query data from cache
     * @param string $query
     * @param string $category
     * @return string
     */
    function cacheRead($query, $category = "")
    {
        #$cache = pramnos_factory::getCache($category, 'sql');
        #$cache->prefix = $this->prefix;
        #$cache_name = $this->cache_generate_cache_name($query);
        #return $cache->load($cache_name);

    }

    /**
     * Clear cache
     * @param string $category
     * @return type
     */
    function cacheflush($category = "")
    {
        #$cache = pramnos_factory::getCache($category, 'sql');
        #$cache->prefix = $this->prefix;
        #return $cache->clear($category);
    }

    /**
     * Refresh the database connection
     * @return void
     */
    public function refresh()
    {
        $this->close();
        $this->connect();
    }   

    // /**
    //  * Generate cache name
    //  * @param string $query
    //  * @return string
    //  */
    // function generateCacheName($query)
    // {
    //     return pramnos_addon::applyFilters(
    //         'pramnos_database_generate_cache_name', md5($query)
    //     );
    //}

    /**
     * Set the database error
     * @param int $errorNumber Error Number
     * @param string $errorMessage  Error Message
     * @param bool $fatal If set to true, display error and stop the application
     *                    execution
     * @throws \Exception
     */
    protected function setError($errorNumber, $errorMessage, $fatal = true)
    {
        $this->error_number = $errorNumber;
        $this->error_text = $errorMessage;
        // error 1141 is okay ... should not die on 1141,
        // but just continue on instead
        if ($fatal && $errorNumber != 1141) {
            $this->displayError();
            throw new \Exception($errorMessage, $errorNumber);
        } elseif ($fatal == false && $errorNumber != 1141) {
            throw new \Exception(
                $errorNumber
                . ':'
                . $errorMessage
                . ' ::: SQL QUERY: '
                . "\n"
                . $this->currentQuery
                . "\n"
            );
        }
    }

    /**
     * Display the last database error
     */
    public function displayError()
    {
        $app = \Pramnos\Application\Application::getInstance();
        $app->showError($this->error_number . ' ' . $this->error_text);
    }

    /**
     *
     * @param string $sql
     * @param boolean $cache
     * @param int $cachetime
     * @param string $category
     * @param boolean $dieOnFatalError
     * @return \Pramnos\Database\Result
     */
    public function query($sql, $cache = false,
        $cachetime = 60, $category = "", $dieOnFatalError = false)
    {
        $cacheData = false;
        $cache = false;
        // eof: collect products_id queries
        if ($cache) {
            #$cache = pramnos_factory::getCache($category, 'sql');
            #$cache->prefix = $this->prefix;
            #$cache_name = $this->cache_generate_cache_name($sql);
            #$cache->timeout=$cachetime;
            #$cacheData = $cache->load($cache_name);
        }
        $this->currentQuery = $sql;

        if ($cache && $cacheData) {
            $obj = new Result($this);
            $obj->cursor = 0;
            $obj->isCached = true;
            $obj->query = $sql;
            $resultArray = unserialize($cacheData);
            $obj->result = $resultArray;
            if ($resultArray === null) {
                $obj->numRows = 0;
                $obj->eof = true;
                return $obj;
            }
            $obj->numRows = count($resultArray);
            if ($obj->numRows > 0) {
                $obj->eof = false;
                foreach ($resultArray[0] as $key => $value) {
                    $obj->fields[$key] = $value;
                }
                return $obj;
            } else {
                $obj->eof = true;
                return $obj;
            }
        } elseif ($cache) {
            $this->cacheExpire($sql, $category);
            $timeStart = explode(' ', microtime());
            $obj = new Result($this);
            $obj->sql_query = $sql;
            if (!$this->connected) {
                $this->setError('0', "Not Connected to database");
            }
            $dbResource = @$this->runQuery($sql, $this->_dbConnection);
            if (!$dbResource) {
                $this->setError(
                    @mysqli_errno(), @mysqli_error(), $dieOnFatalError
                );
            }

            $obj->mysqlResult = $dbResource;
            $obj->numRows = $obj->getNumRows();

            $obj->isCached = true;
            if ($obj->getNumRows() > 0) {
                $iiCount = 0;
                while (!$obj->eof) {
                    $resultArray = mysqli_fetch_array(
                        $dbResource, MYSQLI_ASSOC
                    );
                    mysqli_data_seek($dbResource, 0);
                    if ($resultArray) {
                        foreach ($resultArray as $key => $value) {
                            $obj->result[$iiCount][$key] = $value;
                        }
                    } else {
                        $obj->eof = true;
                    }
                    $iiCount++;
                }
                foreach ($obj->result[$obj->cursor] as $key => $value) {
                    if (!preg_match('/^[0-9]/', $key)) {
                        $obj->fields[$key] = $value;
                    }
                }
                $obj->eof = false;
            } else {
                $obj->eof = true;
            }
            #var_dump($obj);
            $this->cacheStore($sql, $obj->result, $category, $cachetime);
            $timeEnd = explode(' ', microtime());
            $queryTime = $timeEnd[1] + $timeEnd[0]
                - $timeStart[1] - $timeStart[0];
            $this->totalQueryTime += $queryTime;

            return($obj);
        } else {
            $timeStart = explode(' ', microtime());
            $obj = new Result($this);
            if (!$this->connected) {
                $this->setError('0', "Database is not connected");
            }

            if ($this->type == 'postgresql') {
                $dbResource = $this->runQuery($sql, $this->_dbConnection);
                if (!$dbResource) {
                    $this->setError(
                        0,
                        pg_last_error($this->_dbConnection),
                        $dieOnFatalError
                    );
                    \Pramnos\Logs\Logger::logError('Postgres error:' . pg_last_error($this->_dbConnection) . ' for query: ' . $sql, null, 'postgreserrors');
                    
                
                }

                $obj->mysqlResult = $dbResource;
                if (is_object($dbResource)) {
                    $obj->numRows = pg_num_rows($dbResource);
                } elseif (is_resource($dbResource)) {
                    $obj->numRows = $obj->getNumRows();
                }
                if ($obj->getNumRows() > 0) {
                    $obj->eof = false;
                    $resultArray = pg_fetch_array($dbResource, null, PGSQL_ASSOC);
                    pg_fetch_array($dbResource, 0);
                    if ($resultArray) {
                        foreach($resultArray as $key=>$value) {
                            $obj->fields[$key] = $value;
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
                $this->totalQueryTime += $queryTime;

                return($obj);
            } else {
                $dbResource = @$this->runQuery($sql, $this->_dbConnection);
                if (!$dbResource) {
                    $this->setError(
                        @mysqli_errno($this->_dbConnection),
                        @mysqli_error($this->_dbConnection),
                        $dieOnFatalError
                    );
                }

                $obj->mysqlResult = $dbResource;

                $obj->numRows = $obj->getNumRows();

                if ($obj->getNumRows() > 0) {
                    $obj->eof = false;
                    $resultArray = mysqli_fetch_array($dbResource, MYSQLI_ASSOC);
                    mysqli_data_seek($dbResource, 0);
                    if ($resultArray) {
                        foreach($resultArray as $key=>$value) {
                            $obj->fields[$key] = $value;
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
                $this->totalQueryTime += $queryTime;

                return($obj);
            }


            
        }
    }



    /**
     * Check if a table exists in the database
     * @param string $table table to check
     * @return bool true if table exists
     */
    public function tableExists($table)
    {
        if ($this->type == 'postgresql') {
            $exists = $this->prepareQuery(
                "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = '" . $table . "')"
            );
            $result = $this->query($exists);
            if ($result->numRows > 0) {
                return true;
            }
        } else {
            $exists = $this->prepareQuery(
                "SHOW TABLES FROM `"
                . $this->database . "` LIKE '" . $table . "'"
            );
            $result = $this->query($exists);
            if ($result->numRows > 0) {
                return true;
            }
        }
        
        return false;
    }



    /**
     * Stop logging
     */
    public function stopLogs()
    {
        $request = new \Pramnos\Http\Request();
        if (is_resource($this->_queryLogHandler)) {
            $this->_querieslog = "\n\n"
                . "=============================="
                . "=======================================\n"
                . date('d/m/Y') . ' :: ' . $this->queriesCount
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
        $this->close();
    }

    /**
     * Starts a database transaction
     *
     * @return bool True on success, false on failure
     */
    public function startTransaction()
    {
        if (!$this->connected) {
            $this->connect();
        }
        
        try {
            if ($this->type == 'postgresql') {
                return $this->runQuery('BEGIN') !== false;
            } else {
                return $this->runQuery('START TRANSACTION') !== false;
            }
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError("Failed to start transaction: " . $ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Commits the current transaction
     *
     * @return bool True on success, false on failure
     */
    public function commitTransaction()
    {
        if (!$this->connected) {
            return false;
        }
        
        try {
            return $this->runQuery('COMMIT') !== false;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError("Failed to commit transaction: " . $ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Rolls back the current transaction
     *
     * @return bool True on success, false on failure
     */
    public function rollbackTransaction()
    {
        if (!$this->connected) {
            return false;
        }
        
        try {
            return $this->runQuery('ROLLBACK') !== false;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError("Failed to rollback transaction: " . $ex->getMessage(), $ex);
            return false;
        }
    }

}
