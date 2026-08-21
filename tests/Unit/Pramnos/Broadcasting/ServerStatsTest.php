<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\Webhooks\WebhookDispatcherInterface;

/**
 * The server's counters.
 *
 * The one that justifies the whole set is `client_events_refused`: refusals are
 * silent on the wire by design, so without a counter a client that has been
 * throttled for an hour is indistinguishable from one that is simply quiet. The rest
 * exist so that number has context.
 */
#[CoversClass(LocalBroadcastServer::class)]
class ServerStatsTest extends TestCase
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
    private function server(int $clients, array $channels = []): array
    {
        $server  = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $entries = [];
        $ends    = [];

        for ($i = 1; $i <= $clients; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->sockets[] = $pair[0];
            $this->sockets[] = $pair[1];
            stream_set_blocking($pair[0], false);

            $entries[$i] = [
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

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $entries);

        if ($channels !== []) {
            $subs = [];
            foreach ($channels as $channel) {
                $subs[$channel] = array_combine(range(1, $clients), range(1, $clients));
            }
            (new \ReflectionProperty($server, 'subscriptions'))->setValue($server, $subs);
        }

        return [$server, $ends];
    }

    /**
     * A fresh server reports zeros and a non-negative uptime.
     */
    public function testFreshServerReportsZeros(): void
    {
        // Arrange & Act
        $stats = (new LocalBroadcastServer('key'))->stats();

        // Assert
        $this->assertSame(0, $stats['connections_total']);
        $this->assertSame(0, $stats['connections_current']);
        $this->assertSame(0, $stats['messages_sent']);
        $this->assertGreaterThanOrEqual(0, $stats['uptime_seconds']);
    }

    /**
     * Levels reflect the current subscription state.
     */
    public function testLevelsReflectCurrentState(): void
    {
        // Arrange
        [$server] = $this->server(2, ['ops', 'presence-room']);
        (new \ReflectionProperty($server, 'presence'))
            ->setValue($server, ['presence-room' => [1 => ['user_id' => '7']]]);

        // Act
        $stats = $server->stats();

        // Assert
        $this->assertSame(2, $stats['connections_current']);
        $this->assertSame(2, $stats['channels_occupied']);
        $this->assertSame(4, $stats['subscriptions_current'], 'two clients in two channels');
        $this->assertSame(1, $stats['presence_channels']);
    }

    /**
     * Each delivery increments messages_sent, so the counter measures fan-out rather
     * than calls.
     *
     * One broadcast to a room of three is three messages, and that is the number that
     * matters when the question is what the process is spending its time on.
     */
    public function testMessagesSentCountsDeliveriesNotCalls(): void
    {
        // Arrange
        [$server] = $this->server(3, ['room']);

        // Act
        $server->broadcast('room', 'e', []);

        // Assert
        $this->assertSame(3, $server->stats()['messages_sent']);
    }

    /**
     * An excluded connection is not counted, because nothing was sent to it.
     */
    public function testExcludedConnectionIsNotCounted(): void
    {
        // Arrange
        [$server] = $this->server(3, ['room']);

        // Act
        $server->broadcastExcept('room', 'e', [], '2.1');

        // Assert
        $this->assertSame(2, $server->stats()['messages_sent']);
    }

    /**
     * Relayed and refused client events are counted separately.
     *
     * This is the pair that makes a silent rate limit visible.
     */
    public function testClientEventsAreCountedByOutcome(): void
    {
        // Arrange
        [$server] = $this->server(2, ['private-room']);
        $server->allowClientEvents(true, 1);
        $send = new \ReflectionMethod($server, 'handleTextMessage');
        $body = (string) json_encode(['event' => 'client-x', 'channel' => 'private-room', 'data' => []]);

        // Act — one within budget, one over it, one on a public channel
        $send->invoke($server, 1, $body);
        $send->invoke($server, 1, $body);
        $send->invoke($server, 1, (string) json_encode([
            'event' => 'client-x', 'channel' => 'public-room', 'data' => [],
        ]));

        // Assert
        $stats = $server->stats();
        $this->assertSame(1, $stats['client_events_relayed']);
        $this->assertSame(2, $stats['client_events_refused']);
    }

    /**
     * With client events disabled, an attempt is counted as a refusal.
     *
     * A deployment seeing a rising refusal count with the feature off is being told
     * something useful: a client is trying to whisper and nobody has enabled it.
     */
    public function testAttemptsAreCountedWhenTheFeatureIsOff(): void
    {
        // Arrange
        [$server] = $this->server(2, ['private-room']);

        // Act
        (new \ReflectionMethod($server, 'handleTextMessage'))->invoke(
            $server,
            1,
            (string) json_encode(['event' => 'client-x', 'channel' => 'private-room', 'data' => []])
        );

        // Assert
        $this->assertSame(1, $server->stats()['client_events_refused']);
        $this->assertSame(0, $server->stats()['client_events_relayed']);
    }

    /**
     * Webhook events are counted as they are collected, and only when a dispatcher
     * is installed.
     */
    public function testWebhookEventsAreCountedOnlyWhenListening(): void
    {
        // Arrange
        [$withoutDispatcher] = $this->server(1);
        [$withDispatcher]    = $this->server(1);

        $withDispatcher->useWebhooks(new class implements WebhookDispatcherInterface {
            public function dispatch(array $events): void
            {
            }
        });

        $subscribe = new \ReflectionMethod(LocalBroadcastServer::class, 'handleSubscribe');

        // Act
        $subscribe->invoke($withoutDispatcher, 1, ['channel' => 'ops', 'auth' => '']);
        $subscribe->invoke($withDispatcher, 1, ['channel' => 'ops', 'auth' => '']);

        // Assert
        $this->assertSame(0, $withoutDispatcher->stats()['webhook_events_queued']);
        $this->assertSame(1, $withDispatcher->stats()['webhook_events_queued']);
    }

    /**
     * connections_total is cumulative and survives a disconnect, while
     * connections_current does not.
     *
     * The pair is the point: "twelve connected" says nothing about whether four
     * thousand have come and gone in the last minute.
     */
    public function testTotalIsCumulativeAndCurrentIsNot(): void
    {
        // Arrange
        $server   = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener);
        $this->sockets[] = $listener;
        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);
        $accept = new \ReflectionMethod($server, 'acceptClient');

        // Act — two connections accepted, then both dropped
        foreach ([1, 2] as $unused) {
            $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
            $this->assertNotFalse($client);
            $this->sockets[] = $client;
            $accept->invoke($server);
        }

        $this->assertSame(2, $server->stats()['connections_current']);

        $disconnect = new \ReflectionMethod($server, 'disconnectClient');
        foreach (array_keys((new \ReflectionProperty($server, 'clients'))->getValue($server)) as $id) {
            $disconnect->invoke($server, $id);
        }

        // Assert
        $stats = $server->stats();
        $this->assertSame(0, $stats['connections_current']);
        $this->assertSame(2, $stats['connections_total'], 'the total does not go down');
    }
}
