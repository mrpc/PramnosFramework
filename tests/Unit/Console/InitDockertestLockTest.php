<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The `dockertest` script `init` writes has to run on the machine it lands on.
 *
 * WHAT: that the scaffolded test runner locks with a directory rather than flock,
 *       and releases it.
 * WHY:  flock is Linux-only. On macOS it is simply absent, so
 *       `flock: command not found` made `_acquire_lock` fail and every single run
 *       report that another run was already in progress — the suite could not be
 *       run at all, on a platform where the framework's docs tell you to run it.
 *
 *       The framework's own `dockertest` was fixed for exactly this reason and
 *       kept generating the broken version for every project it scaffolded.
 *
 * A shell script cannot be unit-tested for behaviour without running it, so these
 * assert on the text — and on `bash -n`, which at least proves the thing parses.
 */
class InitDockertestLockTest extends TestCase
{
    /** @var string Temporary project root */
    private string $tmpDir;

    /** @var Init The command under test */
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_lock_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command                 = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /** Scaffolds a project and returns the generated test runner. */
    private function scaffoldDockertest(): string
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute([
            '--app-name'      => 'LockApp',
            '--namespace'     => 'LockApp',
            '--features'      => '',
            '--ui-system'     => 'plain-css',
            '--docker'        => 'y',
            '--docker-port'   => '8080',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'db',
            '--db-name'       => 'lock_db',
            '--db-user'       => 'root',
            '--db-pass'       => 'secret',
            '--db-prefix'     => '',
            '--rest-api'      => 'n',
            '--api-docs'      => 'n',
            '--webhook'       => 'n',
            '--app-style'     => 'mvc',
            '--no-install'    => true,
            '--no-download'   => true,
            '--no-migrations' => true,
        ]);

        $path = $this->tmpDir . '/dockertest';
        $this->assertFileExists($path, 'init must write a test runner');

        return (string) file_get_contents($path);
    }

    /**
     * The script does not use flock.
     *
     * The whole finding in one assertion: a project scaffolded on macOS could not
     * run its own test suite.
     */
    public function testTheLockDoesNotUseFlock(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert
        $this->assertStringNotContainsString('flock -n', $script);
        $this->assertStringNotContainsString('exec 9>>', $script);
    }

    /**
     * It locks with a directory, which is atomic everywhere.
     *
     * `mkdir` succeeds only when the directory does not exist, on every platform
     * the framework claims to support.
     */
    public function testTheLockIsADirectory(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert
        $this->assertStringContainsString('LOCK_DIR=', $script);
        $this->assertStringContainsString('mkdir "$LOCK_DIR"', $script);
        $this->assertStringContainsString('LOCK_PID_FILE=', $script);
    }

    /**
     * The lock is released on exit.
     *
     * flock released itself when the process ended; a directory does not. Without
     * the trap the first run would leave a lock behind and the second would refuse
     * to start — trading one platform's failure for every platform's.
     */
    public function testTheLockIsReleasedOnExit(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert
        $this->assertStringContainsString("trap '_release_lock' EXIT", $script);
        $this->assertStringContainsString('_release_lock() { rm -rf "$LOCK_DIR"; }', $script);
    }

    /**
     * A stale lock is recognised and cleared.
     *
     * A hard-killed run leaves the directory behind. Without the PID check the
     * project would need a manual `rm -rf` before it could test again, which is
     * the sort of instruction that ends up in a README instead of being fixed.
     */
    public function testAStaleLockIsCleared(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert
        $this->assertStringContainsString('Stale lock detected', $script);
        $this->assertStringContainsString('kill -0', $script);
    }

    /**
     * The script does not assume GNU `timeout` exists.
     *
     * Same platform story as flock, and found immediately after it: `timeout` is
     * GNU coreutils and absent from macOS. The daemon-hang guards call it, so on a
     * Mac every one of them exited 127 — "command not found" — and the very first
     * guard concluded that Docker was not responding and refused to run, while
     * Docker was running perfectly.
     */
    public function testTheScriptDoesNotAssumeGnuTimeout(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert — a real timeout is preferred, gtimeout next, then a bash one
        $this->assertStringContainsString('command -v timeout', $script);
        $this->assertStringContainsString('command -v gtimeout', $script);
        $this->assertStringContainsString('_bash_timeout', $script);
    }

    /**
     * The fallback reports GNU timeout's 124 on a deadline.
     *
     * The callers test for 124 to tell "the daemon is wedged" from "the command
     * failed". A fallback that returned something else would turn a hang into a
     * misleading error.
     */
    public function testTheFallbackReportsTheConventionalTimeoutCode(): void
    {
        // Act
        $script = $this->scaffoldDockertest();

        // Assert
        $this->assertStringContainsString('rc=124', $script);
    }

    /**
     * The generated script is valid bash.
     *
     * It is assembled from a heredoc with escaped dollars throughout, so "it was
     * written" is a long way from "it runs".
     */
    public function testTheGeneratedScriptParses(): void
    {
        // Act
        $this->scaffoldDockertest();

        // Assert
        $output = [];
        exec('bash -n ' . escapeshellarg($this->tmpDir . '/dockertest') . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, "bash -n failed:\n" . implode("\n", $output));
    }

    /** And it is executable, or nobody can run it. */
    public function testTheScriptIsExecutable(): void
    {
        // Act
        $this->scaffoldDockertest();

        // Assert
        $this->assertTrue(is_executable($this->tmpDir . '/dockertest'));
    }
}
