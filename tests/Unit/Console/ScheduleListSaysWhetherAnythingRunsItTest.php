<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ScheduleList;
use Pramnos\Console\WorkerLock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `schedule:list` printed six due jobs on an installation where none could run.
 *
 * **Listing reads definitions; running needs a process, and nothing checked for one.** The
 * application had forked `DaemonOrchestrator` without extending the framework's, so
 * `includeScheduler()` and `schedulerProcess()` never ran — no `work` process, no
 * `schedule:run` in the crontab. Six registered framework jobs had **never executed**:
 * `spool:drain` every minute, `timescale:drain` hourly, `auth:token-cleanup`,
 * `auth:twofactor-cleanup`, `mail:prune` and `auth:webhook-deliver` every five minutes.
 *
 * It took reading `ps` to find. One line here finds it in seconds, which is the argument
 * for putting it in this command: it is the one somebody runs when they wonder about the
 * schedule.
 *
 * A fork of the orchestrator is a silent way to lose the whole schedule. Nothing warned.
 */
#[CoversClass(ScheduleList::class)]
class ScheduleListSaysWhetherAnythingRunsItTest extends TestCase
{
    private string $lockPath = '';

    protected function setUp(): void
    {
        $this->lockPath = WorkerLock::defaultPath('pramnos-work.lock');

        // A lock left by another test in this process would answer for this one.
        @unlink($this->lockPath);
    }

    protected function tearDown(): void
    {
        if ($this->lockPath !== '') {
            @unlink($this->lockPath);
        }

        parent::tearDown();
    }

    /**
     * Run the command against a schedule file with tasks in it.
     */
    private function invoke(): string
    {
        $command = new ScheduleList();
        $command->scheduleFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app'
            . \DS . 'schedule.php';

        $output = new BufferedOutput();
        $command->run(new ArrayInput(array()), $output);

        return $output->fetch();
    }

    /**
     * With no `work` process, the listing says the tasks are registered and not executing.
     *
     * The reported state, and the wording matters: it says what is **known**. A
     * `schedule:run` crontab line cannot be detected from here, so asserting that nothing
     * runs would be a claim the command cannot make.
     */
    public function testWithNoWorkerItSaysTheTasksAreNotExecuting(): void
    {
        // Act
        $text = $this->invoke();

        // Assert
        $this->assertStringContainsString('No `work` process is running', $text);
        $this->assertStringContainsString('registered, not executing', $text);
        $this->assertStringContainsString('cannot be detected from here', $text);
    }

    /**
     * And it says how to fix it, including the fork that caused this.
     *
     * A warning that names no next step is a warning somebody reads once. The orchestrator
     * sentence is there because the installation's own fork is what lost the schedule, and
     * the next person to fork it will read this line rather than the filing.
     */
    public function testItNamesTheWayOut(): void
    {
        // Act
        $text = $this->invoke();

        // Assert
        $this->assertStringContainsString('php pramnos work', $text);
        $this->assertStringContainsString('DaemonOrchestrator', $text);
    }

    /**
     * With a held lock it says the schedule is being executed.
     *
     * The control. A command that reported trouble whatever the state would be ignored
     * within a week — and this one runs on every installation that has ever wondered what
     * its schedule contains.
     */
    public function testWithAHeldLockItSaysTheScheduleIsRunning(): void
    {
        // Arrange — a lock held by this process, which is what a running `work` looks like
        $lock = new WorkerLock('pramnos-work.lock');
        $this->assertTrue($lock->acquire(), 'the arrangement could not take the lock');

        try {
            // Act
            $text = $this->invoke();

            // Assert
            $this->assertStringContainsString('this schedule is being executed', $text);
            $this->assertStringNotContainsString('No `work` process', $text);
        } finally {
            $lock->release();
        }
    }

    /**
     * The caveat counts the tasks it just listed.
     *
     * A number rather than "some tasks", because the number is the shock: the installation
     * that prompted this had **six** framework jobs that had never run, and being told "6
     * task(s) are registered, not executing" is what turns a line into an action.
     *
     * A first version of this test tried to assert the empty-schedule case instead, with a
     * fixture file containing nothing. That case is unreachable: `Scheduler` registers the
     * framework's own tasks whatever an application declares, so there is no installation
     * where this command prints "No scheduled tasks registered" — and a test asserting it
     * was asserting fiction.
     */
    public function testTheCaveatCountsTheTasksItListed(): void
    {
        // Act
        $text = $this->invoke();

        // Assert — however many the framework and the fixture register between them
        $this->assertMatchesRegularExpression(
            '/These \d+ task\(s\) are registered, not executing/',
            $text
        );
    }
}
