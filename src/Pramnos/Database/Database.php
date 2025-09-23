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
     * @param string $table Table name to insert into
     * @param array $data Array of data to insert with fieldName, value and type keys
     * @param string $primarykey Primary key field name (optional)
     * @param bool $debug Enable debug mode to output the query
     * @return \mysqli_result|bool|\PgSql\Result Query result
     */
    public function insertDataToTable($table, $data, $primarykey = '', $debug = false)
    {
        $insertString = "";
        if ($this->type == 'postgresql' && $this->schema != '' && strpos($table, '.') === false) {
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


            if ($value['type'] == 'geometry' && $this->type == 'postgresql') {
                if (is_string($value['value'])) {
                    // check if the value is in the form of latitude,longitude
                    if (preg_match('/^(-?\d+(\.\d+)?),\s*(-?\d+(\.\d+)?)$/', $value['value'], $matches)) {
                        $latitude = $matches[1];
                        $longitude = $matches[3];
                        $insertString .= 'ST_SetSRID(ST_MakePoint(' . $longitude . ', ' . $latitude . '), 4326), ';
                    } else {
                        // handle other cases
                        $insertString .= 'ST_GeomFromText(\'' . $value['value'] . '\'), ';
                    }
                } elseif (is_array($value['value'])) {
                    // check if the value is in the form of latitude,longitude
                    if (isset($value['value']['latitude']) && isset($value['value']['longitude'])) {
                        $latitude = $value['value']['latitude'];
                        $longitude = $value['value']['longitude'];
                        $insertString .= 'ST_SetSRID(ST_MakePoint(' . $longitude . ', ' . $latitude . '), 4326), ';
                    } else {
                        // handle other cases
                        $insertString .= 'ST_GeomFromText(\'' . $value['value'] . '\'), ';
                    }
                } else {
                    $insertString .= 'NULL, ';
                }
            } elseif ($value['type'] == 'boolean' && $this->type == 'postgresql') {
                if ($value['value'] == 'true' || $value['value'] == true || $value['value'] == 1) {
                    $insertString .= '\'t\', ';
                } elseif ($value['value'] == 'false' || $value['value'] == false || $value['value'] == 0) {
                    $insertString .= '\'f\', ';
                } else {
                    $insertString .= 'NULL, ';
                }

            } else {


                $bindVarValue = $this->prepareValue(
                    $value['value'], $value['type']
                );
                $insertString .= $bindVarValue . ", ";
            }
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
     * @param string $table Table name to update
     * @param array $data Array of data to update with fieldName, value and type keys
     * @param string $filter Filter for update (EX: where x=x)
     * @param bool $debug Enable debug mode to output the query
     * @return \mysqli_result|bool|\PgSql\Result Query result
     */
    public function updateTableData($table, $data, $filter = '', $debug = false)
    {
        if ($this->type == 'postgresql' && $this->schema != '' && strpos($table, '.') === false) {
            $updateString = "UPDATE " . $this->schema . '.' . $table . ' SET ';
        } else {
            $updateString = 'UPDATE ' . $table . ' SET ';
        }
        foreach ($data as $value) {
            if ($value['type'] == 'geometry' && $this->type == 'postgresql') {
                if (is_string($value['value'])) {
                    // check if the value is in the form of latitude,longitude
                    if (preg_match('/^(-?\d+(\.\d+)?),\s*(-?\d+(\.\d+)?)$/', $value['value'], $matches)) {
                        $latitude = $matches[1];
                        $longitude = $matches[3];
                        $updateString .= '"' . $value['fieldName'] . '"=ST_SetSRID(ST_MakePoint(' . $longitude . ', ' . $latitude . '), 4326), ';
                    } else {
                        // handle other cases
                        $updateString .= '"' . $value['fieldName'] . '"= ST_GeomFromText(\'' . $value['value'] . '\'), ';
                    }
                } elseif (is_array($value['value'])) {
                    // check if the value is in the form of latitude,longitude
                    if (isset($value['value']['latitude']) && isset($value['value']['longitude'])) {
                        $latitude = $value['value']['latitude'];
                        $longitude = $value['value']['longitude'];
                        $updateString .= '"' . $value['fieldName'] . '"=ST_SetSRID(ST_MakePoint(' . $longitude . ', ' . $latitude . '), 4326), ';
                    } else {
                        // handle other cases
                        $updateString .= '"' . $value['fieldName'] . '"= ST_GeomFromText(\'' . $value['value'] . '\'), ';
                    }
                } else {
                    $updateString .= '"' . $value['fieldName']
                        . '"= NULL, ';
                }

            } elseif ($value['type'] == 'boolean' && $this->type == 'postgresql') {

                if ($value['value'] == 'true' || $value['value'] == true || $value['value'] == 1) {
                    $updateString .= '"' . $value['fieldName'] . '"=\'t\', ';
                } elseif ($value['value'] == 'false' || $value['value'] == false || $value['value'] == 0) {
                    $updateString .= '"' . $value['fieldName'] . '"=\'f\', ';
                } else {
                    $updateString .= '"' . $value['fieldName'] . '"=NULL, ';
                }

            } else {
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

    /**
     * Prepare data for type-preserving cache storage (Redis only)
     * @param array $data The data array to prepare
     * @return array Array with data and type information
     */
    private function prepareDataForCache($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $preparedData = [];
        
        foreach ($data as $rowIndex => $row) {
            if (is_array($row)) {
                // This is a database row - preserve types for each field
                $preparedRow = [];
                foreach ($row as $fieldName => $fieldValue) {
                    // Store original value and its type
                    $preparedRow[$fieldName] = [
                        'v' => $fieldValue,  // Short key to minimize overhead
                        't' => $this->getSimpleType($fieldValue)
                    ];
                }
                $preparedData[$rowIndex] = $preparedRow;
            } else {
                // Keep non-array values as-is
                $preparedData[$rowIndex] = $row;
            }
        }
        
        // Wrap with minimal metadata
        return [
            '_t' => true,  // Type preserved flag
            'd' => $preparedData
        ];
    }

    /**
     * Restore data types from cache (Redis only)
     * @param mixed $cachedData The cached data to restore
     * @return mixed Restored data with original types - EXACT same structure as original
     */
    private function restoreDataFromCache($cachedData)
    {
        // Check if this is type-preserved data
        if (is_array($cachedData) && isset($cachedData['_t']) && $cachedData['_t'] === true && isset($cachedData['d'])) {
            return $this->restoreTypes($cachedData['d']);
        }
        
        // Return as-is for non-type-preserved data
        return $cachedData;
    }

    /**
     * Restore types from prepared data
     * @param array $data Prepared data with type info
     * @return array Restored data with exact original structure
     */
    private function restoreTypes($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $restoredData = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['v']) && isset($value['t'])) {
                // This is a typed field value, restore it
                $restoredData[$key] = $this->castToType($value['v'], $value['t']);
            } elseif (is_array($value)) {
                // This is a row or nested structure, recurse
                $restoredData[$key] = $this->restoreTypes($value);
            } else {
                // Plain value, keep as-is
                $restoredData[$key] = $value;
            }
        }
        
        return $restoredData;
    }

    /**
     * Get simple type identifier for a value
     * @param mixed $value The value to analyze
     * @return string Simple type code
     */
    private function getSimpleType($value)
    {
        if ($value === null) return 'n';
        if (is_bool($value)) return 'b';
        if (is_int($value)) return 'i';
        if (is_float($value)) return 'f';
        return 's'; // string or other
    }

    /**
     * Cast value to its original type
     * @param mixed $value The value to cast
     * @param string $type The type code
     * @return mixed Value cast to original type
     */
    private function castToType($value, $type)
    {
        switch ($type) {
            case 'n': return null;
            case 'b': return (bool) $value;
            case 'i': return (int) $value;
            case 'f': return (float) $value;
            case 's':
            default:
                return (string) $value;
        }
    }

    /**
     * Expire cache entries matching a query pattern and category
     * @param string $query Cache key pattern to expire
     * @param string|null $category Cache category (optional)
     * @return void
     */
    public function cacheExpire($query, $category = NULL)
    {
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $cacheMethod = 'memcached'; // default
        if (is_array($cacheSettings) && isset($cacheSettings['method'])) {
            $cacheMethod = $cacheSettings['method'];
        } elseif (is_object($cacheSettings) && isset($cacheSettings->method)) {
            $cacheMethod = $cacheSettings->method;
        }
        $cache = \Pramnos\Cache\Cache::getInstance($category, 'sql', $cacheMethod);
        // Fix: Ensure the category is set correctly on the singleton instance
        if ($category !== NULL) {
            $cache->category = $category;
        }
        $cache->prefix = $this->prefix;
        $cache_name = md5($query);
        return $cache->delete($cache_name);
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
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $cacheMethod = 'memcached'; // default
        if (is_array($cacheSettings) && isset($cacheSettings['method'])) {
            $cacheMethod = $cacheSettings['method'];
        } elseif (is_object($cacheSettings) && isset($cacheSettings->method)) {
            $cacheMethod = $cacheSettings->method;
        }
        $cache = \Pramnos\Cache\Cache::getInstance($category, 'sql', $cacheMethod);
        // Fix: Ensure the category is set correctly on the singleton instance
        if ($category !== NULL) {
            $cache->category = $category;
        }
        $cache->prefix = $this->prefix;
        $cache_name = md5($query);
        $cache->extradata = $query;
        $cache->timeout = $cachetime;
        
        // Prepare data for storage with automatic type preservation for Redis
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $cacheMethod = 'memcached'; // default
        if (is_array($cacheSettings) && isset($cacheSettings['method'])) {
            $cacheMethod = $cacheSettings['method'];
        } elseif (is_object($cacheSettings) && isset($cacheSettings->method)) {
            $cacheMethod = $cacheSettings->method;
        }
        
        // Use type preservation only for Redis to solve the string conversion issue
        if ($cacheMethod === 'redis') {
            $preparedData = $this->prepareDataForCache($resultArray);
            $dataToStore = serialize($preparedData);
        } else {
            $dataToStore = serialize($resultArray);
        }
        
        // Apply compression for large datasets
        if (strlen($dataToStore) > 10240) { // 10KB threshold
            if (function_exists('gzcompress')) {
                $compressedData = gzcompress($dataToStore, 6);
                if ($compressedData !== false && strlen($compressedData) < strlen($dataToStore)) {
                    $dataToStore = 'GZCOMPRESSED:' . $compressedData;
                }
            }
        }
        
        return $cache->save($dataToStore, $cache_name);
    }

    /**
     * Load query data from cache
     * @param string $query
     * @param string $category
     * @return string
     */
    function cacheRead($query, $category = "")
    {
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $cacheMethod = 'memcached'; // default
        if (is_array($cacheSettings) && isset($cacheSettings['method'])) {
            $cacheMethod = $cacheSettings['method'];
        } elseif (is_object($cacheSettings) && isset($cacheSettings->method)) {
            $cacheMethod = $cacheSettings->method;
        }
        $cache = \Pramnos\Cache\Cache::getInstance($category, 'sql', $cacheMethod);
        // Fix: Ensure the category is set correctly on the singleton instance
        if ($category !== NULL && $category !== "") {
            $cache->category = $category;
        }
        $cache->prefix = $this->prefix;
        $cache_name = md5($query);
        $cachedData = $cache->load($cache_name);
        
        if ($cachedData === false || $cachedData === null) {
            return false;
        }
        
        // Check if data is compressed
        if (is_string($cachedData) && strpos($cachedData, 'GZCOMPRESSED:') === 0) {
            $compressedData = substr($cachedData, 13); // Remove 'GZCOMPRESSED:' prefix
            if (function_exists('gzuncompress')) {
                $uncompressedData = gzuncompress($compressedData);
                if ($uncompressedData !== false) {
                    $cachedData = $uncompressedData;
                } else {
                    // If decompression fails, return false
                    return false;
                }
            } else {
                // If decompression fails, return false
                return false;
            }
        }
        
        // Deserialize the data and automatically restore types if they were preserved
        $deserializedData = unserialize($cachedData);
        
        // Automatically detect and restore type-preserved data (transparent to caller)
        return $this->restoreDataFromCache($deserializedData);
    }

    /**
     * Clear cache
     * @param string $category
     * @return type
     */
    function cacheflush($category = "")
    {
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $cacheMethod = 'memcached'; // default
        if (is_array($cacheSettings) && isset($cacheSettings['method'])) {
            $cacheMethod = $cacheSettings['method'];
        } elseif (is_object($cacheSettings) && isset($cacheSettings->method)) {
            $cacheMethod = $cacheSettings->method;
        }
        $cache = \Pramnos\Cache\Cache::getInstance($category, 'sql', $cacheMethod);
        // Fix: Ensure the category is set correctly on the singleton instance
        if ($category !== NULL && $category !== "") {
            $cache->category = $category;
        }
        $cache->prefix = $this->prefix;
        return $cache->clear($category);
    }

    /**
     * Determine if a result set should be cached based on memory constraints
     * @param array $resultSet The result set to evaluate
     * @return bool True if the result should be cached, false otherwise
     */
    private function shouldCacheResult($resultSet)
    {
        if (!is_array($resultSet) || empty($resultSet)) {
            return true; // Cache empty results
        }

        $rowCount = count($resultSet);
        
        // Get cache limits from settings or use defaults
        $cacheSettings = \Pramnos\Application\Settings::getSetting('cache');
        $maxRows = 1000; // Default max rows to cache
        $maxMemoryMB = 50; // Default max memory usage in MB
        
        if (is_array($cacheSettings)) {
            $maxRows = isset($cacheSettings['max_cached_rows']) ? $cacheSettings['max_cached_rows'] : $maxRows;
            $maxMemoryMB = isset($cacheSettings['max_cache_memory_mb']) ? $cacheSettings['max_cache_memory_mb'] : $maxMemoryMB;
        } elseif (is_object($cacheSettings)) {
            $maxRows = isset($cacheSettings->max_cached_rows) ? $cacheSettings->max_cached_rows : $maxRows;
            $maxMemoryMB = isset($cacheSettings->max_cache_memory_mb) ? $cacheSettings->max_cache_memory_mb : $maxMemoryMB;
        }

        // Check row count limit
        if ($rowCount > $maxRows) {
            return false;
        }

        // Estimate memory usage
        $estimatedMemoryMB = $this->estimateResultSetMemory($resultSet);
        if ($estimatedMemoryMB > $maxMemoryMB) {
            return false;
        }

        // Check available system memory
        $availableMemoryMB = $this->getAvailableMemoryMB();
        if ($availableMemoryMB !== null && $estimatedMemoryMB > ($availableMemoryMB * 0.1)) {
            return false; // Don't use more than 10% of available memory
        }

        return true;
    }

    /**
     * Estimate memory usage of a result set in MB
     * @param array $resultSet
     * @return float Estimated memory usage in MB
     */
    private function estimateResultSetMemory($resultSet)
    {
        if (empty($resultSet)) {
            return 0;
        }

        // Calculate average row size by sampling first few rows
        $sampleSize = min(10, count($resultSet));
        $totalSampleSize = 0;
        
        for ($i = 0; $i < $sampleSize; $i++) {
            $totalSampleSize += strlen(serialize($resultSet[$i]));
        }
        
        $avgRowSize = $totalSampleSize / $sampleSize;
        $totalEstimatedBytes = $avgRowSize * count($resultSet);
        
        // Add overhead for array structure (approximately 50% overhead)
        $totalEstimatedBytes *= 1.5;
        
        return $totalEstimatedBytes / (1024 * 1024); // Convert to MB
    }

    /**
     * Get available system memory in MB
     * @return float|null Available memory in MB or null if cannot determine
     */
    private function getAvailableMemoryMB()
    {
        // Get PHP memory limit
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return null; // No limit
        }
        
        $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        $currentUsageBytes = memory_get_usage(true);
        $availableBytes = $memoryLimitBytes - $currentUsageBytes;
        
        return max(0, $availableBytes / (1024 * 1024)); // Convert to MB
    }

    /**
     * Parse memory limit string to bytes
     * @param string $memoryLimit
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit($memoryLimit)
    {
        $memoryLimit = trim($memoryLimit);
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $memoryLimit;
        }
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
     * Run a query on the database
     * @note This function is used to run a query on the database
     *       and return the result as an object of the Result class
     * @param string $sql SQL query
     * @param bool $cache If set to true, the result will be cached
     * @param int $cachetime Cache time in seconds
     * @param string $category Cache category
     * @param bool $dieOnFatalError If set to true, the application will die
     *                              on fatal error
     * @param bool $skipDataFix If set to true, the data fix will be skipped
     * @return \Pramnos\Database\Result
     */
    public function query($sql, $cache = false,
        $cachetime = 60, $category = "", $dieOnFatalError = false, $skipDataFix = false)
    {
        $cacheData = false;
        $cacheInstance = null;
        // Check if caching is enabled for this query
        if ($cache) {
            $cacheData = $this->cacheRead($sql, $category);
        }
        $this->currentQuery = $sql;

        if ($cache && $cacheData) {
            $obj = new Result($this);
            $obj->cursor = 0;
            $obj->isCached = true;
            
            // Check if data is already unserialized (new format) or needs unserialization (old format)
            if (is_string($cacheData)) {
                $resultArray = unserialize($cacheData);
            } else {
                // Data is already unserialized by the new cacheRead method
                $resultArray = $cacheData;
            }
            
            $obj->result = $resultArray;
            if ($resultArray === null || !is_array($resultArray)) {
                $obj->numRows = 0;
                $obj->eof = true;
                return $obj;
            }
            $obj->numRows = is_array($resultArray) ? count($resultArray) : 0;
            if ($obj->numRows > 0) {
                $obj->eof = false;
                // Check if first element exists and is an array before accessing it
                if (isset($resultArray[0]) && is_array($resultArray[0])) {
                    foreach ($resultArray[0] as $key => $value) {
                        $obj->fields[$key] = $value;
                    }
                }
                return $obj;
            } else {
                $obj->eof = true;
                return $obj;
            }
        } elseif ($cache) {
            $this->cacheExpire($sql, $category);
            $timeStart = explode(' ', microtime());
            
            if ($this->type == 'postgresql') {
                $obj = $this->runPgQuery($sql, $dieOnFatalError, $skipDataFix);
            } else {
                $obj = $this->runMysqlQuery($sql, $dieOnFatalError, $skipDataFix);
            }
            $obj->isCached = false;
            $obj->result = $obj->fetchAll();

            // Memory optimization: Only cache if result set is reasonable size
            if ($this->shouldCacheResult($obj->result)) {
                $this->cacheStore($sql, $obj->result, $category, $cachetime);
            }
            
            $timeEnd = explode(' ', microtime());
            $queryTime = $timeEnd[1] + $timeEnd[0]
                - $timeStart[1] - $timeStart[0];
            $this->totalQueryTime += $queryTime;

            return($obj);
        } else {
            if ($this->type == 'postgresql') {
                return $this->runPgQuery($sql, $dieOnFatalError, $skipDataFix);
            } else {
                return $this->runMysqlQuery($sql, $dieOnFatalError, $skipDataFix);
            }
        }
    }

    /**
     * Run a query on postgres sql
     * @param string $sql SQL query
     * @param bool $dieOnFatalError If set to true, the application will die
     *                              on fatal error
     * @param bool $skipDataFix If set to true, the data fix will be skipped
     * @return \Pramnos\Database\Result
     */
    protected function runPgQuery($sql, $dieOnFatalError = false, $skipDataFix = false)
    {
        $timeStart = explode(' ', microtime());
        $obj = new Result($this);
        if (!$this->connected) {
            $this->setError('0', "Database is not connected");
        }
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
            
            // Get column types to properly convert numeric values
            $columnTypes = [];
            $numFields = pg_num_fields($dbResource);
            for ($i = 0; $i < $numFields; $i++) {
                $fieldName = pg_field_name($dbResource, $i);
                $fieldType = pg_field_type($dbResource, $i);
                $columnTypes[$fieldName] = $fieldType;
            }
            $obj->columnTypes = $columnTypes;
            $resultArray = pg_fetch_array($dbResource, null, PGSQL_ASSOC);
            pg_result_seek($dbResource, 0);
            
            if ($resultArray) {
                foreach($resultArray as $key => $value) {
                    // Convert numeric types to their PHP equivalents
                    if (isset($columnTypes[$key]) && !$skipDataFix) {
                        switch ($columnTypes[$key]) {
                            case 'int4':
                            case 'int8':
                            case 'int2':
                            case 'integer':
                            case 'bigint':
                            case 'smallint':
                                $obj->fields[$key] = $value === null ? null : (int)$value;
                                break;
                            case 'float4':
                            case 'float8':
                            case 'numeric':
                            case 'decimal':
                            case 'real':
                            case 'double precision':
                                $obj->fields[$key] = $value === null ? null : (float)$value;
                                break;
                            case 'bool':
                            case 'boolean':
                                $obj->fields[$key] = $value === 't' ? true : ($value === 'f' ? false : $value);
                                break;
                            default:
                                $obj->fields[$key] = $value;
                        }
                    } else {
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
        $this->totalQueryTime += $queryTime;

        return($obj);
    }
    
    /**
     * Run a query on mysql
     * @param string $sql SQL query
     * @param bool $dieOnFatalError If set to true, the application will die
     * @param bool $skipDataFix If set to true, the data fix will be skipped
     * @return \Pramnos\Database\Result
     */
    protected function runMysqlQuery($sql, $dieOnFatalError = false, $skipDataFix = false)
    {
        $timeStart = explode(' ', microtime());
        $obj = new Result($this);
        if (!$this->connected) {
            $this->setError('0', "Database is not connected");
        }
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
                // Get field information without creating an intermediate array
                $fields = mysqli_fetch_fields($dbResource);
                $fieldTypes = [];
                foreach ($fields as $field) {
                    $fieldTypes[$field->name] = $field->type;
                }
                
                foreach($resultArray as $key => $value) {
                    // Convert numeric types to their PHP equivalents
                    if ($value !== null && isset($fieldTypes[$key]) && !$skipDataFix) {
                        $type = $fieldTypes[$key];
                        if ($type == MYSQLI_TYPE_TINY || $type == MYSQLI_TYPE_SHORT || 
                            $type == MYSQLI_TYPE_LONG || $type == MYSQLI_TYPE_INT24 || 
                            $type == MYSQLI_TYPE_LONGLONG) {
                            $obj->fields[$key] = (int)$value;
                        } else if ($type == MYSQLI_TYPE_FLOAT || $type == MYSQLI_TYPE_DOUBLE || 
                                    $type == MYSQLI_TYPE_DECIMAL || $type == MYSQLI_TYPE_NEWDECIMAL) {
                            $obj->fields[$key] = (float)$value;
                        } else {
                            $obj->fields[$key] = $value;
                        }
                    } else {
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
        $this->totalQueryTime += $queryTime;

        return($obj);
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
     * Stop logging and write final logs to files
     * Closes all log file handlers and writes summary information
     * @return void
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

    /**
     * Get columns from a table
     * @param string $tableName Table name
     * @param string $schema Optional schema override
     * @param bool $skipDataFix If true, don't transform data types
     * @return \Pramnos\Database\Result
     */
    public function getColumns($tableName, $schema = null, $skipDataFix = false)
    {
        // Use provided schema or fallback to the database schema
        $schemaToUse = $schema ?? $this->schema;
        
        if ($this->type == 'postgresql') {
            $sql = $this->prepareQuery(
                "SELECT column_name as \"Field\", data_type as \"Type\", character_maximum_length, is_nullable as \"Null\", column_default, "
                . "(SELECT col_description((SELECT oid FROM pg_class WHERE relname = '" . $tableName . "' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '" . $schemaToUse . "')), a.ordinal_position)) AS \"Comment\", "
                . "column_name in ( "
                . "    SELECT column_name "
                . "    FROM information_schema.table_constraints tc "
                . "    JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name) "
                . "    WHERE constraint_type = 'PRIMARY KEY' "
                . "    AND tc.table_name = '" . $tableName . "'"
                . "    AND tc.table_schema = '" . $schemaToUse . "'"
                . ") as \"PrimaryKey\", "
                . "EXISTS ( "
                . "    SELECT 1 "
                . "    FROM information_schema.table_constraints tc "
                . "    JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name) "
                . "    WHERE tc.constraint_type = 'FOREIGN KEY' "
                . "    AND tc.table_name = '" . $tableName . "'"
                . "    AND tc.table_schema = '" . $schemaToUse . "'"
                . "    AND ccu.column_name = a.column_name"
                . ") as \"ForeignKey\", "
                . "COALESCE((SELECT kcu2.table_name "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name AND kcu2.constraint_schema = rc.unique_constraint_schema "
                . "    WHERE kcu.table_schema = '" . $schemaToUse . "' "
                . "    AND kcu.table_name = '" . $tableName . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1), '') as \"ForeignTable\", "
                . "COALESCE((SELECT kcu2.table_schema "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name AND kcu2.constraint_schema = rc.unique_constraint_schema "
                . "    WHERE kcu.table_schema = '" . $schemaToUse . "' "
                . "    AND kcu.table_name = '" . $tableName . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1), '') as \"ForeignSchema\", "
                . "COALESCE((SELECT kcu2.column_name "
                . "    FROM information_schema.referential_constraints rc "
                . "    JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema "
                . "    JOIN information_schema.key_column_usage kcu2 ON kcu2.constraint_name = rc.unique_constraint_name AND kcu2.constraint_schema = rc.unique_constraint_schema "
                . "    WHERE kcu.table_schema = '" . $schemaToUse . "' "
                . "    AND kcu.table_name = '" . $tableName . "' "
                . "    AND kcu.column_name = a.column_name "
                . "    LIMIT 1), '') as \"ForeignColumn\" "
                . "FROM information_schema.columns a "
                . "WHERE table_name = '" . $tableName . "' "
                . "AND table_schema = '" . $schemaToUse . "'"
            );
        } else {
            // MySQL query
            $database_name = $this->database;
            $sql = $this->prepareQuery(
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
        
        // Use aggressive caching since table schemas rarely change
        // Cache for 1 hour (3600 seconds) with table-specific cache key
        $cacheKey = "schema_columns_{$tableName}";
        return $this->query($sql, true, 3600, $cacheKey, false, $skipDataFix);
    }

    /**
     * Decode EWKB (Extended Well-Known Binary) to a PHP array
     * @param string $hexWKB Hexadecimal representation of the EWKB
     * @return array Decoded geometry data
     */
    public function decodeEWKB($hexWKB) {
        // Convert hex to binary
        $wkb = hex2bin($hexWKB);
        
        // Check endianness (first byte)
        $endian = ord($wkb[0]);
        $little_endian = ($endian == 1);
        
        // Read geometry type (bytes 1-4)
        $type = unpack($little_endian ? 'V' : 'N', substr($wkb, 1, 4))[1];
        
        // Extract SRID if present (PostGIS EWKB format)
        $hasZ = ($type & 0x80000000) != 0;
        $hasM = ($type & 0x40000000) != 0;
        $hasSRID = ($type & 0x20000000) != 0;
        
        $baseType = $type & 0x1FFFFFFF;
        $offset = 5; // After endian and type
        
        $srid = null;
        if ($hasSRID) {
            $srid = unpack($little_endian ? 'V' : 'N', substr($wkb, $offset, 4))[1];
            $offset += 4;
        }
        
        // For POINT type
        if ($baseType == 1) {
            $x = unpack('d', $little_endian ? substr($wkb, $offset, 8) : strrev(substr($wkb, $offset, 8)))[1];
            $y = unpack('d', $little_endian ? substr($wkb, $offset + 8, 8) : strrev(substr($wkb, $offset + 8, 8)))[1];
            
            return [
                'type' => 'POINT',
                'coordinates' => [$x, $y],
                'srid' => $srid
            ];
        }
        
        
        return null;
    }

}
