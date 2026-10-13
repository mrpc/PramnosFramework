<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Application\Settings;

/**
 * Turning token counts into money.
 *
 * ## Three buckets rather than one rate
 *
 * A cached input token costs a fiftieth of an uncached one on some providers, so two calls with
 * identical token counts can differ in cost by an order of magnitude. One "per 1M tokens" figure
 * would be wrong for every call, and confidently so.
 *
 * ## The prices are a setting
 *
 * No provider publishes a pricing endpoint, so unit prices are configured — on the
 * administration screen, in `llm_prices` — and a provider changing its prices does not need a
 * deploy. {@see SEED} is what the setting starts out as, never a source of truth.
 *
 * ## The estimate is not the bill
 *
 * It says so wherever it is shown. `Connection::balance()` and `Billing\OpenAiCosts` are what
 * the providers themselves say.
 */
final class Pricing
{
    /** Where the editable prices live, as JSON. */
    public const SETTING = 'llm_prices';

    public const CURRENCY = 'USD';

    /**
     * USD per million tokens, as a starting point for the setting.
     *
     * An entry may also carry `off_peak` — the same three buckets — and `peak_utc` — `days`
     * (1 Monday … 7 Sunday) and `hours` (0–23, UTC); a call outside those hours is costed at
     * `off_peak`.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SEED = [
        'deepseek-chat'     => [
            'cache_hit' => 0.006, 'cache_miss' => 0.30, 'output' => 1.20,
            'off_peak'  => ['cache_hit' => 0.003, 'cache_miss' => 0.15, 'output' => 0.60],
            'peak_utc'  => ['days' => [1, 2, 3, 4, 5], 'hours' => [1, 2, 3, 6, 7, 8, 9]],
        ],
        'deepseek-reasoner'     => ['cache_hit' => 0.14, 'cache_miss' => 0.55, 'output' => 2.19],
        'gpt-4o-mini'           => ['cache_hit' => 0.075, 'cache_miss' => 0.15, 'output' => 0.60],
        // ponytail: uncached at the cache-write price — OpenAI reports no write count, so this errs high.
        'gpt-6-luna'            => ['cache_hit' => 0.01, 'cache_miss' => 0.125, 'output' => 0.50],
        'gpt-4o'                => ['cache_hit' => 1.25, 'cache_miss' => 2.50, 'output' => 10.00],
        'gemini-3.1-flash-lite' => ['cache_hit' => 0.025, 'cache_miss' => 0.25, 'output' => 1.50],
        'gemini-3.8-flash'      => ['cache_hit' => 0.075, 'cache_miss' => 0.75, 'output' => 3.75],
        'mistral-small-2603'    => ['cache_hit' => 0.015, 'cache_miss' => 0.15, 'output' => 0.60],
        'mistral-large-latest'  => ['cache_hit' => 0.05, 'cache_miss' => 0.50, 'output' => 1.50],
    ];

    /** The three things a call is billed for. */
    public const BUCKETS = ['cache_hit', 'cache_miss', 'output'];

    /** @var array<string, array<string, mixed>> */
    private array $prices;

    /** @param array<string, array<string, mixed>>|null $prices Null reads the setting */
    public function __construct(?array $prices = null)
    {
        $this->prices = self::clean($prices ?? self::stored());
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->prices;
    }

    /** @return array<string, mixed>|null The three buckets, and `off_peak` / `peak_utc` where priced by the hour */
    public function forModel(string $model): ?array
    {
        return $this->prices[$model] ?? null;
    }

    /**
     * What one call cost, or null when this model has no prices.
     *
     * **Null, not nought.** A model nobody priced costs something, and a total adding noughts
     * for it would be confidently too low.
     *
     * @param array<string, mixed> $usage The provider's own `usage` object
     * @param int|null             $at    When the call was made; now by default
     */
    public function cost(string $model, array $usage, ?int $at = null): ?float
    {
        $prices = $this->forModel($model);

        if ($prices === null) {
            return null;
        }

        if (isset($prices['off_peak']) && !self::isPeak($prices['peak_utc'], $at ?? time())) {
            $prices = $prices['off_peak'];
        }

        ['hit' => $hit, 'miss' => $miss] = self::splitPromptTokens($usage);

        return (($hit * $prices['cache_hit']) + ($miss * $prices['cache_miss'])
            + (self::outputTokens($usage) * $prices['output'])) / 1_000_000;
    }

