<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * The deadlock retry, which is why TimescaleDB writes survive its background workers.
 *
 * `runQuery()` and `execute()` each carry the same loop: a failure whose message mentions a
 * deadlock is retried three times with 100ms, 200ms and 300ms of back-off before the error is
 * surfaced. It exists for SQLSTATE 40P01, which arises on TimescaleDB when a background worker
 * holds an advisory lock during DDL — a transient condition where the right answer is to wait and
 * try again, and where surfacing the error means losing a write for no reason.
 *
 * Never executed, in either copy. Which matters more than the six statements: a retry loop that has
 * never run is as likely to spin for ever as to work, and both `continue`s here restart a `while`
 * whose exit depends on the counter reaching zero.
 *
 * Triggered with `RAISE EXCEPTION 'deadlock detected'`, because the gate is
 * `stripos($error, 'deadlock')` on the driver's message. That is a deterministic way to produce the
 * condition — and it also demonstrates the gate's own looseness, which is recorded in the guide:
 * *any* error whose text mentions a deadlock is retried, including one an application raised itself.
 *
 * PostgreSQL only, and only this lane: the retry sits in the `pg_query` branch. MySQL reports a
 * deadlock as an exception rather than a `false`, so the same condition takes an entirely different
 * path there.
 */
#[CoversClass(Database::class)]
class DeadlockRetryTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 5432,
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return [];
    }

    /** @return string[] */
    protected static function schemaStatements(): array
    {
        return [];
    }

    /**
     * One connection for the class, not one per test.
     *
     * Opened per test first, and the suite went from its 2:34–2:38 band to 2:42 twice over —
     * four PostgreSQL handshakes cost more than the 1.2 seconds of back-off these tests are
     * actually here to observe. Nothing below writes anything, so there is nothing to isolate
     * between tests and no reason to pay for it.
     */
    private static ?Database $shared = null;

    private ?Database $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$shared === null) {
            try {
                self::$shared = static::openConnection();
            } catch (\Throwable $exception) {
                $this->markTestSkipped('PostgreSQL is not reachable: ' . $exception->getMessage());
            }
        }

        if (self::$shared === null || !self::$shared->connected) {
            $this->markTestSkipped('PostgreSQL is not reachable.');
        }

        $this->connection = self::$shared;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$shared !== null) {
            self::$shared->close();
            self::$shared = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * How long a failing statement took, in milliseconds.
     *
     * The exception is expected and swallowed: a PostgreSQL error is surfaced by `setError()` as a
     * throw, so "the failure was surfaced" *is* the exception. What these tests are measuring is
     * how long the framework waited before surfacing it.
     */
    private function millisecondsOf(callable $work): float
    {
        $started = microtime(true);

        try {
            $work();
        } catch (\Throwable) {
            // Surfacing the error is the expected outcome; the timing is the subject.
        }

        return (microtime(true) - $started) * 1000;
    }

    /** A statement that fails with the given message, as the driver reports it. */
    private function failingWith(string $message): string
    {
        return "DO $$ BEGIN RAISE EXCEPTION '" . $message . "'; END $$";
    }

    /**
     * A deadlock is retried three times, with back-off, before the error is surfaced.
     *
     * The elapsed time is the assertion, because the back-off is the behaviour: 100 + 200 + 300
     * milliseconds. A loop that retried without waiting would hammer the lock holder and be
     * indistinguishable from no retry at all by any other measurement, and this test would pass on
     * it if it only checked the return value.
     *
     * The upper bound is there too. Without it, a loop whose counter never reached zero would look
     * like a pass — it would simply keep sleeping, and "took at least 600ms" is true of for ever.
     */
    public function testADeadlockIsRetriedWithBackOffAndThenSurfaced(): void
    {
        // Arrange
        $statement = $this->failingWith('deadlock detected');

        // Act
        $elapsed = $this->millisecondsOf(fn() => $this->connection->execute($statement));

        // Assert
        $this->assertGreaterThanOrEqual(
            550,
            $elapsed,
            'the back-off did not happen: 100 + 200 + 300ms is the whole point of the retry'
        );
        $this->assertLessThan(
            5000,
            $elapsed,
            'the retry loop did not stop, which is what an unbounded counter looks like'
        );
    }

    /**
     * An error that is not a deadlock is surfaced immediately.
     *
     * The control, and the assertion that the gate is a gate: without it, every failing statement
     * would be run four times and every genuine error would take six-tenths of a second to
     * report — on a page that makes a few of them, a visible delay for no benefit.
     */
    public function testAnOrdinaryErrorIsNotRetried(): void
    {
        // Arrange
        $statement = $this->failingWith('column does not exist in any useful sense');

        // Act
        $elapsed = $this->millisecondsOf(fn() => $this->connection->execute($statement));

        // Assert
        $this->assertLessThan(
            200,
            $elapsed,
            'an ordinary error was retried, so every failure now costs six-tenths of a second'
        );
    }

    /**
     * `runQuery()` has its own copy of the loop, and it behaves the same.
     *
     * Its own copy is why this is a second test. `execute()` is the prepared path and `runQuery()`
     * the unprepared one, and a project uses both — a fix applied to one would leave every
     * `query()` call losing its write to a transient lock.
     */
    public function testTheUnpreparedPathRetriesTheSameWay(): void
    {
        // Arrange
        $statement = $this->failingWith('deadlock detected');

        // Act
        $elapsed = $this->millisecondsOf(fn() => $this->connection->query($statement));

        // Assert
        $this->assertGreaterThanOrEqual(
            550,
            $elapsed,
            'the unprepared path did not back off, so query() loses writes the prepared path keeps'
        );
        $this->assertLessThan(5000, $elapsed, 'the unprepared retry loop did not stop');
    }

    /**
     * And an ordinary error on the unprepared path is not retried either.
     *
     * Both copies of the gate, because both copies of the loop exist.
     */
    public function testTheUnpreparedPathDoesNotRetryAnOrdinaryError(): void
    {
        // Arrange
        $statement = $this->failingWith('something else went wrong');

        // Act
        $elapsed = $this->millisecondsOf(fn() => $this->connection->query($statement));

        // Assert
        $this->assertLessThan(200, $elapsed);
    }
}
