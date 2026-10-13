<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Application\Settings;

/**
 * What was asked of a model, what came back, and what it cost — `llm_calls`.
 *
 * ## Writing a log entry must never break the thing being logged
 *
 * Every write is inside a `try`. A full disk, a migration that has not run yet — none of those
 * is a reason for an answer to stop. A write that fails is logged rather than swallowed: a call
 * log that silently stops recording reads as "nothing happened".
 *
 * ## The cost is frozen at write time
 *
 * Prices are editable, so costing at read time would restate history every time somebody
 * corrected one. What a call cost is what it cost when it was made.
 *
 * ## Every call, including the ones that failed
 *
 * Especially those. A revoked key, a model that no longer exists, a budget spent on reasoning —
 * each is a row with a sentence in it, not a total that stopped rising.
 */
class CallLog
{
    /** The table. */
    public const TABLE = '#PREFIX#llm_calls';

    /** Days kept before pruning: long enough to answer "what changed last month". */
    public const KEEP_DAYS = 90;

    public function __construct(private readonly ?Pricing $pricing = null)
    {
    }

    /**
     * Whether calls are recorded. On by default: a feature that spends money silently is one
     * nobody can audit.
     */
    public function isEnabled(): bool
    {
        return (string) Settings::getSetting(Providers::LOG_SETTING, '1') !== '0';
    }

    /**
     * Record one call.
     *
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
            $model = (string) ($entry['model'] ?? '');

            $this->table()->insert([
                'organization_id' => $entry['organization_id'] ?? null,
                'userid'          => $entry['userid'] ?? null,
                'purpose'         => mb_substr((string) ($entry['purpose'] ?? 'assistant'), 0, 32),
                'provider'        => mb_substr((string) ($entry['provider'] ?? ''), 0, 32),
                'model'           => mb_substr($model, 0, 64),
                'endpoint'        => $entry['endpoint'] ?? null,
                'system_prompt'   => $entry['system_prompt'] ?? null,
                'messages'        => self::encode($entry['messages'] ?? null),
                'max_tokens'      => $entry['max_tokens'] ?? null,
                'temperature'     => $entry['temperature'] ?? null,
                'reasoning'       => (bool) ($entry['reasoning'] ?? false),
                'http_status'     => $entry['http_status'] ?? null,
                'finish_reason'   => isset($entry['finish_reason']) ? mb_substr((string) $entry['finish_reason'], 0, 32) : null,
                'reply'           => $entry['reply'] ?? null,
                'usage'           => self::encode($usage === [] ? null : $usage),
                'tokens'          => $usage === [] ? null : Pricing::tokensIn($usage),
                'duration_ms'     => $entry['duration_ms'] ?? null,
                // A good call has no `error` key at all, which is not one whose error is empty.
                'error'           => trim((string) ($entry['error'] ?? '')) === '' ? null : mb_substr((string) $entry['error'], 0, 500),
                'cost'            => ($this->pricing ?? new Pricing())->cost($model, $usage),
                'currency'        => Pricing::CURRENCY,
                'created_at'      => self::instant(time()),
            ]);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm call log: ' . $why->getMessage());
        }
    }

    /**
     * The most recent calls, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 25, bool $problemsOnly = false): array
    {
        try {
            $query = $this->table()->orderBy('call_id', 'desc');

            if ($problemsOnly) {
                // A call that failed, or that never returned at all.
                $query->where(static function ($q): void {
                    $q->whereNotNull('error')->orWhereNull('http_status')->orWhere('http_status', '<>', 200);
                });
            }

            $result = $query->limit(max(1, min(200, $limit)))->get();
            $rows   = [];

            while (($row = $result->fetch()) !== null) {
                $row['usage'] = self::decode($row['usage'] ?? null);
                $rows[]       = $row;
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What was spent and how it went, over a window.
     *
     * `unpriced` is the number of calls the total does not include. Without it a screen showing
     * "$0.41" would report a figure it knows is incomplete.
     *
     * @return array{calls: int, failed: int, tokens: int, cost: float, unpriced: int, ms: int}
     */
    public function summary(int $hours = 24): array
    {
        $sum = ['calls' => 0, 'failed' => 0, 'tokens' => 0, 'cost' => 0.0, 'unpriced' => 0, 'ms' => 0];
        $ms  = 0;

        foreach ($this->window($hours) as $row) {
            $sum['calls']++;
            $sum['failed']   += self::failed($row) ? 1 : 0;
            $sum['tokens']   += (int) ($row['tokens'] ?? 0);
            $sum['cost']     += (float) ($row['cost'] ?? 0);
            $sum['unpriced'] += $row['cost'] === null ? 1 : 0;
            $ms              += (int) ($row['duration_ms'] ?? 0);
        }

        $sum['ms'] = $sum['calls'] > 0 ? (int) round($ms / $sum['calls']) : 0;

        return $sum;
    }

