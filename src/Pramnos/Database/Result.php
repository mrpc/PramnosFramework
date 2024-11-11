<?php

namespace Pramnos\Database;

/**
 * Database result object
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 */
class Result
{
    /**
     * Number of result rows
     * @var int
     */
    public $numRows;
    /**
     * Cached results
     * @var array
     */
    public $result;
    /**
     * Is the result cached
     * @var bool
     */
    public $isCached;
    /**
     * Fields array
     * @var array
     */
    public $fields = array();
    /**
     * Database Result Object
     * @var \mysqli_result|bool|\PgSql\Result
     */
    public $mysqlResult = null;
    /**
     * Cache time to live (in seconds)
     * @var int
     */
    public $cacheTtl = 60;
    /**
     * Cache Category
     * @var string
     */
    public $cacheCategory = '';
    /**
     * Database reference
     * @var Database
     */
    protected $database;
    /**
     * Current cursor position in case of cached results
     * @var int
     */
    protected $cursor = 0;

     /**
     * End of file flag
     * @var bool
     */
     public $eof = true;


    /**
     * Database result object
     * @param array $result
     */
    public function __construct(Database $database, $result = null)
    {
        $this->database = $database;
        $this->result = $result;
        $this->isCached = false;
    }

    /**
     * Magic method to return a result field
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name];
        }
    }

    /**
     * Fetches all result rows as an associative array
     * @return array
     */
    public function fetchAll()
    {
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
            return pg_fetch_all($this->mysqlResult, PGSQL_ASSOC);
        } elseif (is_object($this->mysqlResult)) {
            return mysqli_fetch_all($this->mysqlResult, MYSQLI_ASSOC);
        }
    }

    /**
     * Fetch a result row as an associative array
     * @return array|null Returns an array of strings that corresponds to the
     * fetched row or NULL if there are no more rows in resultset.
     */
    public function fetch()
    {
        $this->cursor++;
        if ($this->isCached) {
            if ($this->cursor >= sizeof($this->result)) {
                $this->eof = true;
            } else {
                foreach ($this->result[$this->cursor] as $key => $value) {
                    $this->fields[$key] = $value;
                }
            }
        } elseif ($this->database->type == 'postgresql' && is_resource($this->mysqlResult)) {
            $this->fields = pg_fetch_array(
                $this->mysqlResult,
                null,
                MYSQLI_ASSOC
            );
        } elseif (is_object($this->mysqlResult)) {
            $this->fields = mysqli_fetch_array(
                $this->mysqlResult,
                MYSQLI_ASSOC
            );
        }
        return $this->fields;
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
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
            return pg_last_oid($this->mysqlResult);
        } else {
            return mysqli_insert_id($this->database->getConnectionLink());
        }
    }

    /**
     * Get number of affected rows in previous database operation
     * @return int
     */
    public function getAffectedRows()
    {
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
            return pg_affected_rows($this->mysqlResult);
        } elseif (is_resource($this->mysqlResult)) {
            return mysqli_affected_rows($this->mysqlResult);
        }

        return 0;
    }

    /**
     * Get number of fields in result.
     * @return int
     */
    public function getNumFields()
    {
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
            return pg_num_fields($this->mysqlResult);
        } elseif (is_object($this->mysqlResult)) {
            return mysqli_num_fields($this->mysqlResult);
        }

        return 0;
    }

    /**
     * Frees the memory associated with the result
     */
    public function free()
    {
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
            pg_free_result($this->mysqlResult);
        } elseif (is_object($this->mysqlResult) 
            && $this->database->type == 'mysql') {
            $this->mysqlResult->free();
            $this->mysqlResult = null;
        }
    }

    /**
     * How many results do we have?
     * @return int
     */
    public function getNumRows()
    {
        if ($this->database->type == 'postgresql' 
            && is_resource($this->mysqlResult)) {
                return pg_num_rows($this->mysqlResult);
        } elseif (is_object($this->mysqlResult) 
            && $this->database->type == 'mysql') {
            return mysqli_num_rows($this->mysqlResult);
        }

        return $this->numRows;
    }

    /**
     * free the memory
     */
    public function __destruct()
    {
        $this->free();
    }
}
