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
}
