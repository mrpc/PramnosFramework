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

    public function installUiFramework(string $uiSystem, string $appName, array $features = []): void
    {
        if ($this->throw) {
            throw new \RuntimeException('install failed');
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
