<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\QueryBuilder;

/**
 * PostgreSQL 12+ SQL grammar.
 *
 * - Identifier quoting: double-quote
 * - Conflict handling: ON CONFLICT DO NOTHING / DO UPDATE SET
 * - RETURNING clause on INSERT / UPDATE / DELETE
 * - LIKE / ILIKE on non-text columns requires a ::text cast
 *
 */
class PostgreSQLGrammar extends Grammar
{
    private const LIKE_OPS = ['LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE'];

    public function quoteColumn(string $column): string
    {
        return '"' . $column . '"';
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    protected function compileReturning(QueryBuilder $qb): string
    {
        $returning = $qb->getReturning();
        return empty($returning) ? '' : ' RETURNING ' . implode(', ', $returning);
    }

    protected function wrapColumnForOperator(string $column, string $operator): string
    {
        if (in_array(strtoupper($operator), self::LIKE_OPS, true)) {
            return $column . '::text';
        }
        return $column;
    }

    /**
     * Case/accent equivalence for {@see prepareLikePattern()}: Greek and Latin capitals fold
     * to their small letter, an accented or diaeresis form to its bare letter, final sigma to
     * sigma — each entry of the first string is an alternate spelling of the one at its place
     * in the second.
     */
    private const FOLD_FROM = 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩΆΈΉΊΌΎΏΪΫάέήίόύώϊϋΐΰςABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const FOLD_TO   = 'αβγδεζηθικλμνξοπρστυφχψωαεηιουωιυαεηιουωιυιυσabcdefghijklmnopqrstuvwxyz';

    /** POSIX ERE metacharacters a literal pattern character must be escaped to lose. */
    private const REGEX_METACHARS = '.^$*+?()[]{}|\\';

    /** Built once from {@see FOLD_FROM}/{@see FOLD_TO}: raw character => every character that folds to the same one, itself included. */
    private static ?array $foldGroups = null;

    private static function foldGroups(): array
    {
        if (self::$foldGroups !== null) {
            return self::$foldGroups;
        }

        $from = mb_str_split(self::FOLD_FROM);
        $to   = mb_str_split(self::FOLD_TO);

        $byTarget = [];
        foreach ($from as $i => $raw) {
            $byTarget[$to[$i]][] = $raw;
        }
        foreach ($byTarget as $target => $raws) {
            if (!in_array($target, $raws, true)) {
                $byTarget[$target][] = $target;
            }
        }

        $byChar = [];
        foreach ($byTarget as $target => $raws) {
            foreach ($raws as $raw) {
                $byChar[$raw] = $raws;
            }
        }

        return self::$foldGroups = $byChar;
    }

    /**
     * Rewrites a `LIKE` pattern (its own `%`, `_`, `\%`, `\_`, `\\`) into a POSIX regular
     * expression that matches the same strings PostgreSQL's `translate()`-folded `LIKE` used
     * to, one `~` per row instead of two whole-column `translate()` calls plus a `LIKE` — the
     * difference between this and a sequential scan `translate()`+`LIKE` was measured at
     * roughly 30x on a 30k-row, 29 MB text column (glideday, 2026-10-26).
     *
     * `ILIKE` folds case through the database's locale, which under the (very common, often
     * default) `C` locale does not touch Greek at all — the reason {@see compileLikeIgnoringCase()}
     * cannot simply become `ILIKE`. This instead spells out every raw form of an ambiguous
     * letter (`Α`, `Ά`, `ά`, `α`, …) as its own regex character class, so the match never
     * depends on what the server's locale considers "the same letter".
     */
    public function prepareLikePattern(string $pattern): string
    {
        $groups = self::foldGroups();
        $chars  = mb_str_split($pattern);
        $regex  = '^';

        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $char = $chars[$i];

            if ($char === '\\' && $i + 1 < $n && in_array($chars[$i + 1], ['%', '_', '\\'], true)) {
                $regex .= $this->literalForRegex($chars[++$i]);
                continue;
            }

            if ($char === '%') {
                $regex .= '.*';
                continue;
            }

            if ($char === '_') {
                $regex .= '.';
                continue;
            }

            if (isset($groups[$char]) && count($groups[$char]) > 1) {
                $regex .= '[' . implode('', $groups[$char]) . ']';
                continue;
            }

            $regex .= $this->literalForRegex($char);
        }

        return $regex . '$';
    }

    private function literalForRegex(string $char): string
    {
        return str_contains(self::REGEX_METACHARS, $char) ? '\\' . $char : $char;
    }

