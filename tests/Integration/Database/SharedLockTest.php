<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\SharedLock;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;

/**
 * A lock that holds across servers, because it holds across connections.
 *
 * WHAT: two `SharedLock`s over the same name — the closest a single process gets to two
 *       machines — and only one of them gets it.
 *
 * WHY:  `WorkerLock` is a file, and it says so: it records the holder's `host` and checks
 *       the holder's pid *on the same host*. On two web servers each has its own `var/`
 *       and its own `sys_get_temp_dir()`, so each takes its own lock and every scheduled
 *       task marked `withoutOverlapping()` runs **once per node** — two nightly emails,
 *       two billing runs. Nothing fails, which is why it can run for months unnoticed.
 *
 *       The exclusion here is the primary key: an `INSERT` of a name already present is
 *       refused by the engine, not by a check this class makes, so there is no window
 *       between looking and taking.
 *
 * Both lanes, because the atomicity is the engine's and the two engines report a duplicate
 * key differently — a test on one proves nothing about the other.
 */
#[CoversClass(SharedLock::class)]
class SharedLockTest extends BaseTestCase
{
    private Database $db;

    private string $name = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations(
            [\Pramnos\Framework\Migrations\Core\CreateLocksTable::class],
            $this->db
        );

        // A name per test: the table outlives a test and two tests sharing a name would
        // have the second read what the first left.
        $this->name = 'probe:' . bin2hex(random_bytes(4));
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        try {
            $this->db->queryBuilder()->table(SharedLock::TABLE)
                ->where('name', $this->name)->delete();
        } catch (\Throwable) {
            // Already gone.
        }

        parent::tearDown();
    }

    private function lock(int $ttl = 3600): SharedLock
    {
        return new SharedLock($this->name, $ttl, $this->db);
    }

    /**
     * The first taker gets it and the second does not.
     *
     * **The assertion the class exists for**, and the one `WorkerLock` cannot make across
     * machines.
     */
    public function testOnlyOneHolderAtATime(): void
    {
        // Arrange
        $first  = $this->lock();
        $second = $this->lock();

        // Act
        $gotFirst  = $first->acquire();
        $gotSecond = $second->acquire();

        // Assert
        $this->assertTrue($gotFirst, 'the first taker did not get the lock');
        $this->assertFalse($gotSecond, 'two holders at once — the exclusion is not one');
        $this->assertTrue($first->isHeld());
        $this->assertFalse($second->isHeld());
    }

    /**
     * And releasing hands it on.
     *
     * The other half: a lock nobody can ever take again is an outage rather than an
     * exclusion, and it is the shape a `release()` that failed quietly would produce.
     */
    public function testReleasingLetsTheNextTakerIn(): void
    {
        // Arrange
        $first = $this->lock();
        $this->assertTrue($first->acquire());

        // Act
        $first->release();

        // Assert
        $this->assertTrue($this->lock()->acquire(), 'the lock was never handed on');
        $this->assertFalse($first->isHeld());
    }

    /**
     * An expired lease is taken over.
     *
     * A holder that dies never releases, so without this the first crash locks the task out
     * for ever — which is worse than the duplicate runs the lock was added to stop.
     */
    public function testAnExpiredLeaseIsTakenOver(): void
    {
        // Arrange — a lease that has already passed
        $abandoned = $this->lock(1);
        $this->assertTrue($abandoned->acquire());
        $this->db->queryBuilder()->table(SharedLock::TABLE)
            ->where('name', $this->name)
            ->update(['expiresat' => time() - 10]);

        // Act
        $next = $this->lock();

        // Assert
        $this->assertTrue($next->acquire(), 'an abandoned lock was never recoverable');
        $this->assertNotNull($next->holder());
    }

    /**
     * A lease that has *not* expired is not taken over.
     *
     * The control. A takeover that ignored the expiry would pass the test above and make
     * the lock useless — and would do it while looking like it worked.
     */
    public function testALiveLeaseIsNotTakenOver(): void
    {
        // Arrange
        $this->assertTrue($this->lock(3600)->acquire());

        // Act + Assert
        $this->assertFalse($this->lock()->acquire(), 'a live lease was taken over');
    }

    /**
     * A process whose lease expired cannot release the new holder's lock.
     *
     * The failure this prevents is worse than the one it is about: the old holder finishes,
     * deletes a row it no longer owns, and now *nobody* holds the lock while the new holder
     * is still working. One overlapping run becomes an unbounded number.
     */
    public function testAnEvictedHolderCannotReleaseTheNewOne(): void
    {
        // Arrange — evicted, then somebody else takes it
        $evicted = $this->lock(1);
        $this->assertTrue($evicted->acquire());
        $this->db->queryBuilder()->table(SharedLock::TABLE)
            ->where('name', $this->name)
            ->update(['expiresat' => time() - 10]);

        $newHolder = $this->lock();
        $this->assertTrue($newHolder->acquire());

        // Act — the evicted process finishes its work and tidies up
        $evicted->release();

        // Assert — the new holder still has it
        $this->assertNotNull($newHolder->holder(), 'the evicted holder deleted a lock it did not own');
        $this->assertFalse($this->lock()->acquire(), 'the lock is now free while somebody is working');
    }

    /**
     * `holder()` says nobody when nothing has taken it.
     *
     * Read by anybody looking at why a task did not run, so "no row" and "somebody holds
     * it" must not both come back as a name.
     */
    public function testHolderIsNullWhenNobodyHasIt(): void
    {
        // Act + Assert
        $this->assertNull($this->lock()->holder());
    }

    /**
     * Releasing a lock this instance never took does nothing.
     *
     * A `finally` calls `release()` whether or not the acquire succeeded — that is how the
     * scheduler is written — so this has to be a no-op rather than a delete of whatever
     * the real holder is working under.
     */
    public function testReleasingALockThisInstanceNeverTookIsHarmless(): void
    {
        // Arrange — somebody else holds it
        $holder = $this->lock();
        $this->assertTrue($holder->acquire());

        // Act — an instance that failed to acquire tidies up anyway
        $loser = $this->lock();
        $this->assertFalse($loser->acquire());
        $loser->release();

        // Assert
        $this->assertNotNull($holder->holder(), 'a loser released the winner\'s lock');
    }

    /**
     * `holder()` says nobody when the lease has passed.
     *
     * Read by `schedule:list` and by anybody looking at why a task is not running, so a
     * lease nobody will honour must not read as a lock somebody holds.
     */
    public function testHolderIgnoresAnExpiredLease(): void
    {
        // Arrange
        $lock = $this->lock(1);
        $this->assertTrue($lock->acquire());
        $this->assertNotNull($lock->holder());

        // Act
        $this->db->queryBuilder()->table(SharedLock::TABLE)
            ->where('name', $this->name)
            ->update(['expiresat' => time() - 10]);

        // Assert
        $this->assertNull($lock->holder());
    }
}
