<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Database\Database;
use Pramnos\Database\ReadOnlyConnection;
use Pramnos\Security\ReadOnlyQuery;

/**
 * One SELECT the analyst wrote, run so that it can only read and cannot run for long.
 *
 * Four guards, the last two the database's own:
 *
 * 1. {@see ReadOnlyQuery}: one statement, a SELECT or WITH, no writing keyword anywhere — which
 *    also refuses `INTO OUTFILE`, `FOR UPDATE` and `LOCK IN SHARE MODE`.
 * 2. No function that waits, locks or reads the server's files: `SLEEP`, `BENCHMARK`, `GET_LOCK`,
 *    `LOAD_FILE`, `pg_sleep`, `pg_read_file`, advisory locks, `dblink`, and `FOR SHARE`.
 * 3. A read-only transaction, rolled back: the database refuses a write the lexer missed.
 * 4. A time limit — `statement_timeout` on PostgreSQL, `max_statement_time` on MariaDB,
 *    `MAX_EXECUTION_TIME` on MySQL — `'llm' => ['analyst' => ['sql_seconds' => 30]]`.
 *
 * Through `database_readonly_dsn` where the installation set one ({@see ReadOnlyConnection}).
 * A statement without `LIMIT` gets one.
 */
final class ReadOnlySql
{
    /** Seconds a statement may run, unless the application says otherwise. */
    public const SECONDS = 30;

    /** Functions no read needs: they wait, lock, or reach outside the database. */
    private const FUNCTIONS = [
        'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks', 'is_free_lock', 'load_file',
        'pg_sleep', 'pg_sleep_for', 'pg_sleep_until', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir',
        'pg_stat_file', 'lo_import', 'lo_export', 'dblink', 'dblink_exec', 'pg_terminate_backend',
        'pg_cancel_backend', 'pg_advisory_lock', 'pg_advisory_xact_lock', 'pg_advisory_lock_shared',
        'pg_try_advisory_lock', 'set_config', 'pg_reload_conf', 'pg_rotate_logfile',
    ];

    /** Why this statement will not run, or null when it may. */
    public static function refusal(string $sql): ?string
    {
        if (!ReadOnlyQuery::isRead($sql, $reason)) {
            return (string) $reason;
        }

        $stripped = strtolower(ReadOnlyQuery::withoutStringsAndComments($sql));

        foreach (self::FUNCTIONS as $function) {
            if (preg_match('/\b' . $function . '\s*\(/', $stripped) === 1) {
                return 'That statement calls ' . strtoupper($function) . '(), which no read needs.';
            }
        }

        if (preg_match('/\bfor\s+(share|no\s+key|key)\b|\bdumpfile\b|\boutfile\b/', $stripped) === 1) {
            return 'That statement locks rows or writes a file; a read does neither.';
        }

        // A credential named in the statement: refused, so no alias or expression carries it out.
        preg_match_all('/[a-z_][a-z0-9_]*/', $stripped, $words);
        foreach (array_unique($words[0]) as $word) {
            if (self::isSecret($word)) {
                return 'That statement reads "' . $word . '", which holds a credential; credentials are not read.';
            }
        }

        return null;
    }

