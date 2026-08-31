<?php

namespace Pramnos\Database;

use Pramnos\Database\Grammar\SchemaGrammarInterface;
use Pramnos\Database\Grammar\MariaDBSchemaGrammar;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;
use Pramnos\Database\Grammar\TimescaleDBSchemaGrammar;

/**
 * Fluent DDL builder for table, index, view, and TimescaleDB operations.
 *
 * Entry point: $db->schema() or $db->schemaBuilder()
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
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

    /**
     * Pick the DDL grammar for the current connection.
     *
     * The MariaDB branch is gated on the SEQUENCES *capability* rather than on
     * the flavor name: MariaDB only grew sequence objects in 10.3, and the only
     * thing MariaDBSchemaGrammar changes is sequences.  An older MariaDB gets
     * the plain MySQL grammar and therefore keeps its existing no-op behaviour
     * instead of being handed DDL its server cannot parse.
     *
     * @return SchemaGrammarInterface
     */
    protected function makeGrammar(): SchemaGrammarInterface
    {
        if ($this->db->type === 'postgresql') {
            return $this->db->timescale
                ? new TimescaleDBSchemaGrammar()
                : new PostgreSQLSchemaGrammar();
        }

        if ($this->capabilities->has(DatabaseCapabilities::SEQUENCES)) {
            return new MariaDBSchemaGrammar();
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
        $this->forgetCachedSchema($table);
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
        $this->forgetCachedSchema($table);
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
        $this->forgetCachedSchema($table);
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
        $this->forgetCachedSchema($table);
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
        $this->forgetCachedSchema($from);
        $this->forgetCachedSchema($to);
    }

    /**
     * Move an existing table into a schema, keeping its rows.
     *
     * Not `renameTable()`. PostgreSQL's `ALTER TABLE … RENAME TO` takes a **bare** name — it
     * cannot move a table between schemas, and handing it a qualified one is a syntax error.
     * The statement for that is `SET SCHEMA`, which is a catalogue update: instant, whatever the
     * table holds.
     *
     * On MySQL a schema is flattened into the table's name (see {@see resolveTable()}), so the
     * same intention is a rename — and that one does copy nothing either, it is a catalogue
     * update as well.
     *
     * Answers `false` rather than raising when there is nothing to move: a migration that moves
     * a table runs on installations that already have it in the right place, and on ones that
     * never had it at all.
     *
     * @param  string $table  The table's current name, qualified or not
     * @param  string $schema The schema to move it into
     * @return bool           True when the table was moved
     */
    public function moveToSchema(string $table, string $schema): bool
    {
        $bare   = str_contains($table, '.') ? substr($table, strpos($table, '.') + 1) : $table;
        $target = $schema . '.' . $bare;

        if (!$this->hasTable($table) || $this->hasTable($target)) {
            return false;
        }

        try {
            if ($this->capabilities->isPostgreSQL()) {
                $this->ensureSchema($schema);

                $this->db->query(
                    'ALTER TABLE ' . $this->getGrammar()->quoteTable($this->resolveTable($table))
                    . ' SET SCHEMA "' . preg_replace('~[^a-z0-9_]~i', '', $schema) . '"'
                );
            } else {
                $this->db->query(
                    $this->getGrammar()->compileRename(
                        $this->resolveTable($table),
                        $this->resolveTable($target)
                    )
                );
            }
        } catch (\Throwable) {
            return false;
        }

        $this->forgetCachedSchema($table);
        $this->forgetCachedSchema($target);

        return true;
    }

    /**
     * Tell the connection that a table's schema has changed.
     *
     * `Database::getColumns()` caches an introspection for an hour on the
     * grounds that schemas rarely change. They do not — but the moment one does
     * is exactly the moment somebody asks again, and nothing was invalidating
     * it. Code generators read fresh for that reason; every other caller in the
     * process was left with the old answer for up to an hour, and with a shared
     * cache store the staleness outlived the process.
     *
     * Flushed under both the raw and the resolved table name, because
     * getColumns() caches under whatever string it was handed: a caller passing
     * `#PREFIX#things` and one passing `pramnos_things` are two entries for one
     * table.
     */
    private function forgetCachedSchema(string $table): void
    {
        $this->db->forgetColumns($table);

        $resolved = $this->resolveTable($table);
        if ($resolved !== $table) {
            $this->db->forgetColumns($resolved);
        }
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

    /**
     * Returns true if the named index exists on the given table.
     *
     * Keyed on the index **name**, not on its columns. Two indexes over the same
     * columns are legal, so "is there an index on this column" would have a migration
     * skip creating the one it needs because an unrelated index happens to cover the
     * same ground.
     *
     * A constraint-backed index — a UNIQUE constraint, a primary key — counts as
     * existing, because the question a caller is really asking is whether creating it
     * would collide.
     *
     * @param  string      $table
     * @param  string      $index
     * @param  string|null $schema
     * @return bool
     */
    public function hasIndex(string $table, string $index, ?string $schema = null): bool
    {
        $resolved = $this->resolveTable($table);
        $schema   = $schema ?? $this->resolveSchema();
        $sql      = $this->getGrammar()->compileHasIndex($resolved, $index, $schema);
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
     *
     * Returns false in two situations, and only one of them is quiet: a backend
     * without TimescaleDB is a documented no-op, while a real failure is logged
     * with the statement that produced it. Before, both were silent and
     * indistinguishable.
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

        return $this->runTimescaleStatement($sql, 'hypertable conversion', $table);
    }

    /**
     * Run a TimescaleDB statement, and make a failure audible.
     *
     * These methods return `false` for two situations that are nothing alike:
     * the backend does not support the operation, and the operation was
     * attempted and failed. Both were silent, so a migration whose
     * `createHypertable()` failed looked exactly like one running on MySQL — and
     * the table stayed unpartitioned with nothing anywhere saying why.
     *
     * The signature stays `bool` because callers depend on it. What changes is
     * that only one of the two cases is quiet now: an unsupported backend
     * returns false without a word, as documented, and a real failure is logged
     * with the statement that produced it.
     *
     * @param  string $sql       The statement to run
     * @param  string $operation What was being attempted, for the log
     * @param  string $table     The table it was attempted on
     * @return bool
     */
    protected function runTimescaleStatement(string $sql, string $operation, string $table): bool
    {
        try {
            $result = (bool) $this->db->query($sql);
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError(
                'TimescaleDB ' . $operation . ' failed for ' . $table . ': '
                . $ex->getMessage(),
                $ex
            );

            return false;
        }

        if (!$result) {
            \Pramnos\Logs\Logger::error(
                'TimescaleDB ' . $operation . ' failed for ' . $table
                . '. Statement: ' . $sql,
                [],
                'migrations'
            );
        }

        return $result;
    }

    /**
     * Add a TimescaleDB space dimension (hash-partitioning).
     *
     * No-op without TimescaleDB; a failure on a capable backend is logged.
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

        return $this->runTimescaleStatement(
            "SELECT add_dimension('{$resolved}', '{$column}', number_partitions => {$partitions})",
            'space dimension',
            $table
        );
    }

    /**
     * Enable column compression on a hypertable.
     *
     * No-op without TimescaleDB; a failure on a capable backend is logged.
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
        return $this->runTimescaleStatement(
            "ALTER TABLE {$quoted} SET (" . implode(', ', $parts) . ')',
            'compression settings',
            $table
        );
    }

    /**
     * The interval a policy is currently configured with, or null when there is none.
     *
     * Reads what the **database** has, not what a declaration says — which is the whole
     * point. Until this existed, nothing could tell that a changed declaration and a live
     * policy disagreed, so `timescale:ensure` reported "nothing missing" and changed
     * nothing, for ever, while the number in the code said otherwise.
     *
     * Works on every backend: from `timescaledb_information.jobs` where the extension is
     * present, from `pramnos.framework_policies` where it is not.
     *
     * @param  string $table Logical name
     * @param  string $kind  `retention` or `compression`
     * @return string|null   e.g. `90 days`
     */
    public function policyInterval(string $table, string $kind = 'retention'): ?string
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return $this->softwarePolicyInterval($table, $kind);
        }

        [$schema, $name] = $this->splitTable($table);
        $procName        = $kind === 'compression' ? 'policy_compression' : 'policy_retention';

        try {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT config FROM timescaledb_information.jobs
                     WHERE proc_name = %s
                       AND hypertable_schema = %s
                       AND hypertable_name = %s
                     LIMIT 1',
                    $procName,
                    $schema,
                    $name
                )
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$result || !isset($result->fields['config'])) {
            return null;
        }

        $config = json_decode((string) $result->fields['config'], true);
        if (!is_array($config)) {
            return null;
        }

        // Timescale names it differently per policy, and has renamed it across versions.
        foreach (['drop_after', 'compress_after', 'older_than'] as $key) {
            if (isset($config[$key]) && $config[$key] !== null) {
                return (string) $config[$key];
            }
        }

        return null;
    }

    /**
     * The interval of a software policy row, for backends without the extension.
     *
     * @return string|null
     */
    protected function softwarePolicyInterval(string $table, string $kind): ?string
    {
        try {
            $policyTable = $this->resolveTable('pramnos.framework_policies');
            $result      = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT config FROM ' . $policyTable
                    . ' WHERE policy_type = %s AND target = %s LIMIT 1',
                    $kind,
                    $table
                )
            );
        } catch (\Throwable) {
            // No policy store yet — which is not an error, it is "no policy".
            return null;
        }

        if (!$result || !isset($result->fields['config'])) {
            return null;
        }

        $config = json_decode((string) $result->fields['config'], true);

        return is_array($config) && isset($config['interval'])
            ? (string) $config['interval']
            : null;
    }

    /**
     * Remove a retention policy, so a changed declaration can replace it.
     *
     * Without a remove there is no replace: `add_retention_policy()` raises on a
     * duplicate, which is why `hasRetentionPolicy()` exists — and why, until now, a
     * policy could be created and never changed.
     *
     * @param  string $table Logical name
     * @return bool          Whether anything was removed
     */
    public function removeRetentionPolicy(string $table): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return $this->removeSoftwarePolicy($table, 'retention');
        }

        $resolved = $this->resolveTable($table);

        return $this->runTimescaleStatement(
            "SELECT remove_retention_policy('{$resolved}', if_exists => true)",
            'retention policy removal',
            $table
        );
    }

    /**
     * Remove a compression policy. Compression settings themselves are left alone.
     *
     * @param  string $table Logical name
     * @return bool
     */
    public function removeCompressionPolicy(string $table): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        $resolved = $this->resolveTable($table);

        return $this->runTimescaleStatement(
            "SELECT remove_compression_policy('{$resolved}', if_exists => true)",
            'compression policy removal',
            $table
        );
    }

    /**
     * Delete a software policy row.
     */
    protected function removeSoftwarePolicy(string $table, string $kind): bool
    {
        try {
            $policyTable = $this->resolveTable('pramnos.framework_policies');
            $this->db->queryBuilder()
                ->table($policyTable)
                ->where('policy_type', $kind)
                ->where('target', $table)
                ->delete();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Add a TimescaleDB compression policy (compress chunks older than $after).
     *
     * No-op without TimescaleDB; a failure on a capable backend is logged.
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

        return $this->runTimescaleStatement(
            "SELECT add_compression_policy('{$resolved}', INTERVAL '{$compressAfter}')",
            'compression policy',
            $table
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

            return $this->runTimescaleStatement(
                "SELECT add_retention_policy('{$resolved}', INTERVAL '{$dropAfter}')",
                'retention policy',
                $table
            );
        }

        $policyTable = $this->resolveTable('pramnos.framework_policies');
        $config      = json_encode(['interval' => $dropAfter, 'time_column' => $timeColumn]);
        $qb          = $this->db->queryBuilder();

        // Update in place when one is already registered, rather than inserting beside
        // it. This used to be an unconditional insert, and the guard that was supposed to
        // stop a second one — hasRetentionPolicy() — answered a flat `false` off
        // TimescaleDB. So every run of the ensure command added another row, and N
        // identical policies issued the same DELETE N times against the same table.
        if ($this->softwarePolicyInterval($table, 'retention') !== null) {
            $this->db->queryBuilder()
                ->table($policyTable)
                ->where('policy_type', 'retention')
                ->where('target', $table)
                ->update(['config' => $config, 'enabled' => 1]);

            return true;
        }

        $qb->table($policyTable)->insert([
            'policy_type' => 'retention',
            'target'      => $table,
            'config'      => $config,
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

            return $this->runTimescaleStatement(
                "SELECT add_continuous_aggregate_policy('{$resolved}'," .
                " start_offset => INTERVAL '{$startOffset}'," .
                " end_offset => INTERVAL '{$endOffset}'," .
                " schedule_interval => INTERVAL '{$scheduleInterval}')",
                'aggregate refresh policy',
                $view
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
            // Not resolveSchema(): that yields '' without a withSchema()
            // override, and '' matches no row in the catalogue view, so the
            // answer was always false for an unqualified table.
            $schema = $this->defaultSchema();
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
     * The columns making up a table's primary key, in key order.
     *
     * TimescaleDB requires the partitioning column to be part of every unique
     * constraint, so converting a table whose primary key omits the time column
     * fails. Reading the key is how a repair can say *why* it is skipping a
     * table instead of surfacing a driver error.
     *
     * @param  string $table Logical name
     * @return array<int, string> Column names; empty when there is no primary key
     */
    public function primaryKeyColumns(string $table): array
    {
        [$schema, $name] = $this->splitTable($table);

        if ($this->capabilities->isMySQL()) {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT COLUMN_NAME AS col FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                       AND CONSTRAINT_NAME = %s
                     ORDER BY ORDINAL_POSITION',
                    $name,
                    'PRIMARY'
                )
            );
        } else {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT a.attname AS col
                     FROM pg_index i
                     JOIN pg_class c ON c.oid = i.indrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     JOIN pg_attribute a
                       ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey)
                     WHERE i.indisprimary AND n.nspname = %s AND c.relname = %s",
                    $schema,
                    $name
                )
            );
        }

        $columns = [];
        foreach (($result ? $result->fetchAll() : []) as $row) {
            $columns[] = (string) ($row['col'] ?? '');
        }

        return array_values(array_filter($columns));
    }

    /**
     * Split a logical table name into the schema and name TimescaleDB reports.
     *
     * `isHypertable()` and the `timescaledb_information` views address a table
     * as two separate identifiers, while everything else in the framework passes
     * one logical name (`authserver.user_consents`). This is the translation
     * between them.
     *
     * @param  string $table Logical name, with or without a schema
     * @return array{0: string, 1: string} Schema, then table name
     */
    protected function splitTable(string $table): array
    {
        $resolved = $this->resolveTable($table);

        if (strpos($resolved, '.') !== false) {
            [$schema, $name] = explode('.', $resolved, 2);

            return [$schema, $name];
        }

        return [$this->defaultSchema(), $resolved];
    }

    /**
     * The schema an unqualified table actually lives in.
     *
     * `resolveSchema()` returns an empty string when no `withSchema()` override
     * is in force, which is fine for building SQL — an unqualified name resolves
     * through the search path — but useless for querying catalogue views, which
     * report the real schema and match nothing against `''`. Every unqualified
     * table the framework creates lands in `public`.
     */
    protected function defaultSchema(): string
    {
        $schema = $this->resolveSchema();

        return $schema !== '' ? $schema : 'public';
    }

    /**
     * Is this logical table a hypertable?
     *
     * The schema-aware counterpart of {@see isHypertable()}, which takes the
     * schema separately. Returns false on non-TimescaleDB backends, and on a
     * table that does not exist at all.
     *
     * @param  string $table Logical name, e.g. `authserver.user_consents`
     * @return bool
     */
    public function hasHypertable(string $table): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        [$schema, $name] = $this->splitTable($table);

        return $this->isHypertable($name, $schema);
    }

    /**
     * Has compression been enabled on this hypertable?
     *
     * Distinct from *having a compression policy*: enabling compression sets
     * the table option, the policy schedules it. `add_compression_policy()`
     * raises if the option was never set, so this is what decides whether that
     * step can run yet.
     *
     * @param  string $table Logical name
     * @return bool
     */
    public function isCompressionEnabled(string $table): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        [$schema, $name] = $this->splitTable($table);

        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT compression_enabled FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s',
                $schema,
                $name
            )
        );

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $enabled = $result->fields['compression_enabled'] ?? false;

        return $enabled === true || $enabled === 't' || $enabled === 1 || $enabled === '1';
    }

    /**
     * Does a background job already compress this hypertable's chunks?
     *
     * `add_compression_policy()` raises when one exists rather than no-opping,
     * so without this check a second run of any repair would fail.
     *
     * @param  string $table Logical name
     * @return bool
     */
    public function hasCompressionPolicy(string $table): bool
    {
        return $this->hasPolicyJob($table, 'policy_compression');
    }

    /**
     * Does a background job already drop this hypertable's old chunks?
     *
     * Same reason as {@see hasCompressionPolicy()}: `add_retention_policy()`
     * raises on a duplicate.
     *
     * @param  string $table Logical name
     * @return bool
     */
    public function hasRetentionPolicy(string $table): bool
    {
        return $this->hasPolicyJob($table, 'policy_retention');
    }

    /**
     * Is a TimescaleDB background job of this kind registered for the table?
     *
     * @param  string $table    Logical name
     * @param  string $procName TimescaleDB job procedure, e.g. `policy_retention`
     * @return bool
     */
    protected function hasPolicyJob(string $table, string $procName): bool
    {
        if (!$this->capabilities->hasTimescaleDB()) {
            // A retention policy exists off TimescaleDB too — as a row in
            // pramnos.framework_policies, executed by the PolicyEngine daemon. Answering
            // a flat `false` here made HypertableRegistry::apply() believe there was
            // never one, so every run inserted another, and N identical policies then
            // issued the same DELETE N times against the same table.
            //
            // Compression has no software equivalent, so it keeps the old answer.
            return $procName === 'policy_retention'
                && $this->softwarePolicyInterval($table, 'retention') !== null;
        }

        [$schema, $name] = $this->splitTable($table);

        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT COUNT(*) AS cnt FROM timescaledb_information.jobs
                 WHERE proc_name = %s
                   AND hypertable_schema = %s
                   AND hypertable_name = %s',
                $procName,
                $schema,
                $name
            )
        );

        return $result && (int) ($result->fields['cnt'] ?? 0) > 0;
    }

    /**
     * Does this view exist, of any kind?
     *
     * "View" covers three things that are not tables and not each other: a plain
     * view, a materialized view, and — on TimescaleDB — a continuous aggregate,
     * which presents as a view over a hidden materialization hypertable.
     * `hasTable()` finds none of them, which is why asking it about an aggregate
     * quietly answers no.
     *
     * @param  string $view Logical view name, e.g. `authserver.daily_2fa_stats`
     * @return bool
     */
    public function hasView(string $view): bool
    {
        [$schema, $name] = $this->splitTable($view);

        if ($this->capabilities->isMySQL()) {
            $result = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT 1 FROM information_schema.views
                     WHERE table_schema = DATABASE() AND table_name = %s',
                    $name
                )
            );

            return $result && $result->numRows > 0;
        }

        // relkind: v = view, m = materialized view. A continuous aggregate is
        // the former, so both belong in the same question.
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT 1 FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind IN ('v', 'm') AND n.nspname = %s AND c.relname = %s",
                $schema,
                $name
            )
        );

        return $result && $result->numRows > 0;
    }

    /**
     * Is this aggregate already being refreshed by something?
     *
     * Two backends, two answers, one question — which is the whole point of
     * asking it here rather than at each call site.
     *
     * On TimescaleDB the refresh is a background job, and **which name
     * `timescaledb_information.jobs` records depends on the version**, so the
     * lookup accepts either. Measured: 2.19.3 records the *materialization*
     * hypertable (`_timescaledb_internal._materialized_hypertable_N`); 2.26.4
     * records the continuous aggregate's own view schema and name.
     *
     * This docblock used to assert that the job "cannot be found by the view's
     * name" as settled fact. It was true when written and false by 2.26, and the
     * consequence was not a wrong answer but a *constant* one: the join matched
     * nothing for every aggregate, so this check — whose only job is to make policy
     * creation idempotent — had never once returned true on such an installation.
     * See the query for what that cost.
     *
     * Everywhere else the "aggregate" is a materialized view that PostgreSQL
     * never refreshes on its own, and the refresh is a row in
     * `pramnos.framework_policies` executed by the policy engine. A view with no
     * such row is frozen at the moment it was created — which is what four
     * framework migrations quietly produced, because they registered the policy
     * only inside their TimescaleDB branch.
     *
     * @param  string $view Logical view name, e.g. `authserver.daily_2fa_stats`
     * @return bool
     */
    public function hasContinuousAggregatePolicy(string $view): bool
    {
        if ($this->capabilities->hasTimescaleDB()) {
            [$schema, $name] = $this->splitTable($view);

            // Joined on **either** pairing, because TimescaleDB changed which one
            // `timescaledb_information.jobs` reports and both are in the field.
            //
            // Measured, on two versions:
            //
            //   2.19.3 — hypertable_schema/name = _timescaledb_internal /
            //            _materialized_hypertable_N, the materialization hypertable
            //   2.26.4 — hypertable_schema/name = the continuous aggregate's own
            //            view schema and name
            //
            // The original join used the materialization pairing and its docblock
            // stated the premise outright: the job "cannot be found by the view's
            // name". True when it was written, false on 2.26. There it matched
            // nothing, for every continuous aggregate, always — so the check that
            // exists to make policy creation idempotent had never once been taken,
            // and the repair re-added a policy that already existed on every schedule
            // cycle: three stack traces per cycle, and an errors counter in every
            // worker's lock file that could never read zero.
            //
            // Nothing was broken, which is exactly why it would never have been
            // fixed. The cost is that a real fault had to compete with it for
            // attention.
            //
            // Accepting both rather than swapping one for the other, because swapping
            // would have broken every 2.19-era installation in the same silent way —
            // including this project's own dev stack. Reported with the 2.26
            // measurement and the instinct to check the older behaviour first, which
            // is what made this the right shape.
            $result = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                       FROM timescaledb_information.jobs j
                       JOIN timescaledb_information.continuous_aggregates c
                         ON (j.hypertable_schema = c.materialization_hypertable_schema
                             AND j.hypertable_name = c.materialization_hypertable_name)
                         OR (j.hypertable_schema = c.view_schema
                             AND j.hypertable_name = c.view_name)
                      WHERE j.proc_name = 'policy_refresh_continuous_aggregate'
                        AND c.view_schema = %s AND c.view_name = %s",
                    $schema,
                    $name
                )
            );

            return $result && (int) ($result->fields['cnt'] ?? 0) > 0;
        }

        // Software policy. A missing policies table means the core migrations
        // have not run, not that the policy is present.
        try {
            if (!$this->hasTable('pramnos.framework_policies')) {
                return false;
            }

            $result = $this->db->queryBuilder()
                ->table('pramnos.framework_policies')
                ->where('policy_type', 'aggregate_refresh')
                ->where('target', $view)
                ->count();

            return $result > 0;
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * Decompress one chunk so that it accepts writes again.
     *
     * A compressed chunk rejects inserts into the time range it covers. The
     * only way to write there is to decompress it, write, and compress it back
     * — which is expensive, and the reason anything doing this should group its
     * writes so that the pair is paid **once per chunk** rather than once per
     * row.
     *
     * Identify the chunk with the schema and name that {@see getChunks()}
     * reports (`chunk_schema`, `chunk_name`), not with the hypertable's name.
     *
     * @param  string $chunkSchema The chunk's own schema, e.g. `_timescaledb_internal`
     * @param  string $chunkName   The chunk's own name, e.g. `_hyper_3_17_chunk`
     * @return bool   False without TimescaleDB, or when the statement failed
     *                (a failure is logged; an unsupported backend is not).
     */
    public function decompressChunk(string $chunkSchema, string $chunkName): bool
    {
        return $this->chunkCompression('decompress_chunk', $chunkSchema, $chunkName);
    }

    /**
     * Compress one chunk again after it was decompressed for a write.
     *
     * @param  string $chunkSchema The chunk's own schema
     * @param  string $chunkName   The chunk's own name
     * @return bool   False without TimescaleDB, or when the statement failed
     */
    public function compressChunk(string $chunkSchema, string $chunkName): bool
    {
        return $this->chunkCompression('compress_chunk', $chunkSchema, $chunkName);
    }

    /**
     * Shared body of {@see compressChunk()} and {@see decompressChunk()}.
     *
     * `format('%I.%I', …)` quotes both identifiers the way PostgreSQL itself
     * would, which matters because the internal chunk names are generated and
     * may need quoting that a plain concatenation would not apply.
     *
     * @param  string $function    `compress_chunk` or `decompress_chunk`
     * @param  string $chunkSchema The chunk's own schema
     * @param  string $chunkName   The chunk's own name
     * @return bool
     */
    protected function chunkCompression(
        string $function,
        string $chunkSchema,
        string $chunkName
    ): bool {
        if (!$this->capabilities->hasTimescaleDB()) {
            return false;
        }

        // Raw by necessity: these are TimescaleDB functions taking a regclass
        // built from two quoted identifiers — nothing the query builder models.
        $sql = 'SELECT ' . $function . '(' . $this->db->prepareQuery(
            "format('%%I.%%I', %s, %s)",
            $chunkSchema,
            $chunkName
        ) . ')';

        return $this->runTimescaleStatement(
            $sql,
            $function,
            $chunkSchema . '.' . $chunkName
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
    // Sequence DDL (PostgreSQL and MariaDB 10.3+; silent no-op on Oracle MySQL)
    // =========================================================================

    /**
     * Create a named sequence.
     *
     * Supported on PostgreSQL and on MariaDB 10.3+.  On Oracle MySQL (and on a
     * MariaDB older than 10.3) the call is silently ignored — no exception.
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
     * Drop a sequence.
     *
     * Supported on PostgreSQL and on MariaDB 10.3+.  Silently ignored where the
     * server has no sequence objects.
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
     * Advance a sequence and return its new value.
     *
     * Equivalent to PostgreSQL's `SELECT nextval('name')` and MariaDB's
     * `SELECT NEXTVAL(name)`.  Returns 0 where sequences are unsupported
     * (Oracle MySQL, MariaDB < 10.3).
     *
     * Use this when you need a unique ID from a shared sequence that is
     * independent of any particular table (e.g. for sharded PKs, event IDs,
     * or document numbers that must be globally unique across tables).
     *
     * @param  string $name  Sequence name (schema-qualify if needed, e.g. "public.order_seq")
     * @return int           Next value, or 0 where sequences are unsupported
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
     * Set a sequence's current value.
     *
     * Equivalent to PostgreSQL's `SELECT setval('name', value, is_called)` and
     * MariaDB's `SELECT SETVAL(name, value, is_used)`.  Returns 0 where
     * sequences are unsupported (Oracle MySQL, MariaDB < 10.3).
     *
     * Useful after bulk-inserting rows with explicit IDs to reset the sequence
     * so the next `nextval()` / serial column does not collide with existing rows.
     *
     * @param  string $name      Sequence name
     * @param  int    $value     Value to set
     * @param  bool   $isCalled  true (default): next nextval() returns value + increment.
     *                           false: next nextval() returns value itself (useful after INSERT … ON CONFLICT).
     * @return int               The value that was set, or 0 where unsupported
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
    /**
     * Make sure a schema exists, so a table can be created inside it.
     *
     * `CREATE TABLE pramnos.x` on PostgreSQL fails outright when the schema is not there, and
     * "somebody else's migration made it" is an assumption that holds until something runs one
     * feature's migrations on their own — which every integration test that touches a feature
     * does. A no-op on MySQL, where a schema is flattened into the table name and there is
     * nothing to create.
     */
    public function ensureSchema(string $name): bool
    {
        if (!$this->capabilities->isPostgreSQL()) {
            return true;
        }

        $safe = preg_replace('~[^a-z0-9_]~i', '', $name);

        if ($safe === '' || $safe === null) {
            return false;
        }

        try {
            $this->db->query('CREATE SCHEMA IF NOT EXISTS "' . $safe . '"');

            return true;
        } catch (\Throwable) {
            // Already there, or this role may not create one. Both leave the caller to find
            // out from its own CREATE TABLE, which is a better error than this one.
            return false;
        }
    }

    protected function resolveTable(string $table): string
    {
        $prefix = $this->db->prefix ?? '';

        // Explicit #PREFIX# token — substitute in place.
        if (strpos($table, '#PREFIX#') !== false) {
            return str_replace('#PREFIX#', $prefix, $table);
        }

        // schema.table passed explicitly — handle as-is per driver.
        //
        // On MySQL a schema is flattened into the table name, so the prefix is
        // the only namespace there is and it applies. On PostgreSQL the schema
        // *is* the namespace, and prefixing inside it would rename tables the
        // framework addresses by schema everywhere else.
        if (strpos($table, '.') !== false) {
            if ($this->capabilities->isMySQL()) {
                [$schema, $name] = explode('.', $table, 2);
                return $this->withPrefix($prefix, $schema . '_' . $name);
            }
            return $table;
        }

        // Apply schema override from withSchema() when the table has no explicit schema.
        if ($this->overrideSchema !== null) {
            if ($this->capabilities->isMySQL()) {
                return $this->withPrefix($prefix, $this->overrideSchema . '_' . $table);
            }
            // PostgreSQL: schema.table — quoteTable() will split and double-quote.
            return $this->overrideSchema . '.' . $table;
        }

        // A plain table name gets the configured prefix.
        //
        // It did not, and that was the whole of the defect: `#PREFIX#users` in
        // application code resolved to `pramnos_users` while `createTable('users')`
        // in a migration created `users`. Two layers of the same framework
        // disagreeing about what a table is called — invisible on the default
        // empty prefix, and total on any installation that sets one.
        return $this->withPrefix($prefix, $table);
    }

    /**
     * Prefix a resolved table name, unless it already carries the prefix.
     *
     * The guard is not decoration. Some callers resolve the name themselves and
     * hand the *result* to the builder — `Model::getFullTableName()` substitutes
     * `#PREFIX#` and then calls `queryBuilder()->from(...)` with what comes out.
     * Prefixing that a second time would look for `pramnos_pramnos_users`.
     *
     * A table genuinely named `pramnos_x` on an installation prefixed `pramnos_`
     * is indistinguishable from an already-prefixed `x` — which is fine, because
     * they are the same table.
     *
     * @param  string $prefix Configured prefix, already ending in `_`
     * @param  string $table  Resolved table name
     * @return string
     */
    protected function withPrefix(string $prefix, string $table): string
    {
        if ($prefix === '' || str_starts_with($table, $prefix)) {
            return $table;
        }

        return $prefix . $table;
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
