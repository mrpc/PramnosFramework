<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthStatus;

/**
 * Unit tests for the Health subsystem.
 *
 * Covers three closely-related classes tested together:
 *
 * - HealthStatus (enum): Ok / Degraded / Down — worst() comparison.
 * - HealthCheckResult (immutable VO): named constructors ok/degraded/down,
 *   readonly properties, toArray() serialisation.
 * - HealthRegistry (static registry): register/get/getNames/run/runAll,
 *   state reset for test isolation.
 *
 * HealthCheck is an interface; tests implement a lightweight anonymous-class
 * stub that always returns a predetermined HealthCheckResult.
 *
 * Tests verify:
 *   - HealthStatus::worst() returns the more severe of two statuses.
 *   - HealthCheckResult named constructors populate all fields correctly.
 *   - HealthCheckResult::toArray() returns the expected shape.
 *   - HealthRegistry::register() stores and retrieves a check by name.
 *   - HealthRegistry::getNames() lists all registered names.
 *   - HealthRegistry::run() executes a single check and returns its result.
 *   - HealthRegistry::run() throws InvalidArgumentException for unknown names.
 *   - HealthRegistry::runAll() aggregates multiple results and computes overall status.
 *   - HealthRegistry::reset() removes all registered checks.
 */
#[CoversClass(HealthStatus::class)]
#[CoversClass(HealthCheckResult::class)]
#[CoversClass(HealthRegistry::class)]
class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Returns a stub HealthCheck that always returns the given result.
     */
    private function makeCheck(string $name, HealthCheckResult $result): HealthCheck
    {
        return new class ($name, $result) implements HealthCheck {
            public function __construct(
                private readonly string $checkName,
                private readonly HealthCheckResult $checkResult
            ) {}
            public function getName(): string           { return $this->checkName; }
            public function run(): HealthCheckResult    { return $this->checkResult; }
        };
    }

    // =========================================================================
    // HealthStatus — worst()
    // =========================================================================

    /**
     * worst() returns the more severe status.  Ok < Degraded < Down.
     */
    public function testWorstReturnsSevererStatus(): void
    {
        // Ok vs Degraded → Degraded
        $this->assertSame(HealthStatus::Degraded, HealthStatus::Ok->worst(HealthStatus::Degraded));
        $this->assertSame(HealthStatus::Degraded, HealthStatus::Degraded->worst(HealthStatus::Ok));

        // Degraded vs Down → Down
        $this->assertSame(HealthStatus::Down, HealthStatus::Degraded->worst(HealthStatus::Down));
        $this->assertSame(HealthStatus::Down, HealthStatus::Down->worst(HealthStatus::Degraded));

        // Ok vs Down → Down
        $this->assertSame(HealthStatus::Down, HealthStatus::Ok->worst(HealthStatus::Down));
        $this->assertSame(HealthStatus::Down, HealthStatus::Down->worst(HealthStatus::Ok));
    }

    /**
     * worst() with two identical statuses returns self (the calling instance).
     */
    public function testWorstWithIdenticalStatusesReturnsSelf(): void
    {
        $this->assertSame(HealthStatus::Ok,       HealthStatus::Ok->worst(HealthStatus::Ok));
        $this->assertSame(HealthStatus::Degraded, HealthStatus::Degraded->worst(HealthStatus::Degraded));
        $this->assertSame(HealthStatus::Down,     HealthStatus::Down->worst(HealthStatus::Down));
    }

    // =========================================================================
    // HealthStatus — enum values
    // =========================================================================

    /**
     * HealthStatus is a string-backed enum with the values 'ok', 'degraded', 'down'.
     */
    public function testHealthStatusEnumValues(): void
    {
        $this->assertSame('ok',       HealthStatus::Ok->value);
        $this->assertSame('degraded', HealthStatus::Degraded->value);
        $this->assertSame('down',     HealthStatus::Down->value);
    }

    // =========================================================================
    // HealthCheckResult — named constructors
    // =========================================================================

    /**
     * HealthCheckResult::ok() creates a result with HealthStatus::Ok and
     * populates name and message.
     */
    public function testOkNamedConstructorSetsStatus(): void
    {
        // Arrange / Act
        $result = HealthCheckResult::ok('database', 'Connection alive');

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('database',        $result->name);
        $this->assertSame('Connection alive', $result->message);
        $this->assertSame([], $result->details);
    }

    /**
     * HealthCheckResult::ok() with an empty message defaults to 'OK'.
     */
    public function testOkWithEmptyMessageDefaultsToOkString(): void
    {
        // Arrange / Act
        $result = HealthCheckResult::ok('check');

        // Assert — empty message becomes 'OK'
        $this->assertSame('OK', $result->message);
    }

    /**
     * HealthCheckResult::degraded() creates a result with HealthStatus::Degraded.
     */
    public function testDegradedNamedConstructorSetsStatus(): void
    {
        // Arrange / Act
        $result = HealthCheckResult::degraded('disk', 'Disk usage > 80%', ['used' => '85%']);

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
        $this->assertSame('disk',            $result->name);
        $this->assertSame('Disk usage > 80%', $result->message);
        $this->assertSame(['used' => '85%'], $result->details);
    }

    /**
     * HealthCheckResult::down() creates a result with HealthStatus::Down.
     */
    public function testDownNamedConstructorSetsStatus(): void
    {
        // Arrange / Act
        $result = HealthCheckResult::down('redis', 'Connection refused');

        // Assert
        $this->assertSame(HealthStatus::Down,     $result->status);
        $this->assertSame('redis',                $result->name);
        $this->assertSame('Connection refused',   $result->message);
    }

    // =========================================================================
    // HealthCheckResult — toArray()
    // =========================================================================

    /**
     * toArray() returns an array with the four expected keys; status is the
     * string value of the enum (not the enum case).
     */
    public function testToArrayReturnsCorrectShape(): void
    {
        // Arrange
        $result = HealthCheckResult::degraded('memory', 'High usage', ['free_mb' => 50]);

        // Act
        $arr = $result->toArray();

        // Assert — shape
        $this->assertArrayHasKey('status',  $arr);
        $this->assertArrayHasKey('name',    $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('details', $arr);

        // Assert — values
        $this->assertSame('degraded', $arr['status']);
        $this->assertSame('memory',   $arr['name']);
        $this->assertSame('High usage', $arr['message']);
        $this->assertSame(['free_mb' => 50], $arr['details']);
    }

    // =========================================================================
    // HealthRegistry — register / get / getNames
    // =========================================================================

    /**
     * register() stores a check that can be retrieved by name.
     */
    public function testRegisterStoresCheckRetrievableByGet(): void
    {
        // Arrange
        $check = $this->makeCheck('db', HealthCheckResult::ok('db'));

        // Act
        HealthRegistry::register($check);

        // Assert — get() returns the same instance
        $this->assertSame($check, HealthRegistry::get('db'));
    }

    /**
     * get() returns null for an unregistered name.
     */
    public function testGetReturnsNullForUnregisteredName(): void
    {
        // Act / Assert
        $this->assertNull(HealthRegistry::get('nonexistent'));
    }

    /**
     * getNames() lists all registered check names.
     */
    public function testGetNamesListsAllRegisteredNames(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db',    HealthCheckResult::ok('db')));
        HealthRegistry::register($this->makeCheck('cache', HealthCheckResult::ok('cache')));

        // Act
        $names = HealthRegistry::getNames();

        // Assert
        $this->assertContains('db',    $names);
        $this->assertContains('cache', $names);
        $this->assertCount(2, $names);
    }

    /**
     * Registering a check with an already-used name replaces the previous one.
     */
    public function testRegisterReplacesExistingCheckWithSameName(): void
    {
        // Arrange — first registration returns ok
        $first  = $this->makeCheck('db', HealthCheckResult::ok('db'));
        $second = $this->makeCheck('db', HealthCheckResult::down('db', 'Down'));

        // Act
        HealthRegistry::register($first);
        HealthRegistry::register($second);

        // Assert — second registration replaced first
        $this->assertSame($second, HealthRegistry::get('db'));
        $this->assertCount(1, HealthRegistry::getNames());
    }

    // =========================================================================
    // HealthRegistry — run
    // =========================================================================

    /**
     * run() executes the named check and returns its HealthCheckResult.
     */
    public function testRunExecutesNamedCheck(): void
    {
        // Arrange
        $expected = HealthCheckResult::ok('ping', 'Alive');
        HealthRegistry::register($this->makeCheck('ping', $expected));

        // Act
        $result = HealthRegistry::run('ping');

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * run() throws InvalidArgumentException when the check name is unknown.
     */
    public function testRunThrowsForUnknownCheckName(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        HealthRegistry::run('phantom_check');
    }

    // =========================================================================
    // HealthRegistry — runAll
    // =========================================================================

    /**
     * runAll() with all-ok checks returns overall status 'ok'.
     */
    public function testRunAllWithAllOkReturnsOkStatus(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db',    HealthCheckResult::ok('db')));
        HealthRegistry::register($this->makeCheck('cache', HealthCheckResult::ok('cache')));

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('ok', $report['status']);
        $this->assertArrayHasKey('db',    $report['checks']);
        $this->assertArrayHasKey('cache', $report['checks']);
    }

    /**
     * runAll() with a degraded check returns overall status 'degraded'.
     */
    public function testRunAllWithOneDegradedReturnsOkDegraded(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db',    HealthCheckResult::ok('db')));
        HealthRegistry::register($this->makeCheck('cache', HealthCheckResult::degraded('cache', 'Slow')));

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('degraded', $report['status']);
    }

    /**
     * runAll() with a down check returns overall status 'down' even when other
     * checks are ok or degraded.
     */
    public function testRunAllWithOneDownReturnsDownStatus(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db',    HealthCheckResult::ok('db')));
        HealthRegistry::register($this->makeCheck('redis', HealthCheckResult::degraded('redis', 'Slow')));
        HealthRegistry::register($this->makeCheck('queue', HealthCheckResult::down('queue', 'Unreachable')));

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('down', $report['status']);
    }

    /**
     * runAll() with no registered checks returns an empty checks map and 'ok' status.
     */
    public function testRunAllWithNoChecksReturnsOkAndEmptyChecks(): void
    {
        // Arrange — registry is already reset in setUp

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('ok', $report['status']);
        $this->assertSame([], $report['checks']);
    }

    // =========================================================================
    // HealthRegistry — reset
    // =========================================================================

    /**
     * reset() removes all registered checks; after reset getNames() returns [].
     */
    public function testResetRemovesAllChecks(): void
    {
        // Arrange — register a check
        HealthRegistry::register($this->makeCheck('db', HealthCheckResult::ok('db')));
        $this->assertNotEmpty(HealthRegistry::getNames());

        // Act
        HealthRegistry::reset();

        // Assert — empty after reset
        $this->assertEmpty(HealthRegistry::getNames());
        $this->assertNull(HealthRegistry::get('db'));
    }
}
