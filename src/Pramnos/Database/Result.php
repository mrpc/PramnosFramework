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
    public $result;
    /**
     * Is the result cached
     * @var bool
     */
    public $isCached;
    public $eof = true;
    public $cursor;
    public $fields = array();
    public $resource = null;
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

    public function __construct()
    {
        $this->isCached = false;
    }

    /**
     * Move the cursor to the next result
     */
    public function MoveNext()
    {
        $this->cursor++;
        if ($this->isCached) {
            if ($this->cursor >= sizeof($this->result)) {
                $this->eof = true;
            } else {
                foreach ($this->result[$this->cursor] as $key=>$value) {
                    $this->fields[$key] = $value;
                }
            }
        } else {
            if ($this->resource instanceof PDOStatement) {
                $resultArray = $this->resource->fetch(PDO::FETCH_ASSOC);
            } else {
                $resultArray = @mysqli_fetch_array($this->resource);
            }
            if (!$resultArray) {
                $this->eof = true;
            } else {
                foreach ($resultArray as $key => $value) {
                    if (!preg_match('/^[0-9]/', $key)) {
                        $this->fields[$key] = $value;
                    }
                }
            }
        }
    }

    /**
     * How many results do we have?
     * @return int
     */
    public function RecordCount()
    {
        if ($this->resource instanceof PDOStatement) {
            return 0; //There is no such thing in PDO :D
        }
        return @mysqli_num_rows($this->resource);
    }

}
