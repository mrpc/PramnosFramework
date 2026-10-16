<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * A moment written to, compared with and read from a `timestampTz` column the same way on MySQL,
 * MariaDB and PostgreSQL: as a Unix time the database converts itself.
 *
 * ```php
 * $db->queryBuilder()->table('authserver.audit_log')->insert(['event_timestamp' => Instant::at($db, time())]);
 * $query->where('event_timestamp', '>=', Instant::at($db, $since));
 * $query->select(['auditid', Instant::unix($db, 'event_timestamp', 'at')]);
 * ```
 *
 * A string carries a zone question with it. `date('c')` writes an offset that MariaDB drops
 * (warning 1292; strict mode refuses the row), and a string without one is read in the session's
 * zone, which PHP does not know. `FROM_UNIXTIME()` and `to_timestamp()` take the moment itself, and
 * `UNIX_TIMESTAMP()` / `EXTRACT(EPOCH …)` give it back, whatever zone the session is in.
 */
final class Instant
{
    /** A Unix time as the database's own timestamp. */
    public static function at(Database $db, int $unix): Expression
    {
        return new Expression(self::postgres($db) ? 'to_timestamp(' . $unix . ')' : 'FROM_UNIXTIME(' . $unix . ')');
    }

    /** A timestamp column read back as a Unix time, under `$alias`. */
    public static function unix(Database $db, string $column, string $alias): Expression
    {
        $column = preg_replace('/[^A-Za-z0-9_.]/', '', $column);
        $alias  = preg_replace('/[^A-Za-z0-9_]/', '', $alias);

        return new Expression(self::postgres($db)
            ? 'CAST(EXTRACT(EPOCH FROM ' . $column . ') AS BIGINT) AS ' . $alias
            : 'UNIX_TIMESTAMP(' . $column . ') AS ' . $alias);
    }

    private static function postgres(Database $db): bool
    {
        return in_array(strtolower((string) $db->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
    }
}
