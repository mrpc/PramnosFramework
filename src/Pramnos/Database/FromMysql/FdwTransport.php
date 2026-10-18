<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

use Pramnos\Database\Database;

/**
 * Rows moved by PostgreSQL itself, through `mysql_fdw`, rather than through PHP.
 *
 * ```php
 * $fdw = FdwTransport::open($postgres, $mysql);          // null without the extension
 * $migrator->useFdw($fdw);
 * …
 * $fdw->close();
 * ```
 *
 * A foreign server, a user mapping and a schema of foreign tables (`pramnos_mysql`) are made for
 * the run and dropped after. A table or window then moves as one `INSERT … SELECT`, its values
 * converted in SQL the way {@see TypeMapper::value()} converts them in PHP, and the rows never pass
 * through PHP — for years of history, that is the difference between hours and days.
 *
 * What it gives up: each table is read in its own MySQL transaction, not one snapshot for all of
 * them; a value that cannot be converted in SQL (bytes that are not UTF-8) fails the statement,
 * and the caller moves that table through PHP instead; values made NULL are not counted.
 */
final class FdwTransport
{
    /** The schema the foreign tables are made in, for the run. */
    public const SCHEMA = 'pramnos_mysql';

    /** The foreign server's name. */
    public const SERVER = 'pramnos_mysql_source';

    /** @var array<string, true> Foreign tables made so far */
    private array $made = [];

    /** Why the last copy was refused, or ''. */
    private string $error = '';

    private function __construct(private Database $target, private string $database)
    {
    }

    /** Whether the PostgreSQL server has `mysql_fdw` to offer. */
    public static function available(Database $target): bool
    {
        $result = $target->query("SELECT 1 FROM pg_available_extensions WHERE name = 'mysql_fdw'");

        return $result !== false && $result->numRows > 0;
    }

