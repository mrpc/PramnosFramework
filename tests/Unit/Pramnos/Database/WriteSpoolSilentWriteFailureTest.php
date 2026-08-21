<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\WriteSpool;

/**
 * A spooled write that fails without throwing must not be counted as written.
 *
 * `Database::execute()` has one documented path that returns false rather than
 * throwing — a *prepare* failure outside strict mode — and its own comment says the
 * point of surfacing it is "so a caller cannot swallow it". `WriteSpool::writeNow()`
 * discarded the return value, so it swallowed it.
 *
 * The consequence was not a failed write but a **lost row**: no exception meant
 * `written` was incremented, which meant the spool file was deleted. Measured on a
 * live stack twice — reported written in 6 ms, absent from the table, absent from the
 * spool, absent from the parked file.
 *
 * It also explains a symptom that had looked unrelated: a parked count that stopped
 * growing while the condition continued. The rows had stopped being *seen* as
 * failures, not stopped failing.
 */
#[CoversClass(WriteSpool::class)]
class WriteSpoolSilentWriteFailureTest extends TestCase
{
    /**
     * A spool whose database returns $result from insert(), so the **real**
     * `writeNow()` is what is under test.
     */
    private function spoolWithDatabaseReturning(mixed $result, string $errorText = ''): object
    {
        $builder = new class($result) {
            public function __construct(private mixed $result)
            {
            }

            public function table(string $table): static
            {
                return $this;
            }

            public function insert(array $values): mixed
            {
                return $this->result;
            }
        };

        $database = new class($builder, $errorText) {
            public string $error_text;

            public function __construct(private object $builder, string $errorText)
            {
                $this->error_text = $errorText;
            }

            public function queryBuilder(): object
            {
                return $this->builder;
            }
        };

        return new class($database) extends WriteSpool {
            /** @var list<array<string,mixed>> */
            public static array $parkedRows = [];

            public static object $injected;

            public function __construct(object $database)
            {
                static::$injected   = $database;
                static::$parkedRows = [];
            }

            protected static function database(): object
            {
                return static::$injected;
            }

            protected static function park(
                string $table,
                array $row,
                string $error,
                int $attempts = 0
            ): void {
                static::$parkedRows[] = ['error' => $error, 'attempts' => $attempts];
            }

            public static function drive(string $table, array $entries): array
            {
                $stats    = ['written' => 0, 'failed' => 0, 'parked' => 0, 'tables' => [], 'errors' => []];
                $rejected = [];
                static::writeRowsIndividually($table, $entries, $stats, null, $rejected);

                return ['stats' => $stats, 'rejected' => $rejected];
            }
        };
    }

    /**
     * A write returning false is a failure, not a success — through the real
     * `writeNow()`.
     *
     * The assertion that matters is `written === 0`: anything above zero is what
     * deleted the row.
     */
    public function testAWriteReturningFalseIsNotCountedAsWritten(): void
    {
        // Arrange
        $spool = $this->spoolWithDatabaseReturning(
            false,
            'ERROR: current transaction is aborted, commands ignored until end of transaction block'
        );

        // Act
        $result = $spool::drive('tokenactions', [['row' => ['tokenid' => 844], 'attempts' => 0]]);

        // Assert
        $this->assertSame(0, $result['stats']['written'], 'a false return must never count as written');
        $this->assertSame(1, $result['stats']['failed']);
        $this->assertCount(1, $result['rejected'], 'and the row is kept rather than dropped');

        // The database's error text is carried, which was the only record of the cause
        // in the reported incident.
        $this->assertStringContainsString(
            'transaction is aborted',
            // `errors` is keyed by message with a count as the value, so the
            // messages are the keys.
            implode(' ', array_keys($result['stats']['errors']))
        );
    }

    /**
     * A successful write still counts.
     *
     * Without this the fix could be "treat every write as failed", which loses nothing
     * and writes nothing.
     */
    public function testASuccessfulWriteStillCounts(): void
    {
        // Arrange
        $spool = $this->spoolWithDatabaseReturning(true);

        // Act
        $result = $spool::drive('tokenactions', [['row' => ['tokenid' => 1], 'attempts' => 0]]);

        // Assert
        $this->assertSame(1, $result['stats']['written']);
        $this->assertSame(0, $result['stats']['failed']);
        $this->assertSame([], $result['rejected']);
    }

    /**
     * Only an exact `false` is a failure.
     *
     * `execute()` returns a truthy value on success and this must not start rejecting
     * one because it is `0`, `''` or `null` in some driver's hands — a write wrongly
     * treated as failed is retried and eventually parked, which is data delayed rather
     * than lost, but it is still a regression and worth pinning either way.
     */
    public function testOnlyAnExactFalseIsTreatedAsFailure(): void
    {
        foreach ([true, 1, 'ok'] as $result) {
            // Arrange
            $spool = $this->spoolWithDatabaseReturning($result);

            // Act & Assert
            $this->assertSame(
                1,
                $spool::drive('t', [['row' => ['a' => 1], 'attempts' => 0]])['stats']['written'],
                'return: ' . var_export($result, true)
            );
        }
    }

    /**
     * With no error text available, the failure still says what happened.
     *
     * The incident had exactly one log line and it was not this one, so a message that
     * degrades to nothing would have left the same gap it is closing.
     */
    public function testTheFailureIsNamedEvenWithoutDatabaseErrorText(): void
    {
        // Arrange
        $spool = $this->spoolWithDatabaseReturning(false, '');

        // Act
        $result = $spool::drive('tokenactions', [['row' => ['tokenid' => 1], 'attempts' => 0]]);

        // Assert
        $this->assertStringContainsString(
            'failed without throwing',
            implode(' ', array_keys($result['stats']['errors']))
        );
    }

    /**
     * "current transaction is aborted" is treated as retryable, not permanent.
     *
     * Correct for this incident: a fresh process per drain means the next attempt has
     * a clean transaction, so retrying is exactly right. Asserted so the constraint
     * classifier added a commit earlier cannot quietly start parking these.
     */
    public function testAnAbortedTransactionIsRetryableRatherThanParked(): void
    {
        // Arrange
        $method = new \ReflectionMethod(WriteSpool::class, 'isPermanentWriteFailure');

        // Act & Assert
        $this->assertFalse((bool) $method->invoke(null, new \RuntimeException(
            'Statement could not be prepared: ERROR: current transaction is aborted, '
            . 'commands ignored until end of transaction block'
        )));
    }
}
