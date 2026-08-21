<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Cluster;

use Pramnos\Broadcasting\Drivers\DriverInterface;

/**
 * Gossip over the broadcasting backplane, using whichever driver the deployment
 * already publishes application events with.
 *
 * Taking the driver rather than constructing one is the point: gossip and
 * application events must use the same Redis primitive as the ingest reads, and the
 * only way to guarantee that is to hand it the same object. See
 * {@see ClusterTransportInterface} for what happens when they diverge.
 */
final class RedisClusterTransport implements ClusterTransportInterface
{
    /**
     * @param DriverInterface $driver  The same driver the application publishes with.
     * @param string          $channel Channel gossip travels on. Defaults to a name
     *                                 no application would choose, since a collision
     *                                 would feed cluster messages to browsers.
     */
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly string $channel = '__pramnos_cluster',
    ) {
    }

    public function publish(array $message): void
    {
        try {
            $this->driver->broadcast($this->channel, 'cluster', $message);
        } catch (\Throwable $e) {
            // A backplane hiccup must not take the edge down. The consequence of a
            // lost gossip message is bounded by design — the next periodic full state
            // corrects it — which is exactly why this can be swallowed and a lost
            // application event could not be.
            \Pramnos\Logs\Logger::log(
                'Broadcasting cluster: gossip could not be published: ' . $e->getMessage(),
                'broadcasting'
            );
        }
    }

    public function channel(): string
    {
        return $this->channel;
    }
}
