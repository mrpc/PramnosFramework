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
     * The id of the backplane event currently being forwarded, if any.
     *
     * Set by {@see stream()} around each callback so {@see event()} can attach
     * it without the application having to pass it along.
     */
    private ?string $currentEventId = null;

    /**
     * Where this client wants to be resumed from, according to the request.
     *
     * Two sources, in order:
     *
     *  - **`Last-Event-ID`** — sent by `EventSource` automatically on every
     *    reconnect, carrying the last `id:` frame it saw. Nothing on the client
     *    side has to be written for this; it is the SSE spec's own answer to the
     *    gap a reconnect opens.
     *  - **`?since=`** — for clients that manage their own cursor (a native app,
     *    a polyfill, a first connection that already has data on screen).
     *
     * Returns an empty string when the client said nothing, which means "start
     * live" — a first connection has nothing to catch up on.
     */
    public static function resumePoint(): string
    {
        $header = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $since = $_GET['since'] ?? '';
        return is_string($since) ? $since : '';
    }

    /**
     * Emit a named event with a data payload.
     *
     * A string payload is written verbatim; anything else is JSON-encoded.
     * Multi-line payloads are split into multiple `data:` lines per the SSE spec.
     *
     * @param string      $event Event name the client listens for.
     * @param mixed       $data  Payload.
     * @param string|null $id    The backplane's id for this event. Written as an
     *                           `id:` frame, which is how the client comes to
     *                           know where it got to: the browser remembers the
     *                           last one it saw and sends it back as
     *                           `Last-Event-ID` when it reconnects. Without it
     *                           there is nothing to resume from, and everything
     *                           published during the reconnect is lost.
     */
    public function event(string $event, mixed $data, ?string $id = null): void
    {
        $id ??= $this->currentEventId;
        if ($id !== null && $id !== '') {
            $this->id($id);
        }
        echo 'event: ' . $event . "\n";
        $this->writeData($data);
        $this->flush();
    }

    /**
     * Emit an `id:` frame.
     *
     * Rarely called directly — {@see event()} and {@see stream()} write it — but
     * available for an endpoint that formats its own frames.
     *
     * Newlines are stripped rather than escaped: a newline inside an id would
     * terminate the field and turn the rest of it into a frame of its own, which
     * is a malformed stream rather than a wrong id.
     */
    public function id(string $id): void
    {
        echo 'id: ' . str_replace(["\r", "\n"], '', $id) . "\n";
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
     *                                                   The stream ends **at** this many seconds:
     *                                                   drivers clamp their last blocking read to
     *                                                   the remaining time. Until 2026-08-14 they
     *                                                   did not, so the close landed anywhere in
     *                                                   `[maxRuntime, maxRuntime + pingInterval]`
     *                                                   — at the bottom on a busy channel, which
     *                                                   is where a client using the same period
     *                                                   for its own reconnect lost the race. A
     *                                                   client should still allow itself a margin,
     *                                                   and does not have to guess it: the stream
     *                                                   opens with a `stream-info` event carrying
     *                                                   `handover_after`.
     * @param int                         $pingInterval  Idle seconds between keep-alive pings.
     * @param callable|null               $onTick        fn(SseWriter $sse): bool|void — invoked on every idle
     *                                                   tick (roughly each $pingInterval) before the keep-alive
     *                                                   ping, for periodic server-side side effects (e.g.
     *                                                   refreshing presence for the still-connected client).
     *                                                   Return false to end the stream. Optional; omit for the
     *                                                   historical ping-only idle behaviour.
     * @param string|null                 $sinceId       Resume after this event id instead of from "now".
     *                                                   Null (the default) reads the client's own resume
     *                                                   point from the request — see {@see resumePoint()} —
     *                                                   so an ordinary endpoint needs no code for it at all.
     *                                                   Pass a string to override, or an empty string to
     *                                                   opt out and start live.
     */
    public function stream(
        SubscribableDriverInterface $driver,
        array $channels,
        callable $onEvent,
        int $maxRuntime = 0,
        int $pingInterval = 20,
        ?callable $onTick = null,
        ?string $sinceId = null,
    ): void {
        $resumeFrom = $sinceId ?? self::resumePoint();

        $options = new SubscriptionOptions(
            readTimeout: max(1, $pingInterval),
            maxRuntime: $maxRuntime > 0 ? $maxRuntime : null,
            onIdle: function () use ($onTick): bool {
                // connection_aborted() is always 0
                // @codeCoverageIgnoreStart
                // under CLI, so the branch that notices a client walking away
                // cannot be reached from a test. It is the reason a closed tab
                // does not leave a PHP process polling a database for another
                // ninety seconds.
                if (connection_aborted()) {
                    return false;
                }
                // @codeCoverageIgnoreEnd
                if ($onTick !== null && $onTick($this) === false) {
                    return false;
                }
                $this->ping();
                return true;
            },
            sinceId: ($resumeFrom === '' ? null : $resumeFrom),
        );

        // The id of the event being delivered, so that an application callback
        // which simply calls $sse->event(...) still produces an `id:` frame
        // without having to thread the id through itself. Almost every callback
        // is that shape, and one that wants the id can take a fifth argument.
        $this->currentEventId = null;

        // Tell the client when to hand over, before anything else happens.
        //
        // A client that overlaps its reconnect needs a period slightly under the server's
        // ceiling, and until this frame existed it had to hard-code one — a constant it cannot
        // see, kept in sync by hand, and wrong the moment the server's changes. `handover_after`
        // is that number, already reduced by a margin, so the client can use it directly.
        //
        // Sent as its own event so it reaches only listeners that asked: EventSource dispatches
        // by name, so a client that has never heard of `stream-info` is unaffected.
        if ($maxRuntime > 0) {
            $this->event('stream-info', [
                'max_runtime'     => $maxRuntime,
                'ping_interval'   => $pingInterval,
                'handover_after'  => self::handoverAfter($maxRuntime),
            ]);
        }

        $driver->subscribe(
            $channels,
            function (string $channel, string $event, array $payload, ?string $id = null) use ($onEvent): bool {
                // see above: unreachable under CLI.
                // @codeCoverageIgnoreStart
                if (connection_aborted()) {
                    return false;
                }
                // @codeCoverageIgnoreEnd
                $this->currentEventId = $id;
                try {
                    return $onEvent($channel, $event, $payload, $this, $id) !== false;
                } finally {
                    $this->currentEventId = null;
                }
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
     * When a client should start its replacement stream.
     *
     * Comfortably before the server closes, because the client's clock starts at `open` —
     * strictly after the server started its own — so equal periods mean the server always
     * leads by however long the connection took to establish.
     *
     * A tenth of the runtime, bounded to 2–10 seconds: enough for a new request to reach the
     * server and prove itself on a slow connection, small enough that two open streams overlap
     * only briefly. On a very short runtime the margin cannot exceed half of it, or the advice
     * would be to reconnect immediately.
     *
     * @param int $maxRuntime The stream's ceiling, in seconds
     * @return int Seconds after `open` at which the client should begin its handover
     */
    private static function handoverAfter(int $maxRuntime): int
    {
        $margin = min(10, max(2, (int) ceil($maxRuntime / 10)));
        $margin = min($margin, (int) floor($maxRuntime / 2));

        return max(1, $maxRuntime - $margin);
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
