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
     * @var \mysqli_result|bool|\PgSql\Result|resource
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
    public $cursor = 0;

    /**
     * End of file flag
     * @var bool
     */
     public $eof = true;
    /**
     * Column types
     * @var array
     */
     public $columnTypes = array();

    /**
     * Database result object
     * @param array $result
     */
    public function __construct(Database $database, $result = null)
    {
        $this->database = $database;
        $this->result = $result;
        $this->isCached = false;
        $this->cursor = -1;
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
        return null;
    }

    /**
     * Fetches all result rows as an associative array
     * @return array
     */
    public function fetchAll()
    {
        if ($this->isCached) {
            // For cached results, data types should already be properly restored
            return $this->result;
        } elseif ($this->database->type == 'postgresql'
            && \is_object($this->mysqlResult)) {
            \pg_result_seek($this->mysqlResult, 0);
            $results = \pg_fetch_all($this->mysqlResult, PGSQL_ASSOC);
            
            // Apply type conversion if column types are available
            if (!empty($this->columnTypes) && is_array($results)) {
                foreach ($results as $rowIndex => $row) {
                    foreach ($row as $columnName => $value) {
                        if ($value !== null && isset($this->columnTypes[$columnName])) {
                            $results[$rowIndex][$columnName] = $this->convertPostgresValue(
                                $value, 
                                $this->columnTypes[$columnName]
                            );
                        }
                    }
                }
            }
            
            return is_array($results) ? $results : array();
        } elseif (\is_object($this->mysqlResult)) {
            \mysqli_data_seek($this->mysqlResult, 0);
            $results = \mysqli_fetch_all($this->mysqlResult, MYSQLI_ASSOC);
            
            // Apply type conversion for MySQL results
            if (\is_array($results) && !empty($results)) {
                $fields = \mysqli_fetch_fields($this->mysqlResult);
                $fieldTypes = [];
                foreach ($fields as $field) {
                    $fieldTypes[$field->name] = $field->type;
                }
                
                foreach ($results as $rowIndex => $row) {
                    foreach ($row as $columnName => $value) {
                        if ($value !== null && isset($fieldTypes[$columnName])) {
                            $results[$rowIndex][$columnName] = $this->convertMysqlValue(
                                $value, 
                                $fieldTypes[$columnName]
                            );
                        }
                    }
                }
            }
            
            return is_array($results) ? $results : array();
        }
        
        return array();
    }

    /**
     * Fetch the next row for iteration loops.
     *
     * Unlike fetch(), this method correctly handles the pre-fetched state from
     * execute(): on the first call it returns the already-loaded row 0 without
     * re-querying the database (whose cursor is already at row 1). On
     * subsequent calls it delegates to the normal fetch() behaviour.
     *
     * Use this in while loops instead of fetch() when iterating all rows:
     *   while ($result->fetchNext()) { ... $result->fields ... }
     *
     * @param bool $skipDataFix
     * @return array|null
     */
    public function fetchNext($skipDataFix = false)
    {
        if ($this->cursor === -1 && !$this->isCached && !$this->eof) {
            // First iteration on a non-cached result from execute(): row 0 is
            // already in $this->fields and the DB cursor is at row 0 (reset by
            // execute()). Return the pre-fetched row and advance DB cursor to 1
            // so the next fetch() call reads row 1 rather than row 0 again.
            $this->cursor = 0;
            if ($this->database->type == 'postgresql'
                && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
                // If seek fails the result has exactly 1 row — mark EOF so the
                // next fetch() call returns null instead of re-reading row 0.
                if (!\pg_result_seek($this->mysqlResult, 1)) {
                    $this->eof = true;
                }
            } elseif (\is_object($this->mysqlResult) && $this->database->type == 'mysql') {
                if (!\mysqli_data_seek($this->mysqlResult, 1)) {
                    $this->eof = true;
                }
            }
            return $this->fields;
        }
        return $this->fetch($skipDataFix);
    }

    /**
     * Fetch a result row as an associative array
     * @param bool $skipDataFix If true, skips the data type conversion
     * @return array|null Returns an array of strings that corresponds to the
     * fetched row or NULL if there are no more rows in resultset.
     */
    public function fetch($skipDataFix = false)
    {
        if ($this->eof) {
            return null;
        }
        $this->cursor++;
        if ($this->isCached) {
            if ($this->cursor >= sizeof($this->result)) {
                $this->eof = true;
                return null;
            } else {
                foreach ($this->result[$this->cursor] as $key => $value) {
                    $this->fields[$key] = $value;
                }
                return $this->fields;
            }
        } elseif ($this->database->type == 'postgresql' && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {

            $pgFetchResult = \pg_fetch_array($this->mysqlResult, null, PGSQL_ASSOC);
            if ($pgFetchResult === false) {
                $this->eof = true;
                return null;
            }
            $this->fields = $pgFetchResult;
            if (\is_array($this->fields)) {

                foreach($this->fields as $key => $value) {
                    // Convert numeric types to their PHP equivalents
                    if (isset($this->columnTypes[$key]) && !$skipDataFix) {
                        switch ($this->columnTypes[$key]) {
                            case 'int4':
                            case 'int8':
                            case 'int2':
                            case 'integer':
                            case 'bigint':
                            case 'smallint':
                                $this->fields[$key] = $value === null ? null : (int)$value;
                                break;
                            case 'float4':
                            case 'float8':
                            case 'numeric':
                            case 'decimal':
                            case 'real':
                            case 'double precision':
                                $this->fields[$key] = $value === null ? null : (float)$value;
                                break;
                            case 'bool':
                            case 'boolean':
                                $this->fields[$key] = $value === 't' ? true : ($value === 'f' ? false : $value);
                                break;
                            default:
                                $this->fields[$key] = $value;
                        }
                    } else {
                        $this->fields[$key] = $value;
                    }
                }
            }
            return $this->fields;

        } elseif (\is_object($this->mysqlResult)) {
            $this->fields = \mysqli_fetch_array(
                $this->mysqlResult,
                MYSQLI_ASSOC
            );
        }
        
        // Set EOF flag for live results
        if (!$this->isCached) {
            if ($this->fields === null || $this->fields === false) {
                $this->eof = true;
                return null;
            }
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
            && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
            return \pg_last_oid($this->mysqlResult);
        } else {
            return \mysqli_insert_id($this->database->getConnectionLink());
        }
    }

    /**
     * Get number of affected rows in previous database operation
     * @return int
     */
    public function getAffectedRows()
    {
        if ($this->database->type == 'postgresql' 
            && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
            return \pg_affected_rows($this->mysqlResult);
        } elseif (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \mysqli_result) {
            return \mysqli_affected_rows($this->mysqlResult);
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
            && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
            return \pg_num_fields($this->mysqlResult);
        } elseif (\is_object($this->mysqlResult)) {
            return \mysqli_num_fields($this->mysqlResult);
        }

        return 0;
    }

    /**
     * Frees the memory associated with the result
     */
    public function free()
    {
        if ($this->database->type == 'postgresql' 
            && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
            \pg_free_result($this->mysqlResult);
        } elseif (\is_object($this->mysqlResult) 
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
            && (\is_resource($this->mysqlResult) || $this->mysqlResult instanceof \PgSql\Result)) {
                return \pg_num_rows($this->mysqlResult);
        } elseif (\is_object($this->mysqlResult) 
            && $this->database->type == 'mysql') {
            return \mysqli_num_rows($this->mysqlResult);
        }

        return $this->numRows;
    }


    /**
     * Convert PostgreSQL value to proper PHP type
     * @param mixed $value
     * @param string $pgType
     * @return mixed
     */
    protected function convertPostgresValue($value, $pgType)
    {
        if ($value === null) {
            return null;
        }
        
        switch ($pgType) {
            case 'int4':
            case 'int8':
            case 'int2':
            case 'integer':
            case 'bigint':
            case 'smallint':
                return (int)$value;
            case 'float4':
            case 'float8':
            case 'numeric':
            case 'decimal':
            case 'real':
            case 'double precision':
                return (float)$value;
            case 'bool':
            case 'boolean':
                return $value === 't' ? true : ($value === 'f' ? false : $value);
            default:
                return $value;
        }
    }

    /**
     * Convert MySQL value to proper PHP type
     * @param mixed $value
     * @param int $mysqlType
     * @return mixed
     */
    protected function convertMysqlValue($value, $mysqlType)
    {
        if ($value === null) {
            return null;
        }
        
        if ($mysqlType == MYSQLI_TYPE_TINY || $mysqlType == MYSQLI_TYPE_SHORT || 
            $mysqlType == MYSQLI_TYPE_LONG || $mysqlType == MYSQLI_TYPE_INT24 || 
            $mysqlType == MYSQLI_TYPE_LONGLONG) {
            return (int)$value;
        } else if ($mysqlType == MYSQLI_TYPE_FLOAT || $mysqlType == MYSQLI_TYPE_DOUBLE || 
                   $mysqlType == MYSQLI_TYPE_DECIMAL || $mysqlType == MYSQLI_TYPE_NEWDECIMAL) {
            return (float)$value;
        }
        
        return $value;
    }
}
