<?php

namespace Pramnos\Database;

/**
 * Orchestrates the execution, rollback, and history tracking of migrations.
 *
 * Core responsibilities:
 *  - Topological sort by dependency graph, priority, and filename timestamp.
 *  - Filtering by autorun flag and migration_cutoff datetime.
 *  - Running pending migrations and recording results in the history table.
 *  - Rolling back the last batch by calling down() and removing history rows.
 *
 * The history table defaults to 'framework_migrations' but can be overridden
 * per-instance (useful for test isolation).
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Database
 */
class MigrationRunner
{
    /** @var Database|null Live database connection (null only in pure-unit-test mode). */
    private ?Database $db;

    /** @var string History table name. */
    private string $historyTable;

    /** @var \Pramnos\Application\Application|null Optional application for maintenance-mode integration. */
    private ?\Pramnos\Application\Application $app;

    /**
     * @param Database|null                             $db           Live database connection. Pass null for unit tests that only use sort/filter methods.
     * @param string                                    $historyTable Name of the migrations history table.
     * @param \Pramnos\Application\Application|null     $app          When provided, MigrationRunner activates maintenance mode for the duration of each run() batch.
     */
    public function __construct(
        ?Database $db = null,
        string $historyTable = 'schemaversion',
        ?\Pramnos\Application\Application $app = null
    ) {
        $this->db           = $db;
        $this->historyTable = $historyTable;
        $this->app          = $app;
    }

    // =========================================================================
    // History table management
    // =========================================================================