    /**
     * Make the server, the mapping and the schema, for `$source`'s database as `$host` reaches
     * it from the PostgreSQL server; null when `mysql_fdw` is not there.
     */
    public static function open(Database $target, Database $source, ?string $host = null): ?self
    {
        if (!self::available($target)) {
            return null;
        }
        $link   = $target->getConnectionLink();
        $quoted = static fn (string $value): string => "'" . pg_escape_string($link, $value) . "'";
        $target->query('CREATE EXTENSION IF NOT EXISTS mysql_fdw');
        $target->query('DROP SERVER IF EXISTS ' . self::SERVER . ' CASCADE');
        $target->query('CREATE SERVER ' . self::SERVER . ' FOREIGN DATA WRAPPER mysql_fdw OPTIONS (host ' . $quoted($host ?? (string) $source->server)
            . ', port ' . $quoted((string) ($source->port ?? 3306)) . ')');
        $target->query('CREATE USER MAPPING FOR current_user SERVER ' . self::SERVER . ' OPTIONS (username ' . $quoted((string) $source->user)
            . ', password ' . $quoted((string) $source->password) . ')');
        $target->query('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $target->query('CREATE SCHEMA ' . self::SCHEMA);

        return new self($target, (string) $source->database);
    }

    /** Why the last copy was refused, or '' when it was not. */
    public function lastError(): string
    {
        return $this->error;
    }

    /** Drop what {@see open()} made. */
    public function close(): void
    {
        $this->target->query('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        $this->target->query('DROP SERVER IF EXISTS ' . self::SERVER . ' CASCADE');
        $this->made = [];
    }

    /**
     * Move a table's rows, or a window of them: `INSERT … SELECT` from the foreign table.
     * Returns the rows inserted, or null when PostgreSQL refused the statement — for the caller
     * to move them through PHP instead.
     *
     * @param array<string, array<string, mixed>> $sourceColumns The source columns, with their MySQL types
     * @param array<string, array<string, mixed>> $targetColumns The target columns, with their PostgreSQL types
     * @param list<string> $columns  The columns that move
     * @param string       $where    A PostgreSQL condition on the foreign table's columns, or ''
     * @param array<string, string> $foreignTypes A column's type in the foreign table, where it is not the default
     * @param string                $zone         The zone the source's `datetime` values were written in
     */
    public function copy(string $source, string $target, array $sourceColumns, array $targetColumns, array $columns, string $where = '', array $foreignTypes = [], string $zone = 'UTC'): ?int
    {
        $foreign = $this->foreignTable($source, $sourceColumns, $targetColumns, $columns, $foreignTypes);
        $exprs   = [];
        foreach ($columns as $column) {
            $exprs[] = self::expression($column, (string) $sourceColumns[$column]['type'], (string) ($targetColumns[$column]['type'] ?? TypeMapper::toPostgres((string) $sourceColumns[$column]['type'])), $zone);
        }
        $sql = 'INSERT INTO ' . self::quoted($target) . ' (' . implode(', ', array_map([self::class, 'identifier'], $columns)) . ') SELECT '
            . implode(', ', $exprs) . ' FROM ' . $foreign . ($where !== '' ? ' WHERE ' . $where : '') . ' ON CONFLICT DO NOTHING';
        $result      = @pg_query($this->target->getConnectionLink(), $sql);
        $this->error = $result === false ? trim((string) strtok(pg_last_error($this->target->getConnectionLink()), "\n")) : '';

        return $result === false ? null : pg_affected_rows($result);
    }

    /**
     * The foreign table for a source table, made once per run. Columns that are converted on the
     * way are read as text — a zero date, JSON, a number kept in a string — so the conversion is
     * PostgreSQL's to make, not the wrapper's to refuse.
     *
     * @param array<string, array<string, mixed>> $sourceColumns
     * @param array<string, array<string, mixed>> $targetColumns
     * @param list<string>                         $columns
     * @param array<string, string>                $foreignTypes
     */
    private function foreignTable(string $source, array $sourceColumns, array $targetColumns, array $columns, array $foreignTypes): string
    {
        $name = self::SCHEMA . '.' . self::identifier($source . (($foreignTypes !== []) ? '_' . substr(md5(serialize($foreignTypes)), 0, 6) : ''));
        if (isset($this->made[$name])) {
            return $name;
        }
        $definitions = [];
        foreach ($columns as $column) {
            $type          = $foreignTypes[$column] ?? self::foreignType((string) $sourceColumns[$column]['type'], (string) ($targetColumns[$column]['type'] ?? ''));
            $definitions[] = self::identifier($column) . ' ' . $type;
        }
        $link = $this->target->getConnectionLink();
        $this->target->query('CREATE FOREIGN TABLE ' . $name . ' (' . implode(', ', $definitions) . ') SERVER ' . self::SERVER
            . " OPTIONS (dbname '" . pg_escape_string($link, $this->database) . "', table_name '" . pg_escape_string($link, $source) . "')");
        $this->made[$name] = true;

        return $name;
    }

    /** What a MySQL column is read as through the wrapper, given where it goes. */
    public static function foreignType(string $mysqlType, string $pgType): string
    {
        $mysql = strtolower($mysqlType);
        $pg    = strtolower($pgType);
        if (preg_match('/^(tiny|small|medium|big)?int/', $mysql) === 1) {
            return preg_match('/unsigned|^bigint/', $mysql) === 1 ? 'numeric' : 'bigint';
        }
        if (preg_match('/^(decimal|numeric|float|double|real)/', $mysql) === 1) {
            return 'numeric';
        }
        if (preg_match('/blob|binary/', $mysql) === 1) {
            return 'bytea';
        }
        if ($pg === 'bytea') {
            return 'bytea';
        }

        // Dates, JSON, enums and text: as text, converted by PostgreSQL.
        return 'text';
    }

    /**
     * The SQL that turns a foreign column into what the target column takes — the same answers
     * as {@see TypeMapper::value()}: a flag as a boolean, a unix time as a moment, MySQL's zero
     * date as NULL, JSON only when it is JSON, a number only when it is one; a `datetime` in
     * `$zone`, the zone it was written in.
     */
    public static function expression(string $column, string $mysqlType, string $pgType, string $zone = 'UTC'): string
    {
        $c     = self::identifier($column);
        $mysql = strtolower($mysqlType);
        $pg    = strtolower($pgType);
        $int   = preg_match('/^(tiny|small|medium|big)?int/', $mysql) === 1;

        if ($pg === 'boolean') {
            return $int ? '(' . $c . ' <> 0)' : "(lower(" . $c . "::text) IN ('1', 't', 'true', 'y', 'yes'))";
        }
        if (str_starts_with($pg, 'timestamp') || $pg === 'date') {
            $in = "'" . str_replace("'", "''", $zone) . "'";
            if ($int) {
                $moment = 'to_timestamp(' . $c . ')';
            } elseif (!str_starts_with($mysql, 'timestamp') && $pg !== 'timestamp with time zone') {
                // A wall-clock time into a column without a zone: as it was.
                $moment = null;
            } else {
                // The wrapper reads with the session in UTC, so a MySQL `timestamp` is UTC; a
                // `datetime` is the wall-clock time of the zone it was written in.
                $moment = '(' . $c . '::timestamp AT TIME ZONE ' . (str_starts_with($mysql, 'timestamp') ? "'UTC'" : $in) . ')';
            }
            $value = match (true) {
                $moment === null                   => $c . '::' . $pg,
                $pg === 'timestamp with time zone' => $moment,
                $pg === 'date'                     => '(' . $moment . ' AT TIME ZONE ' . $in . ')::date',
                default                            => '(' . $moment . ' AT TIME ZONE ' . $in . ')',
            };

            return $int
                ? 'CASE WHEN ' . $c . ' > 0 THEN ' . $value . ' END'
                : 'CASE WHEN ' . $c . " IS NULL OR " . $c . " = '' OR " . $c . " LIKE '0000-00-00%' THEN NULL ELSE " . $value . ' END';
        }
        if ($pg === 'json' || $pg === 'jsonb') {
            return 'CASE WHEN ' . $c . ' IS JSON THEN ' . $c . '::' . $pg . ' END';
        }
        if ($pg === 'bytea') {
            return $c;
        }
        if (preg_match('/^(smallint|integer|bigint|numeric|real|double precision)/', $pg) === 1) {
            if ($int || preg_match('/^(decimal|numeric|float|double|real)/', $mysql) === 1) {
                return $c . '::' . $pg;
            }

            return 'CASE WHEN ' . $c . " ~ '^\\s*[-+]?([0-9]+\\.?[0-9]*|\\.[0-9]+)([eE][-+]?[0-9]+)?\\s*$' THEN " . $c . '::' . $pg . ' END';
        }

        // Bytes going into text: as UTF-8, which PostgreSQL checks — bytes that are not refuse the
        // statement, and the table moves through PHP, which repairs them.
        if (preg_match('/blob|binary/', $mysql) === 1) {
            return 'convert_from(' . $c . ", 'UTF8')::" . ($pg !== '' ? $pg : 'text');
        }

        return $c . '::' . ($pg !== '' ? $pg : 'text');
    }

    private static function identifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** `schema.table` or `table`, double-quoted. */
    private static function quoted(string $table): string
    {
        return implode('.', array_map([self::class, 'identifier'], explode('.', $table, 2)));
    }
}
