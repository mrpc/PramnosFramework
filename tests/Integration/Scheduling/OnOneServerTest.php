<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\SharedLock;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;
use Pramnos\Scheduling\ScheduledTask;

/**
 * `onOneServer()` — the task runs once across the deployment, not once per machine.
 *
 * WHAT: a task whose lock another server already holds does not run, the one that gets the
 *       lock runs and gives it back, and a failure gives it back too.
 *
 * WHY:  `withoutOverlapping()` locks a file in `sys_get_temp_dir()`, which is per machine.
 *       On two web servers each has its own, so each takes its own lock and the task runs
 *       once per node — two copies of a nightly email, two of a billing run. **Nothing
 *       fails**, which is why it can run for months unnoticed.
 *
 *       Two locks in one process is the closest a suite gets to two machines, and it is
 *       close enough: the exclusion is a row in `pramnos.locks` whose primary key does the
 *       work, and it does not know or care which host asked.
 */
#[CoversClass(ScheduledTask::class)]
class OnOneServerTest extends BaseTestCase
{
    private Database $db;

    private string $handler = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $this->runMigrations(
            [\Pramnos\Framework\Migrations\Core\CreateLocksTable::class],
            $this->db
        );

        // A name per test: the lock table outlives a test, and two tests sharing a name
        // would have the second find what the first left.
        $this->handler = 'probe:' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        try {
            $this->db->queryBuilder()->table(SharedLock::TABLE)
                ->where('name', 'schedule:' . $this->handler)->delete();
        } catch (\Throwable) {
            // Already gone.
        }

        parent::tearDown();
    }

    /**
     * A task that records it ran, locked under this test's own name.
     *
     * The name is passed explicitly because the handler is a closure: `describeHandler()`
     * answers the constant `Closure` for every one of them, so two unrelated closure tasks
     * would share a lock. `onOneServer()` refuses a closure without a name for exactly
     * that reason — there is a test for it below.
     */
    private function task(array &$ran): ScheduledTask
    {
        $name = $this->handler;

        return new ScheduledTask(static function () use (&$ran, $name): void {
            $ran[] = $name;
        }, 'callable');
    }

    /**
     * The second machine is turned away.
     *
     * **The assertion the feature exists for.** `run()` returning `false` is how the
     * scheduler already reports "skipped", so nothing above this had to learn a new shape.
     */
    public function testOnlyOneRunHappens(): void
    {
        // Arrange — another server holds the lock and is still working
        $held = new SharedLock('schedule:' . $this->handler, 3600, $this->db);
        $this->assertTrue($held->acquire(), 'the fixture could not take the lock');

        $ran  = [];
        $task = $this->task($ran)->onOneServer(name: $this->handler);

        // Act
        $result = $task->run();

        // Assert
        $this->assertFalse($result, 'the task ran while another server held the lock');
        $this->assertSame([], $ran, 'the handler was executed anyway');
    }

    /**
     * And the machine that gets the lock does the work, then gives it back.
     *
     * A lock nobody ever releases is an outage rather than an exclusion: tomorrow night's
     * run finds it held and the task never happens again.
     */
    public function testTheHolderRunsAndReleases(): void
    {
        // Arrange
        $ran  = [];
        $task = $this->task($ran)->onOneServer(name: $this->handler);

        // Act
        $result = $task->run();

        // Assert
        $this->assertTrue($result);
        $this->assertSame([$this->handler], $ran, 'the handler did not run');
        $this->assertTrue(
            (new SharedLock('schedule:' . $this->handler, 3600, $this->db))->acquire(),
            'the lock was not released, so tomorrow night is blocked'
        );
    }

    /**
     * The lock is released even when the task throws.
     *
     * A task that fails must not take the schedule with it. Without the `finally` this is
     * the shape of the outage: one exception, and the job never runs again until somebody
     * deletes a row nobody knows about.
     */
    public function testTheLockIsReleasedWhenTheTaskThrows(): void
    {
        // Arrange
        $task = (new ScheduledTask(static function (): void {
            throw new \RuntimeException('the task failed');
        }, 'callable'))->onOneServer(name: $this->handler);

        // Act
        try {
            $task->run();
        } catch (\Throwable) {
            // The failure is the caller's to report; what is asserted here is the lock.
        }

        // Assert
        $this->assertTrue(
            (new SharedLock('schedule:' . $this->handler, 3600, $this->db))->acquire(),
            'a failed task kept the lock for ever'
        );
    }

    /**
     * Without `onOneServer()` nothing is taken, so existing schedules are unchanged.
     *
     * Opt-in: every application's `app/schedule.php` keeps behaving as it did, including
     * on the single server where a database round trip per task would buy nothing.
     */
    public function testATaskThatDidNotAskForItTakesNoLock(): void
    {
        // Arrange
        $ran  = [];
        $task = $this->task($ran);

        // Act
        $this->assertTrue($task->run());

        // Assert
        $row = $this->db->queryBuilder()->table(SharedLock::TABLE)
            ->where('name', 'schedule:' . $this->handler)->first();

        $this->assertSame(0, (int) ($row->numRows ?? 0), 'a task that did not ask took a lock');
        $this->assertSame([$this->handler], $ran);
    }

    /**
     * A closure with no name is refused, loudly and at definition time.
     *
     * Every closure describes itself as `Closure`, so two unrelated closure tasks marked
     * `onOneServer()` would share one lock and take turns not running — the silent shape
     * this whole feature exists to remove. Raising while `app/schedule.php` is being read
     * is the moment it costs nothing to fix.
     */
    public function testAClosureWithNoNameIsRefused(): void
    {
        // Arrange
        $ran  = [];
        $task = $this->task($ran);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a name for a closure task/');

        // Act
        $task->onOneServer();
    }

    /**
     * `schedule:list` can see which tasks are one-per-deployment.
     *
     * An operator reading that list is deciding whether it is safe to run cron on a second
     * node, and the answer is per task. A flag that exists only in the source is not one.
     */
    public function testTheSummaryReportsIt(): void
    {
        // Arrange
        $ran  = [];
        $task = $this->task($ran);

        // Act + Assert
        $this->assertFalse($task->getSummary()['one_server']);
        $this->assertTrue($task->onOneServer(name: $this->handler)->getSummary()['one_server']);
    }
}