    /**
     * Creates the migration history table if it does not already exist.
     * Safe to call multiple times (idempotent — uses CREATE TABLE IF NOT EXISTS
     * on MySQL and the equivalent on PostgreSQL).
     *
     * @throws \RuntimeException When no database connection has been provided.
     */
    public function ensureHistoryTable(): void
    {
        $db = $this->requireDb();

        // Schema matches the urbanwater schemaversion table (`when`, `key`, `extra`)
        // with additional columns for logging (scope, feature, batch, execution_time,
        // result, error_message). `key` is the PRIMARY KEY so each migration slug
        // appears exactly once; retries are handled via UPSERT.
        if ($db->type === 'postgresql') {
            $db->query("CREATE TABLE IF NOT EXISTS \"{$this->historyTable}\" (
                \"when\"          TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                \"key\"           VARCHAR(255)  PRIMARY KEY,
                \"extra\"         VARCHAR(255)  NULL,
                \"scope\"         VARCHAR(255)  NOT NULL DEFAULT 'app',
                \"feature\"       VARCHAR(255)  NULL,
                \"batch\"         INTEGER       NULL,
                \"execution_time\" DOUBLE PRECISION NULL,
                \"result\"        SMALLINT      NOT NULL DEFAULT 1,
                \"error_message\" TEXT          NULL
            )");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->historyTable}` (
                `when`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `key`            VARCHAR(255)  NOT NULL PRIMARY KEY,
                `extra`          VARCHAR(255)  NULL,
                `scope`          VARCHAR(255)  NOT NULL DEFAULT 'app',
                `feature`        VARCHAR(255)  NULL,
                `batch`          INT           NULL,
                `execution_time` DOUBLE        NULL,
                `result`         SMALLINT      NOT NULL DEFAULT 1,
                `error_message`  TEXT          NULL
            )");
        }
    }

    // =========================================================================
    // Main lifecycle methods
    // =========================================================================

    /**
     * Runs all pending migrations from the provided list (minus already-ran ones).
     *
     * All migrations executed in one run() call share the same batch number so
     * they can be rolled back as a unit.
     *
     * @param Migration[] $migrations Full list of migrations to consider.
     * @param array{force?: bool, cutoff?: string} $options
     *   - force: if true, include autorun=false migrations.
     *   - cutoff: YYYY_MM_DD_HHmmss string; skip migrations at or before this point.
     * @param callable|null $onProgress Optional callback invoked immediately after each migration.
     *   Signature: fn(string $event, string $slug, string $errorMessage): void
     *   Events: 'ran' (success) | 'failed' (error — $errorMessage is non-empty).
     * @return array{ran: string[], failed: array<string,string>} ran = slugs; failed = slug → error message.
     */
    public function run(array $migrations, array $options = [], ?callable $onProgress = null): array
    {
        $this->ensureHistoryTable();

        $force  = (bool) ($options['force']  ?? false);
        $cutoff = $options['cutoff'] ?? null;

        // Already-ran slugs are needed for dependency validation: a dep that ran
        // in a previous batch is satisfied and must not trigger "unknown dep" errors.
        $alreadyRan = $this->db !== null ? $this->getRanSlugs() : [];

        // Determine which migrations to attempt (sorted, filtered)
        $candidates = $this->sort($migrations, $alreadyRan);
        $candidates = $this->filterAutorun($candidates, $force);

        if ($cutoff !== null) {
            $candidates = $this->filterCutoff($candidates, $cutoff);
        }

        $pending = $this->getPending($candidates);
        $batch   = $this->nextBatch();

        $ran    = [];
        $failed = [];

        // Activate maintenance mode for the batch duration so that concurrent
        // HTTP requests cannot trigger a second migration run. Skip if
        // maintenance was already active (we must not deactivate it on exit).
        $maintenanceFlag      = $this->maintenanceFlagPath();
        $weStartedMaintenance = false;
        if ($this->app !== null && $maintenanceFlag !== null && !file_exists($maintenanceFlag)) {
            $this->app->startMaintenance('Database migrations in progress');
            $weStartedMaintenance = true;
        }

        try {
            foreach ($pending as $migration) {
                $slug  = $migration->getSlug();
                $start = microtime(true);
                $db    = $this->requireDb();

                // Wrap in a PostgreSQL transaction when the migration opts in.
                // MySQL DDL always causes an implicit COMMIT, so transactional=true
                // has no effect on MySQL and is silently ignored.
                $useTransaction = $migration->transactional && $db->type === 'postgresql';

                if ($useTransaction) {
                    $db->query('BEGIN');
                }

                try {
                    $migration->up();
                    $elapsed = microtime(true) - $start;

                    if ($useTransaction) {
                        $db->query('COMMIT');
                    }

                    $this->recordHistory($migration, $slug, $batch, $elapsed, 1, null);
                    $ran[] = $slug;
                    if ($onProgress !== null) {
                        $onProgress('ran', $slug, '');
                    }
                } catch (\Throwable $e) {
                    $elapsed = microtime(true) - $start;

                    if ($useTransaction) {
                        try { $db->query('ROLLBACK'); } catch (\Throwable) {}
                    }

                    $this->recordHistory($migration, $slug, $batch, $elapsed, 0, $e->getMessage());
                    $failed[$slug] = $e->getMessage();
                    if ($onProgress !== null) {
                        $onProgress('failed', $slug, $e->getMessage());
                    }
                }
            }
        } finally {
            if ($weStartedMaintenance) {
                $this->app->stopMaintenance();
            }
        }

        return ['ran' => $ran, 'failed' => $failed];
    }

    /**
     * Rolls back all migrations in the specified batch (or the most recent
     * batch when no batch is given) by calling their down() methods and
     * removing the corresponding history rows.
     *
     * @param Migration[] $migrations Full list of migrations (needed to resolve down() calls).
     * @param array{batch?: int} $options
     *   - batch: specific batch number to roll back; defaults to the last batch.
     * @param callable|null $onProgress Optional callback: fn(string $event, string $slug, string $error): void.
     *   Event: 'rolledBack' on success.
     * @return array{rolledBack: string[]} Slugs of migrations that were rolled back.
     */
    public function rollback(array $migrations, array $options = [], ?callable $onProgress = null): array
    {
        if ($this->db === null) {
            return ['rolledBack' => []];
        }

        $this->ensureHistoryTable();

        $targetBatch = isset($options['batch']) ? (int) $options['batch'] : $this->getLastBatch();
        if ($targetBatch === null) {
            return ['rolledBack' => []];
        }

        // Fetch slugs of migrations in the target batch (reverse order for rollback)
        $rows = $this->fetchBatchRows($targetBatch);
        if (empty($rows)) {
            return ['rolledBack' => []];
        }

        // Build a slug → Migration map for down() dispatch
        $map = [];
        foreach ($migrations as $m) {
            $map[$m->getSlug()] = $m;
        }

        $rolledBack = [];

        // Roll back in reverse order (last ran = first to roll back)
        foreach (array_reverse($rows) as $row) {
            $slug         = $row['migration'];
            $downSucceeded = true;

            if (isset($map[$slug])) {
                try {
                    $map[$slug]->down();
                } catch (\Throwable $e) {
                    $downSucceeded = false;
                    \Pramnos\Logs\Logger::log(
                        "Rollback failed for {$slug}: " . $e->getMessage(),
                        'upgradeerrors'
                    );
                }
            }

            // Only remove the history row when down() succeeded. A failed
            // rollback leaves the row intact so the migration still appears
            // as "ran" — prevents a silent re-run on a half-reverted schema.
            if ($downSucceeded) {
                $this->deleteHistoryRow($slug);
                $rolledBack[] = $slug;
                if ($onProgress !== null) {
                    $onProgress('rolledBack', $slug, '');
                }
            }
        }

        return ['rolledBack' => $rolledBack];
    }

    /**
     * Rolls back ALL batches in reverse batch order (highest batch first).
     * Equivalent to migrate:reset — returns the full system to a clean state.
     *
     * @param Migration[] $migrations Full list of migrations for down() dispatch.
     * @param callable|null $onProgress Optional callback passed through to rollback().
     * @return array{rolledBack: string[]} All slugs that were rolled back.
     */
    public function rollbackAll(array $migrations, ?callable $onProgress = null): array
    {
        $rolledBack = [];

        do {
            $result     = $this->rollback($migrations, [], $onProgress);
            $rolledBack = array_merge($rolledBack, $result['rolledBack']);
        } while (!empty($result['rolledBack']));

        return ['rolledBack' => $rolledBack];
    }

    /**
     * Returns all rows from the history table, ordered by batch then id.
     * Used by migrate:status to show the full migration state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(): array
    {
        $db    = $this->requireDb();
        $quote = $db->type === 'postgresql' ? '"' : '`';

        $this->ensureHistoryTable();

        $result = $db->query(
            "SELECT * FROM {$quote}{$this->historyTable}{$quote} ORDER BY {$quote}batch{$quote} ASC, {$quote}when{$quote} ASC"
        );

        $rows = [];
        while ($result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    /**
     * Returns the subset of migrations that are still pending (not yet
     * recorded as result=1 in the history table).
     *
     * Failed migrations (result=0) are included so they can be retried.
     *
     * @param Migration[] $migrations
     * @return Migration[]
     */
    public function getPending(array $migrations): array
    {
        if ($this->db === null) {
            return $migrations;
        }

        $this->ensureHistoryTable();
        $ranSlugs = $this->getRanSlugs();

        return $this->filterAlreadyRan($migrations, $ranSlugs);
    }

    // =========================================================================
    // Fast-check (no PHP loading)
    // =========================================================================

    /**
     * Checks whether any slug from the given set has not yet been recorded in
     * the history table, WITHOUT loading the Migration PHP files.
     *
     * Intended for the per-request "is there anything pending?" probe inside
     * Application::exec().  Only when this returns true does Application
     * perform the more expensive full MigrationLoader::loadFromDirectories()
     * + run() cycle.
     *
     * Cutoff filtering mirrors MigrationRunner::filterCutoff(): a migration
     * is considered non-pending when its timestamp is at-or-before the cutoff.
     * Slugs without a timestamp (non-timestamped files) always count as
     * potentially pending if not yet in history.
     *
     * When the history table does not yet exist (fresh install), every slug is
     * treated as pending so that the caller proceeds to the full run() which
     * will create the table via ensureHistoryTable().
     *
     * @param array<string, string> $slugTimestamps [slug => YYYY_MM_DD_HHmmss] as
     *   returned by MigrationLoader::slugsFromDirectories(). Value '' means no timestamp.
     * @param string $cutoff YYYY_MM_DD_HHmmss cutoff (empty = no cutoff).
     * @return bool True if at least one non-cutoff slug has not been run.
     */
    public function hasPendingFromSlugs(array $slugTimestamps, string $cutoff = ''): bool
    {
        if ($this->db === null || empty($slugTimestamps)) {
            return false;
        }

        try {
            $ranSlugs = array_flip($this->getRanSlugs());
        } catch (\Throwable) {
            // History table does not exist yet — everything is pending.
            return true;
        }

        foreach ($slugTimestamps as $slug => $timestamp) {
            if (isset($ranSlugs[$slug])) {
                continue;
            }
            // Apply cutoff: skip migrations at-or-before the cutoff date.
            if ($cutoff !== '' && $timestamp !== '' && strcmp($timestamp, $cutoff) <= 0) {
                continue;
            }
            return true;
        }

        return false;
    }

    // =========================================================================
    // Sort and filter methods (public so unit tests can call them directly)
    // =========================================================================

    /**
     * Returns migrations in the order they should be executed:
     *  1. Topological sort resolving $dependencies (a dep runs before its dependent).
     *  2. Within the same topological "level", lower $priority number runs first.
     *  3. Ties on priority are broken by $getTimestamp() ascending.
     *
     * Dependencies that appear in $alreadyRan (ran in a previous batch) are
     * treated as satisfied and excluded from topological ordering. This allows
     * incremental runs where each call to run() only receives the new batch.
     *
     * @param Migration[] $migrations      Migrations to sort.
     * @param string[]    $alreadyRan      Slugs considered already satisfied.
     * @return Migration[]
     * @throws \RuntimeException On unresolvable or cyclic dependencies.
     */
    public function sort(array $migrations, array $alreadyRan = []): array
    {
        if (empty($migrations)) {
            return [];
        }

        $alreadyRanSet = array_flip($alreadyRan);

        // Build a slug → Migration map
        $map = [];
        foreach ($migrations as $m) {
            $map[$m->getSlug()] = $m;
        }

        // Validate all declared dependencies: must be either in the current set
        // or already satisfied (in $alreadyRan). Truly unknown deps throw.
        foreach ($migrations as $m) {
            foreach ($m->dependencies as $dep) {
                if (!isset($map[$dep]) && !isset($alreadyRanSet[$dep])) {
                    throw new \RuntimeException(
                        "Migration '{$m->getSlug()}' declares unknown dependency '{$dep}'"
                    );
                }
            }
        }

        // Kahn's algorithm for topological sort
        // in-degree[slug] = number of dependencies not yet emitted within the current set.
        // Dependencies satisfied by $alreadyRan do not count as unmet.
        $inDegree   = [];
        $dependents = []; // dep slug → list of slugs that depend on it (within current set)

        foreach ($migrations as $m) {
            $slug = $m->getSlug();
            // Only count deps that are in the current batch (not already satisfied)
            $unmetDeps = array_filter($m->dependencies, fn($d) => isset($map[$d]));
            $inDegree[$slug] = count($unmetDeps);
            foreach ($unmetDeps as $dep) {
                $dependents[$dep][] = $slug;
            }
        }

        // Seed queue with migrations that have no unmet dependencies,
        // sorted by (priority ASC, timestamp ASC) so the natural ordering is
        // preserved when there are no constraints.
        $queue = $this->topoQueue(array_keys(array_filter($inDegree, fn($d) => $d === 0)), $map);

        $sorted = [];

        while (!empty($queue)) {
            $slug    = array_shift($queue);
            $sorted[] = $map[$slug];

            // Reduce in-degree for everything that depends on this slug
            $newReady = [];
            foreach ($dependents[$slug] ?? [] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $newReady[] = $dependent;
                }
            }

            // Merge newly-ready migrations into the queue maintaining priority order.
            // Re-sorting the full queue (rather than inserting at position 0) ensures
            // that a high-priority-number migration becoming ready (e.g. daily_activity_summary
            // at priority 125) does not jump ahead of lower-priority-number siblings
            // (e.g. user_consents at 110, data_processing at 115) that are already queued.
            if (!empty($newReady)) {
                foreach ($newReady as $slug) {
                    $queue[] = $slug;
                }
                $queue = $this->topoQueue($queue, $map);
            }
        }

        // If not all migrations were emitted, there is a cycle
        if (count($sorted) !== count($migrations)) {
            throw new \RuntimeException(
                'Cyclic dependency detected in migration dependency graph'
            );
        }

        return $sorted;
    }

    /**
     * Returns only migrations with autoExecute=true, or all migrations when
     * $force is true.
     *
     * @param Migration[] $migrations
     * @param bool $force
     * @return Migration[]
     */
    public function filterAutorun(array $migrations, bool $force = false): array
    {
        if ($force) {
            return $migrations;
        }
        return array_values(array_filter($migrations, fn(Migration $m) => $m->autoExecute));
    }

    /**
     * Returns only migrations whose filename timestamp is strictly after the
     * given cutoff string (format: YYYY_MM_DD_HHmmss).
     *
     * Migrations with no timestamp (legacy un-timestamped names) always pass
     * through regardless of the cutoff.
     *
     * @param Migration[] $migrations
     * @param string $cutoff YYYY_MM_DD_HHmmss string
     * @return Migration[]
     */
    public function filterCutoff(array $migrations, string $cutoff): array
    {
        return array_values(array_filter($migrations, function (Migration $m) use ($cutoff) {
            $ts = $m->getTimestamp();
            if ($ts === null) {
                // Legacy migration with no timestamp always passes through
                return true;
            }
            // Only include migrations strictly newer than cutoff
            return strcmp($ts, $cutoff) > 0;
        }));
    }

    /**
     * Returns migrations whose slug is not in the provided $ranSlugs set.
     *
     * @param Migration[] $migrations
     * @param string[] $ranSlugs Slugs recorded as result=1 in history.
     * @return Migration[]
     */
    public function filterAlreadyRan(array $migrations, array $ranSlugs): array
    {
        $ranSet = array_flip($ranSlugs);
        return array_values(array_filter($migrations, fn(Migration $m) => !isset($ranSet[$m->getSlug()])));
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Sorts a list of slugs by (priority ASC, timestamp ASC) and returns the
     * ordered array of slugs. Used to populate the Kahn's algorithm queue.
     *
     * @param string[] $slugs
     * @param array<string, Migration> $map
     * @return string[]
     */
    private function topoQueue(array $slugs, array $map): array
    {
        usort($slugs, function (string $a, string $b) use ($map): int {
            $ma = $map[$a];
            $mb = $map[$b];

            // Primary: priority ascending
            $pDiff = $ma->priority <=> $mb->priority;
            if ($pDiff !== 0) {
                return $pDiff;
            }

            // Secondary: timestamp ascending (null timestamps sort last)
            $ta = $ma->getTimestamp();
            $tb = $mb->getTimestamp();

            if ($ta === null && $tb === null) return 0;
            if ($ta === null) return 1;
            if ($tb === null) return -1;

            return strcmp($ta, $tb);
        });

        return $slugs;
    }

    /**
     * Returns the slugs of all migrations recorded as result=1 (success) in
     * the history table.
     *
     * @return string[]
     */
    private function getRanSlugs(): array
    {
        $db = $this->requireDb();

        $quote = $db->type === 'postgresql' ? '"' : '`';
        $result = $db->query(
            "SELECT {$quote}key{$quote} FROM {$quote}{$this->historyTable}{$quote} WHERE {$quote}result{$quote} = 1"
        );

        $slugs = [];
        while ($result->fetch()) {
            $slugs[] = $result->fields['key'];
        }

        return $slugs;
    }

    /**
     * Returns the highest batch number currently in the history table, or null
     * when the table is empty.
     */
    private function getLastBatch(): ?int
    {
        $db    = $this->requireDb();
        $quote = $db->type === 'postgresql' ? '"' : '`';

        $result = $db->query(
            "SELECT MAX({$quote}batch{$quote}) as max_batch FROM {$quote}{$this->historyTable}{$quote}"
        );

        $val = $result->fields['max_batch'] ?? null;
        return ($val !== null) ? (int) $val : null;
    }

    /**
     * Returns the batch number to use for the next run() call (last+1, or 1).
     */
    private function nextBatch(): int
    {
        $last = $this->getLastBatch();
        return ($last !== null) ? $last + 1 : 1;
    }

    /**
     * Fetches all history rows for the given batch number.
     *
     * @return array<int, array{migration: string}>
     */
    private function fetchBatchRows(int $batch): array
    {
        $db    = $this->requireDb();
        $quote = $db->type === 'postgresql' ? '"' : '`';

        $result = $db->query(
            $db->prepareQuery(
                "SELECT {$quote}key{$quote} FROM {$quote}{$this->historyTable}{$quote}
                 WHERE {$quote}batch{$quote} = %d ORDER BY {$quote}when{$quote} ASC",
                $batch
            )
        );

        $rows = [];
        while ($result->fetch()) {
            $rows[] = ['migration' => $result->fields['key']];
        }

        return $rows;
    }

    /**
     * Inserts a row into the history table for a migration execution.
     *
     * @param Migration   $migration    The migration object.
     * @param string      $slug         The migration slug.
     * @param int         $batch        Batch number for this run.
     * @param float       $elapsed      Execution time in seconds.
     * @param int         $result       1 = success, 0 = failed.
     * @param string|null $errorMessage Error message if result=0.
     */
    private function recordHistory(
        Migration $migration,
        string $slug,
        int $batch,
        float $elapsed,
        int $result,
        ?string $errorMessage
    ): void {
        $db    = $this->requireDb();

        $feature      = $migration->feature      !== '' ? $migration->feature : null;
        $extra        = $migration->description  !== '' ? mb_substr($migration->description, 0, 255) : null;
        $errorMessage = ($errorMessage !== null) ? mb_substr($errorMessage, 0, 65535) : null;
        $execTime     = number_format($elapsed, 6, '.', '');

        if ($db->type === 'postgresql') {
            $db->query(
                $db->prepareQuery(
                    "INSERT INTO \"{$this->historyTable}\"
                     (\"key\", \"extra\", \"scope\", \"feature\", \"batch\", \"execution_time\", \"result\", \"error_message\")
                     VALUES (%s, %s, %s, %s, %d, %s, %d, %s)
                     ON CONFLICT (\"key\") DO UPDATE SET
                       \"when\" = NOW(), \"extra\" = EXCLUDED.\"extra\",
                       \"scope\" = EXCLUDED.\"scope\", \"feature\" = EXCLUDED.\"feature\",
                       \"batch\" = EXCLUDED.\"batch\", \"execution_time\" = EXCLUDED.\"execution_time\",
                       \"result\" = EXCLUDED.\"result\", \"error_message\" = EXCLUDED.\"error_message\"",
                    $slug, $extra, $migration->scope, $feature, $batch, $execTime, $result, $errorMessage
                )
            );
        } else {
            $db->query(
                $db->prepareQuery(
                    "INSERT INTO `{$this->historyTable}`
                     (`key`, `extra`, `scope`, `feature`, `batch`, `execution_time`, `result`, `error_message`)
                     VALUES (%s, %s, %s, %s, %d, %s, %d, %s)
                     ON DUPLICATE KEY UPDATE
                       `extra` = VALUES(`extra`), `scope` = VALUES(`scope`),
                       `feature` = VALUES(`feature`), `batch` = VALUES(`batch`),
                       `execution_time` = VALUES(`execution_time`),
                       `result` = VALUES(`result`), `error_message` = VALUES(`error_message`)",
                    $slug, $extra, $migration->scope, $feature, $batch, $execTime, $result, $errorMessage
                )
            );
        }
    }

    /**
     * Removes all history rows for the given migration slug.
     */
    private function deleteHistoryRow(string $slug): void
    {
        $db    = $this->requireDb();
        $quote = $db->type === 'postgresql' ? '"' : '`';

        $db->query(
            $db->prepareQuery(
                "DELETE FROM {$quote}{$this->historyTable}{$quote} WHERE {$quote}key{$quote} = %s",
                $slug
            )
        );
    }

    /**
     * Returns the absolute path to the maintenance flag file, or null when the
     * ROOT constant is not defined (CLI/test environments without a full app).
     */
    private function maintenanceFlagPath(): ?string
    {
        if (!defined('ROOT')) {
            return null;
        }
        return ROOT . \DS . 'var' . \DS . 'MAINTENANCE';
    }

    /**
     * Returns the database connection or throws when none was provided.
     * This allows sort/filter methods to be called without a DB in unit tests.
     *
     * @throws \RuntimeException
     */
    private function requireDb(): Database
    {
        if ($this->db === null) {
            throw new \RuntimeException(
                'MigrationRunner requires a Database connection for history table operations'
            );
        }
        return $this->db;
    }
}
