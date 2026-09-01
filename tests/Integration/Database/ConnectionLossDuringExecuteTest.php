<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * A prepared statement whose connection died underneath it.
 *
 * `execute()` carries a re-prepare-and-retry path for exactly this, and it had never run. Twenty-five
 * of its statements had never executed once across the whole suite — not error handling nobody cares
 * about, but the branch that decides whether a long-lived process survives the night.
 *
 * ## Why it matters more than its line count
 *
 * Every connection has an idle timeout — MySQL's `wait_timeout` is eight hours by default, a managed
 * PostgreSQL or a pooler is usually far less — and the processes that hold a handle longest are the
 * ones nobody is watching: a queue worker, a scheduled command, a daemon. They prepare a statement
 * once and execute it for hours. The failure without this path is not an exception in a request
 * somebody sees; it is a worker that stops doing its job quietly, and a queue that grows.
 *
 * A restarted database, a failover, a `KILL` from an operator clearing a lock, and a pooler recycling
 * a backend all produce the same thing this tests.
 *
 * ## How the connection is actually killed
 *
 * From a **second** connection, using the server's own facility: `KILL <id>` on MySQL,
 * `pg_terminate_backend(<pid>)` on PostgreSQL. Not by closing the handle from PHP, which the driver
 * knows about and which is therefore a different code path — the point is a connection that PHP still
 * believes in and the server has already forgotten. That is what production hands you.
 */
class ConnectionLossDuringExecuteTest extends DatabaseTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return ['reconnect_probe'];
    }

    /** @return string[] */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE reconnect_probe (
                id INTEGER NOT NULL,
                label VARCHAR(32) NOT NULL
            )',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query("INSERT INTO reconnect_probe (id, label) VALUES (1, 'before')");
    }

    /**
     * The server's own name for the connection the statement is using.
     *
     * Read through `query()` rather than from the handle, so it is the connection this `Database`
     * will actually reach for — read and write are separate handles here, and killing the wrong one
     * proves nothing.
     */
    private function connectionIdentity(): string
    {
        $sql = ($this->db->type ?? '') === 'postgresql'
            ? 'SELECT pg_backend_pid() AS identity'
            : 'SELECT CONNECTION_ID() AS identity';

        return (string) $this->db->query($sql)->fields['identity'];
    }

    /** Kill it from somewhere else, the way an operator or a pooler would. */
    private function killFromOutside(string $identity): void
    {
        $isPostgres = ($this->db->type ?? '') === 'postgresql';
        $killer = static::openConnection();

        if ($isPostgres) {
            $killer->query('SELECT pg_terminate_backend(' . (int) $identity . ')');
        } else {
            $killer->query('KILL ' . (int) $identity);
        }

        /*
         * Waited out by asking the server, not by sleeping.
         *
         * The kill is asynchronous, so something has to establish that it has landed before the test
         * proceeds — and a fixed `usleep()` is both slower than it needs to be and still a guess. The
         * server knows: the connection is gone when it is no longer listed. Twenty polls at 10ms is a
         * 200ms ceiling that is almost never reached.
         */
        $stillThere = $isPostgres
            ? 'SELECT 1 AS present FROM pg_stat_activity WHERE pid = ' . (int) $identity
            : 'SELECT 1 AS present FROM information_schema.processlist WHERE id = '
                . (int) $identity;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ($killer->query($stillThere)->numRows === 0) {
                break;
            }

            usleep(10000);
        }

        $killer->close();
    }

    /**
     * A statement prepared before the connection died still executes, and returns real rows.
     *
     * The assertion that matters is the *rows*, not the absence of an exception. A retry that
     * reconnected and returned an empty result would look like «the table is empty» to every caller
     * above it — which is worse than an error, because a worker acting on nothing looks like a worker
     * with nothing to do.
     */
    public function testAStatementSurvivesLosingItsConnection(): void
    {
        // Arrange — prepared and proven to work before anything is killed
        $statement = $this->db->prepare('SELECT label FROM reconnect_probe WHERE id = 1');
        $this->assertNotFalse($statement, 'prepare() failed before the test began');

        $before = $this->db->execute($statement);
        $this->assertSame('before', $before->fields['label']);

        $identity = $this->connectionIdentity();
        $this->assertNotSame('', $identity, 'the server did not name its own connection');

        // Act
        $this->killFromOutside($identity);
        $after = $this->db->execute($statement);

        // Assert
        $this->assertNotFalse($after, 'execute() gave up instead of re-preparing');
        $this->assertSame(
            'before',
            $after->fields['label'],
            'the retry reconnected and returned nothing, which reads as an empty table'
        );
    }

    /**
     * And the reconnected handle is a working handle, not one good for a single statement.
     *
     * The retry re-prepares on a new connection and assigns it back to `$statement`/`$connection`
     * inside the loop. If that assignment were incomplete — a new connection used for this execute
     * and then dropped — the *second* statement after the loss would fail, and a test that stopped at
     * the first would pass over it.
     */
    public function testTheHandleKeepsWorkingAfterTheRetry(): void
    {
        // Arrange
        $statement = $this->db->prepare('SELECT label FROM reconnect_probe WHERE id = 1');
        $this->db->execute($statement);

        // Act
        $this->killFromOutside($this->connectionIdentity());
        $this->db->execute($statement);

        // …and now go on using the connection for entirely new work
        $this->db->query("INSERT INTO reconnect_probe (id, label) VALUES (2, 'after')");
        $rows = $this->db->query('SELECT label FROM reconnect_probe ORDER BY id');

        // Assert
        $this->assertSame(2, $rows->numRows, 'the connection was only good for the retried statement');
    }

    /**
     * A write is retried too, and lands exactly once.
     *
     * The half of a retry that has to be got right: re-running a statement whose result was never
     * seen is safe for a `SELECT` and is a duplicate for an `INSERT`. The retry fires only when the
     * driver reports the connection as gone — 2006/2013 on MySQL, a dead handle on PostgreSQL — which
     * is the one case where the server cannot have applied the statement. This is what pins that
     * distinction, and it is the assertion that would catch a well-meaning «retry on any error».
     */
    public function testARetriedWriteLandsOnce(): void
    {
        // Arrange
        $statement = $this->db->prepare(
            "INSERT INTO reconnect_probe (id, label) VALUES (7, 'written')"
        );

        // Act
        $this->killFromOutside($this->connectionIdentity());
        $this->db->execute($statement);

        // Assert
        $rows = $this->db->query('SELECT id FROM reconnect_probe WHERE id = 7');
        $this->assertSame(1, $rows->numRows, 'the retried write was applied twice, or not at all');
    }
}
