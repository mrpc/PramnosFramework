<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RedisIngestInterface;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * Edge and error paths of presence, client events and the socket exclusion.
 *
 * Each of these is a way the happy path can be reached with the wrong data, and each
 * would otherwise fail somewhere later and less legibly — an unnamed member in a
 * room, a rate limit that never resets, an exclusion that never crosses the process
 * boundary it exists to cross.
 */
#[CoversClass(LocalBroadcastServer::class)]
#[CoversClass(PusherAuthorizer::class)]
#[CoversClass(AllowAllAuthorizer::class)]
#[CoversClass(RedisDriver::class)]
class PresenceAndClientEventEdgeCasesTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    // -------------------------------------------------------------------------
    // Authorizers: member data that carries no identity
    // -------------------------------------------------------------------------

    /**
     * Absent or empty channel data yields no member, in both authorizers.
     *
     * A `presence-` subscription without member data is legitimate — a client that
     * only wants the channel's events — so the answer is "no member", not an error.
     */
    public function testAbsentChannelDataYieldsNoMember(): void
    {
        foreach ([new AllowAllAuthorizer(), new PusherAuthorizer('k', 's')] as $authorizer) {
            // Act & Assert
            $this->assertNull($authorizer->presenceMember('presence-x', '1.2', null));
            $this->assertNull($authorizer->presenceMember('presence-x', '1.2', ''));
        }
    }

    /**
     * Channel data that is not an object, or has no user_id, or an empty one,
     * yields no member.
     *
     * Admitting any of these would put an entry with no usable id in every
     * subscriber's member list, where it can be neither matched nor removed.
     */
    public function testUnusableChannelDataYieldsNoMember(): void
    {
        $authorizer = new PusherAuthorizer('k', 's');

        foreach (['not json', '"a string"', '[]', '{}', '{"user_id":""}'] as $channelData) {
            // Act & Assert
            $this->assertNull(
                $authorizer->presenceMember('presence-x', '1.2', $channelData),
                'channel_data: ' . $channelData
            );
        }
    }

    /**
     * user_info is carried through only when it is an object, and omitted otherwise.
     *
     * A scalar `user_info` would reach every client as something they iterate over,
     * so it is dropped rather than forwarded.
     */
    public function testUserInfoIsCarriedOnlyWhenItIsAnObject(): void
    {
        $authorizer = new PusherAuthorizer('k', 's');

        // Act
        $withInfo    = $authorizer->presenceMember('presence-x', '1.2', '{"user_id":"7","user_info":{"n":1}}');
        $scalarInfo  = $authorizer->presenceMember('presence-x', '1.2', '{"user_id":"7","user_info":"nope"}');

        // Assert
        $this->assertSame(['user_id' => '7', 'user_info' => ['n' => 1]], $withInfo);
        $this->assertSame(['user_id' => '7'], $scalarInfo, 'a scalar user_info is dropped');
    }

    // -------------------------------------------------------------------------
    // The server
    // -------------------------------------------------------------------------

    /**
     * @return array{0:LocalBroadcastServer, 1:array<int,resource>}
     */
    private function serverWithClients(int $count, array $channels = []): array
    {
        $server  = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $clients = [];
        $ends    = [];

        for ($i = 1; $i <= $count; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->sockets[] = $pair[0];
            $this->sockets[] = $pair[1];
            stream_set_blocking($pair[0], false);

            $clients[$i] = [
                'socket'    => $pair[1],
                'state'     => 'connected',
                'buffer'    => '',
                'channels'  => $channels,
                'socketId'  => $i . '.1',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ];
            $ends[$i] = $pair[0];
        }

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        if ($channels !== []) {
            $subs = [];
            foreach ($channels as $channel) {
                $subs[$channel] = array_combine(range(1, $count), range(1, $count));
            }
            (new \ReflectionProperty($server, 'subscriptions'))->setValue($server, $subs);
        }

        return [$server, $ends];
    }

    /** @return list<string> event names received */
    private function received(mixed $end): array
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
            if (is_array($decoded) && isset($decoded['event'])) {
                $events[] = (string) $decoded['event'];
            }
        }

        return $events;
    }

    /**
     * The last member leaving removes the channel's presence bucket entirely.
     *
     * Without this the server accumulates one empty array per room that has ever
     * been occupied — a slow leak in a process designed to run for weeks.
     */
    public function testEmptyPresenceChannelIsDiscarded(): void
    {
        // Arrange
        [$server] = $this->serverWithClients(1);
        (new \ReflectionMethod($server, 'handleSubscribe'))->invoke($server, 1, [
            'channel'      => 'presence-room',
            'auth'         => '',
            'channel_data' => json_encode(['user_id' => '7']),
        ]);

        // Act
        (new \ReflectionMethod($server, 'handleUnsubscribe'))->invoke($server, 1, 'presence-room');

        // Assert
        $presence = (new \ReflectionProperty($server, 'presence'))->getValue($server);
        $this->assertArrayNotHasKey('presence-room', $presence, 'no empty bucket is left behind');
    }

    /**
     * The client-event budget resets when the second turns over.
     *
     * A limit that never resets is not a rate limit, it is a quota — a connection
     * that hit it once would be silent for the rest of its life.
     */
    public function testClientEventBudgetResetsOnANewSecond(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, ['private-room']);
        $server->allowClientEvents(true, 1);

        // Exhaust the budget in a window that has already passed, which is what the
        // server sees one second after a burst.
        (new \ReflectionProperty($server, 'clientEventBudget'))
            ->setValue($server, [1 => [time() - 5, 99]]);
        $this->received($ends[2]);

        // Act
        (new \ReflectionMethod($server, 'handleTextMessage'))->invoke(
            $server,
            1,
            (string) json_encode(['event' => 'client-typing', 'channel' => 'private-room', 'data' => []])
        );

        // Assert
        $this->assertSame(['client-typing'], $this->received($ends[2]), 'the stale window is discarded');
    }

    /**
     * An envelope carrying `except` excludes that connection when the event arrives
     * over the Redis ingest.
     *
     * This is the assertion the whole envelope-key design exists for: the process
     * that published is not this one, so the exclusion had to survive a hop that
     * nothing held in PHP memory survives.
     */
    public function testIngestHonoursTheEnvelopeExclusion(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, ['room']);

        $ingest = new class implements RedisIngestInterface {
            public function connect(): void
            {
            }

            public function getStream()
            {
                return null;
            }

            public function drain(): array
            {
                return [[
                    'channel' => 'room',
                    'message' => (string) json_encode([
                        'event'     => 'message.created',
                        'payload'   => ['id' => 1],
                        'timestamp' => time(),
                        'except'    => '2.1',
                    ]),
                ]];
            }

            public function close(): void
            {
            }
        };

        $server->useRedisIngest($ingest);

        // Act
        (new \ReflectionMethod($server, 'drainRedisIngest'))->invoke($server);

        // Assert
        $this->assertSame(['message.created'], $this->received($ends[1]));
        $this->assertSame([], $this->received($ends[2]), 'the originating connection is skipped');
    }

    /**
     * An envelope with no `except` reaches everyone, so ordinary broadcasts are
     * unaffected by the new key.
     */
    public function testIngestWithoutExclusionReachesEveryone(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, ['room']);

        $ingest = new class implements RedisIngestInterface {
            public function connect(): void
            {
            }

            public function getStream()
            {
                return null;
            }

            public function drain(): array
            {
                return [[
                    'channel' => 'room',
                    'message' => (string) json_encode(['event' => 'e', 'payload' => []]),
                ]];
            }

            public function close(): void
            {
            }
        };

        $server->useRedisIngest($ingest);

        // Act
        (new \ReflectionMethod($server, 'drainRedisIngest'))->invoke($server);

        // Assert
        $this->assertSame(['e'], $this->received($ends[1]));
        $this->assertSame(['e'], $this->received($ends[2]));
    }

    // -------------------------------------------------------------------------
    // The driver envelope
    // -------------------------------------------------------------------------

    /**
     * broadcastExcept() puts `except` in the envelope, and an ordinary broadcast
     * leaves it out entirely.
     *
     * The absence matters as much as the presence: an envelope that always carried
     * the key — even as null — would be a different byte sequence from what this
     * driver has always written, for every existing deployment.
     */
    public function testEnvelopeCarriesTheExclusionOnlyWhenThereIsOne(): void
    {
        // Arrange — capture the envelope by calling the private builder directly,
        // which avoids needing a live Redis for a question about JSON shape.
        $driver   = new RedisDriver([]);
        $envelope = new \ReflectionMethod($driver, 'envelope');
        $except   = new \ReflectionProperty($driver, 'exceptSocketId');

        // Act & Assert — no exclusion
        $plain = $envelope->invoke($driver, 'e', ['a' => 1]);
        $this->assertArrayNotHasKey('except', $plain);
        $this->assertSame(['event', 'payload', 'timestamp'], array_keys($plain));

        // Act & Assert — with one
        $except->setValue($driver, '12.34');
        $this->assertSame('12.34', $envelope->invoke($driver, 'e', [])['except']);

        // Act & Assert — an empty id is not an exclusion
        $except->setValue($driver, '');
        $this->assertArrayNotHasKey('except', $envelope->invoke($driver, 'e', []));
    }

    /**
     * broadcastExcept() clears its exclusion afterwards, even when the publish
     * throws.
     *
     * Otherwise one excluded broadcast leaks its exclusion into the next ordinary
     * one — and in a long-running worker that means dropping events for a
     * connection that had nothing to do with them.
     */
    public function testExclusionDoesNotLeakToTheNextBroadcast(): void
    {
        // Arrange — no Redis available, so broadcast() will fail; that is the point.
        $driver = new RedisDriver([]);
        $except = new \ReflectionProperty($driver, 'exceptSocketId');

        // Act
        try {
            $driver->broadcastExcept('room', 'e', [], '12.34');
        } catch (\Throwable) {
            // The publish failing is expected here and is not what is under test.
        }

        // Assert
        $this->assertNull($except->getValue($driver), 'the exclusion is cleared even on failure');
    }

    /**
     * The stream driver's envelope behaves identically to the pub/sub one.
     *
     * Both are covered rather than one, because the pairing between a publisher and
     * its ingest is exactly where this layer has gone wrong before: an application
     * publishing with XADD while a subscriber listens with SUBSCRIBE is a perfectly
     * healthy subscription that receives nothing. Two envelope shapes would be the
     * same class of silent mismatch.
     */
    public function testStreamDriverEnvelopeMatchesThePubSubOne(): void
    {
        // Arrange
        $driver   = new \Pramnos\Broadcasting\Drivers\RedisStreamDriver([]);
        $envelope = new \ReflectionMethod($driver, 'envelope');
        $except   = new \ReflectionProperty($driver, 'exceptSocketId');

        // Act & Assert — no exclusion
        $plain = $envelope->invoke($driver, 'e', ['a' => 1]);
        $this->assertSame(['event', 'payload', 'timestamp'], array_keys($plain));

        // Act & Assert — with one
        $except->setValue($driver, '12.34');
        $this->assertSame('12.34', $envelope->invoke($driver, 'e', [])['except']);
    }

    /**
     * The stream driver clears its exclusion after a broadcast, failed or not.
     */
    public function testStreamDriverExclusionDoesNotLeak(): void
    {
        // Arrange — no Redis available, so the publish fails; that is the point.
        $driver = new \Pramnos\Broadcasting\Drivers\RedisStreamDriver([]);
        $except = new \ReflectionProperty($driver, 'exceptSocketId');

        // Act
        try {
            $driver->broadcastExcept('room', 'e', [], '12.34');
        } catch (\Throwable) {
            // Expected without a Redis connection, and not what is under test.
        }

        // Assert
        $this->assertNull($except->getValue($driver));
    }
}