    /**
     * Run it, handing each row to `$row`, and answer how many there were.
     *
     * At most `$limit` rows: a statement without `LIMIT` gets `LIMIT $limit`, and the fetch stops
     * there whatever its own limit says.
     *
     * @param callable(array<string, mixed>): void $row
     * @throws \InvalidArgumentException When it is refused — the reason as the message
     * @throws \Pramnos\Database\QueryException When the database refuses it or it runs too long
     */
    public static function each(Database $db, string $sql, int $limit, callable $row): int
    {
        if (($refused = self::refusal($sql)) !== null) {
            throw new \InvalidArgumentException($refused);
        }

        $db      = ReadOnlyConnection::for($db);
        // START TRANSACTION would commit whatever the connection had open on MySQL.
        if ($db->inTransaction()) {
            throw new \InvalidArgumentException('A statement cannot be run inside a transaction already open on this connection.');
        }
        $caps    = $db->schema()->getCapabilities();
        $seconds = self::seconds();
        $sql     = self::withLimit($sql, $limit);
        $mysql   = !$caps->isPostgreSQL() && !$caps->isMariaDB();
        $count   = 0;

        /*
         * Every statement here goes through `querySessionControl()`, never the ordinary
         * `query()`. `isWriteQuery()` classifies `BEGIN`, `SET` and `ROLLBACK` as writes — right
         * for pool routing on an ordinary two-pool connection, where a transaction has to stay
         * pinned to the pool its writes will use — but this transaction never writes anything,
         * by `refusal()`'s own guarantee, so every statement in it belongs on the same
         * connection regardless. Without this they do not: `BEGIN` would route to the write
         * pool and the `SELECT` to the read pool, splitting one "read-only transaction" across
         * two connections that have never heard of each other's `READ ONLY` — and on a
         * dedicated read-only account or a {@see \Pramnos\Database\Database::replicaConnection()}
         * replica, which have no write pool at all, `BEGIN` would simply throw (FW-496). A
         * `WITH … SELECT` is read through the same door for the same reason: `WITH` is not in
         * `isWriteQuery()`'s read-keyword list either.
         */
        if ($caps->isPostgreSQL()) {
            $db->querySessionControl('BEGIN READ ONLY');
            $db->querySessionControl('SET LOCAL statement_timeout = ' . ($seconds * 1000));
        } else {
            $db->querySessionControl('START TRANSACTION READ ONLY');
            if ($caps->isMariaDB()) {
                $sql = 'SET STATEMENT max_statement_time = ' . $seconds . ' FOR ' . $sql;
            } else {
                $db->querySessionControl('SET SESSION MAX_EXECUTION_TIME = ' . ($seconds * 1000));
            }
        }

        try {
            $result = $db->querySessionControl($sql);
            while ($count < $limit && $result && $result->fetch()) {
                // A credential is never handed on, whatever the statement selected.
                $row(self::withhold(is_array($result->fields) ? $result->fields : []));
                $count++;
            }
        } finally {
            $db->querySessionControl('ROLLBACK');
            if ($mysql) {
                $db->querySessionControl('SET SESSION MAX_EXECUTION_TIME = 0');
            }
        }

        return $count;
    }

    /** A column whose name says it holds a credential: its value is never handed on. */
    public const SECRET = '/(^|_)(pass(word|wd)?|pwd|secret|token|api_?key|apikey|private_?key|salt|otp|totp|mfa|2fa|credential|auth_?key|signing_?key|access_?key)(_|$)|(password|secret|token|apikey)/i';

    /**
     * A row with every credential's value replaced by `[withheld]`: a column whose name says so
     * ({@see SECRET}), or one the application names in `'llm' => ['withheld' => ['devices.devkey', 'pin']]`
     * — a bare column name, wherever it is, or the table's — for a key the pattern cannot know.
     * What `SELECT *` brings is withheld here; a statement that names such a column, under any
     * alias, is refused before it runs ({@see refusal()}).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function withhold(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value !== null && $value !== '' && self::isSecret((string) $column)) {
                $row[$column] = '[withheld]';
            }
        }

        return $row;
    }

    /** Whether a column's name says it holds a credential, or the application names it so. */
    private static function isSecret(string $name): bool
    {
        if (self::$named === null) {
            $app         = \Pramnos\Application\Application::currentInstance();
            self::$named = array_map(static fn ($c): string => strtolower((string) substr(strrchr('.' . $c, '.'), 1)),
                (array) (is_object($app) ? ($app->applicationInfo['llm']['withheld'] ?? []) : []));
        }

        return preg_match(self::SECRET, $name) === 1 || in_array(strtolower($name), self::$named, true);
    }

    /** @var list<string>|null The application's own withheld column names, read once */
    private static ?array $named = null;

    /** Read the application's own withheld columns again: for a test, or after app.php changed. */
    public static function forgetWithheld(): void
    {
        self::$named = null;
    }

    /**
     * What the database said about a statement it refused, for the model to correct it by: the
     * driver's words, without the statement echoed back after them.
     */
    public static function said(\Throwable $e): string
    {
        $text = $e instanceof \Pramnos\Database\QueryException ? $e->getDriverMessage() : $e->getMessage();
        $text = trim(explode(' ::: SQL QUERY', $text)[0]);

        return (string) preg_replace('/^\d+:\s*/', '', $text);
    }

    /** The seconds a statement may run: the application's, or {@see SECONDS}. */
    public static function seconds(): int
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);

        return max(1, (int) (is_array($config) && isset($config['sql_seconds']) ? $config['sql_seconds'] : self::SECONDS));
    }

    /** `LIMIT $limit` on a statement that has none of its own. */
    private static function withLimit(string $sql, int $limit): string
    {
        $sql = rtrim(rtrim($sql), ';');

        return preg_match('/\blimit\b/', strtolower(ReadOnlyQuery::withoutStringsAndComments($sql))) === 1
            ? $sql
            : $sql . ' LIMIT ' . $limit;
    }
}
