<?php

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthStatus;
use Pramnos\Database\Database;
use Pramnos\Health\Checks\DatabaseConnectivityCheck;
use Pramnos\Health\Checks\DiskSpaceCheck;
use Pramnos\Health\Checks\MemoryLimitCheck;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the Health Check subsystem.
 *
 * These tests cover HealthStatus, HealthCheckResult, HealthRegistry, and all
 * built-in checks: DiskSpaceCheck, MemoryLimitCheck, and DatabaseConnectivityCheck
 * (tested with a fake Database double — no live DB required for these unit tests).
 */
#[CoversClass(DatabaseConnectivityCheck::class)]
#[CoversClass(DiskSpaceCheck::class)]
#[CoversClass(MemoryLimitCheck::class)]
#[CoversClass(HealthCheckResult::class)]
#[CoversClass(HealthRegistry::class)]
#[CoversClass(HealthStatus::class)]
class HealthCheckUnitTest extends TestCase
{
    protected function setUp(): void
    {
        HealthRegistry::reset();
    }

    // =========================================================================
    // HealthStatus
    // =========================================================================

    /**
     * HealthStatus::worst() must return the more severe of two statuses.
     * Severity order: Ok < Degraded < Down.
     */
    public function testWorstReturnsMoreSevereStatus(): void
    {
        // Arrange & Act & Assert
        $this->assertSame(HealthStatus::Degraded, HealthStatus::Ok->worst(HealthStatus::Degraded));
        $this->assertSame(HealthStatus::Down,     HealthStatus::Degraded->worst(HealthStatus::Down));
        $this->assertSame(HealthStatus::Down,     HealthStatus::Ok->worst(HealthStatus::Down));
    }

    /**
     * worst() must be commutative when the two statuses are different — the
     * result must be the same regardless of which side is the receiver.
     */
    public function testWorstIsNotOrderDependent(): void
    {
        // Arrange
        $a = HealthStatus::Ok;
        $b = HealthStatus::Down;

        // Act & Assert — both orderings must give the same (worst) result
        $this->assertSame(HealthStatus::Down, $a->worst($b));
        $this->assertSame(HealthStatus::Down, $b->worst($a));
    }

    /**
     * worst() of equal statuses must return the same status.
     */
    public function testWorstOfEqualStatusReturnsSameStatus(): void
    {
        foreach (HealthStatus::cases() as $status) {
            $this->assertSame($status, $status->worst($status));
        }
    }

    /**
     * HealthStatus values must serialise to their string representations.
     */
    public function testStatusValues(): void
    {
        $this->assertSame('ok',       HealthStatus::Ok->value);
        $this->assertSame('degraded', HealthStatus::Degraded->value);
        $this->assertSame('down',     HealthStatus::Down->value);
    }

    // =========================================================================
    // HealthCheckResult
    // =========================================================================

    /**
     * Named constructors ok(), degraded(), down() must create results with the
     * correct status enum and default message.
     */
    public function testNamedConstructorsSetCorrectStatus(): void
    {
        // Arrange & Act
        $ok       = HealthCheckResult::ok('test', 'all good');
        $degraded = HealthCheckResult::degraded('test', 'slow');
        $down     = HealthCheckResult::down('test', 'unreachable');

        // Assert
        $this->assertSame(HealthStatus::Ok,       $ok->status);
        $this->assertSame(HealthStatus::Degraded, $degraded->status);
        $this->assertSame(HealthStatus::Down,     $down->status);
    }

    /**
     * ok() with no explicit message must default to the string 'OK'.
     */
    public function testOkDefaultsMessage(): void
    {
        $result = HealthCheckResult::ok('test');
        $this->assertSame('OK', $result->message);
    }

    /**
     * toArray() must return all four canonical keys with the correct types and
     * values, ready for JSON serialisation.
     */
    public function testToArrayContainsAllKeys(): void
    {
        // Arrange
        $result = HealthCheckResult::ok('db', 'Reachable', ['latency_ms' => 1.5]);

        // Act
        $arr = $result->toArray();

        // Assert
        $this->assertSame('ok',         $arr['status']);
        $this->assertSame('db',         $arr['name']);
        $this->assertSame('Reachable',  $arr['message']);
        $this->assertSame(['latency_ms' => 1.5], $arr['details']);
    }

