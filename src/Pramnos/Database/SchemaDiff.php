<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * How an installation's tables differ from the ones the framework's migrations would make.
 *
 * ```php
 * $reference = SchemaDiff::describe($referenceDb, true);   // a database the migrations just built
 * $live      = SchemaDiff::describe($db);
 * foreach (SchemaDiff::compare($reference, $live, $allow) as $difference) { … }
 * SchemaDiff::apply($db, $reference, $differences);         // the additive ones only
 * ```
 *
 * The reference is built, not stored: `schema:diff` runs the framework's migrations into an
 * empty database on the same server, so the two are described by the same engine — MariaDB
 * against MariaDB, PostgreSQL against PostgreSQL — and nothing has to be normalised between
 * engines. What is compared:
 *
 * - **columns** — the type (integer display widths left out), whether it takes NULL, the default,
 *   and on MySQL the character set;
 * - **indexes** — by the columns they cover and whether they are unique; the name is not the
 *   index, so the same index under another name is the same index;
 * - **foreign keys** — by the column, the table and column it leads to, and the delete rule.
 *
 * Every difference has a key, `table.kind.name.aspect` (`users.column.mobile.type`,
 * `tokenactions.index.tokenid,urlid.missing`), and an allow-list of globs over those keys marks
 * the intentional ones. What the installation has and the framework does not — a column of its
 * own, an extra index — is information, not a difference.
 *
 * Catalogue introspection throughout, so raw SQL: the query builder does not reach the catalogues.
 */
final class SchemaDiff
{
    /** A difference to resolve. */
    public const DIFFERENCE = 'difference';

    /** Something the installation has beyond the framework's shape. */
    public const INFO = 'info';

    /** A difference the allow-list names. */
    public const ALLOWED = 'allowed';

    /** PostgreSQL schemas that are not tables of the application's. */
    private const SYSTEM_SCHEMAS = ['pg_catalog', 'information_schema', 'pg_toast', 'timescaledb_information', 'timescaledb_experimental', '_timescaledb_catalog', '_timescaledb_internal', '_timescaledb_cache', '_timescaledb_config', '_timescaledb_functions', '_timescaledb_debug', 'toolkit_experimental'];

    /**
     * Every table of a database, described for comparison.
     *
     * With `$definitions`, each column and index also carries the statement that would add it,
     * read from the database itself — what `apply()` runs on the other side.
     *
     * @return array<string, array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign: array<string, array<string, mixed>>}>
     */
    public static function describe(Database $db, bool $definitions = false): array
    {
        return self::isPostgres($db) ? self::describePostgres($db, $definitions) : self::describeMysql($db, $definitions);
    }