    /**
     * {@see prepareLikePattern()} has already turned the pattern into a regular expression
     * equivalent to the fold-and-compare the old `translate()`+`LIKE` did — matching it is a
     * single, native, case-sensitive-by-construction operator, with nothing left to fold here.
     */
    protected function compileLikeIgnoringCase(string $column, string $placeholder): string
    {
        return $column . '::text ~ ' . $placeholder;
    }

    /**
     * A `bytea` column that is really decodable text — most often a MySQL `blob` a migration
     * mapped straight across instead of `--blob-as-text` (FW-278). `::text`, what
     * {@see compileLikeIgnoringCase()} and {@see wrapColumnForOperator()} use, casts a `bytea`
     * to PostgreSQL's hex/escape *output format* (`\x4142…`), not the bytes decoded as text —
     * so a pattern visibly inside the stored value never matches. `convert_from()` decodes it
     * instead, and throws on bytes that are not valid UTF-8: a column of real binary data has
     * no text to find, and that should fail loudly rather than silently match nothing.
     */
    protected function compileLikeOnText(string $column, string $placeholder): string
    {
        return "convert_from({$column}, 'UTF8') LIKE {$placeholder}";
    }

    protected function compileLock(QueryBuilder $qb): string
    {
        return match ($qb->getLock()) {
            'update' => ' FOR UPDATE',
            'share'  => ' FOR SHARE',
            default  => '',
        };
    }

    protected function compileDatePartExtraction(string $part, string $column): string
    {
        return match (strtolower($part)) {
            'year'  => "EXTRACT(YEAR FROM {$column})",
            'month' => "EXTRACT(MONTH FROM {$column})",
            'day'   => "EXTRACT(DAY FROM {$column})",
            'time'  => "({$column})::time",
            default => "({$column})::date",
        };
    }

    // -------------------------------------------------------------------------
    // Conflict handling
    // -------------------------------------------------------------------------

    public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON CONFLICT DO NOTHING';

        return $sql . $this->compileReturning($qb);
    }

    // -------------------------------------------------------------------------
    // Time-bucket (PostgreSQL — DATE_TRUNC / epoch arithmetic)
    // -------------------------------------------------------------------------

    /**
     * Use DATE_TRUNC for standard single-unit intervals; fall back to epoch
     * arithmetic for arbitrary sub-month intervals (e.g. "15 minutes").
     *
     * {@inheritdoc}
     */
    public function compileTimeBucket(string $interval, string $column): string
    {
        $parsed = self::parseInterval($interval);

        if ($parsed === null) {
            return "date_trunc('day', {$column})";
        }

        ['count' => $count, 'unit' => $unit] = $parsed;

        $precision = self::unitToDateTruncPrecision($count, $unit);
        if ($precision !== null) {
            return "date_trunc('{$precision}', {$column})";
        }

        // Arbitrary sub-month interval (e.g. 15 minutes, 6 hours): epoch arithmetic.
        // Month/year fall through to date_trunc because they can't be expressed in seconds.
        $seconds = self::unitToSeconds($unit, $count);
        if ($seconds !== null) {
            return "to_timestamp(floor(extract(epoch from {$column}) / {$seconds}) * {$seconds})";
        }

        // Calendar units (month/year) with count > 1 — degrade gracefully
        $singlePrecision = self::unitToDateTruncPrecision(1, $unit);
        if ($singlePrecision !== null) {
            return "date_trunc('{$singlePrecision}', {$column})";
        }

        return "date_trunc('day', {$column})";
    }

    public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $quotedConflict = array_map(fn($c) => $this->quoteColumn($c), $conflictColumns);

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON CONFLICT (' . implode(', ', $quotedConflict) . ')';

        if (empty($updateValues)) {
            $sql .= ' DO NOTHING';
        } else {
            if (array_is_list($updateValues)) {
                // List of column names → col = EXCLUDED.col (take the value that
                // would have been inserted).
                $sets = array_map(
                    fn($col) => $this->quoteColumn($col) . ' = EXCLUDED.' . $this->quoteColumn($col),
                    $updateValues
                );
            } else {
                // Associative column => value|Expression → custom SET expression,
                // e.g. ['runs' => $qb->raw('table.runs + 1')] for counters, or a
                // bound scalar for a fixed override.
                $sets = [];
                foreach ($updateValues as $col => $val) {
                    $sets[] = $this->quoteColumn($col) . ' = ' . $this->getPlaceholder($val);
                }
            }
            $sql .= ' DO UPDATE SET ' . implode(', ', $sets);
        }

        return $sql . $this->compileReturning($qb);
    }
}