    /**
     * Results must be immutable — the readonly properties must not be
     * settable after construction.
     */
    public function testResultPropertiesAreReadonly(): void
    {
        // Arrange
        $ref = new \ReflectionClass(HealthCheckResult::class);

        // Assert — all four properties are readonly
        foreach (['status', 'name', 'message', 'details'] as $prop) {
            $this->assertTrue(
                $ref->getProperty($prop)->isReadOnly(),
                "Property '{$prop}' must be readonly"
            );
        }
    }

    // =========================================================================
    // HealthRegistry
    // =========================================================================

    /**
     * A registered check must be retrievable by name via get().
     */
    public function testRegisterAndGetCheck(): void
    {
        // Arrange
        $check = $this->makeCheck('db', HealthStatus::Ok);

        // Act
        HealthRegistry::register($check);

        // Assert
        $this->assertSame($check, HealthRegistry::get('db'));
    }

    /**
     * Registering a check with the same name twice must replace the first
     * registration (last write wins).
     */
    public function testRegisterOverwritesSameName(): void
    {
        // Arrange
        $first  = $this->makeCheck('db', HealthStatus::Ok, 'first');
        $second = $this->makeCheck('db', HealthStatus::Down, 'second');

        // Act
        HealthRegistry::register($first);
        HealthRegistry::register($second);

        // Assert — second check replaces the first
        $this->assertSame($second, HealthRegistry::get('db'));
    }

    /**
     * get() must return null for an unknown check name rather than throwing.
     */
    public function testGetReturnsNullForUnknownName(): void
    {
        $this->assertNull(HealthRegistry::get('nonexistent'));
    }

    /**
     * getNames() must return all registered check names.
     */
    public function testGetNamesReturnsAllNames(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db'));
        HealthRegistry::register($this->makeCheck('disk'));

        // Act
        $names = HealthRegistry::getNames();

        // Assert
        $this->assertContains('db',   $names);
        $this->assertContains('disk', $names);
    }

    /**
     * run() must execute the check and return its result.
     */
    public function testRunExecutesCheck(): void
    {
        // Arrange
        $check = $this->makeCheck('memory', HealthStatus::Ok, 'all fine');
        HealthRegistry::register($check);

        // Act
        $result = HealthRegistry::run('memory');

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('all fine', $result->message);
    }

