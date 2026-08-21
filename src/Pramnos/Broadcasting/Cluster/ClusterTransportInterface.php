<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Cluster;

/**
 * How a node sends gossip to its peers.
 *
 * One method, and it must not block: this is called from inside the server's
 * `stream_select()` loop, on every presence change and every relayed client event.
 * A transport that waits on a remote server would make every membership change cost
 * a round trip to it, for every connected client.
 *
 * ## The pairing rule applies here too
 *
 * Gossip travels on the same backplane as application events, so it inherits the
 * rule that has already cost this project twice: **the primitive that publishes must
 * be the primitive the ingest reads.** A node publishing gossip with `XADD` while its
 * peers listen with `SUBSCRIBE` produces a cluster where every node believes it is
 * alone — a perfectly healthy subscription that is never delivered anything, and
 * presence counts that are wrong with nothing in any log.
 *
 * {@see RedisClusterTransport} therefore takes the driver rather than choosing one,
 * so a deployment configures the pair together.
 */
interface ClusterTransportInterface
{
    /**
     * Send one gossip message to the other nodes.
     *
     * @param array<string,mixed> $message
     */
    public function publish(array $message): void;

    /**
     * The channel gossip travels on, so the server can tell an ingested gossip
     * message from an application event and route it accordingly.
     */
    public function channel(): string;
}
