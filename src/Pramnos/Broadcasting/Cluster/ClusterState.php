<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Cluster;

/**
 * What this node believes the *other* nodes' presence membership to be.
 *
 * Local membership stays where it was — keyed by connection, in the server. This
 * holds only the remote half, and merging the two on read is what makes a presence
 * channel span more than one daemon.
 *
 * ## Two message kinds, and why both
 *
 * **Deltas** (`join`, `leave`) carry a single change and arrive immediately, so a
 * member appears on the other nodes as fast as the backplane can move a message.
 * They are the latency mechanism and they are *not* the correctness mechanism: a
 * node that misses one — restarted mid-gossip, a dropped pub/sub message, a
 * subscription that reconnected — would otherwise be wrong about that user until
 * something unrelated corrected it.
 *
 * **Full state** is republished periodically and *replaces* a node's entry
 * wholesale. That is what makes the design self-healing: whatever a node missed, it
 * is right again within one interval, and no delta needs to be reliable. It also
 * removes the whole category of ordering bugs — a state message is not applied
 * relative to anything.
 *
 * A late-arriving delta cannot resurrect a departed member, because every message
 * carries the sending node's timestamp and anything older than the node's last
 * accepted message is dropped.
 *
 * ## Nodes expire
 *
 * A node that stops gossiping has died, been stopped, or been partitioned away, and
 * its members are no longer connected to anything reachable. Keeping them would show
 * a room full of people who left when a node was killed — the failure mode is a
 * member list that only ever grows. So a node whose last message is older than the
 * TTL is dropped entirely, and `pruneExpired()` reports which, so departures can be
 * announced rather than silently applied.
 *
 * The TTL has to be a multiple of the gossip interval, not equal to it: one late
 * message must not evict a healthy node.
 */
final class ClusterState
{
    /** @var array<string, array<string, array<string, array<string,mixed>>>> node → channel → user_id → info */
    private array $remote = [];

    /** @var array<string, int> node → timestamp (ms) of its last accepted message */
    private array $seenAt = [];

    /** @var callable():int */
    private $clock;

    /**
     * @param string $nodeId This node's own id — messages from it are ignored, since
     *                       its membership is already known locally and counting it
     *                       twice would double every member on its own node.
     * @param int    $ttlMs  How long a silent node is believed. Must be a multiple of
     *                       the gossip interval.
     * @param callable():int|null $clock Milliseconds; injectable for tests.
     */
    public function __construct(
        private readonly string $nodeId,
        private readonly int $ttlMs = 90_000,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Replace everything known about $node.
     *
     * @param array<string, array<string, array<string,mixed>>> $channels channel → user_id → info
     * @return bool False when the message was ignored (own node, or stale).
     */
    public function applyState(string $node, array $channels, int $timestampMs): bool
    {
        if (!$this->accept($node, $timestampMs)) {
            return false;
        }

        $this->remote[$node] = $channels;
        $this->seenAt[$node] = $timestampMs;

        return true;
    }

    /**
     * Apply a single membership change.
     *
     * @param array<string,mixed> $info
     * @return bool False when the message was ignored.
     */
    public function applyJoin(string $node, string $channel, string $userId, array $info, int $timestampMs): bool
    {
        if (!$this->accept($node, $timestampMs)) {
            return false;
        }

        $this->remote[$node][$channel][$userId] = $info;
        $this->seenAt[$node] = $timestampMs;

        return true;
    }

    /**
     * @return bool False when the message was ignored.
     */
    public function applyLeave(string $node, string $channel, string $userId, int $timestampMs): bool
    {
        if (!$this->accept($node, $timestampMs)) {
            return false;
        }

        unset($this->remote[$node][$channel][$userId]);

        if (($this->remote[$node][$channel] ?? null) === []) {
            unset($this->remote[$node][$channel]);
        }

        $this->seenAt[$node] = $timestampMs;

        return true;
    }

    /**
     * A heartbeat from a node with no membership to report.
     *
     * Without one, a node whose channels are all empty would look dead and be
     * pruned, and then reappear on its next join — churning the member list of every
     * channel it does serve.
     */
    public function applyHeartbeat(string $node, int $timestampMs): bool
    {
        if (!$this->accept($node, $timestampMs)) {
            return false;
        }

        $this->seenAt[$node]  = $timestampMs;
        $this->remote[$node] ??= [];

        return true;
    }

    /**
     * Drop nodes that have gone quiet.
     *
     * @return array<string, array<string, list<string>>> node → channel → user_ids that
     *         were dropped, so the caller can announce the departures.
     */
    public function pruneExpired(): array
    {
        $now     = ($this->clock)();
        $dropped = [];

        foreach ($this->seenAt as $node => $seenAt) {
            if ($now - $seenAt <= $this->ttlMs) {
                continue;
            }

            foreach ($this->remote[$node] ?? [] as $channel => $members) {
                $dropped[$node][$channel] = array_map('strval', array_keys($members));
            }

            $dropped[$node] ??= [];
            unset($this->remote[$node], $this->seenAt[$node]);
        }

        return $dropped;
    }

    /**
     * Remote members of $channel, deduplicated by user id across nodes.
     *
     * One person with a tab on two nodes is one member — the same rule as within a
     * node, applied one level up. Without it, a load balancer spreading a user's tabs
     * would inflate the count by however many nodes they landed on.
     *
     * @return array<string, array<string,mixed>> user_id → info
     */
    public function remoteMembers(string $channel): array
    {
        $members = [];

        foreach ($this->remote as $channels) {
            foreach ($channels[$channel] ?? [] as $userId => $info) {
                $members[(string) $userId] = is_array($info) ? $info : [];
            }
        }

        return $members;
    }

    /** True when some remote node reports $userId in $channel. */
    public function hasRemoteMember(string $channel, string $userId): bool
    {
        foreach ($this->remote as $channels) {
            if (isset($channels[$channel][$userId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every channel any remote node reports, for a caller merging channel lists.
     *
     * @return list<string>
     */
    public function remoteChannels(): array
    {
        $channels = [];

        foreach ($this->remote as $nodeChannels) {
            foreach (array_keys($nodeChannels) as $channel) {
                $channels[(string) $channel] = true;
            }
        }

        return array_keys($channels);
    }

    /**
     * The peer nodes currently believed alive.
     *
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_keys($this->seenAt);
    }

    /**
     * Whether a message from $node stamped $timestampMs should be applied.
     *
     * Two refusals. **Own node**: its membership is already known locally, and
     * counting it again would double every member on the node that published it.
     * **Stale**: a message older than the last one accepted from that node would
     * otherwise let a delayed `join` resurrect a member who has since left — the
     * only ordering hazard the design has, and this is where it is closed.
     */
    private function accept(string $node, int $timestampMs): bool
    {
        if ($node === '' || $node === $this->nodeId) {
            return false;
        }

        return $timestampMs >= ($this->seenAt[$node] ?? 0);
    }
}
