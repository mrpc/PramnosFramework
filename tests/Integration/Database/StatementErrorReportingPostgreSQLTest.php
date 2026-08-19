<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * A failing statement is reported as itself, on PostgreSQL.
 *
 * Two properties survive a statement and are read by the error path: `error_text`,
 * captured when `prepare()` fails, and `currentQuery`, appended to the exception
 * `setError()` throws. Neither was tied to the statement being attempted —
 * `error_text` was only ever written when it was still empty, so the first failure of
 * a request answered for every failure after it, and `currentQuery` was set by
 * `query()` alone, so anything raised from a prepared statement quoted whichever
 * unprepared query had run last.
 *
 * Together they produced a message that named a real query and a real error belonging
 * to two different statements minutes apart. That is worse than no message: it sends
 * the reader to the wrong file. It cost this repository a diagnosis — a DevPanel panel
 * failing on a PostgreSQL syntax error was reported as a bind-parameter mismatch in the
 * session INSERT from application boot.
 */
class StatementErrorReportingPostgreSQLTest extends DatabaseTestCase
{
    /**
     * PostgreSQL, in the timescaledb container.
     *
     * @return array<string, mixed> Connection properties
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }

    /**
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['err_reporting'];
    }

    /**
     * One table with a unique key, so an INSERT can fail at execution time rather
     * than at prepare time — the two errors travel by different paths.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            "CREATE TABLE err_reporting (
                id INTEGER NOT NULL,
                label VARCHAR(32) NOT NULL,
                PRIMARY KEY (id)
            )",
        ];
    }

    /**
     * The second failure is reported with the second failure's message.
     *
     * The staleness, reduced: two statements that cannot be prepared, naming two
     * tables that do not exist. Before the fix the second was described with the
     * first one's text, because `error_text` was written only while empty.
     *
     * @return void
     */
    public function testEachFailureIsReportedWithItsOwnMessage(): void
    {
        // Arrange + Act — fail once...
        $this->attemptFailingStatement('SELECT * FROM err_missing_one');
        $first = $this->db->error_text;

        // ...then fail differently
        $this->attemptFailingStatement('SELECT * FROM err_missing_two');
        $second = $this->db->error_text;

        // Assert — each names its own table
        $this->assertStringContainsString('err_missing_one', $first);
        $this->assertStringContainsString('err_missing_two', $second);
        $this->assertStringNotContainsString(
            'err_missing_one',
            $second,
            'the second failure must not be described with the first one\'s message'
        );
    }

    /**
     * A statement that succeeds leaves no error behind it.
     *
     * `error_text` is documented as the last error, and code reads it after a call
     * returns false. A value that outlives its statement turns that read into a
     * report of something that already happened and was already handled.
     *
     * @return void
     */
    public function testASuccessfulStatementClearsThePreviousError(): void
    {
        // Arrange
        $this->attemptFailingStatement('SELECT * FROM err_missing_one');
        $this->assertNotSame('', $this->db->error_text, 'the failure must register at all');

        // Act
        $this->db->execute('SELECT 1 AS ok FROM err_reporting');

        // Assert
        $this->assertSame('', $this->db->error_text);
    }

    /**
     * An execution failure quotes the statement that failed.
     *
     * A duplicate key passes `prepare()` and fails on execution, which is the path
     * that goes through `setError(..., false)` — the one that appends `currentQuery`
     * to the exception message. An unrelated `query()` runs first precisely because
     * that is what used to be quoted.
     *
     * @return void
     */
    public function testAnExecutionFailureQuotesItsOwnStatement(): void
    {
        // Arrange — a row to collide with, and an unrelated query after it
        $this->db->query("INSERT INTO err_reporting (id, label) VALUES (1, 'first')");
        $this->db->query('SELECT id FROM err_reporting');

        // Act
        $message = '';
        try {
            $this->db->execute(
                "INSERT INTO err_reporting (id, label) VALUES (1, 'duplicate')"
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        // Assert — the message names the INSERT that failed...
        $this->assertNotSame('', $message, 'a duplicate key must be reported');
        $this->assertStringContainsString('duplicate', $message);
        // ...and not the SELECT that merely happened to run before it
        $this->assertStringNotContainsString('SELECT id FROM err_reporting', $message);
    }

    /**
     * Attempt a statement that cannot be prepared, absorbing however this driver
     * reports it.
     *
     * PostgreSQL returns false from `prepare()`; MySQL throws
     * `mysqli_sql_exception` from it, and that throw is load-bearing for existing
     * callers. Both leave the message in `error_text`, which is what these tests read.
     *
     * @param string $sql A statement referencing a table that does not exist
     * @return void
     */
    private function attemptFailingStatement(string $sql): void
    {
        try {
            $this->db->execute($sql);
        } catch (\Throwable) {
            // The message is asserted through error_text, not through the throw:
            // the two drivers disagree about whether there is one.
        }
    }
}
