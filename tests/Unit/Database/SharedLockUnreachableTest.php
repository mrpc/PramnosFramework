<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SharedLock;

/**
 * What a lock does when the database is not answering.
 *
 * The integration tests drive the exclusion against two real engines, which is where it is
 * proved. What a working database will not produce on demand is a connection that raises —
 * and that is the case where getting the answer wrong is most expensive, because a lock
 * exists to stop two machines doing the same work and a "yes" it should not have given is
 * exactly that.
 *
 * So: a database that throws at everything, and the three answers that follow from it.
 */
#[CoversClass(SharedLock::class)]
class SharedLockUnreachableTest extends TestCase
{
    /** A lock over a connection that raises whatever it is asked. */
    private function lockOverABrokenConnection(): SharedLock
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['queryBuilder'])
            ->getMock();

        $db->method('queryBuilder')->willReturnCallback(static function (): never {
            throw new \RuntimeException('server has gone away');
        });

        return new SharedLock('probe', 3600, $db);
    }

    /**
     * A lock that cannot be read is **not** free.
     *
     * The assertion that matters. `acquire()` returning true here would let every node run
     * the task at once, on the argument that nobody could be shown to hold it — which is
     * the opposite of what a lock is for. A database that is down should stop the work,
     * not multiply it.
     */
    public function testAnUnreachableDatabaseDoesNotHandOutTheLock(): void
    {
        // Act + Assert
        $this->assertFalse($this->lockOverABrokenConnection()->acquire());
    }

    /**
     * `holder()` answers null rather than raising.
     *
     * It is read by `schedule:list` and by anybody looking at why a task is not running —
     * code that runs *around* the thing that is broken. An exception from it turns "the
     * database is down" into a stack trace from whatever asked about a lock first.
     */
    public function testHolderAnswersNullRatherThanRaising(): void
    {
        // Act + Assert
        $this->assertNull($this->lockOverABrokenConnection()->holder());
    }

    /**
     * And `release()` does not raise either.
     *
     * It is called from a `finally`. An exception there replaces whatever the task was
     * actually failing with — so the first thing anybody sees about a broken night is a
     * lock error rather than the error that broke it.
     */
    public function testReleaseDoesNotRaiseWhenTheDatabaseIsGone(): void
    {
        // Arrange — a lock that believes it holds one
        $lock = $this->lockOverABrokenConnection();
        (new \ReflectionProperty(SharedLock::class, 'held'))->setValue($lock, true);

        // Act
        $lock->release();

        // Assert — it gave up its claim rather than keeping one it cannot prove
        $this->assertFalse($lock->isHeld());
    }
}
