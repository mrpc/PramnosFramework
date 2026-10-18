<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

use Pramnos\Database\Database;

/**
 * A table walked by its primary key, a range of keys at a time, on both sides of a move.
 *
 * ```php
 * $ranges = new KeyRanges($mysql, $postgres, 'Europe/Athens');
 * foreach ($ranges->read('orders', ['id'], ['id', 'total'], 5000) as $rows) { … }   // no long transaction
 * $ranges->compare('orders', 'orders', ['id'], $columns, $sourceTypes, $targetTypes, 5000);
 * $ranges->extras('orders', 'orders', ['id'], $targetTypes, 5000, delete: false);
 * ```
 *
 * Each range is its own statement — `WHERE (key) > (last) ORDER BY key LIMIT n` — so nothing holds
 * a snapshot open on the source, memory is one range whatever the table's size, and a composite
 * key walks the same way as a single one.
 *
 * Rows are compared as the target holds them: each source row is converted as a move converts it
 * ({@see TypeMapper::value()}), its values cast to the target's column types, and hashed by
 * PostgreSQL beside the target row of the same key — so a `tinyint(1)` that is a `boolean`, a
 * `datetime` that is a `timestamptz`, JSON reformatted by `jsonb`, all compare equal when they are.
 *
 * Catalogue-free and dialect-specific throughout (row constructors, `VALUES` lists with casts,
 * `md5(ROW(…)::text)`): none of it is something the query builder expresses.
 */
final class KeyRanges
{
    /** Most bind parameters one statement takes, kept under PostgreSQL's 65,535. */
    private const MAX_PARAMETERS = 60000;

    /** Key types whose text is the same on both servers — what a key can be looked up by across them. */
    private const PORTABLE_KEY = '/^(smallint|integer|bigint|numeric|character|text|uuid)/';

    public function __construct(private Database $source, private Database $target, private string $zone = 'UTC')
    {
    }

