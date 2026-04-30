<?php

namespace Pramnos\Database;

/**
 * Fluent Schema Builder for DDL operations.
 * Supports MySQL, PostgreSQL, and TimescaleDB specific features.
 * 
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class SchemaBuilder
{
    /**
     * @var Database
     */
    protected $db;

    /**
     * @var DatabaseCapabilities
     */
    protected $capabilities;

    /**
     * Constructor
     * 
     * @param Database $db
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->capabilities = new DatabaseCapabilities($db);
    }

    /**
     * Create a new table.
     * 
     * @param string $table
     * @param \Closure $callback
     * @return bool
     */
    public function create($table, \Closure $callback)
    {
        // To be implemented with TableDefinition object
        return false;
    }

    /**
     * Drop a table.
     * 
     * @param string $table
     * @return bool
     */
    public function drop($table)
    {
        $table = str_replace('#PREFIX#', $this->db->prefix, $table);
        return $this->db->query("DROP TABLE IF EXISTS " . $table);
    }

    /**
     * Truncate a table.
     * 
     * @param string $table
     * @return bool
     */
    public function truncate($table)
    {
        $table = str_replace('#PREFIX#', $this->db->prefix, $table);
        if ($this->capabilities->isMySQL()) {
            return $this->db->query("TRUNCATE TABLE " . $table);
        } else {
            return $this->db->query("TRUNCATE " . $table . " RESTART IDENTITY CASCADE");
        }
    }

    /**
     * TimescaleDB: Create a hypertable from an existing table.
     * 
     * @param string $table
     * @param string $timeColumn
     * @param array $options
     * @return bool
     */
    public function createHypertable($table, $timeColumn, array $options = [])
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $table = str_replace('#PREFIX#', $this->db->prefix, $table);
        $sql = "SELECT create_hypertable('{$table}', '{$timeColumn}'";
        
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_string($value)) {
                $value = "'{$value}'";
            }
            $sql .= ", {$key} => {$value}";
        }
        
        $sql .= ")";
        
        return (bool)$this->db->query($sql);
    }

    /**
     * TimescaleDB: Add a retention policy.
     * 
     * @param string $table
     * @param string $dropAfter
     * @return bool
     */
    public function addRetentionPolicy($table, $dropAfter)
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $table = str_replace('#PREFIX#', $this->db->prefix, $table);
        $sql = "SELECT add_retention_policy('{$table}', INTERVAL '{$dropAfter}')";
        
        return (bool)$this->db->query($sql);
    }
}
