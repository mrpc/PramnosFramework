<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Webhooks;

use Pramnos\Queue\DelayedQueue;

/**
 * Hands webhook batches to a Redis queue for a worker to deliver.
 *
 * The shipped dispatcher, and the reason the interface exists at all: the daemon
 * cannot make the HTTP call itself. It is a single-threaded `stream_select()` loop,
 * so an outbound request inside it stalls every connected client until that request
 * returns — a slow webhook endpoint would present as a realtime outage, and an
 * unreachable one as a hang.
 *
 * A queue push is a Redis `LPUSH`. It is not free, but it is bounded by a local
 * socket rather than by somebody else's server, and it fails fast.
 *
 * ## The delivery half is the application's
 *
 * This puts a job on the queue; a worker consumes it and POSTs. That is deliberate:
 * retry policy, backoff, dead-lettering and endpoint configuration are all things a
 * deployment has opinions about, and none of them belong in a fan-out loop. The job
 * payload carries everything the worker needs — the target URL, the signed body and
 * the headers — so the worker does no signing and holds no secret.
 */
final class QueueWebhookDispatcher implements WebhookDispatcherInterface
{
    /** Queue job type a worker matches on. */
    public const JOB_TYPE = 'broadcasting.webhook';

    private ?DelayedQueue $queue = null;

    /**
     * @param string           $url       Endpoint the worker will POST to.
     * @param WebhookSigner    $signer    Signs the batch with the app's secret.
     * @param string           $namespace Queue namespace.
     * @param DelayedQueue|null $queue    Injectable for tests; resolved lazily
     *                                    otherwise, so constructing this does not
     *                                    require Redis to be reachable yet.
     */
    public function __construct(
        private readonly string $url,
        private readonly WebhookSigner $signer,
        private readonly string $namespace = 'broadcasting',
        ?DelayedQueue $queue = null,
    ) {
        $this->queue = $queue;
    }

    public function dispatch(array $events): void
    {
        if ($events === [] || $this->url === '') {
            return;
        }

        $body = $this->signer->body($events);

        try {
            $this->resolveQueue()->push(self::JOB_TYPE, [
                'url'     => $this->url,
                'body'    => $body,
                'headers' => $this->signer->headers($body),
            ]);
        } catch (\Throwable $e) {
            // A queue that cannot be reached must not take the realtime edge with
            // it. Losing a webhook degrades an application's bookkeeping; throwing
            // here would drop every connected client instead.
            \Pramnos\Logs\Logger::log(
                'Broadcasting: webhook batch could not be queued: ' . $e->getMessage(),
                'broadcasting'
            );
        }
    }

    private function resolveQueue(): DelayedQueue
    {
        return $this->queue ??= DelayedQueue::redis($this->namespace);
    }
}
