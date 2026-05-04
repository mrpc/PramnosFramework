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
     * Fetches the next result row into $this->fields and returns it.
     *
     * ## Cursor model
     *
     * query() and execute() both pre-load row 0 into $this->fields and rewind
     * the underlying DB cursor back to position 0, leaving $this->cursor at -1.
     * This means that when fetch() is first called, row 0 is already sitting in
     * $this->fields — there is no need to read it from the DB again.
     *
     * To avoid that redundant re-read, the first call (cursor === -1) is handled
     * as a special fast path:
     *   - $this->cursor is set to 0.
     *   - The DB cursor is advanced to position 1 so the next call reads row 1.
     *   - $this->fields (already populated with row 0) is returned immediately.
     *   - If the seek-to-1 fails, the result has exactly 1 row; $this->eof is set
     *     so the next call returns null.
     *
     * All subsequent calls do the normal thing: advance the DB cursor one step
     * and read the next row.
     *
     * ## skipDataFix exception
     *
     * When $skipDataFix = true the caller wants the raw string values that the DB
     * driver returns before any PHP type-casting. The pre-loaded $this->fields may
     * already contain type-converted values (integers, floats, booleans), so the
     * fast path is skipped and row 0 is re-read from the DB without conversion.
     *
     * ## Cached results
     *
     * For cached results ($this->isCached = true) the same fast path applies:
     * $this->fields already contains $this->result[0], so the first call just
     * advances the cursor and returns the already-loaded data.
     *
     * ## Usage
     *
     *   while ($result->fetch()) {
     *       doSomething($result->fields);
     *   }
     *
     * @param bool $skipDataFix When true, returns raw DB strings without PHP type-casting.
     * @return array|null The current row as an associative array, or null at EOF.
     */
    public function fetch($skipDataFix = false)
    {
        if ($this->eof) {
            return null;
        }

        if ($this->cursor === -1 && !$skipDataFix) {
            $this->cursor = 0;
            if (!$this->isCached) {
                if ($this->database->type === 'postgresql'
                    && ($this->mysqlResult instanceof \PgSql\Result || \is_resource($this->mysqlResult))) {
                    if (!\pg_result_seek($this->mysqlResult, 1)) {
                        $this->eof = true; // exactly 1 row; next call returns null
                    }
                } elseif (\is_object($this->mysqlResult)) {
                    if (!\mysqli_data_seek($this->mysqlResult, 1)) {
                        $this->eof = true; // exactly 1 row
                    }
                }
            }
            return $this->fields;
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
        } elseif ($this->database->type == 'mysql') {
            return \mysqli_affected_rows($this->database->getConnectionLink());
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
