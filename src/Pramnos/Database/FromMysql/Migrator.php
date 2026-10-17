<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

use Pramnos\Database\Database;

/**
 * Moving an application's data from MySQL into the PostgreSQL its migrations built.
 *
 * ```php
 * $migrator = new Migrator($mysql, $postgres);
 * $plan     = $migrator->plan();                  // what would move, and what would not
 * $result   = $migrator->run($plan, batch: 5000); // move it
 * ```
 *
 * The PostgreSQL side is the target as its migrations made it — typed better than MySQL, often:
 * a flag that is a `boolean`, a unix time that is a `timestamptz`, a schema per area
 * (`authserver.x` for MySQL's `authserver_x`). Each row is read from MySQL in one consistent
 * snapshot, streamed, converted to what each target column takes ({@see TypeMapper}) and inserted
 * in batches with `ON CONFLICT DO NOTHING`, so a second run adds only what the first did not. A
 * table MySQL has and PostgreSQL does not is created from its MySQL definition.
 *
 * Tables load parents first ({@see TopologicalSorter}); where the target allows it the load runs
 * with `session_replication_role = replica`, keys unchecked as the rows arrive, and the orphans
 * that leaves are counted afterwards. Sequences are moved past the highest id last.
 *
 * Catalogue introspection throughout, and row transfer through the drivers' own calls: neither is
 * something the query builder reaches.
 */
final class Migrator
{
    /** PostgreSQL schemas that hold no application tables. */
    private const SYSTEM_SCHEMAS = "'pg_catalog', 'information_schema', 'pg_toast', 'timescaledb_information', 'timescaledb_experimental'";

    /** Most bind parameters one statement takes, kept under PostgreSQL's 65,535. */
    private const MAX_PARAMETERS = 60000;

    /** @var array<string, array<string, mixed>>|null The source's tables, read once */
    private ?array $sourceShape = null;

    public function __construct(
        private Database $source,
        private Database $target,
        private ?string $sourcePrefix = null,
        private ?string $targetPrefix = null,
    ) {
        $this->sourcePrefix ??= (string) $source->prefix;
        $this->targetPrefix ??= (string) $target->prefix;
    }

    /**
     * What would move: each MySQL table, the PostgreSQL table it goes into (or the one it would
     * be created as), its estimated rows, which columns move, which exist on one side only, which
     * the target requires and will not get, and the load order.
     *
     * @param list<string> $only Source tables to move, all when empty
     * @param list<string> $skip Source tables to leave
     * @return array{tables: array<string, array<string, mixed>>, order: list<string>, cycles: list<string>, replica: bool}
     */
    public function plan(array $only = [], array $skip = []): array
    {
        $sources = $this->sourceTables();
        $targets = $this->targetTables();
        $byName  = [];
        foreach ($targets as $name => $shape) {
            $byName[$this->comparable($name, (string) $this->targetPrefix, true)] = $name;
        }

        $tables = [];
        foreach ($sources as $name => $shape) {
            if (($only !== [] && !in_array($name, $only, true)) || in_array($name, $skip, true)) {
                continue;
            }
            $key    = $this->comparable($name, (string) $this->sourcePrefix, false);
            $target = $byName[$key] ?? null;
            $create = null;
            if ($target === null) {
                $target = $this->targetPrefix . $key;
                $create = $this->createStatement($target, $shape);
            }
            $targetColumns = $target !== null && isset($targets[$target]) ? $targets[$target]['columns'] : [];
            $copied        = $create !== null ? array_keys($shape['columns']) : array_values(array_intersect(array_keys($shape['columns']), array_keys($targetColumns)));
            $required      = [];
            foreach ($targetColumns as $column => $definition) {
                if (!in_array($column, $copied, true) && $definition['notnull'] && !$definition['default']) {
                    $required[] = $column;
                }
            }
            $tables[$name] = [
                'source'      => $name,
                'target'      => $target,
                'create'      => $create,
                'rows'        => $shape['rows'],
                'copied'      => $copied,
                'sourceOnly'  => $create !== null ? [] : array_values(array_diff(array_keys($shape['columns']), $copied)),
                'targetOnly'  => array_values(array_diff(array_keys($targetColumns), $copied)),
                'required'    => $required,
                'compressed'  => $create === null && $this->hasCompressedChunks($target),
            ];
        }

        // The target's keys decide the order: they are the ones that refuse a row.
        $dependsOn = [];
        $byTarget  = [];
        foreach ($tables as $name => $table) {
            $byTarget[$table['target']] = $name;
        }
        foreach ($this->targetForeignKeys() as $key) {
            if (isset($byTarget[$key['table']], $byTarget[$key['references']])) {
                $dependsOn[$byTarget[$key['table']]][] = $byTarget[$key['references']];
            }
        }
        $sorted = TopologicalSorter::sort(array_keys($tables), $dependsOn);

        return ['tables' => $tables, 'order' => $sorted['order'], 'cycles' => $sorted['cycles'], 'replica' => $this->replicaAllowed()];
    }

