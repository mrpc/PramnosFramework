<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Pramnos\Console\Commands\SwitchUi;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Init stub whose asset installation is a no-op (or throws on demand), so the
 * command's execute() flow can be exercised without pulling vendor assets.
 */
class StubInit extends Init
{
    public bool $throw = false;

    /** @var array<string, string> Files to write, relative to the project, as an install would */
    public array $writes = [];

    /** The web root the command handed over. */
    public string $servedFrom = '';

    public function installUiFramework(string $uiSystem, string $appName, array $features = []): void
    {
        if ($this->throw) {
            throw new \RuntimeException('install failed');
        }
        $this->servedFrom = (string) (new \ReflectionProperty(Init::class, 'webRoot'))->getValue($this);
        foreach ($this->writes as $file => $contents) {
            $path = $this->targetBaseDir . '/' . str_replace('{web}', $this->servedFrom, $file);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
    }
}

/** SwitchUi that installs assets via an injectable stub. */
class TestableSwitchUi extends SwitchUi
{
    public StubInit $stub;

    protected function makeInit(): Init
    {
        return $this->stub;
    }
}

/**
 * Unit tests for the project:switch-ui command.
 *
 * The command flips a scaffolded project between UI frameworks. Tests point
 * targetBaseDir at a temporary project so no real files are touched, and use
 * plain-css for the happy path (the only framework with no vendor assets to
 * pull, so it runs fully offline).
 */
#[CoversClass(SwitchUi::class)]
class SwitchUiTest extends TestCase
{
    private string $tmpDir;
    private SwitchUi $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_switchui_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/app', 0777, true);

        $this->command = new SwitchUi();
        $this->command->targetBaseDir = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    /** Write app/app.php with the given config body. */
    private function writeAppConfig(string $body): void
    {
        file_put_contents($this->tmpDir . '/app/app.php', "<?php\nreturn [\n{$body}];\n");
    }

    private function tester(): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        return new CommandTester($this->command);
    }

    /**
     * An unknown framework is rejected with INVALID before touching anything.
     */
    public function testUnknownFrameworkIsRejected(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['framework' => 'nonsense']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("Unknown UI framework 'nonsense'", $tester->getDisplay());
    }

