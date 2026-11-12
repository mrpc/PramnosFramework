<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * Whether the numbers in an answer come from what its tools returned. A tool marked
 * `'numeric' => true` — a report, a metric, a count — hands the model figures; every number the
 * answer then says has to be one of them, or follow from them: a value, a list's or a column's
 * total, the sum or difference of two of those, or a percentage of one by another. A number that
 * does not is said back to the model before the person reads it ({@see Client::askWithTools()}).
 *
 * ```php
 * Figures::untraced('41 attempts: 84 renewals, 107 checkouts', ['{"renewals":[75,8,1],"checkouts":[75,29,2,1]}']);
 * // ['41']
 * ```
 *
 * Not checked: dates and times, a list's own numbering, a year, and what the question itself said.
 * Written to its precision: "38.7%" is 29 of 75, "about 1,200" is 1,234 — a round hundred is
 * read within 5%.
 */
final class Figures
{
    /** Dates and times, in the forms a tool and a model write them: not figures. */
    private const DATES = '~\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?'
        . '|\b\d{1,2}[/.]\d{1,2}[/.]\d{2,4}\b|\b\d{1,2}/\d{1,2}\b|\b\d{1,2}:\d{2}(?::\d{2})?\b~u';

    /** A number as written: 1.234,5 or 1,234.5 or 41; not part of a word (Q3, 3rd). */
    private const NUMBER = '~(?<![\p{L}\d.,])\d+(?:[.,]\d+)*(?![\p{L}\d])(\s*%)?~u';

    /** A list's own numbering, at the start of a line: "1. ", "2) ". */
    private const NUMBERING = '~^\s*\d+[.)]\s~mu';

    /**
     * Past this many figures, only values and totals are checked, not every pair of them.
     * ponytail: the pairs are O(n²); a result this large is a table nobody sums by hand anyway.
     */
    private const PAIRS = 400;

    /**
     * The numbers in an answer that none of the results bears out, as written.
     *
     * @param string       $answer  What the model wrote
     * @param list<string> $results What the numeric tools returned, in this answer
     * @param string       $asked   What the question and the calls' arguments said: its numbers are not checked
     * @return list<string>
     */
    public static function untraced(string $answer, array $results, string $asked = ''): array
    {
        $known = [];
        foreach ($results as $result) {
            array_push($known, ...self::figures($result));
        }
        $known = array_values(array_unique($known, SORT_REGULAR));
        $said  = [];
        foreach (self::numbers($asked) as [$readings]) {
            array_push($said, ...array_column($readings, 0));
        }
        $text  = (string) preg_replace(self::NUMBERING, '', $answer);
        $out   = [];
        foreach (self::numbers($text) as [$readings, $written, $percent]) {
            foreach ($readings as [$value, $decimals]) {
                if (self::isYear($written) || self::near($value, $decimals, $said) || self::traced($value, $decimals, $percent, $known)) {
                    continue 2;
                }
            }
            $out[] = trim($written);
        }

        return array_values(array_unique($out));
    }

    /**
     * Each number in a text: the values it may be read as — 1.234 is a thousand and more, or one
     * and a bit — each with its decimals; as written; and whether a % follows it.
     *
     * @return list<array{0: list<array{0: float, 1: int}>, 1: string, 2: bool}>
     */
    private static function numbers(string $text): array
    {
        preg_match_all(self::NUMBER, (string) preg_replace(self::DATES, ' ', $text), $found, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [self::readings(rtrim($m[0], " %\u{a0}")), $m[0], ($m[1] ?? '') !== ''], $found);
    }

    /**
     * The values a written number may mean.
     *
     * @return list<array{0: float, 1: int}>
     */
    private static function readings(string $written): array
    {
        $dot   = substr_count($written, '.');
        $comma = substr_count($written, ',');
        if ($dot === 0 && $comma === 0) {
            return [[(float) $written, 0]];
        }
        if ($dot > 0 && $comma > 0) {
            // Both: the last one is the decimal point.
            $point = strrpos($written, '.') > strrpos($written, ',') ? '.' : ',';

            return [self::read($written, $point)];
        }
        $mark = $dot > 0 ? '.' : ',';
        if (($dot + $comma) > 1) {
            return [self::read($written, '')];
        }
        $after = strlen($written) - strrpos($written, $mark) - 1;

        // One mark before three digits: a thousand, or a decimal — either.
        return $after === 3 ? [self::read($written, ''), self::read($written, $mark)] : [self::read($written, $mark)];
    }

