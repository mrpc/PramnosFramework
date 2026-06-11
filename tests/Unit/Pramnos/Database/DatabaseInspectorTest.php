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
}
