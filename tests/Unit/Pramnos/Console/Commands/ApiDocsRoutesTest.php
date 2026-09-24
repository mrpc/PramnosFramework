<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ApiDocs;
use Pramnos\Routing\Router;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `api:docs` reading the router, and refusing to write nothing.
 *
 * The generator read `#[Route]` attributes and could read nothing else. An application
 * that registers its routes on the router — which is what the framework's own dispatcher
 * reads, and what the scaffolder generates in `src/Api/routes.php` — got
 * `Wrote 0 path(s), 0 operation(s)`, **over the previous document**, with an exit code of
 * 0 and a line that reads like success.
 *
 * One installation carried a ten-day-old fossil describing four scaffold endpoints while
 * its real API had ninety-one addresses, because an empty scan had overwritten the good
 * one quietly.
 *
 * Two things had to be true to close that: the routes have to be readable, and a scan
 * that finds nothing must not be allowed to destroy what is there.
 */
#[CoversClass(ApiDocs::class)]
#[CoversClass(\Pramnos\Routing\OpenApiGenerator::class)]
#[CoversClass(Router::class)]
class ApiDocsRoutesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/apidocs_routes_' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/src/Api', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);

        // Collecting is process-wide. A test that left it on would make every later
        // dispatch() in the suite silently do nothing.
        $this->assertFalse(Router::isCollecting(), 'the collector was left running');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function tester(): CommandTester
    {
        $command = new ApiDocs();
        $command->targetBaseDir = $this->tmp;

        return new CommandTester($command);
    }

    /** Writes a route file shaped exactly like the one `init` scaffolds. */
    private function writeRouteFile(): string
    {
        $path = $this->tmp . '/src/Api/routes.php';
        file_put_contents($path, <<<'PHP_ROUTES'
<?php
declare(strict_types=1);

$router     = new \Pramnos\Routing\Router($this);
$newRequest = new \Pramnos\Http\Request();

$router->group(
    ['prefix' => '/1.0'],
    function (\Pramnos\Routing\Router $r): void {
        $r->get('/channels', fn () => 'list');
        $r->post('/channels', fn () => 'create');
        $r->get('/channels/{id}', fn ($id) => 'read');
        $r->delete('/channels/{id}', fn ($id) => 'delete', ['channels:write']);
    }
);

return $router->dispatch($newRequest);
PHP_ROUTES);

        return $path;
    }

    private function fixturesDir(): string
    {
        return dirname(__DIR__, 4) . '/Fixtures/OpenApi';
    }

    /**
     * The routes registered on the router become the document's surface.
     *
     * The whole finding: ninety-one addresses that the attribute scan cannot see.
     */
    public function testRegisteredRoutesAreDocumented(): void
    {
        // Arrange
        $this->writeRouteFile();

        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--routes'      => 'src/Api/routes.php',
            '--output'      => 'openapi.json',
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $doc = json_decode((string) file_get_contents($this->tmp . '/openapi.json'), true);

        $this->assertArrayHasKey('/1.0/channels', $doc['paths'], 'the router was not read');
        $this->assertArrayHasKey('get', $doc['paths']['/1.0/channels']);
        $this->assertArrayHasKey('post', $doc['paths']['/1.0/channels']);
        $this->assertArrayHasKey('/1.0/channels/{id}', $doc['paths']);

        // A path parameter is documented as one — a URI is the only place a route says so.
        $this->assertSame(
            'id',
            $doc['paths']['/1.0/channels/{id}']['get']['parameters'][0]['name']
        );

        // The version prefix is not the tag; the resource is.
        $this->assertSame(['channels'], $doc['paths']['/1.0/channels']['get']['tags']);

        // A route with permissions is documented as secured, and says which.
        $delete = $doc['paths']['/1.0/channels/{id}']['delete'];
        $this->assertSame([['bearerAuth' => []]], $delete['security']);
        $this->assertStringContainsString('channels:write', $delete['description']);

        // …and the attribute scan is still there beside it.
        $this->assertArrayHasKey('/api/ping', $doc['paths']);
    }

    /**
     * The dispatch at the end of the route file does not run.
     *
     * The reason this could not be done before. Reading the routes must not serve a
     * request — and a route file's last line is a dispatch, because its return value is
     * the response.
     */
    public function testTheDispatchAtTheEndOfTheRouteFileDoesNothing(): void
    {
        // Arrange — a route file whose handler would raise if it ever ran
        $path = $this->tmp . '/src/Api/routes.php';
        file_put_contents($path, <<<'PHP_ROUTES'
<?php
$router = new \Pramnos\Routing\Router($this);
$router->get('/{any}', function () {
    throw new \RuntimeException('a handler ran while the routes were being read');
});
return $router->dispatch(new \Pramnos\Http\Request());
PHP_ROUTES);

        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--routes'      => 'src/Api/routes.php',
            '--output'      => 'openapi.json',
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertFalse(Router::isCollecting());
    }

    /**
     * A scan that finds nothing refuses to write, and says why.
     *
     * **The worst available outcome is the one this prevents**: the good document is
     * destroyed, the exit code is 0, and the line printed reads like success to a deploy
     * script and to a person skimming.
     */
    public function testAnEmptyScanRefusesToOverwrite(): void
    {
        // Arrange — a good document already there, and nothing to find
        mkdir($this->tmp . '/empty', 0775, true);
        file_put_contents($this->tmp . '/openapi.json', '{"openapi":"3.0.3","paths":{"/kept":{}}}');

        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->tmp . '/empty',
            '--namespace'   => 'Nothing\\Here',
            '--output'      => 'openapi.json',
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit, 'an empty scan must not exit 0');
        $this->assertStringContainsString('refusing to write', $tester->getDisplay());
        $this->assertStringContainsString('--routes', $tester->getDisplay());

        // The document that was there is the one that is still there.
        $this->assertSame(
            '{"openapi":"3.0.3","paths":{"/kept":{}}}',
            file_get_contents($this->tmp . '/openapi.json')
        );
    }

    /**
     * `--allow-empty` is for an API that really has none yet.
     *
     * A new project is a real state, and guessing at it is what the flag avoids.
     */
    public function testAllowEmptyWritesTheEmptyDocument(): void
    {
        // Arrange
        mkdir($this->tmp . '/empty', 0775, true);

        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->tmp . '/empty',
            '--namespace'   => 'Nothing\\Here',
            '--output'      => 'openapi.json',
            '--allow-empty' => true,
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $doc = json_decode((string) file_get_contents($this->tmp . '/openapi.json'), true);
        $this->assertSame([], $doc['paths']);
    }

    /**
     * A route file that is not there is a failure, not a silent skip.
     */
    public function testAMissingRouteFileFails(): void
    {
        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--routes'      => 'src/Api/nowhere.php',
            '--output'      => 'openapi.json',
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Route file not found', $tester->getDisplay());
    }

    /**
     * A route file that raises leaves the collector off.
     *
     * The `finally` this depends on is worth a test of its own: without it the process
     * stays in collecting mode and **every later `dispatch()` silently does nothing** —
     * in a long-running console process, or in the rest of a test suite.
     */
    public function testARaisingRouteFileDoesNotLeaveTheCollectorOn(): void
    {
        // Arrange
        file_put_contents(
            $this->tmp . '/src/Api/routes.php',
            "<?php\nthrow new \\RuntimeException('boom');\n"
        );

        // Act
        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--routes'      => 'src/Api/routes.php',
            '--output'      => 'openapi.json',
            '--no-html'     => true,
        ]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('boom', $tester->getDisplay());
        $this->assertFalse(Router::isCollecting(), 'a raising route file left the collector on');
    }
}
