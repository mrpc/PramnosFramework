<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Queue\CreateDelayedJobsTable;
use Pramnos\Queue\DelayedQueue;
use Pramnos\Queue\Drivers\DatabaseQueueDriver;
use Pramnos\Queue\ReservedJob;

/**
 * Integration tests for the database delayed-queue driver against a live MySQL
 * database (host: db, from the fixtures settings).
 *
 * Exercises the real SQL the driver issues — INSERT on push, the due-time poll
 * plus atomic claim-by-DELETE, COUNT/MIN aggregates, and bulk flush — against the
 * shipped `delayed_jobs` migration, proving the claim-and-remove contract holds
 * on a real relational backend. The PostgreSQL subclass re-runs every test on PG.
 */
#[CoversClass(DatabaseQueueDriver::class)]
#[CoversClass(ReservedJob::class)]
#[CoversClass(DelayedQueue::class)]
class DelayedQueueDatabaseMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected DatabaseQueueDriver $driver;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->app = $this->makeApp();

        $this->dropTable();
        $this->migrate();

        $this->driver = new DatabaseQueueDriver($this->db);
    }

    protected function tearDown(): void
    {
        $this->dropTable();
    }

    /**
     * Create the delayed_jobs table from the shipped migration.
     *
     * Migrations are not PSR-4 autoloaded (they are discovered by the
     * MigrationLoader), so the file is required explicitly before instantiation.
     */
    protected function migrate(): void
    {
        /*
         * Found by its slug, not by its timestamped filename.
         *
         * The timestamp prefix is metadata — it decides ordering and whether a `migration_cutoff`
         * skips the file — and it changes when a migration is found to have been misdated. A
         * test that hard-codes it breaks on a rename that changed nothing it was testing, which
         * is exactly what happened.
         */
        $found = glob(
            ROOT . \DS . 'database' . \DS . 'migrations' . \DS . 'framework'
            . \DS . 'queue' . \DS . '*_create_delayed_jobs_table.php'
        );

        $this->assertNotEmpty($found, 'the delayed jobs migration is missing');

        require_once $found[0];
        (new CreateDelayedJobsTable($this->app))->up();
    }

    /**
     * push() inserts a row and claimDue() returns it as a ReservedJob once due,
     * decoding the JSON payload back to an array and echoing the pushed job id.
     */
    public function testPushThenClaimDueReturnsReservedJob(): void
    {
        $id = $this->driver->push('reply', ['message' => 'γειά', 'n' => 3], 0);
        $this->assertNotSame('', $id);

        $claimed = $this->driver->claimDue();
        $this->assertCount(1, $claimed);
        $this->assertInstanceOf(ReservedJob::class, $claimed[0]);
        $this->assertSame($id, $claimed[0]->id);
        $this->assertSame('reply', $claimed[0]->type);
        $this->assertSame(['message' => 'γειά', 'n' => 3], $claimed[0]->payload);
        $this->assertSame(0, $claimed[0]->attempts);
    }

    /**
     * A job scheduled in the future is not returned by claimDue() until its
     * run-at has passed; a due job alongside it is.
     */
    public function testDelayedJobNotClaimedUntilDue(): void
    {
        $this->driver->push('reply', ['x' => 'future'], 3600);
        $dueId = $this->driver->push('reply', ['x' => 'now'], 0);

        $claimed = $this->driver->claimDue();
        $this->assertCount(1, $claimed);
        $this->assertSame($dueId, $claimed[0]->id);
        $this->assertSame(1, $this->driver->size(), 'the future job is still scheduled');
    }

    /**
     * claimDue() is atomic claim-and-remove: once a job is claimed it is gone,
     * so a second claimDue() returns nothing for it.
     */
    public function testClaimRemovesJob(): void
    {
        $this->driver->push('reply', [], 0);

        $this->assertCount(1, $this->driver->claimDue());
        $this->assertCount(0, $this->driver->claimDue());
        $this->assertSame(0, $this->driver->size());
    }

    /**
     * size() counts scheduled jobs, secondsUntilNext() is null when empty, 0 when
     * work is due, and positive when the soonest job is in the future.
     */
    public function testSizeAndSecondsUntilNext(): void
    {
        $this->assertSame(0, $this->driver->size());
        $this->assertNull($this->driver->secondsUntilNext());

        $this->driver->push('reply', [], 0);
        $this->assertSame(0, $this->driver->secondsUntilNext());

        $this->driver->flush();
        $this->driver->push('reply', [], 300);
        $next = $this->driver->secondsUntilNext();
        $this->assertNotNull($next);
        $this->assertGreaterThan(0, $next);
        $this->assertLessThanOrEqual(300, $next);
    }

    /**
     * flush() removes every scheduled job and returns how many were removed.
     */
    public function testFlush(): void
    {
        $this->driver->push('reply', [], 0);
        $this->driver->push('reply', [], 10);

        $this->assertSame(2, $this->driver->flush());
        $this->assertSame(0, $this->driver->size());
    }

    /**
     * The DelayedQueue facade works identically over the database driver,
     * including the backoff-and-drop retry policy: a job under the ceiling is
     * re-scheduled (a new row with incremented attempts), one at the ceiling is
     * dropped (null).
     */
    public function testDelayedQueueFacadeRetryOverDatabaseDriver(): void
    {
        $queue = new DelayedQueue($this->driver);

        $job = new ReservedJob('a', 'reply', ['k' => 'v'], 1, time());
        $newId = $queue->retry($job, 3, 0); // 0 backoff so it is immediately due
        $this->assertNotNull($newId);

        $claimed = $queue->claimDue();
        $this->assertCount(1, $claimed);
        $this->assertSame(2, $claimed[0]->attempts, 'attempts incremented on retry');
        $this->assertSame(['k' => 'v'], $claimed[0]->payload);

        $this->assertNull($queue->retry(new ReservedJob('b', 'reply', [], 2, time()), 3, 0));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function dropTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS delayed_jobs');
    }
}
