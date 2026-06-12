<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Statistics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Statistics\DatabaseStatsService;

/**
 * Unit tests for DatabaseStatsService structural contracts.
 *
 * These tests verify the return shape of getStats() and the graceful-
 * degradation behaviour when queries fail, without requiring a real database.
 * Actual metric values are verified by the Integration test suite.
 */
#[CoversClass(DatabaseStatsService::class)]
class DatabaseStatsServiceTest extends TestCase
{
    /**
     * getStats() for PostgreSQL must return an array containing at minimum the
     * six documented keys, including 'type' = 'postgresql'.
     *
     * If a key is missing the dashboard template will throw an undefined-index
     * notice in production.
     */
    public function testGetStatsReturnsRequiredKeysForPostgreSQL(): void
    {
        // Arrange — fake a PostgreSQL database that returns empty results
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $emptyResult       = new \stdClass();
        $emptyResult->numRows = 0;
        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — required keys present
        $requiredKeys = ['type', 'db_size_bytes', 'connections_total', 'connections_active', 'cache_hit_ratio'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $stats, "getStats() must include '$key' for PostgreSQL");
        }
        $this->assertSame('postgresql', $stats['type']);
    }

    /**
     * getStats() for MySQL must return an array containing at minimum the
     * six documented keys, including 'type' = 'mysql'.
     */
    public function testGetStatsReturnsRequiredKeysForMySQL(): void
    {
        // Arrange — fake a MySQL database that returns empty results
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;
        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $requiredKeys = ['type', 'db_size_bytes', 'connections_total', 'connections_active', 'cache_hit_ratio'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $stats, "getStats() must include '$key' for MySQL");
        }
        $this->assertSame('mysql', $stats['type']);
    }

    /**
     * If the database raises an exception (e.g. restricted user with no access
     * to pg_stat_database), getStats() must still return an array — never throw.
     *
     * This is the graceful-degradation contract: the dashboard shows nulls
     * rather than an uncaught exception blowing up the entire page.
     */
    public function testGetStatsDoesNotThrowWhenQueriesFail(): void
    {
        // Arrange — database that throws on every query
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';
        $db->method('query')->willThrowException(new \Exception('access denied'));

        $svc = new DatabaseStatsService($db);

        // Act + Assert — no exception escapes the service
        $stats = $svc->getStats();
        $this->assertIsArray($stats, 'getStats() must return an array even when queries fail');
        $this->assertSame('postgresql', $stats['type']);
    }

    /**
     * cache_hit_ratio must be null when the query returns no rows — it should
     * never be calculated as 0/0 (which would be NaN or a division-by-zero).
     */
    public function testCacheHitRatioIsNullWhenNoData(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;
        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — undefined ratio must be null, not 0 or NaN
        $this->assertNull($stats['cache_hit_ratio'], 'cache_hit_ratio must be null when no stat data is available');
    }

    /**
     * getStats() must include a 'version' key for both backends so the dashboard
     * can display human-readable server information (e.g., "PostgreSQL 14.5").
     *
     * Before this was added, the admin dashboard had no way to show the DB
     * server version; omitting the key causes an undefined-index notice.
     */
    public function testGetStatsIncludesVersionKeyForPostgreSQL(): void
    {
        // Arrange — stub returns no rows (version will be null, but key must exist)
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;
        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — key must be present regardless of whether a value was found
        $this->assertArrayHasKey('version', $stats,
            'getStats() must always include the version key for PostgreSQL');
    }

    /**
     * getStats() must include a 'version' key for MySQL, used by the admin
     * dashboard to display the server version string ("MySQL 8.0.36").
     */
    public function testGetStatsIncludesVersionKeyForMySQL(): void
    {
        // Arrange — stub returns no rows so version falls back to null
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;
        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertArrayHasKey('version', $stats,
            'getStats() must always include the version key for MySQL');
    }

    /**
     * When the version query returns a real row, getStats() must prefix the
     * raw version string with the backend type ("PostgreSQL 14.5").
     *
     * This ensures the dashboard shows "PostgreSQL 14.5" rather than the raw
     * system version string returned by SELECT version().
     */
    public function testPostgreSQLVersionIsPrefixedCorrectly(): void
    {
        // Arrange — first query (version) returns a row; subsequent queries return nothing
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $versionResult          = new \stdClass();
        $versionResult->numRows = 1;
        $versionResult->fields  = ['ver' => 'PostgreSQL 14.5 on x86_64-pc-linux-gnu, compiled by gcc'];

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // SELECT version()
            $emptyResult,    // pg_database_size
            $emptyResult,    // pg_stat_activity
            $emptyResult     // pg_stat_database
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — version must be normalised to "PostgreSQL <x.y>"
        $this->assertSame('PostgreSQL 14.5', $stats['version'],
            'PostgreSQL version must be extracted and prefixed with "PostgreSQL"');
    }

    /**
     * When the MySQL version query returns a row, the version must be prefixed
     * with "MySQL " to distinguish it from PostgreSQL on the dashboard.
     */
    public function testMySQLVersionIsPrefixedCorrectly(): void
    {
        // Arrange — first query (version) returns a row; subsequent queries return nothing
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $versionResult          = new \stdClass();
        $versionResult->numRows = 1;
        $versionResult->fields  = ['ver' => '8.0.36'];

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $statusVar          = new \stdClass();
        $statusVar->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // SELECT VERSION()
            $emptyResult,    // information_schema size query
            $statusVar,      // Threads_connected
            $statusVar,      // Threads_running
            $statusVar,      // Queries
            $statusVar,      // Innodb_buffer_pool_reads
            $statusVar       // Innodb_buffer_pool_read_requests
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertSame('MySQL 8.0.36', $stats['version'],
            'MySQL version must be prefixed with "MySQL "');
    }

    /**
     * When TimescaleDB extension is detected, its version must be appended
     * to the PostgreSQL version string.
     */
    public function testPostgreSQLVersionIncludesTimescaleDBWhenDetected(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $versionResult          = new \stdClass();
        $versionResult->numRows = 1;
        $versionResult->fields  = ['ver' => 'PostgreSQL 15.3 on x86_64-linux'];

        $tsResult          = new \stdClass();
        $tsResult->numRows = 1;
        $tsResult->fields  = ['extversion' => '2.11.1'];

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // SELECT version()
            $tsResult,       // pg_extension timescaledb
            $emptyResult,    // pg_database_size
            $emptyResult,    // pg_stat_activity
            $emptyResult     // pg_stat_database
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — version must include TimescaleDB indicator
        $this->assertStringContainsString('TimescaleDB', $stats['version'],
            'PostgreSQL version must include TimescaleDB version when extension is found');
        $this->assertStringContainsString('2.11.1', $stats['version']);
    }

    /**
     * MySQL cache_hit_ratio must be calculated correctly when InnoDB buffer stats
     * are available.
     */
    public function testMySQLCacheHitRatioIsCalculatedCorrectly(): void
    {
        // Arrange — 1000 requests, 100 reads (disk) → 90% hit ratio
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $makeVar = function(string $name, string $value): object {
            $r = new \stdClass();
            $r->numRows = 1;
            $r->fields  = ['Variable_name' => $name, 'Value' => $value];
            return $r;
        };

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $emptyResult,                                 // SELECT VERSION()
            $emptyResult,                                 // information_schema size
            $makeVar('Threads_connected', '5'),           // Threads_connected
            $makeVar('Threads_running', '2'),             // Threads_running
            $makeVar('Queries', '50000'),                 // Queries
            $makeVar('Innodb_buffer_pool_reads', '100'),  // pool reads (disk)
            $makeVar('Innodb_buffer_pool_read_requests', '1000')  // pool requests
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — (1 - 100/1000) * 100 = 90.0
        $this->assertSame(90.0, $stats['cache_hit_ratio'],
            'MySQL cache_hit_ratio must equal (1 - reads/requests) * 100');
        $this->assertSame(5,     $stats['connections_total']);
        $this->assertSame(2,     $stats['connections_active']);
        $this->assertSame(50000, $stats['queries']);
    }

    /**
     * PostgreSQL connection stats must be parsed when the query returns rows.
     */
    public function testPostgreSQLConnectionStatsAreParsedCorrectly(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $connResult          = new \stdClass();
        $connResult->numRows = 1;
        $connResult->fields  = ['total' => '12', 'active' => '3'];

        $pgStatResult          = new \stdClass();
        $pgStatResult->numRows = 1;
        $pgStatResult->fields  = [
            'blks_hit'      => '950',
            'blks_read'     => '50',
            'xact_commit'   => '4200',
            'xact_rollback' => '8',
        ];

        $db->method('query')->willReturnOnConsecutiveCalls(
            $emptyResult,   // SELECT version()
            $emptyResult,   // TimescaleDB check
            $emptyResult,   // pg_database_size
            $connResult,    // pg_stat_activity
            $pgStatResult   // pg_stat_database
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertSame(12, $stats['connections_total']);
        $this->assertSame(3,  $stats['connections_active']);
        $this->assertSame(4200, $stats['xact_commit']);
        $this->assertSame(8,    $stats['xact_rollback']);
        // cache_hit_ratio = 950 / (950+50) * 100 = 95.0
        $this->assertSame(95.0, $stats['cache_hit_ratio']);
    }

    /**
     * PostgreSQL cache_hit_ratio must be null when blks_hit + blks_read = 0
     * (brand-new database with no activity).
     */
    public function testPostgreSQLCacheHitRatioIsNullWhenNoActivity(): void
    {
        // Arrange — pg_stat_database returns zeros
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $pgStatZero          = new \stdClass();
        $pgStatZero->numRows = 1;
        $pgStatZero->fields  = [
            'blks_hit'      => '0',
            'blks_read'     => '0',
            'xact_commit'   => '0',
            'xact_rollback' => '0',
        ];

        $db->method('query')->willReturnOnConsecutiveCalls(
            $emptyResult,  // version
            $emptyResult,  // timescaledb
            $emptyResult,  // db_size
            $emptyResult,  // connections
            $pgStatZero    // pg_stat_database
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertNull($stats['cache_hit_ratio']);
    }

    /**
     * MySQL queries must be null when no Queries status var is returned.
     */
    public function testMySQLQueriesIsNullWhenStatusVarAbsent(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturn($emptyResult);

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertNull($stats['queries']);
        $this->assertNull($stats['connections_total']);
        $this->assertNull($stats['connections_active']);
    }

    /**
     * When the PostgreSQL version string is non-empty but does not match the
     * "PostgreSQL X.Y" pattern, getStats() must use the raw string as the
     * version value. Covers the `$raw !== '' ? $raw : 'PostgreSQL'` branch (line 70).
     */
    public function testPostgreSQLVersionFallsBackToRawStringWhenPatternDoesNotMatch(): void
    {
        // Arrange — version returns a string that doesn't match "PostgreSQL X.Y"
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'postgresql';

        $versionResult          = new \stdClass();
        $versionResult->numRows = 1;
        $versionResult->fields  = ['ver' => 'CockroachDB CCL v22.2'];

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // SELECT version()
            $emptyResult,    // TimescaleDB check
            $emptyResult,    // pg_database_size
            $emptyResult,    // pg_stat_activity
            $emptyResult     // pg_stat_database
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert — raw string used as-is when pattern doesn't match
        $this->assertSame('CockroachDB CCL v22.2', $stats['version'],
            'Non-matching version must be stored verbatim');
    }

    /**
     * When MySQL returns a real version row, the version string must be prefixed
     * with "MySQL " and stored in the stats array.
     * Covers the `$ver !== null ? 'MySQL ' . $ver : 'MySQL'` branch.
     */
    public function testMySQLVersionIsPrefixedWithMySQLWhenRowFound(): void
    {
        // Arrange — first query (VERSION()) returns a row; all others return empty
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $db->type = 'mysql';

        $versionResult          = new \stdClass();
        $versionResult->numRows = 1;
        $versionResult->fields  = ['ver' => '8.0.36'];

        $emptyResult          = new \stdClass();
        $emptyResult->numRows = 0;

        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // SELECT VERSION()
            $emptyResult,    // information_schema size
            $emptyResult,    // Threads_connected
            $emptyResult,    // Threads_running
            $emptyResult,    // Queries
            $emptyResult,    // Innodb_buffer_pool_reads
            $emptyResult     // Innodb_buffer_pool_read_requests
        );

        $svc = new DatabaseStatsService($db);

        // Act
        $stats = $svc->getStats();

        // Assert
        $this->assertSame('MySQL 8.0.36', $stats['version'],
            'MySQL version must be prefixed with "MySQL " when a version row is returned');
    }
}
