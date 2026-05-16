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

    /**
     * Schema name override set by withSchema().  When non-null this takes
     * precedence over $db->schema in resolveSchema() and resolveTable().
     * @var string|null
     */
    protected ?string $overrideSchema = null;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(Database $db)
    {
        $this->db           = $db;
        $this->capabilities = new DatabaseCapabilities($db);
    }

    /**
     * Return a copy of this builder scoped to a specific schema.
     *
     * All DDL methods (create, drop, alter, …) will use the given schema as
     * the default — tables passed as plain names (no dot) are automatically
     * prefixed with the schema.  Useful in migration classes:
     *
     *   $this->schema('authserver')->create('roles', function ($t) { … });
     *
     * On MySQL the schema becomes a table-name prefix (schema_table).
     * On PostgreSQL the schema is used as the PG schema qualifier.
     *
     * @param  string $schema Schema / database name.
     * @return static         A new SchemaBuilder instance scoped to that schema.
     */
    public function withSchema(string $schema): static
    {
        $clone = clone $this;
        $clone->overrideSchema = $schema !== '' ? $schema : null;
        return $clone;
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

    public function getCapabilities(): DatabaseCapabilities
    {
        return $this->capabilities;
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
        // Disable FK checks on MySQL during CREATE TABLE so that pre-existing broken
        // FK constraints (dangling references from previous test teardowns) do not
        // prevent the new table from being created. The FK constraints defined in
        // this table's own Blueprint are still written to the schema and will be
        // enforced at DML time.
        $mysql = $this->capabilities->isMySQL();
        if ($mysql) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($this->getGrammar()->compileCreate($blueprint, $resolved) as $sql) {
            $this->db->query($sql);
        }
        if ($mysql) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @deprecated Use createTable() */
    public function create(string $table, \Closure $callback): void
    {
        $this->createTable($table, $callback);
    }

    /** Fluent alias for alterTable() — matches Laravel's Schema::table() API. */
    public function table(string $table, \Closure $callback): void
    {
        $this->alterTable($table, $callback);
    }

    /** Fluent alias for dropTableIfExists() — matches Laravel's Schema::dropIfExists() API. */
    public function dropIfExists(string $table): void
    {
        $this->dropTableIfExists($table);
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
        // Disable FK checks on MySQL so that a table can be dropped even when other
        // tables have FK constraints pointing to it — unconditional "drop if exists"
        // semantics require this on MySQL. Re-enable immediately after.
        $mysql = $this->capabilities->isMySQL();
        if ($mysql) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        }
        $this->db->query($this->getGrammar()->compileDropIfExists($resolved));
        if ($mysql) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
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
        $schema   = $schema ?? $this->resolveSchema();
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
        $schema   = $schema ?? $this->resolveSchema();
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

        // Options that represent a time duration must be passed as INTERVAL literals.
        // Plain string literals have type 'unknown' in PostgreSQL and are rejected
        // by create_hypertable's polymorphic INTERVAL parameter.
        $intervalOptions = ['chunk_time_interval', 'compress_after', 'drop_after'];

        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_string($value)) {
                $value = in_array($key, $intervalOptions, true)
                    ? "INTERVAL '{$value}'"
                    : "'{$value}'";
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
        $quoted   = $this->getGrammar()->quoteTable($resolved);
        $parts = ["timescaledb.compress"];
        foreach ($options as $key => $value) {
            $parts[] = "timescaledb.compress_{$key} = '{$value}'";
        }
        return (bool)$this->db->query(
            "ALTER TABLE {$quoted} SET (" . implode(', ', $parts) . ')'
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
     * Add a data-retention policy.
     *
     * On TimescaleDB: registers a native chunk-drop policy via add_retention_policy().
     * On MySQL/plain PostgreSQL: registers a software-emulated `retention` policy in
     * `pramnos.framework_policies`, executed by the PolicyEngine daemon.
     *
     * @param  string $table
     * @param  string $dropAfter   Interval string, e.g. '90 days'.
     * @param  string $timeColumn  Column used for age comparison (default: created_at).
     * @return bool
     */
    public function addRetentionPolicy(string $table, string $dropAfter, string $timeColumn = 'created_at'): bool
    {
        if ($this->capabilities->hasTimescaleDB()) {
            $resolved = $this->resolveTable($table);
            return (bool)$this->db->query(
                "SELECT add_retention_policy('{$resolved}', INTERVAL '{$dropAfter}')"
            );
        }

        $policyTable = $this->resolveTable('pramnos.framework_policies');
        $qb          = $this->db->queryBuilder();
        $qb->table($policyTable)->insert([
            'policy_type' => 'retention',
            'target'      => $table,
            'config'      => json_encode(['interval' => $dropAfter, 'time_column' => $timeColumn]),
            'enabled'     => 1,
            'created_at'  => $qb->raw('NOW()'),
        ]);

        return (int) $this->db->getInsertId() > 0;
    }

    /**
     * Add a continuous-aggregate refresh policy.
     *
     * On TimescaleDB: registers a native policy via add_continuous_aggregate_policy().
     * On MySQL/plain PostgreSQL: registers a software-emulated `aggregate_refresh` policy
     * in `pramnos.framework_policies`, executed by the PolicyEngine daemon.
     *
     * @param  string $view              The aggregate / materialized-view name.
     * @param  string $startOffset       How far back to refresh, e.g. '2 hours'.
     * @param  string $endOffset         How close to now to refresh, e.g. '1 hour'.
     * @param  string $scheduleInterval  How often to run, e.g. '1 hour'.
     * @return bool
     */
    public function addContinuousAggregatePolicy(
        string $view,
        string $startOffset,
        string $endOffset,
        string $scheduleInterval
    ): bool {
        if ($this->capabilities->hasTimescaleDB()) {
            $resolved = $this->resolveTable($view);
            return (bool)$this->db->query(
                "SELECT add_continuous_aggregate_policy('{$resolved}'," .
                " start_offset => INTERVAL '{$startOffset}'," .
                " end_offset => INTERVAL '{$endOffset}'," .
                " schedule_interval => INTERVAL '{$scheduleInterval}')"
            );
        }

        $policyTable = $this->resolveTable('pramnos.framework_policies');
        $qb          = $this->db->queryBuilder();
        $qb->table($policyTable)->insert([
            'policy_type' => 'aggregate_refresh',
            'target'      => $view,
            'config'      => json_encode([
                'start_offset'      => $startOffset,
                'end_offset'        => $endOffset,
                'schedule_interval' => $scheduleInterval,
            ]),
            'enabled'     => 1,
            'created_at'  => $qb->raw('NOW()'),
        ]);

        return (int) $this->db->getInsertId() > 0;
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
    // TimescaleDB Informational Views
    // =========================================================================

    /**
     * Return all hypertables visible to the current user.
     * Each row object has at least: hypertable_schema, hypertable_name,
     * num_dimensions, num_chunks, compression_enabled.
     * Returns [] on non-TimescaleDB backends.
     *
     * @param  string $schema Filter by schema (empty = all schemas).
     * @return array<int, object>
     */
    public function getHypertables(string $schema = ''): array
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return [];
        }

        if ($schema !== '') {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT * FROM timescaledb_information.hypertables WHERE hypertable_schema = %s',
                    $schema
                )
            );
        } else {
            $result = $this->db->query('SELECT * FROM timescaledb_information.hypertables');
        }

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row) => (object) $row,
            $result->fetchAll()
        );
    }

    /**
     * Return true when the given table is registered as a TimescaleDB hypertable.
     * Returns false on non-TimescaleDB backends.
     *
     * @param  string $table  Plain table name (no schema prefix).
     * @param  string $schema Schema to check (empty = resolved schema).
     * @return bool
     */
    public function isHypertable(string $table, string $schema = ''): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        if ($schema === '') {
            $schema = $this->resolveSchema();
        }

        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT COUNT(*) AS cnt FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s',
                $schema,
                $table
            )
        );

        return $result && (int) ($result->fields['cnt'] ?? 0) > 0;
    }

    /**
     * Return all continuous aggregates, optionally filtered by view schema.
     * Each row object has at least: view_schema, view_name, hypertable_schema,
     * hypertable_name, materialized_only, finalized.
     * Returns [] on non-TimescaleDB backends.
     *
     * @param  string $schema Filter by view_schema (empty = all schemas).
     * @return array<int, object>
     */
    public function getContinuousAggregates(string $schema = ''): array
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return [];
        }

        if ($schema !== '') {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT * FROM timescaledb_information.continuous_aggregates WHERE view_schema = %s',
                    $schema
                )
            );
        } else {
            $result = $this->db->query('SELECT * FROM timescaledb_information.continuous_aggregates');
        }

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row) => (object) $row,
            $result->fetchAll()
        );
    }

    /**
     * Return partitioning dimensions for a hypertable.
     * Each row object has at least: dimension_type, column_name, column_type.
     * Returns [] on non-TimescaleDB backends.
     *
     * @param  string $table  Hypertable name.
     * @param  string $schema Schema (empty = resolved schema).
     * @return array<int, object>
     */
    public function getHypertableDimensions(string $table, string $schema = ''): array
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return [];
        }

        if ($schema === '') {
            $schema = $this->resolveSchema();
        }

        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT * FROM timescaledb_information.dimensions
                 WHERE hypertable_schema = %s AND hypertable_name = %s',
                $schema,
                $table
            )
        );

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row) => (object) $row,
            $result->fetchAll()
        );
    }

    /**
     * Return TimescaleDB background jobs (retention, compression,
     * aggregate refresh, user-defined actions, etc.).
     * Each row object has at least: job_id, application_name,
     * schedule_interval, max_runtime, max_retries, scheduled,
     * config, next_start, owner.
     * Returns [] on non-TimescaleDB backends.
     *
     * @param  string $procName Substring filter on application_name (empty = all).
     * @return array<int, object>
     */
    public function getTimescaleJobs(string $procName = ''): array
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return [];
        }

        if ($procName !== '') {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT * FROM timescaledb_information.jobs WHERE application_name ILIKE %s",
                    '%' . $procName . '%'
                )
            );
        } else {
            $result = $this->db->query('SELECT * FROM timescaledb_information.jobs');
        }

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row) => (object) $row,
            $result->fetchAll()
        );
    }

    /**
     * Return chunks for a hypertable (or all hypertables when $table is empty).
     * Each row object has at least: hypertable_schema, hypertable_name,
     * chunk_schema, chunk_name, range_start, range_end, is_compressed.
     * Returns [] on non-TimescaleDB backends.
     *
     * @param  string $table  Hypertable name (empty = all hypertables).
     * @param  string $schema Schema (empty = resolved schema; ignored when $table is empty).
     * @return array<int, object>
     */
    public function getChunks(string $table = '', string $schema = ''): array
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return [];
        }

        if ($table !== '') {
            if ($schema === '') {
                $schema = $this->resolveSchema();
            }
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT * FROM timescaledb_information.chunks
                     WHERE hypertable_schema = %s AND hypertable_name = %s
                     ORDER BY range_start',
                    $schema,
                    $table
                )
            );
        } else {
            $result = $this->db->query(
                'SELECT * FROM timescaledb_information.chunks ORDER BY hypertable_name, range_start'
            );
        }

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row) => (object) $row,
            $result->fetchAll()
        );
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

    /**
     * Advance a sequence and return its new value (PostgreSQL only).
     *
     * Equivalent to PostgreSQL's `SELECT nextval('name')`.
     * On MySQL returns 0 — sequences are not supported.
     *
     * Use this when you need a unique ID from a shared sequence that is
     * independent of any particular table (e.g. for sharded PKs, event IDs,
     * or document numbers that must be globally unique across tables).
     *
     * @param  string $name  Sequence name (schema-qualify if needed, e.g. "public.order_seq")
     * @return int           Next value, or 0 on MySQL
     */
    public function nextVal(string $name): int
    {
        $sql = $this->getGrammar()->compileNextVal($name);
        if ($sql === '') {
            return 0;
        }
        $result = $this->db->query($sql);
        if (!$result || $result->numRows === 0) {
            return 0;
        }
        return (int) array_values((array) $result->fields)[0];
    }

    /**
     * Set a sequence's current value (PostgreSQL only).
     *
     * Equivalent to PostgreSQL's `SELECT setval('name', value, is_called)`.
     * On MySQL returns 0 — sequences are not supported.
     *
     * Useful after bulk-inserting rows with explicit IDs to reset the sequence
     * so the next `nextval()` / serial column does not collide with existing rows.
     *
     * @param  string $name      Sequence name
     * @param  int    $value     Value to set
     * @param  bool   $isCalled  true (default): next nextval() returns value + increment.
     *                           false: next nextval() returns value itself (useful after INSERT … ON CONFLICT).
     * @return int               The value that was set, or 0 on MySQL
     */
    public function setVal(string $name, int $value, bool $isCalled = true): int
    {
        $sql = $this->getGrammar()->compileSetVal($name, $value, $isCalled);
        if ($sql === '') {
            return 0;
        }
        $result = $this->db->query($sql);
        if (!$result || $result->numRows === 0) {
            return 0;
        }
        return (int) array_values((array) $result->fields)[0];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve a logical table name to the physical name for the current backend.
     *
     * Two transformations are applied (in order):
     *
     * 1. `#PREFIX#` token → replaced with the configured table prefix (e.g. `myapp_`).
     *    This is the explicit opt-in mechanism for apps that namespace all tables.
     *
     * 2. `schema.table` notation on MySQL → translated to `{prefix}schema_table`.
     *    MySQL has no schema concept; the schema name becomes a name prefix instead,
     *    mirroring the convention used by the authserver and pramnos schemas.
     *    On PostgreSQL the dot notation is preserved and handled by the grammar.
     *
     * Plain table names (no `#PREFIX#`, no dot) are returned as-is so that existing
     * tables are not accidentally renamed by introducing a prefix.
     */
    protected function resolveTable(string $table): string
    {
        $prefix = $this->db->prefix ?? '';

        // Explicit #PREFIX# token — substitute in place.
        if (strpos($table, '#PREFIX#') !== false) {
            return str_replace('#PREFIX#', $prefix, $table);
        }

        // schema.table passed explicitly — handle as-is per driver.
        if (strpos($table, '.') !== false) {
            if ($this->capabilities->isMySQL()) {
                [$schema, $name] = explode('.', $table, 2);
                return $prefix . $schema . '_' . $name;
            }
            return $table;
        }

        // Apply schema override from withSchema() when the table has no explicit schema.
        if ($this->overrideSchema !== null) {
            if ($this->capabilities->isMySQL()) {
                return $prefix . $this->overrideSchema . '_' . $table;
            }
            // PostgreSQL: schema.table — quoteTable() will split and double-quote.
            return $this->overrideSchema . '.' . $table;
        }

        return $table;
    }

    /**
     * Returns the physical table name for the current backend (public façade over resolveTable).
     *
     * Use this when you need the resolved name outside of SchemaBuilder DDL methods,
     * e.g. in raw SQL strings or to build a properly-quoted table reference via quoteTable().
     */
    public function resolveTableName(string $table): string
    {
        return $this->resolveTable($table);
    }

    /**
     * Returns a fully-quoted table reference suitable for embedding in raw SQL.
     *
     * Combines resolveTable() (schema→prefix on MySQL, #PREFIX# substitution) with
     * the grammar's quoteTable() (backtick on MySQL, double-quote on PostgreSQL).
     *
     * Example:
     *   quoteTable('authserver.roles')  →  `authserver_roles`   (MySQL)
     *   quoteTable('authserver.roles')  →  "authserver"."roles"  (PostgreSQL)
     */
    public function quoteTable(string $table): string
    {
        return $this->getGrammar()->quoteTable($this->resolveTable($table));
    }

    /**
     * Resolve the schema name for introspection queries.
     *
     * On MySQL the "schema" is the database name. Using an empty schema causes
     * information_schema queries to search ALL databases, which produces false
     * positives when system databases (e.g. performance_schema) contain tables
     * with the same name (e.g. performance_schema.users). Fall back to the
     * connected database name so that only the current database is searched.
     *
     * On PostgreSQL the schema is the PG schema (e.g. 'public', 'authserver').
     * An empty schema is valid — the PostgreSQL grammar handles that case by
     * excluding system schemas instead.
     */
    protected function resolveSchema(): string
    {
        // withSchema() override takes precedence over the db's own schema setting.
        $schema = $this->overrideSchema ?? $this->db->schema ?? '';
        if ($schema === '' && $this->capabilities->isMySQL()) {
            $schema = $this->db->database ?? '';
        }
        return $schema;
    }
}
