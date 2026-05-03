<?php

namespace Pramnos\Database;

use Pramnos\Database\Grammar\SchemaGrammarInterface;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;
use Pramnos\Database\Grammar\TimescaleDBSchemaGrammar;

/**
 * Fluent DDL builder for table, index, view, and TimescaleDB operations.
 *
 * Entry point: $db->schema() or $db->schemaBuilder()
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class SchemaBuilder
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var Database */
    protected $db;

    /** @var DatabaseCapabilities */
    protected $capabilities;

    /** @var SchemaGrammarInterface|null */
    protected $grammar = null;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(Database $db)
    {
        $this->db           = $db;
        $this->capabilities = new DatabaseCapabilities($db);
    }

    // -------------------------------------------------------------------------
    // Grammar
    // -------------------------------------------------------------------------

    public function getGrammar(): SchemaGrammarInterface
    {
        if ($this->grammar === null) {
            $this->grammar = $this->makeGrammar();
        }
        return $this->grammar;
    }

    public function setGrammar(SchemaGrammarInterface $grammar): static
    {
        $this->grammar = $grammar;
        return $this;
    }

    protected function makeGrammar(): SchemaGrammarInterface
    {
        if ($this->db->type === 'postgresql') {
            return $this->db->timescale
                ? new TimescaleDBSchemaGrammar()
                : new PostgreSQLSchemaGrammar();
        }
        return new MySQLSchemaGrammar();
    }

    // =========================================================================
    // Table DDL
    // =========================================================================

    /**
     * Create a new table.
     *
     * @param  string   $table    Table name (supports #PREFIX# token).
     * @param  \Closure $callback Receives a Blueprint to define columns/indexes.
     * @return void
     */
    public function createTable(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table, 'create');
        $callback($blueprint);

        $resolved = $this->resolveTable($table);
        foreach ($this->getGrammar()->compileCreate($blueprint, $resolved) as $sql) {
            $this->db->query($sql);
        }
    }

    /** @deprecated Use createTable() */
    public function create(string $table, \Closure $callback): void
    {
        $this->createTable($table, $callback);
    }

    /**
     * Modify an existing table.
     *
     * @param  string   $table
     * @param  \Closure $callback Receives a Blueprint for ALTER operations.
     * @return void
     */
    public function alterTable(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table, 'alter');
        $callback($blueprint);

        $resolved = $this->resolveTable($table);
        foreach ($this->getGrammar()->compileAlter($blueprint, $resolved) as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * Drop a table (error if it does not exist).
     *
     * @param  string $table
     * @return void
     */
    public function dropTable(string $table): void
    {
        $resolved = $this->resolveTable($table);
        $this->db->query($this->getGrammar()->compileDrop($resolved));
    }

    /**
     * Drop a table if it exists (no-op otherwise).
     *
     * @param  string $table
     * @return void
     */
    public function dropTableIfExists(string $table): void
    {
        $resolved = $this->resolveTable($table);
        $this->db->query($this->getGrammar()->compileDropIfExists($resolved));
    }

    /** @deprecated Use dropTableIfExists() */
    public function drop(string $table): void
    {
        $this->dropTableIfExists($table);
    }

    /**
     * Rename a table.
     *
     * @param  string $from
     * @param  string $to
     * @return void
     */
    public function renameTable(string $from, string $to): void
    {
        $this->db->query(
            $this->getGrammar()->compileRename(
                $this->resolveTable($from),
                $this->resolveTable($to)
            )
        );
    }

    /**
     * Truncate a table (remove all rows, reset sequences).
     *
     * @param  string $table
     * @return void
     */
    public function truncate(string $table): void
    {
        $resolved = $this->resolveTable($table);
        if ($this->capabilities->isMySQL()) {
            $this->db->query('TRUNCATE TABLE `' . $resolved . '`');
        } else {
            $this->db->query('TRUNCATE "' . $resolved . '" RESTART IDENTITY CASCADE');
        }
    }

    // =========================================================================
    // Introspection
    // =========================================================================

    /**
     * Returns true if the table exists in the database.
     *
     * @param  string      $table
     * @param  string|null $schema  Schema/database name (optional).
     * @return bool
     */
    public function hasTable(string $table, ?string $schema = null): bool
    {
        $resolved = $this->resolveTable($table);
        $schema   = $schema ?? ($this->db->schema ?? '');
        $sql      = $this->getGrammar()->compileHasTable($resolved, $schema);
        $result   = $this->db->query($sql);
        return $result && $result->numRows > 0;
    }

    /**
     * Returns true if the column exists in the given table.
     *
     * @param  string      $table
     * @param  string      $column
     * @param  string|null $schema
     * @return bool
     */
    public function hasColumn(string $table, string $column, ?string $schema = null): bool
    {
        $resolved = $this->resolveTable($table);
        $schema   = $schema ?? ($this->db->schema ?? '');
        $sql      = $this->getGrammar()->compileHasColumn($resolved, $column, $schema);
        $result   = $this->db->query($sql);
        return $result && $result->numRows > 0;
    }

    // =========================================================================
    // Index DDL
    // =========================================================================

    /**
     * Create a non-unique index.
     *
     * @param  string          $table
     * @param  string          $name
     * @param  string|string[] $columns
     * @return void
     */
    public function createIndex(string $table, string $name, $columns): void
    {
        $this->db->query(
            $this->getGrammar()->compileCreateIndex(
                $this->resolveTable($table),
                $name,
                (array)$columns,
                false
            )
        );
    }

    /**
     * Create a unique index.
     *
     * @param  string          $table
     * @param  string          $name
     * @param  string|string[] $columns
     * @return void
     */
    public function createUniqueIndex(string $table, string $name, $columns): void
    {
        $this->db->query(
            $this->getGrammar()->compileCreateIndex(
                $this->resolveTable($table),
                $name,
                (array)$columns,
                true
            )
        );
    }

    /**
     * Drop an index by name.
     *
     * @param  string $table
     * @param  string $name
     * @return void
     */
    public function dropIndex(string $table, string $name): void
    {
        $this->db->query(
            $this->getGrammar()->compileDropIndex($this->resolveTable($table), $name)
        );
    }

    // =========================================================================
    // View DDL
    // =========================================================================

    /**
     * Create a view.
     *
     * @param  string $name  View name (supports #PREFIX#).
     * @param  string $sql   The SELECT statement for the view body.
     * @return void
     */
    public function createView(string $name, string $sql): void
    {
        $resolved = $this->resolveTable($name);
        $this->db->query($this->getGrammar()->compileCreateView($resolved, $sql, false));
    }

    /**
     * Create or replace a view (CREATE OR REPLACE VIEW).
     *
     * @param  string $name
     * @param  string $sql
     * @return void
     */
    public function createOrReplaceView(string $name, string $sql): void
    {
        $resolved = $this->resolveTable($name);
        $this->db->query($this->getGrammar()->compileCreateView($resolved, $sql, true));
    }

    /**
     * Drop a view.
     *
     * @param  string $name
     * @param  bool   $ifExists
     * @return void
     */
    public function dropView(string $name, bool $ifExists = true): void
    {
        $resolved = $this->resolveTable($name);
        $this->db->query($this->getGrammar()->compileDropView($resolved, $ifExists));
    }

    // =========================================================================
    // Materialized view DDL (PostgreSQL / TimescaleDB)
    // On MySQL, createMaterializedView() falls back to a regular VIEW.
    // =========================================================================

    /**
     * Create a materialized view.
     *
     * PostgreSQL/TimescaleDB: CREATE MATERIALIZED VIEW …
     * MySQL: falls back to CREATE VIEW (data is not materialised).
     *
     * @param  string $name
     * @param  string $sql
     * @return void
     */
    public function createMaterializedView(string $name, string $sql): void
    {
        $resolved = $this->resolveTable($name);
        $this->db->query($this->getGrammar()->compileCreateMaterializedView($resolved, $sql));
    }

    /**
     * Refresh a materialized view.
     *
     * @param  string $name
     * @param  bool   $concurrently  PostgreSQL: allow concurrent reads during refresh.
     * @return void
     */
    public function refreshMaterializedView(string $name, bool $concurrently = false): void
    {
        $resolved = $this->resolveTable($name);
        $sql = $this->getGrammar()->compileRefreshMaterializedView($resolved, $concurrently);
        if ($sql !== '') {
            $this->db->query($sql);
        }
    }

    /**
     * Drop a materialized view.
     *
     * @param  string $name
     * @param  bool   $ifExists
     * @return void
     */
    public function dropMaterializedView(string $name, bool $ifExists = true): void
    {
        $resolved = $this->resolveTable($name);
        $this->db->query($this->getGrammar()->compileDropMaterializedView($resolved, $ifExists));
    }

    // =========================================================================
    // TimescaleDB hypertable operations
    // =========================================================================

    /**
     * Convert a regular table into a TimescaleDB hypertable.
     * Silent no-op on non-TimescaleDB backends.
     *
     * @param  string $table
     * @param  string $timeColumn  Time-partitioning column.
     * @param  array  $options     e.g. ['chunk_time_interval' => '7 days']
     * @return bool
     */
    public function createHypertable(string $table, string $timeColumn, array $options = []): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);
        $sql = "SELECT create_hypertable('{$resolved}', '{$timeColumn}'";

        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_string($value)) {
                $value = "'{$value}'";
            }
            $sql .= ", {$key} => {$value}";
        }

        $sql .= ')';
        return (bool)$this->db->query($sql);
    }

    /**
     * Add a TimescaleDB space dimension (hash-partitioning).
     * Silent no-op on non-TimescaleDB backends.
     *
     * @param  string $table
     * @param  string $column
     * @param  int    $partitions
     * @return bool
     */
    public function addSpaceDimension(string $table, string $column, int $partitions = 4): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);
        return (bool)$this->db->query(
            "SELECT add_dimension('{$resolved}', '{$column}', number_partitions => {$partitions})"
        );
    }

    /**
     * Enable column compression on a hypertable.
     * Silent no-op on non-TimescaleDB backends.
     *
     * @param  string      $table
     * @param  array       $options  e.g. ['segmentby' => 'device_id', 'orderby' => 'time DESC']
     * @return bool
     */
    public function enableCompression(string $table, array $options = []): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);
        $parts = ["timescaledb.compress"];
        foreach ($options as $key => $value) {
            $parts[] = "timescaledb.compress_{$key} = '{$value}'";
        }
        return (bool)$this->db->query(
            "ALTER TABLE \"{$resolved}\" SET (" . implode(', ', $parts) . ')'
        );
    }

    /**
     * Add a TimescaleDB compression policy (automatically compress chunks older than $after).
     * Silent no-op on non-TimescaleDB backends.
     *
     * @param  string $table
     * @param  string $compressAfter  e.g. '7 days'
     * @return bool
     */
    public function addCompressionPolicy(string $table, string $compressAfter): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);
        return (bool)$this->db->query(
            "SELECT add_compression_policy('{$resolved}', INTERVAL '{$compressAfter}')"
        );
    }

    /**
     * Add a TimescaleDB data-retention policy (automatically drop chunks older than $dropAfter).
     * Silent no-op on non-TimescaleDB backends.
     *
     * @param  string $table
     * @param  string $dropAfter  e.g. '90 days'
     * @return bool
     */
    public function addRetentionPolicy(string $table, string $dropAfter): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);
        return (bool)$this->db->query(
            "SELECT add_retention_policy('{$resolved}', INTERVAL '{$dropAfter}')"
        );
    }

    /**
     * Create a TimescaleDB continuous aggregate.
     * On plain PostgreSQL: falls back to a regular MATERIALIZED VIEW.
     * On MySQL: falls back to a regular VIEW (data is not materialised).
     *
     * @param  string $name
     * @param  string $sql      The SELECT body (must use time_bucket() on TimescaleDB).
     * @param  array  $options  TimescaleDB-specific WITH options.
     * @return void
     */
    public function createContinuousAggregate(string $name, string $sql, array $options = []): void
    {
        $resolved = $this->resolveTable($name);

        if ($this->capabilities->hasTimescaleDB()) {
            $withOpts = array_merge(['timescaledb.continuous' => true], $options);
            $withParts = [];
            foreach ($withOpts as $k => $v) {
                $withParts[] = $k . ' = ' . ($v === true ? 'true' : ($v === false ? 'false' : "'{$v}'"));
            }
            $this->db->query(
                "CREATE MATERIALIZED VIEW {$resolved} WITH (" . implode(', ', $withParts) . ") AS {$sql}"
            );
        } elseif ($this->capabilities->isPostgreSQL()) {
            $this->db->query("CREATE MATERIALIZED VIEW {$resolved} AS {$sql}");
        } else {
            $this->db->query("CREATE VIEW {$resolved} AS {$sql}");
        }
    }

    // =========================================================================
    // Capability-conditional DDL
    // =========================================================================

    /**
     * Execute $callback only when the database supports $capability.
     * The SchemaBuilder instance is passed to the callback.
     *
     * @param  string        $capability  A DatabaseCapabilities constant.
     * @param  callable      $callback    function(SchemaBuilder $schema): void
     * @param  callable|null $fallback    Executed when capability is absent.
     * @return mixed
     */
    public function ifCapable(string $capability, callable $callback, ?callable $fallback = null)
    {
        if ($this->capabilities->has($capability)) {
            return $callback($this);
        }

        if ($fallback !== null) {
            return $fallback($this);
        }

        return null;
    }

    // =========================================================================
    // Trigger DDL
    // =========================================================================

    /**
     * Create a trigger on a table.
     *
     * MySQL body example:   "BEGIN ... END"
     * PostgreSQL body:      "EXECUTE FUNCTION my_fn()"  (function must exist separately)
     *
     * @param  string $name    Trigger name
     * @param  string $table   Table name (supports #PREFIX#)
     * @param  string $timing  BEFORE | AFTER | INSTEAD OF
     * @param  string $event   INSERT | UPDATE | DELETE
     * @param  string $body    Trigger body (MySQL: BEGIN…END; PG: EXECUTE FUNCTION fn())
     * @param  string $forEach FOR EACH ROW | FOR EACH STATEMENT
     * @return void
     */
    public function createTrigger(
        string $name,
        string $table,
        string $timing,
        string $event,
        string $body,
        string $forEach = 'ROW'
    ): void {
        $resolved = $this->resolveTable($table);
        $sql = $this->getGrammar()->compileCreateTrigger($name, $resolved, $timing, $event, $body, $forEach);
        $this->db->query($sql);
    }

    /**
     * Drop a trigger.
     *
     * @param  string $name     Trigger name
     * @param  string $table    Table the trigger belongs to (needed for PostgreSQL DROP TRIGGER … ON …)
     * @param  bool   $ifExists
     * @return void
     */
    public function dropTrigger(string $name, string $table, bool $ifExists = true): void
    {
        $resolved = $this->resolveTable($table);
        $sql = $this->getGrammar()->compileDropTrigger($name, $resolved, $ifExists);
        $this->db->query($sql);
    }

    // =========================================================================
    // Sequence DDL (PostgreSQL only; silent no-op on MySQL)
    // =========================================================================

    /**
     * Create a named sequence (PostgreSQL only).
     * On MySQL the call is silently ignored — no exception.
     *
     * @param  string   $name
     * @param  int      $start
     * @param  int      $increment
     * @param  int|null $minValue
     * @param  int|null $maxValue
     * @param  bool     $cycle
     * @return void
     */
    public function createSequence(
        string $name,
        int $start = 1,
        int $increment = 1,
        ?int $minValue = null,
        ?int $maxValue = null,
        bool $cycle = false
    ): void {
        $sql = $this->getGrammar()->compileCreateSequence($name, $start, $increment, $minValue, $maxValue, $cycle);
        if ($sql !== '') {
            $this->db->query($sql);
        }
    }

    /**
     * Drop a sequence (PostgreSQL only).
     * On MySQL the call is silently ignored.
     *
     * @param  string $name
     * @param  bool   $ifExists
     * @return void
     */
    public function dropSequence(string $name, bool $ifExists = true): void
    {
        $sql = $this->getGrammar()->compileDropSequence($name, $ifExists);
        if ($sql !== '') {
            $this->db->query($sql);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve the table name: replace #PREFIX# with the configured table prefix.
     */
    protected function resolveTable(string $table): string
    {
        return str_replace('#PREFIX#', $this->db->prefix ?? '', $table);
    }
}
