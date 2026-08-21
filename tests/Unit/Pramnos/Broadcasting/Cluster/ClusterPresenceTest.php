<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Cluster;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Cluster\ClusterState;
use Pramnos\Broadcasting\Cluster\ClusterTransportInterface;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RedisIngestInterface;
use Pramnos\Broadcasting\Webhooks\WebhookDispatcherInterface;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * Two daemons sharing presence and client events.
 *
 * The test rig is a pair of real servers with a bus between them, rather than a
 * mocked cluster: the properties under test are what a *client* on node A sees when
 * something happens on node B, and that can only be read off the sockets.
 *
 * Before this, both nodes received application events from the backplane — so
 * ordinary broadcasts already worked — while presence membership and client events
 * were per-process. A user on node A did not appear in the member list node B
 * served, and a whisper on A never reached B. Neither said anything: the counts were
 * simply wrong.
 */
#[CoversClass(LocalBroadcastServer::class)]
#[CoversClass(ClusterState::class)]
class ClusterPresenceTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    /** The gossip bus: messages published by either node, in order. */
    private array $bus = [];

    // Public because the anonymous transport and ingest classes below cannot reach
    // a private constant of their enclosing scope.
    public const GOSSIP_CHANNEL = 'app:__pramnos_cluster';

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
        $this->bus     = [];
    }

    /**
     * A transport that appends to the shared bus instead of touching Redis.
     */
    private function transport(): ClusterTransportInterface
    {
        $bus = &$this->bus;

        return new class($bus) implements ClusterTransportInterface {
            public function __construct(private array &$bus)
            {
            }

            public function publish(array $message): void
            {
                $this->bus[] = $message;
            }

            public function channel(): string
            {
                return ClusterPresenceTest::GOSSIP_CHANNEL;
            }
        };
    }

    /**
     * Build one node with $clientCount connections.
     *
     * @return array{0:LocalBroadcastServer, 1:array<int,resource>, 2:ClusterState}
     */
    private function node(string $nodeId, int $clientCount, int $ttlMs = 90_000, ?int &$clock = null): array
    {
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $state  = new ClusterState(
            $nodeId,
            $ttlMs,
            $clock === null ? null : function () use (&$clock): int { return $clock; }
        );

        $server->useCluster($this->transport(), $state, self::GOSSIP_CHANNEL, 30);

        $clients = [];
        $ends    = [];

        for ($i = 1; $i <= $clientCount; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->sockets[] = $pair[0];
            $this->sockets[] = $pair[1];
            stream_set_blocking($pair[0], false);

            $clients[$i] = [
                'socket'    => $pair[1],
                'state'     => 'connected',
                'buffer'    => '',
                'channels'  => [],
                'socketId'  => $i . '.1',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ];
            $ends[$i] = $pair[0];
        }

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        return [$server, $ends, $state];
    }

    private function subscribe(LocalBroadcastServer $server, int $id, string $channel, array $extra = []): void
    {
        (new \ReflectionMethod($server, 'handleSubscribe'))
            ->invoke($server, $id, array_merge(['channel' => $channel, 'auth' => ''], $extra));
    }

    private function presenceMember(string $userId, array $info = []): array
    {
        return ['channel_data' => json_encode(
            $info === [] ? ['user_id' => $userId] : ['user_id' => $userId, 'user_info' => $info]
        )];
    }

    /**
     * Deliver everything currently on the bus to $server, as its ingest would.
     */
    private function deliver(LocalBroadcastServer $server): void
    {
        $messages  = $this->bus;
        $this->bus = [];

        $ingest = new class($messages) implements RedisIngestInterface {
            public function __construct(private array $messages)
            {
            }

            public function connect(): void
            {
            }

            public function getStream()
            {
                return null;
            }

            public function drain(): array
            {
                $out = [];
                foreach ($this->messages as $message) {
                    $out[] = [
                        'channel' => ClusterPresenceTest::GOSSIP_CHANNEL,
                        'message' => (string) json_encode([
                            'event'   => 'cluster',
                            'payload' => $message,
                        ]),
                    ];
                }
                $this->messages = [];

                return $out;
            }

            public function close(): void
            {
            }
        };

        $server->useRedisIngest($ingest);
        (new \ReflectionMethod($server, 'drainRedisIngest'))->invoke($server);
    }

    /** Decoded frames waiting on a client socket. */
    private function frames(mixed $end): array
    {
        $raw    = (string) fread($end, 65536);
        $events = [];

        while ($raw !== '') {
            $frame = FrameCodec::decode($raw);
            if ($frame === null) {
                break;
            }
            $raw     = substr($raw, $frame['consumed']);
            $decoded = json_decode($frame['payload'], true);
            if (is_array($decoded)) {
                $decoded['data'] = is_string($decoded['data'] ?? null)
                    ? json_decode($decoded['data'], true)
                    : ($decoded['data'] ?? null);
                $events[] = $decoded;
            }
        }

        return $events;
    }

    private function eventNamed(array $frames, string $name): ?array
    {
        foreach ($frames as $frame) {
            if (($frame['event'] ?? '') === $name) {
                return $frame;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------

    /**
     * A member on node B appears in the list node A serves.
     *
     * The headline property: without it, a load balancer makes every presence count
     * wrong and nothing reports it.
     */
    public function testAMemberOnOneNodeIsVisibleOnTheOther(): void
    {
        // Arrange
        [$nodeA, , ] = $this->node('a', 1);
        [$nodeB, , ] = $this->node('b', 1);

        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));
        $this->subscribe($nodeB, 1, 'presence-room', $this->presenceMember('9', ['name' => 'Grace']));

        // Act — each node hears the other's gossip
        $busSnapshot = $this->bus;
        $this->bus   = array_values(array_filter($busSnapshot, fn (array $m): bool => $m['node'] === 'b'));
        $this->deliver($nodeA);
        $this->bus   = array_values(array_filter($busSnapshot, fn (array $m): bool => $m['node'] === 'a'));
        $this->deliver($nodeB);

        // Assert
        $this->assertEqualsCanonicalizing(
            ['7', '9'],
            array_map('strval', array_keys($nodeA->presenceMembers('presence-room'))),
            "node A must see node B's member"
        );
        $this->assertSame(
            ['name' => 'Grace'],
            $nodeA->presenceMembers('presence-room')['9'],
            'and its info'
        );
        $this->assertEqualsCanonicalizing(
            ['7', '9'],
            array_map('strval', array_keys($nodeB->presenceMembers('presence-room')))
        );
    }

    /**
     * A remote arrival reaches the local subscribers as `member_added`.
     */
    public function testARemoteArrivalIsAnnouncedToLocalClients(): void
    {
        // Arrange
        [$nodeA, $endsA] = $this->node('a', 1);
        [$nodeB, ]       = $this->node('b', 1);
        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));
        $this->bus = [];
        $this->frames($endsA[1]);

        // Act
        $this->subscribe($nodeB, 1, 'presence-room', $this->presenceMember('9', ['name' => 'Grace']));
        $this->deliver($nodeA);

        // Assert
        $added = $this->eventNamed($this->frames($endsA[1]), 'pusher_internal:member_added');
        $this->assertNotNull($added);
        $this->assertSame('9', $added['data']['user_id']);
        $this->assertSame(['name' => 'Grace'], $added['data']['user_info']);
    }

    /**
     * A remote departure is announced, once nobody anywhere holds that user.
     */
    public function testARemoteDepartureIsAnnounced(): void
    {
        // Arrange
        [$nodeA, $endsA] = $this->node('a', 1);
        [$nodeB, ]       = $this->node('b', 1);
        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));
        $this->subscribe($nodeB, 1, 'presence-room', $this->presenceMember('9'));
        $this->bus = array_values(array_filter($this->bus, fn (array $m): bool => $m['node'] === 'b'));
        $this->deliver($nodeA);
        $this->frames($endsA[1]);

        // Act
        (new \ReflectionMethod($nodeB, 'handleUnsubscribe'))->invoke($nodeB, 1, 'presence-room');
        $this->deliver($nodeA);

        // Assert
        $removed = $this->eventNamed($this->frames($endsA[1]), 'pusher_internal:member_removed');
        $this->assertNotNull($removed);
        $this->assertSame('9', $removed['data']['user_id']);
        $this->assertSame(['7'], array_map('strval', array_keys($nodeA->presenceMembers('presence-room'))));
    }

    /**
     * A user connected to both nodes is announced once, and only leaves when both
     * connections go.
     *
     * The people-not-sockets rule applied across nodes. Getting it wrong here shows a
     * single person as two members and then removes them while they are still
     * connected somewhere.
     */
    public function testAUserOnBothNodesIsOneMemberAndLeavesOnce(): void
    {
        // Arrange
        [$nodeA, $endsA] = $this->node('a', 1);
        [$nodeB, ]       = $this->node('b', 1);
        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));
        $this->bus = [];
        $this->frames($endsA[1]);

        // Act — the same user connects to node B
        $this->subscribe($nodeB, 1, 'presence-room', $this->presenceMember('7'));
        $this->deliver($nodeA);

        // Assert — no arrival announced, still one member
        $this->assertNull(
            $this->eventNamed($this->frames($endsA[1]), 'pusher_internal:member_added'),
            'the same person arriving elsewhere is not a new member'
        );
        $this->assertCount(1, $nodeA->presenceMembers('presence-room'));

        // Act — their node B connection goes
        (new \ReflectionMethod($nodeB, 'handleUnsubscribe'))->invoke($nodeB, 1, 'presence-room');
        $this->deliver($nodeA);

        // Assert — still here, because node A still holds them
        $this->assertNull(
            $this->eventNamed($this->frames($endsA[1]), 'pusher_internal:member_removed'),
            'they are still connected to node A'
        );
        $this->assertCount(1, $nodeA->presenceMembers('presence-room'));
    }

    /**
     * A whisper on one node reaches subscribers on the other.
     *
     * The other half of the per-process problem: client events never crossed nodes,
     * so a typing indicator worked only for the fraction of a room that happened to
     * land on the same daemon.
     */
    public function testAWhisperCrossesNodes(): void
    {
        // Arrange
        [$nodeA, $endsA] = $this->node('a', 1);
        [$nodeB, ]       = $this->node('b', 1);
        $nodeA->allowClientEvents(true);
        $nodeB->allowClientEvents(true);

        $this->subscribe($nodeA, 1, 'private-room');
        $this->subscribe($nodeB, 1, 'private-room');
        $this->bus = [];
        $this->frames($endsA[1]);

        // Act
        (new \ReflectionMethod($nodeB, 'handleTextMessage'))->invoke(
            $nodeB,
            1,
            (string) json_encode([
                'event'   => 'client-typing',
                'channel' => 'private-room',
                'data'    => ['user' => 'Grace'],
            ])
        );
        $this->deliver($nodeA);

        // Assert
        $whisper = $this->eventNamed($this->frames($endsA[1]), 'client-typing');
        $this->assertNotNull($whisper);
        $this->assertSame(['user' => 'Grace'], $whisper['data']);
    }

    /**
     * A relayed client event from a peer is held to the same guards as a local one.
     *
     * A peer's enforcement is not something to take on trust: a compromised or
     * misconfigured node must not be able to publish onto a public channel here, or
     * to inject an application event name.
     */
    public function testRelayedClientEventsAreStillGuarded(): void
    {
        // Arrange
        [$nodeA, $endsA] = $this->node('a', 1);
        $nodeA->allowClientEvents(true);
        $this->subscribe($nodeA, 1, 'public-room');
        $this->subscribe($nodeA, 1, 'private-room');
        $this->frames($endsA[1]);

        $handle = new \ReflectionMethod($nodeA, 'handleClusterMessage');

        // Act — a public channel, and an application event name
        $handle->invoke($nodeA, [
            'type' => 'client_event', 'node' => 'b', 'ts' => 1,
            'channel' => 'public-room', 'event' => 'client-typing', 'data' => '{}',
        ]);
        $handle->invoke($nodeA, [
            'type' => 'client_event', 'node' => 'b', 'ts' => 2,
            'channel' => 'private-room', 'event' => 'order.paid', 'data' => '{}',
        ]);

        // Assert
        $this->assertSame([], $this->frames($endsA[1]), 'neither may be relayed');
    }

    /**
     * A node that stops gossiping has its members dropped and their departures
     * announced.
     *
     * Otherwise a killed node leaves a room full of people who are not there — a
     * member list that only ever grows.
     */
    public function testMembersOfAnExpiredNodeAreRemoved(): void
    {
        // Arrange
        $clock = 1_000_000;
        [$nodeA, $endsA, $state] = $this->node('a', 1, ttlMs: 1000, clock: $clock);
        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));

        (new \ReflectionMethod($nodeA, 'handleClusterMessage'))->invoke($nodeA, [
            'type' => 'state', 'node' => 'b', 'ts' => $clock,
            'channels' => ['presence-room' => ['9' => ['name' => 'Grace']]],
        ]);
        $this->frames($endsA[1]);
        $this->assertCount(2, $nodeA->presenceMembers('presence-room'), 'both visible to begin with');

        // Act — node B goes quiet past the TTL, and node A gossips on schedule
        $clock += 2000;
        (new \ReflectionProperty($nodeA, 'lastGossipAt'))->setValue($nodeA, 0);
        (new \ReflectionMethod($nodeA, 'gossipState'))->invoke($nodeA);

        // Assert
        $this->assertSame(
            ['7'],
            array_map('strval', array_keys($nodeA->presenceMembers('presence-room'))),
            "the dead node's members are gone"
        );
        $removed = $this->eventNamed($this->frames($endsA[1]), 'pusher_internal:member_removed');
        $this->assertNotNull($removed, 'and the departure is announced');
        $this->assertSame('9', $removed['data']['user_id']);
        $this->assertSame([], $state->nodes());
    }

    /**
     * Periodic gossip publishes this node's full membership, and a heartbeat when it
     * has none.
     *
     * The full state is the repair mechanism — it is why no individual delta has to
     * be reliable — and the heartbeat is why a node with only empty channels is not
     * mistaken for a dead one.
     */
    public function testPeriodicGossipPublishesStateOrAHeartbeat(): void
    {
        // Arrange
        [$server, , ] = $this->node('a', 1);
        $gossip = new \ReflectionMethod($server, 'gossipState');
        $last   = new \ReflectionProperty($server, 'lastGossipAt');

        // Act — nothing to report
        $this->bus = [];
        $last->setValue($server, 0);
        $gossip->invoke($server);
        $heartbeat = $this->bus;

        // Act — with a member
        $this->subscribe($server, 1, 'presence-room', $this->presenceMember('7'));
        $this->bus = [];
        $last->setValue($server, 0);
        $gossip->invoke($server);
        $state = $this->bus;

        // Assert
        $this->assertSame('heartbeat', $heartbeat[0]['type']);
        $this->assertSame('a', $heartbeat[0]['node']);

        $this->assertSame('state', $state[0]['type']);
        $this->assertSame(['presence-room' => ['7' => []]], $state[0]['channels']);
    }

    /**
     * Gossip is not published more often than the interval.
     */
    public function testGossipRespectsTheInterval(): void
    {
        // Arrange
        [$server, , ] = $this->node('a', 0);
        $gossip = new \ReflectionMethod($server, 'gossipState');
        (new \ReflectionProperty($server, 'lastGossipAt'))->setValue($server, time());
        $this->bus = [];

        // Act
        $gossip->invoke($server);

        // Assert
        $this->assertSame([], $this->bus);
    }

    /**
     * A gossip message must never reach a browser.
     *
     * It arrives on the same backplane as application events, so the only thing
     * separating them is the channel check — and if that check were wrong, every
     * node's internal state would be fanned out to every subscriber.
     */
    public function testGossipIsNeverFannedOutToClients(): void
    {
        // Arrange
        [$server, $ends, ] = $this->node('a', 1);
        (new \ReflectionProperty($server, 'subscriptions'))
            ->setValue($server, [self::GOSSIP_CHANNEL => [1 => 1]]);
        $this->frames($ends[1]);

        // Act
        $this->bus = [['type' => 'heartbeat', 'node' => 'b', 'ts' => 1]];
        $this->deliver($server);

        // Assert
        $this->assertSame([], $this->frames($ends[1]), 'not one frame');
    }

    /**
     * Member webhooks stay with the node that owns the connection.
     *
     * That is what makes them safe without coordination: exactly one node reports
     * each member, so a receiver counting `member_added` is counting people and not
     * nodes.
     */
    public function testMemberWebhooksAreNotEmittedForRemoteMembers(): void
    {
        // Arrange
        $recorder = new class implements WebhookDispatcherInterface {
            /** @var list<array<string,mixed>> */
            public array $events = [];

            public function dispatch(array $events): void
            {
                foreach ($events as $event) {
                    $this->events[] = $event;
                }
            }
        };

        [$nodeA, , ] = $this->node('a', 1);
        $nodeA->useWebhooks($recorder);
        $flush = new \ReflectionMethod($nodeA, 'flushWebhooks');

        // Act — a member arrives on node B
        (new \ReflectionMethod($nodeA, 'handleClusterMessage'))->invoke($nodeA, [
            'type' => 'join', 'node' => 'b', 'ts' => 1,
            'channel' => 'presence-room', 'user_id' => '9',
        ]);
        $flush->invoke($nodeA);

        // Assert
        $this->assertSame(
            [],
            array_values(array_filter(
                $recorder->events,
                static fn (array $e): bool => str_starts_with((string) $e['name'], 'member_')
            )),
            'node A must not report a member it does not own'
        );

        // Act — and one arrives locally
        $this->subscribe($nodeA, 1, 'presence-room', $this->presenceMember('7'));
        $flush->invoke($nodeA);

        // Assert
        $names = array_column($recorder->events, 'name');
        $this->assertContains('member_added', $names, 'but it does report its own');
    }

    /**
     * Without a cluster configured, nothing is gossiped and no work is done per
     * presence change.
     */
    public function testASingleNodeDeploymentGossipsNothing(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $pair   = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        (new \ReflectionProperty($server, 'clients'))->setValue($server, [
            1 => [
                'socket' => $pair[1], 'state' => 'connected', 'buffer' => '',
                'channels' => [], 'socketId' => '1.1', 'pingAt' => time() + 30,
                'assembler' => null,
            ],
        ]);

        // Act
        $this->subscribe($server, 1, 'presence-room', $this->presenceMember('7'));
        (new \ReflectionMethod($server, 'gossipState'))->invoke($server);

        // Assert
        $this->assertSame([], $this->bus);
        $this->assertSame(['7'], array_map('strval', array_keys($server->presenceMembers('presence-room'))));
    }
}
