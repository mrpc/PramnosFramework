<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Broadcasting\Drivers\DriverInterface;
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
        $this->driver()->broadcast($channel, $event, $payload);
    }

    /**
     * Broadcasts an event on a channel via a specific named driver.
     *
     * Useful for fan-out scenarios where the same event should be dispatched
     * to multiple transports (e.g. log + websocket in development).
     */
    public function via(string $driverName, string $channel, string $event, array $payload): void
    {
        $this->driver($driverName)->broadcast($channel, $event, $payload);
    }
}
