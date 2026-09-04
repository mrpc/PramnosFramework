<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * A migration whose statements were rejected must not read as one that worked.
 *
 * WHAT: `executeQueries()` records every statement the database refused, and a
 *       migration exposes them, so the ledger can carry a third state instead of
 *       reporting success.
 * WHY:  the tolerance in `executeQueries()` is deliberate and has to stay — a
 *       re-run whose `ADD COLUMN` is already applied must not abandon the
 *       statements behind it, and installations exist with a hundred-odd numbered
 *       migrations depending on that. But `up()` returning was the only thing
 *       either ledger looked at, so a migration whose *every* statement failed
 *       was indistinguishable from one that succeeded. Two real cases: a
 *       `DROP MATERIALIZED VIEW` that needed `CASCADE` and dropped nothing, and
 *       115 `INSERT`s naming a column that did not exist yet. Both recorded as
 *       successes; the only trace was a log nothing points at.
 *
 * Nothing here asserts that a migration now throws or is skipped. That is the
 * point: the fix is visibility, not intolerance.
 */
#[CoversClass(Migration::class)]
#[CoversClass(MigrationRunner::class)]
class MigrationFailureVisibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }
    }

    // =========================================================================
    // executeQueries() records what was refused
    // =========================================================================

    /**
     * A rejected statement is remembered, and the rest still run.
     *
     * The second half is the pre-existing contract and is asserted here too,
     * because a fix that recorded failures by stopping at the first one would
     * satisfy the first half and break every re-run.
     */
    public function testARejectedStatementIsRecordedAndTheRestStillRun(): void
    {
        // Arrange
        $executed = [];
        $db = $this->db(function (string $sql) use (&$executed) {
            $executed[] = $sql;
            if ($sql === 'BROKEN SQL') {
                throw new \Exception('syntax error at or near "BROKEN"');
            }
            return new \stdClass();
        });
        $migration = new FailureProbeMigration($this->app($db));
        $migration->queue('CREATE TABLE one (id INT)');
        $migration->queue('BROKEN SQL');
        $migration->queue('DROP TABLE one');

        // Act
        $failures = $migration->runExecute();

        // Assert — every statement was attempted
        $this->assertSame(
            ['CREATE TABLE one (id INT)', 'BROKEN SQL', 'DROP TABLE one'],
            $executed,
            'the tolerance must survive: a rejected statement does not stop the rest'
        );

        // Assert — and the one that failed is now on the record
        $this->assertSame(1, $failures);
        $this->assertTrue($migration->hasFailedStatements());
        $recorded = $migration->getFailedStatements();
        $this->assertCount(1, $recorded);
        $this->assertSame('BROKEN SQL', $recorded[0]['query']);
        $this->assertStringContainsString('syntax error', $recorded[0]['error']);
        $this->assertFalse($recorded[0]['benign'], 'a syntax error is not "already applied"');
    }

    /**
     * A statement that returns false without throwing counts as a failure too.
     *
     * `Database::query()` has one path — a statement that cannot be prepared —
     * that logs and returns false instead of throwing. A check that only caught
     * exceptions would keep missing exactly that case, and it is the quietest
     * one.
     */
    public function testAFalseReturnCountsAsAFailure(): void
    {
        // Arrange
        $migration = new FailureProbeMigration($this->app($this->db(fn() => false)));
        $migration->queue('SELECT * FROM a_table_that_cannot_be_prepared');

        // Act
        $failures = $migration->runExecute();

        // Assert
        $this->assertSame(1, $failures);
        $this->assertStringContainsString(
            'could not be prepared',
            $migration->getFailedStatements()[0]['error']
        );
    }

    /**
     * Nothing rejected means nothing recorded, and an empty summary.
     *
     * The complement: callers branch on `failedStatementSummary() !== ''`, so a
     * summary that was non-empty on success would mark every migration.
     */
    public function testAClearRunRecordsNothing(): void
    {
        // Arrange
        $migration = new FailureProbeMigration($this->app($this->db(fn() => new \stdClass())));
        $migration->queue('CREATE TABLE one (id INT)');
        $migration->queue('CREATE TABLE two (id INT)');

        // Act
        $failures = $migration->runExecute();

        // Assert
        $this->assertSame(0, $failures);
        $this->assertFalse($migration->hasFailedStatements());
        $this->assertSame('', $migration->failedStatementSummary());
    }

    /**
     * The summary names how many of how many, and flags the benign ones.
     *
     * The count is what makes a report actionable — "3 of 14" reads very
     * differently from "14 of 14", and the second is the case that cost an
     * afternoon.
     */
    public function testTheSummaryCountsAttemptsAndSeparatesTheBenign(): void
    {
        // Arrange — one redundant column, one genuine defect, one that worked
        $db = $this->db(function (string $sql) {
            if (str_contains($sql, 'ADD COLUMN')) {
                throw new \Exception('ERROR:  column "email" of relation "users" already exists');
            }
            if (str_contains($sql, 'no_such_table')) {
                throw new \Exception('ERROR:  relation "no_such_table" does not exist');
            }
            return new \stdClass();
        });
        $migration = new FailureProbeMigration($this->app($db));
        $migration->queue('ALTER TABLE users ADD COLUMN email VARCHAR(255)');
        $migration->queue('SELECT 1 FROM no_such_table');
        $migration->queue('CREATE TABLE fine (id INT)');

        // Act
        $migration->runExecute();
        $summary = $migration->failedStatementSummary();

        // Assert
        $this->assertStringContainsString('2 of 3 statements failed', $summary);
        $this->assertStringContainsString('1 look like work already applied', $summary);
        // Both messages are reachable from the ledger row, which is the whole
        // point — the alternative was var/logs/upgradeerrors.log.
        $this->assertStringContainsString('already exists', $summary);
        $this->assertStringContainsString('does not exist', $summary);
    }

    // =========================================================================
    // The benign/defect label
    // =========================================================================

    /**
     * "Already applied" is recognised by MySQL's errno and PostgreSQL's text.
     *
     * The asymmetry is not an oversight. MySQL's own `mysqli_sql_exception`
     * propagates with the real errno (1050, 1060, …); PostgreSQL failures reach
     * us as a plain `Exception` whose code is `0`, because `Database::setError()`
     * is called with error number `0` on that driver and no SQLSTATE is captured
     * anywhere. Text is all there is on one side.
     *
     * @param string $message driver message
     * @param int    $code    driver code, 0 when there is none
     * @param bool   $benign  expected label
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failureClassifications')]
    public function testFailuresAreLabelledButNotActedOn(string $message, int $code, bool $benign): void
    {
        // Arrange
        $migration = new FailureProbeMigration($this->app($this->db(
            function () use ($message, $code) {
                throw new \Exception($message, $code);
            }
        )));
        $migration->queue('SOME STATEMENT');

        // Act
        $migration->runExecute();

        // Assert — labelled as expected, and recorded either way
        $recorded = $migration->getFailedStatements();
        $this->assertCount(1, $recorded, 'every failure is recorded, benign or not');
        $this->assertSame($benign, $recorded[0]['benign']);
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function failureClassifications(): array
    {
        return [
            'mysql duplicate column'   => ["Duplicate column name 'email'", 1060, true],
            'mysql table exists'       => ["Table 'users' already exists", 1050, true],
            'mysql duplicate key'      => ["Duplicate key name 'idx'", 1061, true],
            'mysql cannot drop'        => ["Can't DROP 'idx'; check that it exists", 1091, true],
            'mysql missing table'      => ["Table 'db.gone' doesn't exist", 1146, false],
            'postgres relation exists' => ['ERROR:  relation "users" already exists', 0, true],
            'postgres column exists'   => ['ERROR:  column "e" of relation "u" already exists', 0, true],
            'postgres missing relation' => ['ERROR:  relation "gone" does not exist', 0, false],
            'postgres not a view'      => ['ERROR:  "usage_statistics" is not a view', 0, false],
            'unprepared statement'     => ['the statement could not be prepared', 0, false],
        ];
    }

    // =========================================================================
    // The runner's third state
    // =========================================================================

    /**
     * A migration that completed with rejected statements records `result = 2`.
     *
     * WHAT: `up()` returns, statements were refused, and the history row says
     *       `RESULT_RAN_WITH_ERRORS` with the detail in `error_message`.
     * WHY:  `RESULT_OK` and `RESULT_FAILED` could not express this, and the case
     *       fell into `RESULT_OK` — so `migrate:status` said `Ran`. The migration
     *       is still reported in `ran`, because it did run and must not re-run for
     *       ever, and in `warned`, because it is not a success.
     */
    public function testTheRunnerRecordsAThirdStateRatherThanSuccess(): void
    {
        // Arrange — a runner over a migration whose one statement is refused
        $recorded = [];
        $db = $this->recordingDb(
            $recorded,
            'ADD COLUMN email',
            'ERROR:  column "email" of relation "users" already exists'
        );
        $migration = new SelfQueueingMigration($this->app($db));
        $migration->statement = 'ALTER TABLE users ADD COLUMN email VARCHAR(255)';

        $runner = new MigrationRunner($db);

        // Act
        $result = $runner->run([$migration]);

        // Assert — it ran, and it is flagged
        $this->assertSame(['create_probe_thing'], $result['ran']);
        $this->assertSame([], $result['failed']);
        $this->assertArrayHasKey('create_probe_thing', $result['warned']);
        $this->assertStringContainsString('1 of 1 statements failed', $result['warned']['create_probe_thing']);

        // Assert — the ledger says so too, which is where migrate:status reads it
        $insert = $this->lastHistoryInsert($recorded);
        $this->assertNotSame('', $insert, 'the runner must have written a history row');
        $this->assertStringContainsString('already exists', $insert);
        $this->assertMatchesRegularExpression(
            '/,\s*2\s*,/',
            $insert,
            'result must be RESULT_RAN_WITH_ERRORS (2), not 1'
        );
    }

    /**
     * A clean migration still records `result = 1`.
     *
     * Without this, a change that marked everything as "ran with errors" would
     * pass the test above.
     */
    public function testACleanMigrationStillRecordsSuccess(): void
    {
        // Arrange
        $recorded = [];
        $db = $this->recordingDb($recorded);
        $migration = new SelfQueueingMigration($this->app($db));
        $migration->statement = 'CREATE TABLE probe (id INT)';

        // Act
        $result = (new MigrationRunner($db))->run([$migration]);

        // Assert
        $this->assertSame(['create_probe_thing'], $result['ran']);
        $this->assertSame([], $result['warned']);
        $this->assertMatchesRegularExpression(
            '/,\s*1\s*,/',
            $this->lastHistoryInsert($recorded),
            'result must be RESULT_OK (1)'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A Database whose query() runs the given callback.
     *
     * @return Database&\PHPUnit\Framework\MockObject\MockObject
     */
    private function db(callable $onQuery): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->type = 'mysql';
        $db->method('query')->willReturnCallback($onQuery);

        return $db;
    }

    /**
     * A Database that answers everything blandly and remembers the SQL it saw.
     *
     * Enough for MigrationRunner: ensureHistoryTable(), nextBatch() and
     * getRanSlugs() all read through query()/prepareQuery().
     *
     * @param array<int, string> $recorded Filled with every statement issued.
     * @param string|null $failOn Substring of the statement that must be refused.
     * @param string      $error  What the driver says when it refuses it.
     * @return Database&\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingDb(array &$recorded, ?string $failOn = null, string $error = ''): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery', 'schema'])
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $empty = new class {
            public array $fields  = ['max_batch' => null, 'cnt' => 0];
            public int   $numRows = 0;
            public function fetch(): bool { return false; }
            public function fetchAll(): array { return []; }
        };

        // prepareQuery is a sprintf-alike; the values are what the assertions read,
        // so they have to end up in the string.
        $db->method('prepareQuery')->willReturnCallback(
            function (string $sql, ...$args): string {
                foreach ($args as $arg) {
                    $sql = preg_replace(
                        '/%[sdf]/',
                        is_string($arg) ? addslashes($arg) : (string) $arg,
                        $sql,
                        1
                    ) ?? $sql;
                }
                return $sql;
            }
        );
        $db->method('query')->willReturnCallback(
            function ($sql) use (&$recorded, $empty, $failOn, $error) {
                $recorded[] = is_string($sql) ? $sql : '(object)';
                if ($failOn !== null && is_string($sql) && str_contains($sql, $failOn)) {
                    throw new \Exception($error);
                }
                return $empty;
            }
        );
        $db->method('schema')->willReturn(new class {
            public function hasColumn(string $t, string $c): bool { return true; }
            public function hasTable(string $t, ?string $s = null): bool { return true; }
        });

        return $db;
    }

    /**
     * The last INSERT into the history table out of everything issued.
     *
     * @param array<int, string> $recorded
     */
    private function lastHistoryInsert(array $recorded): string
    {
        foreach (array_reverse($recorded) as $sql) {
            if (stripos($sql, 'INSERT INTO') !== false
                && stripos($sql, 'schemaversion') !== false
            ) {
                return $sql;
            }
        }

        return '';
    }

    private function app(Database $db): Application
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $application->database = $db;

        return $application;
    }
}

/**
 * Exposes the protected queue so the recording can be asserted directly.
 */
class FailureProbeMigration extends Migration
{
    public function queue(string $query): void
    {
        $this->addQuery($query);
    }

    /** @return int failures, as executeQueries() now reports them */
    public function runExecute(): int
    {
        return $this->executeQueries();
    }
}

/**
 * A migration whose `up()` queues one statement, optionally a doomed one.
 *
 * Shaped for the runner: it needs a slug, and `up()` must return normally even
 * when the statement is refused — which is the exact situation under test.
 */
class SelfQueueingMigration extends Migration
{
    public string $statement = 'SELECT 1';

    public function getSlug(): string
    {
        return 'create_probe_thing';
    }

    public function getTimestamp(): ?string
    {
        return null;
    }

    public function up(): void
    {
        $this->addQuery($this->statement);
        $this->executeQueries();
    }
}
