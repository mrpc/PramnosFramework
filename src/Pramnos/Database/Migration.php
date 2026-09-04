<?php

namespace Pramnos\Database;

/**
 * Base class for database migrations.
 *
 * Phase 4 additions add metadata fields ($feature, $scope, $priority,
 * $dependencies) that the MigrationRunner uses for topological
 * sort, cutoff filtering, and history recording. All additions are
 * backward-compatible — existing subclasses continue to work without any
 * changes.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
abstract class Migration extends \Pramnos\Framework\Base
{
    // =========================================================================
    // Legacy properties (v1.1 API — kept for BC)
    // =========================================================================

    /**
     * Version that this migration sets.
     * Intentionally untyped for BC — existing subclasses redeclare this
     * property without a type annotation. Adding a type here would cause a
     * fatal "Type of Child::$version must be string" error in PHP 8.x.
     * @var string
     */
    public $version = '';

    /**
     * Description of the migration.
     * Intentionally untyped for BC — see $version note above.
     * @var string
     */
    public $description = '';

    /**
     * When false, the migration is skipped unless MigrationRunner is called
     * with force=true.
     * Intentionally untyped for BC — existing subclasses redeclare this
     * without a type annotation.
     * @var bool
     */
    public $autoExecute = true;

    // =========================================================================
    // Phase 4 metadata
    // =========================================================================

    /**
     * Feature key this migration belongs to, e.g. 'auth', 'queue'.
     * Empty string means it is an application-level migration (no feature).
     * @var string
     */
    public string $feature = '';

    /**
     * Scope identifier: 'app' for application migrations, 'framework' for
     * migrations shipped as part of the framework itself.
     * @var string
     */
    public string $scope = 'app';

    /**
     * Execution priority — lower number runs first.
     * When two migrations have no dependency relationship, priority determines
     * their order. Ties are broken by filename timestamp.
     * @var int
     */
    public int $priority = 50;

    /**
     * Slugs of migrations that must have run successfully before this one.
     * MigrationRunner performs a topological sort based on these declarations.
     * @var string[]
     */
    public array $dependencies = [];

    /**
     * When true, MigrationRunner wraps up() in a database transaction on
     * PostgreSQL (BEGIN / COMMIT / ROLLBACK). Has no effect on MySQL because
     * DDL causes an implicit COMMIT regardless.
     *
     * Set to false for migrations that use TimescaleDB-native operations (e.g.
     * createHypertable()) or any other DDL that cannot run inside a transaction.
     * @var bool
     */
    public bool $transactional = false;

    /**
     * Does this migration legitimately do nothing on some engines?
     *
     * A few of them are conditional by design: `pramnos.framework_policies` exists on MySQL and
     * plain PostgreSQL and **must not** exist on TimescaleDB, which manages its own policies.
     * The migration runs, records itself applied, and creates nothing — correctly.
     *
     * Which is indistinguishable, from the outside, from a migration whose table was dropped by
     * hand: the history says applied and the table is not there. That is the most alarming thing
     * a drift check can report, and reporting it about a migration that is behaving exactly as
     * designed is how a check stops being read. So the migration says so, and
     * {@see \Pramnos\Mcp\Tools\SchemaDriftTool} lists it apart.
     *
     * Declared, not detected: "does this `return` depend on the engine" is not a question to
     * answer by pattern-matching somebody's source.
     */
    public bool $conditional = false;

    // =========================================================================
    // Internal state
    // =========================================================================

    /**
     * List of queries to execute in executeQueries().
     * @var string[]
     */
    protected $queriesToExecute = array();

    /**
     * Statements that `executeQueries()` ran and the database rejected.
     *
     * Kept so the ledger can stop reporting a migration whose statements all
     * failed as one that worked. Each entry is
     * `['query' => string, 'error' => string, 'benign' => bool]`.
     *
     * @var array<int, array{query: string, error: string, benign: bool}>
     */
    protected $failedStatements = array();

    /**
     * How many statements `executeQueries()` has attempted, across all calls.
     * @var int
     */
    protected $attemptedStatements = 0;

    /**
     * Application instance providing the database connection.
     * @var \Pramnos\Application\Application
     */
    protected $application;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param \Pramnos\Application\Application $application
     */
    public function __construct(\Pramnos\Application\Application $application)
    {
        $this->application = $application;
        parent::__construct();
    }

    // =========================================================================
    // Database helpers
    // =========================================================================

    /**
     * Return the live database connection for this migration.
     *
     * Shorthand so migration subclasses can write $this->DB()->statement(…)
     * instead of $this->application->database->statement(…).
     */
    protected function DB(): \Pramnos\Database\Database
    {
        return $this->application->database;
    }

    /**
     * Return a SchemaBuilder scoped to the given schema (or the default schema).
     *
     * Usage in migration up()/down():
     *   $this->schema('authserver')->create('roles', function ($t) { … });
     *   $this->schema('authserver')->dropIfExists('roles');
     *
     * On MySQL the schema name becomes a table prefix (schema_table).
     * On PostgreSQL it is used as a PG schema qualifier ("schema"."table").
     * Passing no argument returns an unscoped builder (uses DB default schema).
     *
     * @param  string $schemaName Schema / database name.
     */
    protected function schema(string $schemaName = ''): \Pramnos\Database\SchemaBuilder
    {
        $builder = new \Pramnos\Database\SchemaBuilder($this->DB());
        if ($schemaName !== '') {
            $builder = $builder->withSchema($schemaName);
        }
        return $builder;
    }

    // =========================================================================
    // Metadata accessors
    // =========================================================================

    /**
     * Returns the migration description.
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Returns the migration slug derived from the concrete class name.
     *
     * For a class named "2024_01_15_120000_create_users_table" the slug is
     * "create_users_table" (the part after the timestamp prefix).
     * For a non-timestamped class name the entire name is returned lowercased.
     *
     * @return string
     */
    public function getSlug(): string
    {
        // Prefer the filename (YYYY_MM_DD_HHmmss_slug.php) because PHP class
        // names cannot start with a digit, so timestamp-prefix filenames are
        // the canonical source of truth for slug + ordering.
        $ref      = new \ReflectionClass($this);
        $fileName = $ref->getFileName();
        if ($fileName !== false) {
            $base = basename($fileName, '.php');
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $base, $m)) {
                return strtolower($m[1]);
            }
        }
        return static::extractSlugFromName($ref->getShortName());
    }

    /**
     * Returns the YYYY_MM_DD_HHmmss timestamp prefix, or null when unavailable.
     *
     * Checks the migration file's basename first (because PHP class names
     * cannot start with a digit), then falls back to the class short name for
     * legacy non-timestamped conventions.
     *
     * @return string|null
     */
    public function getTimestamp(): ?string
    {
        $ref      = new \ReflectionClass($this);
        $fileName = $ref->getFileName();
        if ($fileName !== false) {
            $base = basename($fileName, '.php');
            $ts   = static::extractTimestampFromName($base);
            if ($ts !== null) {
                return $ts;
            }
        }
        return static::extractTimestampFromName($ref->getShortName());
    }

    // =========================================================================
    // Static extraction helpers (protected so unit test stubs can expose them)
    // =========================================================================

    /**
     * Extracts the slug from a migration class name.
     *
     * Two forms are supported:
     *  - Timestamped: "2024_01_15_120000_create_users_table" → "create_users_table"
     *    (strips the YYYY_MM_DD_HHmmss_ prefix; the remainder is already snake_case)
     *  - CamelCase: "CreateUsersTable" → "create_users_table"
     *    (converts to snake_case so slugs are consistent regardless of naming style)
     *
     * @param string $name Short class name.
     * @return string
     */
    protected static function extractSlugFromName(string $name): string
    {
        // Timestamped names (YYYY_MM_DD_HHmmss_slug) — strip the prefix
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $name, $m)) {
            return strtolower($m[1]);
        }
        // CamelCase names — insert underscore before each uppercase letter that
        // follows a lowercase letter or digit (standard camelCase → snake_case).
        $snake = preg_replace('/(?<!^)(?<![A-Z])[A-Z]/', '_$0', $name);
        return strtolower((string) $snake);
    }

    /**
     * Extracts the YYYY_MM_DD_HHmmss timestamp prefix from a migration class
     * name, or returns null if the name is not timestamped.
     *
     * @param string $name Short class name.
     * @return string|null
     */
    protected static function extractTimestampFromName(string $name): ?string
    {
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $m)) {
            return $m[1];
        }
        return null;
    }

    // =========================================================================
    // Query execution helpers
    // =========================================================================

    /**
     * Adds a SQL query to the execution queue.
     * @param string $query
     */
    protected function addQuery($query)
    {
        $this->queriesToExecute[] = $query;
    }

    /**
     * Executes all queued queries in insertion order.
     *
     * A statement the database rejects does not stop the ones after it. That
     * tolerance is deliberate and load-bearing: a re-run of a migration whose
     * `ALTER TABLE … ADD COLUMN` has already been applied must not abandon the
     * eleven statements behind it, and installations exist with a hundred-odd
     * numbered migrations relying on exactly that.
     *
     * What is *not* deliberate is losing the fact that it happened. Every
     * failure is now recorded on the migration as well as logged, so the
     * runner can record "ran, with N statements rejected" instead of plain
     * success, and `migrate:status` can say so. Nothing here throws, and no
     * statement that merely repeats work stops running.
     *
     * Note the two ways a statement can fail. `Database::query()` throws for an
     * execution error — a `mysqli_sql_exception` carrying the real errno on
     * MySQL, a plain `Exception` on PostgreSQL — but it also has one path that
     * returns false without throwing, when a statement cannot even be prepared.
     * Only checking the exception would have kept missing that one.
     *
     * @return int Number of statements the database rejected in this call.
     */
    protected function executeQueries()
    {
        $failures = 0;

        foreach ($this->queriesToExecute as $query) {
            $this->attemptedStatements++;
            try {
                $result = $this->application->database->query($query);
                if ($result === false) {
                    $failures++;
                    $this->recordFailedStatement(
                        $query,
                        'the statement could not be prepared or executed',
                        0
                    );
                    continue;
                }
                \Pramnos\Logs\Logger::log("\n" . $query . "\n\n", 'upgrades');
            } catch (\Exception $exception) {
                $failures++;
                $this->recordFailedStatement(
                    $query,
                    $exception->getMessage(),
                    (int) $exception->getCode()
                );
                \Pramnos\Logs\Logger::log(
                    $exception->getMessage() . "\n\n" . $query, 'upgradeerrors'
                );
            }
        }
        $this->queriesToExecute = [];

        return $failures;
    }

    /**
     * Remember one rejected statement.
     *
     * @param  string $query
     * @param  string $error
     * @param  int    $code Driver error code where there is one; 0 otherwise.
     * @return void
     */
    private function recordFailedStatement(string $query, string $error, int $code): void
    {
        $this->failedStatements[] = array(
            'query'  => $query,
            'error'  => $error,
            'benign' => static::statementFailureLooksBenign($error, $code),
        );
    }

    /**
     * Does this failure look like "already done" rather than a defect?
     *
     * **This labels; it does not decide anything.** No statement is skipped and
     * no migration is failed on the strength of it — it exists so a report can
     * separate the eleven redundant `ADD COLUMN`s of a re-run from the one
     * statement that names a table nobody created.
     *
     * It has to be a label rather than a gate because it cannot be trusted
     * enough to be one. Only MySQL supplies an error code here: its own
     * `mysqli_sql_exception` propagates with the real errno (1050, 1060, …).
     * PostgreSQL failures arrive as a plain `Exception` whose code is `0` —
     * `Database::setError()` is called with error number `0` on that driver and
     * no SQLSTATE is captured anywhere — so the only discriminator left is the
     * message text, and message text is localisable. Gate on that and a
     * database running with a non-English `lc_messages` would start failing
     * migrations whose statements were merely redundant, which is the one
     * outcome the tolerance exists to prevent.
     *
     * @param  string $error Driver message.
     * @param  int    $code  Driver error code, or 0 when the driver gave none.
     * @return bool
     */
    protected static function statementFailureLooksBenign(string $error, int $code): bool
    {
        // MySQL, where the errno is actually available.
        //   1050 table exists · 1060 duplicate column · 1061 duplicate key
        //   1091 cannot drop, it is not there · 1022/1826 duplicate key name
        if (in_array($code, array(1050, 1060, 1061, 1091, 1022, 1826), true)) {
            return true;
        }

        // PostgreSQL, and MySQL messages that reached us without a code.
        $haystack = strtolower($error);
        foreach (array('already exists', 'duplicate column', 'duplicate key name') as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statements the database rejected, in the order they were attempted.
     *
     * @return array<int, array{query: string, error: string, benign: bool}>
     */
    public function getFailedStatements(): array
    {
        return $this->failedStatements;
    }

    /**
     * Did anything queued through `addQuery()` get rejected?
     *
     * A migration can use this to decide its own outcome — throw, repair, or
     * report — instead of verifying its work against the schema afterwards to
     * find out whether the framework's own report of it was true.
     *
     * @return bool
     */
    public function hasFailedStatements(): bool
    {
        return $this->failedStatements !== array();
    }

    /**
     * One line naming what was rejected, for a ledger row or a report.
     *
     * Empty when nothing failed, so a caller can use it as the condition.
     *
     * @return string
     */
    public function failedStatementSummary(): string
    {
        if ($this->failedStatements === array()) {
            return '';
        }

        $benign = 0;
        foreach ($this->failedStatements as $failure) {
            if ($failure['benign']) {
                $benign++;
            }
        }
        $total = count($this->failedStatements);

        $summary = $total . ' of ' . $this->attemptedStatements . ' statements failed';
        if ($benign > 0) {
            $summary .= ' (' . $benign . ' look like work already applied)';
        }

        foreach ($this->failedStatements as $failure) {
            $summary .= "\n  " . ($failure['benign'] ? '~ ' : '! ')
                . str_replace("\n", ' ', $failure['error']);
        }

        return $summary;
    }

    // =========================================================================
    // Abstract up / down
    // =========================================================================

    /**
     * Apply the migration.
     * @return void
     */
    public function up(): void
    {
    }

    /**
     * Undo the migration.
     * @return void
     */
    public function down(): void
    {
    }
}
