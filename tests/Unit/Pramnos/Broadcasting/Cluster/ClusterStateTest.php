<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Cluster;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Cluster\ClusterState;

/**
 * The merge logic behind cluster-wide presence.
 *
 * Pure on purpose: everything that decides whether a member is present, whose word
 * counts, and when a node stops being believed lives here, with an injected clock
 * and no sockets. The failure modes it has to rule out are all "wrong member list
 * with nothing in any log" — a departed member resurrected by a late message, a
 * dead node's members lingering forever, a user counted once per node they landed
 * on.
 */
#[CoversClass(ClusterState::class)]
class ClusterStateTest extends TestCase
{
    private int $now = 1_000_000;

    private function state(int $ttlMs = 90_000): ClusterState
    {
        return new ClusterState('self', $ttlMs, fn (): int => $this->now);
    }

    /**
     * The member ids of a channel, as strings.
     *
     * `array_keys()` alone will not do: PHP casts a numeric string array key to an
     * integer, so a member id of "7" comes back as int 7. The production code casts
     * on the way out for exactly this reason — see LocalBroadcastServer's presence
     * payload — and the assertions have to compare the same thing clients do.
     *
     * @return list<string>
     */
    private function memberIds(ClusterState $state, string $channel): array
    {
        return array_map('strval', array_keys($state->remoteMembers($channel)));
    }

    /**
     * A peer's full state becomes visible as remote membership.
     */
    public function testFullStateBecomesRemoteMembership(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $applied = $state->applyState('node-b', [
            'presence-room' => ['7' => ['name' => 'Ada']],
        ], $this->now);

        // Assert
        $this->assertTrue($applied);
        $this->assertSame(['name' => 'Ada'], $state->remoteMembers('presence-room')['7']);
        $this->assertSame(['7'], $this->memberIds($state, 'presence-room'));
        $this->assertTrue($state->hasRemoteMember('presence-room', '7'));
        $this->assertSame(['node-b'], $state->nodes());
    }

    /**
     * A full state replaces a node's entry wholesale, so a member it no longer lists
     * is gone.
     *
     * This is the self-healing property: whatever a node missed, one state message
     * makes it right again, and no individual delta has to be reliable.
     */
    public function testFullStateReplacesRatherThanMerges(): void
    {
        // Arrange
        $state = $this->state();
        $state->applyState('node-b', ['presence-room' => ['7' => [], '9' => []]], $this->now);

        // Act — node-b now reports only 9
        $this->now += 1000;
        $state->applyState('node-b', ['presence-room' => ['9' => []]], $this->now);

        // Assert
        $this->assertSame(['9'], $this->memberIds($state, 'presence-room'));
    }

    /**
     * Deltas add and remove single members.
     */
    public function testDeltasAddAndRemoveMembers(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $state->applyJoin('node-b', 'presence-room', '7', ['name' => 'Ada'], $this->now);
        $this->now += 10;
        $state->applyJoin('node-b', 'presence-room', '9', [], $this->now);
        $this->now += 10;
        $state->applyLeave('node-b', 'presence-room', '7', $this->now);

        // Assert
        $this->assertSame(['9'], $this->memberIds($state, 'presence-room'));
    }

    /**
     * A member with a tab on two nodes is one member.
     *
     * The same people-not-sockets rule as within a node, one level up. Without it a
     * load balancer spreading a user's tabs would inflate the count by however many
     * nodes they landed on.
     */
    public function testTheSameUserOnTwoNodesIsOneMember(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $state->applyJoin('node-b', 'presence-room', '7', ['name' => 'Ada'], $this->now);
        $state->applyJoin('node-c', 'presence-room', '7', ['name' => 'Ada'], $this->now);

        // Assert
        $this->assertCount(1, $state->remoteMembers('presence-room'));
    }

    /**
     * A stale message is dropped, so a delayed join cannot resurrect a member who has
     * since left.
     *
     * The only ordering hazard the design has, and the one place it is closed.
     */
    public function testAStaleJoinCannotResurrectADepartedMember(): void
    {
        // Arrange
        $state = $this->state();
        $state->applyJoin('node-b', 'presence-room', '7', [], $this->now);
        $this->now += 100;
        $state->applyLeave('node-b', 'presence-room', '7', $this->now);

        // Act — a join stamped before the leave arrives late
        $applied = $state->applyJoin('node-b', 'presence-room', '7', [], $this->now - 50);

        // Assert
        $this->assertFalse($applied, 'the stale message must be refused');
        $this->assertSame([], $state->remoteMembers('presence-room'));
    }

    /**
     * A message from this node itself is ignored.
     *
     * Its membership is already known locally, so counting it again would double
     * every member on the node that published it.
     */
    public function testOwnGossipIsIgnored(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $applied = $state->applyState('self', ['presence-room' => ['7' => []]], $this->now);

        // Assert
        $this->assertFalse($applied);
        $this->assertSame([], $state->remoteMembers('presence-room'));
        $this->assertSame([], $state->nodes());
    }

