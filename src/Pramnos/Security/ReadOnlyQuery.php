<?php

declare(strict_types=1);

namespace Pramnos\Security;

/**
 * Is this SQL a read, and only a read?
 *
 * The second guard. The first is a database account with no write privileges,
 * and where an installation has one this is belt to its braces; where it has not
 * — because setting one up is work, and a developer with access to the box is
 * already trusted — **this is the only boundary there is**, and it is stated
 * that way rather than implied.
 *
 * ## Why "starts with SELECT" is not the check
 *
 * PostgreSQL allows a data-modifying statement inside a common table expression,
 * and the whole thing is a `SELECT`:
 *
 * ```sql
 * WITH gone AS (DELETE FROM usertokens RETURNING *) SELECT count(*) FROM gone;
 * ```
 *
 * That begins with `WITH`, ends as a `SELECT`, and empties a table. Any check
 * that reads the first keyword and stops passes it. So the rule here is the other
 * way round: a statement is refused if a writing keyword appears **anywhere** in
 * it outside of a string literal or a comment.
 *
 * ## What it does not claim
 *
 * It is a lexer, not a parser, and it errs towards refusal. A column genuinely
 * called `update_count` is refused, and that is the trade taken deliberately: the
 * cost of a false refusal is a rephrased query, and the cost of a false
 * acceptance is somebody's data.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ReadOnlyQuery
{
    /**
     * Keywords that never appear in a read.
     *
     * `COPY` is here because PostgreSQL's `COPY … TO PROGRAM` runs a shell
     * command, and `COPY … FROM` reads a file the server can see. `DO` runs
     * procedural code. `GRANT` and `SET` change what the session may do next.
     */
    private const WRITING_KEYWORDS = array(
        'insert', 'update', 'delete', 'truncate', 'drop', 'create', 'alter',
        'grant', 'revoke', 'copy', 'do', 'call', 'merge', 'replace', 'vacuum',
        'analyze', 'reindex', 'cluster', 'lock', 'set', 'reset', 'begin',
        'commit', 'rollback', 'savepoint', 'listen', 'notify', 'load', 'refresh',
        'comment', 'security', 'prepare', 'execute', 'deallocate', 'discard',
        'import', 'into', 'handler',
    );

    /**
     * Check a statement, and say why when it is refused.
     *
     * @param  string      $sql
     * @param  string|null $reason Filled with what is wrong.
     * @return bool
     */
    public static function isRead(string $sql, ?string &$reason = null): bool
    {
        $stripped = self::withoutStringsAndComments($sql);
        $trimmed  = trim($stripped);

        if ($trimmed === '') {
            $reason = 'There is no statement here.';
            return false;
        }

        // One statement. A trailing semicolon is fine; a second statement is not,
        // and "; DROP …" is the oldest trick there is.
        if (str_contains(rtrim($trimmed, "; \t\n\r"), ';')) {
            $reason = 'Only one statement at a time.';
            return false;
        }

        $lowered = strtolower($trimmed);

        if (!str_starts_with($lowered, 'select') && !str_starts_with($lowered, 'with')) {
            $reason = 'Only SELECT is allowed, optionally with a WITH clause in front of it.';
            return false;
        }

        foreach (self::WRITING_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $lowered) === 1) {
                $reason = 'That statement contains "' . strtoupper($keyword)
                    . '", which is not part of a read. A data-modifying statement inside'
                    . ' a WITH clause is still a data-modifying statement.';
                return false;
            }
        }

        return true;
    }

    /**
     * The statement with string literals and comments blanked out.
     *
     * They are blanked rather than removed, so nothing that was separated becomes
     * adjacent — `'a' delete` must not become `adelete` and slip past a word
     * boundary. Blanking keeps every offset and every gap.
     *
     * Handles single quotes with SQL's doubled-quote escape, double-quoted
     * identifiers, PostgreSQL dollar quoting, `--` to end of line and `/* … *&#47;`.
     */
    public static function withoutStringsAndComments(string $sql): string
    {
        $out    = '';
        $length = strlen($sql);
        $i      = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // -- to end of line
            if ($char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            // /* ... */, which PostgreSQL allows to nest
            if ($char === '/' && $next === '*') {
                $depth = 0;
                while ($i < $length) {
                    if ($sql[$i] === '/' && ($i + 1 < $length) && $sql[$i + 1] === '*') {
                        $depth++;
                        $out .= '  ';
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === '*' && ($i + 1 < $length) && $sql[$i + 1] === '/') {
                        $depth--;
                        $out .= '  ';
                        $i += 2;
                        if ($depth === 0) {
                            break;
                        }
                        continue;
                    }
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            // '...' with '' as the escape
            if ($char === "'") {
                $out .= ' ';
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === "'" && ($i + 1 < $length) && $sql[$i + 1] === "'") {
                        $out .= '  ';
                        $i += 2;
                        continue;
                    }
                    $out .= ' ';
                    if ($sql[$i] === "'") {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // "identifier" — blanked, and that is safe rather than a hole. A
            // double-quoted token is always an identifier and never a keyword, so
            // `SELECT "delete" FROM t` reads a column called delete and cannot be
            // a command. Scanning inside them would refuse that perfectly ordinary
            // query for no gain.
            if ($char === '"') {
                $out .= ' ';
                $i++;
                while ($i < $length) {
                    $out .= ' ';
                    if ($sql[$i] === '"') {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // $tag$ ... $tag$
            if ($char === '$' && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $stop = $end === false ? $length : $end + strlen($tag);
                $out .= str_repeat(' ', $stop - $i);
                $i = $stop;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }
}
