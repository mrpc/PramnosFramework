<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\FrameworkSchedule;
use Pramnos\Scheduling\Scheduler;

/**
 * The framework's own periodic work, and how an application overrides it.
 *
 * A framework that adds a background command and then leaves every project to
 * remember it has shipped an obligation rather than a feature — `app/schedule.php`
 * is written once, at scaffold time, so a later framework version could never
 * add to it. These tests pin the two halves of the answer: the framework's tasks
 * are registered whether or not the application has a schedule file, and an
 * application that wants a different arrangement can say so.
 */
#[CoversClass(FrameworkSchedule::class)]
class FrameworkScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
        parent::tearDown();
    }

    /**
     * The framework's work is registered with no application file at all.
     *
     * The case that matters: an application that never created `app/schedule.php`
     * still gets its spool drained, because it installed the cron line or runs
     * the worker and that is all it should have to do.
     */
    public function testTheFrameworkTasksAreRegisteredWithoutAnApplicationFile(): void
    {
        // Act — a path that does not exist
        $loaded = Scheduler::loadDefinitions('/no/such/schedule.php');

        // Assert
        $this->assertFalse($loaded, 'there was no application file');
        $this->assertNotSame([], Scheduler::all(), 'but the framework registered its own');

        $handlers = $this->registeredHandlers();
        $this->assertContains('spool:drain', $handlers);
        $this->assertContains('timescale:drain', $handlers);
    }

    /**
     * Loading twice does not register anything twice.
     *
     * A command that loads definitions and then loads them again would
     * otherwise run every framework job twice per tick.
     */
    public function testRegisteringTwiceRegistersOnce(): void
    {
        // Act
        FrameworkSchedule::register();
        $afterFirst = count(Scheduler::all());
        FrameworkSchedule::register();

        // Assert
        $this->assertSame($afterFirst, count(Scheduler::all()));
        $this->assertGreaterThan(0, $afterFirst);
    }

    /**
     * Every framework task takes a lock.
     *
     * They are idempotent, but a drain that overlaps itself does the same work
     * twice — and for the one that decompresses a chunk to write into it, twice
     * is expensive rather than merely wasteful.
     */
    public function testEveryFrameworkTaskRefusesToOverlapItself(): void
    {
        // Act
        FrameworkSchedule::register();

        // Assert
        foreach (Scheduler::all() as $task) {
            $summary = $task->getSummary();
            $this->assertTrue(
                $summary['no_overlap'],
                $summary['handler'] . ' must not be able to run beside itself'
            );
        }
    }

    /**
     * Every framework task says what it is for.
     *
     * `schedule:list` is where an operator finds out what this application does
     * on a timer; a row with a bare command name and no description is a row
     * they have to go and look up.
     */
    public function testEveryFrameworkTaskIsDescribed(): void
    {
        // Act
        FrameworkSchedule::register();

        // Assert
        foreach (Scheduler::all() as $task) {
            $summary = $task->getSummary();
            $this->assertNotSame('', trim((string) $summary['description']));
        }
    }

    /**
     * An application can drop one framework task and keep the rest.
     *
     * The documented way to replace a cadence: disable the framework's version,
     * register your own.
     */
    public function testAnApplicationCanDisableOneTask(): void
    {
        // Arrange
        FrameworkSchedule::disable('spool:drain');

        // Act
        FrameworkSchedule::register();

        // Assert
        $handlers = $this->registeredHandlers();
        $this->assertNotContains('spool:drain', $handlers);
        $this->assertContains('timescale:drain', $handlers, 'the others are untouched');
        $this->assertFalse(FrameworkSchedule::isEnabled('spool:drain'));
    }

    /**
     * An application can drop all of them.
     *
     * Naming each one instead would leave the caller broken by the next
     * framework version that adds a task — which is the same trap this whole
     * class exists to avoid.
     */
    public function testAnApplicationCanDisableAllOfThem(): void
    {
        // Arrange
        FrameworkSchedule::disableAll();

        // Act
        $registered = FrameworkSchedule::register();

        // Assert
        $this->assertSame(0, $registered);
        $this->assertSame([], Scheduler::all());
    }

    /**
     * The application's own file is loaded before the framework registers.
     *
     * That ordering is what makes `disable()` usable at all: the application
     * has to be able to say "not that one" before the framework adds it.
     */
    public function testTheApplicationFileIsLoadedFirst(): void
    {
        // Arrange — a schedule file that disables one and adds its own
        $file = sys_get_temp_dir() . '/pramnos_fw_sched_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\n"
            . "\\Pramnos\\Scheduling\\FrameworkSchedule::disable('spool:drain');\n"
            . "\\Pramnos\\Scheduling\\Scheduler::command('spool:drain')->everyFiveMinutes();\n");

        try {
            // Act
            Scheduler::loadDefinitions($file);

            // Assert — one spool:drain, on the application's cadence
            $drains = array_values(array_filter(
                Scheduler::all(),
                static fn($task): bool => $task->getSummary()['handler'] === 'spool:drain'
            ));

            $this->assertCount(1, $drains, 'the framework did not add a second one');
            $this->assertSame('*/5 * * * *', $drains[0]->getSummary()['expression']);
        } finally {
            @unlink($file);
        }
    }

    /**
     * The advertised command list matches what is actually registered.
     *
     * `commands()` is what a caller uses to reason about the framework's
     * schedule without reading its source; a list that drifts from the truth is
     * worse than no list.
     */
    public function testTheAdvertisedListMatchesWhatIsRegistered(): void
    {
        // Act
        FrameworkSchedule::register();

        // Assert
        $expected = FrameworkSchedule::commands();
        $actual   = $this->registeredHandlers();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * Every handler currently in the scheduler.
     *
     * @return list<string>
     */
    private function registeredHandlers(): array
    {
        return array_values(array_map(
            static fn($task): string => (string) $task->getSummary()['handler'],
            Scheduler::all()
        ));
    }
}