    /**
     * The differences between the framework's shape and the installation's, in table order.
     *
     * @param array<string, mixed> $reference From {@see describe()} on the reference database
     * @param array<string, mixed> $live      From {@see describe()} on the installation
     * @param list<string>         $allow     Globs over the difference keys
     * @return list<array{key: string, table: string, kind: string, name: string, aspect: string, reference: mixed, live: mixed, severity: string}>
     */
    public static function compare(array $reference, array $live, array $allow = []): array
    {
        $found = [];
        $add   = static function (string $table, string $kind, string $name, string $aspect, mixed $ref, mixed $have, bool $info = false) use (&$found, $allow): void {
            $key      = $table . '.' . $kind . '.' . $name . '.' . $aspect;
            $severity = $info ? self::INFO : self::DIFFERENCE;
            foreach ($allow as $pattern) {
                if (fnmatch((string) $pattern, $key)) {
                    $severity = self::ALLOWED;
                    break;
                }
            }
            $found[] = ['key' => $key, 'table' => $table, 'kind' => $kind, 'name' => $name, 'aspect' => $aspect, 'reference' => $ref, 'live' => $have, 'severity' => $severity];
        };

        foreach ($reference as $table => $shape) {
            if (!isset($live[$table])) {
                $add($table, 'table', $table, 'missing', true, false);
                continue;
            }
            $have = $live[$table];

            foreach ($shape['columns'] as $name => $column) {
                $theirs = $have['columns'][$name] ?? null;
                if ($theirs === null) {
                    $add($table, 'column', $name, 'missing', $column['type'], null);
                    continue;
                }
                foreach (['type', 'nullable', 'default', 'charset'] as $aspect) {
                    if (($column[$aspect] ?? null) !== ($theirs[$aspect] ?? null) && !($aspect === 'charset' && ($column[$aspect] ?? null) === null)) {
                        $add($table, 'column', $name, $aspect, $column[$aspect] ?? null, $theirs[$aspect] ?? null);
                    }
                }
            }
            foreach (array_diff_key($have['columns'], $shape['columns']) as $name => $column) {
                $add($table, 'column', $name, 'extra', null, $column['type'], true);
            }

            foreach ($shape['indexes'] as $columns => $index) {
                $theirs = $have['indexes'][$columns] ?? null;
                if ($theirs === null) {
                    $add($table, 'index', $columns, 'missing', $index['name'], null);
                } elseif ($theirs['unique'] !== $index['unique']) {
                    $add($table, 'index', $columns, 'unique', $index['unique'], $theirs['unique']);
                } elseif ($theirs['name'] !== $index['name']) {
                    $add($table, 'index', $columns, 'name', $index['name'], $theirs['name'], true);
                }
            }
            foreach (array_diff_key($have['indexes'], $shape['indexes']) as $columns => $index) {
                $add($table, 'index', $columns, 'extra', null, $index['name'], true);
            }

            foreach ($shape['foreign'] as $leads => $key) {
                $theirs = $have['foreign'][$leads] ?? null;
                if ($theirs === null) {
                    $add($table, 'foreign', $leads, 'missing', $key['rule'], null);
                } elseif ($theirs['rule'] !== $key['rule']) {
                    $add($table, 'foreign', $leads, 'rule', $key['rule'], $theirs['rule']);
                }
            }
            foreach (array_diff_key($have['foreign'], $shape['foreign']) as $leads => $key) {
                $add($table, 'foreign', $leads, 'extra', null, $key['rule'], true);
            }
        }

        return $found;
    }

