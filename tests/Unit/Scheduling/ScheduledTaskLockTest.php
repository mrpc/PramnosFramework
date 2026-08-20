<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;

/**
 * A pid is a fact about the process table of whoever is asking.
 *
 * `withoutOverlapping()` wrote its own pid to a file and, on the next run, asked
 * `posix_kill($pid, 0)` whether that number was alive — locally. Two containers sharing a
 * volume is the ordinary shape here (an application container and a daemon container, one
 * `var/` between them), and in that shape the question is meaningless: whichever container
 * asks sees *its own* process table, where some unrelated process very likely holds the
 * number the other one wrote.
 *
 * The consequence is the worst kind: the task is skipped, silently, for as long as that
 * number stays in use — which for a low pid on a busy container is for ever. Nothing is
 * logged, because "still running" is a normal answer.
 *
 * The lock is now a {@see \Pramnos\Console\WorkerLock}, which records the host beside the
 * pid and trusts the pid only when the host matches, falling back to heartbeat age when it
 * does not. These tests assert the distinction the old code could not make: **the same live
 * local pid, believed when it is ours and not when it is somebody else's.**
 */
#[CoversClass(ScheduledTask::class)]
class ScheduledTaskLockTest extends TestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/pramnos_lock_test_' . bin2hex(random_bytes(4));
        mkdir($this->lockDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir);
    }

    /**
     * A task that counts its runs, with overlap protection in a temp directory.
     *
     * @param int $counter Incremented on each run
     * @return array{0: ScheduledTask, 1: string} The task and its lock file path
     */
    private function task(int &$counter): array
    {
        $task = new ScheduledTask(function () use (&$counter) { $counter++; }, 'callable');
        $task->withoutOverlapping($this->lockDir);

        return [$task, (new \ReflectionMethod($task, 'lockFile'))->invoke($task)];
    }

    /**
     * Write a lock as another host would have left it.
     *
     * The pid is **this** process's, alive and verifiable — which is the whole point. Under
     * the old check that made the lock permanent.
     *
     * @param string $file        Lock path
     * @param int    $heartbeatAge How many seconds ago the holder last reported
     * @return void
     */
    private function writeForeignLock(string $file, int $heartbeatAge): void
    {
        file_put_contents($file, json_encode([
            'name'         => 'schedule:test',
            'pid'          => getmypid(),
            'host'         => 'a-different-container',
            'started_at'   => time() - $heartbeatAge,
            'heartbeat_at' => time() - $heartbeatAge,
            'status'       => 'running',
        ]));
    }

    /**
     * A lock held on this host by a live process is honoured.
     *
     * The invariant overlap protection exists for, and the half that already worked.
     *
     * @return void
     */
    public function testALiveLocalHolderStillBlocksTheTask(): void
    {
        // Arrange
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);
        file_put_contents($lockFile, json_encode([
            'name'         => 'schedule:test',
            'pid'          => getmypid(),
            'host'         => gethostname() ?: '',
            'started_at'   => time(),
            'heartbeat_at' => time(),
            'status'       => 'running',
        ]));

        // Act
        $result = $task->run();

        // Assert
        $this->assertFalse($result, 'run() reports that it did not run');
        $this->assertSame(0, $ran);
    }

    /**
     * A holder on another host is believed only while it is still reporting.
     *
     * A fresh heartbeat is evidence of a running task wherever it runs, so the lock holds.
     *
     * @return void
     */
    public function testAFreshHolderOnAnotherHostBlocksTheTask(): void
    {
        // Arrange
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);
        $this->writeForeignLock($lockFile, 5);

        // Act + Assert
        $this->assertFalse($task->run());
        $this->assertSame(0, $ran);
    }

    /**
     * A holder on another host that stopped reporting is not kept alive by a local pid.
     *
     * **The regression test.** The pid in this lock is live and checkable — it is this very
     * process — so the old `posix_kill($pid, 0)` answered "still running" and skipped the
     * task, every minute, indefinitely. The host does not match, so the pid proves nothing,
     * and the only evidence left is a heartbeat that stopped long ago.
     *
     * @return void
     */
    public function testAStaleHolderOnAnotherHostDoesNotBlockTheTaskForEver(): void
    {
        // Arrange — same live pid, another host, no heartbeat for hours
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);
        $this->writeForeignLock($lockFile, 9999);

        // Act
        $result = $task->run();

        // Assert
        $this->assertTrue($result);
        $this->assertSame(1, $ran, 'a pid on another host is not evidence of anything here');
    }

    /**
     * How long "stopped reporting" means is configurable.
     *
     * A task that legitimately runs for an hour needs a threshold above an hour, or a second
     * scheduler would start it beside the first.
     *
     * @return void
     */
    public function testTheStaleThresholdCanBeRaised(): void
    {
        // Arrange — a holder quiet for ten minutes, and a task willing to wait an hour
        $ran  = 0;
        $task = new ScheduledTask(function () use (&$ran) { $ran++; }, 'callable');
        $task->withoutOverlapping($this->lockDir, 3600);
        $lockFile = (new \ReflectionMethod($task, 'lockFile'))->invoke($task);
        $this->writeForeignLock($lockFile, 600);

        // Act + Assert — still considered held
        $this->assertFalse($task->run());
        $this->assertSame(0, $ran);
    }

    /**
     * A lock in the pre-upgrade format is honoured while fresh.
     *
     * The migration case: a bare pid is not JSON, and `WorkerLock` reports it as an unknown
     * holder with the file's own age. An upgrade must not run a task that is running.
     *
     * @return void
     */
    public function testALegacyPidLockIsHonouredWhileFresh(): void
    {
        // Arrange — the old format, written a moment ago
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);
        file_put_contents($lockFile, (string) getmypid());

        // Act + Assert
        $this->assertFalse($task->run());
        $this->assertSame(0, $ran);
    }

    /**
     * ...and released once it is older than the threshold.
     *
     * The other half of the migration: a lock whose writer is gone must not outlive it, or
     * the upgrade freezes the schedule instead of fixing it.
     *
     * @return void
     */
    public function testALegacyPidLockIsTakenOverOnceStale(): void
    {
        // Arrange — the old format, aged past the default threshold
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);
        file_put_contents($lockFile, (string) getmypid());
        touch($lockFile, time() - 9999);

        // Act + Assert
        $this->assertTrue($task->run());
        $this->assertSame(1, $ran);
    }

    /**
     * A completed run leaves nothing behind.
     *
     * @return void
     */
    public function testTheLockIsGoneAfterTheTaskFinishes(): void
    {
        // Arrange
        $ran = 0;
        [$task, $lockFile] = $this->task($ran);

        // Act
        $task->run();

        // Assert
        $this->assertFileDoesNotExist($lockFile);
    }
}
