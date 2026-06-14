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

    /**
     * When total_requests > 0 getSummary() must delegate to computeErrorRate(),
     * computeAvg(), computePercentile(), and computeByStatus(). Lines 73-77
     * are only reached when the first count() call returns a positive integer.
     * This test also exercises the MySQL percentile path (lines 211-224) and
     * the computeErrorRate/computeAvg non-null branches (lines 166, 177).
     */
    public function testGetSummaryPopulatesMetricsWhenDataPresent(): void
    {
        // Arrange — build one QueryBuilder stub per private-method call,
        // then wire them via willReturnOnConsecutiveCalls so each sequential
        // call to $db->queryBuilder() receives the right stub.

        $db = $this->createMock(\Pramnos\Database\Database::class);

        // QB 0: initial count in getSummary() — returns 5 so the > 0 branch is taken
        $qbTotal = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbTotal->method('table')->willReturnSelf();
        $qbTotal->method('where')->willReturnSelf();
        $qbTotal->method('whereNotNull')->willReturnSelf();
        $qbTotal->method('count')->willReturn(5);

        // QB 1: computeErrorRate — errors count (400+)
        $qbErrors = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbErrors->method('table')->willReturnSelf();
        $qbErrors->method('where')->willReturnSelf();
        $qbErrors->method('whereNotNull')->willReturnSelf();
        $qbErrors->method('count')->willReturn(1);

        // QB 2: computeErrorRate — total-with-status count (needed for round() branch)
        $qbWithStatus = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbWithStatus->method('table')->willReturnSelf();
        $qbWithStatus->method('where')->willReturnSelf();
        $qbWithStatus->method('whereNotNull')->willReturnSelf();
        $qbWithStatus->method('count')->willReturn(4); // error_rate = 1/4*100 = 25%

        // QB 3: computeAvg — avg() returns non-zero to cover the round() branch
        $qbAvg = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbAvg->method('table')->willReturnSelf();
        $qbAvg->method('where')->willReturnSelf();
        $qbAvg->method('whereNotNull')->willReturnSelf();
        $qbAvg->method('avg')->willReturn(120.750);

        // QB 4 & 5: computePercentile(95) and computePercentile(99) — MySQL path
        // count > 0 so the LIMIT/OFFSET path (lines 211-224) is executed
        $qbPct95 = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbPct95->method('table')->willReturnSelf();
        $qbPct95->method('where')->willReturnSelf();
        $qbPct95->method('whereNotNull')->willReturnSelf();
        $qbPct95->method('count')->willReturn(10);

        $qbPct99 = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbPct99->method('table')->willReturnSelf();
        $qbPct99->method('where')->willReturnSelf();
        $qbPct99->method('whereNotNull')->willReturnSelf();
        $qbPct99->method('count')->willReturn(10);

        // QB 6: computeByStatus — get() returns null (empty map is fine here)
        $qbStatus = $this->createStub(\Pramnos\Database\QueryBuilder::class);
        $qbStatus->method('table')->willReturnSelf();
        $qbStatus->method('select')->willReturnSelf();
        $qbStatus->method('where')->willReturnSelf();
        $qbStatus->method('whereNotNull')->willReturnSelf();
        $qbStatus->method('groupBy')->willReturnSelf();
        $qbStatus->method('orderBy')->willReturnSelf();
        $qbStatus->method('get')->willReturn(null);

        $db->method('queryBuilder')->willReturnOnConsecutiveCalls(
            $qbTotal, $qbErrors, $qbWithStatus, $qbAvg, $qbPct95, $qbPct99, $qbStatus
        );

        // prepareQuery is called twice (once per percentile) — return a placeholder SQL
        $db->method('prepareQuery')->willReturn(
            "SELECT execution_time_ms FROM tokenactions ORDER BY execution_time_ms LIMIT 1 OFFSET 0"
        );

        // query() is called twice — return a fake result with numRows > 0
        $pctResult = new class {
            public int $numRows = 1;
            /** @var array<string, mixed> */
            public array $fields = ['execution_time_ms' => '95.000'];
        };
        $db->method('query')->willReturn($pctResult);

        $svc = new ApiPerformanceService($db);

        // Act
        $summary = $svc->getSummary();

        // Assert
        $this->assertSame(5, $summary['total_requests'],
            'total_requests must reflect the mocked count value');
        $this->assertEqualsWithDelta(25.0, $summary['error_rate'], 0.01,
            'error_rate must be 1/4 * 100 = 25% when one of four tracked requests is an error');
        $this->assertEqualsWithDelta(120.75, $summary['avg_execution_ms'], 0.001,
            'avg_execution_ms must round the mocked avg value');
        $this->assertNotNull($summary['p95_execution_ms'],
            'p95_execution_ms must be non-null when the percentile query returns a row');
        $this->assertNotNull($summary['p99_execution_ms'],
            'p99_execution_ms must be non-null when the percentile query returns a row');
        $this->assertIsArray($summary['by_status']);
    }

    /**
     * getTopSlowEndpoints() must catch any exception thrown by the QueryBuilder
     * and return an empty array rather than propagating the error. This ensures
     * the dashboard does not crash on missing columns (pre-v1.2 installs).
     */
    public function testGetTopSlowEndpointsReturnEmptyArrayOnException(): void
    {
        // Arrange — QueryBuilder throws when limit() is called
        $db  = $this->createStub(\Pramnos\Database\Database::class);
        $qb  = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('limit')->willThrowException(new \Exception('Column missing'));
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act + Assert — exception must not propagate
        $rows = $svc->getTopSlowEndpoints();
        $this->assertIsArray($rows);
        $this->assertEmpty($rows, 'getTopSlowEndpoints() must return [] on exception');
    }

    /**
     * getTopCalledEndpoints() must catch any exception thrown by the QueryBuilder
     * and return an empty array. Same guarantee as getTopSlowEndpoints().
     */
    public function testGetTopCalledEndpointsReturnEmptyArrayOnException(): void
    {
        // Arrange — QueryBuilder throws when limit() is called
        $db  = $this->createStub(\Pramnos\Database\Database::class);
        $qb  = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('limit')->willThrowException(new \Exception('Column missing'));
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act + Assert
        $rows = $svc->getTopCalledEndpoints();
        $this->assertIsArray($rows);
        $this->assertEmpty($rows, 'getTopCalledEndpoints() must return [] on exception');
    }

    /**
     * computePercentile() must return null immediately when the row count is zero.
     * This guards against a divide-by-zero / invalid OFFSET in the SQL query
     * (line 208: early return null when $count === 0).
     */
    public function testComputePercentileReturnsNullWhenCountIsZero(): void
    {
        // Arrange — DB whose queryBuilder returns count = 0 for the percentile query
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('count')->willReturn(0);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act — invoke private computePercentile() via Reflection
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'computePercentile');
        $result = $method->invoke($svc, time() - 3600, 95);

        // Assert
        $this->assertNull($result,
            'computePercentile() must return null when the table has no execution_time_ms rows');
    }

    /**
     * rowsFromResult() must convert each fetched row into a typed associative
     * array and return them in order. This is the primary data-mapping path
     * exercised by getTopSlowEndpoints() and getTopCalledEndpoints() (lines 272-286).
     */
    public function testRowsFromResultReturnsTypedRows(): void
    {
        // Arrange — a fake DB result that yields two rows then stops
        $rows = [
            ['urlid' => '42',   'avg_ms' => '123.456', 'request_count' => '10'],
            ['urlid' => '7',    'avg_ms' => '88.100',  'request_count' => '3'],
        ];
        $fakeResult = new class($rows) {
            /** @var array<string, mixed> */
            public array $fields = [];
            /** @var list<array<string, mixed>> */
            private array $rows;
            private int $idx = -1;
            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows) { $this->rows = $rows; }
            public function fetch(): bool {
                $this->idx++;
                if (isset($this->rows[$this->idx])) {
                    $this->fields = $this->rows[$this->idx];
                    return true;
                }
                return false;
            }
        };

        $db  = $this->createStub(\Pramnos\Database\Database::class);
        $svc = new ApiPerformanceService($db);

        // Act — invoke private rowsFromResult() via Reflection
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'rowsFromResult');
        $result = $method->invoke($svc, $fakeResult, ['urlid' => 'int', 'avg_ms' => 'float', 'request_count' => 'int']);

        // Assert — two rows, correctly cast
        $this->assertCount(2, $result,
            'rowsFromResult() must return one entry per fetched row');
        $this->assertSame(42,      $result[0]['urlid'],         'urlid must be cast to int');
        $this->assertEqualsWithDelta(123.456, $result[0]['avg_ms'], 0.001, 'avg_ms must be cast to float');
        $this->assertSame(10,      $result[0]['request_count'], 'request_count must be cast to int');
        $this->assertSame(7,       $result[1]['urlid']);
        $this->assertEqualsWithDelta(88.1,   $result[1]['avg_ms'], 0.001);
    }

    /**
     * computeByStatus() must iterate the DB result and build a [status => count]
     * map from the fetched rows (lines 248-250). The method is covered here via
     * Reflection since getSummary() passes an empty result in the main test.
     */
    public function testComputeByStatusBuildsStatusMap(): void
    {
        // Arrange — fake DB result with two status-group rows
        $rows = [
            ['return_status' => '200', 'cnt' => '42'],
            ['return_status' => '404', 'cnt' => '5'],
        ];
        $fakeResult = new class($rows) {
            /** @var array<string, mixed> */
            public array $fields = [];
            /** @var list<array<string, mixed>> */
            private array $rows;
            private int $idx = -1;
            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows) { $this->rows = $rows; }
            public function fetch(): bool {
                $this->idx++;
                if (isset($this->rows[$this->idx])) {
                    $this->fields = $this->rows[$this->idx];
                    return true;
                }
                return false;
            }
        };

        $db  = $this->createMock(\Pramnos\Database\Database::class);
        $qb  = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('whereNotNull')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('get')->willReturn($fakeResult);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ApiPerformanceService($db);

        // Act — invoke private computeByStatus() via Reflection
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'computeByStatus');
        $map = $method->invoke($svc, time() - 3600);

        // Assert — map must contain both status codes with integer counts
        $this->assertArrayHasKey(200, $map, 'status 200 must appear in the map');
        $this->assertArrayHasKey(404, $map, 'status 404 must appear in the map');
        $this->assertSame(42, $map[200], 'count for status 200 must be cast to int');
        $this->assertSame(5,  $map[404], 'count for status 404 must be cast to int');
    }

    /**
     * rowsFromResult() default match arm (line 283) fires when the type map
     * contains a type other than 'int' or 'float'. The value must be cast to
     * string via the default arm of the match expression.
     */
    public function testRowsFromResultUsesStringDefaultCast(): void
    {
        // Arrange — single row with a 'string' type column
        $rows = [['endpoint' => '/api/v1/users']];
        $fakeResult = new class($rows) {
            /** @var array<string, mixed> */
            public array $fields = [];
            private array $rows;
            private int $idx = -1;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function fetch(): bool {
                $this->idx++;
                if (isset($this->rows[$this->idx])) {
                    $this->fields = $this->rows[$this->idx];
                    return true;
                }
                return false;
            }
        };

        $db  = $this->createStub(\Pramnos\Database\Database::class);
        $svc = new ApiPerformanceService($db);

        // Act — type 'string' triggers the default arm of the match in rowsFromResult
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'rowsFromResult');
        $result = $method->invoke($svc, $fakeResult, ['endpoint' => 'string']);

        // Assert — value must be preserved as a string, not cast to int or float
        $this->assertCount(1, $result);
        $this->assertSame('/api/v1/users', $result[0]['endpoint'],
            'default match arm must cast the value to string');
    }

    /**
     * computePercentile() catch block (lines 226-227) fires when the QueryBuilder
     * throws. The method must return null rather than propagating the exception
     * so that getSummary() can still return a partial result.
     */
    public function testComputePercentileCatchesDbException(): void
    {
        // Arrange — DB whose queryBuilder() throws immediately
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('queryBuilder')->willThrowException(new \Exception('DB unavailable'));

        $svc = new ApiPerformanceService($db);

        // Act — invoke via Reflection; exception must be swallowed
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'computePercentile');
        $result = $method->invoke($svc, time() - 3600, 95);

        // Assert — null is the expected graceful-degradation return
        $this->assertNull($result,
            'computePercentile() must return null when a DB exception is thrown');
    }

    /**
     * computeByStatus() catch block (lines 253-254) fires when the QueryBuilder
     * throws. The method must return an empty array rather than propagating the
     * exception so that getSummary() continues without a by_status breakdown.
     */
    public function testComputeByStatusCatchesDbException(): void
    {
        // Arrange — DB whose queryBuilder() throws immediately
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('queryBuilder')->willThrowException(new \Exception('DB unavailable'));

        $svc = new ApiPerformanceService($db);

        // Act — invoke via Reflection; exception must be swallowed
        $method = new \ReflectionMethod(ApiPerformanceService::class, 'computeByStatus');
        $result = $method->invoke($svc, time() - 3600);

        // Assert — empty array is the expected graceful-degradation return
        $this->assertSame([], $result,
            'computeByStatus() must return [] when a DB exception is thrown');
    }
}
