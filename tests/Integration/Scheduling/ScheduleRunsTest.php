<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Scheduling\RunLog;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\Scheduling\Scheduler;

/**
 * The scheduler's history, written and read back — on MySQL and on PostgreSQL with TimescaleDB.
 *
 * What holds: every run is a row with its duration, result and output, whoever started it; a
 * failure keeps the reason and still reaches the caller; a run the overlap lock refused is
 * `skipped`; a run an operator asked for is taken by one pass and becomes that run; a request for
 * a task the schedule no longer has fails with the reason; a run a killed worker left `running`
 * is marked `interrupted` by the next; and old runs go while waiting requests stay.
 */
#[CoversClass(RunLog::class)]
#[CoversClass(ScheduledTask::class)]
final class ScheduleRunsTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** Put the framework's connection back and drop the table. */
    protected function tearDown(): void
    {
        Scheduler::reset();
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists('pramnos.scheduleruns');
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        RunLog::reset();
    }

    /** A connection made the framework's, with a fresh scheduleruns from the migration. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        $db->schema()->dropTableIfExists('pramnos.scheduleruns');
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        (new \Pramnos\Framework\Migrations\Core\CreateSchedulerunsTable($app))->up();
        RunLog::reset();
    }

    /** @return list<array<string, mixed>> Every row, oldest first */
    private function rows(): array
    {
        return RunLog::table()->orderBy('id')->getAll();
    }

    /**
     * A run that succeeds is one `ok` row: its key and label, a duration, and the host.
     */
    #[DataProvider('databases')]
    public function testARunIsRecorded(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = Scheduler::call(static function (): void {
            usleep(20000);
        })->everyMinute()->description('payments');

        // Act
        $ran  = $task->run();
        $rows = $this->rows();

        // Assert
        $this->assertTrue($ran);
        $this->assertTrue($this->db->schema()->hasTable('pramnos.scheduleruns'), 'the migration made no table');
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['result']);
        $this->assertSame($task->key(), $rows[0]['task']);
        $this->assertSame('payments', $rows[0]['label']);
        $this->assertGreaterThanOrEqual(20, (int) $rows[0]['duration_ms'], 'the duration is not the run\'s');
        $this->assertSame((string) gethostname(), $rows[0]['host']);
        $this->assertSame('ok', RunLog::latestByTask()[$task->key()]['result'] ?? null);
    }

    /**
     * A run that throws is `failed` with the reason as its output — and the exception still
     * reaches the caller, which counts it.
     */
    #[DataProvider('databases')]
    public function testAFailureKeepsItsReason(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = Scheduler::call(static function (): void {
            throw new \RuntimeException('gateway refused');
        })->everyMinute();

        // Act
        $caught = null;
        try {
            $task->run();
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }
        $row = $this->rows()[0] ?? [];

        // Assert
        $this->assertSame('gateway refused', $caught, 'the failure was swallowed');
        $this->assertSame('failed', $row['result'] ?? null);
        $this->assertSame('gateway refused', $row['output'] ?? null);
        $this->assertSame(1, (int) ($row['exit_code'] ?? 0));
    }

    /**
     * A run the overlap lock refused is a `skipped` row, so the screen shows why nothing ran.
     */
    #[DataProvider('databases')]
    public function testARunTheLockRefusedIsSkipped(string $type, string $host, int $port): void
    {
        // Arrange — a live holder of the task's lock: this process
        $this->connect($type, $host, $port);
        $dir  = sys_get_temp_dir() . '/sched-runs-' . bin2hex(random_bytes(3));
        mkdir($dir);
        $task = Scheduler::call(static function (): void {
        })->everyMinute()->withoutOverlapping($dir);
        $lock = new \Pramnos\Console\WorkerLock('held', (new \ReflectionMethod($task, 'lockFile'))->invoke($task));
        $this->assertTrue($lock->acquire());

        try {
            // Act
            $ran = $task->run();

            // Assert
            $this->assertFalse($ran);
            $this->assertSame('skipped', $this->rows()[0]['result'] ?? null);
            $this->assertTrue($task->isRunning(), 'a held lock is a task running now');
        } finally {
            $lock->release();
            @unlink($lock->getPath());
            @rmdir($dir);
        }
    }

    /**
     * A run asked for from the screen is taken by one pass and becomes that run; a second pass
     * finds nothing to take, and a request for a task the schedule no longer has fails with
     * the reason.
     */
    #[DataProvider('databases')]
    public function testARequestedRunIsTakenOnce(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $ran  = 0;
        $task = Scheduler::call(static function () use (&$ran): void {
            $ran++;
        })->cron('20 * * * *')->description('payments');
        $gone = Scheduler::call(static function (): void {
        })->daily()->description('removed since');
        $this->assertTrue(RunLog::request($task, 'admin'));
        $this->assertTrue(RunLog::request($gone, 'admin'));
        $pending = RunLog::pendingRequests();

        // Act — the schedule now holds only `payments`; two passes
        $failedFirst  = RunLog::runRequested([$task]);
        $failedSecond = RunLog::runRequested([$task]);
        $rows = $this->rows();

        // Assert
        $this->assertArrayHasKey($task->key(), $pending, 'the request was not waiting');
        $this->assertSame(1, $ran, 'the request ran twice, or not at all');
        $this->assertSame(1, $failedFirst, 'the request for a removed task did not fail');
        $this->assertSame(0, $failedSecond);
        $this->assertCount(2, $rows, 'the request became a second row instead of the run');
        $this->assertSame(['ok', 'admin'], [$rows[0]['result'], $rows[0]['requested_by']]);
        $this->assertSame('failed', $rows[1]['result']);
        $this->assertSame('This task is no longer in the schedule.', $rows[1]['output']);
        $this->assertSame([], RunLog::pendingRequests());
    }

    /**
     * A run left `running` by a killed worker is `interrupted` once the task starts again on that
     * host, instead of showing as running for ever.
     */
    #[DataProvider('databases')]
    public function testARunLeftRunningIsInterruptedByTheNext(string $type, string $host, int $port): void
    {
        // Arrange — the row a SIGKILLed worker leaves behind; the overlap lock is what proves
        // no run of it is still alive here
        $this->connect($type, $host, $port);
        $dir  = sys_get_temp_dir() . '/sched-int-' . bin2hex(random_bytes(3));
        mkdir($dir);
        $task = Scheduler::call(static function (): void {
        })->everyMinute()->withoutOverlapping($dir);
        RunLog::table()->insert(['task' => $task->key(), 'label' => 'x', 'started_at' => time() - 600,
            'result' => 'running', 'host' => (string) gethostname()]);

        // Act
        $task->run();
        $rows = $this->rows();

        // Assert
        $this->assertSame(['interrupted', 'ok'], array_column($rows, 'result'));
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    /**
     * A background run: the scheduler records it `running` and returns at once; the detached
     * `schedule:background` takes the lock, runs the command, and finishes the same row with
     * its duration, exit status and output — then lets the lock go. A start while a run holds
     * the lock is `skipped` and starts nothing.
     */
    #[DataProvider('databases')]
    public function testABackgroundRunIsFinishedByItsOwnProcess(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $dir  = sys_get_temp_dir() . '/sched-bg-' . bin2hex(random_bytes(3));
        @mkdir($dir);
        $task = new class ('hello', 'command') extends ScheduledTask {
            /** @var list<string> What would have been started */
            public array $spawned = [];

            /** Keep the line instead of starting it. */
            protected function spawnDetached(string $line): void
            {
                $this->spawned[] = $line;
            }
        };
        $task->everyMinute()->withoutOverlapping($dir)->runInBackground();
        $child = new class () extends \Pramnos\Console\Commands\ScheduleBackground {
            /** The command through `echo`, so the run is real and quick. */
            protected function task(string $command): ScheduledTask
            {
                return new class ($command, 'command') extends ScheduledTask {
                    /** `echo` stands in for the application's console. */
                    protected function consoleBinary(): string
                    {
                        return 'echo';
                    }
                };
            }
        };

        // Act — the scheduler's half, then the detached process's, then a start during a run
        $returned = $task->run();
        $during   = $this->rows()[0]['result'] ?? null;
        preg_match('/--run=(\d+)/', $task->spawned[0] ?? '', $m);
        $status = (new \Symfony\Component\Console\Tester\CommandTester($child))
            ->execute(['task' => 'hello', '--run' => $m[1] ?? '0', '--lock-dir' => $dir]);
        $row  = $this->rows()[0] ?? [];
        $held = new \Pramnos\Console\WorkerLock('held', (new \ReflectionMethod($task, 'lockFile'))->invoke($task));
        $held->acquire();
        $second = $task->run();
        $held->release();
        @unlink($held->getPath());
        @rmdir($dir);

        // Assert
        $this->assertTrue($returned);
        $this->assertSame('running', $during, 'the run was not recorded as started');
        $this->assertStringContainsString("schedule:background 'hello'", $task->spawned[0] ?? '');
        $this->assertSame(0, $status);
        $this->assertSame('ok', $row['result'] ?? null, 'the detached process did not finish the row it was given');
        $this->assertSame('hello', $row['output'] ?? null);
        $this->assertSame(0, (int) ($row['exit_code'] ?? -1));
        $this->assertNotNull($row['duration_ms'] ?? null);
        $this->assertCount(2, $this->rows(), 'the detached process wrote a row of its own');
        $this->assertFalse($second, 'a start while a run held the lock was not skipped');
        $this->assertCount(1, $task->spawned, 'a second process was started beside the first');
        $this->assertSame('skipped', $this->rows()[1]['result'] ?? null);
    }

    /**
     * Old runs go; a waiting request stays however old it is, and so does a recent run. The
     * history begins at the oldest row left.
     */
    #[DataProvider('databases')]
    public function testOldRunsArePurgedAndRequestsStay(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $old = time() - 30 * 86400;
        foreach ([['ok', $old], ['requested', $old], ['failed', time() - 60]] as [$result, $at]) {
            RunLog::table()->insert(['task' => 't', 'label' => 't', 'started_at' => $at, 'result' => $result, 'host' => 'h']);
        }

        // Act
        $removed = RunLog::purge(14);

        // Assert
        $this->assertSame(1, $removed);
        $this->assertSame(['requested', 'failed'], array_column($this->rows(), 'result'));
        $this->assertSame($old, RunLog::since());
    }

    /**
     * Without the table nothing is written and the task runs as it would have.
     */
    #[DataProvider('databases')]
    public function testWithoutTheTableTheTaskStillRuns(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->db->schema()->dropTableIfExists('pramnos.scheduleruns');
        RunLog::reset();
        $ran  = false;
        $task = Scheduler::call(static function () use (&$ran): void {
            $ran = true;
        })->everyMinute();

        // Act
        $task->run();

        // Assert
        $this->assertTrue($ran);
        $this->assertFalse(RunLog::request($task, 'admin'));
        $this->assertSame([], RunLog::latestByTask());
        $this->assertNull(RunLog::purge());
    }
}
