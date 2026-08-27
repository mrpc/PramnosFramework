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

    /**
     * The exclusion list a generated screen is filtered through.
     *
     * @return string[]
     */
    public function exposeNonEditable(): array
    {
        return self::NON_EDITABLE_COLUMNS;
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
        // A buildless project keeps its screens under the web root, so a test that
        // generated one there leaves the framework's own www/ dirty otherwise.
        $this->rmdir(ROOT . '/www/assets/js/screens');
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
        // The resource path carries the application's own api_prefix rather than
        // a hard-coded one — a generated screen with the wrong prefix 404s in
        // exactly the projects that configured one.
        $this->assertStringContainsString("const RESOURCE = '/api/1.0/widget'", $component);
        // The list must ask the server for a page, not fetch everything.
        $this->assertStringContainsString('limit: PER_PAGE', $component);
        $this->assertStringContainsString('result?.pagination', $component);
        // And it imports the shared components rather than hand-rolling a
        // table. Asserted on the import line, not on a mention of the name: a
        // docblock that talks about DataTable would satisfy a grep for the word.
        $this->assertStringContainsString(
            "import DataTable from '../components/DataTable.svelte';", $component
        );
        $this->assertStringContainsString(
            "import Field from '../components/Field.svelte';", $component
        );

        // ...and the components it imports were written, so the build works.
        foreach (\Pramnos\Console\Commands\Init::SPA_SHARED_COMPONENTS as $relative => $ignored) {
            $path = ROOT . '/frontend/' . $relative;
            $this->created[] = $path;
            $this->assertFileExists($path, $relative . ' must ship with the screen');
        }

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

    /**
     * A generated screen never offers a secret column.
     *
     * The screen renders every column it is given twice: as a table cell and as
     * a text input. Handed the `users` table unfiltered, it would print the
     * password hash in an admin list and invite an administrator to type over
     * it — which stores a plain string where a hash belongs and locks the
     * account out. For `applications` it would print the API secret.
     */
    public function testSecretColumnsAreNeverOfferedForEditing(): void
    {
        // Arrange
        $probe    = new SpaCrudProbe('probe');
        $excluded = $probe->exposeNonEditable();

        // Act + Assert — the ones that actually exist in framework tables
        foreach (['password', 'salt', 'apikey', 'apisecret', 'token'] as $secret) {
            $this->assertContains(
                $secret,
                $excluded,
                $secret . ' must never reach a generated screen'
            );
        }
    }

    /**
     * Model-maintained timestamps stay excluded too.
     *
     * They were the original contents of this list, and adding the secrets must
     * not have displaced them — typing over a creation date is meaningless.
     */
    public function testTimestampColumnsRemainExcluded(): void
    {
        // Arrange
        $probe = new SpaCrudProbe('probe');

        // Act + Assert
        foreach (['created', 'updated', 'createdate', 'updatedate'] as $column) {
            $this->assertContains($column, $probe->exposeNonEditable());
        }
    }

    /**
     * A vanilla project gets a working screen, and one that can be reached.
     *
     * The generator wrote a screen for the vanilla stacks all along and registered
     * it — and nothing rendered it: `main.js` never read `screens/registry.js`, so
     * the file existed, the endpoints answered, and the screen was unreachable. The
     * generator reported success either way.
     *
     * So this asserts both halves: the screen is written and registered as a
     * `mount` entry, **and** the shell that ships with the stack imports the
     * registry. Either alone passes on the broken state.
     */
    public function testAVanillaProjectGetsAReachableScreen(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo['spa_stack'] = 'vanilla';

        // Act
        $result = $this->command->exposeCreateScreen('widget');

        // Assert — a plain module, not a Svelte component. A buildless project
        // keeps its sources under the web root, where the browser can load them.
        $screen = ROOT . '/www/assets/js/screens/Widget.js';
        $this->created[] = $screen;
        $this->assertStringContainsString('OK', $result);
        $this->assertFileExists($screen);

        $module = (string) file_get_contents($screen);
        $this->assertStringContainsString('export function mount(target)', $module);
        $this->assertStringContainsString("const RESOURCE = '/widget'", $module);
        // Paging on the server, and a pager the user can actually press
        $this->assertStringContainsString('limit: String(perPage)', $module);
        $this->assertStringContainsString('Next', $module);
        // Column descriptors, not bare names: labels and per-type inputs
        $this->assertStringContainsString('const FIELDS =', $module);
        $this->assertStringContainsString('function inputType(field)', $module);

        // …registered as a mount entry
        $registry = ROOT . '/www/assets/js/screens/registry.js';
        $this->created[] = $registry;
        $contents = (string) file_get_contents($registry);
        $this->assertStringContainsString("import * as Widget from './Widget.js';", $contents);
        $this->assertStringContainsString('mount: Widget.mount', $contents);

        // …and the shell renders whatever the registry holds
        $shell = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/spa-vanilla-main.js.stub'
        );
        $this->assertStringContainsString(
            "import { screens } from './screens/registry.js';",
            $shell,
            'main.js must read the registry, or a generated screen is unreachable'
        );
        $this->assertStringContainsString('export async function mountRoute(', $shell);
    }

    /**
     * Nothing a record contains is written as markup.
     *
     * The screen builds its DOM by hand, and the first version interpolated row
     * values straight into `innerHTML` — in a **generated** file, which is the worst
     * place to leave that decision to whoever edits it next. Every value now goes
     * through `textContent` or `.value`.
     */
    public function testTheVanillaScreenNeverWritesDataAsMarkup(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo['spa_stack'] = 'vanilla-vite';

        // Act
        $this->command->exposeCreateScreen('widget');
        $screen = ROOT . '/frontend/screens/Widget.js';
        $this->created[] = $screen;
        $this->created[] = ROOT . '/frontend/screens/registry.js';
        $module = (string) file_get_contents($screen);

        // Assert — values reach the DOM as text, never as HTML
        $this->assertStringContainsString('td.textContent = row[field.name]', $module);
        $this->assertStringContainsString('input.value = record[field.name]', $module);
        $this->assertDoesNotMatchRegularExpression(
            '/innerHTML\s*=\s*[^;]*\$\{(row|record)\[/',
            $module,
            'a record must never be interpolated into innerHTML'
        );
    }
}
}
