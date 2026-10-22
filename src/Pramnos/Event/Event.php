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
 *
 * A {@see QueuedListener} is not called by `fire()`: its transport carries the event to it —
 * the queue by default, a task a worker runs ({@see QueuedEvents}, {@see Transport\Transports}).
 *
 * An event can also be an object — {@see dispatch()}: `Event::dispatch(new JobPostCreated($post))`
 * fires `JobPostCreated::EVENT` with the object as its one argument.
 */
class Event
{
    /** @var array<string, array<int, list<callable|class-string<ListenerInterface>>>> */
    private static array $listeners = [];

    /** @var array{userid: int|null}|null Who {@see fireAs()} fires for, while it runs */
    private static ?array $actor = null;

    /**
     * Register a listener for the given event.
     *
     * @param string                                         $event    Event name.
     * @param callable|class-string<ListenerInterface>|ListenerInterface $listener
     *   A callable, a ListenerInterface instance, or a class name that
     *   implements ListenerInterface (instantiated on first fire).
     * @param int $priority Lower = earlier execution (default 10).
     *
     * **Registering the same listener twice is a no-op**, for the same event and priority.
     * An application registers its listeners in `init()`, and an application can start more
     * than once in one process — a test suite starts it for every test — so appending made
     * each start one more copy: 3,000 tests in, one project's listener ran 3,000 times per
     * event. "The same" is: the identical callable (a function name, `[Class, 'method']`, the
     * same object); a closure written at the same place in the code, in the same class scope,
     * having captured the same values — which is what `init()` produces on every start;
     * or an equal instance of the same `ListenerInterface` class.
     */
    public static function listen(
        string $event,
        callable|string|ListenerInterface $listener,
        int $priority = 10
    ): void {
        foreach (self::$listeners[$event][$priority] ?? [] as $registered) {
            if (self::sameListener($registered, $listener)) {
                return;
            }
        }

        self::$listeners[$event][$priority][] = $listener;
    }

    /**
     * Whether two listeners would do the same thing.
     *
     * A closure's own object differs on every evaluation of the code that creates it, so
     * closures are compared by where they were written, the class they were written in and
     * what they captured. The bound `$this` is deliberately not compared: a fresh application
     * object on every start is exactly the duplicate this exists to catch. Two closures on
     * the same line capturing nothing are taken as one, which a reader would too.
     */
    private static function sameListener(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if ($a instanceof \Closure && $b instanceof \Closure) {
            $first  = new \ReflectionFunction($a);
            $second = new \ReflectionFunction($b);

            return $first->getFileName() === $second->getFileName()
                && $first->getStartLine() === $second->getStartLine()
                && $first->getEndLine() === $second->getEndLine()
                && $first->getClosureScopeClass()?->getName() === $second->getClosureScopeClass()?->getName()
                && $first->getClosureUsedVariables() == $second->getClosureUsedVariables();
        }

        return is_object($a) && is_object($b) && !$a instanceof \Closure
            && $a::class === $b::class && $a == $b;
    }

    /**
     * Fire an event, calling all registered listeners in priority order.
     *
     * Returns an array of each listener's return value. If a listener returns
     * false, propagation is stopped immediately and subsequent listeners are
     * skipped.
     *
     * A {@see QueuedListener} is queued instead of called, and its place in the results is the
     * task's id — null where nothing was queued. It never stops the listeners after it: it has
     * not run yet.
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

        $results  = [];
        $envelope = null;

        foreach ($priorities as $bucket) {
            foreach ($bucket as $listener) {
                if ($listener instanceof QueuedListener
                    || (is_string($listener) && is_a($listener, QueuedListener::class, true))
                ) {
                    // One firing, one id, whichever of its listeners are queued — and by whichever
                    // transport each is set to use.
                    $envelope ??= new Transport\Envelope($event, $args, QueuedEvents::meta($event, self::$actor));
                    $results[]  = Transport\Transports::for($listener)->deliver($envelope, $listener);
                    continue;
                }

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
     * Fire an event that is an object: its class's `EVENT` constant is the name, and the object is
     * the one argument.
     *
     * ```php
     * Event::dispatch(new JobPostCreated($post));   // = Event::fire('jobpost.created', $event)
     * ```
     *
     * A listener in the request receives **this instance** — the caller's models, not copies — so
     * what it changes on them is what the caller saves next. A {@see QueuedListener} receives it
     * built again in the worker, which the event allows by being a {@see QueueableEvent}. A class
     * without `EVENT` is fired under its class name. An event that is also a
     * {@see \Pramnos\Audience\PersonEvent} is recorded for its person as well.
     *
     * @return list<mixed> As {@see fire()}
     */
    public static function dispatch(object $event): array
    {
        $name    = defined($event::class . '::EVENT') ? (string) constant($event::class . '::EVENT') : $event::class;
        $results = static::fire($name, $event);
        // Something a person did is also recorded for them, in the queue, with no call of its own.
        if ($event instanceof \Pramnos\Audience\PersonEvent) {
            \Pramnos\Audience\PersonEvents::dispatched($name, $event);
        }

        return $results;
    }

    /**
     * Fire an event for an account, or for nobody — the system.
     *
     * ```php
     * Event::fireAs($userId, 'subscription.expired', $userId);   // a cron, for the user
     * Event::fireAs(null, 'payments.reconciled', $batchId);       // the system itself
     * ```
     *
     * The same as {@see fire()}, except for who a queued listener's task says fired it: a cron,
     * a webhook or a worker has no session to ask.
     *
     * @return list<mixed>
     */
    public static function fireAs(?int $userid, string $event, mixed ...$args): array
    {
        $previous    = self::$actor;
        self::$actor = ['userid' => $userid];
        try {
            return static::fire($event, ...$args);
        } finally {
            self::$actor = $previous;
        }
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
     * The events something listens for, by name.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return array_keys(array_filter(self::$listeners));
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

        // A class with a handle() of its own types — handle(JobPostCreated $event) — cannot
        // implement ListenerInterface, whose handle() takes anything; it is registered by name.
        if (is_string($listener) && (is_a($listener, ListenerInterface::class, true)
            || (class_exists($listener) && method_exists($listener, 'handle')))
        ) {
            return (new $listener())->handle(...$args);
        }

        return ($listener)(...$args);
    }
}
