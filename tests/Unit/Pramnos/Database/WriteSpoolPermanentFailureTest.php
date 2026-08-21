<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\WriteSpool;

/**
 * A spooled row whose failure cannot resolve itself is parked early.
 *
 * `writeRowsIndividually()` has always carried the reasoning — *"a row whose foreign
 * key was deleted while it waited cannot become writable by being tried again"* — and
 * then applied it only at the fifth attempt. So a deployment spent five minutes of
 * failing scheduled task establishing what the first error had already said:
 *
 *     Key (tokenid)=(844) is not present in table "usertokens"
 *
 * 40 rows, once a minute, `spool:drain` exiting 1 each time. And while it ran, the
 * scheduler's error counter read "3 in 200 seconds", which is one task failing three
 * times and reads as three tasks failing once — the ambiguity that cost the reporter
 * an afternoon before they got to the counter.
 */
#[CoversClass(WriteSpool::class)]
class WriteSpoolPermanentFailureTest extends TestCase
{
    /** Reach the classifier, which is protected because nothing outside should ask. */
    private function isPermanent(string $message): bool
    {
        $method = new \ReflectionMethod(WriteSpool::class, 'isPermanentWriteFailure');

        return (bool) $method->invoke(null, new \RuntimeException($message));
    }

    /**
     * The failure that prompted this is classified as permanent.
     *
     * Verbatim from the reporter's spool file, TimescaleDB chunk name included, because
     * that is the string the classifier has to survive rather than a tidied version of
     * it.
     */
    public function testTheReportedForeignKeyFailureIsPermanent(): void
    {
        // Assert
        $this->assertTrue($this->isPermanent(
            'insert or update on table "_hyper_12_9_chunk" violates foreign key '
            . 'constraint "9_10_fk_tokenactions_tokenid" DETAIL: Key (tokenid)=(844) '
            . 'is not present in table "usertokens".'
        ));
    }

    /**
     * The constraint classes both engines report are covered.
     */
    public function testConstraintViolationsAreClassifiedAsPermanent(): void
    {
        foreach ([
            'violates foreign key constraint "fk_x"',
            'violates unique constraint "uq_x"',
            'violates not-null constraint',
            'violates check constraint "ck_x"',
            "Duplicate entry 'x' for key 'PRIMARY'",
            'Cannot add or update a child row: a foreign key constraint fails',
            'Cannot delete or update a parent row: a foreign key constraint fails',
            'SQLSTATE[23505]: Unique violation: 7 ERROR',
            'SQLSTATE[23000]: Integrity constraint violation',
        ] as $message) {
            // Assert
            $this->assertTrue($this->isPermanent($message), $message);
        }
    }

    /**
     * Everything the retry budget exists for stays retryable.
     *
     * This is the half that matters more. A false negative costs the extra attempts
     * this classifier exists to avoid; a **false positive parks a row that would have
     * been written** — so the test is weighted towards the failures that must not be
     * misread.
     */
    public function testTransientFailuresStayRetryable(): void
    {
        foreach ([
            'server closed the connection unexpectedly',
            'deadlock detected',
            'could not serialize access due to concurrent update',
            'canceling statement due to lock timeout',
            'too many connections',
            'No space left on device',
            'SQLSTATE[08006]: Connection failure',
            'SQLSTATE[40001]: Serialization failure',
            'Lost connection to MySQL server during query',
        ] as $message) {
            // Assert
            $this->assertFalse($this->isPermanent($message), $message);
        }
    }

    /**
     * The permanent budget is two, not one.
     *
     * Deliberately not one: the spool groups rows by table and has no dependency
     * ordering, so a child row can legitimately fail because its parent is still in
     * the spool. One retry covers a parent landing in the next drain; a budget of one
     * would park rows that were about to become writable.
     */
    public function testThePermanentBudgetLeavesRoomForAParentStillInTheSpool(): void
    {
        // Assert
        $this->assertSame(2, WriteSpool::CONSTRAINT_MAX_ATTEMPTS);
        $this->assertGreaterThan(
            WriteSpool::CONSTRAINT_MAX_ATTEMPTS,
            WriteSpool::DEFAULT_MAX_ATTEMPTS,
            'a permanent failure must be given fewer attempts than a transient one'
        );
    }

