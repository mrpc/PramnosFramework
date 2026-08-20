<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * Presence channels: the member list, the arrival and departure announcements, and
 * the deduplication that makes them correct for a user with more than one tab open.
 *
 * Before this, `presence-` channels authenticated correctly and then dropped the
 * member data on the floor: `subscription_succeeded` carried `'{}'` and no
 * `member_added` was ever sent, so `here()` / `joining()` / `leaving()` could not
 * work at all.
 */
#[CoversClass(LocalBroadcastServer::class)]
#[CoversClass(PusherAuthorizer::class)]
#[CoversClass(AllowAllAuthorizer::class)]
class PresenceChannelTest extends TestCase
{
    private const KEY    = 'test-key';
    private const SECRET = 'test-secret';

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

    /**
     * A server with N connected clients, all in the 'connected' state.
     *
     * @return array{0:LocalBroadcastServer, 1:list<resource>} [server, client ends]
     */
    private function serverWithClients(int $count, bool $pusherAuth = true): array
    {
        $server = new LocalBroadcastServer(self::KEY, null, $pusherAuth
            ? new PusherAuthorizer(self::KEY, self::SECRET)
            : new AllowAllAuthorizer());

        $clients    = [];
        $clientEnds = [];

        for ($i = 1; $i <= $count; $i++) {
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
            $clientEnds[$i] = $pair[0];
        }

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        return [$server, $clientEnds];
    }

    /** The signed channel_data + auth a client would have got from the endpoint. */
    private function signedSubscribe(string $socketId, string $channel, array $member): array
    {
        $channelData = (string) json_encode($member);
        $auth        = self::KEY . ':' . hash_hmac(
            'sha256',
            $socketId . ':' . $channel . ':' . $channelData,
            self::SECRET
        );

        return ['channel' => $channel, 'auth' => $auth, 'channel_data' => $channelData];
    }

    private function subscribe(LocalBroadcastServer $server, int $clientId, array $data): void
    {
        (new \ReflectionMethod($server, 'handleSubscribe'))->invoke($server, $clientId, $data);
    }

    /** Decode every WebSocket frame waiting on a client end. */
    private function framesFor(mixed $clientEnd): array
    {
        $raw    = (string) fread($clientEnd, 65536);
        $events = [];

        while ($raw !== '') {
            $frame = FrameCodec::decode($raw);
            if ($frame === null) {
                break;
            }
            $raw = substr($raw, $frame['consumed']);

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

    private function eventNamed(array $events, string $name): ?array
    {
        foreach ($events as $event) {
            if (($event['event'] ?? '') === $name) {
                return $event;
            }
        }

        return null;
    }

    /**
     * subscription_succeeded carries the member list, including the subscriber.
     *
     * Including itself is per the protocol: a client that had to add itself would
     * show a different room to the person who just joined than to everyone already
     * in it.
     */
    public function testSubscriptionSucceededCarriesTheMemberList(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(1);

        // Act
        $this->subscribe($server, 1, $this->signedSubscribe(
            '1.1',
            'presence-room',
            ['user_id' => '7', 'user_info' => ['name' => 'Ada']]
        ));

        // Assert
        $succeeded = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:subscription_succeeded');
        $this->assertNotNull($succeeded);
        $this->assertSame(['7'], $succeeded['data']['presence']['ids'], 'ids are strings on the wire');
        $this->assertSame(1, $succeeded['data']['presence']['count']);
        $this->assertSame(['name' => 'Ada'], $succeeded['data']['presence']['hash']['7']);
    }

    /**
     * A second user's arrival reaches the first, and not itself.
     *
     * The exclusion is what makes it an announcement: the joiner already has itself
     * in the list it was just sent.
     */
    public function testMemberAddedReachesOthersAndNotTheJoiner(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));
        $this->framesFor($ends[1]);                        // drain client 1's own success

        // Act
        $this->subscribe($server, 2, $this->signedSubscribe(
            '2.1',
            'presence-room',
            ['user_id' => '9', 'user_info' => ['name' => 'Grace']]
        ));

        // Assert
        $added = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:member_added');
        $this->assertNotNull($added, 'the existing member must be told');
        $this->assertSame('9', $added['data']['user_id']);
        $this->assertSame(['name' => 'Grace'], $added['data']['user_info']);

        $this->assertNull(
            $this->eventNamed($this->framesFor($ends[2]), 'pusher_internal:member_added'),
            'the joiner must not be told it arrived'
        );
    }