    /**
     * Move the rows the plan describes.
     *
     * @param array<string, mixed> $plan       From {@see plan()}
     * @param bool                 $useReplica Load with the target's keys and triggers off, where allowed
     * @param bool                 $nullOrphans After a load without keys, set orphaned references to NULL where the column takes it
     * @param (callable(string, int, int): void)|null $progress Table, rows inserted, rows refused — after each batch
     * @return array{tables: array<string, array{inserted: int, refused: int, nulled: int, errors: list<string>}>, orphans: list<array<string, mixed>>, sequences: list<array<string, mixed>>, replica: bool}
     */
    public function run(array $plan, int $batch = 5000, bool $useReplica = true, bool $nullOrphans = false, ?callable $progress = null): array
    {
        $result = ['tables' => [], 'orphans' => [], 'sequences' => [], 'replica' => false];
        foreach ($plan['order'] as $name) {
            $table = $plan['tables'][$name];
            if ($table['create'] !== null && !$this->hasTargetTable($table['target'])) {
                $this->target->query($table['create']);
            }
        }

        $replica = $useReplica && $plan['replica'] && $this->setReplica(true);
        $result['replica'] = $replica;
        $mysqli = $this->source->getConnectionLink();
        // One snapshot for every table: rows that point at each other were read at one moment.
        $mysqli->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $targets = $this->targetTables();
            foreach ($plan['order'] as $name) {
                $table = $plan['tables'][$name];
                $result['tables'][$name] = $this->copyTable($table, $targets[$table['target']]['columns'] ?? [], max(1, $batch), $progress);
            }
        } finally {
            $mysqli->query('COMMIT');
            if ($replica) {
                $this->setReplica(false);
            }
        }

        $copied = array_values(array_unique(array_map(static fn (array $t): string => $t['target'], array_intersect_key($plan['tables'], array_flip($plan['order'])))));
        if ($replica) {
            $result['orphans'] = $this->orphans($copied, $nullOrphans);
        }
        $result['sequences'] = $this->alignSequences($copied);

