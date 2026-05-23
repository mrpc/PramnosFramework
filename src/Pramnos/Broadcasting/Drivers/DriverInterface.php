<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

/**
 * Contract for broadcasting transport drivers.
 *
 * Drivers implement the actual delivery mechanism (WebSocket push, Pusher API,
 * log file, null/no-op, etc.).  The BroadcastingManager selects the active
 * driver and delegates every broadcast() call to it.
 *
 * @package PramnosFramework
 * @subpackage Broadcasting\Drivers
 */
interface DriverInterface
{
    /**
     * Send an event on a channel.
     *
     * @param string               $channel Channel name (e.g. 'presence-room.42', 'private-user.7').
     * @param string               $event   Event name (e.g. 'message.created').
     * @param array<string, mixed> $payload Event data.
     */
    public function broadcast(string $channel, string $event, array $payload): void;

    /**
     * Human-readable driver name (for debug output and logging).
     */
    public function name(): string;
}