    /**
     * Apply the differences that only add: a missing column, a missing index. Each is added as
     * the reference database declares it. Nothing is changed or removed, and a foreign key is
     * not added — rows that would break it are the installation's to look at first.
     *
     * @param array<string, mixed> $reference From {@see describe()} with definitions
     * @param list<array<string, mixed>> $differences From {@see compare()}
     * @return list<array{key: string, sql: string, error: ?string}> What was run, and how it went
     */
    public static function apply(Database $live, array $reference, array $differences): array
    {
        $done = [];
        foreach ($differences as $difference) {
            if ($difference['severity'] !== self::DIFFERENCE || $difference['aspect'] !== 'missing') {
                continue;
            }
            $table = $difference['table'];
            $sql   = match ($difference['kind']) {
                'column' => $reference[$table]['columns'][$difference['name']]['definition'] ?? null,
                'index'  => $reference[$table]['indexes'][$difference['name']]['definition'] ?? null,
                default  => null,
            };
            if ($sql === null || $sql === '') {
                continue;
            }
            try {
                $result = $live->query($sql);
                $error  = $result === false ? 'refused' : null;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $then = $difference['kind'] === 'column' ? ($reference[$table]['columns'][$difference['name']]['then'] ?? null) : null;
            if ($error === null && is_string($then)) {
                try {
                    $live->query($then);
                } catch (\Throwable) {
                    // Rows without a value: the column stays nullable, and the report says so.
                }
            }
            $done[] = ['key' => $difference['key'], 'sql' => $sql, 'error' => $error];
        }

        return $done;
    }

    /**
     * An integer type without its display width — `int(11) unsigned` is `int unsigned` — which
     * MySQL 8 no longer shows and MariaDB still does.
     */
    public static function normaliseType(string $type): string
    {
        return strtolower((string) preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/i', '$1', trim($type)));
    }

    /** @return array<string, array<string, mixed>> */
    private static function describeMysql(Database $db, bool $definitions): array
    {
        $tables = [];
        foreach (self::rows($db, "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $row) {
            $tables[(string) $row['t']] = ['columns' => [], 'indexes' => [], 'foreign' => []];
        }

        foreach (self::rows($db, 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, CHARACTER_SET_NAME AS cs'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            if (!isset($tables[$row['t']])) {
                continue;
            }
            $tables[$row['t']]['columns'][(string) $row['c']] = [
                'type'     => self::normaliseType((string) $row['type']),
                'nullable' => strtoupper((string) $row['n']) === 'YES',
                'default'  => $row['d'] === null ? null : (string) $row['d'],
                'charset'  => $row['cs'] === null ? null : strtolower((string) $row['cs']),
            ];
        }

        $indexes = [];
        foreach (self::rows($db, 'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, COLUMN_NAME AS c'
            . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $row) {
            $indexes[$row['t']][$row['i']]['columns'][] = strtolower((string) $row['c']);
            $indexes[$row['t']][$row['i']]['unique']    = (int) $row['nu'] === 0;
        }
        foreach ($indexes as $table => $byName) {
            foreach ($byName as $name => $index) {
                if (isset($tables[$table])) {
                    $tables[$table]['indexes'][implode(',', $index['columns'])] = ['name' => (string) $name, 'unique' => $index['unique']];
                }
            }
        }

        foreach (self::rows($db, 'SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS rule, k.CONSTRAINT_NAME AS name'
            . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME'
            . ' WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL') as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['foreign'][strtolower($row['c']) . '>' . $row['rt'] . '.' . strtolower((string) $row['rc'])] = ['rule' => strtoupper((string) $row['rule']), 'name' => (string) $row['name']];
            }
        }

        if ($definitions) {
            foreach (array_keys($tables) as $table) {
                self::mysqlDefinitions($db, $table, $tables[$table]);
            }
        }

        return $tables;
    }

    /**
     * The statements that add each column and index of a table, from `SHOW CREATE TABLE`, so
     * they are exactly what the database itself declares.
     *
     * @param array<string, mixed> $shape
     */
    private static function mysqlDefinitions(Database $db, string $table, array &$shape): void
    {
        $rows   = self::rows($db, 'SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
        $create = (string) (array_values($rows[0] ?? [])[1] ?? '');
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        foreach (preg_split('/\R/', $create) ?: [] as $line) {
            $line = rtrim(trim($line), ',');
            if (preg_match('/^`((?:[^`]|``)+)`\s/', $line, $m) === 1 && isset($shape['columns'][str_replace('``', '`', $m[1])])) {
                $shape['columns'][str_replace('``', '`', $m[1])]['definition'] = 'ALTER TABLE ' . $quoted . ' ADD COLUMN ' . $line;
            } elseif (preg_match('/^(UNIQUE |FULLTEXT |SPATIAL )?KEY `((?:[^`]|``)+)` \((.+)\)/', $line, $m) === 1) {
                foreach ($shape['indexes'] as $columns => $index) {
                    if ($index['name'] === str_replace('``', '`', $m[2])) {
                        $shape['indexes'][$columns]['definition'] = 'ALTER TABLE ' . $quoted . ' ADD ' . $line;
                    }
                }
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function describePostgres(Database $db, bool $definitions): array
    {
        $excluded = "'" . implode("','", self::SYSTEM_SCHEMAS) . "'";
        $name     = static fn (string $schema, string $table): string => $schema === 'public' ? $table : $schema . '.' . $table;
        $tables   = [];
        foreach (self::rows($db, 'SELECT table_schema AS s, table_name AS t FROM information_schema.tables'
            . " WHERE table_type = 'BASE TABLE' AND table_schema NOT IN (" . $excluded . ") AND table_schema NOT LIKE 'pg_temp%'") as $row) {
            $tables[$name($row['s'], $row['t'])] = ['columns' => [], 'indexes' => [], 'foreign' => [], 'schema' => $row['s'], 'table' => $row['t']];
        }

        foreach (self::rows($db, 'SELECT ns.nspname AS s, c.relname AS t, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS type,'
            . ' a.attnotnull AS notnull, pg_get_expr(d.adbin, d.adrelid) AS def'
            . ' FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace'
            . ' LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
            . " WHERE c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped ORDER BY ns.nspname, c.relname, a.attnum") as $row) {
            $table = $name($row['s'], $row['t']);
            if (!isset($tables[$table])) {
                continue;
            }
            $notNull = in_array($row['notnull'], [true, 't', 1, '1'], true);
            $tables[$table]['columns'][(string) $row['col']] = [
                'type'     => strtolower((string) $row['type']),
                'nullable' => !$notNull,
                'default'  => $row['def'] === null ? null : (string) $row['def'],
                'charset'  => null,
            ];
            if ($definitions) {
                $tables[$table]['columns'][(string) $row['col']]['definition'] = 'ALTER TABLE ' . self::pgQualified($row['s'], $row['t'])
                    . ' ADD COLUMN "' . str_replace('"', '""', (string) $row['col']) . '" ' . $row['type']
                    . ($row['def'] !== null ? ' DEFAULT ' . $row['def'] : '')
                    // NOT NULL only with a default: without one, a table with rows cannot take it.
                    . ($notNull && $row['def'] !== null ? ' NOT NULL' : '');
                // …and then asked for, which an empty table — or one whose rows got a value —
                // takes; refused, the difference stays in the report.
                $tables[$table]['columns'][(string) $row['col']]['then'] = $notNull && $row['def'] === null
                    ? 'ALTER TABLE ' . self::pgQualified($row['s'], $row['t']) . ' ALTER COLUMN "' . str_replace('"', '""', (string) $row['col']) . '" SET NOT NULL'
                    : null;
            }
        }

        foreach (self::rows($db, 'SELECT ns.nspname AS s, t.relname AS t, i.relname AS i, x.indisunique AS u, pg_get_indexdef(x.indexrelid) AS def,'
            . ' array_to_string(ARRAY(SELECT a.attname FROM unnest(x.indkey) WITH ORDINALITY AS k(attnum, n)'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum ORDER BY k.n), \',\') AS cols'
            . ' FROM pg_index x JOIN pg_class t ON t.oid = x.indrelid JOIN pg_class i ON i.oid = x.indexrelid'
            . ' JOIN pg_namespace ns ON ns.oid = t.relnamespace') as $row) {
            $table = $name($row['s'], $row['t']);
            if (!isset($tables[$table]) || (string) $row['cols'] === '') {
                continue;
            }
            $tables[$table]['indexes'][strtolower((string) $row['cols'])] = [
                'name'       => (string) $row['i'],
                'unique'     => in_array($row['u'], [true, 't', 1, '1'], true),
                'definition' => $definitions ? (string) $row['def'] : null,
            ];
        }

        foreach (self::rows($db, 'SELECT ns.nspname AS s, t.relname AS t, a.attname AS col, rns.nspname AS rs, r.relname AS rt, ra.attname AS rcol,'
            . ' c.confdeltype AS rule, c.conname AS name'
            . ' FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace'
            . ' JOIN pg_class r ON r.oid = c.confrelid JOIN pg_namespace rns ON rns.oid = r.relnamespace'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = c.conkey[1]'
            . ' JOIN pg_attribute ra ON ra.attrelid = r.oid AND ra.attnum = c.confkey[1]'
            . " WHERE c.contype = 'f'") as $row) {
            $table = $name($row['s'], $row['t']);
            if (isset($tables[$table])) {
                $rules = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];
                $tables[$table]['foreign'][strtolower((string) $row['col']) . '>' . $name($row['rs'], $row['rt']) . '.' . strtolower((string) $row['rcol'])] = [
                    'rule' => $rules[(string) $row['rule']] ?? (string) $row['rule'],
                    'name' => (string) $row['name'],
                ];
            }
        }

        foreach ($tables as $table => $shape) {
            unset($tables[$table]['schema'], $tables[$table]['table']);
        }

        return $tables;
    }

    private static function pgQualified(string $schema, string $table): string
    {
        return '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $table) . '"';
    }

    private static function isPostgres(Database $db): bool
    {
        return in_array(strtolower((string) $db->type), ['postgresql', 'pgsql', 'postgres', 'timescaledb'], true);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(Database $db, string $sql): array
    {
        $result = $db->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }
}
