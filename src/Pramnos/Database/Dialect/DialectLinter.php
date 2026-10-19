<?php

declare(strict_types=1);

namespace Pramnos\Database\Dialect;

/**
 * SQL written for MySQL that would run on PostgreSQL and mean something else — what
 * {@see MysqlToPostgres} leaves alone on purpose — named with its file and line.
 *
 * ```bash
 * php bin/pramnos db:dialect-lint src app
 * ```
 *
 * - **`like`** — `LIKE` ignores case under MySQL's usual `_ci` collations and does not on
 *   PostgreSQL: a search for `maria` stops finding `Maria`. `ILIKE`, or `lower()` on both sides.
 * - **`group-by`** — a column in the SELECT list that is neither grouped nor aggregated. MySQL
 *   returns one of the group's values; PostgreSQL refuses it, unless the group is the table's
 *   primary key.
 * - **`double-quoted-string`** — `"…"` used as a value: a string to MySQL, a column name to
 *   PostgreSQL.
 *
 * A text column compared with a number is not looked for: without the schema it cannot be told
 * from a number compared with a number, and PostgreSQL refuses it with an error rather than
 * answering differently — the suite run against PostgreSQL finds it.
 *
 * The SQL is read from the PHP's string literals, a statement or a fragment of one (a datatable's
 * WHERE): only those that read as SQL. A line ending in `// dialect-ok` is not reported.
 */
final class DialectLinter
{
    /**
     * A string reads as SQL when it has a statement's shape, or a condition's — a datatable's
     * WHERE — not when it merely holds the word "delete".
     */
    private const SQL = '/\bSELECT\b[\s\S]*\bFROM\b|\bINSERT\s+(?:IGNORE\s+)?INTO\b|\bUPDATE\s+[\w`."#]+\s+SET\b|\bDELETE\s+FROM\b|\bGROUP\s+BY\b'
        . '|\bLIKE\s*(?:[\'"%?]|$)|\b(?:WHERE|AND|OR)\s+[\w`."]+\s*(?:=|<>|!=|<=|>=|\bIN\b|\bLIKE\b|\bIS\b)/i';

    /** Markup — a template, an XML file — is not SQL, whatever words it holds. */
    private const MARKUP = '/<[a-z!?\/][^>]*>/i';

    /** Aggregates, under which a column needs no GROUP BY. */
    private const AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT', 'STRING_AGG', 'ARRAY_AGG', 'BIT_AND', 'BIT_OR', 'BOOL_AND', 'BOOL_OR', 'STDDEV', 'VARIANCE', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'ANY_VALUE'];

