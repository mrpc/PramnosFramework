<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Broadcasting\Drivers\DriverInterface;
use Pramnos\Broadcasting\Drivers\ExcludesSocketInterface;
use Pramnos\Broadcasting\Drivers\NullDriver;

/**
 * Central broadcasting manager.
 *
 * Manages a set of named drivers and delegates broadcast() calls to the
 * currently selected driver.  Applications can swap drivers at runtime (e.g.
 * use NullDriver in tests and LogDriver in development).
 *
 * ## Usage
 *
 * ```php
 * $manager = new BroadcastingManager();
 * $manager->addDriver(new LogDriver());
 * $manager->setDefault('log');
 *
 * $manager->broadcast('room.42', 'message.created', ['body' => 'Hello!']);
 * ```
 *
 * ## Channel conventions
 *
 * | Prefix      | Meaning                                                  |
 * |-------------|----------------------------------------------------------|
 * | (none)      | Public channel — anyone can subscribe                   |
 * | `private-`  | Private channel — subscription requires auth             |
 * | `presence-` | Presence channel — member list exposed to subscribers   |
 *
 */
class BroadcastingManager
{
    /** @var array<string, DriverInterface> Registered drivers keyed by name. */
    private array $drivers = [];

    /** Name of the currently active driver. */
    private string $defaultDriver = 'null';

    /** The default manager, pre-wired with a Redis driver on the ConnectionManager. */
    private static ?self $instance = null;

    /** Connection excluded from the next broadcast, set by {@see except()}. */
    private ?string $exceptSocketId = null;

    /** Queue for QueuedBroadcastableEvents; resolved lazily when null. */
    private ?\Pramnos\Queue\DelayedQueue $queue = null;

    private string $queueNamespace = 'broadcasting';

    public function __construct()
    {
        // Always register the null driver so setDefault('null') is always valid.
        $this->drivers['null'] = new NullDriver();
    }

