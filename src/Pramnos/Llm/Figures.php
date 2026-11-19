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
 *
 * A name can be just as fabricated as a number, and a failed join produces exactly that: a real,
 * traced count or id, with a name invented because the real one was never fetched. So a sentence
 * that traces a number is also checked for a capitalized name next to it that matches no string
 * value anywhere in the results ({@see stemMatches()} — tolerant of a different grammatical
 * ending, not of an unrelated name). Checked only where the results carried a string worth
 * comparing against at all, so a purely numeric result never flags an ordinary capitalized word
 * in the prose around it.
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
     * One capitalized word — not an acronym, not a sentence's own capital letter alone.
     * Single words only, deliberately: a run of several ("Νέα Υόρκη") would glue a sentence's
     * own opening capital onto the real name right after it ("Overall, Athens" as one candidate,
     * neither half of which then matches "Athens" alone) — simpler and more reliable to check
     * each word on its own, at the cost of a multi-word name needing each of its words to match.
     */
    private const PROPER_NOUN = '~(?<![\p{L}])\p{Lu}\p{Ll}{2,}(?![\p{L}])~u';

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
        $known        = [];
        $knownStrings = [];
        foreach ($results as $result) {
            array_push($known, ...self::figures($result));
            array_push($knownStrings, ...self::stringLeaves($result));
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

        // A failed join produces exactly this: a real, traced count or id, with a name
        // invented because the real one was never fetched. Only checked where the
        // results actually carried a string worth comparing against — a tool that
        // returned pure numbers leaves nothing here to flag against, which keeps this
        // from catching an ordinary capitalized word in prose that never claimed a name
        // came from a tool at all.
        if ($knownStrings !== []) {
            $askedNames = self::properNouns($asked);
            // A bare newline is its own boundary, not just "[.!?\n] followed by
            // whitespace": that lookbehind form needs a whitespace character AFTER
            // the one it just matched as the boundary, so a single "\n" between two
            // lines — one \n, next line starts right at a letter, no second space —
            // never actually split anything. A markdown table (one \n per row) then
            // read as ONE "sentence", sweeping header words ("Περιοχή", "Κατάσταση")
            // into whatever data row's traced number gated the check, and flagging
            // them as fabricated names — reproduced in production.
            foreach (preg_split('~\n+|(?<=[.!?])\s+~u', $text) ?: [$text] as $sentence) {
                if (!self::sentenceTracesANumber($sentence, $known, $said)) {
                    continue;
                }
                foreach (self::properNouns($sentence, excludeSentenceStart: true) as $name) {
                    if (!in_array($name, $askedNames, true) && !self::stemMatches($name, $knownStrings)) {
                        $out[] = $name;
                    }
                }
            }
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
     * The string values a result holds — recursed the same way {@see figures()} recurses
     * numbers, through every row and column — skipping one that is itself a number written as
     * a string (`figures()`'s own job) and one with no letter in it at all (an id, a code).
     *
     * @return list<string>
     */
    private static function stringLeaves(string $result): array
    {
        $data = json_decode($result, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        self::walkStrings($data, $out);

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $out
     */
    private static function walkStrings(array $node, array &$out): void
    {
        foreach ($node as $value) {
            if (is_string($value) && !is_numeric($value) && preg_match('~\p{L}~u', $value) === 1) {
                $out[] = $value;
            } elseif (is_array($value)) {
                self::walkStrings($value, $out);
            }
        }
    }

    /**
     * Every capitalized word in a text, in the order it appears.
     *
     * `$excludeSentenceStart` drops the one sitting at the very start of `$text` — a sentence's
     * own opening capital ("Overall, …", "Συνολικά, …") is not a name, and `$text` here is
     * always one sentence, never more.
     */
    private static function properNouns(string $text, bool $excludeSentenceStart = false): array
    {
        preg_match_all(self::PROPER_NOUN, $text, $found, PREG_OFFSET_CAPTURE);
        $leadingOffset = strlen($text) - strlen(ltrim($text));
        $out = [];
        foreach ($found[0] as [$word, $offset]) {
            if ($excludeSentenceStart && $offset === $leadingOffset) {
                continue;
            }
            $out[] = $word;
        }

        return array_values(array_unique($out));
    }

    /** Whether at least one number in a sentence is traced — used to gate the name check on it. */
    private static function sentenceTracesANumber(string $sentence, array $known, array $said): bool
    {
        foreach (self::numbers($sentence) as [$readings, $written, $percent]) {
            if (self::isYear($written)) {
                continue;
            }
            foreach ($readings as [$value, $decimals]) {
                if (!self::near($value, $decimals, $said) && self::traced($value, $decimals, $percent, $known)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a name matches a known string closely enough to be the same word — tolerant of a
     * different grammatical ending (Greek declension changes a name's last few letters: Βέροια,
     * Βεροίας, Βέροιας are all the same place), not of a genuinely different name. Diacritics and
     * case are stripped first, then the shorter string's first few letters must still be the
     * other's — a short word needs an exact match, since a 1-2 letter prefix matches almost
     * anything.
     *
     * ponytail: a stem's shared-prefix length (4, floor to the shorter word) is a heuristic, not
     * a real Greek stemmer — it can still miss an irregular form or match two unrelated short
     * words that happen to start alike. Upgrade to a proper stemmer if this proves too noisy or
     * too lenient in practice.
     *
     * @param list<string> $knownStrings
     */
    private static function stemMatches(string $name, array $knownStrings): bool
    {
        $needle = self::stem($name);
        foreach ($knownStrings as $candidate) {
            $hay = self::stem($candidate);
            if ($needle === $hay) {
                return true;
            }
            $prefix = min(4, mb_strlen($needle), mb_strlen($hay));
            if ($prefix >= 3 && mb_substr($needle, 0, $prefix) === mb_substr($hay, 0, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercased, with every diacritic stripped — ext-intl's Normalizer where it is installed. */
    private static function stem(string $text): string
    {
        $text = class_exists(\Normalizer::class)
            ? (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D))
            : $text;

        return mb_strtolower(trim($text));
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
