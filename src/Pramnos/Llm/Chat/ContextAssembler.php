<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\Client;
use Pramnos\Llm\Pricing;

/**
 * An answer's context in three zones, laid out so that a provider's prompt cache keeps as much of
 * it as it can from one question to the next.
 *
 * | Zone | Where | Holds | Changes |
 * |---|---|---|---|
 * | 1, static prefix | the system prompt | the assistant's role and rules ({@see Assistant::systemPrompt()}) | with the code, never between two questions |
 * | 2, semi-static | a system message ahead of the history | what is remembered, what is pinned, the summary of earlier messages | when one of those changes |
 * | 3, dynamic tail | a system message just before the question | the time, the assistant's `volatileContext()` — the page the person is on — and the notice for an attached document | every question |
 *
 * A cache matches a request from its start. With the time and the page at the end rather than in
 * the system prompt, two questions in a row share everything up to the last question but one; with
 * them at the top they shared nothing, and every question paid for its whole context again.
 *
 * **The static prefix is watched.** {@see checkPrefix()} remembers its hash per conversation and
 * logs a warning, with the first line that differs, when it changes between two questions while
 * the assistant's code and the `llm` settings have not: something volatile has crept into zone 1.
 * {@see cacheReport()} logs a warning when a question that followed others was mostly not read
 * from the cache.
 */
final class ContextAssembler
{
    /** Below this many prompt tokens a provider caches nothing, so a low hit ratio means nothing. */
    public const CACHE_FLOOR = 1024;

    /** A hit ratio under this, on a question that followed others, is logged. */
    public const HIT_RATIO = 0.8;

    /** The log the warnings go to. */
    public const LOG = 'llm';

    /** What the framework adds to zone 3 itself, beside the assistant's own. */
    private string $alsoNow = '';

    public function __construct(private readonly Assistant $assistant)
    {
    }

    /** Add to zone 3 what changes too often for zone 2 — how the person feels, for one. */
    public function alsoNow(string $text): static
    {
        $this->alsoNow = trim($text);

        return $this;
    }

    /** Zone 1: the assistant's role and rules, byte for byte the same from one question to the next. */
    public function staticPrefix(Turn $turn): string
    {
        return trim($this->assistant->systemPrompt($turn));
    }

    /**
     * Zone 3: what is true only now — the time in the assistant's zone, what the assistant adds
     * for this question, and the notice for an attached document.
     */
    public function dynamicTail(Turn $turn): string
    {
        $zone = $this->assistant->timezone($turn->scope);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $own  = trim((method_exists($this->assistant, 'volatileContext') ? (string) $this->assistant->volatileContext($turn) : '')
            . ($this->alsoNow === '' ? '' : "\n\n" . $this->alsoNow));

        return 'Now: ' . $now->format('l j F Y, H:i') . ' (' . $zone . ').'
            . ($own !== '' ? "\n\n" . $own : '')
            . ($turn->documents === [] ? '' : "\n\n" . Client::DATA_NOTICE);
    }

    /**
     * The messages as they are sent: zone 2 ahead of the history, zone 3 just before the last
     * message — the question. Zone 1 is the system prompt, sent apart.
     *
     * @param list<array<string, mixed>> $messages The history and the question
     * @return list<array<string, mixed>>
     */
    public function messages(Turn $turn, string $semiStatic, array $messages): array
    {
        $last = array_pop($messages);
        $out  = trim($semiStatic) === '' ? [] : [['role' => 'system', 'content' => trim($semiStatic)]];

        return [...$out, ...$messages, ['role' => 'system', 'content' => $this->dynamicTail($turn)], ...($last === null ? [] : [$last])];
    }

    /**
     * Compare zone 1 with what it was at this conversation's last question; log a warning, with
     * the first line that differs, when it changed while neither the assistant's code nor the
     * `llm` settings did. Answers the prefix's hash.
     */
    public function checkPrefix(string $prefix, ?int $conversationId): string
    {
        $hash = hash('sha256', $prefix);
        if ($conversationId === null) {
            return $hash;
        }

        try {
            $cache  = \Pramnos\Cache\Cache::getInstance('llm');
            $key    = 'prefix-' . $conversationId;
            $marker = $this->marker();
            $before = $cache->load($key);
            $before = is_string($before) ? json_decode($before, true) : $before;

            if (is_array($before) && ($before['marker'] ?? '') === $marker && ($before['hash'] ?? '') !== $hash) {
                \Pramnos\Logs\Logger::log('Cache invalidation: the static prompt of conversation ' . $conversationId
                    . ' changed between two questions with the same code and settings. First difference: '
                    . self::firstDifference((string) ($before['text'] ?? ''), $prefix), self::LOG);
            }
            $cache->save((string) json_encode(['hash' => $hash, 'marker' => $marker, 'text' => mb_substr($prefix, 0, 20000)]), $key);
        } catch (\Throwable) {
            // A cache that cannot keep it costs the warning, never the answer.
        }

        return $hash;
    }

    /**
     * The share of an answer's prompt tokens read from the provider's cache, or null when the
     * provider does not say or the prompt was too small to cache. On a question that followed
     * others, a share under {@see HIT_RATIO} is logged.
     *
     * @param array<string, mixed> $usage
     */
    public static function cacheReport(array $usage, bool $followsOthers, ?int $conversationId = null): ?float
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $says   = isset($usage['prompt_cache_hit_tokens']) || isset($usage['prompt_tokens_details']['cached_tokens']);
        if (!$says || $prompt < self::CACHE_FLOOR) {
            return null;
        }

        $ratio = Pricing::splitPromptTokens($usage)['hit'] / $prompt;
        if ($followsOthers && $ratio < self::HIT_RATIO) {
            \Pramnos\Logs\Logger::log(sprintf(
                'Cache hit ratio %d%% on %d prompt tokens%s — under %d%%: the prompt\'s start changed since the last question.',
                (int) round($ratio * 100),
                $prompt,
                $conversationId === null ? '' : ' in conversation ' . $conversationId,
                (int) round(self::HIT_RATIO * 100)
            ), self::LOG);
        }

        return $ratio;
    }

    /** What zone 1 is made from besides the turn: the assistant's code and the `llm` settings. */
    private function marker(): string
    {
        $file = (new \ReflectionClass($this->assistant))->getFileName();

        return hash('sha256', get_class($this->assistant) . '|' . ($file !== false ? (string) @filemtime($file) : '')
            . '|' . json_encode(\Pramnos\Llm\Llm::config('chat', [])) . '|' . json_encode(\Pramnos\Llm\Llm::config('analyst', [])));
    }

    /** The first line that differs between two texts, both sides, cut short. */
    private static function firstDifference(string $before, string $now): string
    {
        $a = explode("\n", $before);
        $b = explode("\n", $now);
        foreach ($b as $at => $line) {
            if (($a[$at] ?? null) !== $line) {
                return 'line ' . ($at + 1) . ' was "' . mb_substr((string) ($a[$at] ?? ''), 0, 160) . '", is "' . mb_substr($line, 0, 160) . '"';
            }
        }

        return 'lines removed after line ' . count($b);
    }
}
