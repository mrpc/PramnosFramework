<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Statistics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Statistics\ActiveUsersService;

/**
 * Unit tests for ActiveUsersService structural contracts.
 *
 * These tests verify the public API shape, the time-window constants,
 * and the getCounts() return structure without hitting the database.
 * Database behaviour is covered by the Integration test suite.
 */
#[CoversClass(ActiveUsersService::class)]
class ActiveUsersServiceTest extends TestCase
{
    /**
     * The five standard time-window constants must exist and have the correct
     * second values. Any change would silently alter the dashboard counts.
     */
    public function testTimeWindowConstantsHaveCorrectValues(): void
    {
        // Assert — each constant is the expected number of seconds
        $this->assertSame(300,     ActiveUsersService::WINDOW_NOW,  'WINDOW_NOW must be 5 minutes (300 s)');
        $this->assertSame(3600,    ActiveUsersService::WINDOW_1H,   'WINDOW_1H must be 3 600 s');
        $this->assertSame(86400,   ActiveUsersService::WINDOW_24H,  'WINDOW_24H must be 86 400 s');
        $this->assertSame(604800,  ActiveUsersService::WINDOW_7D,   'WINDOW_7D must be 604 800 s');
        $this->assertSame(2592000, ActiveUsersService::WINDOW_30D,  'WINDOW_30D must be 2 592 000 s');
    }

    /**
     * getCounts() must return an array with exactly the five expected keys.
     * A missing key would cause a silent zero in the dashboard widget.
     */
    public function testGetCountsReturnsAllExpectedKeys(): void
    {
        // Arrange — stub the DB so queries return 0 without a real connection
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('count')->willReturn(0);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ActiveUsersService($db);

        // Act
        $counts = $svc->getCounts();

        // Assert — shape contract: all five keys present
        $expectedKeys = ['now', 'last_1h', 'last_24h', 'last_7d', 'last_30d'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $counts, "getCounts() must include '$key'");
        }
        $this->assertCount(5, $counts, 'getCounts() must return exactly 5 keys');
    }

    /**
     * getCounts() values must be non-negative integers.
     * A negative count would indicate a query logic error.
     */
    public function testGetCountsReturnsIntegers(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->createStub(\Pramnos\Database\QueryBuilder::class);

        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('count')->willReturn(42);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ActiveUsersService($db);

        // Act
        $counts = $svc->getCounts();

        // Assert — every value is a non-negative integer
        foreach ($counts as $key => $value) {
            $this->assertIsInt($value, "getCounts()['$key'] must be an integer");
            $this->assertGreaterThanOrEqual(0, $value, "getCounts()['$key'] must be non-negative");
        }
    }

    /**
     * countSince() must pass guest=0 as a filter so it counts only
     * authenticated users, never anonymous visitors.
     *
     * Verifying this at the unit level guards against accidentally removing
     * the filter and reporting inflated "active users" numbers.
     */
    public function testCountSinceFiltersOnGuestEqualsZero(): void
    {
        // Arrange — capture the where() call arguments
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['table', 'where', 'count'])
            ->getMock();

        $seenGuestFilter = false;
        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (mixed $col, mixed $val) use ($qb, &$seenGuestFilter): mixed {
                if ($col === 'guest' && $val == 0) {
                    $seenGuestFilter = true;
                }
                return $qb;
            }
        );
        $qb->method('count')->willReturn(5);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ActiveUsersService($db);

        // Act
        $svc->countSince(time() - 300);

        // Assert — the guest=0 filter must have been applied
        $this->assertTrue($seenGuestFilter, 'countSince() must filter WHERE guest = 0');
    }

    /**
     * countAllSince() must NOT apply a guest filter so it counts all sessions
     * (authenticated + anonymous). Used for total-traffic measurements.
     */
    public function testCountAllSinceDoesNotFilterByGuest(): void
    {
        // Arrange
        $db = $this->createStub(\Pramnos\Database\Database::class);
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['table', 'where', 'count'])
            ->getMock();

        $seenGuestFilter = false;
        $qb->method('table')->willReturnSelf();
        $qb->method('where')->willReturnCallback(
            function (mixed $col) use ($qb, &$seenGuestFilter): mixed {
                if ($col === 'guest') {
                    $seenGuestFilter = true;
                }
                return $qb;
            }
        );
        $qb->method('count')->willReturn(10);
        $db->method('queryBuilder')->willReturn($qb);

        $svc = new ActiveUsersService($db);

        // Act
        $svc->countAllSince(time() - 300);

        // Assert — countAllSince should NOT filter on guest
        $this->assertFalse($seenGuestFilter, 'countAllSince() must not add a guest filter');
    }
}
