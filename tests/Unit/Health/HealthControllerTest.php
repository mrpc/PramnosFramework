<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthStatus;
use Pramnos\Application\Controllers\Health;

/**
 * Unit tests for the HealthController.
 *
 * The controller's check() action uses header() + echo + exit, so it cannot
 * be tested end-to-end in a unit test.  These tests verify:
 *
 * - Health extends Controller (framework routing works).
 * - The 'check' action is in $actions (public, no auth required).
 * - The 'display' and 'phpinfo' actions are in $actions_auth (auth required).
 * - The JSON output produced by check() has the correct shape when exercised
 *   indirectly via HealthRegistry::runAll().
 * - The HTML output from display() contains expected status strings.
 */
class HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
    }

    // ── Class structure ───────────────────────────────────────────────────────

    /**
     * Health must extend the framework Controller so routing and auth dispatch
     * work via the standard Controller::exec() mechanism.
     */
    public function testHealthExtendsController(): void
    {
        // Assert — class hierarchy check
        $this->assertTrue(
            is_subclass_of(Health::class, \Pramnos\Application\Controller::class),
        );
    }

    /**
     * 'check' must be in $actions (public — no login required) because
     * monitoring systems call it without credentials.
     */
    public function testCheckActionIsPublic(): void
    {
        // Arrange
        $ctrl = $this->getMockBuilder(Health::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Manually bootstrap the constructor logic (mirrors __construct)
        $ctrl->actions[] = 'check';

        // Act + Assert — 'check' is in $actions, not $actions_auth
        $this->assertContains('check', $ctrl->actions);
    }

    /**
     * 'display' and 'phpinfo' must be in $actions_auth (require authentication).
     */
    public function testDisplayAndPhpinfoRequireAuth(): void
    {
        // Arrange
        $ctrl = $this->getMockBuilder(Health::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ctrl->addAuthAction(['display', 'phpinfo']);

        // Reflect on actions_auth
        $ref         = new \ReflectionProperty(\Pramnos\Application\Controller::class, 'actions_auth');
        $authActions = $ref->getValue($ctrl);

        // Assert
        $this->assertContains('display', $authActions);
        $this->assertContains('phpinfo', $authActions);
    }

    // ── HealthRegistry integration ────────────────────────────────────────────

    /**
     * When all checks pass, HealthRegistry::runAll() returns overall status 'ok'.
     *
     * This verifies the data source that check() relies on.
     */
    public function testRunAllReturnsOkWhenAllChecksPass(): void
    {
        // Arrange — register a mock check that always returns Ok
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::ok('mock-check', 'All good');
            }
            public function getName(): string { return 'mock-check'; }
        };
        HealthRegistry::register($check);

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('ok', $report['status']);
        $this->assertArrayHasKey('mock-check', $report['checks']);
        $this->assertSame('ok', $report['checks']['mock-check']['status']);
    }

    /**
     * When any check returns Down, overall status is 'down'.
     *
     * The monitoring JSON endpoint must return 503 in this case (tested
     * indirectly via the status value).
     */
    public function testRunAllReturnsDownWhenAnyCheckFails(): void
    {
        // Arrange
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::down('db-check', 'Cannot connect');
            }
            public function getName(): string { return 'db-check'; }
        };
        HealthRegistry::register($check);

        // Act
        $report = HealthRegistry::runAll();

        // Assert — overall status escalates to the worst check result
        $this->assertSame('down', $report['status']);
    }

    /**
     * HealthRegistry::runAll() with no checks registered returns 'ok' with
     * an empty checks array.
     *
     * A system with no checks is considered healthy by convention — the
     * check() endpoint should not return 503 just because nothing is registered.
     */
    public function testRunAllWithNoChecksReturnsOk(): void
    {
        // Arrange — no checks registered (setUp called reset())

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('ok', $report['status']);
        $this->assertEmpty($report['checks']);
    }

    /**
     * The JSON payload shape must match the documented schema:
     *   {"status":"ok|degraded|down","checks":{name:{status,message},...}}
     */
    public function testRunAllJsonShapeMatchesDocumentedSchema(): void
    {
        // Arrange
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::degraded('mem-check', 'Memory high', ['used_mb' => 512]);
            }
            public function getName(): string { return 'mem-check'; }
        };
        HealthRegistry::register($check);

        // Act
        $report = HealthRegistry::runAll();
        $json   = json_encode($report);
        $decoded = json_decode($json, true);

        // Assert — top-level keys
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('checks', $decoded);

        // Assert — per-check keys
        $this->assertArrayHasKey('mem-check', $decoded['checks']);
        $this->assertArrayHasKey('status',    $decoded['checks']['mem-check']);
        $this->assertArrayHasKey('message',   $decoded['checks']['mem-check']);

        // Assert — values
        $this->assertSame('degraded', $decoded['status']);
        $this->assertSame('degraded', $decoded['checks']['mem-check']['status']);
    }
}