    /**
     * A constraint violation is parked on its second attempt; a transient failure is
     * requeued and keeps its full budget.
     *
     * Driven through `writeRowsIndividually()` with `persist()` overridden, so the
     * decision under test is the retry branch rather than a database.
     */
    public function testAConstraintViolationIsParkedEarlyAndATransientOneIsNot(): void
    {
        // Arrange
        $spool = new class extends WriteSpool {
            /** @var list<array{table:string,error:string,attempts:int}> */
            public static array $parkedRows = [];

            public static string $failWith = '';

            protected static function persist(string $table, array $row): void
            {
                throw new \RuntimeException(static::$failWith);
            }

            protected static function park(
                string $table,
                array $row,
                string $error,
                int $attempts = 0
            ): void {
                static::$parkedRows[] = [
                    'table'    => $table,
                    'error'    => $error,
                    'attempts' => $attempts,
                ];
            }

            /**
             * @param  list<array<string,mixed>> $entries
             * @return array{stats: array<string,mixed>, rejected: list<string>}
             */
            public static function drive(string $table, array $entries): array
            {
                $stats    = ['written' => 0, 'failed' => 0, 'parked' => 0, 'tables' => [], 'errors' => []];
                $rejected = [];

                static::writeRowsIndividually($table, $entries, $stats, null, $rejected);

                return ['stats' => $stats, 'rejected' => $rejected];
            }
        };

        $row = ['tokenid' => 844];

        // Act — a constraint violation on its second attempt
        $spool::$parkedRows = [];
        $spool::$failWith   = 'violates foreign key constraint "fk_tokenactions_tokenid"';
        $permanent = $spool::drive('tokenactions', [['row' => $row, 'attempts' => 1]]);

        // Assert
        $this->assertSame(1, $permanent['stats']['parked'], 'parked on attempt 2, not 5');
        $this->assertSame([], $permanent['rejected'], 'and not requeued');
        $this->assertSame(2, $spool::$parkedRows[0]['attempts'], 'recording what was spent');

        // Act — a transient failure at the same attempt count
        $spool::$parkedRows = [];
        $spool::$failWith   = 'server closed the connection unexpectedly';
        $transient = $spool::drive('tokenactions', [['row' => $row, 'attempts' => 1]]);

        // Assert
        $this->assertSame(0, $transient['stats']['parked'], 'still inside its budget');
        $this->assertCount(1, $transient['rejected'], 'and requeued for the next run');
    }

    /**
     * A constraint violation on its *first* attempt is still requeued once.
     *
     * The parent-still-in-the-spool case, asserted from the other side so the budget
     * cannot quietly become one.
     */
    public function testAConstraintViolationGetsOneRetryFirst(): void
    {
        // Arrange
        $spool = new class extends WriteSpool {
            public static array $parkedRows = [];

            protected static function persist(string $table, array $row): void
            {
                throw new \RuntimeException('violates foreign key constraint "fk_x"');
            }

            protected static function park(
                string $table,
                array $row,
                string $error,
                int $attempts = 0
            ): void {
                static::$parkedRows[] = $attempts;
            }

            public static function drive(string $table, array $entries): array
            {
                $stats    = ['written' => 0, 'failed' => 0, 'parked' => 0, 'tables' => [], 'errors' => []];
                $rejected = [];
                static::writeRowsIndividually($table, $entries, $stats, null, $rejected);

                return ['stats' => $stats, 'rejected' => $rejected];
            }
        };

        // Act — never tried before
        $spool::$parkedRows = [];
        $result = $spool::drive('tokenactions', [['row' => ['tokenid' => 844], 'attempts' => 0]]);

        // Assert
        $this->assertSame(0, $result['stats']['parked']);
        $this->assertCount(1, $result['rejected'], 'one retry, in case the parent is still queued');
    }
}