    /**
     * The source's rows in key order, `$size` at a time, each range read by its own statement.
     *
     * @param list<string> $key     The primary key's columns
     * @param list<string> $columns The columns to read, the key's among them
     * @param string       $where   A MySQL condition on the rows, or ''
     * @return \Generator<int, list<array<string, ?string>>>
     */
    public function read(string $table, array $key, array $columns, int $size, string $where = ''): \Generator
    {
        $mysqli = $this->source->getConnectionLink();
        $quote  = static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`';
        $order  = implode(', ', array_map($quote, $key));
        $size   = max(1, $size);
        $last   = null;
        do {
            $conditions = $where !== '' ? ['(' . $where . ')'] : [];
            if ($last !== null) {
                $conditions[] = '(' . $order . ') > (' . implode(', ', array_map(static fn (mixed $v): string => "'" . $mysqli->real_escape_string((string) $v) . "'", $last)) . ')';
            }
            $result = $mysqli->query('SELECT ' . implode(', ', array_map($quote, $columns)) . ' FROM ' . $quote($table)
                . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '') . ' ORDER BY ' . $order . ' LIMIT ' . $size);
            $rows = $result !== false ? $result->fetch_all(MYSQLI_ASSOC) : [];
            if ($rows === []) {
                return;
            }
            yield $rows;
            $tail = $rows[count($rows) - 1];
            $last = array_map(static fn (string $k): mixed => $tail[$k], $key);
        } while (count($rows) === $size);
    }

    /**
     * Each source row against the target's row of the same key. With `$repair`, the rows that
     * are missing or differ are handed to it, converted, in `$columns` order, a range at a time.
     *
     * @param list<string>                         $key
     * @param list<string>                         $columns     The columns that move, the key's among them
     * @param array<string, array<string, mixed>>  $sourceTypes The source columns, with their MySQL types
     * @param array<string, array<string, mixed>>  $targetTypes The target columns, with their PostgreSQL types
     * @param (callable(list<list<mixed>>): void)|null $repair
     * @return array{rows: int, missing: int, different: int, keys: list<string>}
     */
    public function compare(string $source, string $target, array $key, array $columns, array $sourceTypes, array $targetTypes, int $size, ?callable $repair = null): array
    {
        $stats    = ['rows' => 0, 'missing' => 0, 'different' => 0, 'keys' => []];
        $size     = max(1, min($size, intdiv(self::MAX_PARAMETERS, count($columns) + 1)));
        $types    = array_map(static fn (string $c): string => (string) ($targetTypes[$c]['type'] ?? TypeMapper::toPostgres((string) $sourceTypes[$c]['type'])), $columns);
        $keyTypes = array_map(static fn (string $c): string => $types[array_search($c, $columns, true)], $key);
        $at       = array_map(static fn (string $c): int => (int) array_search($c, $columns, true), $key);
        foreach ($this->read($source, $key, $columns, $size) as $rows) {
            $converted = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $i => $column) {
                    $values[] = TypeMapper::value($row[$column], (string) $sourceTypes[$column]['type'], $types[$i], $refused, $this->zone);
                }
                $converted[] = $values;
            }
            // Each source row carries its place, so a difference is matched to the row it came from.
            $places = array_map(static fn (array $r, int $i): array => [...$r, $i], $converted, array_keys($converted));
            $theirs = $this->hashes('(' . self::values(count($converted), [...$types, 'integer']) . ') AS v(' . self::names([...$columns, '__place']) . ')', 'v', $key, $columns, array_merge(...$places), true);
            $ours   = $this->hashes($this->quoted($target) . ' AS t WHERE (' . self::names($key, 't') . ') IN (' . self::values(count($converted), $keyTypes) . ')',
                't', $key, $columns, array_merge(...array_map(static fn (array $r): array => array_map(static fn (int $i): mixed => $r[$i], $at), $converted)));
            $bad = [];
            foreach ($theirs as $id => [$hash, $place]) {
                $state = !isset($ours[$id]) ? 'missing' : ($ours[$id] !== $hash ? 'different' : null);
                if ($state !== null) {
                    $stats[$state]++;
                    $bad[] = $converted[$place];
                    if (count($stats['keys']) < 5) {
                        $stats['keys'][] = str_replace("\x1f", ', ', (string) $id);
                    }
                }
            }
            $stats['rows'] += count($converted);
            if ($repair !== null && $bad !== []) {
                $repair($bad);
            }
        }

        return $stats;
    }

    /**
     * The target's rows whose key the source no longer has, the target walked `$size` keys at a
     * time; deleted with `$delete`. A key whose text differs between the servers (a moment, a
     * float) cannot be looked up across them: it is named in `unchecked`, and nothing is touched.
     *
     * @param list<string>                        $key
     * @param array<string, array<string, mixed>> $targetTypes
     * @return array{rows: int, keys: list<string>, deleted: int, unchecked: ?string}
     */
    public function extras(string $source, string $target, array $key, array $targetTypes, int $size, bool $delete): array
    {
        $stats = ['rows' => 0, 'keys' => [], 'deleted' => 0, 'unchecked' => null];
        $types = array_map(static fn (string $c): string => (string) ($targetTypes[$c]['type'] ?? ''), $key);
        foreach ($types as $i => $type) {
            if (preg_match(self::PORTABLE_KEY, $type) !== 1) {
                $stats['unchecked'] = 'rows deleted in MySQL are not looked for: the key ' . $key[$i] . ' is a ' . ($type !== '' ? $type : 'column the target has not');

                return $stats;
            }
        }
        $link   = $this->target->getConnectionLink();
        $mysqli = $this->source->getConnectionLink();
        $size   = max(1, min($size, intdiv(self::MAX_PARAMETERS, count($key))));
        $last   = null;
        do {
            $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => self::name($c) . '::text AS ' . self::name($c), $key)) . ' FROM ' . $this->quoted($target)
                . ($last !== null ? ' WHERE (' . self::names($key) . ') > ' . substr(self::values(1, $types), strlen('VALUES ')) : '') . ' ORDER BY ' . self::names($key) . ' LIMIT ' . $size;
            $result = $last !== null ? @pg_query_params($link, $sql, $last) : @pg_query($link, $sql);
            $keys   = $result !== false ? (pg_fetch_all($result) ?: []) : [];
            if ($keys === []) {
                break;
            }
            $present = [];
            $found   = $mysqli->query('SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $key)) . ' FROM `' . str_replace('`', '``', $source) . '`'
                . ' WHERE (' . implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $key)) . ') IN ('
                . implode(', ', array_map(static fn (array $k): string => '(' . implode(', ', array_map(static fn (mixed $v): string => "'" . $mysqli->real_escape_string((string) $v) . "'", array_values($k))) . ')', $keys)) . ')');
            while ($found !== false && ($row = $found->fetch_row()) !== null) {
                $present[implode("\x1f", $row)] = true;
            }
            $gone = array_values(array_filter($keys, static fn (array $k): bool => !isset($present[implode("\x1f", $k)])));
            foreach ($gone as $k) {
                if (count($stats['keys']) < 5) {
                    $stats['keys'][] = implode(', ', $k);
                }
            }
            $stats['rows'] += count($gone);
            if ($delete && $gone !== []) {
                $deleted = @pg_query_params($link, 'DELETE FROM ' . $this->quoted($target) . ' WHERE (' . self::names($key) . ') IN (' . self::values(count($gone), $types) . ')',
                    array_merge(...array_map('array_values', $gone)));
                $stats['deleted'] += $deleted !== false ? pg_affected_rows($deleted) : 0;
            }
            $last = array_values($keys[count($keys) - 1]);
        } while (count($keys) === $size);

        return $stats;
    }

    /**
     * Each row's key and the hash of its columns, from `$from` (rows aliased `$alias`).
     *
     * @param list<string> $key
     * @param list<string> $columns
     * @param list<mixed>  $params
     * @param bool         $placed The rows carry their place in `__place`, returned beside the hash
     * @return array<string, mixed> The key's text (columns joined by U+001F) to the row's md5, or to [md5, place]
     */
    private function hashes(string $from, string $alias, array $key, array $columns, array $params, bool $placed = false): array
    {
        $sql = 'SELECT concat_ws(chr(31), ' . implode(', ', array_map(static fn (string $c): string => $alias . '.' . self::name($c) . '::text', $key)) . ') AS k,'
            . ' md5(ROW(' . implode(', ', array_map(static fn (string $c): string => $alias . '.' . self::name($c), $columns)) . ')::text) AS h'
            . ($placed ? ', ' . $alias . '."__place" AS p' : '') . ' FROM ' . $from;
        $result = @pg_query_params($this->target->getConnectionLink(), $sql, $params);
        if ($result === false) {
            throw new \RuntimeException('Could not compare: ' . trim((string) strtok(pg_last_error($this->target->getConnectionLink()), "\n")));
        }
        $hashes = [];
        while (($row = pg_fetch_assoc($result)) !== false) {
            $hashes[(string) $row['k']] = $placed ? [(string) $row['h'], (int) $row['p']] : (string) $row['h'];
        }

        return $hashes;
    }

    /**
     * `($1::t1, $2::t2), ($3::t1, …)` — `$rows` rows of placeholders, each cast to its column's type.
     *
     * @param list<string> $types
     */
    private static function values(int $rows, array $types): string
    {
        $groups = [];
        for ($r = 0, $n = 1; $r < $rows; $r++) {
            $cells = [];
            foreach ($types as $type) {
                $cells[] = '$' . $n++ . '::' . $type;
            }
            $groups[] = '(' . implode(', ', $cells) . ')';
        }

        return 'VALUES ' . implode(', ', $groups);
    }

    /**
     * Column names, double-quoted and comma-separated, optionally qualified.
     *
     * @param list<string> $columns
     */
    private static function names(array $columns, string $alias = ''): string
    {
        return implode(', ', array_map(static fn (string $c): string => ($alias !== '' ? $alias . '.' : '') . self::name($c), $columns));
    }

    private static function name(string $column): string
    {
        return '"' . str_replace('"', '""', $column) . '"';
    }

    /** `schema.table` or `table`, double-quoted. */
    private function quoted(string $table): string
    {
        return implode('.', array_map([self::class, 'name'], explode('.', $table, 2)));
    }
}
