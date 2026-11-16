<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;

/**
 * One queued `listen`/`speak` call's row in `pramnos.llm_speech_runs`, from the moment
 * {@see Listening::hear()}/{@see Speech::speak()} ask for it (a self-hosted provider, with
 * queueing turned on) until a worker of the `speech` pool finishes it ({@see SpeechQueueTask}).
 *
 * Mirrors {@see \Pramnos\Llm\Sandbox\Exec} (FW-508) deliberately: the same reasoning applies —
 * a self-hosted whisper.cpp/faster-whisper/Piper server absorbs its own CPU cost, but nothing
 * protected *this* process's request budget while it waited on one. Its task type is never in
 * {@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS}, and claims nothing unless the application
 * registers it explicitly: `'queue' => ['handlers' => ['speech' => SpeechQueueTask::class]]]`.
 */
final class SpeechQueue
{
    public const TABLE = 'pramnos.llm_speech_runs';

    /** The queue task type. */
    public const TASK = 'speech';

    /** Whether this installation can queue one: its table exists. */
    public static function available(): bool
    {
        try {
            return Factory::getDatabase()->schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** How many calls, across every person, are waiting for a worker or being run right now. */
    public static function pending(): int
    {
        return (int) self::rows()->whereIn('status', ['pending', 'running'])->count();
    }

    /**
     * A new row, pending a worker.
     *
     * @param array<string, mixed> $meta
     */
    public static function create(int $userId, string $kind, string $payload, array $meta): int
    {
        self::rows()->insert([
            'kind'       => $kind,
            'user_id'    => $userId,
            'payload'    => $payload,
            'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'status'     => 'pending',
            'created_at' => \Pramnos\Database\Instant::utc(time()),
        ]);

        return (int) Factory::getDatabase()->getInsertId();
    }

    /**
     * One row, for the person it was made for — null for somebody else's, or one gone already.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $runId, int $userId): ?array
    {
        $row = self::rows()->where('run_id', $runId)->where('user_id', $userId)->first();

        return $row && (int) $row->numRows > 0 ? (array) $row->fields : null;
    }

    /** Take a pending call for `$owner` — the same claim shape as {@see \Pramnos\Llm\Sandbox\Exec::claim()}. */
    public static function claim(int $runId, string $owner = 'worker'): bool
    {
        self::rows()->where('run_id', $runId)->where('status', 'pending')->update(['status' => 'running', 'owner' => $owner]);
        $row = self::rows()->select(['status', 'owner'])->where('run_id', $runId)->first();

        return $row && (int) $row->numRows > 0 && (string) $row->fields['status'] === 'running' && (string) $row->fields['owner'] === $owner;
    }

    /** The run finished: its own JSON-encoded answer kept, nothing to say went wrong. */
    public static function finish(int $runId, string $result): void
    {
        self::rows()->where('run_id', $runId)->update([
            'status' => 'done', 'result' => $result, 'finished_at' => \Pramnos\Database\Instant::utc(time()),
        ]);
    }

    /** The run failed: why, for the caller to read back. */
    public static function fail(int $runId, string $why): void
    {
        self::rows()->where('run_id', $runId)->update([
            'status' => 'error', 'error' => $why, 'finished_at' => \Pramnos\Database\Instant::utc(time()),
        ]);
    }

    /** Forget a finished row: kept only while its call is answered. */
    public static function forget(int $runId): void
    {
        try {
            self::rows()->where('run_id', $runId)->delete();
        } catch (\Throwable) {
            // Already gone, or the table is not there: nothing left to clean up either way.
        }
    }

    private static function rows(): QueryBuilder
    {
        return Factory::getDatabase()->queryBuilder()->table(self::TABLE);
    }
}
