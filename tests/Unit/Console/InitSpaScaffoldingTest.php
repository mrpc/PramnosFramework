<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers `pramnos init --app-style=spa|hybrid` — the single-page-application
 * scaffolding.
 *
 * Three stacks are offered and they differ in ways that matter operationally:
 * where the sources live, whether a build step exists, how assets get their
 * cache-busting, which test runner is wired up, and whether the image needs
 * Node at all. Each of those is asserted here, because getting any of them
 * wrong produces a project that looks scaffolded but cannot be served, built
 * or tested.
 */
class InitSpaScaffoldingTest extends TestCase
{
    private string $tmpDir;
    private Init   $command;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Fresh workspace per test, plus the PHP_SELF guard the other Init tests
     * use (Symfony's completion command reads it while building the console
     * application).
     */
    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_init_spa_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Run init with the SPA options under test, filling in the rest.
     *
     * @param array<string, string> $options Extra/overriding CLI options
     */
    private function runInit(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'     => 'SpaApp',
            '--namespace'    => 'SpaApp',
            '--features'     => 'auth',
            '--ui-system'    => 'plain-css',
            '--docker'       => 'y',
            '--docker-port'  => '8080',
            '--spa-dev-port' => '5199',
            '--cache-system' => 'none',
            '--libraries'    => '',
            '--db-type'      => 'postgresql',
            '--db-host'      => 'db',
            '--db-name'      => 'spa_db',
            '--db-user'      => 'spa',
            '--db-pass'      => 'secret',
            '--db-prefix'    => '',
            '--api-docs'     => 'n',
            '--webhook'      => 'n',
            '--no-download'  => true,
            '--no-migrations' => true,
        ], $options), ['interactive' => false]);

        return $tester;
    }

    /** Read a scaffolded file relative to the project root. */
    private function read(string $path): string
    {
        $full = $this->tmpDir . '/' . $path;
        $this->assertFileExists($full, "expected $path to be scaffolded");
        return (string) file_get_contents($full);
    }

    /**
     * The default style is unchanged: without --app-style nothing SPA-related
     * appears, so every existing project and test keeps its behaviour.
     */
    public function testMvcRemainsTheDefaultAndScaffoldsNoSpa(): void
    {
        // Act — no --app-style at all
        $this->runInit();

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/www/spa.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend');
        $this->assertFileDoesNotExist($this->tmpDir . '/vite.config.js');
        $this->assertStringContainsString('index.php', $this->read('www/.htaccess'));
    }

    /**
     * The Svelte stack: sources under frontend/, a Vite build with Tailwind and
     * daisyUI, Vitest wiring, and the helper scripts that run them in Docker.
     */
    public function testSvelteStackScaffoldsSourcesBuildAndTests(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — component sources exist and use Svelte 5 runes + daisyUI
        $app = $this->read('frontend/App.svelte');
        $this->assertStringContainsString('$state(', $app, 'Svelte 5 runes, not the legacy store syntax');
        $this->assertStringContainsString('card-body', $app, 'daisyUI component classes');
        $this->assertStringContainsString('btn btn-primary', $app, 'daisyUI button classes');
        $this->assertStringContainsString('mount(App', $this->read('frontend/main.js'));

        // Tailwind v4 + daisyUI are configured from CSS — no tailwind.config.js
        $css = $this->read('frontend/app.css');
        $this->assertStringContainsString('@import "tailwindcss"', $css);
        $this->assertStringContainsString('@plugin "daisyui"', $css);
        $this->assertFileDoesNotExist($this->tmpDir . '/tailwind.config.js');

        // A svelte.config.js exists so the plugin stops warning and there is a
        // place to add preprocessors later.
        $this->assertStringContainsString('vitePreprocess', $this->read('svelte.config.js'));

        // Build + test configuration
        $vite = $this->read('vite.config.js');
        $this->assertStringContainsString('@sveltejs/vite-plugin-svelte', $vite);
        $this->assertStringContainsString('@tailwindcss/vite', $vite);
        $this->assertStringContainsString('manifest: true', $vite, 'the PHP shell reads the manifest');
        $vitest = $this->read('vitest.config.js');
        $this->assertStringContainsString('jsdom', $vitest);
        // Without the browser export condition Vitest resolves Svelte's server
        // build and every component test dies on "mount() is not available on
        // the server" — verified against a real npm run.
        $this->assertStringContainsString("conditions: ['browser']", $vitest);

        // Tests ship with the scaffold, for both the client and the component
        $this->assertFileExists($this->tmpDir . '/frontend/__tests__/api.test.js');
        $this->assertFileExists($this->tmpDir . '/frontend/__tests__/App.test.js');

        // Helper scripts, executable
        $this->assertFileExists($this->tmpDir . '/testjs');
        $this->assertFileExists($this->tmpDir . '/dockernpm');
        $this->assertTrue(is_executable($this->tmpDir . '/testjs'));
        $this->assertTrue(is_executable($this->tmpDir . '/dockernpm'));
    }

    /**
     * package.json carries the scripts and the pinned toolchain — a project
     * whose `npm run build` or `npm test` is missing is not usable.
     */
    public function testSveltePackageJsonCarriesScriptsAndToolchain(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $pkg = json_decode($this->read('package.json'), true);
        $this->assertSame('module', $pkg['type']);
        $this->assertSame('vite build', $pkg['scripts']['build']);
        $this->assertSame('vitest run', $pkg['scripts']['test']);
        $this->assertArrayHasKey('svelte', $pkg['devDependencies']);
        $this->assertArrayHasKey('daisyui', $pkg['devDependencies']);
        $this->assertArrayHasKey('@testing-library/svelte', $pkg['devDependencies']);
        $this->assertArrayHasKey('vitest', $pkg['devDependencies']);
    }

    /**
     * The build-less stack must stay dependency-free: that is the entire reason
     * it is offered. Its JavaScript is served exactly as written and its tests
     * run on Node's built-in runner.
     */
    public function testVanillaStackHasNoBuildAndNoDependencies(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert — no build tooling anywhere
        $this->assertFileDoesNotExist($this->tmpDir . '/vite.config.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/vitest.config.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/dockernpm');

        // Sources are served straight from the web root
        $this->assertFileExists($this->tmpDir . '/www/assets/js/main.js');
        $this->assertFileExists($this->tmpDir . '/www/assets/js/lib/api.js');

        // Tests run with node --test and no packages at all
        $pkg = json_decode($this->read('package.json'), true);
        $this->assertSame('module', $pkg['type'], 'so Node runs the same ES modules the browser loads');
        // A glob, not a directory: Node 24 tries to load a directory argument
        // as a module and the run fails before any test executes.
        $this->assertSame('node --test tests/js/*.test.js', $pkg['scripts']['test']);
        $this->assertArrayNotHasKey('devDependencies', $pkg);
        $this->assertStringContainsString("from 'node:test'", $this->read('tests/js/api.test.js'));

        // A CSS import would break both the browser (which cannot import CSS
        // from a module) and node --test: only a bundler can consume one.
        $this->assertStringNotContainsString("import './app.css'", $this->read('www/assets/js/main.js'));
    }

    /**
     * With a bundler the stylesheet must be imported from the entry point, or
     * Vite never emits it and the built page renders unstyled.
     */
    public function testViteStacksImportTheStylesheetFromTheEntryPoint(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla-vite']);

        // Assert
        $this->assertStringContainsString("import './app.css'", $this->read('frontend/main.js'));
    }

    /**
     * The unbuilt fallback URLs must be root-relative: the shell also answers
     * deep client routes (/things/42), where a relative asset URL resolves
     * against the wrong directory and 404s.
     */
    public function testFallbackAssetUrlsAreRootRelative(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $shell = $this->read('www/spa.php');
        $this->assertStringContainsString("stamp('/assets/js/main.js')", $shell);
        $this->assertStringContainsString("stamp('/assets/css/app.css')", $shell);
    }

    /**
     * The shell handles both cache-busting modes. Which one applies is decided
     * at runtime by the presence of the Vite manifest, so the same file works
     * before and after the first build.
     */
    public function testShellSupportsBothManifestAndMtimeCacheBusting(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla-vite']);

        // Assert
        $shell = $this->read('www/spa.php');
        $this->assertStringContainsString('manifest.json', $shell, 'hashed filenames after a build');
        $this->assertStringContainsString('filemtime', $shell, 'mtime stamping before one');
        $this->assertStringContainsString('no-cache', $shell, 'the shell itself must be revalidated');
    }

    /**
     * The Vite dev server serves no HTML — there is no index.html, the page
     * comes from Apache. So opening the Vite port gives a 404, and the actual
     * workflow is: the dev server writes a "hot" file, and the shell then loads
     * the Vite client plus the entry module from it while browsing the app URL.
     */
    public function testDevServerIsWiredThroughTheShellNotItsOwnPort(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the dev server announces itself...
        $vite = $this->read('vite.config.js');
        $this->assertStringContainsString('.vite/hot', $vite);
        $this->assertStringContainsString('pramnosHotFile()', $vite);
        // ...cross-origin module loads are allowed (page and assets differ)...
        $this->assertStringContainsString('cors: true', $vite);
        // ...and no proxy is configured: the page is already served by the
        // backend, so API calls are same-origin and need no forwarding.
        $this->assertStringNotContainsString('proxy:', $vite);

        // The shell prefers the dev server over any build output, and asks for
        // the Vite client under the configured base — the dev server serves
        // everything, its own client included, beneath it (a bare
        // /@vite/client is a 404, verified against a running server).
        $shell = $this->read('www/spa.php');
        $this->assertStringContainsString('.vite/hot', $shell);
        $this->assertStringContainsString("'/assets/spa/@vite/client'", $shell);

        // ...and the summary must not send the developer to the Vite port
        $this->assertStringNotContainsString(
            'run dev</comment> → <comment>http://localhost',
            $this->read('www/spa.php')
        );
    }

    /**
     * package.json in a SPA project declares "type": "module", which turns every
     * .js file into an ES module — including the CommonJS API-docs generator.
     * It ships as .cjs so `npm run docs:build` does not die on "require is not
     * defined in ES module scope".
     */
    public function testApiDocsGeneratorSurvivesTypeModule(): void
    {
        // Act — both features on at once is exactly the combination that broke
        $this->runInit([
            '--app-style' => 'spa',
            '--spa-stack' => 'svelte',
            '--api-docs'  => 'y',
        ]);

        // Assert
        $this->assertFileExists($this->tmpDir . '/scripts/apidoc-to-openapi.cjs');
        $this->assertFileDoesNotExist($this->tmpDir . '/scripts/apidoc-to-openapi.js');

        $pkg = json_decode($this->read('package.json'), true);
        $this->assertSame('module', $pkg['type']);
        $this->assertSame('node scripts/apidoc-to-openapi.cjs', $pkg['scripts']['openapi:generate']);
    }

    /**
     * A SPA project still ships server-rendered areas (login, admin CRUD, the
     * API). Those paths must keep reaching the front controller — otherwise the
     * shell swallows them and the login page silently becomes the SPA.
     */
    public function testSpaRoutingKeepsServerRenderedAreasReachable(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $htaccess = $this->read('www/.htaccess');
        $this->assertMatchesRegularExpression('/\^\(api\|.*\)\(\/\.\*\)\?\$ index\.php/', $htaccess);
        $this->assertStringContainsString('login', $htaccess, 'the auth feature was enabled');
        $this->assertStringContainsString('spa.php [L]', $htaccess, 'everything else is a client route');

        // The document root is a directory, so the !-d guarded catch-all never
        // fires for "/" — without an explicit DirectoryIndex the site root
        // serves the MVC index.php instead of the SPA (seen with a real curl).
        $this->assertStringContainsString('DirectoryIndex spa.php', $htaccess);
    }

    /**
     * Hybrid keeps the MVC front controller in charge and mounts the SPA under
     * /app, so both styles coexist in one project.
     */
    public function testHybridMountsTheSpaUnderAppAndKeepsMvc(): void
    {
        // Act
        $this->runInit(['--app-style' => 'hybrid', '--spa-stack' => 'svelte']);

        // Assert
        $htaccess = $this->read('www/.htaccess');
        $this->assertStringContainsString('RewriteRule ^app(/.*)?$ app.php', $htaccess);
        $this->assertStringContainsString('index.php?r=$1', $htaccess, 'MVC routing survives');
        $this->assertFileExists($this->tmpDir . '/www/app.php');
    }

    /**
     * The Docker image only grows a Node toolchain when something needs it, and
     * the Vite dev-server port is published so HMR is reachable from the host.
     */
    public function testDockerGainsNodeAndTheDevPortForBuildStacks(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $this->assertStringContainsString('nodejs npm', $this->read('Dockerfile'));
        $this->assertStringContainsString('"5199:5199"', $this->read('docker-compose.yml'));
        $this->assertStringContainsString('5199', $this->read('vite.config.js'));
    }

    /**
     * ...and stays lean for the build-less stack, which has nothing to build.
     */
    public function testDockerStaysNodeFreeForTheBuildlessStack(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $this->assertStringNotContainsString('nodejs npm', $this->read('Dockerfile'));
    }

    /**
     * Build output and node_modules must never be committed — the first is
     * generated, the second is enormous.
     */
    public function testGitignoreCoversNodeModulesAndBuildOutput(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $gitignore = $this->read('.gitignore');
        $this->assertStringContainsString('node_modules/', $gitignore);
        $this->assertStringContainsString('www/assets/spa/', $gitignore);
    }

    /**
     * The SPA's first screen must call an endpoint that exists.
     *
     * It used to probe `/health`, which was never scaffolded — so a brand-new
     * project greeted its author with "API answered 403". init now generates the
     * whole vertical slice the front end talks to: a service, a thin controller
     * over it, and the route. That is also the layering this application style
     * is named after, demonstrated once.
     */
    public function testSpaGetsAWorkingStatusEndpointAcrossAllLayers(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert — service holds the behaviour...
        $service = $this->read('src/Services/StatusService.php');
        $this->assertStringContainsString('namespace SpaApp\Services;', $service);
        $this->assertStringContainsString('function snapshot(', $service);
        // ...and it extends the framework base, which is the only reason the
        // request's service work shows up in the toolbar's Domain tab.
        $this->assertStringContainsString('extends Service', $service);
        $this->assertStringContainsString("measure('snapshot'", $service);

        // ...the controller is thin and asks the service...
        $controller = $this->read('src/Api/Controllers/Status.php');
        $this->assertStringContainsString('use SpaApp\Services\StatusService;', $controller);
        $this->assertStringContainsString('StatusService())->snapshot()', $controller);

        // ...and the route is registered, publicly.
        $routes = $this->read('src/Api/routes.php');
        $this->assertStringContainsString("\$r->get('/status'", $routes);
        $this->assertStringContainsString('Api\Controllers\Status', $routes);

        // The front end probes exactly that path.
        $this->assertStringContainsString("api.get('/status')", $this->read('www/assets/js/main.js'));
    }

    /**
     * The framework's API layer rejects any request without an `apiKey` header
     * ("API key is missing", 403). The shell therefore derives this
     * application's own key — the md5 of the site URL that Api::checkApiKey
     * accepts — and hands it to the client, so the SPA authenticates as the app
     * without anything being hard-coded per environment.
     */
    public function testShellInjectsTheRuntimeConfigTheClientNeeds(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the shell computes the key and publishes the config
        $shell = $this->read('www/spa.php');
        $this->assertStringContainsString("md5(str_replace('/api/', '/', \$siteUrl))", $shell);
        $this->assertStringContainsString('window.__PRAMNOS__', $shell);
        $this->assertStringContainsString("'auth' => true", $shell, 'the auth feature is on in this project');

        // ...and the client sends it, plus the framework's token header
        $client = $this->read('frontend/lib/api.js');
        $this->assertStringContainsString('requestHeaders.apiKey', $client);
        $this->assertStringContainsString('requestHeaders.accessToken', $client);
        // No Authorization header is set: the framework's native header is
        // accessToken (it now also accepts Bearer, but the client speaks the
        // framework's own contract).
        $this->assertStringNotContainsString('requestHeaders.Authorization', $client);
    }

    /**
     * With the auth feature the SPA ships a working sign-in flow — the same
     * thing the MVC scaffold provides as server-rendered pages.
     */
    public function testAuthFeatureAddsASignInFlow(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — client verbs...
        $client = $this->read('frontend/lib/api.js');
        // login() calls request() directly so it can be anonymous — sending a
        // stale token with it is what used to make signing in impossible.
        $this->assertStringContainsString("request('/account/login'", $client);
        $this->assertStringContainsString("api.post('/account/logout'", $client);
        $this->assertStringContainsString("api.get('/me')", $client);

        // ...and a screen that uses them
        $app = $this->read('frontend/App.svelte');
        $this->assertStringContainsString('submitLogin', $app);
        $this->assertStringContainsString('Sign out', $app);
        $this->assertStringContainsString('Sign in', $app);
    }

    /**
     * Without the auth feature there is no sign-in UI to show — a login form
     * posting to endpoints that were never scaffolded would be worse than none.
     */
    public function testNoAuthFeatureMeansNoSignInUi(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--features' => '']);

        // Assert
        $this->assertStringContainsString("'auth' => false", $this->read('www/spa.php'));
    }

    /**
     * A SPA has no other way to reach its data, so the API layer is scaffolded
     * regardless of what --rest-api says.
     */
    public function testSpaAlwaysScaffoldsTheApiLayer(): void
    {
        // Act — explicitly declining the REST API must not produce a dead SPA
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla', '--rest-api' => 'n']);

        // Assert
        $this->assertDirectoryExists($this->tmpDir . '/src/Api');
        $this->assertStringContainsString('/api/1.0', $this->read('www/assets/js/lib/api.js'));
    }

    /**
     * CLAUDE.md must describe the front end, or an assistant working in the
     * project will add server-rendered views to a SPA, edit generated build
     * output, or call the API without the header it requires.
     */
    public function testAiGuidelinesDescribeTheFrontEnd(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $claude = $this->read('CLAUDE.md');
        $this->assertStringContainsString('Services + API + SPA', $claude, 'the style is stated up front');
        $this->assertStringContainsString('## Front end (SPA)', $claude);
        $this->assertStringContainsString('frontend/', $claude, 'where the sources are');
        $this->assertStringContainsString('BUILD OUTPUT', $claude, 'what must never be edited');
        $this->assertStringContainsString('./dockernpm run dev', $claude);
        $this->assertStringContainsString('Do not open the Vite port', $claude);
        $this->assertStringContainsString('apiKey', $claude, 'the header every request needs');
        $this->assertStringContainsString('src/Services/', $claude, 'how to add an endpoint');

        // ...and that a CRUD is generated, not hand-written. The command line
        // must carry the real CLI name: a {{ CLI_NAME }} token inside this
        // section is substituted before the section itself and would survive.
        $this->assertStringContainsString('create:crud thing --table=things', $claude);
        $this->assertStringNotContainsString('{{ CLI_NAME }}', $claude);
        $this->assertStringContainsString('./spaapp create:crud', $claude);
    }

    /**
     * An MVC project gets none of that — a front-end chapter describing files
     * that do not exist is worse than no chapter.
     */
    public function testAiGuidelinesStayMvcOnlyWithoutASpa(): void
    {
        // Act
        $this->runInit();

        // Assert
        $claude = $this->read('CLAUDE.md');
        $this->assertStringContainsString('MVC + Models', $claude);
        $this->assertStringNotContainsString('## Front end (SPA)', $claude);
    }

    /**
     * A SPA project with the auth feature gets an administration screen.
     *
     * The MVC scaffold generates whole admin areas; a SPA got none of them, so
     * parity meant hand-writing each. The screen is generated and registered,
     * and its endpoints are framework-side so only the screen is generated code.
     */
    public function testAdminScreenAndEndpointsAreScaffolded(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the screen exists and shows the three things an admin opens
        $screen = $this->read('frontend/screens/Admin.svelte');
        $this->assertStringContainsString("api.get('/admin/summary')", $screen);
        $this->assertStringContainsString('/admin/users?', $screen);
        $this->assertStringContainsString("api.get('/admin/logs", $screen);
        // A 403 must read differently from a broken endpoint on an admin screen
        // 401, 403 and a broken endpoint are three problems with three fixes;
        // an admin screen is where conflating them wastes the most time.
        $this->assertStringContainsString('session has ended', $screen);
        $this->assertStringContainsString('does not have permission', $screen);
        $this->assertStringContainsString('Could not load', $screen);

        // ...it is registered, so it appears in the navigation...
        $registry = $this->read('frontend/screens/registry.js');
        $this->assertStringContainsString("import Admin from './Admin.svelte';", $registry);
        $this->assertStringContainsString("name: 'admin'", $registry);

        // ...and the routes and controller behind it exist
        $routes = $this->read('src/Api/routes.php');
        $this->assertStringContainsString("\$r->get('/admin/summary'", $routes);
        $this->assertStringContainsString("\$r->get('/admin/users'", $routes);
        $this->assertStringContainsString("\$r->get('/admin/logs'", $routes);
        $this->assertStringContainsString('ApiAdmin', $this->read('src/Api/Controllers/Admin.php'));
    }

    /**
     * Without the auth feature there is nobody to administer, so no admin
     * screen is generated — it could only ever answer 401.
     */
    public function testNoAdminScreenWithoutTheAuthFeature(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--features' => '']);

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/screens/Admin.svelte');
        $this->assertStringNotContainsString("name: 'admin'", $this->read('frontend/screens/registry.js'));
    }

    /**
     * Signing in must work even with a stale token in storage.
     *
     * The API validates the access token before routing, so a token signed with
     * a key the application no longer has — every re-deploy produces one —
     * failed **every** request with 403 InvalidAccessToken, including the login
     * that would have replaced it. The application was wedged until someone
     * cleared their browser storage. Login is therefore explicitly anonymous,
     * and a rejected token is dropped rather than presented again.
     */
    public function testLoginIsAnonymousAndABadTokenIsDiscarded(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $client = $this->read('frontend/lib/api.js');
        $this->assertStringContainsString('anonymous: true', $client, 'login must send no token');
        $this->assertStringContainsString("const token = anonymous ? null : getToken();", $client);
        $this->assertStringContainsString("payload.error === 'InvalidAccessToken'", $client);
        $this->assertStringContainsString('setToken(null)', $client, 'a rejected token must be dropped');
    }

    /**
     * Every screen has a real URL, and access decides where you land.
     *
     * Without routing the back button leaves the application and no page can be
     * linked to. And an anonymous visitor clicking a protected screen must be
     * sent to sign in — telling them they lack permission when they simply have
     * not signed in is a dead end.
     */
    public function testScreensHaveUrlsAndUnauthenticatedVisitorsAreSentToSignIn(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — a router with real history entries
        $router = $this->read('frontend/lib/router.js');
        $this->assertStringContainsString('window.history', $router);
        $this->assertStringContainsString('popstate', $router, 'the back button must work');
        // Modified clicks stay with the browser
        $this->assertStringContainsString('event.metaKey', $router);

        // ...and the sign-in path does not shadow the server-rendered /login
        $this->assertStringContainsString("'signin'", $router);

        $app = $this->read('frontend/App.svelte');
        $this->assertStringContainsString("router.go('signin', true)", $app, 'anonymous → sign in');
        $this->assertStringContainsString("router.go('home', true)", $app);
        $this->assertStringContainsString('href={pathFor(', $app, 'navigation uses real links');
    }

    /**
     * The daisyUI palette is derived from the project's theme at build time.
     *
     * daisyUI reads its colours from CSS custom properties, and so does the
     * scaffolded theme, so they are mapped across rather than chosen twice by
     * hand. It happens in a build step, not in the shell: a per-request
     * derivation would put work on every page load and need an inline <style>
     * that a CSP has to be taught about, to save nothing.
     */
    public function testThemePaletteIsGeneratedByABuildStep(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--ui-system' => 'plain-css']);

        // Assert — the generator exists and reads the theme the browser loads
        $script = $this->read('scripts/build-theme.mjs');
        $this->assertStringContainsString('www/assets/css/style.css', $script);
        $this->assertStringContainsString('frontend/theme.css', $script);
        $this->assertStringContainsString('--color-primary', $script);
        $this->assertStringContainsString(':root:root', $script, 'must beat daisyUI on specificity');

        // ...npm runs it before every build and every dev-server start...
        $pkg = json_decode($this->read('package.json'), true);
        $this->assertSame('node scripts/build-theme.mjs', $pkg['scripts']['prebuild']);
        $this->assertSame('node scripts/build-theme.mjs', $pkg['scripts']['predev']);

        // ...and the stylesheet imports the file it writes, which exists from
        // the start so the very first build cannot fail on a missing import.
        $this->assertStringContainsString('@import "./theme.css"', $this->read('frontend/app.css'));
        $this->assertFileExists($this->tmpDir . '/frontend/theme.css');
    }

    /**
     * Running the generator produces the theme's own colours — asserted by
     * executing it, so the test cannot drift from the implementation.
     */
    public function testGeneratorMapsTheThemeColours(): void
    {
        // Arrange
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not available in this environment');
        }
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--ui-system' => 'plain-css']);

        // Act — run it exactly as `npm run build` would
        exec(
            'cd ' . escapeshellarg($this->tmpDir) . ' && node scripts/build-theme.mjs 2>&1',
            $output,
            $status
        );

        // Assert
        $this->assertSame(0, $status, implode("\n", $output));
        $theme = $this->read('frontend/theme.css');
        $this->assertStringContainsString('--color-primary: #2563eb', $theme, "the theme's --primary-color");
        $this->assertStringContainsString('--color-base-content: #1e293b', $theme, "its --text-main");
        $this->assertStringContainsString('--color-neutral: #64748b', $theme, "its --text-muted");
        $this->assertStringContainsString('GENERATED', $theme, 'and it says not to edit it');
    }

    /**
     * A changed theme colour reaches the SPA on the next build — the whole
     * point of deriving rather than copying.
     */
    public function testAThemeColourChangePropagatesOnTheNextBuild(): void
    {
        // Arrange
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not available in this environment');
        }
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--ui-system' => 'plain-css']);

        $style = $this->tmpDir . '/www/assets/css/style.css';
        file_put_contents($style, str_replace(
            '--primary-color: #2563eb',
            '--primary-color: #dc2626',
            (string) file_get_contents($style)
        ));

        // Act
        exec('cd ' . escapeshellarg($this->tmpDir) . ' && node scripts/build-theme.mjs 2>&1', $out, $status);

        // Assert
        $this->assertSame(0, $status, implode("\n", $out));
        $this->assertStringContainsString('--color-primary: #dc2626', $this->read('frontend/theme.css'));
    }

    /**
     * A theme that declares no custom properties falls back to the colour its
     * own framework paints with — not daisyUI's default, which would match
     * nothing on the server-rendered side — and the generated file says so.
     */
    public function testPaletteFallsBackToTheUiFrameworkColour(): void
    {
        // Arrange
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not available in this environment');
        }
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte', '--ui-system' => 'bootstrap']);

        // Act
        exec('cd ' . escapeshellarg($this->tmpDir) . ' && node scripts/build-theme.mjs 2>&1', $out, $status);

        // Assert — Bootstrap's own primary, with the reason stated
        $this->assertSame(0, $status, implode("\n", $out));
        $theme = $this->read('frontend/theme.css');
        $this->assertStringContainsString('--color-primary: #0d6efd', $theme);
        $this->assertStringContainsString('framework palette', $theme);
    }

    /**
     * The SPA shell mirrors the server-rendered theme, so the two halves of a
     * hybrid project do not look like two different products.
     */
    public function testSpaLooksLikeTheRestOfTheApplication(): void
    {
        // Act
        $this->runInit(['--app-style' => 'hybrid', '--spa-stack' => 'svelte']);

        // Assert — header with the application name, navigation, footer
        $app = $this->read('frontend/App.svelte');
        $this->assertStringContainsString('<header', $app);
        $this->assertStringContainsString('<nav', $app);
        $this->assertStringContainsString('<footer', $app);
        $this->assertStringContainsString('All rights reserved', $app, 'same footer line as the theme');

        // ...and the MVC front page points at the SPA, which is otherwise easy
        // to forget behind it in a hybrid project
        $home = $this->read('src/Views/home/home.html.php');
        $this->assertStringContainsString('Single-page application', $home);
        $this->assertStringContainsString('/app', $home);
    }

    /**
     * A SPA gets the debug panel the HTML toolbar cannot give it.
     *
     * `DebugBarMiddleware` injects before `</body>`, which a JSON response does
     * not have, and the shell never goes through that pipeline — so the
     * requests that do the work had no debug information at all. The panel is
     * shipped in every project because it is inert without debug data: it only
     * draws when a response carries `_debug`, which only happens in development.
     */
    public function testDebugPanelIsScaffoldedAndFedByTheClient(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the panel exists...
        $panel = $this->read('frontend/lib/debug.js');
        $this->assertStringContainsString('export function record(', $panel);
        // The panel exists only once a payload has arrived, and the API attaches
        // one only in development. Asserted on the guard's meaning rather than
        // its exact text: it now also lets a 204 through once the panel is
        // active, since a save carries no body to put a payload in.
        $this->assertStringContainsString(
            'if (!payload && entries.length === 0) {',
            $panel,
            'no debug data, and nothing recorded yet, means no panel at all'
        );
        // The same source the server-rendered toolbar uses: two renderers drifted
        // and the same bug then had to be fixed twice.
        $this->assertStringContainsString('FRAMEWORK-OWNED', $panel);

        // ...and the client feeds it every response
        $client = $this->read('frontend/lib/api.js');
        $this->assertStringContainsString(
            "import { record as recordDebug, reportError as reportDebugError } from './debug.js';",
            $client
        );
        $this->assertStringContainsString('recordDebug(method, path, response.status', $client);
    }

    /**
     * The panel can say where the router thinks it is, and where it is mounted.
     *
     * Neither is knowable from the framework's side: the route table lives in the
     * project's `lib/router.js` and the mount point is baked into it at scaffold
     * time. Together they are the "why does my deep link 404" failure — an
     * application served under `/app` whose router base is empty resolves every
     * deep link to its home screen and says nothing at all.
     */
    public function testTheRouterAndTheShellTellThePanelWhereTheApplicationIs(): void
    {
        // Act
        $this->runInit(['--app-style' => 'hybrid', '--spa-stack' => 'svelte']);

        // Assert — the router reports every navigation, with its base
        $router = $this->read('frontend/lib/router.js');
        $this->assertStringContainsString("import { reportRoute } from './debug.js';", $router);
        $this->assertStringContainsString('reportRoute(name, { base: BASE });', $router);

        // ...and the shell publishes the mount point, so the tab is useful even
        // in a project whose router.js predates this
        $shell = $this->read('www/app.php');
        $this->assertStringContainsString("'routerBase' => '/app'", $shell);
    }

    /**
     * Failures the application handles itself still reach the Errors tab.
     *
     * The global `error` / `unhandledrejection` handlers in the panel only see
     * what *nobody* caught, and the two most common front-end failures are both
     * caught: an `ApiError` a screen turns into a message, and a component that
     * throws inside a `<svelte:boundary>`. Without these three call sites the
     * tab would be empty for exactly the pages that are visibly broken — and a
     * network failure, which produces no response at all, would leave no trace
     * anywhere in the panel.
     */
    public function testTheClientAndTheBoundaryHandTheirFailuresToThePanel(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — an HTTP failure, reported with the request it belongs to
        $client = $this->read('frontend/lib/api.js');
        $this->assertStringContainsString("kind: 'ApiError'", $client);
        // ...a network failure, which has no status and no payload to record
        $this->assertStringContainsString("kind: 'network'", $client);
        $this->assertStringContainsString('throw error;', $client, 'and it is re-thrown, not swallowed');

        // Assert — a screen that throws while rendering keeps the shell, and the
        // failure is handed over rather than only shown
        $app = $this->read('frontend/App.svelte');
        $this->assertStringContainsString('<svelte:boundary', $app);
        $this->assertStringContainsString("reportError(err, { kind: 'component' })", $app);
        $this->assertStringContainsString("import { reportError } from './lib/debug.js';", $app);
    }

    /**
     * The project docs name the panel's file and forbid replacing it.
     *
     * This is the failure this assertion guards: the panel is invisible until a
     * response carries debug data, so a reader who is only told that "a panel
     * shows it" concludes the framework ships the *data* for a SPA and that the
     * rendering is theirs to write — and writes a second panel beside the one
     * already wired in. Naming `lib/debug.js`, marking it framework-owned, and
     * giving the recovery command for older projects is what prevents that.
     */
    public function testDocsNameTheDebugPanelAndForbidRewritingIt(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — CLAUDE.md: the file, its ownership, and the recovery route.
        $claude = $this->read('CLAUDE.md');
        $this->assertStringContainsString('frontend/lib/debug.js', $claude, 'the panel is named, not alluded to');
        $this->assertStringContainsString('FRAMEWORK-OWNED', $claude);
        $this->assertStringContainsString('Do not write your own debug panel', $claude);
        // An older project has no panel at all; without the command the only way
        // to get one is by hand, which is how a rewrite starts.
        $this->assertStringContainsString('project:resync --debug-panel --all', $claude);
        // Why the HTML toolbar is not an option here — stated so the reader does
        // not "fix" the shell by booting the framework in it.
        $this->assertStringContainsString('does not boot', $claude);

        // Assert — README carries the same short version.
        $readme = $this->read('README.md');
        $this->assertStringContainsString('frontend/lib/debug.js', $readme);
        $this->assertStringContainsString('no reason to build another one', $readme);
    }

    /**
     * The build-less stack's docs point at its own path.
     *
     * A documented path that does not exist in this project is worse than none:
     * the reader looks for `frontend/`, finds nothing, and concludes the panel
     * was never shipped.
     */
    public function testDebugPanelDocsFollowTheBuildlessPath(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $claude = $this->read('CLAUDE.md');
        $this->assertStringContainsString('www/assets/js/lib/debug.js', $claude);
        $this->assertStringNotContainsString('frontend/lib/debug.js', $claude);
        $this->assertStringContainsString('www/assets/js/lib/debug.js', $this->read('README.md'));
    }

    /**
     * A SPA project ships a front-end testing guide.
     *
     * The scaffold provides a working test setup and real tests; without a guide
     * beside them the next screen arrives untested, because the examples are not
     * obviously extensible. It is generated per project, so it names this
     * project's runner, directories and commands rather than describing a setup
     * the reader does not have.
     */
    public function testFrontEndTestingGuideIsScaffolded(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the guide exists and is about *this* stack
        $guide = $this->read('docs/FRONTEND_TESTING.md');
        $this->assertStringContainsString('Testing the SpaApp front end', $guide);
        $this->assertStringContainsString('Vitest', $guide);
        $this->assertStringContainsString('frontend/__tests__', $guide);
        $this->assertStringContainsString('@testing-library/svelte', $guide);
        $this->assertStringContainsString('/api/1.0/status', $guide, 'the example uses the real prefix');
        $this->assertStringNotContainsString('{{', $guide, 'every token must be resolved');

        // ...and both documents point at it, or nobody finds it
        $this->assertStringContainsString('docs/FRONTEND_TESTING.md', $this->read('CLAUDE.md'));
        $this->assertStringContainsString('docs/FRONTEND_TESTING.md', $this->read('README.md'));
    }

    /**
     * The build-less stack gets the same guide, describing its own runner —
     * Vitest instructions would be useless in a project with no npm packages.
     */
    public function testTestingGuideMatchesTheBuildlessStack(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $guide = $this->read('docs/FRONTEND_TESTING.md');
        $this->assertStringContainsString('node --test', $guide);
        $this->assertStringContainsString('tests/js', $guide);
        $this->assertStringNotContainsString('vi.stubGlobal', $guide, 'no Vitest API in a project without it');
        $this->assertStringNotContainsString('@testing-library/svelte', $guide);
    }

    /**
     * An MVC project has no front end to test, so no guide is written — a
     * document describing files that do not exist is worse than none.
     */
    public function testNoTestingGuideForAnMvcProject(): void
    {
        // Act
        $this->runInit();

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/docs/FRONTEND_TESTING.md');
    }

    /**
     * A project also has to explain itself to the person who clones it, not
     * only to an AI assistant — so a README is written, matching the choices
     * made during init.
     */
    public function testReadmeIsGeneratedAndMatchesTheProject(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $readme = $this->read('README.md');
        $this->assertStringContainsString('# SpaApp', $readme);
        $this->assertStringContainsString('Svelte 5 + Vite + Tailwind/daisyUI', $readme);
        $this->assertStringContainsString('docker-compose up -d', $readme, 'this project chose Docker');
        $this->assertStringContainsString('http://localhost:8080', $readme, 'the port it will answer on');
        $this->assertStringContainsString('./dockernpm run dev', $readme);
        $this->assertStringContainsString('apiKey', $readme, 'the API contract is not a surprise');
        $this->assertStringContainsString('CLAUDE.md', $readme, 'points at the deeper conventions');
    }

    /**
     * The README follows the build-less stack too: no npm instructions for a
     * project that has no toolchain.
     */
    public function testReadmeMatchesTheBuildlessStack(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $readme = $this->read('README.md');
        $this->assertStringContainsString('www/assets/js/', $readme);
        $this->assertStringContainsString('no build step', $readme);
        $this->assertStringNotContainsString('./dockernpm', $readme);
    }

    /**
     * The final summary has to tell the developer how to build, develop and
     * test the front end — the scaffold is otherwise invisible.
     *
     * It names the project's **own CLI** for building and serving, because that
     * is where every other command in the project lives: sending the reader to
     * `./dockernpm run dev` for this one workflow meant it never appeared in
     * `pramnos list` and had to be remembered from the docs. npm is still named
     * once, for the scripts the two shortcuts do not wrap.
     */
    public function testSummaryExplainsTheFrontEndWorkflow(): void
    {
        // Act — the CLI name is what the shortcuts are printed under
        $tester = $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('SPA front end', $display);
        $this->assertStringContainsString('spa:dev', $display);
        $this->assertStringContainsString('spa:build', $display);
        $this->assertStringContainsString('./dockernpm run build|dev', $display,
            'the npm escape hatch stays named');
        $this->assertStringContainsString('./testjs', $display);
    }

    /**
     * An unknown stack name must not scaffold something half-built; it falls
     * back to the documented default rather than writing nothing.
     */
    public function testUnknownStackFallsBackToTheDefault(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'ember']);

        // Assert — the Svelte scaffold, not an empty frontend/
        $this->assertFileExists($this->tmpDir . '/frontend/App.svelte');
    }

    /**
     * The interactive path works too, not just the CLI options: answering the
     * style and stack questions by hand must produce the same scaffold. This is
     * how a developer actually meets the feature.
     *
     * Both questions are answered with a **number** — typing "spa" or "vanilla"
     * is not something anyone should have to spell at a prompt.
     */
    public function testInteractiveAnswersSelectTheStackAsWell(): void
    {
        // Arrange
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->setInputs([
            'Interactive SPA',   // App name
            'InteractiveSpa',    // Namespace
            '2',                 // Step 1b: application style → spa
            '3',                 // Step 1c: front-end stack → vanilla (no build)
            'n', 'n', 'n', 'n', 'n', // features
            'n',                 // webhook
            '',                  // UI system (plain-css)
            'n',                 // extra libraries
            'n',                 // Docker
            '0',                 // database type (mysql)
            'localhost',
            'idb',
            'iuser',
            'ipass',
            '',                  // prefix
            'Author',
            'author@example.com',
        ]);

        // Act
        $tester->execute([], ['interactive' => true]);

        // Assert — the answered stack, not the default one
        $this->assertFileExists($this->tmpDir . '/www/spa.php');
        $this->assertFileExists($this->tmpDir . '/www/assets/js/main.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend');
    }

    /**
     * Every generated JavaScript file must actually parse.
     *
     * Assertions over substrings cannot see a missing comma between two plugin
     * entries — a real `npm run build` did, with "Expected ] but found
     * pramnosHotFile". Node parses the files here so that class of defect fails
     * in the suite instead of in someone's project.
     *
     * @param string $stack
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('spaStackProvider')]
    public function testGeneratedJavaScriptParses(string $stack): void
    {
        // Arrange
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not available in this environment');
        }
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => $stack]);

        // Act + Assert — check every generated .js/.svelte-free module
        $files = array_merge(
            glob($this->tmpDir . '/*.js') ?: [],
            glob($this->tmpDir . '/frontend/*.js') ?: [],
            glob($this->tmpDir . '/frontend/lib/*.js') ?: [],
            glob($this->tmpDir . '/www/assets/js/*.js') ?: [],
            glob($this->tmpDir . '/www/assets/js/lib/*.js') ?: []
        );
        $this->assertNotEmpty($files, 'the stack must generate JavaScript');

        foreach ($files as $file) {
            // node --check parses .mjs as an ES module, which these all are.
            $asModule = $file . '.mjs';
            copy($file, $asModule);
            exec('node --check ' . escapeshellarg($asModule) . ' 2>&1', $out, $status);
            unlink($asModule);
            $this->assertSame(
                0,
                $status,
                basename($file) . " does not parse:\n" . implode("\n", $out)
            );
            $out = [];
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spaStackProvider(): array
    {
        return [
            'svelte'       => ['svelte'],
            'vanilla-vite' => ['vanilla-vite'],
            'vanilla'      => ['vanilla'],
        ];
    }

    /**
     * Everything the container writes into the bind mount must belong to the
     * host user, not to root.
     *
     * The image remaps its www-data user to the host user's ids, and the ids
     * travel through .env because docker-compose interpolates ${UID}/${GID}
     * from there — a plain shell does not export UID, so relying on the
     * environment would silently fall back to the 1000 default.
     */
    public function testContainerWritesFilesAsTheHostUser(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert — the image accepts and applies the ids
        $dockerfile = $this->read('Dockerfile');
        $this->assertStringContainsString('ARG UID=1000', $dockerfile);
        $this->assertStringContainsString('usermod -o -u $UID', $dockerfile);

        // ...compose passes them as build args...
        $compose = $this->read('docker-compose.yml');
        $this->assertStringContainsString('UID: ${UID:-1000}', $compose);
        $this->assertStringContainsString('GID: ${GID:-1000}', $compose);

        // ...and .env carries this host's actual ids
        $env = $this->read('.env');
        $ids = Init::hostUserIds();
        $this->assertStringContainsString('UID=' . $ids['UID'], $env);
        $this->assertStringContainsString('GID=' . $ids['GID'], $env);

        // .env is machine-specific and must not be committed
        $this->assertStringContainsString('/.env', $this->read('.gitignore'));
    }

    /**
     * An existing .env must survive: appending the ids may not clobber
     * application configuration a developer already put there.
     */
    public function testExistingEnvIsPreserved(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/.env', "APP_SECRET=keepme\n");

        // Act
        $this->runInit(['--app-style' => 'mvc']);

        // Assert
        $env = $this->read('.env');
        $this->assertStringContainsString('APP_SECRET=keepme', $env);
        $this->assertStringContainsString('UID=', $env);
    }

    /**
     * npm must not be run as root anywhere, and existing root-owned artefacts
     * must be repaired rather than hit.
     *
     * The API-docs generator installs node modules too. When it ran as root it
     * created a root-owned `node_modules`, and every later npm command — the SPA
     * build, `./dockernpm` — died with `EACCES: permission denied, mkdir
     * '/var/www/html/node_modules/@esbuild/…'`. So doc.sh runs as the mapped
     * user, `./dockernpm` hands back anything still owned by root, and init
     * repairs the tree once after the containers come up.
     */
    public function testNpmNeverRunsAsRootAndOwnershipIsRepaired(): void
    {
        // Act — API docs and a SPA build stack together: the failing combination
        $this->runInit([
            '--app-style' => 'spa',
            '--spa-stack' => 'svelte',
            '--api-docs'  => 'y',
        ]);

        // Assert — the docs generator installs and runs as the mapped user
        $docSh = $this->read('scripts/doc.sh');
        $this->assertStringContainsString('-u www-data', $docSh);
        $this->assertStringContainsString('HOME=/tmp', $docSh, 'npm needs a writable cache home');

        // ...and dockernpm repairs a root-owned tree before touching it
        $dockernpm = $this->read('dockernpm');
        $this->assertStringContainsString('-u root', $dockernpm);
        $this->assertStringContainsString('chown -R www-data:www-data', $dockernpm);
        $this->assertStringContainsString('node_modules', $dockernpm);
    }

    /**
     * The generated helper scripts run as the mapped user too — otherwise
     * `./dockernpm install` recreates the very root-owned node_modules the
     * image mapping exists to prevent.
     */
    public function testHelperScriptsRunAsTheMappedUser(): void
    {
        // Act
        $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $this->assertStringContainsString('-u www-data', $this->read('dockernpm'));
        $this->assertStringContainsString('-u www-data', $this->read('testjs'));
        $this->assertStringContainsString('-u www-data', $this->read('dockerbash'));
        $this->assertStringContainsString('-u www-data', $this->read('dockertest'));
        // The CLI wrapper too — it runs migrations, which write into var/logs.
        $this->assertStringContainsString('-u www-data', $this->read('spaapp'));
    }

    /**
     * Recursively delete a directory tree created by a test.
     */
    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }
}
