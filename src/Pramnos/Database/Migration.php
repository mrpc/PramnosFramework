<?php

namespace Pramnos\Database;

/**
 * Base class for database migrations.
 *
 * Phase 4 additions add metadata fields ($feature, $scope, $priority,
 * $dependencies, $autorun) that the MigrationRunner uses for topological
 * sort, cutoff filtering, and history recording. All additions are
 * backward-compatible — existing subclasses continue to work without any
 * changes.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   (C) 2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 */
abstract class Migration extends \Pramnos\Framework\Base
{
    // =========================================================================
    // Legacy properties (v1.1 API — kept for BC)
    // =========================================================================

    /**
     * Version that this migration sets.
     * @var string
     */
    public string $version = '';

    /**
     * Description of the migration.
     * @var string
     */
    public string $description = '';

    /**
     * BC alias for $autorun. Reads and writes delegate to $autorun via PHP 8.4
     * property hooks, so both names always refer to the same value.
     * @deprecated Use $autorun instead.
     */
    public bool $autoExecute {
        get { return $this->autorun; }
        set { $this->autorun = $value; }
    }

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
     * When false, the migration is skipped unless MigrationRunner is called
     * with force=true.  Replaces the legacy $autoExecute flag.
     * @var bool
     */
    public bool $autorun = true;

    // =========================================================================
    // Internal state
    // =========================================================================

    /**
     * List of queries to execute in executeQueries().
     * @var string[]
     */
    protected $queriesToExecute = array();

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
     * Each query is logged; failures are swallowed and logged separately so
     * that a broken statement does not prevent subsequent queries from running.
     */
    protected function executeQueries()
    {
        foreach ($this->queriesToExecute as $query) {
            try {
                $this->application->database->query($query);
                \Pramnos\Logs\Logger::log("\n" . $query . "\n\n", 'upgrades');
            } catch (\Exception $exception) {
                \Pramnos\Logs\Logger::log(
                    $exception->getMessage() . "\n\n" . $query, 'upgradeerrors'
                );
            }
        }
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
