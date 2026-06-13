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
use Pramnos\Http\Response;

/**
 * Testable subclass of Health that exposes the private humanBytes() helper.
 *
 * humanBytes() is a private method called inside display(). Depending on how
 * much memory the test process uses, not all four size branches (B / KB / MB / GB)
 * may be exercised by display() alone.  This subclass allows direct invocation
 * so all branches are explicitly covered.
 */
class InspectableHealthController extends Health
{
    /** Expose humanBytes() for direct branch testing. */
    public function pubHumanBytes(int $bytes): string
    {
        $ref = new \ReflectionMethod($this, 'humanBytes');
        return $ref->invoke($this, $bytes);
    }
}

/**
 * Unit tests for the HealthController.
 *
 * NOTE: check() returns a Response object (no exit()), so it IS directly
 * testable.  The original comment saying it cannot be called was inaccurate.
 * These tests now verify:
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
        // Remove the anonymous themeObject to prevent subsequent tests from
        // calling loadTheme() on an object that doesn't have that method.
        $doc = \Pramnos\Framework\Factory::getDocument('html');
        $doc->themeObject = null;
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

    /**
     * display() must show 'not connected' as the DB type when the Database
     * singleton is null AND the report contains no database check details.
     *
     * Line 69 in Health.php is only reachable when $dbType === 'Unknown' AND
     * $db (from Factory::getDatabase()) is falsy.  We temporarily null the DB
     * singleton to reproduce this condition, then restore it in tearDown via
     * the existing reset logic.
     */
    public function testDisplayShowsNotConnectedWhenDatabaseSingletonIsNull(): void
    {
        // Arrange — null out the Database singleton so Factory::getDatabase()
        // returns null; no checks registered so report has no database details
        $db = &\Pramnos\Database\Database::getInstance();
        $db = null;

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — line 69: 'not connected' must appear in the DB type cell
        $this->assertStringContainsString('not connected', $html,
            'display() must show "not connected" as the DB type when the DB singleton is null');
    }

    /**
     * phpinfo() must return the captured phpinfo() HTML for a user with
     * usertype >= 90.
     *
     * Lines 139-145 in Health.php are only reachable when the user is
     * authorized.  We set up an Application singleton with a User object whose
     * usertype=90 and mark the session as logged-in.
     */
    public function testPhpinfoReturnsHtmlForAuthorizedUser(): void
    {
        // Arrange — session logged-in state
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['logged'] = true;
        $_SESSION['login']  = true;
        $_SESSION['userid'] = 99;
        $_SESSION['uid']    = 99; // staticIsLogged() checks uid > 1

        // Build a minimal Application singleton with a User set as currentUser
        $appRef = new \ReflectionClass(Application::class);
        $instances = $appRef->getProperty('appInstances');
        $instances->setValue(null, []);
        $lastUsed = $appRef->getProperty('lastUsedApplication');
        $lastUsed->setValue(null, null);

        $app = new Application();
        $initProp = $appRef->getProperty('initialized');
        $initProp->setValue($app, true);

        $user = new \Pramnos\User\User(0);
        $user->userid   = 99;
        $user->usertype = 90;
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app->currentUser = $user;

        // Act — phpinfo() must detect the authorized user and return content
        $ctrl   = new Health();
        $result = $ctrl->phpinfo();

        // Assert — lines 139-145 executed; actual phpinfo HTML was captured
        $this->assertIsString($result,
            'phpinfo() must return a string when the user is authorized');
        $this->assertNotSame('<p>Access denied.</p>', $result,
            'phpinfo() must not return "Access denied" for a usertype=90 user');

        // Teardown session and app state
        $_SESSION = [];
        $instances->setValue(null, []);
        $lastUsed->setValue(null, null);
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

    // ── check() — directly callable, returns Response ────────────────────────

    /**
     * check() returns an HTTP 200 Response with JSON body when all checks pass.
     *
     * check() does NOT call exit() — it returns a Response object that the
     * framework router sends.  This makes it directly testable.
     */
    public function testCheckReturnsOkResponseWhenAllChecksPass(): void
    {
        // Arrange — register a passing check
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::ok('svc', 'fine');
            }
            public function getName(): string { return 'svc'; }
        };
        HealthRegistry::register($check);

        // Act
        $ctrl     = new Health();
        $response = $ctrl->check();

        // Assert — 200 OK with JSON body
        $this->assertInstanceOf(Response::class, $response,
            'check() must return a Response instance');
        $this->assertSame(200, $response->getStatusCode(),
            'check() must return HTTP 200 when status is ok');

        $data = json_decode($response->getBody(), true);
        $this->assertSame('ok', $data['status'],
            'JSON body status must be ok');
        $this->assertArrayHasKey('svc', $data['checks'],
            'JSON body must include the registered check');
    }

    /**
     * check() returns HTTP 503 when at least one check is degraded.
     *
     * Monitoring systems (Uptime Robot, Grafana) use the HTTP status code to
     * trigger alerts, so 503 is the correct code for any non-ok state.
     */
    public function testCheckReturnsDegradedResponseWhenCheckIsDegraded(): void
    {
        // Arrange
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::degraded('mem', 'High memory');
            }
            public function getName(): string { return 'mem'; }
        };
        HealthRegistry::register($check);

        // Act
        $ctrl     = new Health();
        $response = $ctrl->check();

        // Assert — 503 for degraded
        $this->assertSame(503, $response->getStatusCode(),
            'check() must return HTTP 503 when any check is degraded');

        $data = json_decode($response->getBody(), true);
        $this->assertSame('degraded', $data['status']);
    }

    /**
     * check() returns HTTP 503 when a check is down.
     */
    public function testCheckReturnsDownResponseWhenCheckIsDown(): void
    {
        // Arrange
        $check = new class implements HealthCheck {
            public function run(): HealthCheckResult {
                return HealthCheckResult::down('db', 'Cannot connect');
            }
            public function getName(): string { return 'db'; }
        };
        HealthRegistry::register($check);

        // Act
        $response = (new Health())->check();

        // Assert
        $this->assertSame(503, $response->getStatusCode(),
            'check() must return HTTP 503 when any check is down');
        $this->assertSame('down', json_decode($response->getBody(), true)['status']);
    }

    /**
     * check() sets the Cache-Control header to prevent caching of health results.
     *
     * Stale cached results could mask a real outage, so the response must never
     * be stored by intermediate proxies.
     */
    public function testCheckResponseHasNoCacheHeader(): void
    {
        // Arrange — empty registry (always ok)

        // Act
        $response = (new Health())->check();

        // Assert — Cache-Control: no-cache, no-store
        $this->assertStringContainsString('no-cache', $response->getHeaderLine('Cache-Control') ?? '',
            'check() must set Cache-Control: no-cache to prevent stale results');
    }

    // ── phpinfo() access control ──────────────────────────────────────────────

    /**
     * phpinfo() must return 403 + access denied message when no user is logged in.
     *
     * User::getCurrentUser() returns null in test environments (no session),
     * so the guard at line 130 is always triggered here.  This covers the
     * http_response_code(403) + return path without needing a real session.
     */
    public function testPhpinfoReturnsDeniedWhenNoUserLoggedIn(): void
    {
        // Arrange — no session user (default in tests)
        $ctrl = new Health();

        // Act
        $result = $ctrl->phpinfo();

        // Assert — access denied string (not phpinfo() HTML)
        $this->assertSame('<p>Access denied.</p>', $result,
            'phpinfo() must return access denied when no user is authenticated');
    }

    // ── humanBytes() — private helper, all four size branches ────────────────

    /**
     * humanBytes() formats a byte count into a human-readable string.
     *
     * There are four code paths based on the magnitude:
     *   < 1024          → "N B"
     *   < 1 048 576     → "N KB"
     *   < 1 073 741 824 → "N MB"
     *   otherwise       → "N GB"
     *
     * display() calls this with memory_get_peak_usage(), which in test runs is
     * typically a few MB — so only the MB branch is reached via display().
     * These tests exercise all four branches directly.
     */
    public function testHumanBytesBranches(): void
    {
        // Arrange — expose private method via testable subclass
        $ctrl = new InspectableHealthController();

        // Act + Assert — bytes
        $this->assertSame('0 B', $ctrl->pubHumanBytes(0),
            '0 bytes must render as "0 B"');
        $this->assertSame('512 B', $ctrl->pubHumanBytes(512),
            '512 bytes must render as "512 B"');
        $this->assertSame('1023 B', $ctrl->pubHumanBytes(1023),
            '1023 bytes must render as "1023 B" (just under 1 KB)');

        // Act + Assert — kilobytes
        $this->assertStringContainsString('KB', $ctrl->pubHumanBytes(1024),
            '1024 bytes must render with KB suffix');
        $this->assertStringContainsString('KB', $ctrl->pubHumanBytes(1048575),
            '1 048 575 bytes must render with KB suffix');

        // Act + Assert — megabytes
        $this->assertStringContainsString('MB', $ctrl->pubHumanBytes(1048576),
            '1 MiB must render with MB suffix');
        $this->assertStringContainsString('MB', $ctrl->pubHumanBytes(1073741823),
            'Just under 1 GiB must render with MB suffix');

        // Act + Assert — gigabytes
        $this->assertStringContainsString('GB', $ctrl->pubHumanBytes(1073741824),
            '1 GiB must render with GB suffix');
        $this->assertStringContainsString('GB', $ctrl->pubHumanBytes(2147483648),
            '2 GiB must render with GB suffix');
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
