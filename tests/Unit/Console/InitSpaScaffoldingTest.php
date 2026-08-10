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
