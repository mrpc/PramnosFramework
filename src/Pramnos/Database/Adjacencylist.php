<?php

namespace Pramnos\Database;

/**
 * Adjacency List implementation class
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Adjacencylist extends \Pramnos\Framework\Base
{

    public $table = "";
    public $idField = "";
    public $parentField = "";
    public $titleField = "";
    public $separator = " » ";
    public $extraWhere='';
    protected $database;

    /**
     * Adjacency List implementation class constructor
     * @param string $table Database Table
     * @param string $idField Item ID field of the table
     * @param string $parentField Parent Field of the table
     * @param string $titleField Title fields of the table
     */
    public function __construct(Database $database, $table = "", $idField = "",
            $parentField = "", $titleField = "")
    {
        $this->database = $database;
        $this->table = $table;
        $this->idField = $idField;
        $this->parentField = $parentField;
        $this->titleField = $titleField;
        parent::__construct();
    }

    /**
     * Returns the extraWhere condition stripped of any leading WHERE keyword,
     * ready to pass to QueryBuilder::whereRaw().
     */
    private function extraWhereRaw(): string
    {
        return trim(str_ireplace('where', '', $this->extraWhere));
    }

    /**
     * Returns an array with all items, useful for drop down lists
     * All the items will have their entire path (item >> subitem >> subitem)
     * @param  int  $parent Where to begin (display everything under this node)
     * @param  int  itemId if looking for a specific node (overides $parent)
     * @return array [itemid]=>[name]
     */
    function getArray($parent = NULL, $itemId = NULL)
    {
        $items = array();

        $qb = $this->database->queryBuilder()->from($this->table)->select('*');

        if ($itemId !== NULL) {
            $qb->where($this->idField, $itemId);
        } elseif ($parent !== NULL) {
            $qb->where($this->parentField, $parent);
        }

        if ($this->extraWhere != '') {
            $qb->whereRaw($this->extraWhereRaw());
        }

        $result = $qb->get();

        while ($result->fetch()) {
            if ((int) $result->fields[$this->parentField] == 0) {

                $items[$result->fields[$this->idField]]
                    = $result->fields[$this->titleField];
            } else {
                $underid = $result->fields[$this->parentField];
                $topicname = $result->fields[$this->titleField];
                //Loops until we have the top level topic
                while ($underid <> 0) {
                    $result2 = $this->database->queryBuilder()
                        ->from($this->table)
                        ->select('*')
                        ->where($this->idField, (int) $underid)
                        ->get();
                    while ($result2->fetch()) {
                        $topicname = $result2->fields[$this->titleField]
                            . $this->separator . $topicname;
                        $underid = $result2->fields[$this->parentField];
                    }
                }
                $items[$result->fields[$this->idField]] = $topicname;
            }

        }
        asort($items); //sorts the topic names for more usability
        return $items;
    }

    /**
     * Returns the full path of a node as string
     * @param int $itemId
     * @return string
     */
    public function getPath($itemId)
    {
        $array = $this->getArray(NULL, $itemId);
        $keys = array_keys($array);
        if (isset($keys[0])){
            return $array[$keys[0]];
        } else {
            return NULL;
        }
    }

    /**
     * Return an array representing the whole path of an item
     * @param int $itemId ID of the item
     * @param array $array An array of all following items
     * @return array An array of items
     */
    public function getPathAsArray($itemId, array $array = array())
    {
        $result = $this->database->queryBuilder()
            ->from($this->table)
            ->select('*')
            ->where($this->idField, (int) $itemId)
            ->get();

        if (isset($result->fields[$this->parentField])
                && (int)$result->fields[$this->parentField] != 0) {
            $array = $this->getPathAsArray(
                $result->fields[$this->parentField], $array
            );
        }
        $item = new \stdClass();
        foreach ($result->fields as $key => $value) {
            $item->$key = $value;
        }
        $array[] = $item;
        return $array;
    }

}
