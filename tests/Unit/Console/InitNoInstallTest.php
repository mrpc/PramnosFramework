<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `pramnos init --no-install`.
 *
 * Scaffolding files and installing dependencies are separate jobs, and there are
 * several reasons to want only the first: a CI job that installs from a lockfile of
 * its own, a machine with no network, a project whose `vendor/` is committed. The
 * measured reason was this framework's own suite, which scaffolded 61 projects per run
 * and so ran `composer update` 61 times — **85% of that class's runtime**, and a
 * dependency on the network from inside a unit test.
 *
 * What the flag must get right is the reporting. A silent skip ends with somebody
 * pointing a browser at the new application and getting a fatal about a missing
 * autoloader, with nothing to connect the two.
 */
class InitNoInstallTest extends TestCase
{
    /** @var string Temporary project root for this test */
    private string $tmpDir;

    /** @var Init The command under test */
    private Init $command;

    /**
     * Creates an isolated project root with the minimum `init` expects.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // Arrange
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_noinstall_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command                 = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    /**
     * Removes the temporary tree.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Cleanup
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * Runs `init` in the temporary project.
     *
     * @param array<string, mixed> $options Options merged over the non-interactive set
     * @return CommandTester The tester, for its display
     */
    private function runInit(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'      => 'NoInstallApp',
            '--namespace'     => 'NoInstallApp',
            '--features'      => '',
            '--ui-system'     => 'plain-css',
            '--docker'        => 'n',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'localhost',
            '--db-name'       => 'noinstall_db',
            '--db-user'       => 'noinstall',
            '--db-pass'       => 'secret',
            '--db-prefix'     => '',
            '--rest-api'      => 'n',
            '--api-docs'      => 'n',
            '--app-style'     => 'mvc',
            '--no-download'   => true,
            '--no-migrations' => true,
        ], $options));

        return $tester;
    }

    /**
     * The scaffold is still written in full — only the install is skipped.
     *
     * This is the assertion that makes the flag safe to use in the suite: if it
     * quietly reduced what gets generated, 52 tests would be asserting against a
     * different scaffold than the one users get, and nothing would say so.
     */
    public function testItStillWritesTheWholeScaffold(): void
    {
        // Act
        $exit = $this->runInit(['--no-install' => true])->getStatusCode();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->tmpDir . '/app/config/settings.php');
        $this->assertFileExists($this->tmpDir . '/app/app.php');
        $this->assertFileExists($this->tmpDir . '/www/index.php');
        $this->assertFileExists($this->tmpDir . '/src/Controllers/Home.php');
        $this->assertFileExists($this->tmpDir . '/phpunit.xml');
    }

    /**
     * It says dependencies were not installed, and names the command to run.
     *
     * Twice, deliberately: once where the step would have happened, and once in the
     * closing next-steps list, which is the part somebody actually reads.
     */
    public function testItReportsTheSkipAndWhatToRun(): void
    {
        // Act
        $display = $this->runInit(['--no-install' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('--no-install', $display);
        $this->assertStringContainsString('composer install', $display);
        $this->assertStringContainsString(
            'cannot boot without an autoloader',
            $display,
            'The next-steps list must explain the consequence, not just the fact.'
        );
    }

    /**
     * It does not claim the autoloader failed.
     *
     * `--no-install` is a choice, not a failure, and the two need different
     * messages: "autoloader sync failed" sends the reader looking for a broken
     * composer.
     */
    public function testItDoesNotReportAnAutoloaderFailure(): void
    {
        // Act
        $display = $this->runInit(['--no-install' => true])->getDisplay();

        // Assert
        $this->assertStringNotContainsString('autoloader sync failed', $display);
    }

    /**
     * Without the flag, the install still happens.
     *
     * The flag has to be opt-in: `init` finishing with an application that cannot
     * boot would be a worse default than a slow one. Asserted through the step's own
     * label rather than by looking for a `vendor/` directory, so the test does not
     * depend on what composer decides to do in a throwaway project.
     */
    public function testWithoutTheFlagDependenciesAreInstalled(): void
    {
        // Act
        $display = $this->runInit()->getDisplay();

        // Assert
        $this->assertStringContainsString('Syncing dependencies', $display);
        $this->assertStringNotContainsString('--no-install', $display);
    }
}
