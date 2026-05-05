<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * Lightweight event bus — fire/listen with priority ordering.
 *
 * Runs parallel to the existing Addon hook system. Existing
 * Addon::doAction() / Addon::addAction() calls are unaffected.
 *
 * Usage:
 *   Event::listen('user.registered', fn(array $user) => ...);
 *   Event::fire('user.registered', $userData);
 *
 * A listener returning false stops propagation to subsequent listeners.
 */
class Event
{
    /** @var array<string, array<int, list<callable|class-string<ListenerInterface>>>> */
    private static array $listeners = [];

    /**
     * Register a listener for the given event.
     *
     * @param string                                         $event    Event name.
     * @param callable|class-string<ListenerInterface>|ListenerInterface $listener
     *   A callable, a ListenerInterface instance, or a class name that
     *   implements ListenerInterface (instantiated on first fire).
     * @param int $priority Lower = earlier execution (default 10).
     */
    public static function listen(
        string $event,
        callable|string|ListenerInterface $listener,
        int $priority = 10
    ): void {
        self::$listeners[$event][$priority][] = $listener;
    }

    /**
     * Fire an event, calling all registered listeners in priority order.
     *
     * Returns an array of each listener's return value. If a listener returns
     * false, propagation is stopped immediately and subsequent listeners are
     * skipped.
     *
     * @return list<mixed>  Return values from each listener that was called.
     */
    public static function fire(string $event, mixed ...$args): array
    {
        if (!isset(self::$listeners[$event])) {
            return [];
        }

        $priorities = self::$listeners[$event];
        ksort($priorities);

        $results = [];

        foreach ($priorities as $bucket) {
            foreach ($bucket as $listener) {
                $result = self::callListener($listener, $args);
                $results[] = $result;

                if ($result === false) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Remove all listeners for an event (or all events).
     */
    public static function forget(string $event = ''): void
    {
        if ($event === '') {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$event]);
        }
    }

    /**
     * Returns true if at least one listener is registered for the event.
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * Returns all registered listeners for an event, sorted by priority.
     *
     * @return list<callable|string|ListenerInterface>
     */
    public static function getListeners(string $event): array
    {
        if (!isset(self::$listeners[$event])) {
            return [];
        }

        $priorities = self::$listeners[$event];
        ksort($priorities);

        $flat = [];
        foreach ($priorities as $bucket) {
            foreach ($bucket as $listener) {
                $flat[] = $listener;
            }
        }

        return $flat;
    }

    /**
     * Invoke a single listener, handling class-name strings and ListenerInterface.
     *
     * @param callable|string|ListenerInterface $listener
     * @param list<mixed>                        $args
     */
    private static function callListener(
        callable|string|ListenerInterface $listener,
        array $args
    ): mixed {
        if ($listener instanceof ListenerInterface) {
            return $listener->handle(...$args);
        }

        if (is_string($listener) && is_a($listener, ListenerInterface::class, true)) {
            return (new $listener())->handle(...$args);
        }

        return ($listener)(...$args);
    }
}
