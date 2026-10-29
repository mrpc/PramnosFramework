<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * A change made once per answer: a tool marked `'writes' => true`, called again with the same
 * arguments in the same answer, is not run again — the model is told it was done, and what it
 * returned then.
 *
 * The key is the answer, the tool and its arguments (sorted, so `{a, b}` and `{b, a}` are the same
 * call). An answer is a random token unless the caller names it — `Client::answering()`, which a
 * queued question does with its ask, so a question answered again after a crash does not repeat
 * what it already changed. It is taken by an insert into `pramnos.llm_tool_idempotency` before the
 * tool runs, so two processes racing for the same change cannot both make it. A run that fails
 * gives the key back, so the change can be tried again. Keys are kept a day.
 *
 * Without the table (its migration not run) every call runs, as before.
 */
final class Idempotency
{
    /** The table. */
    public const TABLE = 'pramnos.llm_tool_idempotency';

    /** Seconds a key is kept. */
    public const KEEP = 86400;

    /** Told for a change already made: `%s` is what it returned the first time. */
    public const DONE = 'Already done earlier in this answer, so not done again. What it returned then: %s';

    /** Told for a change being made right now by another run of the same answer. */
    public const BUSY = 'Not done: the same change is being made right now by another run of this answer.';

    /** @var array<string, bool> Whether the table exists, per database */
    private static array $available = [];

    /**
     * The key of one call in one answer.
     *
     * @param array<string, mixed> $arguments
     */
    public static function key(string $answer, string $tool, array $arguments): string
    {
        return hash('sha256', $answer . "\0" . $tool . "\0" . json_encode(self::sorted($arguments), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Run a change once for its key; a key already taken answers what the first run returned.
     * A result that is an error gives the key back.
     *
     * @param callable(): string $run
     */
    public static function once(string $key, string $tool, callable $run): string
    {
        if (!self::available()) {
            return $run();
        }

        $now = time();
        $db  = \Pramnos\Framework\Factory::getDatabase();
        // A key past its day may be taken again; and none of them is kept longer.
        $db->queryBuilder()->table(self::TABLE)->where('expires_at', '<=', CallLog::instant($now))->delete();

        try {
            $db->queryBuilder()->table(self::TABLE)->insert([
                'idempotency_key' => $key,
                'tool'            => mb_substr($tool, 0, 64),
                'created_at'      => CallLog::instant($now),
                'expires_at'      => CallLog::instant($now + self::KEEP),
            ]);
        } catch (\Throwable) {
            $row = $db->queryBuilder()->table(self::TABLE)->select(['result'])->where('idempotency_key', $key)->first();
            $result = $row && (int) $row->numRows > 0 ? $row->fields['result'] : null;

            return $result === null ? self::BUSY : sprintf(self::DONE, (string) $result);
        }

        $result = $run();

        if (str_starts_with($result, 'Error:')) {
            $db->queryBuilder()->table(self::TABLE)->where('idempotency_key', $key)->delete();
        } else {
            $db->queryBuilder()->table(self::TABLE)->where('idempotency_key', $key)->update(['result' => $result]);
        }

        return $result;
    }

    /** Forget whether the table exists — for tests that make or drop it. */
    public static function reset(): void
    {
        self::$available = [];
    }

    private static function available(): bool
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $key = spl_object_id($db) . ':' . $db->type;

        try {
            return self::$available[$key] ??= $db->schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Arguments with every object's keys in order, so the same call is the same key.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn ($v) => is_array($v) ? self::sorted($v) : $v, $value);
    }
}
