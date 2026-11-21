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
 *
 * A count and an amount beside it in the same sentence are held to each other too, where a
 * result's rows carry both: each on its own is only ever checked against *some* subset of its
 * own column, so a count that rightly sums three categories and an amount that sums only two of
 * them both pass alone ({@see inconsistentPairs()}).
 *
 * A fenced code block's own numbers (a chart's JSON, say) are read exactly, as JSON, never by
 * the prose regex the rest of the check uses — a compact array has no thousands-grouping marks
 * to tell its comma-separated elements apart from a single long number ({@see fencedUntraced()}).
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

    /** A fenced code block: ` ```lang\n...\n``` ` or bare ` ```...``` `. */
    private const FENCED = '~```[^\n`]*\n(.*?)\n?```~su';

    /** A markdown table row — pipes at both ends; its cells are between them. */
    private const TABLE_ROW = '~^[ \t]*\|(.+)\|[ \t]*$~mu';

    /** A cell with nothing in it but a bare, comma-separated list of plain integers, two or more. */
    private const NUMERIC_LIST_CELL = '~^\s*\d+(?:\s*,\s*\d+)+\s*$~u';

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
        $rowGroups    = [];
        foreach ($results as $result) {
            array_push($known, ...self::figures($result));
            array_push($knownStrings, ...self::stringLeaves($result));
            array_push($rowGroups, ...self::rowGroups($result));
        }
        $known = array_values(array_unique($known, SORT_REGULAR));
        $said  = [];
        foreach (self::numbers($asked) as [$readings]) {
            array_push($said, ...array_column($readings, 0));
        }
        // A fenced code block (a chart's JSON, say) is read the same way a tool's own JSON
        // result is — exact values, no grouping marks to misread — never by the number regex
        // below: a compact array ("data":[4676,2111,658,...]) is written with no thousands
        // grouping at all, and that regex, built for how people write numbers in prose, glues
        // its comma-separated elements into one bogus figure. FW-573: reported live from a
        // chart block, 16 real figures flagged as invented over one real typo elsewhere, which
        // cost more than the typo itself — the answer was rejected so widely that the model
        // abandoned an already-correct tool result for improvised SQL instead.
        $text = (string) preg_replace(self::NUMBERING, '', $answer);
        [$text, $fenced] = self::fencedUntraced($text, $known, $said);
        $out  = $fenced;
        [$text, $tableListed] = self::tableListsUntraced($text, $known, $said);
        array_push($out, ...$tableListed);
        foreach (self::numbers($text) as [$readings, $written, $percent]) {
            foreach ($readings as [$value, $decimals]) {
                if (self::isYear($written) || self::near($value, $decimals, $said) || self::traced($value, $decimals, $percent, $known)) {
                    continue 2;
                }
            }
            $out[] = trim($written);
        }
        array_push($out, ...self::inconsistentPairs($text, $rowGroups));

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
     * The numbers inside every fenced code block of a text that none of the known figures bears
     * out — each block's own JSON read exactly, the same way {@see figures()} reads a tool's
     * result, with no thousands-grouping ambiguity to misparse. A block that is not valid JSON
     * is left to the ordinary prose check instead.
     *
     * @param list<float> $known
     * @param list<float> $said
     * @return array{0: string, 1: list<string>} The text with every JSON block blanked, and what did not trace
     */
    private static function fencedUntraced(string $text, array $known, array $said): array
    {
        $out  = [];
        $text = (string) preg_replace_callback(self::FENCED, static function (array $m) use (&$out, $known, $said): string {
            $data = json_decode($m[1], true);
            if (!is_array($data)) {
                // Not JSON: left whole, numbers and all, to the ordinary prose check below.
                return $m[0];
            }
            $values = [];
            self::leaves($data, $values);
            foreach ($values as $value) {
                // Both sides are exact floats decoded from JSON, never hand-typed prose: a tight
                // tolerance, not the looser one a written number's own precision needs.
                if (!self::near($value, 6, $said) && !self::traced($value, 6, false, $known)) {
                    $out[] = $value === (float) (int) $value ? (string) (int) $value : rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
                }
            }

            return str_repeat(' ', strlen($m[0]));
        }, $text);

        return [$text, $out];
    }

    /**
     * A markdown table cell that is nothing but a bare, comma-separated list of plain integers
     * ("4676,2111,658,...") is read one number at a time — the same reasoning
     * {@see fencedUntraced()} applies to a chart's JSON: no thousands-grouping marks tell a
     * programmatic list's elements apart from one very large formatted number, and the prose
     * check glues them into one. FW-527: reported live from exactly that, in a table cell this
     * time rather than a fenced block.
     *
     * Left alone where every group after the first happens to be exactly 3 digits —
     * indistinguishable from a real grouped number without more context ("100,200,300" could
     * honestly be either), so left to the ordinary check instead rather than risk splitting a
     * genuine one apart.
     *
     * @param list<float> $known
     * @param list<float> $said
     * @return array{0: string, 1: list<string>} The text with every such cell blanked, and what did not trace
     */
    private static function tableListsUntraced(string $text, array $known, array $said): array
    {
        $out  = [];
        $text = (string) preg_replace_callback(self::TABLE_ROW, static function (array $row) use (&$out, $known, $said): string {
            $cells = explode('|', $row[1]);
            foreach ($cells as &$cell) {
                if (preg_match(self::NUMERIC_LIST_CELL, $cell) !== 1) {
                    continue;
                }
                $parts   = array_map('trim', explode(',', $cell));
                $grouped = array_filter(array_slice($parts, 1), static fn (string $p): bool => strlen($p) !== 3) === [];
                if ($grouped) {
                    continue;
                }
                foreach ($parts as $part) {
                    $value = (float) $part;
                    if (!self::near($value, 0, $said) && !self::traced($value, 0, false, $known)) {
                        $out[] = $part;
                    }
                }
                $cell = str_repeat(' ', strlen($cell));
            }

            return '|' . implode('|', $cells) . '|';
        }, $text);

        return [$text, $out];
    }

    /**
     * Every numeric leaf of a JSON structure, no computed totals — the block itself is what is
     * being checked against the known figures, not a trusted source of new ones to check
     * everything else against ({@see walk()}, which adds a list's or column's own total, is for
     * the other direction).
     *
     * @param array<mixed> $node
     * @param list<float>  $out
     */
    private static function leaves(array $node, array &$out): void
    {
        foreach ($node as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $out[] = (float) $value;
            } elseif (is_array($value)) {
                self::leaves($value, $out);
            }
        }
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

    /** A list of rows, from {@see walkRowGroups()}: more than this many costs more to check than the table is worth. */
    private const COLUMN_ROWS = 16;

    /**
     * Every "list of rows" a result holds, each row's numeric columns aligned by index — only a
     * group with more than one column, since a single one has nothing else to be consistent with
     * ({@see inconsistentPairs()}).
     *
     * @return list<array<string, list<float>>>
     */
    private static function rowGroups(string $result): array
    {
        $data = json_decode($result, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        self::walkRowGroups($data, $out);

        return $out;
    }

    /**
     * @param array<mixed>                      $node
     * @param list<array<string, list<float>>>  $out
     */
    private static function walkRowGroups(array $node, array &$out): void
    {
        if (array_is_list($node) && $node !== [] && count($node) <= self::COLUMN_ROWS) {
            $columns = [];
            $isRows  = true;
            foreach ($node as $row) {
                if (!is_array($row) || array_is_list($row)) {
                    $isRows = false;

                    break;
                }
                foreach ($row as $key => $value) {
                    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                        $columns[$key][] = (float) $value;
                    }
                }
            }
            // Only a column every row actually had — one missing it is not a row group anymore.
            $aligned = $isRows ? array_filter($columns, static fn (array $c): bool => count($c) === count($node)) : [];
            if (count($aligned) > 1) {
                $out[] = $aligned;
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                self::walkRowGroups($child, $out);
            }
        }
    }

    /**
     * Every non-empty subset's sum, keyed by its row mask.
     *
     * @param list<float> $values
     * @return array<int, float>
     */
    private static function subsetSums(array $values): array
    {
        $n    = count($values);
        $bit  = array_flip(array_map(static fn (int $i): int => 1 << $i, range(0, $n - 1)));
        $sums = [0 => 0.0];
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $low         = $mask & -$mask;
            $sums[$mask] = $sums[$mask ^ $low] + $values[$bit[$low]];
        }
        unset($sums[0]);

        return $sums;
    }

    /**
     * The row masks whose column sums equal this value — empty if none, more than one if several
     * different subsets happen to total the same figure (too ambiguous to hold anything to).
     *
     * @param array<int, float> $sums
     * @return list<int>
     */
    private static function matchingMasks(float $value, int $decimals, array $sums): array
    {
        $out = [];
        foreach ($sums as $mask => $sum) {
            if (self::near($value, $decimals, [$sum])) {
                $out[] = $mask;
            }
        }

        return $out;
    }

    /**
     * A count and an amount in the same sentence, each a specific subset of the same rows'
     * figures — one not the partial sum the other is. Every `numeric` tool's figures are checked
     * pairwise ({@see traced()}) with no idea which of a result's rows an answer means to add up,
     * so a count that correctly sums three categories and an amount that only sums two of them
     * both pass on their own — each is, by itself, a number some subset of its column sums to.
     * FW-571: reported live from exactly that — a count right, its amount short one category,
     * nothing catching the two disagreeing on which rows they added.
     *
     * Caught only where a number resolves to exactly one subset of its column; several subsets
     * landing on the same figure leaves nothing to hold the other column to, so that stays with
     * the ordinary check. The first such number in a sentence anchors it; a later one from a
     * different column is checked against that anchor's rows, not held to being first or last.
     *
     * @param list<array<string, list<float>>> $rowGroups
     * @return list<string>
     */
    private static function inconsistentPairs(string $text, array $rowGroups): array
    {
        if ($rowGroups === []) {
            return [];
        }
        $out = [];
        foreach (preg_split('~\n+|(?<=[.!?])\s+~u', $text) ?: [$text] as $sentence) {
            foreach ($rowGroups as $columns) {
                $sums      = array_map(self::subsetSums(...), $columns);
                $reference = null;
                foreach (self::numbers($sentence) as [$readings, $written, $percent]) {
                    if ($percent || self::isYear($written)) {
                        continue;
                    }
                    foreach ($readings as [$value, $decimals]) {
                        foreach ($sums as $column => $colSums) {
                            $masks = self::matchingMasks($value, $decimals, $colSums);
                            if (count($masks) !== 1) {
                                continue;
                            }
                            if ($reference === null) {
                                $reference = [$column, $masks[0]];
                            } elseif ($reference[0] !== $column && $reference[1] !== $masks[0]) {
                                $out[] = trim($written);
                            }
                        }
                    }
                }
            }
        }

        return $out;
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
