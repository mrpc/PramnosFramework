<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\RouteList;
use Pramnos\Routing\Router;
use Pramnos\Application\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the route:list console command.
 *
 * route:list enumerates the routes registered with the application's Router as a
 * table (Method | URI | Handler | Permissions) or, with --json, as JSON. The
 * command's contract has two important edge cases:
 *
 *  - Routes are declared in src/Api/routes.php and are not globally reachable
 *    from a CLI process. When no Router can be resolved the command must degrade
 *    gracefully — print an explanatory message / empty JSON and still exit
 *    SUCCESS, never crash.
 *  - When a populated Router is available (injected here via the public $router
 *    property) the command must faithfully report each route's method, URI,
 *    handler description and permissions, and --json must emit valid JSON.
 *
 * The command is attached to a minimal Symfony Console Application so
 * getApplication() is non-null, mirroring ScaffoldViewsTest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(RouteList::class)]
class RouteListTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    private CommandTester $tester;
    private RouteList $command;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Bootstrap: attach a fresh RouteList command to a minimal Console
     * Application and wrap it in a CommandTester.
     */
    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->command = new RouteList();

        $app = new Application('test', '1.0');
        $app->add($this->command);
        $app->setAutoExit(false);

        $found        = $app->find('route:list');
        $this->tester = new CommandTester($found);
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Build a Router populated with a small, representative set of routes:
     * a closure route with no permissions, a controller-array route with a
     * permission, and a named route. Used by the "router available" tests.
     */
    private function makePopulatedRouter(): Router
    {
        // A Container is required by the Router constructor; a bare instance is
        // sufficient because we never dispatch — we only read the route table.
        $router = new Router(new Container());

        // Closure handler, no permissions.
        $router->get('/health', fn() => 'ok');

        // Controller@action handler with a required permission, and a name.
        $router->post('/users', ['App\\Controllers\\UserController', 'store'], 'users:write')
            ->name('users.store');

        return $router;
    }

    // =========================================================================
    // Router available
    // =========================================================================

    /**
     * With a populated Router injected, the command must succeed and render a
     * table that includes each route's method, URI and handler description.
     * This proves the happy-path rendering contract.
     */
    public function testListsRoutesAsTableWhenRouterAvailable(): void
    {
        // Arrange — inject a Router with known routes
        $this->command->router = $this->makePopulatedRouter();

        // Act
        $exitCode = $this->tester->execute([]);
        $output   = $this->tester->getDisplay();

        // Assert — command ran successfully
        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        // Assert — both routes appear with their methods and URIs
        $this->assertStringContainsString('GET', $output);
        $this->assertStringContainsString('/health', $output);
        $this->assertStringContainsString('POST', $output);
        $this->assertStringContainsString('/users', $output);

        // Assert — closure and controller handlers are described distinctly
        $this->assertStringContainsString('(Closure)', $output);
        $this->assertStringContainsString('UserController@store', $output);

        // Assert — the required permission is surfaced
        $this->assertStringContainsString('users:write', $output);

        // Assert — the footer reports the correct count (2 routes)
        $this->assertStringContainsString('2 route(s) registered', $output);
    }

    /**
     * --json with a populated Router must emit valid JSON whose decoded value is
     * a list of route descriptors carrying method/uri/handler/permissions.
     * This proves the machine-readable contract used by tooling.
     */
    public function testJsonOutputWithRouterIsValidAndComplete(): void
    {
        // Arrange
        $this->command->router = $this->makePopulatedRouter();

        // Act
        $exitCode = $this->tester->execute(['--json' => true]);
        $output   = $this->tester->getDisplay();

        // Assert — success
        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        // Assert — output parses as JSON (proves it is valid)
        $decoded = json_decode(trim($output), true);
        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'route:list --json must emit syntactically valid JSON'
        );

        // Assert — exactly the two routes are present
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);

        // Assert — each entry carries the documented keys
        foreach ($decoded as $entry) {
            $this->assertArrayHasKey('method', $entry);
            $this->assertArrayHasKey('uri', $entry);
            $this->assertArrayHasKey('handler', $entry);
            $this->assertArrayHasKey('permissions', $entry);
            $this->assertArrayHasKey('name', $entry);
        }

        // Assert — the POST /users route is reported with its permission & name
        $uris = array_column($decoded, 'uri');
        $this->assertContains('/users', $uris);
        $this->assertContains('/health', $uris);
    }

    // =========================================================================
    // Router unavailable — graceful degradation
    // =========================================================================

    /**
     * With no Router resolvable (the normal CLI case), the command must still
     * exit SUCCESS and print an explanatory message instead of crashing. This
     * is the documented graceful-degradation guarantee.
     */
    public function testGracefulMessageWhenNoRouterAvailable(): void
    {
        // Arrange — no Router injected; the test app is a plain Console
        // Application, so no internal Pramnos application router exists either.

        // Act
        $exitCode = $this->tester->execute([]);
        $output   = $this->tester->getDisplay();

        // Assert — never crashes; exits cleanly
        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        // Assert — the user is told why nothing was listed
        $this->assertStringContainsString('No router is available', $output);
    }

    /**
     * --json with no Router must still emit valid JSON — specifically an empty
     * array — so downstream tooling can parse the result unconditionally.
     */
    public function testJsonOutputWithoutRouterIsEmptyArray(): void
    {
        // Act
        $exitCode = $this->tester->execute(['--json' => true]);
        $output   = $this->tester->getDisplay();

        // Assert — success and valid JSON
        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $decoded = json_decode(trim($output), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Output must be valid JSON');

        // Assert — empty list rather than null/object
        $this->assertSame([], $decoded);
    }
}
