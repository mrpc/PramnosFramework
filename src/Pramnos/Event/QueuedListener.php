<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * A listener that runs in the queue, not in the request that fired its event.
 *
 * ```php
 * final class SyncMoosend extends QueuedListener
 * {
 *     public function handle(mixed ...$args): mixed
 *     {
 *         [$userId] = $args;
 *         // … call the provider …
 *         return true;
 *     }
 *
 *     public function debounceKey(mixed ...$args): ?string
 *     {
 *         return 'moosend:' . $args[0];
 *     }
 * }
 *
 * Event::listen('user.saved', SyncMoosend::class);
 * Event::fire('user.saved', $userId);   // writes a task; a worker runs handle()
 * ```
 *
 * Registered and fired like any listener. `fire()` does not call it: it writes one task of type
 * {@see EventListenerTask::TYPE} with the event's name, its arguments and who fired it, and a
 * worker runs {@see handle()} with the same arguments. The arguments therefore travel as JSON —
 * ids and plain values, not objects.
 *
 * Every method below has a default that does nothing special; a listener overrides the ones it
 * needs. Each is asked with the event's arguments, so a key can be built from them.
 */
abstract class QueuedListener implements ListenerInterface
{
    /**
     * Events with the same key fold into one waiting task, which runs with the last event's
     * arguments. Null: every event is a task of its own.
     */
    public function debounceKey(mixed ...$args): ?string
    {
        return null;
    }

    /** Seconds a folded task waits after the last event before it runs. */
    public function debounceWindow(): int
    {
        return 60;
    }

    /**
     * Seconds after its first event a folded task runs at the latest, however many events keep
     * arriving — so a value that changes every few seconds is still synced.
     */
    public function debounceCeiling(): int
    {
        return 600;
    }

    /**
     * Work done once per key: an event whose key was used already queues nothing, and a task
     * whose key another took does not run. Null: no such limit.
     */
    public function idempotencyKey(mixed ...$args): ?string
    {
        return null;
    }

    /** Seconds a used key stays used. */
    public function idempotencyTtl(): int
    {
        return 30 * 86400;
    }

    /** The task's priority — lower is sooner. */
    public function priority(): int
    {
        return 10;
    }

    /** Attempts before the task fails for good. */
    public function maxAttempts(): int
    {
        return 3;
    }
}
