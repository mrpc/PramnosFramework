<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;

/**
 * `runInBackground()`: a command the scheduler starts and does not wait for.
 *
 * A four-minute command due every minute of an hour held `work` for the hour — the tasks behind
 * it, due at :15 and :20, never ran. In the background the pass returns at once; the line it
 * starts carries what the detached process needs to keep the overlap lock and finish the run.
 */
#[CoversClass(ScheduledTask::class)]
final class RunInBackgroundTest extends TestCase
{
    /** A command task that keeps the line it would start. */
    private function task(string $lockDir = ''): ScheduledTask
    {
        $task = new class ('mail:bounces --since=1h', 'command') extends ScheduledTask {
            /** @var list<string> */
            public array $spawned = [];

            /** Keep the line instead of starting it. */
            protected function spawnDetached(string $line): void
            {
                $this->spawned[] = $line;
            }

            /** A fixed console, so the line can be read. */
            protected function consoleBinary(): string
            {
                return 'php app.php';
            }
        };
        if ($lockDir !== '') {
            $task->withoutOverlapping($lockDir, 600);
        }

        return $task->runInBackground();
    }

    /**
     * The scheduler returns at once, having started `schedule:background` with the command —
     * quoted as one argument — and, with `withoutOverlapping()`, the lock's directory and
     * staleness for the detached process to take it.
     */
    public function testItReturnsAtOnceHavingStartedTheCommand(): void
    {
        // Arrange
        $dir  = sys_get_temp_dir();
        $task = $this->task($dir);

        // Act
        $started = hrtime(true);
        $ran     = $task->run();
        $elapsed = (hrtime(true) - $started) / 1e9;

        // Assert
        $this->assertTrue($ran);
        $this->assertLessThan(1.0, $elapsed, 'the scheduler waited for the command');
        $this->assertCount(1, $task->spawned);
        $this->assertStringStartsWith("php app.php schedule:background 'mail:bounces --since=1h'", $task->spawned[0]);
        $this->assertStringContainsString(' --lock-dir=' . escapeshellarg($dir) . ' --stale=600', $task->spawned[0]);
        $this->assertTrue($task->getSummary()['background']);
    }

    /**
     * Without `withoutOverlapping()` there is no lock to hand over: runs may overlap, as a
     * crontab line's would.
     */
    public function testWithoutOverlappingOffNoLockIsHandedOver(): void
    {
        // Arrange
        $task = $this->task();

        // Act
        $task->run();

        // Assert
        $this->assertStringNotContainsString('--lock-dir', $task->spawned[0] ?? '');
    }

    /**
     * Only a command can run in the background: a callable belongs to the process that
     * defined it, and is refused when it is configured, not at its first run.
     */
    public function testACallableIsRefused(): void
    {
        // Arrange
        $task = new ScheduledTask(static function (): void {
        }, 'callable');

        // Assert
        $this->expectException(\LogicException::class);

        // Act
        $task->runInBackground();
    }
}
