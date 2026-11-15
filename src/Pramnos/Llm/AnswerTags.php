<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * Patterns looked for in an answer's own text, once it is written — never asked of the model, so
 * they work the same whichever provider answered and however it happened to phrase things.
 *
 * Two uses, both post-hoc, both opt-in:
 *
 * - {@see leaked()}: a provider's own tool-call protocol, dumped as plain text instead of reaching
 *   `Client::askWithTools()` through the structured field this is built on. Several self-hosted
 *   models' serving layers mark their reserved tokens with a fullwidth pipe
 *   (`<｜tool▁calls▁begin｜>` and the like) — when that layer's own grammar misfires and the
 *   tokens land in the ordinary content stream instead, this framework has no way to fix the
 *   upstream bug, but it can stop showing somebody raw JSON and reserved-token markers in place
 *   of an answer. `'llm' => ['leaked_patterns' => ['~<\|tool_call\|>~']]` adds a shape of an
 *   application's own local model, beside the fullwidth-pipe one this already knows.
 * - {@see matches()}: the general case `leaked()` is built on — a list of substrings or regular
 *   expressions, checked against an answer once it is written. Used for `'llm' => ['chat'] =>
 *   ['capability_gap_patterns' => [...]]]`: the words an assistant's own system prompt already
 *   asks it to use when it says plainly it cannot do something, so a review turns into a `WHERE`
 *   clause on `extra.capability_gap` instead of reading a conversation export by hand.
 */
final class AnswerTags
{
    /** `<｜…｜` or `</｜…｜` — several self-hosted models' own shape for a reserved token. */
    private const LEAKED = '~</?\x{FF5C}{2}~u';

    /** Whether `$text` carries a provider's own tool-call protocol, leaked as plain content. */
    public static function leaked(string $text): bool
    {
        return self::matches($text, [self::LEAKED, ...array_map('strval', (array) Llm::config('leaked_patterns', []))]);
    }

    /**
     * Whether `$text` matches any of `$patterns` — each a plain substring, read
     * case-insensitively, or, starting with `~`, a PCRE pattern of the caller's own.
     *
     * @param list<string> $patterns
     */
    public static function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '~')) {
                if (@preg_match($pattern, $text) === 1) {
                    return true;
                }
                continue;
            }
            if (mb_stripos($text, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