        return $result;
    }

    /**
     * Stream one table: read unbuffered, convert, insert in batches; a batch that is refused is
     * tried row by row, so one bad row costs that row.
     *
     * @param array<string, mixed> $table
     * @param array<string, array<string, mixed>> $targetColumns
     * @return array{inserted: int, refused: int, nulled: int, errors: list<string>}
     */
    private function copyTable(array $table, array $targetColumns, int $batch, ?callable $progress): array
    {
        $stats   = ['inserted' => 0, 'refused' => 0, 'nulled' => 0, 'errors' => []];
        $columns = $table['copied'];
        if ($columns === []) {
            return $stats;
        }
        $sourceTypes = $this->sourceTables()[$table['source']]['columns'];
        $perBatch    = max(1, min($batch, intdiv(self::MAX_PARAMETERS, count($columns))));
        $mysqli      = $this->source->getConnectionLink();
        $read        = $mysqli->query(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns))
            . ' FROM `' . str_replace('`', '``', $table['source']) . '`',
            MYSQLI_USE_RESULT
        );
        $rows = [];
        while ($read && ($row = $read->fetch_assoc()) !== null) {
            $converted = [];
            foreach ($columns as $column) {
                $value = TypeMapper::value($row[$column], (string) $sourceTypes[$column]['type'], (string) ($targetColumns[$column]['type'] ?? TypeMapper::toPostgres((string) $sourceTypes[$column]['type'])), $refused);
                $stats['nulled'] += $refused ? 1 : 0;
                $converted[] = $value;
            }
            $rows[] = $converted;
            if (count($rows) >= $perBatch) {
                $this->insert($table['target'], $columns, $rows, $stats);
                $rows = [];
                if ($progress !== null) {
                    $progress($table['source'], $stats['inserted'], $stats['refused']);
                }
            }
        }
        if ($read) {
            $read->free();
        }
        if ($rows !== []) {
            $this->insert($table['target'], $columns, $rows, $stats);
            if ($progress !== null) {
                $progress($table['source'], $stats['inserted'], $stats['refused']);
            }
        }

        return $stats;
    }

    /**
     * @param list<string>             $columns
     * @param list<list<mixed>>        $rows
     * @param array<string, mixed>     $stats
     */
    private function insert(string $table, array $columns, array $rows, array &$stats): void
    {
        $connection = $this->target->getConnectionLink();
        $head       = 'INSERT INTO ' . $this->quoted($table) . ' (' . implode(', ', array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $columns)) . ') VALUES ';
        $statement  = static function (int $count, int $width) use ($head): string {
            $groups = [];
            for ($r = 0, $n = 1; $r < $count; $r++) {
                $placeholders = [];
                for ($c = 0; $c < $width; $c++, $n++) {
                    $placeholders[] = '$' . $n;
                }
                $groups[] = '(' . implode(', ', $placeholders) . ')';
            }

            return $head . implode(', ', $groups) . ' ON CONFLICT DO NOTHING';
        };

        $result = @pg_query_params($connection, $statement(count($rows), count($columns)), array_merge(...$rows));
        if ($result !== false) {
            $stats['inserted'] += pg_affected_rows($result);

            return;
        }
        foreach ($rows as $row) {
            $one = @pg_query_params($connection, $statement(1, count($columns)), $row);
            if ($one !== false) {
                $stats['inserted'] += pg_affected_rows($one);
                continue;
            }
            $stats['refused']++;
            if (count($stats['errors']) < 3) {
                $stats['errors'][] = trim((string) strtok(pg_last_error($connection), "\n"));
            }
        }
    }

    /**
     * Rows whose reference names no row of the table it points at, per key of the moved tables —
     * what a load without keys can leave. With `$null`, set to NULL where the column takes it.
     *
     * @param list<string> $tables
     * @return list<array{table: string, column: string, references: string, rows: int, nulled: bool}>
     */
    private function orphans(array $tables, bool $null): array
    {
        $found = [];
        foreach ($this->targetForeignKeys() as $key) {
            if (!in_array($key['table'], $tables, true)) {
                continue;
            }
            $where = $this->quoted($key['table']) . ' c WHERE c."' . $key['column'] . '" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '
                . $this->quoted($key['references']) . ' p WHERE p."' . $key['referencedColumn'] . '" = c."' . $key['column'] . '")';
            $count = (int) ($this->rows('SELECT COUNT(*) AS n FROM ' . $where)[0]['n'] ?? 0);
            if ($count === 0) {
                continue;
            }
            $nulled = false;
            if ($null && $key['nullable']) {
                $this->target->query('UPDATE ' . $this->quoted($key['table']) . ' SET "' . $key['column'] . '" = NULL WHERE "' . $key['column']
                    . '" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $this->quoted($key['references']) . ' p WHERE p."' . $key['referencedColumn']
                    . '" = ' . $this->quoted($key['table']) . '."' . $key['column'] . '")');
                $nulled = true;
            }
            $found[] = ['table' => $key['table'], 'column' => $key['column'], 'references' => $key['references'], 'rows' => $count, 'nulled' => $nulled];
        }

        return $found;
    }

    /**
     * Every sequence behind a column of the moved tables, set past its highest value, so the
     * next row the application writes does not collide with a moved one.
     *
     * @param list<string> $tables
     * @return list<array{table: string, column: string, next: int}>
     */
    private function alignSequences(array $tables): array
    {
        $aligned = [];
        foreach ($this->rows('SELECT ns.nspname AS s, c.relname AS t, a.attname AS col,'
            . " pg_get_serial_sequence(quote_ident(ns.nspname) || '.' || quote_ident(c.relname), a.attname) AS seq"
            . ' FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace'
            . " WHERE c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped AND ns.nspname NOT IN (" . self::SYSTEM_SCHEMAS . ')') as $row) {
            $table = $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'];
            if ($row['seq'] === null || !in_array($table, $tables, true)) {
                continue;
            }
            $next = (int) ($this->rows('SELECT COALESCE(MAX("' . $row['col'] . '"), 0) + 1 AS n FROM ' . $this->quoted($table))[0]['n'] ?? 1);
            $this->target->query("SELECT setval('" . str_replace("'", "''", (string) $row['seq']) . "', " . $next . ', false)');
            $aligned[] = ['table' => $table, 'column' => (string) $row['col'], 'next' => $next];
        }

        return $aligned;
    }

    /** @return array<string, array{columns: array<string, array<string, mixed>>, primary: list<string>, rows: int}> */
    private function sourceTables(): array
    {
        if ($this->sourceShape !== null) {
            return $this->sourceShape;
        }
        $tables = [];
        foreach ($this->sourceRows("SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $row) {
            $tables[(string) $row['t']] = ['columns' => [], 'primary' => [], 'rows' => (int) $row['n']];
        }
        foreach ($this->sourceRows('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, EXTRA AS e'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['columns'][(string) $row['c']] = ['type' => (string) $row['type'], 'nullable' => $row['n'] === 'YES', 'default' => $row['d'], 'auto' => str_contains((string) $row['e'], 'auto_increment')];
            }
        }
        foreach ($this->sourceRows("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PRIMARY' ORDER BY TABLE_NAME, SEQ_IN_INDEX") as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['primary'][] = (string) $row['c'];
            }
        }

        return $this->sourceShape = $tables;
    }

    /** @return array<string, array{columns: array<string, array{type: string, notnull: bool, default: bool}>}> */
    private function targetTables(): array
    {
        $tables = [];
        foreach ($this->rows("SELECT table_schema AS s, table_name AS t FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN (" . self::SYSTEM_SCHEMAS . ") AND table_schema NOT LIKE '\\_timescaledb%'") as $row) {
            $tables[$row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t']] = ['columns' => []];
        }
        foreach ($this->rows('SELECT ns.nspname AS s, c.relname AS t, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS nn,'
            . ' (a.atthasdef OR a.attidentity <> \'\') AS def FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace'
            . " WHERE c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped") as $row) {
            $name = $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'];
            if (isset($tables[$name])) {
                $tables[$name]['columns'][(string) $row['col']] = ['type' => (string) $row['type'], 'notnull' => in_array($row['nn'], [true, 't', 1, '1'], true), 'default' => in_array($row['def'], [true, 't', 1, '1'], true)];
            }
        }

        return $tables;
    }

    /** @return list<array{table: string, column: string, references: string, referencedColumn: string, nullable: bool}> */
    private function targetForeignKeys(): array
    {
        $keys = [];
        foreach ($this->rows('SELECT ns.nspname AS s, t.relname AS t, a.attname AS col, NOT a.attnotnull AS nullable, rns.nspname AS rs, r.relname AS rt, ra.attname AS rcol'
            . ' FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace'
            . ' JOIN pg_class r ON r.oid = c.confrelid JOIN pg_namespace rns ON rns.oid = r.relnamespace'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1] JOIN pg_attribute ra ON ra.attrelid = r.oid AND ra.attnum = c.confkey[1]'
            . " WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1") as $row) {
            $keys[] = [
                'table'            => $row['s'] === 'public' ? (string) $row['t'] : $row['s'] . '.' . $row['t'],
                'column'           => (string) $row['col'],
                'references'       => $row['rs'] === 'public' ? (string) $row['rt'] : $row['rs'] . '.' . $row['rt'],
                'referencedColumn' => (string) $row['rcol'],
                'nullable'         => in_array($row['nullable'], [true, 't', 1, '1'], true),
            ];
        }

        return $keys;
    }

    /**
     * A table name as both sides can be matched on: the side's prefix taken off, and on the
     * PostgreSQL side a schema written the way MySQL flattens it (`authserver.x` is `authserver_x`).
     */
    private function comparable(string $name, string $prefix, bool $target): string
    {
        if ($target && str_contains($name, '.')) {
            [$schema, $bare] = explode('.', $name, 2);
            $bare = $prefix !== '' && str_starts_with($bare, $prefix) ? substr($bare, strlen($prefix)) : $bare;

            return strtolower($schema . '_' . $bare);
        }

        return strtolower($prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name);
    }

    /**
     * The statement that creates a MySQL table nothing on the PostgreSQL side made: its columns
     * typed by {@see TypeMapper}, an auto-increment as an identity, the same primary key.
     *
     * @param array<string, mixed> $shape
     */
    private function createStatement(string $table, array $shape): string
    {
        $columns = [];
        foreach ($shape['columns'] as $name => $column) {
            $type = TypeMapper::toPostgres($column['type']);
            $sql  = '"' . str_replace('"', '""', $name) . '" ' . $type;
            if ($column['auto']) {
                $sql .= ' GENERATED BY DEFAULT AS IDENTITY';
            } elseif ($column['default'] !== null && is_numeric($column['default']) && $type !== 'boolean') {
                $sql .= ' DEFAULT ' . $column['default'];
            } elseif ($column['default'] !== null && strtoupper((string) $column['default']) === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            }
            $columns[] = $sql . ($column['nullable'] ? '' : ' NOT NULL');
        }
        if ($shape['primary'] !== []) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $shape['primary'])) . ')';
        }

        return 'CREATE TABLE ' . $this->quoted($table) . ' (' . implode(', ', $columns) . ')';
    }

    /** Whether the target user may load with keys and triggers off. */
    private function replicaAllowed(): bool
    {
        $row = $this->rows("SELECT rolsuper AS s FROM pg_roles WHERE rolname = current_user")[0] ?? [];

        return in_array($row['s'] ?? null, [true, 't', 1, '1'], true);
    }

    private function setReplica(bool $on): bool
    {
        try {
            return $this->target->query('SET session_replication_role = ' . ($on ? 'replica' : 'origin')) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTargetTable(string $table): bool
    {
        return isset($this->targetTables()[$table]);
    }

    private function hasCompressedChunks(string $table): bool
    {
        try {
            [$schema, $bare] = str_contains($table, '.') ? explode('.', $table, 2) : ['public', $table];

            return $this->target->schema()->hasCompressedChunks($bare, $schema);
        } catch (\Throwable) {
            return false;
        }
    }

    /** `schema.table` or `table`, double-quoted for PostgreSQL. */
    private function quoted(string $table): string
    {
        return implode('.', array_map(static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"', explode('.', $table, 2)));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = $this->target->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(string $sql): array
    {
        $result = $this->source->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }
}
