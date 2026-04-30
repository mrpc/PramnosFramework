<?php

namespace Pramnos\Database;

/**
 * Handles detection and management of database engine capabilities.
 * 
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class DatabaseCapabilities
{
    const ENGINE_MYSQL = 'mysql';
    const ENGINE_POSTGRESQL = 'postgresql';
    const FEATURE_TIMESCALEDB = 'timescaledb';
    const FEATURE_JSON = 'json';
    const FEATURE_JSONB = 'jsonb';
    const FEATURE_FULLTEXT = 'fulltext';
    const FEATURE_SPATIAL = 'spatial';

    /**
     * @var Database
     */
    protected $db;

    /**
     * Cache for detected capabilities
     * @var array
     */
    protected $cache = [];

    /**
     * Constructor
     * 
     * @param Database $db
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Check if the database has a specific capability/feature.
     * 
     * @param string $feature
     * @return bool
     */
    public function has($feature)
    {
        if (isset($this->cache[$feature])) {
            return $this->cache[$feature];
        }

        switch ($feature) {
            case self::ENGINE_MYSQL:
                return $this->db->type === 'mysql';
            
            case self::ENGINE_POSTGRESQL:
                return $this->db->type === 'postgresql';
            
            case self::FEATURE_TIMESCALEDB:
                $this->cache[$feature] = $this->detectTimescaleDB();
                return $this->cache[$feature];

            case self::FEATURE_JSON:
                if ($this->isMySQL()) {
                    // MySQL 5.7.8+ supports JSON
                    return true; 
                }
                return $this->isPostgreSQL();

            case self::FEATURE_JSONB:
                return $this->isPostgreSQL();

            case self::FEATURE_FULLTEXT:
                return true; // Supported by both in modern versions

            case self::FEATURE_SPATIAL:
                return true; // Supported by both (GIS/PostGIS)
        }

        return false;
    }

    /**
     * Is the current database MySQL?
     * 
     * @return bool
     */
    public function isMySQL()
    {
        return $this->has(self::ENGINE_MYSQL);
    }

    /**
     * Is the current database PostgreSQL?
     * 
     * @return bool
     */
    public function isPostgreSQL()
    {
        return $this->has(self::ENGINE_POSTGRESQL);
    }

    /**
     * Has the database TimescaleDB extension?
     * 
     * @return bool
     */
    public function hasTimescaleDB()
    {
        return $this->has(self::FEATURE_TIMESCALEDB);
    }

    /**
     * Detect TimescaleDB extension on PostgreSQL
     * 
     * @return bool
     */
    protected function detectTimescaleDB()
    {
        if (!$this->isPostgreSQL()) {
            return false;
        }

        // Framework might already know via config
        if ($this->db->timescale) {
            return true;
        }

        try {
            $result = $this->db->query("SELECT 1 FROM pg_extension WHERE extname = 'timescaledb'");
            return $result && $result->numRows > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Execute a callback only if a capability is present, otherwise execute an optional fallback.
     * 
     * @param string $capability
     * @param callable $ifTrue
     * @param callable|null $ifFalse
     * @return mixed
     */
    public function ifCapable($capability, callable $ifTrue, callable $ifFalse = null)
    {
        if ($this->has($capability)) {
            return $ifTrue($this->db);
        }

        if ($ifFalse) {
            return $ifFalse($this->db);
        }

        return null;
    }
}
