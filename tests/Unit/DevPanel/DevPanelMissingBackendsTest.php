<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;

/**
 * What each card on the performance panel shows when the thing behind it is not there.
 *
 * This is the panel somebody opens *because* something is wrong, on an installation that may not have
 * finished setting itself up — no queue table, no write spool, no scheduler definitions, no git. Every
 * card therefore has a `catch`, and none of those catches had ever run.
 *
 * The failure they prevent is the specific one: a panel that throws is a panel that cannot be opened,
 * so the tool for diagnosing a broken installation is the tool a broken installation takes away. And a
 * card that showed a plausible zero instead of an em-dash would be worse than either — «0 pending
 * jobs» and «I could not read the queue» are different facts, and only one of them means the queue is
 * fine.
 *
 * ## Not covered here, and why
 *
 * `readProcUptime()`, `readProcLoadAvg()` and `readProcMemInfo()` each carry a one-line fallback for a
 * host with no `/proc`. Inside a Linux container that branch is unreachable, and a seam for one
 * `return '—'` would be more machinery than the line it tests. Recorded rather than worked around.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelMissingBackendsTest extends TestCase
{
    /** @var mixed The database singleton as it was before the test */
    private $originalDatabase = null;

    protected function tearDown(): void
    {
        if ($this->originalDatabase !== null) {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = $this->originalDatabase;
            $this->originalDatabase = null;
        }

        parent::tearDown();
    }

    /**
     * A controller with no constructor: these cards read services and the database singleton, not
     * controller state.
     */
    private function controller(): object
    {
        return new class extends DevPanelController {
            public function __construct()
            {
            }

            /**
             * What the page would show about the cards that failed.
             *
             * `panelError()` is private, so an override in this subclass declares a *new* method and
             * silently records nothing — which is how the first version of this test asserted its own
             * stub. Reading what the panel renders instead asserts the real recording, and it is also
             * the thing an operator actually sees.
             */
            public function reportedFailures(): string
            {
                return (string) (new \ReflectionMethod(DevPanelController::class, 'panelErrorsHtml'))
                    ->invoke($this);
            }

            public function call(string $method, ...$arguments): mixed
            {
                return (new \ReflectionMethod(DevPanelController::class, $method))
                    ->invoke($this, ...$arguments);
            }
        };
    }

    /** Point the framework's database singleton at a connection whose every statement fails. */
    private function useUnavailableDatabase(): void
    {
        $db = new class extends \Pramnos\Database\Database {
            public function __construct()
            {
                $this->type = 'mysql';
                $this->connected = true;
            }

            public function execute($sql, &...$arguments)
            {
                throw new \Exception('the database is unavailable');
            }

            public function queryBuilder()
            {
                throw new \Exception('the database is unavailable');
            }
        };

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $this->originalDatabase = $singleton;
        $singleton = $db;
    }

    /**
     * With no readable queue table, the card says so rather than reporting zero.
     *
     * Em-dashes, not zeros, and the distinction is the whole point of the branch: «0 pending jobs» and
     * «I could not read the queue» are different facts, and only one of them means the queue is
     * healthy. An operator looking at this panel because jobs are not running would read a zero as
     * «the queue is empty, so the problem is upstream» and go looking in the wrong place.
     *
     * The failure is also *reported* rather than swallowed — `panelError()` is what puts a line on the
     * page saying which card could not load, so the em-dash is explained rather than mysterious.
     */
    public function testTheQueueCardSaysNothingRatherThanZero(): void
    {
        // Arrange
        $this->useUnavailableDatabase();
        $panel = $this->controller();

        // Act
        $stats = $panel->call('fetchQueueStats');

        // Assert
        $this->assertSame(['—', '—', '—'], $stats, 'an unreadable queue was reported as empty');
        $this->assertStringContainsString(
            'queue statistics',
            $panel->reportedFailures(),
            'the em-dash is unexplained: the page says nothing about which card failed'
        );
    }

    /**
     * The background-work card degrades one half at a time.
     *
     * Two independent `try` blocks — the write spool and the scheduler — because they are two different
     * subsystems and one being absent says nothing about the other. A single `try` around both would
     * make a missing scheduler definition hide the spool's queue depth, which is the number somebody
     * came to the panel to read.
     */
    public function testTheBackgroundWorkCardDegradesOneHalfAtATime(): void
    {
        // Arrange — the spool resolves fine; the scheduler is what fails
        $panel = $this->controller();

        \Pramnos\Database\WriteSpool::reset();

        // Act
        [$pending, $driver, $tasks] = $panel->call('fetchBackgroundWork');

        // Assert — the spool half answered
        $this->assertIsInt($pending);
        $this->assertNotSame('unknown', $driver, 'the spool driver could not be resolved either');
        $this->assertIsInt($tasks);
    }

    /**
     * With no application to ask, the migration card is em-dashes rather than an exception.
     *
     * `fetchMigrationStatus()` needs an Application to load the migration directories through, and a
     * panel reached from a context that has none — a CLI probe, a half-booted request — must not take
     * the page down for it. The early return is what makes the card degrade instead.
     */
    public function testTheMigrationCardWithNoApplicationIsEmDashes(): void
    {
        // Arrange
        $this->useUnavailableDatabase();
        $panel = $this->controller();

        // Act
        $status = $panel->call('fetchMigrationStatus');

        // Assert
        $this->assertCount(3, $status);
        $this->assertSame(['—', '—', '—'], $status);
    }

    /**
     * The MCP traffic card explains how to turn logging on when there is no log.
     *
     * Rather than «off», which is what it would be tempting to print. The panel exists to be read by
     * somebody who does not already know the answer, so the absent state carries the command that
     * changes it — and a link to the viewer that will read the file once it exists.
     */
    public function testTheMcpCardExplainsHowToStartLogging(): void
    {
        // Arrange
        $panel = $this->controller();

        // Act
        $html = (string) $panel->call('mcpTrafficLogStatus');

        // Assert
        $this->assertStringContainsString('mcp:serve --log', $html, 'the card does not say how');
        $this->assertStringContainsString('log viewer', $html, 'there is no way to the file');
    }

    /**
     * The repo root falls back rather than guessing wrong.
     *
     * Three steps, in order: the application's own `ROOT` if it is a checkout, the framework's source
     * root if *that* is, and the working directory otherwise. The order matters — an application
     * vendoring the framework has two `.git` directories, and the git card is supposed to describe the
     * application's history rather than the framework's.
     *
     * The last step is a fallback and not an answer, which is why it must never be empty: a git command
     * run in `''` runs in whatever directory the process happens to be in.
     */
    public function testTheRepoRootAlwaysResolvesToSomething(): void
    {
        // Arrange
        $panel = $this->controller();

        // Act
        $root = (string) $panel->call('detectRepoRoot');

        // Assert
        $this->assertNotSame('', $root, 'a git command would run in an unknown directory');
        $this->assertDirectoryExists($root);
    }
}