    /**
     * A message with no node id is ignored rather than filed under an empty name.
     */
    public function testMessageWithoutANodeIdIsIgnored(): void
    {
        // Arrange
        $state = $this->state();

        // Act & Assert
        $this->assertFalse($state->applyJoin('', 'presence-room', '7', [], $this->now));
        $this->assertSame([], $state->nodes());
    }

    /**
     * A node that stops gossiping is dropped, and its members are reported so the
     * departures can be announced.
     *
     * Keeping them would show a room full of people who left when a node was killed:
     * a member list that only ever grows.
     */
    public function testAQuietNodeExpiresAndReportsItsMembers(): void
    {
        // Arrange
        $state = $this->state(ttlMs: 90_000);
        $state->applyState('node-b', [
            'presence-room' => ['7' => [], '9' => []],
            'presence-hall' => ['7' => []],
        ], $this->now);

        // Act — silent for longer than the TTL
        $this->now += 90_001;
        $dropped = $state->pruneExpired();

        // Assert
        $this->assertArrayHasKey('node-b', $dropped);
        $this->assertEqualsCanonicalizing(['7', '9'], $dropped['node-b']['presence-room']);
        $this->assertSame(['7'], $dropped['node-b']['presence-hall']);
        $this->assertSame([], $state->remoteMembers('presence-room'));
        $this->assertSame([], $state->nodes());
    }

    /**
     * A node inside the TTL is not pruned.
     *
     * The TTL is a multiple of the gossip interval precisely so one late message does
     * not evict a healthy node — this pins the boundary.
     */
    public function testANodeInsideTheTtlSurvives(): void
    {
        // Arrange
        $state = $this->state(ttlMs: 90_000);
        $state->applyState('node-b', ['presence-room' => ['7' => []]], $this->now);

        // Act
        $this->now += 90_000;

        // Assert
        $this->assertSame([], $state->pruneExpired(), 'exactly at the TTL is still alive');
        $this->assertSame(['node-b'], $state->nodes());
    }

    /**
     * A heartbeat keeps a node alive with nothing to report.
     *
     * Without it, a node serving only empty channels would look dead, be pruned, and
     * reappear on its next join — churning the member list of every channel it does
     * serve.
     */
    public function testAHeartbeatKeepsAnEmptyNodeAlive(): void
    {
        // Arrange
        $state = $this->state(ttlMs: 1000);
        $state->applyHeartbeat('node-b', $this->now);

        // Act
        $this->now += 900;
        $state->applyHeartbeat('node-b', $this->now);
        $this->now += 900;

        // Assert
        $this->assertSame([], $state->pruneExpired());
        $this->assertSame(['node-b'], $state->nodes());
    }

    /**
     * remoteChannels() reports every channel any peer holds, for a caller merging
     * channel lists.
     */
    public function testRemoteChannelsAreReported(): void
    {
        // Arrange
        $state = $this->state();
        $state->applyJoin('node-b', 'presence-a', '1', [], $this->now);
        $state->applyJoin('node-c', 'presence-b', '2', [], $this->now);
        $state->applyJoin('node-c', 'presence-a', '3', [], $this->now);

        // Act & Assert
        $this->assertEqualsCanonicalizing(['presence-a', 'presence-b'], $state->remoteChannels());
    }

    /**
     * Removing a node's last member in a channel removes the channel, so
     * remoteChannels() does not accumulate empty names.
     */
    public function testAnEmptiedChannelIsForgotten(): void
    {
        // Arrange
        $state = $this->state();
        $state->applyJoin('node-b', 'presence-room', '7', [], $this->now);

        // Act
        $this->now += 10;
        $state->applyLeave('node-b', 'presence-room', '7', $this->now);

        // Assert
        $this->assertSame([], $state->remoteChannels());
    }

    /**
     * A leave for a member the node never reported is harmless.
     *
     * Deltas are not required to be reliable — a node may well hear a leave whose
     * join it missed — so this has to be a no-op rather than an error.
     */
    public function testALeaveForAnUnknownMemberIsHarmless(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $applied = $state->applyLeave('node-b', 'presence-room', '404', $this->now);

        // Assert
        $this->assertTrue($applied, 'the message is still fresh, so the node is still seen');
        $this->assertSame([], $state->remoteMembers('presence-room'));
        $this->assertSame(['node-b'], $state->nodes(), 'and the node counts as alive');
    }

    /**
     * Non-array member info is normalised, so a caller reading `info` never has to
     * guard.
     */
    public function testNonArrayInfoIsNormalised(): void
    {
        // Arrange
        $state = $this->state();

        // Act
        $state->applyState('node-b', ['presence-room' => ['7' => 'not an array']], $this->now);

        // Assert
        $this->assertSame(['7'], $this->memberIds($state, 'presence-room'));
        $this->assertSame([], $state->remoteMembers('presence-room')['7']);
    }

    /**
     * The node id is readable, since gossip has to be stamped with it.
     */
    public function testNodeIdIsExposed(): void
    {
        // Assert
        $this->assertSame('self', $this->state()->nodeId());
    }
}
