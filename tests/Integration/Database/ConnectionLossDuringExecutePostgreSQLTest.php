<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same loss, against real PostgreSQL — where the retry works differently and had also never run.
 *
 * `pg_execute()` returns false rather than throwing, so this lane never had the unreachable-branch
 * problem the MySQL one did. What it has instead is a different question to answer: the retry is
 * gated on `isConnectionAlive()`, which for PostgreSQL asks `pg_connection_status()` — and that
 * reports `BAD` only after an operation has actually failed on the handle. So the order matters here
 * in a way it does not on MySQL, and nothing had ever established that the order is right.
 *
 * A terminated backend is also more common on this engine than on the other: a pooler recycling a
 * connection, an idle-in-transaction timeout, and `pg_terminate_backend()` from an operator clearing
 * a lock all produce exactly this.
 */
class ConnectionLossDuringExecutePostgreSQLTest extends ConnectionLossDuringExecuteTest
{
    /**
     * PostgreSQL, in the timescaledb container.
     *
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => TEST_DATABASE,
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }

    /**
     * The id of the row just inserted is read in the session that inserted it, after the
     * connection was lost and found again: `getInsertId()`'s `currval()` must not land in another
     * session, where the sequence was never used.
     *
     * A scheduler running when PostgreSQL restarted failed every run log entry afterwards with
     * «currval of sequence "scheduleruns_id_seq" is not yet defined in this session».
     */
    public function testTheInsertIdIsReadInTheSessionThatInsertedAfterAReconnect(): void
    {
        // Arrange — a table with a serial id, and the connection killed from outside
        $this->db->query('CREATE TABLE IF NOT EXISTS reconnect_serial (id SERIAL PRIMARY KEY, label VARCHAR(32) NOT NULL)');
        try {
            $pid    = (string) $this->db->query('SELECT pg_backend_pid() AS identity')->fields['identity'];
            $killer = static::openConnection();
            $killer->query('SELECT pg_terminate_backend(' . (int) $pid . ')');
            for ($attempt = 0; $attempt < 20 && $killer->query('SELECT 1 AS present FROM pg_stat_activity WHERE pid = ' . (int) $pid)->numRows > 0; $attempt++) {
                usleep(10000);
            }
            $killer->close();

            // Act
            $this->db->query("INSERT INTO reconnect_serial (label) VALUES ('after the loss')");
            $id = $this->db->getInsertId();

            // Assert
            $this->assertNotFalse($id, 'currval() ran in a session that never used the sequence');
            $this->assertSame('after the loss', $this->db->query('SELECT label FROM reconnect_serial WHERE id = ' . (int) $id)->fields['label']);
        } finally {
            $this->db->query('DROP TABLE IF EXISTS reconnect_serial');
        }
    }

    /**
     * Asked for an id where this session took none — an INSERT that gave its own id — the read
     * raises, as any failed statement does, rather than answering false as though nothing went
     * wrong.
     *
     * An id of false is read as "no row", and a caller that ties a body or a redirect to it ties
     * it to nothing, silently.
     */
    public function testAnInsertIdThatCannotBeReadRaises(): void
    {
        // Arrange — a fresh session, and an INSERT that names its id, so no sequence is used
        $this->db->query('CREATE TABLE IF NOT EXISTS reconnect_serial (id SERIAL PRIMARY KEY, label VARCHAR(32) NOT NULL)');
        $fresh = static::openConnection();
        try {
            $fresh->query("INSERT INTO reconnect_serial (id, label) VALUES (900, 'named')");

            // Assert
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('lastval is not yet defined in this session');

            // Act
            $fresh->getInsertId();
        } finally {
            $fresh->close();
            $this->db->query('DROP TABLE IF EXISTS reconnect_serial');
        }
    }
}
