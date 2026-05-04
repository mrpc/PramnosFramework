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

    /**
     * @param Database|null $db           Live database connection. Pass null for unit tests that only use sort/filter methods.
     * @param string        $historyTable Name of the migrations history table.
     */
    public function __construct(?Database $db = null, string $historyTable = 'framework_migrations')
    {
        $this->db           = $db;
        $this->historyTable = $historyTable;
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

        if ($db->type === 'postgresql') {
            $db->query("CREATE TABLE IF NOT EXISTS \"{$this->historyTable}\" (
                id             SERIAL PRIMARY KEY,
                migration      VARCHAR(255)  NOT NULL,
                scope          VARCHAR(255)  NOT NULL DEFAULT 'app',
                feature        VARCHAR(255)  NULL,
                batch          INTEGER       NULL,
                execution_time DOUBLE PRECISION NULL,
                result         SMALLINT      NOT NULL DEFAULT 1,
                error_message  TEXT          NULL,
                description    VARCHAR(255)  NULL,
                ran_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW()
            )");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->historyTable}` (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                migration      VARCHAR(255)  NOT NULL,
                scope          VARCHAR(255)  NOT NULL DEFAULT 'app',
                feature        VARCHAR(255)  NULL,
                batch          INT           NULL,
                execution_time DOUBLE        NULL,
                result         SMALLINT      NOT NULL DEFAULT 1,
                error_message  TEXT          NULL,
                description    VARCHAR(255)  NULL,
                ran_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
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
     * @return array{ran: string[], failed: string[]} Slugs of ran and failed migrations.
     */
    public function run(array $migrations, array $options = []): array
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

        foreach ($pending as $migration) {
            $slug  = $migration->getSlug();
            $start = microtime(true);

            try {
                $migration->up();
                $elapsed = microtime(true) - $start;

                $this->recordHistory($migration, $slug, $batch, $elapsed, 1, null);
                $ran[] = $slug;
            } catch (\Throwable $e) {
                $elapsed = microtime(true) - $start;

                $this->recordHistory($migration, $slug, $batch, $elapsed, 0, $e->getMessage());
                $failed[] = $slug;
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
     * @return array{rolledBack: string[]} Slugs of migrations that were rolled back.
     */
    public function rollback(array $migrations, array $options = []): array
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
            $slug = $row['migration'];
            if (isset($map[$slug])) {
                try {
                    $map[$slug]->down();
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log(
                        "Rollback failed for {$slug}: " . $e->getMessage(),
                        'upgradeerrors'
                    );
                }
            }

            $this->deleteHistoryRow($slug);
            $rolledBack[] = $slug;
        }

        return ['rolledBack' => $rolledBack];
    }

    /**
     * Rolls back ALL batches in reverse batch order (highest batch first).
     * Equivalent to migrate:reset — returns the full system to a clean state.
     *
     * @param Migration[] $migrations Full list of migrations for down() dispatch.
     * @return array{rolledBack: string[]} All slugs that were rolled back.
     */
    public function rollbackAll(array $migrations): array
    {
        $rolledBack = [];

        do {
            $result     = $this->rollback($migrations);
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
            "SELECT * FROM {$quote}{$this->historyTable}{$quote} ORDER BY batch ASC, id ASC"
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

            // Merge newly-ready into queue in correct order
            if (!empty($newReady)) {
                $insertable = $this->topoQueue($newReady, $map);
                array_splice($queue, 0, 0, $insertable);
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
            "SELECT migration FROM {$quote}{$this->historyTable}{$quote} WHERE result = 1"
        );

        $slugs = [];
        while ($result->fetch()) {
            $slugs[] = $result->fields['migration'];
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
            "SELECT MAX(batch) as max_batch FROM {$quote}{$this->historyTable}{$quote}"
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
                "SELECT migration FROM {$quote}{$this->historyTable}{$quote}
                 WHERE batch = %d ORDER BY id ASC",
                $batch
            )
        );

        $rows = [];
        while ($result->fetch()) {
            $rows[] = ['migration' => $result->fields['migration']];
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
        $quote = $db->type === 'postgresql' ? '"' : '`';

        $feature      = $migration->feature      !== '' ? $migration->feature : null;
        $description  = $migration->description  !== '' ? $migration->description : null;
        $errorMessage = ($errorMessage !== null) ? mb_substr($errorMessage, 0, 65535) : null;

        if ($db->type === 'postgresql') {
            $db->query(
                $db->prepareQuery(
                    "INSERT INTO \"{$this->historyTable}\"
                     (migration, scope, feature, batch, execution_time, result, error_message, description, ran_at)
                     VALUES (%s, %s, %s, %d, %s, %d, %s, %s, NOW())",
                    $slug,
                    $migration->scope,
                    $feature,
                    $batch,
                    number_format($elapsed, 6, '.', ''),
                    $result,
                    $errorMessage,
                    $description
                )
            );
        } else {
            $db->query(
                $db->prepareQuery(
                    "INSERT INTO `{$this->historyTable}`
                     (migration, scope, feature, batch, execution_time, result, error_message, description)
                     VALUES (%s, %s, %s, %d, %s, %d, %s, %s)",
                    $slug,
                    $migration->scope,
                    $feature,
                    $batch,
                    number_format($elapsed, 6, '.', ''),
                    $result,
                    $errorMessage,
                    $description
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
                "DELETE FROM {$quote}{$this->historyTable}{$quote} WHERE migration = %s",
                $slug
            )
        );
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
