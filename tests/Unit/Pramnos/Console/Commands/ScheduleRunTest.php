<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ScheduleRun;
use Pramnos\Logs\Logger;
use Pramnos\Scheduling\Scheduler;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for `schedule:run`, including the schedule-log channel.
 *
 * Cron redirects schedule:run output to /dev/null, so the durable record of what
 * ran is the `schedule` log channel (schedule.log, visible in the log viewer).
 * These tests drive the real command against in-memory-registered tasks and
 * assert both the console output and the log entries for every outcome:
 * ran / failed / skipped-overlap, plus the no-tasks and --pretend branches.
 */
#[CoversClass(ScheduleRun::class)]
class ScheduleRunTest extends TestCase
{
    private string $logFile;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        Scheduler::reset();
        $this->logFile = Logger::getLogPath('schedule', 'log');
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
        @unlink($this->logFile);
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        }
    }

    /** A CommandTester for schedule:run whose schedule file is intentionally absent. */
    private function tester(): CommandTester
    {
        $cmd = new ScheduleRun();
        // Point at a nonexistent file so loadDefinitions() is a no-op and only the
        // tasks registered directly in the test are considered.
        $cmd->scheduleFile = sys_get_temp_dir() . '/pramnos_no_schedule_' . bin2hex(random_bytes(3)) . '.php';
        $app = new Application('t', '1');
        $app->add($cmd);
        $app->setAutoExit(false);
        return new CommandTester($app->find('schedule:run'));
    }

    private function logContents(): string
    {
        return file_exists($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * With no due tasks the command reports so, succeeds, and writes no log.
     */
    public function testNoTasksDue(): void
    {
        // Act
        $tester = $this->tester();
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No tasks due', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->logFile);
    }

    /**
     * --pretend lists the due task without executing it or logging.
     */
    public function testPretendDoesNotRunOrLog(): void
    {
        // Arrange — a due task that flips a flag if (wrongly) executed.
        $ran = false;
        Scheduler::call(function () use (&$ran) { $ran = true; })->everyMinute();

        // Act
        $tester = $this->tester();
        $tester->execute(['--pretend' => true]);

        // Assert
        $this->assertStringContainsString('Would run', $tester->getDisplay());
        $this->assertFalse($ran, 'pretend must not execute the task');
        $this->assertFileDoesNotExist($this->logFile);
    }

    /**
     * A due task runs, prints Done, and is logged to the schedule channel.
     */
    public function testRunsDueTaskAndLogs(): void
    {
        // Arrange
        $ran = false;
        Scheduler::call(function () use (&$ran) { $ran = true; })->everyMinute()->description('nightly-report');

        // Act
        $tester = $this->tester();
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($ran, 'the due task must be executed');
        $this->assertStringContainsString('Done', $tester->getDisplay());
        $this->assertStringContainsString('ran: nightly-report', $this->logContents());
    }

    /**
     * A throwing task is reported as failed, exits FAILURE, and is logged.
     */
    public function testFailedTaskLogsError(): void
    {
        // Arrange
        Scheduler::call(function () { throw new \RuntimeException('kaboom'); })
            ->everyMinute()->description('broken-task');

        // Act
        $tester = $this->tester();
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('Failed: kaboom', $tester->getDisplay());
        $this->assertStringContainsString('failed: broken-task', $this->logContents());
        $this->assertStringContainsString('kaboom', $this->logContents());
    }

    /**
     * A withoutOverlapping task whose lock is already held is skipped (not run)
     * and the skip is logged. The lock is pre-created (with this test process's
     * live PID) at the task's real lock path via reflection.
     */
    public function testSkippedOverlappingTaskLogs(): void
    {
        // Arrange — a due, no-overlap task; pre-hold its lock.
        $ran = false;
        $lockDir = sys_get_temp_dir();
        $task = Scheduler::call(function () use (&$ran) { $ran = true; })
            ->everyMinute()->withoutOverlapping($lockDir)->description('long-job');

        // (private methods are invokable via reflection without setAccessible() on PHP 8.1+)
        $lockFile = (new \ReflectionMethod($task, 'lockFile'))->invoke($task);
        file_put_contents($lockFile, (string) getmypid()); // held by the live test process

        try {
            // Act
            $tester = $this->tester();
            $code = $tester->execute([]);

            // Assert — task skipped, not executed, and logged as skipped.
            $this->assertSame(Command::SUCCESS, $code);
            $this->assertFalse($ran, 'an overlapping task must not run');
            $this->assertStringContainsString('Skipped', $tester->getDisplay());
            $this->assertStringContainsString('skipped: long-job', $this->logContents());
        } finally {
            @unlink($lockFile);
        }
    }
}