    /**
     * The default broadcasting manager: pre-wired with a {@see Drivers\RedisDriver}
     * on the shared {@see \Pramnos\Redis\ConnectionManager} (its per-install prefix
     * and pooled connection), with 'redis' as the active driver. Built lazily so an
     * app that configures the manager during bootstrap is already in effect. This
     * lets an app broadcast through the capability without wiring the driver itself.
     *
     * (Named instance()/setInstance() rather than default() to avoid colliding with
     * the existing {@see setDefault()} instance method, which selects the driver.)
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            $cm      = \Pramnos\Redis\ConnectionManager::getInstance();
            $manager = new self();
            $manager->addDriver(new Drivers\RedisDriver(
                ['prefix' => $cm->prefix()],
                static fn (): object => \Pramnos\Redis\ConnectionManager::getInstance()->connection()
            ));
            $manager->setDefault('redis');
            self::$instance = $manager;
        }
        return self::$instance;
    }

    /**
     * Override the default manager (bootstrap wiring / test-reset seam). Pass null
     * to clear it so the next {@see instance()} rebuilds from the ConnectionManager.
     */
    public static function setInstance(?self $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * The process-default manager if one exists, without creating one.
     *
     * {@see instance()} is a factory: given no instance it builds a Redis-backed
     * manager, which is right for a caller that wants to broadcast and wrong for one
     * that only wants to know what is currently installed. A test swapping the
     * default in and out is the second kind, and calling the factory to find out
     * what to restore would construct a Redis connection as a side effect of asking
     * a question — the same trap `Application::currentInstance()` exists for.
     */
    public static function currentInstance(): ?self
    {
        return self::$instance;
    }

    // =========================================================================
    // Driver management
    // =========================================================================

    /**
     * Registers a driver.  If a driver with the same name is already registered
     * it is replaced.
     */
    public function addDriver(DriverInterface $driver): static
    {
        $this->drivers[$driver->name()] = $driver;
        return $this;
    }

    /**
     * Sets the default driver by name.
     *
     * @throws \InvalidArgumentException When the driver name is not registered.
     */
    public function setDefault(string $name): static
    {
        if (!isset($this->drivers[$name])) {
            throw new \InvalidArgumentException(
                "Broadcasting driver '{$name}' is not registered. "
                . "Registered: " . implode(', ', array_keys($this->drivers)),
            );
        }
        $this->defaultDriver = $name;
        return $this;
    }

    /**
     * Returns the currently active driver.
     */
    public function driver(?string $name = null): DriverInterface
    {
        $key = $name ?? $this->defaultDriver;
        if (!isset($this->drivers[$key])) {
            throw new \InvalidArgumentException("Broadcasting driver '{$key}' is not registered.");
        }
        return $this->drivers[$key];
    }

    /**
     * Returns the names of all registered drivers.
     *
     * @return string[]
     */
    public function getDriverNames(): array
    {
        return array_keys($this->drivers);
    }

    // =========================================================================
    // Broadcasting
    // =========================================================================

    /**
     * Broadcasts an event on a channel via the default driver.
     *
     * @param string               $channel Channel name.
     * @param string               $event   Event name.
     * @param array<string, mixed> $payload Event data.
     */
    public function broadcast(string $channel, string $event, array $payload): void
    {
        $driver = $this->driver();

        if ($this->exceptSocketId === null) {
            $driver->broadcast($channel, $event, $payload);
            return;
        }

        if ($driver instanceof ExcludesSocketInterface) {
            $driver->broadcastExcept($channel, $event, $payload, $this->exceptSocketId);
            return;
        }

        // The exclusion was asked for and cannot be honoured. Said out loud,
        // because the only symptom is one user seeing a duplicate of something they
        // just did — which looks like an application bug and is a driver
        // capability gap.
        \Pramnos\Logs\Logger::log(
            'Broadcasting: driver "' . $driver->name() . '" cannot exclude a socket, so '
            . 'the event on "' . $channel . '" went to every subscriber including the '
            . 'originating connection.',
            'broadcasting'
        );

        $driver->broadcast($channel, $event, $payload);
    }

    /**
     * Publish a self-describing event.
     *
     * Resolves the channels, the name and the payload from the event itself, so the
     * three decisions live next to the data they describe rather than being repeated
     * — and drifting — at every call site.
     *
     * An event implementing {@see QueuedBroadcastableEvent} is handed to the queue
     * instead of published inline; everything else goes out immediately. Any
     * exclusion set with {@see except()} applies to both paths.
     *
     * A queued event whose queue is unreachable **throws** — the exception from the
     * queue propagates rather than being caught and published inline. Falling back
     * would turn a deliberate "get this out of the request" into the slow request
     * somebody was avoiding, on a path that only misbehaves under load and so would
     * be discovered in production.
     */
    public function event(BroadcastableEvent $event): void
    {
        $channels = $event->broadcastOn();
        $name     = $event->broadcastAs();

        // Resolved once: broadcastWith() may be doing real work, and calling it per
        // channel would multiply that by the size of the audience.
        $payload  = $event->broadcastWith();

        if ($event instanceof QueuedBroadcastableEvent) {
            $this->queueEvent($channels, $name, $payload);

            return;
        }

        foreach ($channels as $channel) {
            $this->broadcast($channel, $name, $payload);
        }
    }

    /**
     * The queue used for {@see QueuedBroadcastableEvent}s.
     *
     * Injectable so an application can point it at its own namespace, and so a test
     * does not need Redis. Resolved lazily otherwise — constructing a manager must
     * not require a queue to exist.
     */
    public function useQueue(?\Pramnos\Queue\DelayedQueue $queue, string $namespace = 'broadcasting'): static
    {
        $this->queue          = $queue;
        $this->queueNamespace = $namespace;

        return $this;
    }

    /**
     * Job type a worker matches to publish a deferred broadcast.
     */
    public const QUEUED_EVENT_JOB = 'broadcasting.event';

    /**
     * @param list<string>        $channels
     * @param array<string,mixed> $payload
     */
    private function queueEvent(array $channels, string $name, array $payload): void
    {
        $queue = $this->queue ?? \Pramnos\Queue\DelayedQueue::redis($this->queueNamespace);

        $queue->push(self::QUEUED_EVENT_JOB, [
            'channels' => $channels,
            'event'    => $name,
            'payload'  => $payload,
            // Carried so the worker can honour toOthers(): the socket id belongs to
            // the request that caused this, and the worker will never see that
            // request.
            'except'   => $this->exceptSocketId,
            'driver'   => $this->defaultDriver,
        ]);
    }

    /**
     * A manager that excludes one connection from the next broadcast.
     *
     * ```php
     * $broadcasting->except($socketId)->broadcast('chat.updates', 'message.created', $payload);
     * ```
     *
     * Returns a clone rather than mutating, and takes no new parameter on
     * {@see broadcast()}. That is not stylistic: adding a trailing parameter to a
     * public method is source-compatible for callers but fatal for a subclass that
     * overrides it, and this framework's own test suite subclasses and overrides
     * `broadcast()` — so the codebase itself proves the pattern exists in the wild.
     *
     * @param string|null $socketId The originating connection, or null to clear.
     */
    public function except(?string $socketId): static
    {
        $clone = clone $this;
        $clone->exceptSocketId = ($socketId === null || $socketId === '') ? null : $socketId;

        return $clone;
    }

    /**
     * The originating connection's socket id, from the request that caused this
     * broadcast.
     *
     * A Pusher-protocol client is given a socket id at handshake and sends it back
     * on the write that triggers a broadcast, conventionally as `X-Socket-ID`. The
     * body field is read too, for a form post that cannot set a header — and for
     * `EventSource`, which cannot set one at all.
     *
     * Validated to the `<n>.<n>` shape the server issues rather than trusted: it is
     * compared against connection ids and, in a driver envelope, is data an edge
     * acts on.
     */
    public static function socketIdFromRequest(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_SOCKET_ID'] ?? null,
            $_POST['socket_id'] ?? null,
            $_GET['socket_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^\d+\.\d+$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Broadcasts an event on a channel via a specific named driver.
     *
     * Useful for fan-out scenarios where the same event should be dispatched
     * to multiple transports (e.g. log + websocket in development).
     */
    public function via(string $driverName, string $channel, string $event, array $payload): void
    {
        $driver = $this->driver($driverName);

        if ($this->exceptSocketId !== null && $driver instanceof ExcludesSocketInterface) {
            $driver->broadcastExcept($channel, $event, $payload, $this->exceptSocketId);
            return;
        }

        $driver->broadcast($channel, $event, $payload);
    }
}
