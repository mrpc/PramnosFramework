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
     * The final summary has to tell the developer how to build, develop and
     * test the front end — the scaffold is otherwise invisible.
     */
    public function testSummaryExplainsTheFrontEndWorkflow(): void
    {
        // Act
        $tester = $this->runInit(['--app-style' => 'spa', '--spa-stack' => 'svelte']);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('SPA front end', $display);
        $this->assertStringContainsString('./dockernpm run dev', $display);
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
            'spa',               // Step 1b: application style
            'vanilla',           // Step 1c: front-end stack
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
