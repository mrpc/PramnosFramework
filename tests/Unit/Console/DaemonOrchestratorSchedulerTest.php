<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * An orchestrator supervises the framework's schedule, without being asked.
 *
 * The framework declares periodic work of its own — `spool:drain` every minute,
 * `timescale:drain` hourly, `queue:cleanup` daily — and a schedule only happens when
 * something runs it. The traditional something is a crontab line; a container does not
 * have one, which is why `work` exists.
 *
 * But `DaemonOrchestrator` supervised only what the application listed, so a stack with
 * an orchestrator, no cron, and an application that never thought about the framework's
 * schedule ran none of it. Measured on one: three supervised application daemons, twenty
 * requests sitting unwritten in `var/spool/`, and a Performance panel that had never had
 * a row in it — a symptom several layers from its cause, with nothing connecting them.
 *
 * The application answers for its own daemons. The framework's are the framework's
 * business, and this is where that is decided.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorSchedulerTest extends TestCase
{
    /**
     * Symfony's completion command reads PHP_SELF while commands are configured.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    /**
     * Build an orchestrator whose application declares the given processes.
     *
     * @param array<int, array<string, mixed>> $processes What the application supervises
     * @param bool                             $wantScheduler Whether it opts in (the default)
     * @return DaemonOrchestrator
     */
    private function orchestrator(array $processes, bool $wantScheduler = true): DaemonOrchestrator
    {
        return new class($processes, $wantScheduler) extends DaemonOrchestrator {
            /**
             * @param array<int, array<string, mixed>> $processes
             */
            public function __construct(
                private array $processes,
                private bool $wantScheduler
            ) {
                parent::__construct();
            }

            protected function buildDesiredProcesses(): array
            {
                return $this->processes;
            }

            protected function includeScheduler(): bool
            {
                return $this->wantScheduler;
            }

            protected function getDashboardTitle(): string
            {
                return ' TEST ';
            }

            protected function getEntryPoint(): string
            {
                return '/app/console.php';
            }

            protected function getJobName(): string
            {
                return 'test-orchestrator.lock';
            }

            /** Exposed: everything inside the class reads this, not the abstract method. */
            public function desired(): array
            {
                return $this->collectDesiredProcesses();
            }
        };
    }

    /**
     * An application that lists only its own daemons still gets the schedule.
     *
     * The regression test for the whole report: this is the shape every scaffolded
     * project has, and it ran no scheduled work at all.
     *
     * @return void
     */
    public function testTheSchedulerIsSupervisedWithoutTheApplicationAskingForIt(): void
    {
        // Arrange — three application daemons, as a real project declares them
        $orchestrator = $this->orchestrator([
            ['id' => 'realtime',    'daemon' => 'realtime',    'workerId' => 'realtime-1',    'lockFile' => '/tmp/a.lock', 'tokens' => ['realtime:serve']],
            ['id' => 'stats',       'daemon' => 'stats',       'workerId' => 'stats-1',       'lockFile' => '/tmp/b.lock', 'tokens' => ['stats:start']],
            ['id' => 'maintenance', 'daemon' => 'maintenance', 'workerId' => 'maintenance-1', 'lockFile' => '/tmp/c.lock', 'tokens' => ['maintenance:start']],
        ]);

        // Act
        $desired = $orchestrator->desired();

        // Assert — the application's three, plus one more
        $this->assertCount(4, $desired);

        $scheduler = end($desired);
        $this->assertSame('schedule', $scheduler['id']);
        // `work` is the long-running form of `schedule:run` — the command that
        // actually drains the spool and runs the cleanups.
        $this->assertSame(['work'], $scheduler['tokens']);
        // And it takes the lock every managed daemon is health-checked through,
        // so it is supervised rather than merely started.
        $this->assertStringEndsWith('/var/pramnos-work.lock', $scheduler['lockFile']);
        $this->assertArrayHasKey('workerId', $scheduler);
    }

    /**
     * An application that already runs `work` is not given a second one.
     *
     * Two schedulers would not corrupt anything — the tasks take their own overlap
     * locks and `work` holds a single-instance lock — but the second could never
     * acquire that lock, so it would sit in the dashboard failing to start for ever.
     * Recognised by what the entry runs, not by the id, because a project that added
     * one before this existed chose its own name for it.
     *
     * @return void
     */
    public function testAnApplicationThatAlreadyRunsTheSchedulerKeepsItsOwn(): void
    {
        // Arrange — the same worker, under the application's own id
        $orchestrator = $this->orchestrator([
            ['id' => 'cron', 'daemon' => 'cron', 'workerId' => 'cron-1', 'lockFile' => '/tmp/own.lock', 'tokens' => ['work', '--interval=30']],
        ]);

        // Act
        $desired = $orchestrator->desired();

        // Assert — one entry, and it is the application's
        $this->assertCount(1, $desired);
        $this->assertSame('cron', $desired[0]['id']);
        $this->assertSame('/tmp/own.lock', $desired[0]['lockFile']);
    }

    /**
     * A scheduler declared as a raw shell command counts too.
     *
     * `shellCommand` bypasses the token list entirely, and an application using it is
     * exactly the one whose entry a token scan would miss.
     *
     * @return void
     */
    public function testAShellCommandSchedulerIsRecognised(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([
            ['id' => 'sched', 'daemon' => 'sched', 'workerId' => 's-1', 'lockFile' => '/tmp/s.lock', 'shellCommand' => 'php /app/console.php work --interval=15'],
        ]);

        // Act + Assert
        $this->assertCount(1, $orchestrator->desired());
    }

    /**
     * A command that merely contains the letters "work" is not the scheduler.
     *
     * `workflow:run` and `network:sync` are ordinary daemon names, and a match on the
     * substring would silently switch the schedule off for the projects running them —
     * the failure this whole change exists to prevent, reintroduced by the fix.
     *
     * @return void
     */
    public function testADaemonWhoseNameContainsWorkIsNotMistakenForIt(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([
            ['id' => 'wf', 'daemon' => 'wf', 'workerId' => 'wf-1', 'lockFile' => '/tmp/w.lock', 'tokens' => ['workflow:run']],
            ['id' => 'net', 'daemon' => 'net', 'workerId' => 'net-1', 'lockFile' => '/tmp/n.lock', 'shellCommand' => 'php /app/console.php network:sync'],
        ]);

        // Act
        $desired = $orchestrator->desired();

        // Assert — neither matched, so the scheduler was still added
        $this->assertCount(3, $desired);
        $this->assertSame('schedule', end($desired)['id']);
    }

    /**
     * An installation with a crontab can turn it off.
     *
     * Running both is safe and pointless; the override exists so that "safe and
     * pointless" is a choice rather than something to work around.
     *
     * @return void
     */
    public function testAnApplicationCanOptOut(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator(
            [['id' => 'queue', 'daemon' => 'queue', 'workerId' => 'q-1', 'lockFile' => '/tmp/q.lock', 'tokens' => ['queue:process']]],
            false
        );

        // Act
        $desired = $orchestrator->desired();

        // Assert
        $this->assertCount(1, $desired);
        $this->assertSame('queue', $desired[0]['id']);
    }

    /**
     * An application that declares nothing still gets a scheduler.
     *
     * The empty case is not hypothetical: an orchestrator whose daemons are all
     * feature-gated off still has the framework's own periodic work to do.
     *
     * @return void
     */
    public function testAnEmptyApplicationListStillSchedules(): void
    {
        // Act
        $desired = $this->orchestrator([])->desired();

        // Assert
        $this->assertCount(1, $desired);
        $this->assertSame('schedule', $desired[0]['id']);
    }

    /**
     * A console without `work` is left alone.
     *
     * The framework's console registers it, so this is an application that built its
     * console by hand and left it out. Supervising a command that does not exist means
     * `[failed-start] schedule` on every reconcile cycle, for ever — a permanent error
     * in somebody's dashboard, put there by an upgrade they did not ask for. Doing
     * nothing is the right answer.
     *
     * @return void
     */
    public function testAConsoleWithoutTheWorkCommandGetsNoScheduler(): void
    {
        // Arrange — a console application that knows one command, and it is not `work`
        $console = new \Symfony\Component\Console\Application('test');
        $orchestrator = $this->orchestrator([
            ['id' => 'queue', 'daemon' => 'queue', 'workerId' => 'q-1', 'lockFile' => '/tmp/q.lock', 'tokens' => ['queue:process']],
        ]);
        $console->add($orchestrator);

        // Act
        $desired = $orchestrator->desired();

        // Assert
        $this->assertCount(1, $desired);
        $this->assertSame('queue', $desired[0]['id']);
    }

    /**
     * A console that has `work` gets the scheduler.
     *
     * The other half: a guard that always said no would pass the test above while
     * switching the feature off entirely.
     *
     * @return void
     */
    public function testAConsoleWithTheWorkCommandGetsTheScheduler(): void
    {
        // Arrange
        $console      = new \Symfony\Component\Console\Application('test');
        $orchestrator = $this->orchestrator([]);
        $console->add($orchestrator);
        $console->add(new \Pramnos\Console\Commands\Work());

        // Act
        $desired = $orchestrator->desired();

        // Assert
        $this->assertCount(1, $desired);
        $this->assertSame('schedule', $desired[0]['id']);
    }
}