    /**
     * The prompt tokens, split into cached and not, however the provider says it.
     *
     * DeepSeek gives both counts; OpenAI gives only the cached one. A provider that reports
     * neither billed everything at the uncached rate, which is how it must be costed — not as free.
     *
     * @param array<string, mixed> $usage
     * @return array{hit: int, miss: int}
     */
    public static function splitPromptTokens(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);

        if (isset($usage['prompt_cache_hit_tokens']) || isset($usage['prompt_cache_miss_tokens'])) {
            $hit = (int) ($usage['prompt_cache_hit_tokens'] ?? 0);

            return ['hit' => $hit, 'miss' => (int) ($usage['prompt_cache_miss_tokens'] ?? max(0, $prompt - $hit))];
        }

        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return ['hit' => $cached, 'miss' => max(0, $prompt - $cached)];
    }

    /**
     * The output tokens. Gemini bills its thinking as output but leaves it out of
     * `completion_tokens`, so what the total holds beyond the prompt is output too.
     *
     * @param array<string, mixed> $usage
     */
    public static function outputTokens(array $usage): int
    {
        return max((int) ($usage['completion_tokens'] ?? 0), (int) ($usage['total_tokens'] ?? 0) - (int) ($usage['prompt_tokens'] ?? 0));
    }

    /**
     * Every token a usage object counts.
     *
     * @param array<string, mixed> $usage
     */
    public static function tokensIn(array $usage): int
    {
        return max((int) ($usage['total_tokens'] ?? 0), (int) ($usage['prompt_tokens'] ?? 0) + self::outputTokens($usage));
    }

    /** The seed, as the setting's starting value. */
    public static function seedJson(): string
    {
        return (string) json_encode(self::SEED, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validate prices an administrator typed, and say what is wrong.
     *
     * @return array{ok: bool, says: string, json: string}
     */
    public static function validate(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || $decoded === []) {
            return ['ok' => false, 'says' => 'The prices are not a JSON object of models.', 'json' => $json];
        }

        $kept    = self::clean($decoded);
        $dropped = array_diff(array_keys($decoded), array_keys($kept));

        if ($dropped !== []) {
            return ['ok' => false, 'says' => 'These need cache_hit, cache_miss and output as numbers: '
                . implode(', ', $dropped) . '.', 'json' => $json];
        }

        return ['ok' => true, 'says' => '', 'json' => (string) json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
    }

    /** @return array<string, mixed> What the setting holds, or the seed when it holds nothing usable */
    private static function stored(): array
    {
        $raw = (string) Settings::getSetting(self::SETTING, '');

        if (trim($raw) === '') {
            return self::SEED;
        }

        $decoded = json_decode($raw, true);

        // A setting edited into invalid JSON must not take costing down.
        return is_array($decoded) && $decoded !== [] ? $decoded : self::SEED;
    }

    /**
     * Only the shapes this can cost with: a model missing a bucket would bill it at nought, an
     * estimate wrong downwards, which is the direction nobody checks.
     *
     * @param array<mixed> $prices
     * @return array<string, array<string, mixed>>
     */
    private static function clean(array $prices): array
    {
        $kept = [];

        foreach ($prices as $model => $buckets) {
            if (!is_array($buckets)) {
                continue;
            }

            $row = [];

            foreach (self::BUCKETS as $bucket) {
                if (!isset($buckets[$bucket]) || !is_numeric($buckets[$bucket])) {
                    continue 2;
                }

                $row[$bucket] = (float) $buckets[$bucket];
            }

            $off = (array) ($buckets['off_peak'] ?? []);
            $at  = (array) ($buckets['peak_utc'] ?? []);

            if (count(array_filter(self::BUCKETS, static fn (string $b): bool => isset($off[$b]) && is_numeric($off[$b]))) === count(self::BUCKETS)
                && is_array($at['days'] ?? null) && is_array($at['hours'] ?? null)) {
                $row['off_peak'] = array_map('floatval', array_intersect_key($off, array_flip(self::BUCKETS)));
                $row['peak_utc'] = ['days' => array_map('intval', $at['days']), 'hours' => array_map('intval', $at['hours'])];
            }

            $kept[(string) $model] = $row;
        }

        return $kept;
    }

    /** @param array{days: list<int>, hours: list<int>} $peak */
    private static function isPeak(array $peak, int $at): bool
    {
        return in_array((int) gmdate('N', $at), $peak['days'], true) && in_array((int) gmdate('G', $at), $peak['hours'], true);
    }
}
