<?php

declare(strict_types=1);

namespace SpaTestApp {
    /**
     * Stand-in application whose app.php declares a SPA project, so the make
     * commands take the branch a scaffolded SPA would.
     */
    class Application extends \Pramnos\Application\Application
    {
        public $applicationInfo = [
            'namespace' => 'SpaTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        public $appName = '';
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Unit\Console {

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * Exposes the SPA-facing generator internals.
 */
class SpaCrudProbe extends MakeCommandBase
{
    protected function configure() {}
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ) {
        return 0;
    }

    /** @return string mvc|spa|both */
    public function exposeDefaultTarget(): string
    {
        return $this->defaultCrudTarget();
    }

    public function exposeAppStyle(): string
    {
        return $this->appStyle();
    }

    public function exposeCreateScreen(string $name): string
    {
        return $this->createSpaScreen($name);
    }

    /** @param array<string, string> $tokens */
    public function exposeRegisterRoutes(string $file, array $tokens, string $resource): void
    {
        $this->registerApiRoutes($file, $tokens, $resource);
    }
}

/**
 * An application subclass that overrides createCrud() with the signature it has
 * always had.
 *
 * It exists to hold that signature still: the target is carried on a property
 * precisely so this keeps working. Adding a parameter to the parent would make
 * PHP reject this class outright — which is exactly what happened, and what the
 * framework's "no public signature changes" rule forbids.
 */
class LegacyCrudOverride extends SpaCrudProbe
{
    public function createCrud($name)
    {
        return "overridden for $name";
    }
}

/**
 * Covers `create:crud` in a SPA project.
 *
 * The MVC scaffold has always answered "I ran a migration, now give me the
 * feature" with a model, a controller and views. A SPA project got none of
 * that — so a new table meant hand-writing an API controller, its routes and a
 * screen. These cover the generator that closes the gap, and the three defects
 * that made the generated API unusable before it.
 */
class MakeCrudSpaTest extends TestCase
{
    private SpaCrudProbe $command;
    private \Pramnos\Console\Application $app;
    private string $tmpDir;
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->app = new \Pramnos\Console\Application();
        $internal = new \SpaTestApp\Application();
        // The base constructor reloads applicationInfo from app.php, wiping the
        // property default — so the fixture's values are set afterwards.
        $internal->applicationInfo = [
            'namespace' => 'SpaTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        $this->app->internalApplication = $internal;

        $this->command = new SpaCrudProbe();
        $this->command->setApplication($this->app);

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_crud_spa_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->rmdir($this->tmpDir);
        $this->rmdir(ROOT . '/frontend');
    }

    /**
     * What `create:crud` generates follows the application style recorded in
     * app.php — a SPA project has no server-rendered view layer to generate
     * into, and a hybrid one needs both halves.
     */
    public function testTargetFollowsTheApplicationStyle(): void
    {
        // Assert — this fixture declares a SPA
        $this->assertSame('spa', $this->command->exposeAppStyle());
        $this->assertSame('spa', $this->command->exposeDefaultTarget());

        // Act + Assert — hybrid asks for both
        $this->app->internalApplication->applicationInfo['app_style'] = 'hybrid';
        $this->assertSame('both', $this->command->exposeDefaultTarget());

        // ...and anything else (including an app.php predating this key) stays MVC
        $this->app->internalApplication->applicationInfo['app_style'] = 'mvc';
        $this->assertSame('mvc', $this->command->exposeDefaultTarget());

        unset($this->app->internalApplication->applicationInfo['app_style']);
        $this->assertSame('mvc', $this->command->exposeDefaultTarget());
    }

    /**
     * An app (or test) that overrides createCrud() with the original signature
     * must keep working. The target travels on a property for exactly this
     * reason — an added parameter makes PHP reject the subclass at load time,
     * which is a broken public signature, not a new capability.
     */
    public function testOverridingCreateCrudStillWorks(): void
    {
        // Act — merely instantiating this would fatal on an incompatible parent
        $legacy = new LegacyCrudOverride();

        // Assert
        $this->assertSame('overridden for thing', $legacy->createCrud('thing'));
        $this->assertSame(
            1,
            (new \ReflectionMethod(MakeCommandBase::class, 'createCrud'))->getNumberOfParameters(),
            'createCrud() must keep its single-parameter signature'
        );
    }

    /**
     * The screen is generated into the front-end sources and registered, so it
     * is reachable. An unregistered component would not even be bundled.
     */
    public function testScreenIsGeneratedAndRegistered(): void
    {
        // Act
        $result = $this->command->exposeCreateScreen('widget');

        // Assert — the component exists...
        $screen = ROOT . '/frontend/screens/Widget.svelte';
        $this->created[] = $screen;
        $this->assertStringContainsString('OK', $result);
        $this->assertFileExists($screen);

        $component = (string) file_get_contents($screen);
        $this->assertStringContainsString("const RESOURCE = '/widget'", $component);
        // The list must ask the server for a page, not fetch everything
        $this->assertStringContainsString('limit: String(perPage)', $component);
        $this->assertStringContainsString('pagination?.totalitems', $component);

        // ...and the registry points at it
        $registry = ROOT . '/frontend/screens/registry.js';
        $this->created[] = $registry;
        $this->assertFileExists($registry);
        $contents = (string) file_get_contents($registry);
        $this->assertStringContainsString("import Widget from './Widget.svelte';", $contents);
        $this->assertStringContainsString("name: 'widget'", $contents);
    }

    /**
     * Running it twice must not duplicate the registry entry — re-running a
     * generator after adding a column is normal.
     */
    public function testRegisteringTwiceIsIdempotent(): void
    {
        // Arrange
        $this->command->exposeCreateScreen('widget');
        $this->created[] = ROOT . '/frontend/screens/Widget.svelte';
        $this->created[] = ROOT . '/frontend/screens/registry.js';

        // Act
        $second = $this->command->exposeCreateScreen('widget');

        // Assert — the screen is left alone and the entry appears once
        $this->assertStringContainsString('SKIPPED', $second);
        $contents = (string) file_get_contents(ROOT . '/frontend/screens/registry.js');
        $this->assertSame(1, substr_count($contents, "name: 'widget'"));
    }

    /**
     * An MVC project has no front-end stack, so there is nothing to generate —
     * and writing a Svelte component into it would be worse than nothing.
     */
    public function testNoScreenWithoutASpaStack(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo['spa_stack'] = '';

        // Act
        $result = $this->command->exposeCreateScreen('widget');

        // Assert
        $this->assertStringContainsString('SKIPPED', $result);
        $this->assertFileDoesNotExist(ROOT . '/frontend/screens/Widget.svelte');
    }

    /**
     * Routes must land INSIDE the version group.
     *
     * Appended after it — as the generator used to do — they register at
     * `/thing` while every request arrives as `/1.0/thing`: nothing matches,
     * the API falls back to legacy controller resolution, and the caller gets
     * "Cannot find controller: 1.0". Every generated endpoint was unreachable.
     */
    public function testRoutesAreRegisteredInsideTheVersionGroup(): void
    {
        // Arrange — a routes.php shaped like the scaffolded one
        $file = $this->tmpDir . '/routes.php';
        file_put_contents($file, <<<'PHP'
<?php
$router = new \Pramnos\Routing\Router($this);
$router->group(
    ['prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')],
    function (\Pramnos\Routing\Router $r): void {
        $r->get('/me', function () {
            return 'me';
        });
    }
);

return $router->dispatch($newRequest);
PHP);

        // Act
        $this->command->exposeRegisterRoutes($file, [
            'modelClassLower' => 'thing',
            'primaryKey'      => 'thingid',
            'className'       => 'Thing',
            'modelClass'      => 'Thing',
            'apiNamespace'    => 'SpaTestApp\Api\Controllers',
        ], 'thing');

        // Assert — inside the group, using its $r, before the group closes
        $contents = (string) file_get_contents($file);
        $groupEnd = strpos($contents, "\n    }\n);");
        $routePos = strpos($contents, "'/thing'");
        $this->assertNotFalse($routePos);
        $this->assertLessThan($groupEnd, $routePos, 'the route must be inside the version group');
        $this->assertStringContainsString('$r->get(', $contents);

        // ...and it instantiates the API controller directly: getController()
        // resolves against src/Controllers and cannot see src/Api/Controllers.
        $this->assertStringContainsString('new \SpaTestApp\Api\Controllers\Thing($this)', $contents);
        $this->assertStringNotContainsString("getController('Thing')", $contents);
    }

    /**
     * Registering the same resource twice must not duplicate its routes.
     */
    public function testRoutesAreNotDuplicated(): void
    {
        // Arrange
        $file = $this->tmpDir . '/routes.php';
        file_put_contents($file, <<<'PHP'
<?php
$router->group(
    ['prefix' => '/1.0'],
    function (\Pramnos\Routing\Router $r): void {
    }
);

return $router->dispatch($newRequest);
PHP);
        $tokens = [
            'modelClassLower' => 'thing',
            'primaryKey'      => 'thingid',
            'className'       => 'Thing',
            'modelClass'      => 'Thing',
            'apiNamespace'    => 'SpaTestApp\Api\Controllers',
        ];

        // Act — one insertion, then a second that must change nothing
        $this->command->exposeRegisterRoutes($file, $tokens, 'thing');
        $afterFirst = (string) file_get_contents($file);

        $this->command->exposeRegisterRoutes($file, $tokens, 'thing');
        $afterSecond = (string) file_get_contents($file);

        // Assert — byte-identical: no duplicated block, no stray whitespace
        $this->assertSame($afterFirst, $afterSecond);
        // (one insertion registers the collection path twice: GET and POST)
        $this->assertSame(2, substr_count($afterSecond, "'/thing'"));
    }

    /**
     * Recursively delete a directory tree.
     */
    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }
}

}
