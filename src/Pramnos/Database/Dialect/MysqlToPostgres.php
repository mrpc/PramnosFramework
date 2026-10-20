<?php

declare(strict_types=1);

namespace Pramnos\Database\Dialect;

/**
 * MySQL's SQL as PostgreSQL takes it — for an application moving to PostgreSQL with hand-written
 * SQL that is mechanically translatable, so it is not rewritten by hand.
 *
 * ```php
 * 'database' => ['type' => 'postgresql', 'translate_mysql' => true]
 * ```
 *
 * Only what has one meaning on both sides is translated:
 *
 * | MySQL | PostgreSQL |
 * |---|---|
 * | `` `name` `` | `"name"` |
 * | `IFNULL(a, b)` | `COALESCE(a, b)` |
 * | `RAND()` | `random()` |
 * | `LIMIT 10, 20` | `LIMIT 20 OFFSET 10` |
 * | `UNIX_TIMESTAMP()`, `UNIX_TIMESTAMP(x)` | `EXTRACT(EPOCH FROM …)::bigint` |
 * | `FROM_UNIXTIME(x)`, `FROM_UNIXTIME(x, fmt)` | `to_timestamp(x)`, `to_char(to_timestamp(x), …)` |
 * | `CURDATE()`, `CURTIME()` | `CURRENT_DATE`, `LOCALTIME` |
 * | `DATE_FORMAT(d, '%Y-%m-%d')` | `to_char(d, 'YYYY-MM-DD')` |
 * | `GROUP_CONCAT([DISTINCT] x [ORDER BY …] [SEPARATOR s])` | `string_agg([DISTINCT] (x)::text, s [ORDER BY …])` |
 * | `CONCAT(a, b, …)` | `((a)::text || (b)::text || …)` — NULL when any is, as in MySQL |
 * | `INSERT IGNORE …` | `INSERT … ON CONFLICT DO NOTHING` |
 * | `… ON DUPLICATE KEY UPDATE c = VALUES(c)` | `… ON CONFLICT (key) DO UPDATE SET c = EXCLUDED.c` |
 * | `REPLACE INTO t (…) VALUES …` | `INSERT INTO t (…) VALUES … ON CONFLICT (key) DO UPDATE SET …` |
 * | `SHA2(x, 256)` | `encode(sha256(convert_to((x)::text, 'UTF8')), 'hex')` |
 * | `CAST(x AS SIGNED)`, `… AS UNSIGNED` | `CAST(x AS bigint)` |
 * | `DAY(d)`, `MONTH(d)`, `YEAR(d)` | `EXTRACT(DAY FROM d)::int`, … |
 * | `GET_LOCK(name, t)`, `RELEASE_LOCK(name)` | advisory locks on `hashtext(name)`, as 1 or 0 |
 * | `JSON_EXTRACT(doc, '$.a[0]')`, `JSON_UNQUOTE(…)` | `(doc)::jsonb #> '{a,0}'`, `… #>> '{}'` |
 * | `DELETE a FROM t a JOIN u b ON … WHERE …` | `DELETE FROM t a USING u b WHERE (…) AND (…)` |
 * | `… HAVING alias > 5` | the aliased expression in its place |
 * | `SET FOREIGN_KEY_CHECKS = 0` / `1` | `SET session_replication_role = 'replica'` / `'origin'` |
 * | `a REGEXP p`, `a RLIKE p`, `a NOT REGEXP p` | `a ~* p`, `a ~* p`, `a !~* p` |
 *
 * What would change meaning silently is not translated — a `LIKE` that ignored case, a `GROUP BY`
 * over columns it does not name, `"…"` as a string — and is the dialect linter's to report. Each
 * construct translated is invalid in PostgreSQL, so SQL already written for it passes untouched
 * and a second pass changes nothing. String literals are never altered: they are only recognised,
 * MySQL's backslash escapes included, so nothing inside one is mistaken for SQL.
 *
 * The conflict target of `ON DUPLICATE KEY UPDATE` and `REPLACE INTO` is the table's unique key
 * whose columns the insert names — asked of `$uniqueKeys`. Where two such keys qualify, or none,
 * the statement is refused with {@see DialectException} rather than guessed: MySQL fires on any
 * of them, PostgreSQL on the one named.
 */
final class MysqlToPostgres
{
    /** MySQL's `DATE_FORMAT` specifiers, as `to_char`'s. */
    private const FORMAT = [
        'Y' => 'YYYY', 'y' => 'YY', 'm' => 'MM', 'c' => 'FMMM', 'd' => 'DD', 'e' => 'FMDD',
        'H' => 'HH24', 'k' => 'FMHH24', 'h' => 'HH12', 'I' => 'HH12', 'l' => 'FMHH12',
        'i' => 'MI', 's' => 'SS', 'S' => 'SS', 'p' => 'AM', 'M' => 'FMMonth', 'b' => 'Mon',
        'W' => 'FMDay', 'a' => 'Dy', 'j' => 'DDD', 'f' => 'US', 'T' => 'HH24:MI:SS',
        'r' => 'HH12:MI:SS AM', 'D' => 'FMDDth', 'u' => 'IW', 'v' => 'IW', 'x' => 'IYYY', 'X' => 'IYYY',
    ];

    /** The functions translated. */
    private const FUNCTIONS = ['IFNULL', 'IF', 'ISNULL', 'LCASE', 'UCASE', 'RAND', 'CURDATE', 'CURTIME', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATE_FORMAT', 'DATE_ADD', 'DATE_SUB', 'GROUP_CONCAT', 'CONCAT',
        'SHA2', 'CAST', 'DAY', 'MONTH', 'YEAR', 'GET_LOCK', 'RELEASE_LOCK', 'JSON_EXTRACT', 'JSON_UNQUOTE'];

