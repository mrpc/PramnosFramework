<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Inspector\DatabaseInspector;

/**
 * Unit tests for DatabaseInspector.
 *
 * DatabaseInspector receives a Database instance via constructor injection,
 * so each method can be exercised with a mock — no real database needed.
 *
 * Paths covered for each of the four public methods:
 *  - MySQL branch and PostgreSQL branch (different SQL issued)
 *  - rows found → fetchAll() data returned
 *  - no rows → empty array
 *  - query throws → empty array (defensive catch)
 *  - PostgreSQL-only methods return [] immediately on MySQL
 */
#[CoversClass(DatabaseInspector::class)]
class DatabaseInspectorTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a mock Database with the given type whose query() returns the
     * supplied result object (or throws when $throw is true).
     */
    private function makeDb(string $type, mixed $result = null, bool $throw = false): Database
    {
        $db = $this->createMock(Database::class);
        $db->type = $type;
        if ($throw) {
            $db->method('query')->willThrowException(new \Exception('boom'));
        } else {
            $db->method('query')->willReturn($result);
        }
        return $db;
    }

    /**
     * Build a Result-like object exposing ->numRows and a fetchAll() method,
     * matching what DatabaseInspector reads.
     */
    private function makeResult(int $numRows, array $rows = []): object
    {
        return new class($numRows, $rows) {
            public function __construct(
                public int $numRows,
                private array $rows
            ) {
            }

            public function fetchAll(): array
            {
                return $this->rows;
            }
        };
    }

    // =========================================================================
    // getProcessList()
    // =========================================================================

    /**
     * getProcessList() on MySQL must run SHOW PROCESSLIST and return all rows.
     */
    public function testGetProcessListMysqlReturnsRows(): void
    {
        // Arrange
        $rows = [['Id' => 1, 'User' => 'root', 'Command' => 'Query']];
        $db   = $this->makeDb('mysql', $this->makeResult(1, $rows));

        // Act
        $list = (new DatabaseInspector($db))->getProcessList();

        // Assert — the raw process rows are passed through unchanged
        $this->assertSame($rows, $list);
    }

    /**
     * getProcessList() on PostgreSQL must query pg_stat_activity and return rows.
     */
    public function testGetProcessListPostgresReturnsRows(): void
    {
        // Arrange
        $rows = [['pid' => 100, 'state' => 'active', 'duration_sec' => 5]];
        $db   = $this->makeDb('postgresql', $this->makeResult(1, $rows));

        // Act
        $list = (new DatabaseInspector($db))->getProcessList();

        // Assert
        $this->assertSame($rows, $list);
    }

    /**
     * getProcessList() must return [] when the query yields no rows.
     */
    public function testGetProcessListReturnsEmptyWhenNoRows(): void
    {
        // Arrange — numRows = 0
        $db = $this->makeDb('mysql', $this->makeResult(0));

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getProcessList());
    }

    /**
     * getProcessList() must swallow query exceptions and return [] —
     * a broken inspector must never take down the DevPanel page.
     */
    public function testGetProcessListReturnsEmptyOnException(): void
    {
        // Arrange — query() throws
        $db = $this->makeDb('mysql', throw: true);

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getProcessList());
    }

    // =========================================================================
    // getTableSizes()
    // =========================================================================

    /**
     * getTableSizes() on MySQL must read information_schema.tables and
     * return the size rows.
     */
    public function testGetTableSizesMysqlReturnsRows(): void
    {
        // Arrange
        $rows = [['table_name' => 'users', 'total_bytes' => 4096, 'row_estimate' => 10]];
        $db   = $this->makeDb('mysql', $this->makeResult(1, $rows));

        // Act + Assert
        $this->assertSame($rows, (new DatabaseInspector($db))->getTableSizes());
    }

    /**
     * getTableSizes() on PostgreSQL must use pg_total_relation_size and
     * return the size rows.
     */
    public function testGetTableSizesPostgresReturnsRows(): void
    {
        // Arrange
        $rows = [['table_name' => 'logs', 'total_bytes' => 8192, 'index_bytes' => 1024]];
        $db   = $this->makeDb('postgresql', $this->makeResult(1, $rows));

        // Act + Assert
        $this->assertSame($rows, (new DatabaseInspector($db))->getTableSizes());
    }

    /**
     * getTableSizes() must return [] for an empty result set.
     */
    public function testGetTableSizesReturnsEmptyWhenNoRows(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', $this->makeResult(0));

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getTableSizes());
    }

    /**
     * getTableSizes() must swallow exceptions and return [].
     */
    public function testGetTableSizesReturnsEmptyOnException(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', throw: true);

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getTableSizes());
    }

    // =========================================================================
    // getReplicationStatus()
    // =========================================================================

    /**
     * getReplicationStatus() must return [] immediately on MySQL without
     * issuing any query — pg_stat_replication does not exist there.
     */
    public function testGetReplicationStatusReturnsEmptyOnMysql(): void
    {
        // Arrange — query() must never be reached, so make it throw
        $db = $this->makeDb('mysql', throw: true);

        // Act + Assert — no exception means the guard short-circuited
        $this->assertSame([], (new DatabaseInspector($db))->getReplicationStatus());
    }

    /**
     * getReplicationStatus() on PostgreSQL must return the standby rows.
     */
    public function testGetReplicationStatusPostgresReturnsRows(): void
    {
        // Arrange
        $rows = [['client_addr' => '10.0.0.2', 'state' => 'streaming', 'lag_sec' => 0]];
        $db   = $this->makeDb('postgresql', $this->makeResult(1, $rows));

        // Act + Assert
        $this->assertSame($rows, (new DatabaseInspector($db))->getReplicationStatus());
    }

    /**
     * getReplicationStatus() must return [] when no standbys are connected.
     */
    public function testGetReplicationStatusReturnsEmptyWhenNoStandbys(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', $this->makeResult(0));

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getReplicationStatus());
    }

    /**
     * getReplicationStatus() must swallow exceptions (e.g. insufficient
     * privileges on pg_stat_replication) and return [].
     */
    public function testGetReplicationStatusReturnsEmptyOnException(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', throw: true);

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getReplicationStatus());
    }

    // =========================================================================
    // getPublicViews()
    // =========================================================================

    /**
     * getPublicViews() must return [] immediately on MySQL — the method is
     * PostgreSQL-specific (public schema views).
     */
    public function testGetPublicViewsReturnsEmptyOnMysql(): void
    {
        // Arrange — query() must never run
        $db = $this->makeDb('mysql', throw: true);

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getPublicViews());
    }

    /**
     * getPublicViews() on PostgreSQL must return the view definition rows.
     */
    public function testGetPublicViewsPostgresReturnsRows(): void
    {
        // Arrange
        $rows = [['view_name' => 'v_active_users', 'view_definition' => 'SELECT ...']];
        $db   = $this->makeDb('postgresql', $this->makeResult(1, $rows));

        // Act + Assert
        $this->assertSame($rows, (new DatabaseInspector($db))->getPublicViews());
    }

    /**
     * getPublicViews() must return [] when the public schema has no views.
     */
    public function testGetPublicViewsReturnsEmptyWhenNoViews(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', $this->makeResult(0));

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getPublicViews());
    }

    /**
     * getPublicViews() must swallow exceptions and return [].
     */
    public function testGetPublicViewsReturnsEmptyOnException(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', throw: true);

        // Act + Assert
        $this->assertSame([], (new DatabaseInspector($db))->getPublicViews());
    }

    /**
     * Capture the SQL an inspector method issues.
     *
     * The other tests here hand back rows whatever the SQL says, which is the
     * right shape for testing the plumbing and the reason an invalid query
     * survived: `getTableSizes()` selected `pg_tables`' column names from
     * `information_schema.tables`, so on PostgreSQL the statement failed, the
     * catch swallowed it and the method returned `[]` — and these tests still
     * passed, because the mock never ran the SQL.
     *
     * A failed query and an empty result are indistinguishable to the caller, so
     * the only thing that can catch this is the statement itself.
     */
    private function captureSql(string $type, callable $call): string
    {
        $captured = '';
        $db = $this->createMock(Database::class);
        $db->type = $type;
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured) {
                $captured = $sql;

                return $this->makeResult(0);
            }
        );

        $call(new DatabaseInspector($db));

        return $captured;
    }

    /**
     * The PostgreSQL table-size query reads the relation that has its columns.
     *
     * `schemaname` and `tablename` exist on `pg_tables`. `information_schema.tables`
     * calls them `table_schema` and `table_name`, so selecting the first pair from
     * the second relation is an error, not a rename.
     */
    public function testTheTableSizeQueryReadsPgTables(): void
    {
        // Act
        $sql = $this->captureSql('postgresql', fn (DatabaseInspector $i) => $i->getTableSizes());

        // Assert
        $this->assertStringContainsString('FROM pg_tables', $sql);
        $this->assertStringNotContainsString('information_schema.tables', $sql,
            'schemaname/tablename do not exist on information_schema.tables');
    }

    /**
     * And the MySQL one still reads information_schema, where its columns live.
     *
     * `table_name`, `data_length` and `index_length` are information_schema
     * columns; MySQL has no `pg_tables`.
     */
    public function testTheMysqlTableSizeQueryStillReadsInformationSchema(): void
    {
        // Act
        $sql = $this->captureSql('mysql', fn (DatabaseInspector $i) => $i->getTableSizes());

        // Assert
        $this->assertStringContainsString('information_schema.tables', $sql);
        $this->assertStringNotContainsString('pg_tables', $sql);
    }

    /**
     * The TimescaleDB job query reads the run columns from where they live.
     *
     * `timescaledb_information.jobs` describes a job's schedule. When it last ran
     * and whether that run succeeded are in `timescaledb_information.job_stats`,
     * one row per job — so selecting `last_run_started_at` from `jobs` is
     * `column … does not exist`, and the catch around it turned that into an empty
     * list. The database dashboard's scheduled-jobs panel was blank on every
     * server, which reads as "no policies configured" rather than as a broken
     * query: the same answer a healthy server with no policies gives.
     *
     * Asserted on the statement, because a mock that returns rows whatever the SQL
     * says cannot catch a query that the server rejects.
     */
    public function testTheTimescaleJobQueryJoinsJobStats(): void
    {
        // Arrange
        $captured = '';
        $db = $this->createMock(Database::class);
        $db->type = 'postgresql';
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured) {
                if (str_contains($sql, 'timescaledb_information.jobs')) {
                    $captured = $sql;
                }

                return $this->makeResult(0);
            }
        );

        // Act — the method directly: getData() returns early when it cannot detect
        // a TimescaleDB version, and a mock has none to detect
        $inspector = new \Pramnos\Database\Inspector\TimescaleInspector($db);
        (new \ReflectionMethod($inspector, 'getScheduledJobs'))->invoke($inspector);

        // Assert
        $this->assertNotSame('', $captured, 'the jobs query must be issued');
        $this->assertStringContainsString('timescaledb_information.job_stats', $captured,
            'the run columns are in job_stats');
        $this->assertStringContainsString('LEFT JOIN', $captured,
            'left, so a job that has never run is still listed');
    }
}
