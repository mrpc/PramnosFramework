<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Statistics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Statistics\ApiPerformanceService;

/**
 * Unit tests for ApiPerformanceService structural contracts.
 *
 * These tests verify the return shape of getSummary() / getTopSlowEndpoints() /
 * getTopCalledEndpoints() and the graceful-degradation when the tokenactions
 * table does not yet exist. Actual metric values are covered by the Integration
 * test suite.
 */
#[CoversClass(ApiPerformanceService::class)]
class ApiPerformanceServiceTest extends TestCase
{
    /**
     * The three time-window constants must have the correct second values.
     * Changing them silently alters the default reporting windows in the dashboard.
     */
    public function testTimeWindowConstantsHaveCorrectValues(): void
    {
        // Assert
        $this->assertSame(3600,  ApiPerformanceService::WINDOW_1H,  'WINDOW_1H must be 3 600 s');
        $this->assertSame(86400, ApiPerformanceService::WINDOW_24H, 'WINDOW_24H must be 86 400 s');
        $this->assertSame(604800, ApiPerformanceService::WINDOW_7D, 'WINDOW_7D must be 604 800 s');
    }

    /**
     * getSummary() must return an array with all seven documented keys even
     * when there are zero rows in the table. A missing key would cause an
     * undefined-index notice in the dashboard template.
     */
    public function testGetSummaryReturnsAllRequiredKeysWhenTableEmpty(): void
    {
        // Arrange — stub DB returning count = 0
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('count')->willReturn(0);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act
        $summary = $svc->getSummary();

        // Assert — all seven keys must be present
        $expectedKeys = [
            'window_seconds', 'total_requests', 'error_rate',
            'avg_execution_ms', 'p95_execution_ms', 'p99_execution_ms', 'by_status',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $summary, "getSummary() must include '$key'");
        }
        $this->assertSame(0, $summary['total_requests']);
        $this->assertIsArray($summary['by_status']);
    }

    /**
     * The window_seconds key in the summary must reflect the argument passed to
     * getSummary(). This is important so AJAX callers can verify the window they
     * requested and correctly label the time-range in the UI.
     */
    public function testGetSummaryWindowSecondsMatchesArgument(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('count')->willReturn(0);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act — pass a non-default window
        $summary = $svc->getSummary(ApiPerformanceService::WINDOW_7D);

        // Assert
        $this->assertSame(ApiPerformanceService::WINDOW_7D, $summary['window_seconds']);
    }

    /**
     * If the tokenactions table does not exist (fresh install, exception thrown),
     * getSummary() must return a zeroed summary rather than propagating the exception.
     *
     * Without this guarantee a missing table crashes the entire admin dashboard page.
     */
    public function testGetSummaryDoesNotThrowWhenTableMissing(): void
    {
        // Arrange — DB that throws on queryBuilder usage
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('count')->willThrowException(new \Exception('Table not found'));
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act + Assert — no exception propagates
        $summary = $svc->getSummary();
        $this->assertIsArray($summary);
        $this->assertSame(0, $summary['total_requests']);
    }

    /**
     * getTopSlowEndpoints() must return an empty array — not throw — when no
     * rows match (e.g., table just created, no execution_time_ms values yet).
     */
    public function testGetTopSlowEndpointsReturnsEmptyArrayOnNoData(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn(null);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act
        $rows = $svc->getTopSlowEndpoints();

        // Assert
        $this->assertIsArray($rows);
        $this->assertEmpty($rows);
    }

    /**
     * getTopCalledEndpoints() must return an empty array when no data is
     * available. The dashboard widget must tolerate an empty result set.
     */
    public function testGetTopCalledEndpointsReturnsEmptyArrayOnNoData(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('limit')->willReturnSelf();
        $qb->method('get')->willReturn(null);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act
        $rows = $svc->getTopCalledEndpoints();

        // Assert
        $this->assertIsArray($rows);
        $this->assertEmpty($rows);
    }
}