    /** Words a statement this translates may contain; one of them, or a backtick, or nothing to do. */
    private const MARKERS = '/`|\bLIMIT\s+[^\s,]+\s*,|\b(?:IF|ISNULL|LCASE|UCASE|DATE_ADD|DATE_SUB)\s*\(|\b(?:IFNULL|RAND|UNIX_TIMESTAMP|FROM_UNIXTIME|CURDATE|CURTIME|DATE_FORMAT|GROUP_CONCAT|IGNORE|DUPLICATE|REPLACE|CONFLICT|HAVING|FOREIGN_KEY_CHECKS|REGEXP|RLIKE)\b|\b(?:CONCAT|SHA2|DAY|MONTH|YEAR|GET_LOCK|RELEASE_LOCK|JSON_EXTRACT|JSON_UNQUOTE)\s*\(|\bAS\s+(?:UN)?SIGNED\b|^\s*DELETE\s+(?!FROM\b)/i';

    /** @var array<string, string> Statements translated, by their text */
    private array $cache = [];

    /**
     * @param (callable(string): list<list<string>>)|null $uniqueKeys A table's unique keys (the
     *        primary key among them), each as its columns — for the conflict target
     * @param (callable(string): list<string>)|null       $columnsOf  A table's columns — for the
     *        row an upsert's update refers to, which PostgreSQL wants named
     */
    public function __construct(private $uniqueKeys = null, private $columnsOf = null)
    {
    }

    /** Whether the statement being translated is written for printf, `%%` for a percent. */
    private bool $percentEscaped = false;

    /**
     * {@see translate()}, for a statement written for printf — `Database::prepareQuery()`'s, where
     * `%%` is a percent and `%s` a value still to come.
     *
     * A `DATE_FORMAT` pattern is then `'%%Y-%%m'`, which read as MySQL wrote it is a literal
     * percent and a `Y`: the translation kept the text, printf then met `%Y`, and the statement
     * failed with "Unknown format specifier". Here the pattern is read with `%%` as `%`, and what
     * comes out has its percents doubled again, so printf gives back `to_char`'s pattern.
     */
    public function translatePercentEscaped(string $sql): string
    {
        $this->percentEscaped = true;
        try {
            return $this->translate($sql);
        } finally {
            $this->percentEscaped = false;
        }
    }

    /**
     * The statement in PostgreSQL's dialect.
     *
     * @throws DialectException For an upsert whose conflict target cannot be told
     */
    public function translate(string $sql): string
    {
        if (preg_match(self::MARKERS, $sql) !== 1) {
            return $sql;
        }
        // The same text means another thing written for printf: kept apart.
        $key = ($this->percentEscaped ? "%\0" : '') . $sql;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        // Foreign keys off for the session: PostgreSQL's way is to act as a replica, which fires
        // no trigger — the keys' among them. It takes a superuser, as an import usually has.
        if (preg_match('/^\s*SET\s+(?:SESSION\s+|@@(?:SESSION\.)?)?FOREIGN_KEY_CHECKS\s*=\s*([01])\s*(;?)\s*$/i', $sql, $m) === 1) {
            return $this->cache[$key] = "SET session_replication_role = '" . ($m[1] === '0' ? 'replica' : 'origin') . "'" . $m[2];
        }
        // The statement's shape first, while every column is still a token of its own; then the
        // functions, which turn each call into one.
        $tokens = $this->functions($this->operators($this->statement(self::tokenize($sql))));
        $out    = implode('', array_column($tokens, 'v'));
        if (count($this->cache) >= 1000) {
            $this->cache = [];
        }

        return $this->cache[$key] = $out;
    }