    /**
     * A valid framework but no app/app.php (not a project root) fails cleanly.
     */
    public function testMissingAppConfigFails(): void
    {
        // No app/app.php written (ensure absent).
        @unlink($this->tmpDir . '/app/app.php');

        $tester = $this->tester();
        $exit = $tester->execute(['framework' => 'bootstrap']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Could not find app/app.php', $tester->getDisplay());
    }

    /**
     * Switching to plain-css (no vendor assets) updates scaffold_theme in
     * app/app.php and reports success — full happy path through execute().
     */
    public function testSwitchToPlainCssUpdatesConfig(): void
    {
        $this->writeAppConfig(
            "    'name' => 'MyApp',\n"
            . "    'theme' => 'default',\n"
            . "    'scaffold_theme' => 'bootstrap',\n"
            . "    'features' => ['auth'],\n"
            . "    'csp' => [\n        'script-src' => [],\n        'style-src'  => [\"'unsafe-inline'\"]\n    ],\n"
        );

        $tester = $this->tester();
        $exit = $tester->execute(['framework' => 'plain-css']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $config = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'scaffold_theme' => 'plain-css'", $config);
        $this->assertStringContainsString("'style-src'  => []", $config,
            'plain-css must reset style-src to the strict empty list');
        $this->assertStringContainsString("Switched UI framework to 'plain-css'", $tester->getDisplay());
    }

    /**
     * When app.php has no scaffold_theme key yet, it is inserted after 'theme'.
     * Exercised directly on the private patchAppConfig so no vendor assets are
     * pulled.
     */
    public function testPatchInsertsScaffoldThemeWhenAbsent(): void
    {
        $path = $this->tmpDir . '/app/app.php';
        file_put_contents(
            $path,
            "<?php\nreturn [\n    'name' => 'MyApp',\n    'theme' => 'default',\n    'csp' => [\n        'style-src'  => []\n    ]\n];\n"
        );

        $method = new \ReflectionMethod(SwitchUi::class, 'patchAppConfig');
        $method->invoke($this->command, $path, 'tailwind');

        $config = file_get_contents($path);
        $this->assertStringContainsString("'scaffold_theme' => 'tailwind'", $config,
            'scaffold_theme must be inserted after the theme line when absent');
        $this->assertStringContainsString("'style-src'  => [\"'unsafe-inline'\"]", $config,
            "tailwind must relax style-src to 'unsafe-inline' for its runtime build");
    }

    /**
     * A non-Tailwind framework keeps the strict empty style-src.
     */
    public function testPatchKeepsStrictStyleSrcForBootstrap(): void
    {
        $path = $this->tmpDir . '/app/app.php';
        file_put_contents(
            $path,
            "<?php\nreturn [\n    'theme' => 'default',\n    'scaffold_theme' => 'tailwind',\n    'csp' => [\n        'style-src'  => [\"'unsafe-inline'\"]\n    ]\n];\n"
        );

        $method = new \ReflectionMethod(SwitchUi::class, 'patchAppConfig');
        $method->invoke($this->command, $path, 'bootstrap');

        $config = file_get_contents($path);
        $this->assertStringContainsString("'scaffold_theme' => 'bootstrap'", $config);
        $this->assertStringContainsString("'style-src'  => []", $config,
            'bootstrap must reset style-src to the strict empty list');
    }

    /** Build a TestableSwitchUi (stubbed asset install) + its CommandTester. */
    private function testableTester(bool $throwOnInstall = false): array
    {
        $cmd = new TestableSwitchUi();
        $cmd->targetBaseDir = $this->tmpDir;
        $cmd->stub = new StubInit();
        $cmd->stub->throw = $throwOnInstall;
        $app = new Application();
        $app->add($cmd);
        return [$cmd, new CommandTester($cmd)];
    }

    /**
     * Switching to a non-plain-css framework succeeds (assets stubbed) and
     * reports the vendor-assets line.
     */
    public function testSwitchToBootstrapReportsVendorAssets(): void
    {
        $this->writeAppConfig(
            "    'name' => 'MyApp',\n    'theme' => 'default',\n    'scaffold_theme' => 'plain-css',\n"
            . "    'features' => ['auth'],\n    'csp' => [\n        'style-src'  => []\n    ],\n"
        );
        [, $tester] = $this->testableTester();

        $exit = $tester->execute(['framework' => 'bootstrap']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('vendor assets installed', $tester->getDisplay());
        $this->assertStringContainsString("'scaffold_theme' => 'bootstrap'",
            file_get_contents($this->tmpDir . '/app/app.php'));
    }

    /**
     * A failure during asset installation surfaces as FAILURE (the catch path).
     */
    public function testInstallFailureReturnsFailure(): void
    {
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'plain-css',\n");
        [, $tester] = $this->testableTester(throwOnInstall: true);

        $exit = $tester->execute(['framework' => 'tailwind']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Failed to install UI assets', $tester->getDisplay());
    }

    /**
     * Switching to the framework already in use re-installs assets and says so
     * (the current === target branch).
     */
    public function testAlreadyCurrentFrameworkReinstalls(): void
    {
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'plain-css',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        [, $tester] = $this->testableTester();

        $exit = $tester->execute(['framework' => 'plain-css']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString("already 'plain-css'", $tester->getDisplay());
    }

    /**
     * An app.php with no `features` key is tolerated (defaults to an empty list).
     */
    public function testFeaturesKeyAbsentIsTolerated(): void
    {
        $this->writeAppConfig("    'name' => 'MyApp',\n    'theme' => 'default',\n    'scaffold_theme' => 'bootstrap',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        [, $tester] = $this->testableTester();

        $exit = $tester->execute(['framework' => 'tailwind']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString("'scaffold_theme' => 'tailwind'",
            file_get_contents($this->tmpDir . '/app/app.php'));
    }

    /**
     * A layout file or stylesheet the application changed is kept: the switch writes over it, and
     * the previous copy goes to `var/switch-ui/<time>/` and is named in the output. One rewritten
     * with the same bytes, or that did not exist, is not copied.
     */
    public function testAChangedThemeFileIsKeptBeforeItIsReplaced(): void
    {
        // Arrange — a project served from public/, with its own header and an untouched footer
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'bootstrap',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        mkdir($this->tmpDir . '/public', 0777, true);
        file_put_contents($this->tmpDir . '/public/index.php', '<?php');
        mkdir($this->tmpDir . '/app/themes/default', 0777, true);
        file_put_contents($this->tmpDir . '/app/themes/default/header.php', 'OUR HEADER');
        file_put_contents($this->tmpDir . '/app/themes/default/footer.php', 'SAME FOOTER');
        [$cmd, $tester] = $this->testableTester();
        $cmd->stub->writes = [
            'app/themes/default/header.php'  => 'NEW HEADER',
            'app/themes/default/footer.php'  => 'SAME FOOTER',
            'app/themes/default/head.php'    => 'NEW HEAD',
            '{web}/assets/css/style.css'     => 'NEW CSS',
        ];

        // Act
        $exit    = $tester->execute(['framework' => 'tailwind']);
        $display = $tester->getDisplay();
        $backups = glob($this->tmpDir . '/var/switch-ui/*', GLOB_ONLYDIR) ?: [];

        // Assert — installed where the project is served from, the changed file kept and named
        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertSame('public', $cmd->stub->servedFrom);
        $this->assertFileExists($this->tmpDir . '/public/assets/css/style.css');
        $this->assertCount(1, $backups);
        $this->assertSame('OUR HEADER', file_get_contents($backups[0] . '/app/themes/default/header.php'));
        $this->assertFileDoesNotExist($backups[0] . '/app/themes/default/footer.php', 'unchanged: no copy');
        $this->assertFileDoesNotExist($backups[0] . '/app/themes/default/head.php', 'new: nothing to keep');
        $this->assertStringContainsString('previous copies are in var/switch-ui/', $display);
        $this->assertStringContainsString('    app/themes/default/header.php', $display);
    }

    /**
     * A Svelte SPA builds its own stylesheet, so the switch rewrites its `ui.css` for the new
     * system — keeping the previous copy like any theme file — and says what the SPA still needs:
     * an app.css that imports ui.css, Bootstrap in package.json, and a build.
     */
    public function testASvelteSpaGetsTheNewSystemsUiCss(): void
    {
        // Arrange — a SPA from before ui.css: app.css registers daisyUI itself, no bootstrap package
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'tailwind',\n    'app_style' => 'spa',\n"
            . "    'spa_stack' => 'svelte',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        mkdir($this->tmpDir . '/frontend', 0777, true);
        file_put_contents($this->tmpDir . '/frontend/app.css', "@import \"tailwindcss\";\n@plugin \"daisyui\";\n");
        file_put_contents($this->tmpDir . '/frontend/ui.css', '/* our own */');
        file_put_contents($this->tmpDir . '/package.json', '{"devDependencies": {"daisyui": "^5.0.0"}}');
        [, $tester] = $this->testableTester();

        // Act
        $exit    = $tester->execute(['framework' => 'bootstrap']);
        $display = $tester->getDisplay();
        $backups = glob($this->tmpDir . '/var/switch-ui/*', GLOB_ONLYDIR) ?: [];

        // Assert — Bootstrap's ui.css, from npm and the web root's theme stylesheet
        $this->assertSame(Command::SUCCESS, $exit, $display);
        $uiCss = (string) file_get_contents($this->tmpDir . '/frontend/ui.css');
        $this->assertStringContainsString('@import "bootstrap/dist/css/bootstrap.min.css";', $uiCss);
        $this->assertStringContainsString('@import "../www/assets/css/style.css";', $uiCss);
        // The previous copy kept, as any file the switch writes over
        $this->assertCount(1, $backups);
        $this->assertSame('/* our own */', file_get_contents($backups[0] . '/frontend/ui.css'));
        // What the project still has to do, each said once
        $this->assertStringContainsString('frontend/ui.css rewritten', $display);
        $this->assertStringContainsString('does not import ui.css', $display);
        $this->assertStringContainsString('npm install -D bootstrap', $display);
        $this->assertStringContainsString('npm run build', $display);
    }

    /**
     * A SPA whose app.css imports ui.css and whose package.json has Bootstrap is told only to
     * rebuild; tailwind writes daisyUI's ui.css. A project with no SPA hears none of it.
     */
    public function testAnUpToDateSpaIsOnlyToldToRebuild(): void
    {
        // Arrange
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'bootstrap',\n    'app_style' => 'spa',\n"
            . "    'spa_stack' => 'svelte',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        mkdir($this->tmpDir . '/frontend', 0777, true);
        file_put_contents($this->tmpDir . '/frontend/app.css', "@import \"./ui.css\";\n");
        file_put_contents($this->tmpDir . '/package.json', '{"devDependencies": {"bootstrap": "^5.3.0"}}');
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'tailwind']);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('@plugin "daisyui";', (string) file_get_contents($this->tmpDir . '/frontend/ui.css'));
        $this->assertStringNotContainsString('does not import ui.css', $display);
        $this->assertStringNotContainsString('npm install', $display);
        $this->assertStringContainsString('npm run build', $display);
    }

    /** A vanilla SPA draws with the web root's stylesheet: no ui.css, and nothing said about one. */
    public function testAVanillaSpaGetsNoUiCss(): void
    {
        // Arrange
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'tailwind',\n    'app_style' => 'spa',\n"
            . "    'spa_stack' => 'vanilla-vite',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'plain-css']);

        // Assert
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/ui.css');
        $this->assertStringNotContainsString('ui.css', $tester->getDisplay());
    }

    /** Nothing changed, nothing kept, and nothing said about it. */
    public function testNothingChangedIsNothingKept(): void
    {
        // Arrange
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'plain-css',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'plain-css']);

        // Assert
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/var/switch-ui');
        $this->assertStringNotContainsString('previous copies', $tester->getDisplay());
    }

    /**
     * Views copied with project:publish-views keep the system they were copied from, and the
     * switch says which — under src/Views and src/Admin/Views — with how to take the new copy.
     */
    public function testPublishedViewsAreNamed(): void
    {
        // Arrange — two bundled bootstrap views published, one view of the application's own
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'bootstrap',\n    'csp' => [\n        'style-src'  => []\n    ],\n");
        foreach (['src/Views/users/users.html.php', 'src/Admin/Views/roles/roles.html.php', 'src/Views/orders/orders.html.php'] as $view) {
            mkdir(dirname($this->tmpDir . '/' . $view), 0777, true);
            file_put_contents($this->tmpDir . '/' . $view, 'x');
        }
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'tailwind']);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString("2 view(s) copied with project:publish-views keep their 'bootstrap' markup", $display);
        $this->assertStringContainsString('    src/Admin/Views/roles/roles.html.php', $display);
        $this->assertStringContainsString('    src/Views/users/users.html.php', $display);
        $this->assertStringNotContainsString('orders.html.php', $display, 'the application\'s own view is not a copy');
        $this->assertStringContainsString('project:publish-views --group=<name> --force', $display);
    }

    /** Over ten published views, ten are named and the rest counted. */
    public function testManyPublishedViewsAreCounted(): void
    {
        // Arrange — every bundled users/ view of bootstrap, published
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'bootstrap',\n");
        $bundled = glob(ROOT . '/scaffolding/themes/bootstrap/views/*/*.html.php') ?: [];
        $this->assertGreaterThan(10, count($bundled));
        foreach ($bundled as $file) {
            $relative = substr($file, strlen(ROOT . '/scaffolding/themes/bootstrap/views/'));
            @mkdir(dirname($this->tmpDir . '/src/Views/' . $relative), 0777, true);
            file_put_contents($this->tmpDir . '/src/Views/' . $relative, 'x');
        }
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'plain-css']);

        // Assert
        $this->assertStringContainsString('… and ' . (count($bundled) - 10) . ' more', $tester->getDisplay());
    }

    /**
     * Tailwind needs 'unsafe-inline' in style-src. With a CSP block that has no style-src there is
     * nothing to update, and the switch says what to add rather than leaving the policy blocking it.
     */
    public function testTailwindWithoutAStyleSrcSaysWhatToAdd(): void
    {
        // Arrange
        $this->writeAppConfig("    'theme' => 'default',\n    'scaffold_theme' => 'plain-css',\n");
        [, $tester] = $this->testableTester();

        // Act
        $tester->execute(['framework' => 'tailwind']);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString("app/app.php has no CSP 'style-src' to update", $display);
        $this->assertStringContainsString('  - app/app.php: scaffold_theme updated', $display);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
