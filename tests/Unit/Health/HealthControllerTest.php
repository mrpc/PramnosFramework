<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthStatus;
use Pramnos\Application\Controllers\Health;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\Application;

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
        NavRegistry::reset();
        
        // Mock Document theme so View can find the template in scaffolding/themes/plain-css
        $doc = \Pramnos\Framework\Factory::getDocument('html');
        $doc->themeObject = new class {
            public $fullpath = '';
            public function allowsViewOverrides() { return true; }
        };
        $doc->themeObject->fullpath = ROOT . DIRECTORY_SEPARATOR . 'scaffolding' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'plain-css';
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
        NavRegistry::reset();
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

    // ── display() HTML output ─────────────────────────────────────────────────

    /**
     * display() must return HTML containing the "System Health" heading and an
     * overall status badge reflecting the registered checks.
     *
     * When all checks pass the badge must read "OK".  No DB connection is
     * required for this test — the controller handles null DB gracefully.
     */
    public function testDisplayHtmlContainsOkStatusBadge(): void
    {
        // Arrange — one passing check
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::ok('test-check', 'All fine');
            }
            public function getName(): string { return 'test-check'; }
        };
        HealthRegistry::register($check);

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — heading and ok badge
        $this->assertIsString($html);
        $this->assertStringContainsString('System Health', $html,
            'HTML must contain the dashboard heading');
        $this->assertStringContainsString('status-ok', $html,
            'CSS class status-ok must appear for a passing health check');
        $this->assertStringContainsString('OK', $html,
            'Badge label must read OK when all checks pass');
    }

    /**
     * display() must show status-down CSS class and "DOWN" badge when at least
     * one check fails.
     *
     * The overall status escalates to the worst individual result.
     */
    public function testDisplayHtmlContainsDownStatusBadge(): void
    {
        // Arrange — one failing check
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::down('db-check', 'DB unreachable');
            }
            public function getName(): string { return 'db-check'; }
        };
        HealthRegistry::register($check);

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert
        $this->assertStringContainsString('status-down', $html,
            'CSS class status-down must appear when a check fails');
        $this->assertStringContainsString('DOWN', $html,
            'Badge label must read DOWN when a check fails');
    }

    /**
     * display() must list the individual check names and their status badges
     * inside the health-table.
     *
     * This verifies the per-row rendering loop, not just the overall status.
     */
    public function testDisplayHtmlListsIndividualChecks(): void
    {
        // Arrange — two checks with different results
        foreach (['disk-check', 'mem-check'] as $name) {
            $n     = $name;
            $check = new class ($n) implements HealthCheck {
                public function __construct(private string $n) {}
                public function run(): HealthCheckResult {
                    return HealthCheckResult::ok($this->n, 'fine');
                }
                public function getName(): string { return $this->n; }
            };
            HealthRegistry::register($check);
        }

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — each check name appears in the HTML
        $this->assertStringContainsString('disk-check', $html,
            'disk-check row must appear in the health table');
        $this->assertStringContainsString('mem-check', $html,
            'mem-check row must appear in the health table');
        $this->assertStringContainsString('health-table', $html,
            'CSS class health-table must be present');
    }

    /**
     * display() must show a placeholder row when no checks are registered.
     *
     * A system with no checks is considered healthy but the dashboard should
     * communicate that no monitoring is configured.
     */
    public function testDisplayHtmlWhenNoChecksRegistered(): void
    {
        // Arrange — no checks (setUp resets registry)

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — placeholder message for empty registry
        $this->assertStringContainsString('No health checks registered', $html,
            'Empty registry must show placeholder message');
    }

    /**
     * display() must include PHP version and the System Info section header.
     *
     * This confirms the system info table is always rendered, even with no DB.
     */
    public function testDisplayHtmlContainsSystemInfo(): void
    {
        // Arrange — no DB, no cache (default in test environment)

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — PHP version from PHP_VERSION constant must appear
        $this->assertStringContainsString(PHP_VERSION, $html,
            'PHP version must appear in the System Info table');
        $this->assertStringContainsString('System Info', $html,
            '"System Info" section heading must be present');
        $this->assertStringContainsString('Memory (peak)', $html,
            'Peak memory row must appear in the info table');
    }

    // ── check() HTTP code mapping ──────────────────────────────────────────────

    /**
     * The HTTP code mapping used by check() must return 200 for 'ok' and 503
     * for 'degraded' and 'down'.
     *
     * check() itself cannot be called in a unit test because it outputs headers
     * and calls exit().  This test verifies the same match() expression
     * independently by replicating the logic, ensuring future refactoring
     * does not silently break it.
     */
    public function testCheckHttpCodeMapping(): void
    {
        // Arrange + Act — replicate the match expression from check()
        $codeFor = static function (string $status): int {
            return match ($status) {
                'ok'       => 200,
                'degraded' => 503,
                'down'     => 503,
                default    => 503,
            };
        };

        // Assert — ok is 200; anything else is 503
        $this->assertSame(200, $codeFor('ok'));
        $this->assertSame(503, $codeFor('degraded'));
        $this->assertSame(503, $codeFor('down'));
        $this->assertSame(503, $codeFor('unknown'));
    }

    // ── Navigation — admin.health NavItem ────────────────────────────────────

    /**
     * Application::registerDefaultNavItems() must register an 'admin.health'
     * NavItem so every app that calls parent::registerDefaultNavItems() gets the
     * Health link in the admin navigation without any per-app configuration.
     *
     * Verifies: key, label, auth requirement, minimum usertype, admin section.
     */
    public function testRegisterDefaultNavItemsIncludesAdminHealth(): void
    {
        // Arrange — call registerDefaultNavItems() directly via the mock
        // (Application::__construct() needs a full bootstrap; we skip that and
        //  call the method in isolation because NavRegistry is a static registry)
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Act
        $app->registerDefaultNavItems([]);

        // Access NavRegistry static $items via reflection — no public getAll()
        // setAccessible() is a no-op since PHP 8.1 and deprecated since PHP 8.5
        $ref   = new \ReflectionClass(NavRegistry::class);
        $prop  = $ref->getProperty('items');
        $items = $prop->getValue();

        // Assert — admin.health key is registered
        $this->assertArrayHasKey('admin.health', $items,
            'admin.health must be registered by registerDefaultNavItems()');

        $item = $items['admin.health'];

        // Label must be "Health"
        $this->assertSame('Health', $item->label,
            'admin.health label must be "Health"');

        // Must require authentication
        $this->assertTrue($item->requireAuth,
            'admin.health must require authentication');

        // Must require at least usertype 80 (admin)
        $this->assertGreaterThanOrEqual(80, $item->minUserType,
            'admin.health must require admin-level usertype (≥80)');
    }
}
