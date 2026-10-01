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

    /**
     * Runs api:docs over the route fixture with the given overrides document.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed> The written document
     */
    private function generateWithOverrides(array $overrides, bool $html = false): array
    {
        $this->writeRouteFile();
        file_put_contents($this->tmp . '/overrides.json', json_encode($overrides));

        $tester = $this->tester();
        $exit   = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--routes'      => 'src/Api/routes.php',
            '--overrides'   => 'overrides.json',
            '--output'      => 'openapi.json',
            '--no-html'     => !$html,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        return json_decode((string) file_get_contents($this->tmp . '/openapi.json'), true);
    }

    /**
     * Responses every operation shares are declared once, not once per operation.
     *
     * A route carries only its address, so a route-derived operation documented nothing but
     * `200`. The 403 an API answers without a key and the 401 it answers without a session
     * come from middleware, and saying so took one override per operation — 192 of them in
     * one application, kept by hand as the routes changed.
     */
    public function testDefaultResponsesAreMergedIntoEveryOperation(): void
    {
        // Arrange & Act
        $doc = $this->generateWithOverrides([
            'x-pramnos-default-responses' => ['403' => ['description' => 'Missing API key']],
            'x-pramnos-secured-responses' => ['401' => ['description' => 'Not signed in']],
        ]);

        // Assert — every operation, from both sources, gets the shared default
        $list = $doc['paths']['/1.0/channels']['get']['responses'];
        $this->assertSame('Missing API key', $list['403']['description']);
        $this->assertSame('Missing API key', $doc['paths']['/api/ping']['get']['responses']['403']['description']);

        // The operation's own response is kept beside the defaults
        $this->assertSame('Successful response', $list['200']['description']);

        // The secured default only where the route requires a credential
        $this->assertArrayNotHasKey('401', $list);
        $this->assertSame(
            'Not signed in',
            $doc['paths']['/1.0/channels/{id}']['delete']['responses']['401']['description']
        );

        // The instructions are consumed, not published as part of the API's document
        $this->assertArrayNotHasKey('x-pramnos-default-responses', $doc);
        $this->assertArrayNotHasKey('x-pramnos-secured-responses', $doc);
    }

    /**
     * A null in an override takes a default back from the operation it does not apply to.
     *
     * A public address — a webhook, a pairing handshake — answers no 403 for a missing key.
     * Without a way to remove a default, declaring it once would make it wrong there.
     */
    public function testANullOverrideRemovesADefaultResponse(): void
    {
        // Arrange & Act
        $doc = $this->generateWithOverrides([
            'x-pramnos-default-responses' => ['403' => ['description' => 'Missing API key']],
            'paths' => ['/1.0/channels' => ['post' => ['responses' => ['403' => null]]]],
        ]);

        // Assert — removed where the override says so, kept everywhere else
        $this->assertArrayNotHasKey('403', $doc['paths']['/1.0/channels']['post']['responses']);
        $this->assertArrayHasKey('403', $doc['paths']['/1.0/channels']['get']['responses']);
    }

    /**
     * An override on an operation only the router knows adds to it instead of replacing it.
     *
     * The overrides used to be merged into the attribute document first, where such an
     * operation does not exist, so it was created there as a bare `{responses: …}` and the
     * route-derived one — summary, tags, parameters, security — was discarded as already
     * present. Documenting one extra response cost the operation everything else it had.
     */
    public function testAnOverrideOnARouteOnlyOperationKeepsWhatTheRouteSaid(): void
    {
        // Arrange & Act
        $doc = $this->generateWithOverrides([
            'paths' => ['/1.0/channels/{id}' => ['delete' => [
                'responses' => ['404' => ['description' => 'No such channel']],
            ]]],
        ]);

        // Assert
        $delete = $doc['paths']['/1.0/channels/{id}']['delete'];
        $this->assertSame('No such channel', $delete['responses']['404']['description']);
        $this->assertSame(['channels'], $delete['tags'], 'the route-derived tag was lost');
        $this->assertSame('id', $delete['parameters'][0]['name'], 'the path parameter was lost');
        $this->assertSame([['bearerAuth' => []]], $delete['security'], 'the security was lost');
        $this->assertArrayHasKey('bearerAuth', $doc['components']['securitySchemes']);
    }

    /**
     * The viewer is titled from the document, so `info.title` in the overrides names it.
     *
     * Read from `--title` only, a project that set its title in the overrides got a spec
     * titled correctly beside a viewer that said "API — API documentation".
     */
    public function testTheViewerTitleComesFromTheMergedDocument(): void
    {
        // Arrange & Act
        $this->generateWithOverrides(['info' => ['title' => 'Example API']], true);

        // Assert
        $html = (string) file_get_contents($this->tmp . '/docs/index.html');
        $this->assertStringContainsString('Example API — API documentation', $html);
    }

    /**
     * `fromRoutes()` on its own applies the defaults too, for an application that builds its
     * document in code rather than through the command.
     */
    public function testFromRoutesAppliesTheDefaultResponses(): void
    {
        // Arrange
        $generator = new \Pramnos\Routing\OpenApiGenerator([], [], [
            'x-pramnos-secured-responses' => ['401' => ['description' => 'Not signed in']],
        ]);

        // Act
        $doc = $generator->fromRoutes([
            'GET'    => ['/open' => ['hasPermissions' => false]],
            'DELETE' => ['/closed' => ['hasPermissions' => true, 'permissions' => ['x:write']]],
        ]);

        // Assert — only the operation that requires a credential answers 401
        $this->assertArrayNotHasKey('401', $doc['paths']['/open']['get']['responses']);
        $this->assertSame('Not signed in', $doc['paths']['/closed']['delete']['responses']['401']['description']);
    }
}