    /**
     * run() must throw InvalidArgumentException for an unregistered name.
     */
    public function testRunThrowsForUnknownCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HealthRegistry::run('does_not_exist');
    }

    /**
     * runAll() must aggregate results and set the overall status to the worst
     * individual status.
     */
    public function testRunAllAggregatesWorstStatus(): void
    {
        // Arrange — one OK check and one Down check
        HealthRegistry::register($this->makeCheck('a', HealthStatus::Ok));
        HealthRegistry::register($this->makeCheck('b', HealthStatus::Down));

        // Act
        $report = HealthRegistry::runAll();

        // Assert — overall must be 'down'
        $this->assertSame('down', $report['status']);
        $this->assertArrayHasKey('a', $report['checks']);
        $this->assertArrayHasKey('b', $report['checks']);
    }

    /**
     * runAll() on an empty registry must return status 'ok' with no checks.
     */
    public function testRunAllWithNoChecksReturnsOk(): void
    {
        // Arrange — registry is empty after setUp() reset
        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('ok', $report['status']);
        $this->assertEmpty($report['checks']);
    }

    /**
     * reset() must remove all registered checks so the registry is clean for
     * the next test.
     */
    public function testResetClearsAllChecks(): void
    {
        // Arrange
        HealthRegistry::register($this->makeCheck('db'));
        $this->assertNotEmpty(HealthRegistry::getNames());

        // Act
        HealthRegistry::reset();

        // Assert
        $this->assertEmpty(HealthRegistry::getNames());
    }

    // =========================================================================
    // Built-in checks (no DB required)
    // =========================================================================

    /**
     * DiskSpaceCheck must return a result (any status) without throwing.
     * The actual status depends on available disk on the test machine.
     */
    public function testDiskSpaceCheckRuns(): void
    {
        // Arrange
        $check = new DiskSpaceCheck();

        // Act — must not throw
        $result = $check->run();

        // Assert — a valid HealthCheckResult is returned
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('disk_space', $result->name);
        $this->assertArrayHasKey('free_mb', $result->details);
        $this->assertArrayHasKey('used_pct', $result->details);
    }

    /**
     * DiskSpaceCheck must report Down when free space is below the down
     * threshold.  We achieve this by setting both thresholds extremely high
     * (always above any real value).
     */
    public function testDiskSpaceCheckReportsDownWhenBelowDownThreshold(): void
    {
        // Arrange — thresholds impossibly high to force a Down result
        $check = new DiskSpaceCheck('.', PHP_INT_MAX, PHP_INT_MAX);

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
    }

    /**
     * DiskSpaceCheck must report Degraded when free space is between the
     * degraded threshold and the down threshold.
     */
    public function testDiskSpaceCheckReportsDegradedWhenBelowDegradedThreshold(): void
    {
        // Arrange — degradedThreshold = very high, downThreshold = 0
        // This means free > downThreshold but free < degradedThreshold → Degraded
        $check = new DiskSpaceCheck('.', PHP_INT_MAX, 0);

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
    }

    /**
     * DiskSpaceCheck must report OK when free space exceeds both thresholds.
     */
    public function testDiskSpaceCheckReportsOkWhenAboveThresholds(): void
    {
        // Arrange — thresholds of 0 MB mean any free space is "above"
        $check = new DiskSpaceCheck('.', 0, 0);

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    /**
     * DiskSpaceCheck must return a Down result with "Could not read disk space"
     * when disk_free_space() returns false for the given path.
     *
     * Line 43 in DiskSpaceCheck.php is only reachable when the path argument
     * causes disk_free_space() to return false (e.g. a non-existent path).
     * An E_WARNING is emitted but suppressed so PHPUnit does not flag it.
     */
    public function testDiskSpaceCheckReturnsDownWhenPathIsInvalid(): void
    {
        // Arrange — pass a non-existent path; disk_free_space() returns false
        // (emits E_WARNING which we suppress to keep test output clean)
        $invalidPath = '/tmp/pramnos_disk_space_nonexistent_path_' . bin2hex(random_bytes(4));
        $check = new DiskSpaceCheck($invalidPath);

        // Act — PHP warning is suppressed; the check must handle the false return
        $result = @$check->run();

        // Assert — line 43: HealthCheckResult::down('disk_space', 'Could not read disk space')
        $this->assertSame(HealthStatus::Down, $result->status,
            'DiskSpaceCheck must report Down when disk_free_space() returns false');
        $this->assertStringContainsString('Could not read disk space', $result->message,
            'DiskSpaceCheck must include "Could not read disk space" in the Down message');
    }

    /**
     * MemoryLimitCheck must return a result without throwing.
     */
    public function testMemoryLimitCheckRuns(): void
    {
        // Arrange
        $check = new MemoryLimitCheck();

        // Act
        $result = $check->run();

        // Assert
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('memory_limit', $result->name);
    }

    /**
     * MemoryLimitCheck must report Down when current usage exceeds the down
     * threshold (set to 0% to always trigger).
     */
    public function testMemoryLimitCheckReportsDownAtZeroThreshold(): void
    {
        // Arrange — 0% thresholds mean any usage is "above down threshold"
        $check = new MemoryLimitCheck(0.0, 0.0);

        // Act
        $result = $check->run();

        // Assert — any memory usage (>0%) should be at least Down
        $limitBytes = (int) ini_get('memory_limit');
        if ($limitBytes === -1) {
            // No limit — check returns Ok with a note; nothing to assert about status
            $this->assertSame('memory_limit', $result->name);
        } else {
            $this->assertSame(HealthStatus::Down, $result->status);
        }
    }

    /**
     * MemoryLimitCheck must report OK when thresholds are set extremely high
     * (current usage will never exceed them in a test environment).
     */
    public function testMemoryLimitCheckReportsOkAtHighThresholds(): void
    {
        // Arrange — thresholds of 100% mean only 100% usage is "over threshold"
        $check = new MemoryLimitCheck(100.0, 100.0);

        // Act
        $result = $check->run();

        // Assert
        $this->assertNotSame(HealthStatus::Down, $result->status);
    }

    /**
     * MemoryLimitCheck must report Degraded when usage exceeds the degraded
     * threshold but remains below the down threshold.
     *
     * Uses degradedPct=0.0 (any usage triggers degraded) and downPct=100.0
     * (usage never reaches 100% in tests), so the result is always Degraded
     * in a normally-configured environment.
     *
     * Covers the `if ($pct >= $this->degradedPct)` true branch at lines 67–72.
     */
    public function testMemoryLimitCheckReportsDegradedBetweenThresholds(): void
    {
        // Arrange — degraded at 0%, down only at 100% (never reached in tests)
        $check = new MemoryLimitCheck(0.0, 100.0);

        // Act
        $result = $check->run();

        // Assert — if memory_limit is set, usage ≥ 0% triggers Degraded (not Down)
        $limitBytes = (int) ini_get('memory_limit');
        if ($limitBytes === -1) {
            // No limit configured — check returns OK; accept it
            $this->assertSame('memory_limit', $result->name);
        } else {
            $this->assertSame(HealthStatus::Degraded, $result->status,
                'With degraded=0% and down=100%, any memory usage must be reported as Degraded');
        }
    }

    /**
     * MemoryLimitCheck must return OK with "No memory limit configured" when
     * the PHP memory_limit is set to -1 (unlimited).
     *
     * Covers the `if ($limitBytes <= 0)` true branch at lines 40–46 and the
     * parseMemoryLimit() -1 path at lines 93–94.
     */
    public function testMemoryLimitCheckReturnsOkWhenLimitIsUnlimited(): void
    {
        // Arrange — temporarily override memory_limit to simulate unlimited
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '-1');

        try {
            $check  = new MemoryLimitCheck();
            $result = $check->run();

            // Assert — unlimited memory is treated as OK with a descriptive message
            $this->assertSame(HealthStatus::Ok, $result->status,
                'Unlimited memory_limit must be reported as OK');
            $this->assertStringContainsString('No memory limit', $result->message,
                'Message must mention that no memory limit is configured');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * parseMemoryLimit() must correctly convert 'M' (megabytes) and 'K' (kilobytes)
     * suffixes to their byte equivalents. Covers the 'M' and 'K' match arms at
     * lines 102–103 in parseMemoryLimit().
     *
     * The private method is exercised indirectly by manipulating php.ini.
     */
    public function testMemoryLimitCheckParsesMegabytesAndKilobytes(): void
    {
        // Arrange — memory limit in megabytes; thresholds at 100% so we get OK
        $original = ini_get('memory_limit');

        try {
            // Test M suffix
            ini_set('memory_limit', '128M');
            $checkM = new MemoryLimitCheck(100.0, 100.0);
            $resultM = $checkM->run();
            $this->assertSame(HealthStatus::Ok, $resultM->status,
                '128M limit with high thresholds must produce OK');
            $this->assertArrayHasKey('limit_mb', $resultM->details,
                'Result must include limit_mb in details');
            $this->assertEqualsWithDelta(128.0, $resultM->details['limit_mb'], 0.01,
                '128M must be parsed as 128 MB');

            // Test K suffix
            ini_set('memory_limit', '131072K'); // 128 MB in kilobytes
            $checkK = new MemoryLimitCheck(100.0, 100.0);
            $resultK = $checkK->run();
            $this->assertSame(HealthStatus::Ok, $resultK->status,
                '131072K limit with high thresholds must produce OK');
            $this->assertEqualsWithDelta(128.0, $resultK->details['limit_mb'], 0.01,
                '131072K must be parsed as 128 MB');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * parseMemoryLimit() must return the raw integer value when no unit suffix
     * is present (plain byte count). Covers the `default` match arm at line 104.
     *
     * Uses a 128 MB limit expressed as raw bytes to verify the no-suffix path.
     */
    public function testMemoryLimitCheckParsesPlainByteCount(): void
    {
        // Arrange — limit expressed as plain byte count (no G/M/K suffix)
        $original = ini_get('memory_limit');
        $limitBytes = 134217728; // 128 MB in bytes

        try {
            ini_set('memory_limit', (string) $limitBytes);
            $check  = new MemoryLimitCheck(100.0, 100.0);
            $result = $check->run();

            // Assert — limit is treated as raw bytes; limit_mb ≈ 128 MB
            $this->assertSame(HealthStatus::Ok, $result->status);
            $this->assertEqualsWithDelta(128.0, $result->details['limit_mb'], 0.01,
                'Plain byte-count limit must be parsed correctly by the default match arm');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    // =========================================================================
    // DatabaseConnectivityCheck (tested with fake DB double)
    // =========================================================================

    /**
     * DatabaseConnectivityCheck::run() must return an OK result when the database
     * query succeeds. This is the happy path that confirms the DB is reachable.
     */
    public function testDatabaseConnectivityCheckReturnsOkWhenQuerySucceeds(): void
    {
        // Arrange — mock DB whose query() returns a truthy result object
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $db->method('query')->willReturn(new \stdClass());

        $check = new DatabaseConnectivityCheck($db);

        // Act
        $result = $check->run();

        // Assert — healthy DB connection reports OK
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('database', $result->name);
        $this->assertArrayHasKey('latency_ms', $result->details);
        $this->assertArrayHasKey('driver', $result->details);
        $this->assertSame('mysql', $result->details['driver']);
    }

    /**
     * DatabaseConnectivityCheck::run() must return a Down result when the
     * query() call returns false. This indicates the DB is reachable but the
     * query failed — a misconfigured or read-restricted connection.
     */
    public function testDatabaseConnectivityCheckReturnsDownWhenQueryReturnsFalse(): void
    {
        // Arrange — mock DB whose query() returns false
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn(false);

        $check = new DatabaseConnectivityCheck($db);

        // Act
        $result = $check->run();

        // Assert — query failure → down
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertSame('database', $result->name);
        $this->assertStringContainsString('no result', strtolower($result->message));
    }

    /**
     * DatabaseConnectivityCheck::run() must return a Down result and include the
     * exception message when query() throws. This covers network timeouts, auth
     * failures, and other hard DB errors.
     */
    public function testDatabaseConnectivityCheckReturnsDownWhenQueryThrows(): void
    {
        // Arrange — mock DB whose query() throws a RuntimeException
        $db = $this->createMock(Database::class);
        $db->method('query')->willThrowException(new \RuntimeException('Connection refused'));

        $check = new DatabaseConnectivityCheck($db);

        // Act
        $result = $check->run();

        // Assert — exception path → down with error details in message
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('Connection refused', $result->message);
    }

    /**
     * DatabaseConnectivityCheck::run() must return a Down result when query()
     * returns null (some DB drivers return null instead of false on failure).
     */
    public function testDatabaseConnectivityCheckReturnsDownWhenQueryReturnsNull(): void
    {
        // Arrange — mock DB whose query() returns null
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn(null);

        $check = new DatabaseConnectivityCheck($db);

        // Act
        $result = $check->run();

        // Assert — null result is falsy and treated the same as false → down
        $this->assertSame(HealthStatus::Down, $result->status);
    }

    /**
     * DatabaseConnectivityCheck::getName() must return 'database' — the fixed
     * identifier used by HealthRegistry to index the check.
     */
    public function testDatabaseConnectivityCheckName(): void
    {
        // Arrange — getName() never calls query(), so any mock will do
        $db    = $this->createMock(Database::class);
        $check = new DatabaseConnectivityCheck($db);

        // Act & Assert
        $this->assertSame('database', $check->getName());
    }

    // =========================================================================
    // HealthCheck interface
    // =========================================================================

    /**
     * Any class that implements HealthCheck and never throws in run() must be
     * accepted by HealthRegistry without modification — this verifies the
     * open/closed property: add new checks without touching the registry.
     */
    public function testCustomCheckCanBeRegisteredAndRun(): void
    {
        // Arrange — anonymous implementation of HealthCheck
        $custom = new class implements HealthCheck {
            public function getName(): string { return 'custom'; }
            public function run(): HealthCheckResult {
                return HealthCheckResult::degraded($this->getName(), 'Slightly slow');
            }
        };

        // Act
        HealthRegistry::register($custom);
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('degraded', $report['status']);
        $this->assertSame('degraded', $report['checks']['custom']['status']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Creates a simple HealthCheck stub that always returns a fixed result.
     */
    private function makeCheck(
        string       $name,
        HealthStatus $status  = HealthStatus::Ok,
        string       $message = ''
    ): HealthCheck {
        return new class($name, $status, $message) implements HealthCheck {
            public function __construct(
                private string       $n,
                private HealthStatus $s,
                private string       $m
            ) {}

            public function getName(): string      { return $this->n; }
            public function run(): HealthCheckResult {
                return new HealthCheckResult($this->s, $this->n, $this->m ?: 'ok');
            }
        };
    }
}
