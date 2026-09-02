<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\Tools\RouteListTool;

/**
 * `route-list` answering from the routes files that are actually on disk.
 *
 * The parser has thirteen tests and they all reach it by reflection, which proves the algorithm and
 * runs none of the code that calls it: finding the candidate files, reading them, stamping each
 * route with the file it came from, applying the filter, and assembling the answer had never
 * executed once. That is the shape of a test suite that covers a private method and not the tool —
 * `parseRoutes()` was green while `execute()` was a straight line of untried code.
 *
 * So these go in through `execute()`, against real files, with the tool pointed at a directory this
 * test owns. `projectRoot()` is `protected` for exactly this reason.
 */
#[CoversClass(RouteListTool::class)]
class RouteListFromDiskTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pramnos-routelist-' . getmypid();
        @mkdir($this->root . '/app', 0777, true);
        @mkdir($this->root . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/app/routes.php', '/routes/api.php', '/routes/web.php', '/routes.php'] as $file) {
            @unlink($this->root . $file);
        }
        @rmdir($this->root . '/app');
        @rmdir($this->root . '/routes');
        @rmdir($this->root);
        parent::tearDown();
    }

    /** A tool that reads the routes files this test wrote, instead of the ones the project has. */
    private function tool(): RouteListTool
    {
        $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        return new class ($application, $this->root) extends RouteListTool {
            public function __construct(Application $app, private readonly string $root)
            {
                parent::__construct($app);
            }

            protected function projectRoot(): string
            {
                return $this->root;
            }
        };
    }

    /**
     * The routes out of an answer, whichever shape the answer has.
     *
     * A console application has no attribute routes — that is the whole reason this tool parses
     * files — so with routes on disk the answer is always the keyed report, and a flat list only
     * ever comes back when a router was already built by an HTTP request. Asserting on
     * `$answer['routes']` in one place keeps every test below about routes rather than about which
     * of the two shapes it happened to get.
     *
     * @param  array<string, mixed>|list<array<string, mixed>> $answer
     * @return list<array<string, mixed>>
     */
    private static function routesIn(array $answer): array
    {
        return array_is_list($answer) ? $answer : ($answer['routes'] ?? []);
    }

    /**
     * The routes in `app/routes.php` come back, each one naming the file it was read from.
     *
     * The file ends in `dispatch()` on purpose: it is what a real one does, and it is the reason
     * the tool parses rather than includes. If this test ever dispatches a request, the tool has
     * started including the file it is describing.
     */
    public function testTheRoutesInTheApplicationsRoutesFileAreListed(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/routes.php', <<<'ROUTES'
        <?php
        $router = \Pramnos\Routing\Router::getInstance();
        $router->get('/posts', 'Posts@index');
        $router->post('/posts', 'Posts@store');
        return $router->dispatch($request);
        ROUTES);

        // Act
        $routes = self::routesIn($this->tool()->execute([]));

        // Assert
        $uris = array_column($routes, 'uri');
        $this->assertContains('/posts', $uris);

        $get = null;
        foreach ($routes as $route) {
            if ($route['uri'] === '/posts' && $route['method'] === 'GET') {
                $get = $route;
            }
        }

        $this->assertNotNull($get, 'the GET route was not listed');
        $this->assertSame('Posts@index', $get['action']);
        $this->assertSame('app/routes.php', $get['file'], 'the route does not say where it came from');
    }

    /**
     * Every candidate location is searched, not just the first one that exists.
     *
     * A project splits its routes across `routes/web.php` and `routes/api.php`; stopping at the
     * first hit would report half an application as the whole of it.
     *
     * The order is the order the candidates are searched in — `app/routes.php`, `routes.php`,
     * `routes/web.php`, `routes/api.php` — and within a file, the order the routes are written in.
     * Only the combined answer is sorted by URI, because only it merges two sources that have no
     * natural order between them. Reading a single file back in the order it was written is the
     * more useful of the two, so this asserts the behaviour rather than changing it.
     */
    public function testAllTheCandidateFilesAreRead(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/routes.php', "<?php\n\$r->get('/one', 'A@one');\n");
        file_put_contents($this->root . '/routes/web.php', "<?php\n\$r->get('/two', 'B@two');\n");
        file_put_contents($this->root . '/routes/api.php', "<?php\n\$r->get('/three', 'C@three');\n");

        // Act
        $routes = self::routesIn($this->tool()->execute([]));

        // Assert
        $this->assertSame(
            ['/one', '/two', '/three'],
            array_column($routes, 'uri'),
            'not every routes file was read'
        );
        $this->assertSame(
            ['app/routes.php', 'routes/web.php', 'routes/api.php'],
            array_column($routes, 'file'),
            'the files are not reported in the order they are searched'
        );
    }

    /**
     * The filter matches a URI or an action, and drops everything else.
     *
     * Applied while reading the files rather than afterwards, so this is the only test that can
     * tell whether the filter in `routesFromFiles()` works — the one in `execute()` covers the
     * attribute routes, which a console application has none of.
     */
    public function testTheFilterMatchesUriOrAction(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/routes.php', <<<'ROUTES'
        <?php
        $r->get('/posts', 'Posts@index');
        $r->get('/users', 'Users@index');
        $r->get('/health', 'Posts@health');
        ROUTES);

        // Act
        $byUri    = self::routesIn($this->tool()->execute(['filter' => 'user']));
        $byAction = self::routesIn($this->tool()->execute(['filter' => 'posts@']));

        // Assert
        $this->assertSame(['/users'], array_column($byUri, 'uri'));
        $this->assertSame(
            ['/posts', '/health'],
            array_column($byAction, 'uri'),
            'a filter on the action should keep /health, whose action is Posts@health'
        );
    }

    /**
     * With routes on disk and no attribute controllers, the answer says so — and still lists them.
     *
     * This is the branch the tool exists for. The console kernel builds no router, so there are
     * never any attribute routes on the path that reaches this tool; answering `No routes found`
     * there reads as a fact about the application rather than a limit of the tool.
     */
    public function testFilesOnlyAnswerCountsTheRoutesAndKeepsTheExplanation(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/routes.php', "<?php\n\$r->get('/only', 'Only@here');\n");

        // Act
        $answer = $this->tool()->execute([]);

        // Assert
        $this->assertArrayHasKey('routes', $answer, 'the files-only answer should be a keyed report');
        $this->assertSame(1, $answer['count']);
        $this->assertSame(['app/routes.php'], $answer['files']);
        $this->assertStringContainsString('attribute', $answer['note']);
        $this->assertSame('/only', $answer['routes'][0]['uri']);
    }

    /**
     * With nothing on disk, the answer names the files it looked for.
     *
     * "No routes found" without that list is unactionable: the reader cannot tell whether the
     * application has no routes or the tool looked in the wrong place.
     */
    public function testAnEmptyProjectReportsWhereItLooked(): void
    {
        // Act — setUp made the directories, and nothing wrote a routes file into them
        $answer = $this->tool()->execute([]);

        // Assert
        $this->assertArrayHasKey('routes_files_searched', $answer);
        $this->assertSame([], $answer['routes_files_searched'], 'no candidate file exists to report');
    }

    /**
     * A filter that matches nothing falls through to the same explanation as an empty project.
     *
     * Not the same code path as no routes at all: the files were found and read, and the filter
     * emptied the result afterwards. The list of files searched still has to be right.
     */
    public function testAFilterThatMatchesNothingStillReportsTheFilesItRead(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/routes.php', "<?php\n\$r->get('/posts', 'Posts@index');\n");

        // Act
        $answer = $this->tool()->execute(['filter' => 'nothing-matches-this']);

        // Assert
        $this->assertArrayHasKey('routes_files_searched', $answer);
        $this->assertSame(['app/routes.php'], $answer['routes_files_searched']);
    }
}
