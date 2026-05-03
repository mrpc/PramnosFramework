<?php

namespace Pramnos\Database;

/**
 * Runtime detection and management of database engine capabilities.
 *
 * Results are cached per database-connection object (keyed by spl_object_hash)
 * so repeated has() calls incur no extra queries.
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class DatabaseCapabilities
{
    // -------------------------------------------------------------------------
    // Engine constants
    // -------------------------------------------------------------------------

    const ENGINE_MYSQL      = 'mysql';
    const ENGINE_POSTGRESQL = 'postgresql';

    // -------------------------------------------------------------------------
    // Feature constants
    // -------------------------------------------------------------------------

    const TIMESCALEDB        = 'timescaledb';
    const JSONB              = 'jsonb';
    const MATERIALIZED_VIEWS = 'materialized_views';
    const ENUMS              = 'enums';
    const FEATURE_JSON       = 'json';
    const FEATURE_FULLTEXT   = 'fulltext';
    const FEATURE_SPATIAL    = 'spatial';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var Database */
    protected $db;

    /**
     * WeakMap<Database, array<string, bool>>
     *
     * Keyed by the live Database object — entries are automatically removed
     * when the object is garbage-collected, so no stale entries survive between
     * test cases or across long-lived processes that cycle through connections.
     *
     * @var \WeakMap|null
     */
    protected static $cache = null;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Core API
    // -------------------------------------------------------------------------

    /**
     * Returns true if the connected server supports the given capability.
     *
     * @param  string $feature  One of the ENGINE_* or feature constants.
     * @return bool
     */
    public function has($feature): bool
    {
        $cache = $this->getCache();

        if (!isset($cache[$this->db])) {
            $cache[$this->db] = [];
        }

        $bucket = $cache[$this->db];
        if (array_key_exists($feature, $bucket)) {
            return $bucket[$feature];
        }

        $result            = $this->detect($feature);
        $bucket[$feature]  = $result;
        $cache[$this->db]  = $bucket;

        return $result;
    }

    protected function getCache(): \WeakMap
    {
        if (self::$cache === null) {
            self::$cache = new \WeakMap();
        }
        return self::$cache;
    }

    // -------------------------------------------------------------------------
    // Convenience predicates
    // -------------------------------------------------------------------------

    public function isMySQL(): bool
    {
        return $this->has(self::ENGINE_MYSQL);
    }

    public function isPostgreSQL(): bool
    {
        return $this->has(self::ENGINE_POSTGRESQL);
    }

    public function hasTimescaleDB(): bool
    {
        return $this->has(self::TIMESCALEDB);
    }

    public function hasMaterializedViews(): bool
    {
        return $this->has(self::MATERIALIZED_VIEWS);
    }

    public function hasEnums(): bool
    {
        return $this->has(self::ENUMS);
    }

    // -------------------------------------------------------------------------
    // Conditional execution
    // -------------------------------------------------------------------------

    /**
     * Execute $ifTrue when the capability is present, $ifFalse otherwise.
     * Both callables receive the Database instance as their sole argument.
     *
     * @param  string        $capability
     * @param  callable      $ifTrue
     * @param  callable|null $ifFalse
     * @return mixed
     */
    public function ifCapable($capability, callable $ifTrue, ?callable $ifFalse = null)
    {
        if ($this->has($capability)) {
            return $ifTrue($this->db);
        }

        if ($ifFalse !== null) {
            return $ifFalse($this->db);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Detection logic
    // -------------------------------------------------------------------------

    protected function detect(string $feature): bool
    {
        switch ($feature) {
            case self::ENGINE_MYSQL:
                return $this->db->type === 'mysql';

            case self::ENGINE_POSTGRESQL:
                return $this->db->type === 'postgresql';

            case self::TIMESCALEDB:
                return $this->detectTimescaleDB();

            case self::JSONB:
                return $this->db->type === 'postgresql';

            case self::FEATURE_JSON:
                return true; // MySQL 5.7.8+ and all supported PG versions

            case self::FEATURE_FULLTEXT:
                return true; // Both MySQL and PostgreSQL support full-text search

            case self::FEATURE_SPATIAL:
                return true; // Both support spatial (GIS / PostGIS)

            case self::MATERIALIZED_VIEWS:
                return $this->db->type === 'postgresql';

            case self::ENUMS:
                // PostgreSQL supports named ENUM types via CREATE TYPE ... AS ENUM
                return $this->db->type === 'postgresql';
        }

        return false;
    }

    protected function detectTimescaleDB(): bool
    {
        if ($this->db->type !== 'postgresql') {
            return false;
        }

        // Framework config shortcut
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
}
