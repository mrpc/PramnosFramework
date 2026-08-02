<?php

declare(strict_types=1);

namespace Pramnos\Http\Sse;

use Pramnos\Broadcasting\Drivers\SubscribableDriverInterface;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * Writes a Server-Sent Events stream and, optionally, pumps a broadcasting
 * backplane into it.
 *
 * The low-level methods ({@see event()}, {@see data()}, {@see comment()},
 * {@see ping()}, {@see retry()}) format and flush individual SSE frames. The
 * high-level {@see stream()} runs the long-lived consume loop that real SSE
 * endpoints need: it subscribes to a backplane, forwards each event to an
 * application callback, sends keep-alive pings while idle, stops when the client
 * disconnects, and — when a max runtime is set (e.g. to stay under a Cloudflare
 * 100s edge timeout) — tells the client to reconnect before the edge cuts it off.
 *
 * Obtain one from {@see \Pramnos\Http\StreamedResponse::sse()}.
 */
class SseWriter
{
    /**
     * Emit a named event with a data payload.
     *
     * A string payload is written verbatim; anything else is JSON-encoded.
     * Multi-line payloads are split into multiple `data:` lines per the SSE spec.
     */
    public function event(string $event, mixed $data): void
    {
        echo 'event: ' . $event . "\n";
        $this->writeData($data);
        $this->flush();
    }

    /**
     * Emit an unnamed message (default "message" event on the client).
     */
    public function data(mixed $data): void
    {
        $this->writeData($data);
        $this->flush();
    }

    /**
     * Emit a comment line (ignored by EventSource; used to keep the connection
     * warm and to establish it before the first event).
     */
    public function comment(string $text): void
    {
        echo ': ' . $text . "\n\n";
        $this->flush();
    }

    /**
     * Emit a keep-alive ping as a timestamped comment.
     */
    public function ping(): void
    {
        echo ': ping ' . time() . "\n\n";
        $this->flush();
    }

    /**
     * Tell the client how long to wait before reconnecting after a drop.
     */
    public function retry(int $milliseconds): void
    {
        echo 'retry: ' . $milliseconds . "\n\n";
        $this->flush();
    }

    /**
     * Consume a subscribable backplane and forward events to the client.
     *
     * @param SubscribableDriverInterface $driver       Backplane to subscribe to.
     * @param string[]                    $channels      Logical channels to consume.
     * @param callable                    $onEvent       fn(string $channel, string $event, array $payload, SseWriter $sse): bool|void
     *                                                   Emit via $sse; return false to stop.
     * @param int                         $maxRuntime    Seconds before asking the client to reconnect (0 = unlimited).
     * @param int                         $pingInterval  Idle seconds between keep-alive pings.
     * @param callable|null               $onTick        fn(SseWriter $sse): bool|void — invoked on every idle
     *                                                   tick (roughly each $pingInterval) before the keep-alive
     *                                                   ping, for periodic server-side side effects (e.g.
     *                                                   refreshing presence for the still-connected client).
     *                                                   Return false to end the stream. Optional; omit for the
     *                                                   historical ping-only idle behaviour.
     */
    public function stream(
        SubscribableDriverInterface $driver,
        array $channels,
        callable $onEvent,
        int $maxRuntime = 0,
        int $pingInterval = 20,
        ?callable $onTick = null,
    ): void {
        $options = new SubscriptionOptions(
            readTimeout: max(1, $pingInterval),
            maxRuntime: $maxRuntime > 0 ? $maxRuntime : null,
            onIdle: function () use ($onTick): bool {
                if (connection_aborted()) {
                    return false;
                }
                if ($onTick !== null && $onTick($this) === false) {
                    return false;
                }
                $this->ping();
                return true;
            },
        );

        $driver->subscribe(
            $channels,
            function (string $channel, string $event, array $payload) use ($onEvent): bool {
                if (connection_aborted()) {
                    return false;
                }
                return $onEvent($channel, $event, $payload, $this) !== false;
            },
            $options,
        );

        // Reached the runtime ceiling (not a client disconnect): prompt a reconnect
        // so the browser re-establishes before the edge/proxy kills the socket.
        if ($maxRuntime > 0 && !connection_aborted()) {
            $this->event('reconnect', ['reason' => 'max_runtime']);
        }
    }

    /**
     * Write a data payload as one or more `data:` lines followed by the blank
     * line that terminates the event.
     */
    private function writeData(mixed $data): void
    {
        $string = is_string($data)
            ? $data
            : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (explode("\n", $string) as $line) {
            echo 'data: ' . $line . "\n";
        }
        echo "\n";
    }

    private function flush(): void
    {
        // Output buffering is torn down by StreamedResponse::send() before the
        // producer runs, so a plain flush() pushes each frame to the client. We
        // deliberately do NOT ob_flush() here — that would drain any surrounding
        // buffer (e.g. a test's output capture) instead of the SAPI write buffer.
        @flush();
    }
}
