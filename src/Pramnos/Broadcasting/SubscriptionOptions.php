<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Options controlling a backplane subscription loop.
 *
 * A subscribable driver (Redis, Database, …) blocks while it waits for events.
 * These options tell it how often to surface an "idle" tick to the caller (so a
 * long-lived consumer such as an SSE stream can send keep-alive pings and check
 * whether the client is still connected), and when to stop the loop entirely.
 *
 * The design deliberately keeps the driver transport-agnostic: it knows nothing
 * about SSE, WebSockets or connection_aborted(). The caller expresses "should I
 * keep going?" through {@see $onIdle} (return false to stop) and an absolute
 * {@see $maxRuntime} ceiling.
 */
final class SubscriptionOptions
{
    /**
     * @param int           $readTimeout Seconds the driver waits for a message
     *                                   before surfacing an idle tick. Also the
     *                                   granularity at which onIdle / maxRuntime
     *                                   are evaluated. Must be >= 1.
     * @param int|null      $maxRuntime  Hard ceiling in seconds; the loop stops
     *                                   once it has run this long. Null = no cap
     *                                   (run until onIdle/onEvent returns false).
     * @param callable|null $onIdle      Invoked on every idle tick (no message
     *                                   arrived within readTimeout). Return false
     *                                   to stop the loop. Signature: fn(): bool.
     * @param callable|null $onError     Invoked with a \Throwable when a transient
     *                                   backplane error is swallowed and the driver
     *                                   is about to reconnect. Signature:
     *                                   fn(\Throwable $e): void. Purely for logging.
     * @param string|null   $sinceId     Resume *after* this event id instead of
     *                                   from "now". Null = only events published
     *                                   from this moment on, which is the
     *                                   historical behaviour.
     *
     *                                   This is what closes the reconnect gap.
     *                                   `maxRuntime` ends every SSE stream on
     *                                   purpose, so each client reconnects on a
     *                                   schedule — and a driver that always
     *                                   starts at "now" delivers nothing that
     *                                   was published while it was away. A
     *                                   driver that cannot replay ignores this
     *                                   and says so in its documentation; one
     *                                   that can (database, Redis streams)
     *                                   resumes from it.
     *
     *                                   A string because ids are the backplane's
     *                                   own: an integer row id in a table, a
     *                                   `1699…-0` entry id in a Redis stream.
     */
    public function __construct(
        public readonly int $readTimeout = 20,
        public readonly ?int $maxRuntime = null,
        public readonly mixed $onIdle = null,
        public readonly mixed $onError = null,
        public readonly ?string $sinceId = null,
    ) {
        if ($readTimeout < 1) {
            throw new \InvalidArgumentException('readTimeout must be at least 1 second.');
        }
        if ($maxRuntime !== null && $maxRuntime < 1) {
            throw new \InvalidArgumentException('maxRuntime, when set, must be at least 1 second.');
        }
        if ($onIdle !== null && !is_callable($onIdle)) {
            throw new \InvalidArgumentException('onIdle must be callable or null.');
        }
        if ($onError !== null && !is_callable($onError)) {
            throw new \InvalidArgumentException('onError must be callable or null.');
        }
    }

    /**
     * Fire the idle callback. Returns true when the loop should continue.
     */
    /**
     * How long the next blocking read may wait, given the run's deadline.
     *
     * A driver's loop checks the deadline at the top and then blocks for `readTimeout`
     * seconds, so a deadline that falls *during* a read is not noticed until that read
     * returns. The stream therefore ended somewhere in `[maxRuntime, maxRuntime + readTimeout]`
     * — at the bottom of that window on a busy channel, where an event arrives just after the
     * deadline, and at the top on a quiet one.
     *
     * That range was the whole bug. A client doing an overlapping reconnect has only
     * `maxRuntime` to go on, its own clock starts at `open` — strictly after the server's — and
     * so at equal periods the server leads. It wins **exactly on the busy installs**, where the
     * close lands at the bottom of the range, and the symptom is an occasional transport error
     * that gets worse under load.
     *
     * Clamping the last read to the remaining time makes the close happen at `maxRuntime`
     * regardless of traffic, which is what the parameter always read like.
     *
     * @param int|null $deadline Unix time the run must end by, or null for no ceiling
     * @return int Seconds to block, at least 1 and never past the deadline
     */
    public function blockingWindow(?int $deadline): int
    {
        if ($deadline === null) {
            return $this->readTimeout;
        }

        // At least 1: a driver only reaches a read when the deadline is still ahead, and a
        // zero-second block means "wait forever" to most clients — the opposite of the intent.
        return max(1, min($this->readTimeout, $deadline - time()));
    }

    public function fireIdle(): bool
    {
        if ($this->onIdle === null) {
            return true;
        }
        return ($this->onIdle)() !== false;
    }

    /**
     * Report a swallowed transient error to the caller (if a handler was given).
     */
    public function reportError(\Throwable $e): void
    {
        if ($this->onError !== null) {
            ($this->onError)($e);
        }
    }
}
