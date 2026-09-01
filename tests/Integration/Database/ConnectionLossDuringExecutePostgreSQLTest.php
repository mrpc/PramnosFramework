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
            'database' => 'pramnos_test',
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }
}