    /**
     * What a piece of SQL holds, with each finding's offset in it.
     *
     * @return list<array{offset: int, rule: string, message: string}>
     */
    public static function sql(string $sql): array
    {
        $tokens   = MysqlToPostgres::tokenize($sql);
        $findings = [];
        $offset   = 0;
        $offsets  = [];
        foreach ($tokens as $k => $token) {
            $offsets[$k] = $offset;
            $offset     += strlen($token['v']);
        }
        $significant = array_values(array_filter(array_keys($tokens), static fn (int $k): bool => !in_array($tokens[$k]['t'], ['ws', 'comment'], true)));
        foreach ($significant as $n => $k) {
            $token = $tokens[$k];
            $prev  = $n > 0 ? $tokens[$significant[$n - 1]] : null;
            // A LIKE of SQL's: a pattern after it, a placeholder, or the end of a fragment a value
            // is joined to — not the word in a sentence.
            $after = $significant[$n + 1] ?? null;
            if ($token['t'] === 'word' && strcasecmp($token['v'], 'LIKE') === 0
                && ($after === null || in_array($tokens[$after]['t'], ['str', 'ph'], true) || in_array(strtoupper($tokens[$after]['v']), ['CONCAT', '?', '('], true))) {
                $findings[] = ['offset' => $offsets[$k], 'rule' => 'like',
                    'message' => 'LIKE ignores case on MySQL (a _ci collation) and not on PostgreSQL: ILIKE, or lower() on both sides'];
            }
            if ($token['t'] === 'ident' && $prev !== null
                && (in_array($prev['v'], ['=', '>', '<', ',', '('], true) || in_array(strtoupper($prev['v']), ['LIKE', 'IN', 'VALUES', 'THEN', 'ELSE'], true))) {
                $findings[] = ['offset' => $offsets[$k], 'rule' => 'double-quoted-string',
                    'message' => $token['v'] . ' is a string to MySQL and a column name to PostgreSQL: single quotes'];
            }
        }
        foreach (self::ungrouped($tokens) as [$k, $column]) {
            $findings[] = ['offset' => $offsets[$k], 'rule' => 'group-by',
                'message' => $column . ' is selected but neither grouped nor aggregated: PostgreSQL refuses it (MySQL returned any one value) — GROUP BY it, or aggregate it'];
        }
        usort($findings, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $findings;
    }

    /**
     * What a PHP file's SQL holds, by line.
     *
     * @return list<array{line: int, rule: string, message: string}>
     */
    public static function file(string $path): array
    {
        $code     = (string) @file_get_contents($path);
        $lines    = explode("\n", $code);
        $findings = [];
        foreach (@token_get_all($code) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            // PHP's escapes of a quote or a backslash, as the character with a space before it:
            // the SQL reads as it will run, and every offset stays the source's.
            $double = $token[0] === T_ENCAPSED_AND_WHITESPACE || ($token[1][0] ?? '') === '"';
            $text   = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
            $text   = (string) preg_replace_callback($double ? '/\\\\(["\\\\$])/' : "/\\\\(['\\\\])/", static fn (array $m): string => ' ' . $m[1], $text);
            // An operator's name alone — 'LIKE', 'NOT LIKE' in a list of them — is not SQL to judge.
            if (preg_match(self::SQL, $text) !== 1 || preg_match(self::MARKUP, $text) === 1 || preg_match('/^\s*\w+(?:\s+\w+)?\s*$/', $text) === 1) {
                continue;
            }
            $skip = $token[0] === T_CONSTANT_ENCAPSED_STRING ? 1 : 0;
            foreach (self::sql($text) as $finding) {
                $line = $token[2] + substr_count(substr($token[1], 0, $finding['offset'] + $skip), "\n");
                if (str_contains($lines[$line - 1] ?? '', 'dialect-ok')) {
                    continue;
                }
                $findings[] = ['line' => $line, 'rule' => $finding['rule'], 'message' => $finding['message']];
            }
        }

        return $findings;
    }

    /**
     * Columns of a grouped SELECT that are neither grouped nor under an aggregate: each with the
     * index of its token. Only bare columns (`name`, `a.name`, with or without an alias) are
     * judged; an expression is not guessed at.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{int, string}>
     */
    private static function ungrouped(array $tokens): array
    {
        $found = [];
        $count = count($tokens);
        for ($select = 0; $select < $count; $select++) {
            if ($tokens[$select]['t'] !== 'word' || strcasecmp($tokens[$select]['v'], 'SELECT') !== 0) {
                continue;
            }
            // This SELECT's FROM and GROUP BY, at its own depth.
            $from = $group = $end = null;
            for ($k = $select + 1, $depth = 0; $k < $count; $k++) {
                $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
                if ($depth < 0) {
                    $end = $k;
                    break;
                }
                if ($depth !== 0 || $tokens[$k]['t'] !== 'word') {
                    continue;
                }
                $word = strtoupper($tokens[$k]['v']);
                if ($word === 'FROM' && $from === null) {
                    $from = $k;
                } elseif ($word === 'GROUP' && $group === null) {
                    $group = $k;
                } elseif ($group !== null && in_array($word, ['HAVING', 'ORDER', 'LIMIT', 'UNION', 'WINDOW'], true)) {
                    $end = $k;
                    break;
                }
            }
            if ($from === null || $group === null) {
                continue;
            }
            $grouped = [];
            foreach (self::items($tokens, $group + 1, $end ?? $count, 'BY') as [, $text]) {
                $grouped[strtolower($text)] = true;
                $grouped[strtolower((string) substr(strrchr('.' . $text, '.'), 1))] = true;
            }
            foreach (self::items($tokens, $select + 1, $from, null) as [$at, $text, $item]) {
                $significant = array_values(array_filter($item, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
                // A bare column: name, a.name, "name" — optionally AS alias, or an alias alone.
                $parts = [];
                foreach ($significant as $t) {
                    if (in_array(strtoupper($t['v']), ['AS', 'DISTINCT'], true)) {
                        break;
                    }
                    $parts[] = $t;
                }
                $column = trim(implode('', array_column($parts, 'v')));
                if ($parts === [] || preg_match('/^(?:[A-Za-z_"`][\w"`]*\.)?[A-Za-z_"`][\w"`]*$/', $column) !== 1 || $column === '*' || str_ends_with($column, '.*')) {
                    continue;
                }
                $bare = strtolower(trim($column, '"`'));
                $name = strtolower(trim((string) substr(strrchr('.' . $column, '.'), 1), '"`'));
                if (!isset($grouped[$bare]) && !isset($grouped[$name]) && !in_array(strtoupper($column), ['NULL', 'TRUE', 'FALSE'], true)) {
                    $found[] = [$at, $column];
                }
            }
        }

        return $found;
    }

    /**
     * The comma-separated items between two token indexes, at their own depth, each with the
     * index of its first significant token, its text, and its tokens. Aggregated items are left
     * out. `$lead` is a word to skip first (GROUP's BY).
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{int, string, list<array{t: string, v: string}>}>
     */
    private static function items(array $tokens, int $from, int $to, ?string $lead): array
    {
        $items   = [];
        $current = [];
        $flush   = static function () use (&$items, &$current): void {
            $significant = array_values(array_filter($current, static fn (array $p): bool => !in_array($p[1]['t'], ['ws', 'comment'], true)));
            if ($significant !== []) {
                $words = array_map(static fn (array $p): string => strtoupper($p[1]['v']), $significant);
                if (array_intersect($words, self::AGGREGATES) === []) {
                    $items[] = [$significant[0][0], trim(implode('', array_map(static fn (array $p): string => $p[1]['v'], $current))), array_column($current, 1)];
                }
            }
            $current = [];
        };
        for ($k = $from, $depth = 0; $k < $to; $k++) {
            $token = $tokens[$k];
            $nothingYet = $items === [] && array_filter($current, static fn (array $p): bool => !in_array($p[1]['t'], ['ws', 'comment'], true)) === [];
            if ($lead !== null && $nothingYet && strcasecmp($token['v'], $lead) === 0) {
                continue;
            }
            $depth += $token['v'] === '(' ? 1 : ($token['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $token['v'] === ',' && $token['t'] === 'op') {
                $flush();
                continue;
            }
            $current[] = [$k, $token];
        }
        $flush();

        return $items;
    }
}
