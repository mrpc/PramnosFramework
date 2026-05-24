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
}
