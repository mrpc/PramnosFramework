<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\WorkerLock;

/**
 * Unit tests for the standalone worker lock + heartbeat primitive.
 *
 * The lock is a JSON file whose creation is the atomic step; these tests drive
 * every branch that decides "is the holder alive?" by writing lock files by hand
 * (a live pid = our own; a dead pid = an impossibly high one) and by ageing the
 * recorded heartbeat, so no second process is ever needed.
 */
class WorkerLockTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            @unlink($p);
            @unlink($p . '.stop');
        }
        $this->paths = [];
        parent::tearDown();
    }

    /** A fresh, unique lock path in the temp dir, tracked for cleanup. */
    private function tempPath(string $tag): string
    {
        $path = sys_get_temp_dir() . '/wl_' . getmypid() . '_' . $tag . '.lock';
        @unlink($path);
        $this->paths[] = $path;
        return $path;
    }

    /** Write a lock file holding an arbitrary JSON state. */
    private function writeState(string $path, array $state): void
    {
        file_put_contents($path, json_encode($state) . "\n");
    }

    /**
     * acquire() on a free path wins the lock, records the running state (pid,
     * host, status) and reports itself held.
     */
    public function testAcquireOnFreePathSucceedsAndWritesState(): void
    {
        $lock = new WorkerLock('worker', $this->tempPath('free'));

        $this->assertTrue($lock->acquire($taken));
        $this->assertNull($taken, 'a free path has no previous holder');
        $this->assertTrue($lock->isHeld());

        $state = $lock->readState();
        $this->assertSame('running', $state['status']);
        $this->assertSame(getmypid(), (int) $state['pid']);
        $this->assertArrayHasKey('heartbeat_at', $state);
    }

    /**
     * acquire() refuses a lock already held by a live, heartbeating worker.
     */
    public function testAcquireFailsWhenLiveHolderPresent(): void
    {
        $path = $this->tempPath('live');
        // Our own pid is alive; a fresh heartbeat means "making progress".
        $this->writeState($path, [
            'status' => 'running',
            'pid' => getmypid(),
            'host' => gethostname() ?: '',
            'heartbeat_at' => time(),
        ]);

        $lock = new WorkerLock('worker', $path);
        $this->assertFalse($lock->acquire($taken));
        $this->assertFalse($lock->isHeld());
    }

    /**
     * acquire() takes over a lock whose recorded process is dead, and reports
     * whom it was taken from for logging.
     */
    public function testAcquireTakesOverDeadHolder(): void
    {
        $path = $this->tempPath('dead');
        $this->writeState($path, [
            'status' => 'running',
            'pid' => 999999, // almost certainly not a live pid
            'host' => gethostname() ?: '',
            'heartbeat_at' => time(),
        ]);

        $lock = new WorkerLock('worker', $path);
        $this->assertTrue($lock->acquire($taken));
        $this->assertNotNull($taken, 'the previous (dead) holder is described');
        $this->assertStringContainsString('999999', $taken);
        $this->assertSame(getmypid(), (int) $lock->readState()['pid']);
    }

    /**
     * acquire() takes over a wedged holder: its process is alive but it has not
     * written a heartbeat within the stale window.
     */
    public function testAcquireTakesOverWedgedHolder(): void
    {
        $path = $this->tempPath('wedged');
        $this->writeState($path, [
            'status' => 'running',
            'pid' => getmypid(), // alive...
            'host' => gethostname() ?: '',
            'heartbeat_at' => time() - 9999, // ...but not heartbeating
        ]);

        $lock = new WorkerLock('worker', $path, 60);
        $this->assertTrue($lock->holderIsWedged(), 'alive but not progressing');
        $this->assertTrue($lock->acquire($taken));
    }

    /**
     * heartbeat() refreshes the recorded timestamp while the lock is held, and
     * records extra fields the caller passes.
     */
    public function testHeartbeatRefreshesStateWhenHeld(): void
    {
        $lock = new WorkerLock('worker', $this->tempPath('hb'));
        $this->assertTrue($lock->acquire());

        $this->assertTrue($lock->heartbeat(['jobs_processed' => 5, 'last_job' => 'abc']));
        $state = $lock->readState();
        $this->assertSame(5, (int) $state['jobs_processed']);
        $this->assertSame('abc', $state['last_job']);
    }

    /**
     * heartbeat() returns false when the lock is not held, so a caller that
     * never acquired never believes it is alive.
     */
    public function testHeartbeatReturnsFalseWhenNotHeld(): void
    {
        $lock = new WorkerLock('worker', $this->tempPath('nohb'));
        $this->assertFalse($lock->heartbeat());
    }

    /**
     * heartbeat() returns false — and drops its held flag — once another worker
     * has taken the lock over (the recorded pid is no longer ours).
     */
    public function testHeartbeatDetectsTakeover(): void
    {
        $path = $this->tempPath('takeover');
        $lock = new WorkerLock('worker', $path);
        $this->assertTrue($lock->acquire());

        // Someone else grabbed it: rewrite the state with a different pid.
        $this->writeState($path, [
            'status' => 'running',
            'pid' => 999999,
            'host' => gethostname() ?: '',
            'heartbeat_at' => time(),
        ]);

        $this->assertFalse($lock->heartbeat());
        $this->assertFalse($lock->isHeld());
    }

    /**
     * release() marks the lock stopped and drops the held flag, but keeps the
     * file so a later status read can still see how the run ended.
     */
    public function testReleaseMarksStoppedAndKeepsFile(): void
    {
        $path = $this->tempPath('rel');
        $lock = new WorkerLock('worker', $path);
        $this->assertTrue($lock->acquire());

        $lock->release();
        $this->assertFalse($lock->isHeld());
        $this->assertFileExists($path);
        $this->assertSame('stopped', $lock->readState()['status']);
    }

    /**
     * isHeldByAnother() is true only for a live foreign holder: false for a free
     * path, false for a lock we hold ourselves.
     */
    public function testIsHeldByAnother(): void
    {
        $path = $this->tempPath('another');
        $lock = new WorkerLock('worker', $path);
        $this->assertFalse($lock->isHeldByAnother(), 'free path');

        $this->writeState($path, [
            'status' => 'running',
            'pid' => getmypid(),
            'host' => gethostname() ?: '',
            'heartbeat_at' => time(),
        ]);
        $this->assertTrue($lock->isHeldByAnother(), 'live foreign holder');

        $held = new WorkerLock('worker', $this->tempPath('mine'));
        $held->acquire();
        $this->assertFalse($held->isHeldByAnother(), 'we hold it ourselves');
    }

    /**
     * pidFromFile() reads the JSON pid, falls back to a legacy plain-text
     * "<pid>\n..." lock, and returns 0 for missing/empty/non-numeric files.
     */
    public function testPidFromFileParsesJsonAndLegacyAndZero(): void
    {
        $json = $this->tempPath('pid_json');
        $this->writeState($json, ['pid' => 4242, 'status' => 'running']);
        $this->assertSame(4242, WorkerLock::pidFromFile($json));

        $legacy = $this->tempPath('pid_legacy');
        file_put_contents($legacy, "777\nCommand started at: ...");
        $this->assertSame(777, WorkerLock::pidFromFile($legacy));

        $none = $this->tempPath('pid_none');
        file_put_contents($none, "no digits here\njust text");
        $this->assertSame(0, WorkerLock::pidFromFile($none));

        $empty = $this->tempPath('pid_empty');
        file_put_contents($empty, '');
        $this->assertSame(0, WorkerLock::pidFromFile($empty));

        $this->assertSame(0, WorkerLock::pidFromFile(sys_get_temp_dir() . '/wl_does_not_exist'));
    }

    /**
     * readState() returns null for a path that was never written, and a decoded
     * array once a lock exists.
     */
    public function testReadStateNullThenArray(): void
    {
        $path = $this->tempPath('readstate');
        $lock = new WorkerLock('worker', $path);
        $this->assertNull($lock->readState());

        $lock->acquire();
        $this->assertIsArray($lock->readState());
    }

    /**
     * stopRequested() reflects the presence of the `<path>.stop` sentinel.
     */
    public function testStopRequestedSentinel(): void
    {
        $path = $this->tempPath('stop');
        $lock = new WorkerLock('worker', $path);
        $this->assertFalse($lock->stopRequested());

        file_put_contents($path . '.stop', '1');
        $this->assertTrue($lock->stopRequested());
    }

    /**
     * heartbeatAge() reports seconds since the recorded heartbeat, and null when
     * unknown.
     */
    public function testHeartbeatAge(): void
    {
        $path = $this->tempPath('age');
        $lock = new WorkerLock('worker', $path);
        $this->assertNull($lock->heartbeatAge(), 'no state yet');

        $this->writeState($path, ['status' => 'running', 'heartbeat_at' => time() - 30]);
        $this->assertGreaterThanOrEqual(30, $lock->heartbeatAge());
    }

    /**
     * defaultPath() builds a `<dir>/<name>.lock` path and honours an explicit dir.
     */
    public function testDefaultPath(): void
    {
        $dir = sys_get_temp_dir();
        $this->assertSame(
            rtrim($dir, '/') . '/myworker.lock',
            WorkerLock::defaultPath('myworker', $dir)
        );
    }
}