    /**
     * The same window, by model: "what did it cost" is not one number once two are in use, and
     * the split is usually the answer to "why has this gone up".
     *
     * @return list<array{model: string, calls: int, cost: float, unpriced: int}>
     */
    public function byModel(int $hours = 24): array
    {
        $models = [];

        foreach ($this->window($hours) as $row) {
            $model            = (string) ($row['model'] ?? '');
            $models[$model] ??= ['model' => $model, 'calls' => 0, 'cost' => 0.0, 'unpriced' => 0];
            $models[$model]['calls']++;
            $models[$model]['cost']     += (float) ($row['cost'] ?? 0);
            $models[$model]['unpriced'] += $row['cost'] === null ? 1 : 0;
        }

        usort($models, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return $models;
    }

    /**
     * What one provider's calls cost since a moment — to compare with what the provider billed.
     */
    public function spentOn(string $provider, int $since): float
    {
        try {
            $result = $this->table()->select(['cost'])
                ->where('provider', $provider)
                ->where('created_at', '>=', self::instant($since))
                ->get();
            $total = 0.0;

            while (($row = $result->fetch()) !== null) {
                $total += (float) ($row['cost'] ?? 0);
            }

            return round($total, 4);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * One call in full, for the "why did that cost that" question.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $callId): ?array
    {
        try {
            $result = $this->table()->where('call_id', $callId)->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$result || (int) $result->numRows === 0) {
            return null;
        }

        $row = $result->fields;

        $row['usage']    = self::decode($row['usage'] ?? null);
        $row['messages'] = self::decode($row['messages'] ?? null);

        return $row;
    }

    /**
     * Drop what is older than the window worth keeping.
     *
     * @return int The days kept
     */
    public function prune(?int $days = null): int
    {
        $keep = max(1, $days ?? self::KEEP_DAYS);

        $this->table()->where('created_at', '<', self::instant(time() - $keep * 86400))->delete();

        return $keep;
    }

    /**
     * A moment as `created_at` is written and compared: UTC, with its offset.
     *
     * Not `date()`: a time without an offset is read in the database session's zone, so with
     * PHP in Athens and the session in UTC every row would be stored hours off — and off from
     * rows written correctly before, which the windows and the pruning then compare against.
     * PostgreSQL's `timestamptz` and MySQL's `TIMESTAMP` (8.0.19 onwards) both take the offset.
     */
    public static function instant(int $at): string
    {
        return gmdate('Y-m-d H:i:sP', $at);
    }

    /** Whether a row is a call that failed or never returned. */
    public static function failed(array $row): bool
    {
        return ($row['error'] ?? null) !== null || ($row['http_status'] ?? null) === null || (int) $row['http_status'] !== 200;
    }

    /**
     * The rows of a window, for the summaries.
     *
     * ponytail: summed in PHP, which every database can do the same way; a window of tens of
     * thousands of calls wants an aggregate query instead.
     *
     * @return list<array<string, mixed>>
     */
    private function window(int $hours): array
    {
        try {
            $result = $this->table()
                ->select(['model', 'cost', 'error', 'http_status', 'duration_ms', 'tokens'])
                ->where('created_at', '>', self::instant(time() - max(1, $hours) * 3600))
                ->get();
            $rows = [];

            while (($row = $result->fetch()) !== null) {
                $rows[] = $row + ['cost' => null];
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(self::TABLE);
    }

    private static function encode(mixed $value): ?string
    {
        return $value === null ? null : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function decode(mixed $value): mixed
    {
        return is_string($value) && trim($value) !== '' ? json_decode($value, true) : null;
    }
}
