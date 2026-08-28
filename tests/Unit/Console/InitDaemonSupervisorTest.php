<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A scaffolded application whose features have background work gets something to run it.
 *
 * Nothing used to. `init` wrote the queue, the messaging tables and the schedule, and no
 * process that touched any of them — so a fresh project's background work simply did not
 * happen, in development and then in production. The symptom is never "the worker is not
 * running": it is a screen with no rows on it, a job that stays queued, a scheduled cleanup
 * that never ran, each of which reads as a bug in the code that would have used them.
 *
 * So the scaffold writes three things together: the supervisor class, its registration in the
 * application's console, and a container to run it in. Any one of them missing is the same
 * silence as before, which is why all three are asserted here rather than trusted to each
 * other.
 */
#[CoversClass(Init::class)]
class InitDaemonSupervisorTest extends TestCase
{
    private string $tmpDir = '';

    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-daemons-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command = new Init();
        $this->command->targetBaseDir = $this->tmpDir;
        $this->command->skipDockerRun = true;
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->tmpDir);
    }

    private function scaffold(string $features): void
    {
        $app = new Application();
        $app->add($this->command);
        (new CommandTester($this->command))->execute([
            '--app-name'    => 'DaemonApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'DaemonApp',
            '--features'    => $features,
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'db',
            '--db-name'     => 'daemon_db',
            '--db-user'     => 'daemon',
            '--db-pass'     => 'daemon_secret',
            '--db-prefix'   => '',
        ], ['interactive' => false]);
    }

    private function read(string $relative): string
    {
        $path = $this->tmpDir . '/' . $relative;
        $this->assertFileExists($path, $relative . ' should have been scaffolded');

        return (string) file_get_contents($path);
    }

    /**
     * With the queue enabled, docker-compose runs the supervisor beside the app.
     *
     * `restart: unless-stopped` is asserted with the rest, because this container's worst
     * failure is a *clean* exit: a machine that comes back before the database accepts
     * connections boots the framework into its maintenance page and returns 0. `on-failure`
     * looks at that and correctly does nothing — leaving the supervisor gone, with every other
     * container up and healthy beside it, which is exactly how it is not noticed.
     */
    public function testTheComposeFileRunsTheSupervisorWhenFeaturesNeedIt(): void
    {
        // Arrange & Act
        $this->scaffold('queue,messaging');

        // Assert
        $compose = $this->read('docker-compose.yml');
        $this->assertStringContainsString('daemons:', $compose);
        $this->assertStringContainsString('daemons:start', $compose);
        $this->assertStringContainsString('restart: unless-stopped', $compose);
    }

    /**
     * And the class it runs exists, is valid PHP, and declares the queue worker.
     *
     * A compose service pointing at a command nothing registers is a container that restarts
     * for ever printing "command not defined" into a log nobody is reading.
     */
    public function testTheSupervisorClassIsScaffoldedAndDeclaresTheQueueWorker(): void
    {
        // Arrange & Act
        $this->scaffold('queue');

        // Assert
        $daemons = $this->read('src/ConsoleCommands/Daemons.php');
        $this->assertStringContainsString('class Daemons extends DaemonOrchestrator', $daemons);
        $this->assertStringContainsString("queue:process --daemon", $daemons);
        $this->assertStringNotContainsString('{{', $daemons, 'every placeholder must be filled');

        // Valid PHP, checked by parsing it rather than by reading it
        $file = $this->tmpDir . '/src/ConsoleCommands/Daemons.php';
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * The application's console registers it, which is the half that makes it runnable.
     */
    public function testTheConsoleRegistersTheSupervisor(): void
    {
        // Arrange & Act
        $this->scaffold('queue');

        // Assert
        $console = $this->read('src/Console.php');
        $this->assertStringContainsString('ConsoleCommands\\Daemons()', $console);
    }

    /**
     * An application with no background work gets none of it.
     *
     * The point of asking the features rather than always writing it: a container that
     * supervises nothing is a container somebody has to explain, and the first person to read
     * this compose file should not have to work out what it is for.
     */
    public function testAnApplicationWithNoBackgroundWorkGetsNoSupervisor(): void
    {
        // Arrange & Act
        $this->scaffold('');

        // Assert
        $this->assertStringNotContainsString('daemons:start', $this->read('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->tmpDir . '/src/ConsoleCommands/Daemons.php');
    }

    /**
     * `auth` alone is enough, because the framework schedules work on its behalf.
     *
     * Expiring tokens, pruning sessions, clearing abandoned second-factor setups. None of it
     * is dispatched by the application, so a project that reads its own code sees nothing that
     * needs a worker — and runs none of those jobs, silently, for as long as it is up.
     */
    public function testAuthAloneIsEnoughToNeedASupervisor(): void
    {
        // Act & Assert
        $this->assertTrue(Init::needsDaemonSupervisor(['auth']));
        $this->assertFalse(Init::needsDaemonSupervisor(['devpanel', 'cache']));
    }
}
