<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * One queued `listen`/`speak` call, run by a worker pointed at a self-hosted speech provider —
 * never claimed by an ordinary worker, since this type is deliberately not in
 * {@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS}:
 *
 * ```php
 * 'queue' => ['handlers' => ['speech' => \Pramnos\Llm\Speech\SpeechQueueTask::class]]
 * ```
 *
 * A call the caller's own wait gave up on first, or another worker already took, is nothing to
 * do — the same claim {@see SpeechQueue::claim()} makes atomic.
 */
class SpeechQueueTask extends AbstractTask
{
    public string $name = SpeechQueue::TASK;

    public function execute(QueueItem $queueItem): mixed
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);
        $runId   = (int) ($payload['run_id'] ?? 0);

        if ($runId <= 0 || !SpeechQueue::claim($runId, 'worker-' . getmypid())) {
            return true;
        }

        $row = SpeechQueue::find($runId, (int) ($payload['user_id'] ?? 0));
        if ($row === null) {
            return true;
        }

        $meta = (array) json_decode((string) ($row['meta'] ?? ''), true);

        $answer = (string) $row['kind'] === 'listen'
            ? Listening::hearNow((string) base64_decode((string) $row['payload'], true), (string) ($meta['type'] ?? 'audio/webm'),
                isset($meta['seconds']) ? (float) $meta['seconds'] : null, Listening::config(isset($meta['provider']) ? (string) $meta['provider'] : null))
            : Speech::speakNow((string) $row['payload'], Speech::config(isset($meta['provider']) ? (string) $meta['provider'] : null),
                isset($meta['voice']) ? (string) $meta['voice'] : null);

        SpeechQueue::finish($runId, (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return true;
    }

    public function getDescription(QueueItem $queueItem): string
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);

        return 'Run a queued speech call, call ' . (int) ($payload['run_id'] ?? 0);
    }
}