    /**
     * SQL as tokens: whitespace, comments, string literals, quoted identifiers, words, numbers,
     * placeholders (`?`, `$1`, `:name`, the framework's `%s`), and single characters.
     *
     * @return list<array{t: string, v: string}>
     */
    public static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if (ctype_space($c)) {
                $j = $i;
                while ($j < $length && ctype_space($sql[$j])) {
                    $j++;
                }
                $tokens[] = ['t' => 'ws', 'v' => substr($sql, $i, $j - $i)];
                $i        = $j;
            } elseif (($c === '-' && $n === '-') || $c === '#') {
                $end      = strpos($sql, "\n", $i);
                $end      = $end === false ? $length : $end;
                $tokens[] = ['t' => 'comment', 'v' => substr($sql, $i, $end - $i)];
                $i        = $end;
            } elseif ($c === '/' && $n === '*') {
                $end      = strpos($sql, '*/', $i + 2);
                $end      = $end === false ? $length : $end + 2;
                $tokens[] = ['t' => 'comment', 'v' => substr($sql, $i, $end - $i)];
                $i        = $end;
            } elseif ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $length) {
                    if ($c !== '`' && $sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $text     = substr($sql, $i, min($j + 1, $length) - $i);
                $tokens[] = ['t' => $c === "'" ? 'str' : ($c === '"' ? 'ident' : 'btick'), 'v' => $text];
                $i        = $j + 1;
            } elseif ($c === '%' && ctype_alpha($n)) {
                $tokens[] = ['t' => 'ph', 'v' => '%' . $n];
                $i       += 2;
            } elseif ($c === '?' || ($c === '$' && ctype_digit($n)) || ($c === ':' && (ctype_alpha($n) || $n === '_'))) {
                $j = $i + 1;
                while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['t' => 'ph', 'v' => substr($sql, $i, $j - $i)];
                $i        = $j;
            } elseif (ctype_digit($c) || ($c === '.' && ctype_digit($n))) {
                $j = $i;
                while ($j < $length && (ctype_digit($sql[$j]) || $sql[$j] === '.')) {
                    $j++;
                }
                $tokens[] = ['t' => 'num', 'v' => substr($sql, $i, $j - $i)];
                $i        = $j;
            } elseif (ctype_alpha($c) || $c === '_' || ord($c) > 127) {
                $j = $i;
                while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || ord($sql[$j]) > 127)) {
                    $j++;
                }
                $tokens[] = ['t' => 'word', 'v' => substr($sql, $i, $j - $i)];
                $i        = $j;
            } else {
                $tokens[] = ['t' => 'op', 'v' => $c];
                $i++;
            }
        }

        return $tokens;
    }

    /**
     * Functions and the token-level forms, at every depth: backticks, the function calls, and
     * `LIMIT a, b`.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>
     */
    private function functions(array $tokens): array
    {
        $out   = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['t'] === 'btick') {
                $out[] = ['t' => 'ident', 'v' => '"' . str_replace(['``', '"'], ['`', '""'], substr($token['v'], 1, -1)) . '"'];
                continue;
            }
            if ($token['t'] === 'word' && strcasecmp($token['v'], 'LIMIT') === 0) {
                $a = self::next($tokens, $i);
                $comma = $a !== null ? self::next($tokens, $a) : null;
                $b = $comma !== null && $tokens[$comma]['v'] === ',' ? self::next($tokens, $comma) : null;
                if ($b !== null && in_array($tokens[$a]['t'], ['num', 'ph'], true) && in_array($tokens[$b]['t'], ['num', 'ph'], true)) {
                    $out[] = ['t' => 'raw', 'v' => $token['v'] . ' ' . $tokens[$b]['v'] . ' OFFSET ' . $tokens[$a]['v']];
                    $i     = $b;
                    continue;
                }
            }
            // One of the functions translated: its arguments, each translated, and the call rewritten.
            $open = $token['t'] === 'word' && in_array(strtoupper($token['v']), self::FUNCTIONS, true) ? self::next($tokens, $i) : null;
            if ($open !== null && $tokens[$open]['v'] === '(' && ($close = self::closing($tokens, $open)) !== null) {
                $args        = array_map(fn (array $arg): array => $this->functions($arg), self::split(array_slice($tokens, $open + 1, $close - $open - 1)));
                $replacement = $this->call(strtoupper($token['v']), $args);
                if ($replacement !== null) {
                    $out[] = ['t' => 'raw', 'v' => $replacement];
                    $i     = $close;
                    continue;
                }
            }
            // Anything else in parentheses — a subquery, a list, another function — is translated
            // as one run: a comma there may be LIMIT's, not an argument's.
            if ($token['v'] === '(' && ($close = self::closing($tokens, $i)) !== null && $close > $i) {
                $inner = array_slice($tokens, $i + 1, $close - $i - 1);
                $lead  = self::next($inner, -1);
                if ($lead !== null && strcasecmp($inner[$lead]['v'], 'SELECT') === 0) {
                    $inner = $this->having($inner);
                }
                array_push($out, $token, ...$this->functions($inner));
                $out[] = $tokens[$close];
                $i = $close;
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    /**
     * A MySQL function call as PostgreSQL's, or null when it is not one of those translated.
     *
     * @param list<list<array{t: string, v: string}>> $args Each argument's tokens, translated
     */
    private function call(string $name, array $args): ?string
    {
        $text  = static fn (array $tokens): string => trim(implode('', array_column($tokens, 'v')));
        $given = array_values(array_filter($args, static fn (array $arg): bool => trim(implode('', array_column($arg, 'v'))) !== ''));

        return match ($name) {
            'IFNULL'         => 'COALESCE(' . implode(', ', array_map($text, $given)) . ')',
            'IF'             => count($given) === 3 ? 'CASE WHEN ' . $text($given[0]) . ' THEN ' . $text($given[1]) . ' ELSE ' . $text($given[2]) . ' END' : null,
            'ISNULL'         => count($given) === 1 ? '((' . $text($given[0]) . ') IS NULL)' : null,
            'LCASE'          => count($given) === 1 ? 'lower(' . $text($given[0]) . ')' : null,
            'UCASE'          => count($given) === 1 ? 'upper(' . $text($given[0]) . ')' : null,
            'DATE_ADD', 'DATE_SUB' => count($given) === 2 && ($interval = self::interval($given[1])) !== null
                ? '(' . $text($given[0]) . ($name === 'DATE_ADD' ? ' + ' : ' - ') . $interval . ')'
                : null,
            'RAND'           => $given === [] ? 'random()' : null,
            'CURDATE'        => $given === [] ? 'CURRENT_DATE' : null,
            'CURTIME'        => $given === [] ? 'LOCALTIME' : null,
            'UNIX_TIMESTAMP' => match (count($given)) {
                0       => 'EXTRACT(EPOCH FROM NOW())::bigint',
                1       => 'EXTRACT(EPOCH FROM CAST((' . $text($given[0]) . ') AS timestamptz))::bigint',
                default => null,
            },
            'FROM_UNIXTIME'  => match (count($given)) {
                1       => 'to_timestamp(' . $text($given[0]) . ')',
                2       => ($format = $this->format($given[1])) !== null ? 'to_char(to_timestamp(' . $text($given[0]) . '), ' . $format . ')' : null,
                default => null,
            },
            'DATE_FORMAT'    => count($given) === 2 && ($format = $this->format($given[1])) !== null ? 'to_char(' . $text($given[0]) . ', ' . $format . ')' : null,
            'GROUP_CONCAT'   => $this->groupConcat($given),
            // MySQL's is NULL when any argument is; PostgreSQL's concat() skips a NULL, so a
            // value MySQL left empty came back filled. `||` keeps MySQL's answer, and each
            // argument as text, as MySQL's CONCAT turns a number into one.
            'CONCAT'         => $given === [] ? null : '(' . implode(' || ', array_map(static fn (array $arg): string => '(' . $text($arg) . ')::text', $given)) . ')',
            'SHA2'           => count($given) === 2 && in_array($bits = $text($given[1]), ['0', '224', '256', '384', '512'], true)
                ? "encode(sha" . ($bits === '0' ? '256' : $bits) . "(convert_to((" . $text($given[0]) . ")::text, 'UTF8')), 'hex')"
                : null,
            'CAST'           => count($given) === 1 && preg_match('/^(.*\S)\s+AS\s+(?:UN)?SIGNED(?:\s+INT(?:EGER)?)?$/is', $text($given[0]), $m) === 1
                ? 'CAST(' . $m[1] . ' AS bigint)'
                : null,
            'DAY', 'MONTH', 'YEAR' => count($given) === 1 ? 'EXTRACT(' . $name . ' FROM ' . $text($given[0]) . ')::int' : null,
            // ponytail: GET_LOCK's timeout is not waited out — taken now or not at all.
            'GET_LOCK'       => count($given) === 2 ? '(CASE WHEN pg_try_advisory_lock(hashtext(' . $text($given[0]) . ')) THEN 1 ELSE 0 END)' : null,
            'RELEASE_LOCK'   => count($given) === 1 ? '(CASE WHEN pg_advisory_unlock(hashtext(' . $text($given[0]) . ')) THEN 1 ELSE 0 END)' : null,
            'JSON_EXTRACT'   => count($given) === 2 && ($path = self::jsonPath($given[1])) !== null ? '((' . $text($given[0]) . ')::jsonb #> ' . $path . ')' : null,
            'JSON_UNQUOTE'   => count($given) === 1 ? '((' . $text($given[0]) . ')::jsonb #>> \'{}\')' : null,
            default          => null,
        };
    }

    /**
     * A JSON path literal — `'$.a.b[0]'`, `'$."a b"'` — as PostgreSQL's path array, `'{a,b,0}'`.
     * Null for anything else: a wildcard, a path not a literal.
     *
     * @param list<array{t: string, v: string}> $arg
     */
    private static function jsonPath(array $arg): ?string
    {
        $significant = array_values(array_filter($arg, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
        if (count($significant) !== 1 || $significant[0]['t'] !== 'str') {
            return null;
        }
        $path = str_replace("''", "'", substr($significant[0]['v'], 1, -1));
        if (!str_starts_with($path, '$')) {
            return null;
        }
        $rest = substr($path, 1);
        preg_match_all('/\G(?:\.([A-Za-z_][\w$]*)|\."([^"]*)"|\[(\d+)\])/', $rest, $parts, PREG_SET_ORDER);
        if (implode('', array_column($parts, 0)) !== $rest) {
            return null;
        }
        $keys = array_map(static fn (array $p): string => implode('', array_slice($p, 1)), $parts);

        return "'{" . implode(',', array_map(static fn (string $k): string => preg_match('/^\w+$/', $k) === 1 ? $k : '"' . str_replace(['\\', '"', "'"], ['\\\\', '\\"', "''"], $k) . '"', $keys)) . "}'";
    }

    /**
     * A `SELECT` whose `HAVING` names an alias of its select list, which MySQL allows and
     * PostgreSQL does not: the aliased expression in the alias's place — in the statement and in
     * a subquery. Only an alias given with `AS`.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>
     */
    private function having(array $tokens): array
    {
        $at = [];
        for ($k = 0, $depth = 0, $count = count($tokens); $k < $count; $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $tokens[$k]['t'] === 'word') {
                $word = strtoupper($tokens[$k]['v']);
                if (in_array($word, ['SELECT', 'FROM', 'HAVING'], true) && !isset($at[$word])) {
                    $at[$word] = $k;
                } elseif (isset($at['HAVING']) && !isset($at['END']) && in_array($word, ['ORDER', 'LIMIT', 'UNION', 'WINDOW', 'FOR'], true)) {
                    $at['END'] = $k;
                }
            }
        }
        if (!isset($at['SELECT'], $at['FROM'], $at['HAVING']) || $at['FROM'] < $at['SELECT']) {
            return $tokens;
        }
        $aliases = [];
        foreach (self::split(array_slice($tokens, $at['SELECT'] + 1, $at['FROM'] - $at['SELECT'] - 1)) as $item) {
            $words = array_keys(array_filter($item, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
            $n     = count($words);
            if ($n >= 3 && strcasecmp($item[$words[$n - 2]]['v'], 'AS') === 0) {
                $name = in_array($item[$words[$n - 1]]['t'], ['ident', 'btick'], true) ? self::unquote($item[$words[$n - 1]]['v']) : $item[$words[$n - 1]]['v'];
                $expression = array_slice($item, $words[0], $words[$n - 3] - $words[0] + 1);
                // `SUM(n) AS n` is left: which `n` MySQL's HAVING meant is not for this to decide.
                $same = array_filter($expression, static fn (array $t): bool => strcasecmp(in_array($t['t'], ['ident', 'btick'], true) ? self::unquote($t['v']) : $t['v'], $name) === 0);
                if ($same === []) {
                    $aliases[strtolower($name)] = $expression;
                }
            }
        }
        if ($aliases === []) {
            return $tokens;
        }
        $end = $at['END'] ?? count($tokens);
        $out = array_slice($tokens, 0, $at['HAVING'] + 1);
        for ($k = $at['HAVING'] + 1; $k < $end; $k++) {
            $token = $tokens[$k];
            $name  = $token['t'] === 'word' ? $token['v'] : (in_array($token['t'], ['ident', 'btick'], true) ? self::unquote($token['v']) : null);
            $next  = self::next($tokens, $k);
            $prev  = end($out);
            if ($name !== null && isset($aliases[strtolower($name)]) && ($prev === false || $prev['v'] !== '.')
                && ($next === null || !in_array($tokens[$next]['v'], ['(', '.'], true))) {
                $out = [...$out, ['t' => 'op', 'v' => '('], ...$aliases[strtolower($name)], ['t' => 'op', 'v' => ')']];
                continue;
            }
            $out[] = $token;
        }

        return [...$out, ...array_slice($tokens, $end)];
    }

    /**
     * `DELETE a FROM t a JOIN u b ON … [WHERE …]` — MySQL's delete through a join — as
     * PostgreSQL's `DELETE FROM t a USING u b WHERE (…) AND (…)`. Only when what is deleted is
     * the first table and every join is an inner one with `ON`; anything else is left, to fail
     * where it can be seen.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>|null
     */
    private function deleteJoin(array $tokens, int $first): ?array
    {
        $marks = [];
        for ($k = $first + 1, $depth = 0, $count = count($tokens); $k < $count; $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $tokens[$k]['t'] === 'word') {
                $marks[] = [strtoupper($tokens[$k]['v']), $k];
            }
        }
        $words = array_column($marks, 0);
        $from  = array_search('FROM', $words, true);
        if ($from === false) {
            return null;
        }
        $target = trim(self::sql(array_slice($tokens, $first + 1, $marks[$from][1] - $first - 1)));
        if ($target === '' || str_contains($target, ',') || array_intersect($words, ['LEFT', 'RIGHT', 'CROSS', 'OUTER', 'NATURAL', 'STRAIGHT_JOIN', 'USING', 'ORDER', 'LIMIT']) !== []) {
            return null;
        }
        // The pieces, by the keywords between them: FROM t a, then JOIN u b ON …, then WHERE.
        $end    = count($tokens);
        while ($end > 0 && (in_array($tokens[$end - 1]['t'], ['ws', 'comment'], true) || $tokens[$end - 1]['v'] === ';')) {
            $end--;
        }
        $cuts   = array_values(array_filter($marks, static fn (array $m): bool => in_array($m[0], ['JOIN', 'INNER', 'ON', 'WHERE'], true) && $m[1] > $marks[$from][1]));
        $piece  = static fn (int $a, int $b): array => array_slice($tokens, $a, $b - $a);
        $bounds = [...array_column($cuts, 1), $end];
        $table  = $piece($marks[$from][1] + 1, $bounds[0]);
        $joined = [];
        $conds  = [];
        $where  = null;
        foreach ($cuts as $c => [$word, $k]) {
            $body = $piece($k + 1, $bounds[$c + 1]);
            match ($word) {
                'JOIN'  => $joined[] = $body,
                'ON'    => $conds[] = $body,
                'WHERE' => $where = $body,
                default => null,
            };
        }
        // What is deleted is the first table, by its name or its alias.
        $named = array_values(array_filter($table, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
        $names = $named === [] ? [] : array_map(static fn (array $t): string => strtolower(in_array($t['t'], ['ident', 'btick'], true) ? self::unquote($t['v']) : $t['v']), [$named[0], end($named)]);
        $bare  = strtolower(str_starts_with($target, '"') ? self::unquote($target) : $target);
        if ($joined === [] || count($joined) !== count($conds) || !in_array($bare, $names, true)) {
            return null;
        }
        $raw  = static fn (string $v): array => ['t' => 'raw', 'v' => $v];
        $trim = static function (array $body): array {
            while ($body !== [] && in_array($body[0]['t'], ['ws', 'comment'], true)) {
                array_shift($body);
            }
            while ($body !== [] && in_array(end($body)['t'], ['ws', 'comment'], true)) {
                array_pop($body);
            }

            return $body;
        };
        $out = [...array_slice($tokens, 0, $first), $raw('DELETE FROM '), ...$trim($table), $raw(' USING ')];
        foreach ($joined as $j => $body) {
            $out = [...$out, ...($j > 0 ? [$raw(', ')] : []), ...$trim($body)];
        }
        $out[] = $raw(' WHERE ');
        foreach ([...$conds, ...($where !== null ? [$where] : [])] as $c => $body) {
            $out = [...$out, ...($c > 0 ? [$raw(' AND ')] : []), $raw('('), ...$trim($body), $raw(')')];
        }

        return [...$out, ...array_slice($tokens, $end)];
    }

    /**
     * MySQL's `INTERVAL n UNIT` as PostgreSQL's: `(n) * INTERVAL '1 unit'`, which takes an
     * expression for `n` as MySQL does. Null for a unit PostgreSQL has no such word for.
     *
     * @param list<array{t: string, v: string}> $arg
     */
    private static function interval(array $arg): ?string
    {
        $significant = array_values(array_filter($arg, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
        if (count($significant) < 3 || strcasecmp($significant[0]['v'], 'INTERVAL') !== 0) {
            return null;
        }
        $unit = strtoupper((string) end($significant)['v']);
        if (!in_array($unit, ['MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR'], true)) {
            return null;
        }
        $amount = trim(implode('', array_column(array_slice($arg, array_search($significant[0], $arg, true) + 1, array_search(end($significant), $arg, true) - array_search($significant[0], $arg, true) - 1), 'v')));
        $one    = $unit === 'QUARTER' ? "INTERVAL '3 month'" : "INTERVAL '1 " . strtolower($unit) . "'";

        return '(' . $amount . ') * ' . $one;
    }

    /**
     * `GROUP_CONCAT([DISTINCT] x [ORDER BY …] [SEPARATOR 's'])` as `string_agg`, whose order
     * comes after the separator; a separator not given is MySQL's, a comma.
     *
     * @param list<list<array{t: string, v: string}>> $args
     */
    private function groupConcat(array $args): ?string
    {
        if (count($args) !== 1) {
            // Several expressions concatenated: CONCAT them first — not a shape worth guessing at.
            return null;
        }
        $tokens   = $args[0];
        $words    = array_map(static fn (array $t): string => $t['t'] === 'word' ? strtoupper($t['v']) : '', $tokens);
        $distinct = '';
        $first    = self::next($tokens, -1);
        if ($first !== null && $words[$first] === 'DISTINCT') {
            $distinct = 'DISTINCT ';
            $tokens   = array_slice($tokens, $first + 1);
            $words    = array_slice($words, $first + 1);
        }
        $separator = "','";
        $sepAt     = array_search('SEPARATOR', $words, true);
        if ($sepAt !== false) {
            $after = self::next($tokens, $sepAt);
            if ($after === null || $tokens[$after]['t'] !== 'str') {
                return null;
            }
            $separator = $tokens[$after]['v'];
            $tokens    = array_slice($tokens, 0, $sepAt);
            $words     = array_slice($words, 0, $sepAt);
        }
        $order = '';
        for ($k = 0, $depth = 0; $k < count($tokens); $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $words[$k] === 'ORDER') {
                $order  = ' ' . trim(implode('', array_column(array_slice($tokens, $k), 'v')));
                $tokens = array_slice($tokens, 0, $k);
                break;
            }
        }
        $expression = trim(implode('', array_column($tokens, 'v')));

        return $expression === '' ? null : 'string_agg(' . $distinct . '(' . $expression . ')::text, ' . $separator . $order . ')';
    }

    /**
     * A `DATE_FORMAT` pattern as `to_char`'s: each specifier its equivalent, the text between
     * quoted so `to_char` does not read it. Null for a pattern that is not a literal, or holds a
     * specifier `to_char` has none for — left as MySQL wrote it, to fail where it can be seen.
     *
     * @param list<array{t: string, v: string}> $arg
     */
    private function format(array $arg): ?string
    {
        $significant = array_values(array_filter($arg, static fn (array $t): bool => !in_array($t['t'], ['ws', 'comment'], true)));
        if (count($significant) !== 1 || $significant[0]['t'] !== 'str') {
            return null;
        }
        $pattern = str_replace("''", "'", substr($significant[0]['v'], 1, -1));
        if ($this->percentEscaped) {
            $pattern = str_replace('%%', '%', $pattern);
        }
        $out     = '';
        $literal = '';
        $flush   = static function () use (&$out, &$literal): void {
            if ($literal !== '') {
                $out    .= '"' . str_replace('"', '\\"', $literal) . '"';
                $literal = '';
            }
        };
        for ($k = 0, $length = strlen($pattern); $k < $length; $k++) {
            if ($pattern[$k] !== '%' || $k + 1 >= $length) {
                $literal .= $pattern[$k];
                continue;
            }
            $spec = $pattern[++$k];
            if ($spec === '%') {
                $literal .= '%';
                continue;
            }
            if (!isset(self::FORMAT[$spec])) {
                return null;
            }
            $flush();
            $out .= self::FORMAT[$spec];
        }
        $flush();
        $out = "'" . str_replace("'", "''", $out) . "'";

        // Back into printf's spelling: a literal percent the pattern kept must survive it.
        return $this->percentEscaped ? str_replace('%', '%%', $out) : $out;
    }

    /**
     * The statement-level forms: `INSERT IGNORE`, `REPLACE INTO`, `ON DUPLICATE KEY UPDATE`.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>
     */
    private function statement(array $tokens): array
    {
        $first = self::next($tokens, -1);
        if ($first === null || $tokens[$first]['t'] !== 'word') {
            return $tokens;
        }
        $verb = strtoupper($tokens[$first]['v']);
        if ($verb === 'SELECT') {
            return $this->having($tokens);
        }
        if ($verb === 'DELETE') {
            return $this->deleteJoin($tokens, $first) ?? $tokens;
        }
        if ($verb !== 'INSERT' && $verb !== 'REPLACE') {
            return $tokens;
        }
        $second = self::next($tokens, $first);
        $ignore = $verb === 'INSERT' && $second !== null && strcasecmp($tokens[$second]['v'], 'IGNORE') === 0;
        $upsert = $this->topLevel($tokens, ['ON', 'DUPLICATE', 'KEY', 'UPDATE']);
        if (!$ignore && $upsert === null && $verb !== 'REPLACE') {
            // Already PostgreSQL's upsert — written so, or translated and then added to, as code
            // that appends `, n = n + 1` to a prepared statement does: its bare self-references
            // are as ambiguous as they would have been in MySQL's.
            $set = $this->topLevel($tokens, ['DO', 'UPDATE', 'SET']);
            if ($set === null) {
                return $tokens;
            }

            return [...array_slice($tokens, 0, $set[1] + 1), ...$this->qualified(array_slice($tokens, $set[1] + 1), $this->target($tokens, $first)[0])];
        }
        [$table, $columns] = $this->target($tokens, $first);

        if ($verb === 'REPLACE') {
            $tokens[$first] = ['t' => 'raw', 'v' => 'INSERT'];
            $key            = $this->conflictKey($table, $columns, 'REPLACE INTO');
            $others         = array_values(array_diff($columns, $key));

            return self::append($tokens, ' ON CONFLICT (' . self::list($key) . ')' . ($others === []
                ? ' DO NOTHING'
                : ' DO UPDATE SET ' . implode(', ', array_map(static fn (string $c): string => self::ident($c) . ' = EXCLUDED.' . self::ident($c), $others))));
        }
        if ($ignore) {
            // `IGNORE` and the space after it go; the statement ends in doing nothing on a conflict.
            unset($tokens[$second]);
            $tokens = array_values($tokens);
            if (($tokens[$second]['t'] ?? '') === 'ws') {
                unset($tokens[$second]);
                $tokens = array_values($tokens);
            }
            if ($upsert === null) {
                return self::append($tokens, ' ON CONFLICT DO NOTHING');
            }
            $upsert = $this->topLevel($tokens, ['ON', 'DUPLICATE', 'KEY', 'UPDATE']);
        }
        $key  = $this->conflictKey($table, $columns, 'ON DUPLICATE KEY UPDATE');
        $head = array_slice($tokens, 0, $upsert[0]);
        $tail = array_slice($tokens, $upsert[1] + 1);

        return [...$head, ['t' => 'raw', 'v' => 'ON CONFLICT (' . self::list($key) . ') DO UPDATE SET'], ...$this->qualified($tail, $table)];
    }

    /**
     * An upsert's assignments as PostgreSQL takes them. VALUES(c), the value the insert proposed,
     * is EXCLUDED.c; a bare column on the right of an assignment is the row already there, which
     * MySQL takes it for and PostgreSQL wants named — `n = n + 1` is `n = t.n + 1`, or it is
     * ambiguous with EXCLUDED's. A name already qualified, or one that qualifies another
     * (`t.n`, `EXCLUDED.n`), is left.
     *
     * @param list<array{t: string, v: string}> $tail
     * @return list<array{t: string, v: string}>
     */
    private function qualified(array $tail, string $table): array
    {
        // ponytail: `INSERT INTO t AS x` is not read — its alias is what the update would have to name.
        $known     = array_map('strtolower', $this->columnsOf !== null ? ($this->columnsOf)($table) : []);
        $existing  = self::ident((string) substr(strrchr('.' . $table, '.'), 1));
        $rewritten = [];
        $right     = false;
        for ($k = 0, $count = count($tail), $depth = 0; $k < $count; $k++) {
            $token  = $tail[$k];
            $depth += $token['v'] === '(' ? 1 : ($token['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $token['t'] === 'op') {
                $right = $token['v'] === '=' ? true : ($token['v'] === ',' ? false : $right);
            }
            $open = $token['t'] === 'word' && strcasecmp($token['v'], 'VALUES') === 0 ? self::next($tail, $k) : null;
            if ($open !== null && $tail[$open]['v'] === '(' && ($close = self::closing($tail, $open)) !== null) {
                $rewritten[] = ['t' => 'raw', 'v' => 'EXCLUDED.' . trim(self::sql(array_slice($tail, $open + 1, $close - $open - 1)))];
                $k           = $close;
                continue;
            }
            $name = $token['t'] === 'word' ? $token['v'] : (in_array($token['t'], ['ident', 'btick'], true) ? self::unquote($token['v']) : null);
            $next = self::next($tail, $k);
            $prev = end($rewritten);
            if ($right && $name !== null && in_array(strtolower($name), $known, true)
                && ($next === null || !in_array($tail[$next]['v'], ['(', '.'], true)) && ($prev === false || $prev['v'] !== '.')) {
                $rewritten[] = ['t' => 'raw', 'v' => $existing . '.' . self::sql([$token])];
                continue;
            }
            $rewritten[] = $token;
        }

        return $rewritten;
    }

    /**
     * The table an insert writes and the columns it names: `INSERT [IGNORE] INTO t (a, b)`.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return array{string, list<string>}
     */
    private function target(array $tokens, int $verbAt): array
    {
        $k = self::next($tokens, $verbAt);
        while ($k !== null && in_array(strtoupper($tokens[$k]['v']), ['IGNORE', 'INTO', 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY'], true)) {
            $k = self::next($tokens, $k);
        }
        // The name runs on, without spaces, through its parts: `t`, `s.t`, `"s"."t"`.
        $table = '';
        while ($k !== null && $k < count($tokens) && (in_array($tokens[$k]['t'], ['word', 'ident', 'btick'], true) || $tokens[$k]['v'] === '.')) {
            $table .= in_array($tokens[$k]['t'], ['ident', 'btick'], true) ? self::unquote($tokens[$k]['v']) : $tokens[$k]['v'];
            $k++;
        }
        $k = $k !== null && $k < count($tokens) && $tokens[$k]['t'] === 'ws' ? self::next($tokens, $k) : $k;
        $columns = [];
        if ($k !== null && $tokens[$k]['v'] === '(' && ($close = self::closing($tokens, $k)) !== null) {
            foreach (self::split(array_slice($tokens, $k + 1, $close - $k - 1)) as $column) {
                $name      = trim(implode('', array_column($column, 'v')));
                $columns[] = str_starts_with($name, '"') || str_starts_with($name, '`') ? self::unquote($name) : $name;
            }
        }

        return [$table, $columns];
    }

    /**
     * The conflict target: the one unique key of the table whose columns the insert names.
     *
     * @param list<string> $columns
     * @return list<string>
     * @throws DialectException When none qualifies, or more than one
     */
    private function conflictKey(string $table, array $columns, string $construct): array
    {
        $keys = $this->uniqueKeys !== null ? ($this->uniqueKeys)($table) : [];
        $fit  = array_values(array_filter($keys, static fn (array $key): bool => $key !== [] && array_diff($key, $columns) === []));
        if (count($fit) === 1) {
            return $fit[0];
        }
        throw new DialectException(count($fit) === 0
            ? $construct . ' on ' . $table . ': no unique key of the table is among the columns the insert names, so PostgreSQL has nothing to tell a conflict by. Name one with ON CONFLICT (…) yourself.'
            : $construct . ' on ' . $table . ': ' . count($fit) . ' unique keys are among the columns the insert names (' . implode('; ', array_map([self::class, 'list'], $fit))
                . '). MySQL fires on any of them, PostgreSQL on one: write ON CONFLICT (…) naming it.');
    }

    /**
     * Where a sequence of words stands at the top level of the statement: [first, last] token index.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @param list<string>                       $words
     * @return array{int, int}|null
     */
    private function topLevel(array $tokens, array $words): ?array
    {
        for ($k = 0, $depth = 0, $count = count($tokens); $k < $count; $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth !== 0 || $tokens[$k]['t'] !== 'word' || strcasecmp($tokens[$k]['v'], $words[0]) !== 0) {
                continue;
            }
            $at = $k;
            foreach (array_slice($words, 1) as $word) {
                $at = self::next($tokens, $at);
                if ($at === null || $tokens[$at]['t'] !== 'word' || strcasecmp($tokens[$at]['v'], $word) !== 0) {
                    continue 2;
                }
            }

            return [$k, $at];
        }

        return null;
    }

    /**
     * The statement with a clause at its end: before a trailing `;`, and before `RETURNING`.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>
     */
    private static function append(array $tokens, string $clause): array
    {
        $end = count($tokens);
        while ($end > 0 && (in_array($tokens[$end - 1]['t'], ['ws', 'comment'], true) || $tokens[$end - 1]['v'] === ';')) {
            $end--;
        }
        for ($k = 0, $depth = 0; $k < $end; $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $tokens[$k]['t'] === 'word' && strcasecmp($tokens[$k]['v'], 'RETURNING') === 0) {
                $end = $k;
                while ($end > 0 && $tokens[$end - 1]['t'] === 'ws') {
                    $end--;
                }
                break;
            }
        }

        return [...array_slice($tokens, 0, $end), ['t' => 'raw', 'v' => $clause], ...array_slice($tokens, $end)];
    }

    /**
     * MySQL's regular-expression operators as PostgreSQL's: `REGEXP` and `RLIKE` as `~*`, and
     * `NOT REGEXP` as `!~*`. Case-insensitive, as MySQL matches under the `_ci` collations a
     * table is usually in; a match that has to tell cases apart is written `~` for PostgreSQL.
     * `REGEXP_SUBSTR()` and `REGEXP_LIKE()` are left as they are: PostgreSQL has both from 15.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<array{t: string, v: string}>
     */
    private function operators(array $tokens): array
    {
        foreach ($tokens as $k => $token) {
            if ($token['t'] !== 'word' || !in_array(strtoupper($token['v']), ['REGEXP', 'RLIKE'], true)) {
                continue;
            }
            // The word before it, past whitespace and comments.
            for ($previous = $k - 1; $previous >= 0 && in_array($tokens[$previous]['t'], ['ws', 'comment'], true); $previous--);
            if ($previous >= 0 && $tokens[$previous]['t'] === 'word' && strcasecmp($tokens[$previous]['v'], 'NOT') === 0) {
                $tokens[$previous] = ['t' => 'raw', 'v' => '!~*'];
                // `NOT REGEXP` and what was between them: one operator.
                for ($j = $previous + 1; $j <= $k; $j++) {
                    $tokens[$j] = ['t' => 'raw', 'v' => ''];
                }
                continue;
            }
            $tokens[$k] = ['t' => 'raw', 'v' => '~*'];
        }

        return $tokens;
    }

    /** The index of the next token that is not whitespace or a comment, after `$from`; null at the end. */
    private static function next(array $tokens, int $from): ?int
    {
        for ($k = $from + 1, $count = count($tokens); $k < $count; $k++) {
            if (!in_array($tokens[$k]['t'], ['ws', 'comment'], true)) {
                return $k;
            }
        }

        return null;
    }

    /** The index of the `)` that closes the `(` at `$open`; null when it is never closed. */
    private static function closing(array $tokens, int $open): ?int
    {
        for ($k = $open, $depth = 0, $count = count($tokens); $k < $count; $k++) {
            $depth += $tokens[$k]['v'] === '(' ? 1 : ($tokens[$k]['v'] === ')' ? -1 : 0);
            if ($depth === 0) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Tokens split at their top-level commas.
     *
     * @param list<array{t: string, v: string}> $tokens
     * @return list<list<array{t: string, v: string}>>
     */
    private static function split(array $tokens): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($tokens as $token) {
            $depth += $token['v'] === '(' ? 1 : ($token['v'] === ')' ? -1 : 0);
            if ($depth === 0 && $token['v'] === ',' && $token['t'] === 'op') {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $token;
        }

        return $parts;
    }

    /** A quoted identifier's name: `"a""b"` or `` `a``b` `` as `a"b` and ``a`b``. */
    private static function unquote(string $quoted): string
    {
        $q = $quoted[0];

        return str_replace($q . $q, $q, substr($quoted, 1, -1));
    }

    /**
     * Tokens as text, a backtick-quoted name as PostgreSQL quotes it.
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function sql(array $tokens): string
    {
        return implode('', array_map(static fn (array $t): string => $t['t'] === 'btick' ? '"' . str_replace('"', '""', self::unquote($t['v'])) . '"' : $t['v'], $tokens));
    }

    /** @param list<string> $columns */
    private static function list(array $columns): string
    {
        return implode(', ', array_map([self::class, 'ident'], $columns));
    }

    private static function ident(string $name): string
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/', $name) === 1 ? $name : '"' . str_replace('"', '""', $name) . '"';
    }
}
