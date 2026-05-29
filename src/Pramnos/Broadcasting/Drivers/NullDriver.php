<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

/**
 * No-op driver — discards all broadcast events.
 *
 * Used as the default driver when broadcasting is enabled in app.php but no
 * concrete transport is configured.  Also useful in unit tests that don't want
 * any side-effects.
 *
 */
class NullDriver implements DriverInterface
{
    public function broadcast(string $channel, string $event, array $payload): void
    {
        // intentionally empty
    }

    public function name(): string
    {
        return 'null';
    }
}