    /**
     * The second subscriber's own list contains both members.
     */
    public function testJoinerSeesEveryoneAlreadyPresent(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));

        // Act
        $this->subscribe($server, 2, $this->signedSubscribe('2.1', 'presence-room', ['user_id' => '9']));

        // Assert
        $succeeded = $this->eventNamed($this->framesFor($ends[2]), 'pusher_internal:subscription_succeeded');
        $this->assertEqualsCanonicalizing(['7', '9'], $succeeded['data']['presence']['ids']);
        $this->assertSame(2, $succeeded['data']['presence']['count']);
    }

    /**
     * A user with two connections is one member, and the second connection does
     * not announce an arrival.
     *
     * Two tabs is the ordinary case, and counting connections instead of people
     * shows a room of one person as a room of three.
     */
    public function testSecondConnectionOfTheSameUserIsNotANewMember(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));
        $this->framesFor($ends[1]);

        // Act — the same user, a different socket
        $this->subscribe($server, 2, $this->signedSubscribe('2.1', 'presence-room', ['user_id' => '7']));

        // Assert
        $this->assertSame(['7' => []], $server->presenceMembers('presence-room'));
        $this->assertNull(
            $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:member_added'),
            'a second tab must not announce an arrival'
        );
    }

    /**
     * Unsubscribing announces the departure to the others.
     */
    public function testUnsubscribeAnnouncesDeparture(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));
        $this->subscribe($server, 2, $this->signedSubscribe('2.1', 'presence-room', ['user_id' => '9']));
        $this->framesFor($ends[1]);

        // Act
        (new \ReflectionMethod($server, 'handleUnsubscribe'))->invoke($server, 2, 'presence-room');

        // Assert
        $removed = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:member_removed');
        $this->assertNotNull($removed);
        $this->assertSame('9', $removed['data']['user_id']);
        $this->assertSame(['7' => []], $server->presenceMembers('presence-room'));
    }

    /**
     * Closing one of a user's two connections does not announce a departure.
     *
     * Announcing per connection reports somebody as having left a room they are
     * still sitting in — the mirror of the two-tab arrival case, and the one that
     * shows up as members flickering out of a list.
     */
    public function testClosingOneOfTwoConnectionsDoesNotAnnounceDeparture(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(3);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));
        $this->subscribe($server, 2, $this->signedSubscribe('2.1', 'presence-room', ['user_id' => '7']));
        $this->subscribe($server, 3, $this->signedSubscribe('3.1', 'presence-room', ['user_id' => '9']));
        $this->framesFor($ends[3]);

        // Act — one of user 7's two connections goes
        (new \ReflectionMethod($server, 'disconnectClient'))->invoke($server, 2);

        // Assert
        $this->assertNull(
            $this->eventNamed($this->framesFor($ends[3]), 'pusher_internal:member_removed'),
            'a user with another connection has not left'
        );
        $this->assertArrayHasKey('7', $server->presenceMembers('presence-room'));

        // Act — and now the last one
        (new \ReflectionMethod($server, 'disconnectClient'))->invoke($server, 1);

        // Assert
        $removed = $this->eventNamed($this->framesFor($ends[3]), 'pusher_internal:member_removed');
        $this->assertNotNull($removed, 'the last connection leaving is a departure');
        $this->assertSame('7', $removed['data']['user_id']);
    }

    /**
     * A disconnect announces the departure, i.e. a client that vanishes is not left
     * in the room forever.
     */
    public function testDisconnectAnnouncesDeparture(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);
        $this->subscribe($server, 1, $this->signedSubscribe('1.1', 'presence-room', ['user_id' => '7']));
        $this->subscribe($server, 2, $this->signedSubscribe('2.1', 'presence-room', ['user_id' => '9']));
        $this->framesFor($ends[1]);

        // Act
        (new \ReflectionMethod($server, 'disconnectClient'))->invoke($server, 2);

        // Assert
        $this->assertNotNull($this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:member_removed'));
        $this->assertSame(['7' => []], $server->presenceMembers('presence-room'));
    }

    /**
     * A presence subscription with no channel_data succeeds but is unlisted.
     *
     * Refusing it would break a client that only wants to receive the channel's
     * events, and inventing an identity for it would put an anonymous entry in
     * everybody's member list.
     */
    public function testPresenceWithoutMemberDataSubscribesButIsUnlisted(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(1);
        $auth = self::KEY . ':' . hash_hmac('sha256', '1.1:presence-room', self::SECRET);

        // Act
        $this->subscribe($server, 1, ['channel' => 'presence-room', 'auth' => $auth]);

        // Assert
        $succeeded = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:subscription_succeeded');
        $this->assertNotNull($succeeded, 'the subscription still succeeds');
        $this->assertSame([], $succeeded['data'], 'and carries no member list');
        $this->assertSame([], $server->presenceMembers('presence-room'));
    }

    /**
     * A private channel is unaffected: no member list, no announcements.
     *
     * This is the BC assertion. `subscription_succeeded` for anything that is not a
     * presence channel must carry `'{}'` exactly as before.
     */
    public function testPrivateChannelIsUnchanged(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(1);
        $auth = self::KEY . ':' . hash_hmac('sha256', '1.1:private-room', self::SECRET);

        // Act
        $this->subscribe($server, 1, ['channel' => 'private-room', 'auth' => $auth]);

        // Assert
        $succeeded = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:subscription_succeeded');
        $this->assertSame([], $succeeded['data']);
        $this->assertSame([], $server->presenceMembers('private-room'));
    }

    /**
     * With an authorizer that does not implement PresenceAuthorizer, presence
     * degrades to the historical behaviour rather than failing.
     *
     * This is the compatibility promise for the deployments the Realtime guide
     * invited to write their own authorizer: they keep working, with no membership,
     * until they opt in.
     */
    public function testAuthorizerWithoutPresenceSupportDegrades(): void
    {
        // Arrange — a bespoke authorizer implementing only the older interface
        $legacy = new class implements \Pramnos\Broadcasting\Auth\ConnectionAuthorizer {
            public function authorizeConnection(string $appKey, array $params = []): bool
            {
                return true;
            }

            public function authorizeChannel(
                string $channel,
                string $socketId,
                string $auth,
                ?string $channelData = null
            ): bool {
                return true;
            }
        };

        [$server, $ends] = $this->serverWithClients(1);
        $server->useAuthorizer($legacy);

        // Act
        $this->subscribe($server, 1, [
            'channel'      => 'presence-room',
            'auth'         => 'anything',
            'channel_data' => json_encode(['user_id' => '7']),
        ]);

        // Assert
        $succeeded = $this->eventNamed($this->framesFor($ends[1]), 'pusher_internal:subscription_succeeded');
        $this->assertSame([], $succeeded['data'], 'no membership without opting in');
        $this->assertSame([], $server->presenceMembers('presence-room'));
    }

    /**
     * The permissive dev authorizer supports presence, so a developer is not
     * debugging an empty member list caused by the default.
     */
    public function testAllowAllAuthorizerSupportsPresenceForLocalDevelopment(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(1, pusherAuth: false);

        // Act
        $this->subscribe($server, 1, [
            'channel'      => 'presence-room',
            'auth'         => '',
            'channel_data' => json_encode(['user_id' => '3', 'user_info' => ['name' => 'Dev']]),
        ]);

        // Assert
        $this->assertSame(['3' => ['name' => 'Dev']], $server->presenceMembers('presence-room'));
    }

    /**
     * Member data that carries no usable identity is ignored rather than trusted.
     *
     * Each of these would otherwise put a member with an empty or missing id in
     * every subscriber's list, where it cannot be matched, removed, or reasoned
     * about.
     */
    public function testUnusableMemberDataIsIgnored(): void
    {
        foreach (['not json', '[]', '{}', '{"user_id":""}', '{"user_info":{"a":1}}'] as $channelData) {
            // Arrange
            [$server] = $this->serverWithClients(1, pusherAuth: false);

            // Act
            $this->subscribe($server, 1, [
                'channel'      => 'presence-room',
                'auth'         => '',
                'channel_data' => $channelData,
            ]);

            // Assert
            $this->assertSame(
                [],
                $server->presenceMembers('presence-room'),
                'channel_data: ' . $channelData
            );
        }
    }

    /**
     * A numeric user_id in the signed data becomes a string member id.
     *
     * A client comparing the member id to its own gets `7 !== "7"` otherwise, which
     * presents as a member who is in the room but never recognised as "me".
     */
    public function testNumericUserIdBecomesAString(): void
    {
        // Arrange
        [$server] = $this->serverWithClients(1, pusherAuth: false);

        // Act
        $this->subscribe($server, 1, [
            'channel'      => 'presence-room',
            'auth'         => '',
            'channel_data' => json_encode(['user_id' => 7]),
        ]);

        // Assert — the wire form is what matters, and PHP array keys cannot carry
        // it: a numeric string key becomes an int, so presenceMembers() is checked
        // through the payload the client actually receives.
        $payload = (new \ReflectionMethod($server, 'presencePayload'))->invoke($server, 'presence-room');
        $this->assertSame(['7'], $payload['presence']['ids'], 'ids must serialise as strings');
    }
}
