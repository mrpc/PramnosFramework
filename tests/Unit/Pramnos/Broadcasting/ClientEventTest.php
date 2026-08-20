<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * Browser-to-browser `client-*` events.
 *
 * Before this, `handleTextMessage()` switched on exactly three event names and a
 * `client-typing` from a browser fell into the void — so the whole category of
 * typing indicators, cursors and transient cues was unreachable, which is the one
 * thing a WebSocket can carry that SSE cannot.
 *
 * The tests are weighted towards refusals, because enabling this grants every
 * connected browser a write path onto a channel. Each guard below is the difference
 * between a relay and an open publish endpoint.
 */
#[CoversClass(LocalBroadcastServer::class)]
class ClientEventTest extends TestCase
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

    /**
     * @return array{0:LocalBroadcastServer, 1:array<int,resource>}
     */
    private function serverWithClients(int $count, bool $allowClientEvents, int $perSecond = 10): array
    {
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        if ($allowClientEvents) {
            $server->allowClientEvents(true, $perSecond);
        }

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
                'channels'  => [],
                'socketId'  => $i . '.1',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ];
            $ends[$i] = $pair[0];
        }

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        return [$server, $ends];
    }

    private function subscribe(LocalBroadcastServer $server, int $clientId, string $channel): void
    {
        (new \ReflectionMethod($server, 'handleSubscribe'))
            ->invoke($server, $clientId, ['channel' => $channel, 'auth' => '']);
    }

    /** Feed a decoded client message through the text-message handler. */
    private function send(LocalBroadcastServer $server, int $clientId, array $message): void
    {
        (new \ReflectionMethod($server, 'handleTextMessage'))
            ->invoke($server, $clientId, (string) json_encode($message));
    }

    /** @return list<array<string,mixed>> */
    private function framesFor(mixed $end): array
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
                $events[] = $decoded;
            }
        }

        return $events;
    }

    private function clientEvents(array $frames): array
    {
        return array_values(array_filter(
            $frames,
            static fn (array $f): bool => str_starts_with((string) ($f['event'] ?? ''), 'client-')
        ));
    }

    /**
     * With the feature on, a client event reaches the channel's other subscribers.
     */
    public function testRelaysToOtherSubscribers(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[1]);
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, [
            'event'   => 'client-typing',
            'channel' => 'private-room',
            'data'    => ['user' => 'Ada'],
        ]);

        // Assert
        $received = $this->clientEvents($this->framesFor($ends[2]));
        $this->assertCount(1, $received);
        $this->assertSame('client-typing', $received[0]['event']);
        $this->assertSame('private-room', $received[0]['channel']);
        $this->assertSame(['user' => 'Ada'], json_decode($received[0]['data'], true));
    }

    /**
     * The sender does not receive its own event.
     *
     * Echoing is how a client renders its own cursor twice, and it already knows
     * what it typed.
     */
    public function testDoesNotEchoToTheSender(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[1]);

        // Act
        $this->send($server, 1, ['event' => 'client-typing', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $this->assertSame([], $this->clientEvents($this->framesFor($ends[1])));
    }

    /**
     * With the feature off — the default — nothing is relayed.
     *
     * This is the compatibility and security assertion in one: an installation that
     * merely updates the framework must not acquire a client-to-client write path.
     */
    public function testDisabledByDefault(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: false);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, ['event' => 'client-typing', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $this->assertSame([], $this->clientEvents($this->framesFor($ends[2])));
    }

    /**
     * A public channel never relays client events.
     *
     * A public channel has no membership test at all, so relaying on one would let
     * any connection publish to every listener — an open write path dressed as a
     * feature.
     */
    public function testRefusesPublicChannels(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'updates');
        $this->subscribe($server, 2, 'updates');
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, ['event' => 'client-typing', 'channel' => 'updates', 'data' => []]);

        // Assert
        $this->assertSame([], $this->clientEvents($this->framesFor($ends[2])));
    }

    /**
     * A sender that is not subscribed cannot publish to the channel.
     *
     * The subscription is the only proof of authorization the daemon holds — it
     * verified a signature to grant it. Without this check a connection could
     * publish into any channel it can name, having never been authorized for it.
     */
    public function testRefusesSenderThatIsNotSubscribed(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 2, 'private-room');       // client 1 stays out
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, ['event' => 'client-typing', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $this->assertSame([], $this->clientEvents($this->framesFor($ends[2])));
    }

    /**
     * A presence channel relays client events too.
     */
    public function testRelaysOnPresenceChannels(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'presence-room');
        $this->subscribe($server, 2, 'presence-room');
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, ['event' => 'client-cursor', 'channel' => 'presence-room', 'data' => ['x' => 1]]);

        // Assert
        $this->assertCount(1, $this->clientEvents($this->framesFor($ends[2])));
    }

    /**
     * An event without the `client-` prefix is not treated as one.
     *
     * The prefix is the protocol's only marker for "this came from a browser", so a
     * connection must not be able to inject an application event name.
     */
    public function testIgnoresEventsWithoutTheClientPrefix(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[2]);

        // Act — an application event name, sent by a browser
        $this->send($server, 1, ['event' => 'order.paid', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $this->assertSame([], $this->framesFor($ends[2]), 'nothing is relayed');
    }

    /**
     * A connection is held to its per-second budget.
     *
     * Without one, a single browser can drive the fan-out loop as fast as it can
     * write — and the fan-out is per subscriber, so the cost is multiplied by the
     * size of the room.
     */
    public function testEnforcesThePerSecondBudget(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true, perSecond: 3);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[2]);

        // Act — five sends against a budget of three
        for ($i = 0; $i < 5; $i++) {
            $this->send($server, 1, [
                'event'   => 'client-typing',
                'channel' => 'private-room',
                'data'    => ['n' => $i],
            ]);
        }

        // Assert
        $this->assertCount(3, $this->clientEvents($this->framesFor($ends[2])));
    }

    /**
     * The budget is per connection, not per server: one noisy client must not
     * silence another.
     */
    public function testBudgetIsPerConnection(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(3, allowClientEvents: true, perSecond: 1);
        foreach ([1, 2, 3] as $id) {
            $this->subscribe($server, $id, 'private-room');
        }
        $this->framesFor($ends[3]);

        // Act — client 1 exhausts its own budget, then client 2 sends
        $this->send($server, 1, ['event' => 'client-a', 'channel' => 'private-room', 'data' => []]);
        $this->send($server, 1, ['event' => 'client-a', 'channel' => 'private-room', 'data' => []]);
        $this->send($server, 2, ['event' => 'client-b', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $names = array_column($this->clientEvents($this->framesFor($ends[3])), 'event');
        $this->assertSame(['client-a', 'client-b'], $names, "client 1's limit must not affect client 2");
    }

    /**
     * A string payload is forwarded as-is rather than re-encoded.
     *
     * Re-encoding an already-encoded body produces a double-escaped string on the
     * far side, which decodes to a string instead of an object.
     */
    public function testForwardsAStringPayloadUnchanged(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: true);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, [
            'event'   => 'client-typing',
            'channel' => 'private-room',
            'data'    => '{"already":"encoded"}',
        ]);

        // Assert
        $received = $this->clientEvents($this->framesFor($ends[2]));
        $this->assertSame('{"already":"encoded"}', $received[0]['data']);
    }

    /**
     * A refused client event draws no reply.
     *
     * Answering each rejection would hand a browser a cheap way to make the server
     * talk, which is the opposite of what a rate limit is for.
     */
    public function testRefusalsAreSilent(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(1, allowClientEvents: true, perSecond: 1);
        $this->subscribe($server, 1, 'private-room');
        $this->framesFor($ends[1]);

        // Act — one over budget, and one on a channel it is not in
        $this->send($server, 1, ['event' => 'client-x', 'channel' => 'private-room', 'data' => []]);
        $this->send($server, 1, ['event' => 'client-x', 'channel' => 'private-room', 'data' => []]);
        $this->send($server, 1, ['event' => 'client-x', 'channel' => 'private-other', 'data' => []]);

        // Assert
        $this->assertSame([], $this->framesFor($ends[1]), 'no error frames come back');
    }

    /**
     * allowClientEvents() clamps a nonsensical budget to at least one, so a
     * mistyped zero does not silently disable a feature the operator just enabled.
     */
    public function testBudgetIsClampedToAtLeastOne(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2, allowClientEvents: false);
        $server->allowClientEvents(true, 0);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->framesFor($ends[2]);

        // Act
        $this->send($server, 1, ['event' => 'client-x', 'channel' => 'private-room', 'data' => []]);

        // Assert
        $this->assertCount(1, $this->clientEvents($this->framesFor($ends[2])));
    }
}