    /**
     * A written number, `$point` its decimal mark ('' for none).
     *
     * @return array{0: float, 1: int}
     */
    private static function read(string $written, string $point): array
    {
        if ($point === '') {
            return [(float) str_replace(['.', ','], '', $written), 0];
        }
        [$whole, $fraction] = explode($point, $written, 2) + [1 => ''];

        return [(float) (str_replace(['.', ','], '', $whole) . '.' . $fraction), strlen($fraction)];
    }

    /** A year as a year is written: four digits, 1900 to 2100. */
    private static function isYear(string $written): bool
    {
        return preg_match('~^(19|20)\d\d$|^2100$~', trim($written)) === 1;
    }

    /**
     * The figures a result holds: its values, and the totals of its lists and columns. JSON is
     * read as data; any other text by its numbers.
     *
     * @return list<float>
     */
    private static function figures(string $result): array
    {
        $data = json_decode($result, true);
        if (!is_array($data)) {
            $out = [];
            foreach (self::numbers($result) as [$readings]) {
                array_push($out, ...array_column($readings, 0));
            }

            return $out;
        }
        $out = [];
        self::walk($data, $out);

        return $out;
    }

    /**
     * A node's values, its numbers' total, and — for a list of rows — each column's total.
     *
     * @param array<mixed> $node
     * @param list<float>  $out
     */
    private static function walk(array $node, array &$out): void
    {
        $numbers = [];
        $columns = [];
        $rows    = $node !== [];
        foreach ($node as $child) {
            if (is_int($child) || is_float($child) || (is_string($child) && is_numeric($child))) {
                $numbers[] = (float) $child;
                $rows      = false;
            } elseif (is_array($child)) {
                self::walk($child, $out);
                // A row has names for its values; a list in a list is not a row.
                foreach (array_is_list($child) ? [] : $child as $key => $value) {
                    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                        $columns[$key][] = (float) $value;
                    }
                }
            } else {
                $rows = false;
            }
        }
        array_push($out, ...$numbers);
        if (count($numbers) > 1) {
            $out[] = array_sum($numbers);
        }
        foreach ($rows ? $columns : [] as $column) {
            if (count($column) > 1) {
                $out[] = array_sum($column);
            }
        }
    }

    /**
     * Whether a value follows from the figures: one of them, the sum or difference of two, or —
     * written as a percentage — one as a share of another, or the change from one to another.
     *
     * @param list<float> $known
     */
    private static function traced(float $value, int $decimals, bool $percent, array $known): bool
    {
        if (self::near($value, $decimals, $known) || ($percent && self::near($value / 100, $decimals + 2, $known))) {
            return true;
        }
        if (count($known) > self::PAIRS) {
            return false;
        }
        foreach ($known as $i => $a) {
            foreach ($known as $j => $b) {
                if ($i === $j) {
                    continue;
                }
                if (self::near($value, $decimals, [$a + $b, $a - $b])
                    || ($percent && $b != 0.0 && self::near($value, $decimals, [$a / $b * 100, ($a - $b) / $b * 100]))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a value, as written, is any of these: to its decimals, or — a round hundred or more —
     * within 5%. The sign is not compared: "down 12%" is -12.
     *
     * @param list<float> $values
     */
    private static function near(float $value, int $decimals, array $values): bool
    {
        $tolerance = 0.5 * 10 ** -$decimals + 1e-9;
        if ($decimals === 0 && $value >= 100 && fmod($value, 100) == 0.0) {
            $tolerance = max($tolerance, $value * 0.05);
        }
        foreach ($values as $one) {
            if (abs(abs($one) - $value) <= $tolerance) {
                return true;
            }
        }

        return false;
    }
}
