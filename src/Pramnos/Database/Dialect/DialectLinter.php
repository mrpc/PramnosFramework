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
 *   An `ORDER BY` of such a column is one too, and so is one of a `SELECT DISTINCT` that orders
 *   by a column it does not select.
 * - **`double-quoted-string`** — `"…"` used as a value: a string to MySQL, a column name to
 *   PostgreSQL.
 * - **`implicit-cast`** — a text column compared with a number, which MySQL converts and
 *   PostgreSQL refuses. Only with the schema ({@see schema()}): without it, a text column cannot
 *   be told from a number.
 *
 * The SQL is read as it will run: a string with variables in it, strings joined with `.`, and a
 * variable built up with `.=` are each one piece of SQL, a variable standing in as `?`. Only what
 * reads as SQL is judged — a statement, or a fragment of one such as a datatable's WHERE — and
 * not prose, such as a prompt that says "like". A line ending in `// dialect-ok` is not reported.
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

    /** The shape of a statement, which prose does not take by chance. */
    private const STATEMENT = '/\bSELECT\s+[\s\S]*?\bFROM\s+[`"#\w]|\bINSERT\s+(?:IGNORE\s+)?INTO\s+[`"#\w]|\bUPDATE\s+[\w`."#]+\s+SET\s|\bDELETE\s+FROM\s/i';

    /** A sentence ending and the next beginning: prose. */
    private const SENTENCE = '/[a-z]{2,}["\')]?[.!?:]\s+["\'(]?[A-Z][a-z]/';

    /** Aggregates, under which a column needs no GROUP BY. */
    private const AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT', 'STRING_AGG', 'ARRAY_AGG', 'BIT_AND', 'BIT_OR', 'BOOL_AND', 'BOOL_OR', 'STDDEV', 'VARIANCE', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'ANY_VALUE'];

    /**
     * What a piece of SQL holds, with each finding's offset in it.
     *
     * @return list<array{offset: int, rule: string, message: string}>
     */
    public static function sql(string $sql, ?array $schema = null): array
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
        foreach (self::ungrouped($tokens, $significant, $schema) as [$k, $column, $why]) {
            $findings[] = ['offset' => $offsets[$k], 'rule' => 'group-by', 'message' => match ($why) {
                'select'   => $column . ' is selected but neither grouped nor aggregated: PostgreSQL refuses it (MySQL returned any one value) — GROUP BY it, or aggregate it',
                'order'    => $column . ' orders a grouped SELECT but is neither grouped nor aggregated: PostgreSQL refuses it — GROUP BY it, or order by an aggregate',
                default    => $column . ' orders a SELECT DISTINCT that does not select it: PostgreSQL refuses it — select it too, or order by what is selected',
            }];
        }
        if ($schema !== null) {
            foreach (self::casts($tokens, $significant, $schema['columns'] ?? []) as [$k, $column, $other]) {
                $findings[] = ['offset' => $offsets[$k], 'rule' => 'implicit-cast',
                    'message' => $column . ' is text, compared with ' . $other . ': MySQL converts one to the other, PostgreSQL refuses — compare like with like, or cast one side'];
            }
        }
        usort($findings, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $findings;
    }

    /**
     * What a PHP file's SQL holds, by line.
     *
     * @param array{columns: array<string, array<string, string>>, keys: array<string, list<string>>}|null $schema {@see schema()}
     * @return list<array{line: int, rule: string, message: string}>
     */
    public static function file(string $path, ?array $schema = null): array
    {
        $code    = (string) @file_get_contents($path);
        $lines   = explode("\n", $code);
        $tokens  = @token_get_all($code);
        $pieces  = [];
        $buffers = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            // A function's `$sql` is not the last one's.
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_FUNCTION, T_FN], true)) {
                array_push($pieces, ...array_values($buffers));
                $buffers = [];
                continue;
            }
            if (!self::opensString($tokens[$i])) {
                continue;
            }
            [$var, $operator] = self::assignedTo($tokens, $i);
            [$segments, $i]   = self::chain($tokens, $i);
            if ($var !== null && $operator === '=') {
                if (isset($buffers[$var])) {
                    $pieces[] = $buffers[$var];
                }
                $buffers[$var] = $segments;
            } elseif ($var !== null) {
                $buffers[$var] = [...($buffers[$var] ?? []), ...$segments];
            } else {
                $pieces[] = $segments;
            }
        }
        array_push($pieces, ...array_values($buffers));

        $findings = [];
        foreach ($pieces as $segments) {
            $text = implode('', array_column($segments, 'text'));
            if (!self::readsAsSql($text)) {
                continue;
            }
            foreach (self::sql($text, $schema) as $finding) {
                $line = self::lineAt($segments, $finding['offset']);
                if (str_contains($lines[$line - 1] ?? '', 'dialect-ok')) {
                    continue;
                }
                $findings[] = ['line' => $line, 'rule' => $finding['rule'], 'message' => $finding['message']];
            }
        }
        usort($findings, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * What the rules need of a live database's schema: each column's type, as a text or a
     * number, and each table's primary key — by table, lower-cased, and without the
     * application's prefix as well as with it.
     *
     * @return array{columns: array<string, array<string, string>>, keys: array<string, list<string>>}
     */
    public static function schema(\Pramnos\Database\Database $db): array
    {
        // information_schema introspection: the query builder has no catalogue reader for it.
        $pg      = $db->type === 'postgresql';
        $columns = $db->query($pg
            ? "SELECT table_name AS t, column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema NOT IN ('pg_catalog', 'information_schema')"
            : 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        $keys    = $db->query($pg
            ? "SELECT k.table_name AS t, k.column_name AS c FROM information_schema.key_column_usage k JOIN information_schema.table_constraints c ON c.constraint_name = k.constraint_name AND c.table_schema = k.table_schema WHERE c.constraint_type = 'PRIMARY KEY' ORDER BY k.ordinal_position"
            : "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
        $names   = static function (string $table) use ($db): array {
            $table = strtolower($table);

            return $db->prefix !== '' && str_starts_with($table, strtolower($db->prefix)) ? [$table, substr($table, strlen($db->prefix))] : [$table];
        };
        $out = ['columns' => [], 'keys' => []];
        while ($columns && $columns->fetch()) {
            $type  = strtolower((string) $columns->fields['d']);
            $class = preg_match('/char|text|enum|set/', $type) === 1 ? 'text'
                : (preg_match('/int|numeric|decimal|float|double|real|serial/', $type) === 1 ? 'number' : 'other');
            foreach ($names((string) $columns->fields['t']) as $table) {
                $out['columns'][$table][strtolower((string) $columns->fields['c'])] = $class;
            }
        }
        while ($keys && $keys->fetch()) {
            foreach ($names((string) $keys->fields['t']) as $table) {
                $out['keys'][$table][] = strtolower((string) $keys->fields['c']);
            }
        }

        return $out;
    }

    /** Whether a PHP token opens a string: a literal, a string with variables, a heredoc. */
    private static function opensString(mixed $token): bool
    {
        return $token === '"' || (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC], true));
    }

    /**
     * The variable a string at `$at` is assigned to or appended to — `$sql`, `$this->sql` — and
     * how: `=` or `.=`. [null, null] for a string that is not.
     *
     * @return array{?string, ?string}
     */
    private static function assignedTo(array $tokens, int $at): array
    {
        $k = self::previous($tokens, $at);
        if ($k === null || !($tokens[$k] === '=' || (is_array($tokens[$k]) && $tokens[$k][0] === T_CONCAT_EQUAL))) {
            return [null, null];
        }
        $operator = $tokens[$k] === '=' ? '=' : '.=';
        $name     = '';
        for ($j = self::previous($tokens, $k); $j !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true); $j = self::previous($tokens, $j)) {
            $name = $tokens[$j][1] . $name;
            if ($tokens[$j][0] === T_VARIABLE) {
                return [$name, $operator];
            }
        }

        return [null, null];
    }

    /**
     * The strings joined with `.` from `$at` on, as segments of text each with the line it starts
     * on — what is not a string standing in as `?` — and the index of the last token read.
     *
     * @return array{list<array{text: string, line: int}>, int}
     */
    private static function chain(array $tokens, int $at): array
    {
        $segments = [];
        $k        = $at;
        while (true) {
            [$read, $k] = self::operand($tokens, $k);
            array_push($segments, ...$read);
            $dot = self::next($tokens, $k);
            if ($dot === null || $tokens[$dot] !== '.') {
                return [$segments, $k];
            }
            $following = self::next($tokens, $dot);
            if ($following === null) {
                return [$segments, $dot];
            }
            $k = $following;
        }
    }

    /**
     * One operand of a `.` chain at `$at`: a string's text, or `?` for anything else (a variable,
     * a call), with the index of its last token.
     *
     * @return array{list<array{text: string, line: int}>, int}
     */
    private static function operand(array $tokens, int $at): array
    {
        $token = $tokens[$at];
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [[['text' => self::unescape(substr($token[1], 1, -1), $token[1][0] === '"'), 'line' => $token[2]]], $at];
        }
        if (self::opensString($token)) {
            $heredoc  = is_array($token);
            $double   = !$heredoc || !str_contains($token[1], "'");
            $segments = [];
            $line     = $heredoc ? $token[2] + 1 : self::lineOf($tokens, $at);
            for ($k = $at + 1, $count = count($tokens); $k < $count; $k++) {
                $t = $tokens[$k];
                if ($heredoc ? (is_array($t) && $t[0] === T_END_HEREDOC) : $t === '"') {
                    return [$segments, $k];
                }
                if (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $segments[] = ['text' => $double ? self::unescape($t[1], true) : $t[1], 'line' => $t[2]];
                    $line       = $t[2] + substr_count($t[1], "\n");
                } elseif (($segments === [] || end($segments)['text'] !== '?')) {
                    $segments[] = ['text' => '?', 'line' => is_array($t) ? $t[2] : $line];
                }
            }

            return [$segments, $count - 1];
        }
        // An expression: up to the `.`, `,`, `;` or closing bracket at its own depth.
        $line = self::lineOf($tokens, $at);
        for ($k = $at, $depth = 0, $count = count($tokens); $k < $count; $k++) {
            $t = $tokens[$k];
            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($t, ['.', ',', ';', '?', ':', '='], true)) {
                break;
            }
        }

        return [[['text' => '?', 'line' => $line]], max($at, self::previous($tokens, $k) ?? $at)];
    }

    /**
     * PHP's escapes of a quote, a backslash or a dollar, as the character with a space before it:
     * the SQL reads as it will run, and every line stays the source's.
     */
    private static function unescape(string $text, bool $double): string
    {
        return (string) preg_replace_callback($double ? '/\\\\(["\\\\$])/' : "/\\\\(['\\\\])/", static fn (array $m): string => ' ' . $m[1], $text);
    }

    /** Whether a piece of text is SQL to judge: SQL's shape, and not markup, an operator's name or prose. */
    private static function readsAsSql(string $text): bool
    {
        if (preg_match(self::SQL, $text) !== 1 || preg_match(self::MARKUP, $text) === 1 || preg_match('/^\s*\w+(?:\s+\w+)?\s*$/', $text) === 1) {
            return false;
        }
        // Prose — a prompt, a message — that happens to say "like" or "and the … is": sentences.
        // A statement in it is still SQL unless the sentences run on.
        $sentences = preg_match_all(self::SENTENCE, $text);

        return $sentences === 0 || ($sentences === 1 && preg_match(self::STATEMENT, $text) === 1);
    }

    /** The source line of an offset in the text joined from segments. */
    private static function lineAt(array $segments, int $offset): int
    {
        $start = 0;
        foreach ($segments as $n => $segment) {
            $length = strlen($segment['text']);
            if ($offset < $start + $length || $n === count($segments) - 1) {
                return $segment['line'] + substr_count(substr($segment['text'], 0, max(0, $offset - $start)), "\n");
            }
            $start += $length;
        }

        return 1;
    }

    /** The line a token is on: its own, or the nearest one before it that says. */
    private static function lineOf(array $tokens, int $at): int
    {
        for ($k = $at; $k >= 0; $k--) {
            if (is_array($tokens[$k])) {
                return $tokens[$k][2] + ($k < $at ? substr_count($tokens[$k][1], "\n") : 0);
            }
        }

        return 1;
    }

    /** The index of the next PHP token that is not whitespace or a comment, after `$from`. */
    private static function next(array $tokens, int $from): ?int
    {
        for ($k = $from + 1, $count = count($tokens); $k < $count; $k++) {
            if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $k;
            }
        }

        return null;
    }

    /** The index of the previous PHP token that is not whitespace or a comment, before `$from`. */
    private static function previous(array $tokens, int $from): ?int
    {
        for ($k = $from - 1; $k >= 0; $k--) {
            if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Columns PostgreSQL refuses where MySQL picked a value: in a grouped SELECT, one selected
     * or ordered by that is neither grouped nor aggregated; in a `SELECT DISTINCT`, one ordered
     * by that is not selected. Each with the index of its token and which it is — `select`,
     * `order` or `distinct`. Only bare columns (`name`, `a.name`, with or without an alias) are
     * judged; an expression is not guessed at.
     *
     * A column of a table whose primary key is grouped is grouped too, as PostgreSQL has it. With
     * the schema that is known; without it, a grouped column whose name ends in `id` is taken for
     * its table's key, and that table's columns — or, when it is not qualified, every unqualified
     * one — are not reported.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @param list<int>                         $significant
     * @return list<array{int, string, string}>
     */
    private static function ungrouped(array $tokens, array $significant, ?array $schema): array
    {
        $tables = self::tables($tokens, $significant);
        $found  = [];
        $count = count($tokens);
        for ($select = 0; $select < $count; $select++) {
            if ($tokens[$select]['t'] !== 'word' || strcasecmp($tokens[$select]['v'], 'SELECT') !== 0) {
                continue;
            }
            // This SELECT's clauses, at its own depth.
            $at  = [];
            $end = $count;
            for ($k = $select + 1, $depth = 0; $k < $count; $k++) {
                $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
                if ($depth < 0 || ($depth === 0 && $tokens[$k]['v'] === ';')) {
                    $end = $k;
                    break;
                }
                if ($depth === 0 && $tokens[$k]['t'] === 'word') {
                    $at[strtoupper($tokens[$k]['v'])] ??= $k;
                }
            }
            if (!isset($at['FROM']) || isset($at['UNION'])) {
                continue;
            }
            $after = static function (int $from, array $words) use ($at, $end): int {
                $next = array_filter(array_intersect_key($at, array_flip($words)), static fn (int $k): bool => $k > $from);

                return $next === [] ? $end : min($next);
            };
            $items   = self::items($tokens, $select + 1, $at['FROM'], null);
            $aliases = [];
            $listed  = [];
            foreach ($items as [, $text, $item]) {
                [$column, $alias] = self::column($item);
                if ($alias !== null) {
                    $aliases[strtolower($alias)] = true;
                }
                if ($column !== null) {
                    $listed[strtolower(trim($column, '"`'))] = true;
                    $listed[self::name($column)]             = true;
                }
                if ($column === '*' || str_ends_with((string) $column, '.*') || trim($text) === '*') {
                    $listed['*'] = true;
                }
            }
            $order = isset($at['ORDER']) ? self::items($tokens, $at['ORDER'] + 1, $after($at['ORDER'], ['LIMIT', 'OFFSET', 'FOR', 'FETCH']), 'BY') : [];

            if (isset($at['GROUP'])) {
                $grouped = [];
                $keyed   = [];
                foreach (self::items($tokens, $at['GROUP'] + 1, $after($at['GROUP'], ['HAVING', 'ORDER', 'LIMIT', 'WINDOW']), 'BY') as [, $text]) {
                    $grouped[strtolower(trim($text, '"`'))] = true;
                    $grouped[self::name($text)]            = true;
                    if ($schema === null && preg_match('/id$/i', self::name($text)) === 1) {
                        $keyed[self::qualifier($text) ?? ''] = true;
                    }
                }
                if ($schema !== null) {
                    // The tables whose every key column is grouped.
                    foreach (array_unique($tables) as $table) {
                        $key = $schema['keys'][$table] ?? [];
                        if ($key !== [] && array_diff(array_map(static fn (string $c): string => self::resolve($c, $tables, $schema) . '.' . $c, $key),
                            array_map(static fn (string $g): string => self::resolve($g, $tables, $schema) . '.' . self::name($g), array_keys($grouped))) === []) {
                            $keyed[$table] = true;
                        }
                    }
                }
                $judged = [];
                foreach ($items as [$k, , $item]) {
                    $judged[] = [$k, self::column($item)[0], 'select', $item];
                }
                foreach ($order as [$k, , $item]) {
                    $judged[] = [$k, self::column($item)[0], 'order', $item];
                }
                foreach ($judged as [$k, $column, $why, $item]) {
                    if ($column === null || self::aggregated($item)) {
                        continue;
                    }
                    $bare = strtolower(trim($column, '"`'));
                    $of   = $schema !== null ? self::resolve($column, $tables, $schema) : (self::qualifier($column) ?? '');
                    if ($column === '*' || str_ends_with($column, '.*') || isset($keyed[$of]) || ($schema === null && $of === '' && $keyed !== [])) {
                        continue;
                    }
                    if (!isset($grouped[$bare]) && !isset($grouped[self::name($column)]) && !($why === 'order' && isset($aliases[$bare]))) {
                        $found[] = [$k, $column, $why];
                    }
                }
            } elseif (($first = self::nextSignificant($tokens, $select)) !== null && strcasecmp($tokens[$first]['v'], 'DISTINCT') === 0 && !isset($listed['*'])) {
                foreach ($order as [$k, , $item]) {
                    $column = self::column($item)[0];
                    $bare   = strtolower(trim((string) $column, '"`'));
                    if ($column !== null && !isset($listed[$bare]) && !isset($aliases[$bare]) && !isset($listed[self::name($column)]) && !isset($aliases[self::name($column)])) {
                        $found[] = [$k, $column, 'distinct'];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * An item's bare column — `name`, `a.name`, `"name"`, before `AS`, `ASC`, `DESC` — and its
     * alias; the column is null when the item is an expression, a number or a literal.
     *
     * @param list<array{t: string, v: string}> $item
     * @return array{?string, ?string}
     */
    private static function column(array $item): array
    {
        $significant = array_values(array_filter($item, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
        $parts       = [];
        $alias       = null;
        foreach ($significant as $n => $t) {
            $word = strtoupper($t['v']);
            if ($word === 'DISTINCT' && $parts === []) {
                continue;
            }
            if (in_array($word, ['AS', 'ASC', 'DESC', 'NULLS'], true)) {
                $alias = $word === 'AS' && isset($significant[$n + 1]) ? trim($significant[$n + 1]['v'], '"`') : null;
                break;
            }
            $parts[] = $t;
        }
        $column = trim(implode('', array_column($parts, 'v')));
        if ($column === '*' || str_ends_with($column, '.*')) {
            return [$column, $alias];
        }
        // An alias without AS: `name who`, `a.name who`.
        $n = count($parts);
        if ($alias === null && $n >= 2 && in_array($parts[$n - 1]['t'], ['word', 'ident', 'btick'], true) && $parts[$n - 2]['v'] !== '.') {
            $alias  = trim($parts[$n - 1]['v'], '"`');
            $column = trim(implode('', array_column(array_slice($parts, 0, -1), 'v')));
        }
        if ($parts === [] || preg_match('/^(?:[A-Za-z_"`][\w"`]*\.)?[A-Za-z_"`][\w"`]*$/', $column) !== 1
            || in_array(strtoupper($column), ['NULL', 'TRUE', 'FALSE'], true)) {
            return [null, $alias];
        }

        return [$column, $alias];
    }

    /** A column's name without its table: `a.name` as `name`, lower-cased. */
    private static function name(string $column): string
    {
        return strtolower(trim((string) substr(strrchr('.' . $column, '.'), 1), '"`'));
    }

    /** Whether an item is under an aggregate. */
    private static function aggregated(array $item): bool
    {
        return array_intersect(array_map(static fn (array $t): string => strtoupper($t['v']), $item), self::AGGREGATES) !== [];
    }

    /** The index of the next SQL token that is not whitespace or a comment, after `$from`. */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($k = $from + 1, $count = count($tokens); $k < $count; $k++) {
            if (!in_array($tokens[$k]['t'], ['ws', 'comment'], true)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Comparisons of a text column with a number — a number, or a column the schema says is
     * one — each with the index of the text column's token, its name, and what it met.
     *
     * @param list<array{t: string, v: string}>    $tokens
     * @param list<int>                             $significant
     * @param array<string, array<string, string>> $schema
     * @return list<array{int, string, string}>
     */
    private static function casts(array $tokens, array $significant, array $schema): array
    {
        $tables = self::tables($tokens, $significant);
        // A side's class: a number, or a column the schema knows.
        $classOf = static function (int $n) use ($tokens, $significant, $schema, $tables): ?array {
            $k = $significant[$n] ?? null;
            if ($k === null) {
                return null;
            }
            if ($tokens[$k]['t'] === 'num') {
                return ['number', $tokens[$k]['v'], $k];
            }
            if (!in_array($tokens[$k]['t'], ['word', 'ident', 'btick'], true)) {
                return null;
            }
            $column = strtolower(trim($tokens[$k]['v'], '"`'));
            $table  = null;
            $dot    = $significant[$n + 1] ?? null;
            if ($dot !== null && $tokens[$dot]['v'] === '.' && isset($significant[$n + 2])) {
                $table  = $tables[$column] ?? $column;
                $k      = $significant[$n + 2];
                $column = strtolower(trim($tokens[$k]['v'], '"`'));
            }
            $candidates = $table !== null ? [$table] : array_values(array_unique($tables));
            $classes    = array_unique(array_filter(array_map(static fn (string $t): ?string => $schema[$t][$column] ?? null, $candidates)));

            return count($classes) === 1 ? [reset($classes), ($table !== null ? $tokens[$significant[$n]]['v'] . '.' : '') . $tokens[$k]['v'], $k] : null;
        };
        $found = [];
        foreach ($significant as $n => $k) {
            // An operator's first character: =, <, >, or the ! of !=; <=, >=, <> are two.
            $second = isset($significant[$n + 1]) && in_array($tokens[$significant[$n + 1]]['v'], ['=', '>'], true);
            if (!in_array($tokens[$k]['v'], ['=', '<', '>', '!'], true) || ($tokens[$k]['v'] === '!' && !$second)
                || ($n > 0 && in_array($tokens[$significant[$n - 1]]['v'], ['<', '>', '!', '='], true))) {
                continue;
            }
            $right = $n + 1 + ($second && $tokens[$k]['v'] !== '=' ? 1 : 0);
            $left  = $n - 1;
            // The left side starts where its qualifier does: a.b is three tokens.
            $leftStart = $left >= 2 && $tokens[$significant[$left - 1]]['v'] === '.' ? $left - 2 : $left;
            $a = $classOf($leftStart);
            $b = $classOf($right);
            if ($a === null || $b === null || [$a[0], $b[0]] !== ['text', 'number'] && [$a[0], $b[0]] !== ['number', 'text']) {
                continue;
            }
            [$text, $other] = $a[0] === 'text' ? [$a, $b] : [$b, $a];
            $found[] = [$text[2], $text[1], $other[0] === 'number' && preg_match('/^[\d.]+$/', $other[1]) === 1 ? 'the number ' . $other[1] : $other[1] . ', a number'];
        }

        return $found;
    }

    /**
     * Which table each name in FROM and JOIN stands for — `users u`, `#PREFIX#users AS u`, and
     * the table by its own name — without the prefix, lower-cased.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @param list<int>                         $significant
     * @return array<string, string>
     */
    private static function tables(array $tokens, array $significant): array
    {
        $tables = [];
        foreach ($significant as $n => $k) {
            if ($tokens[$k]['t'] !== 'word' || !in_array(strtoupper($tokens[$k]['v']), ['FROM', 'JOIN'], true) || !isset($significant[$n + 1])) {
                continue;
            }
            [$name, $took] = self::run($tokens, $significant, $n + 1);
            if ($took === 0) {
                continue;
            }
            $table          = (string) substr(strrchr('.' . str_replace(['#prefix#', '"', '`'], '', strtolower($name)), '.'), 1);
            $tables[$table] = $table;
            $m              = $n + 1 + $took;
            if (isset($significant[$m]) && strcasecmp($tokens[$significant[$m]]['v'], 'AS') === 0) {
                $m++;
            }
            $alias = $significant[$m] ?? null;
            if ($alias !== null && in_array($tokens[$alias]['t'], ['word', 'ident', 'btick'], true)
                && !in_array(strtoupper($tokens[$alias]['v']), ['WHERE', 'ON', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'GROUP', 'ORDER', 'LIMIT', 'SET', 'USING', 'HAVING', 'CROSS', 'NATURAL', 'UNION', 'STRAIGHT_JOIN'], true)) {
                $tables[strtolower(trim($tokens[$alias]['v'], '"`'))] = $table;
            }
        }

        return $tables;
    }

    /** A column's qualifier — `a` of `a.name` — lower-cased; null for a bare name. */
    private static function qualifier(string $column): ?string
    {
        return str_contains($column, '.') ? strtolower(trim((string) strstr($column, '.', true), '"`')) : null;
    }

    /**
     * The table a column belongs to: its qualifier's, or the one table of the statement the
     * schema says has a column of that name. '' when it cannot be told.
     *
     * @param array<string, string> $tables
     */
    private static function resolve(string $column, array $tables, array $schema): string
    {
        $qualifier = self::qualifier($column);
        if ($qualifier !== null) {
            return $tables[$qualifier] ?? $qualifier;
        }
        $name = self::name($column);
        $with = array_values(array_unique(array_filter($tables, static fn (string $t): bool => isset($schema['columns'][$t][$name]))));

        return count($with) === 1 ? $with[0] : '';
    }

    /**
     * A table's name, which may run over several tokens (`#PREFIX#users`, `s.t`), from the
     * significant token `$n` on: its text and how many tokens it took.
     *
     * @return array{string, int}
     */
    private static function run(array $tokens, array $significant, int $n): array
    {
        $text = '';
        $took = 0;
        for ($m = $n; isset($significant[$m]); $m++) {
            $t = $tokens[$significant[$m]];
            if (!in_array($t['t'], ['word', 'ident', 'btick'], true) && !in_array($t['v'], ['.', '#'], true)) {
                break;
            }
            if ($m > $n && $significant[$m] !== $significant[$m - 1] + 1) {
                break;
            }
            $text .= $t['v'];
            $took++;
        }

        return [$text, $took];
    }

    /**
     * The comma-separated items between two token indexes, at their own depth, each with the
     * index of its first significant token, its text, and its tokens. `$lead` is a word to skip
     * first (GROUP's BY).
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
                $items[] = [$significant[0][0], trim(implode('', array_map(static fn (array $p): string => $p[1]['v'], $current))), array_column($current, 1)];
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
