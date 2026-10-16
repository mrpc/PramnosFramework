<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * A moment written to, compared with and read from a `timestampTz` column the same way on MySQL,
 * MariaDB and PostgreSQL: as a Unix time the database converts itself ({@see at()} and
 * {@see unix()}, used together) — or, for a column whose readers already take the database's
 * answer as UTC or as PHP's local time, as the string they expect ({@see utc()}, {@see local()}).
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

    /**
     * A moment as a string for a column whose readers take what the database answers as UTC
     * (`Llm\CallLog`, `Push\Log`): on PostgreSQL with its offset, which `timestamptz` keeps;
     * on MySQL and MariaDB the bare UTC wall clock. MariaDB drops an offset (warning 1292, and
     * strict mode refuses the row), and a `TIMESTAMP` answers a bare value as it was written,
     * whatever the session's zone — so the reader's UTC is right on both.
     */
    public static function utc(int $unix, ?Database $db = null): string
    {
        return self::postgres($db ?? \Pramnos\Framework\Factory::getDatabase()) ? gmdate('Y-m-d H:i:sP', $unix) : gmdate('Y-m-d H:i:s', $unix);
    }

    /**
     * The same, in PHP's zone, for a column whose readers parse what they get with
     * `strtotime()` (the activity log): with its offset on PostgreSQL, the bare local wall
     * clock on MySQL and MariaDB.
     */
    public static function local(int $unix, ?Database $db = null): string
    {
        return self::postgres($db ?? \Pramnos\Framework\Factory::getDatabase()) ? date('Y-m-d H:i:sP', $unix) : date('Y-m-d H:i:s', $unix);
    }

    private static function postgres(Database $db): bool
    {
        return in_array(strtolower((string) $db->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
    }
}
