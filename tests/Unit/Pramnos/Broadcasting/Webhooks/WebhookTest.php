<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Webhooks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\Webhooks\QueueWebhookDispatcher;
use Pramnos\Broadcasting\Webhooks\WebhookDispatcherInterface;
use Pramnos\Broadcasting\Webhooks\WebhookSigner;

/**
 * Lifecycle webhooks: the five events, their batching, and the signing that lets a
 * receiver trust them.
 *
 * These are how an application learns things it otherwise cannot — that a room is
 * empty and its state can be torn down, that a user's last connection went away.
 * The only previous route was polling from an `onTick` callback, which counts
 * channels rather than observing transitions and fires on a timer rather than on
 * the event.
 */
#[CoversClass(LocalBroadcastServer::class)]
#[CoversClass(WebhookSigner::class)]
#[CoversClass(QueueWebhookDispatcher::class)]
class WebhookTest extends TestCase
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

    /** A dispatcher that records the batches it is handed. */
    private function recorder(): WebhookDispatcherInterface
    {
        return new class implements WebhookDispatcherInterface {
            /** @var list<list<array<string,mixed>>> */
            public array $batches = [];

            public function dispatch(array $events): void
            {
                $this->batches[] = $events;
            }
        };
    }

    /**
     * @return array{0:LocalBroadcastServer, 1:array<int,resource>}
     */
    private function server(int $clientCount, ?WebhookDispatcherInterface $dispatcher = null): array
    {
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        if ($dispatcher !== null) {
            $server->useWebhooks($dispatcher);
        }

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

        return [$server, $ends];
    }

    private function subscribe(LocalBroadcastServer $server, int $id, string $channel, array $extra = []): void
    {
        (new \ReflectionMethod($server, 'handleSubscribe'))
            ->invoke($server, $id, array_merge(['channel' => $channel, 'auth' => ''], $extra));
    }

    private function flush(LocalBroadcastServer $server): void
    {
        (new \ReflectionMethod($server, 'flushWebhooks'))->invoke($server);
    }

    /** Flatten every dispatched batch into one list of event names. */
    private function names(object $recorder): array
    {
        $names = [];
        foreach ($recorder->batches as $batch) {
            foreach ($batch as $event) {
                $names[] = $event['name'];
            }
        }

        return $names;
    }

    // -------------------------------------------------------------------------
    // The five events
    // -------------------------------------------------------------------------

    /**
     * The first subscriber occupies a channel; later ones do not.
     *
     * Occupancy is a transition, and it is read before the subscription is
     * recorded — asking afterwards would report every subscribe as an arrival into
     * an already-occupied channel, i.e. never fire at all.
     */
    public function testFirstSubscriberOccupiesTheChannel(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(2, $recorder);

        // Act
        $this->subscribe($server, 1, 'ops');
        $this->subscribe($server, 2, 'ops');
        $this->flush($server);

        // Assert
        $this->assertSame(['channel_occupied'], $this->names($recorder));
        $this->assertSame('ops', $recorder->batches[0][0]['channel']);
    }

    /**
     * The last unsubscriber vacates it, and an earlier one does not.
     */
    public function testLastUnsubscriberVacatesTheChannel(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(2, $recorder);
        $this->subscribe($server, 1, 'ops');
        $this->subscribe($server, 2, 'ops');
        $this->flush($server);
        $recorder->batches = [];

        // Act
        $unsubscribe = new \ReflectionMethod($server, 'handleUnsubscribe');
        $unsubscribe->invoke($server, 1, 'ops');
        $this->flush($server);
        $unsubscribe->invoke($server, 2, 'ops');
        $this->flush($server);

        // Assert
        $this->assertSame(['channel_vacated'], $this->names($recorder));
    }

    /**
     * A disconnect vacates every channel that connection was alone in, in one
     * batch.
     *
     * This is why the events are batched: one action produces several.
     */
    public function testDisconnectVacatesEveryChannelInOneBatch(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(1, $recorder);
        foreach (['a', 'b', 'c'] as $channel) {
            $this->subscribe($server, 1, $channel);
        }
        $this->flush($server);
        $recorder->batches = [];

        // Act
        (new \ReflectionMethod($server, 'disconnectClient'))->invoke($server, 1);
        $this->flush($server);

        // Assert
        $this->assertCount(1, $recorder->batches, 'one hand-off, not three');
        $this->assertSame(
            ['channel_vacated', 'channel_vacated', 'channel_vacated'],
            $this->names($recorder)
        );
        $this->assertEqualsCanonicalizing(
            ['a', 'b', 'c'],
            array_column($recorder->batches[0], 'channel')
        );
    }

    /**
     * Presence arrivals and departures emit member events carrying the user id.
     */
    public function testMemberAddedAndRemovedCarryTheUserId(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(1, $recorder);

        // Act
        $this->subscribe($server, 1, 'presence-room', [
            'channel_data' => json_encode(['user_id' => '7']),
        ]);
        $this->flush($server);
        (new \ReflectionMethod($server, 'handleUnsubscribe'))->invoke($server, 1, 'presence-room');
        $this->flush($server);

        // Assert
        $this->assertSame(
            ['channel_occupied', 'member_added', 'member_removed', 'channel_vacated'],
            $this->names($recorder)
        );
        $this->assertSame('7', $recorder->batches[0][1]['user_id']);
    }

    /**
     * A user's second connection produces no member event, and closing it produces
     * none either.
     *
     * The webhooks follow the same people-not-sockets rule as the wire
     * announcements: an application tearing down state on `member_removed` must not
     * be told somebody left because they closed one of two tabs.
     */
    public function testSecondConnectionOfAUserProducesNoMemberEvents(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(2, $recorder);
        $member   = ['channel_data' => json_encode(['user_id' => '7'])];
        $this->subscribe($server, 1, 'presence-room', $member);
        $this->flush($server);
        $recorder->batches = [];

        // Act
        $this->subscribe($server, 2, 'presence-room', $member);
        $this->flush($server);
        (new \ReflectionMethod($server, 'handleUnsubscribe'))->invoke($server, 2, 'presence-room');
        $this->flush($server);

        // Assert
        $this->assertSame([], $this->names($recorder));
    }

    /**
     * A relayed client event is reported, with its name, payload and sender.
     *
     * The sender's socket id is included because it is the only thing that
     * distinguishes two whispers from one user's two tabs.
     */
    public function testClientEventIsReported(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(2, $recorder);
        $server->allowClientEvents(true);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->flush($server);
        $recorder->batches = [];

        // Act
        (new \ReflectionMethod($server, 'handleTextMessage'))->invoke(
            $server,
            1,
            (string) json_encode([
                'event'   => 'client-typing',
                'channel' => 'private-room',
                'data'    => ['user' => 'Ada'],
            ])
        );
        $this->flush($server);

        // Assert
        $event = $recorder->batches[0][0];
        $this->assertSame('client_event', $event['name']);
        $this->assertSame('client-typing', $event['event']);
        $this->assertSame('private-room', $event['channel']);
        $this->assertSame('1.1', $event['socket_id']);
        $this->assertSame(['user' => 'Ada'], json_decode($event['data'], true));
    }

    /**
     * A refused client event is not reported.
     *
     * Reporting one would tell an application that a whisper happened when nothing
     * was relayed — and a rate-limited sender would generate webhook traffic
     * precisely when the point was to stop generating traffic.
     */
    public function testRefusedClientEventIsNotReported(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(2, $recorder);
        $server->allowClientEvents(true, 1);
        $this->subscribe($server, 1, 'private-room');
        $this->subscribe($server, 2, 'private-room');
        $this->flush($server);
        $recorder->batches = [];

        $send = new \ReflectionMethod($server, 'handleTextMessage');
        $body = (string) json_encode(['event' => 'client-x', 'channel' => 'private-room', 'data' => []]);

        // Act — one within budget, one over, one on a channel the sender is not in
        $send->invoke($server, 1, $body);
        $send->invoke($server, 1, $body);
        $send->invoke($server, 1, (string) json_encode([
            'event' => 'client-x', 'channel' => 'private-elsewhere', 'data' => [],
        ]));
        $this->flush($server);

        // Assert
        $this->assertSame(['client_event'], $this->names($recorder), 'only the relayed one');
    }

    // -------------------------------------------------------------------------
    // Dispatch behaviour
    // -------------------------------------------------------------------------

    /**
     * With no dispatcher installed nothing is collected.
     *
     * The guard is what keeps webhooks free for deployments that do not use them:
     * no array grows, and there is no per-subscribe work.
     */
    public function testNothingIsCollectedWithoutADispatcher(): void
    {
        // Arrange
        [$server] = $this->server(1);
        $pending  = new \ReflectionProperty($server, 'pendingWebhooks');

        // Act
        $this->subscribe($server, 1, 'ops');

        // Assert
        $this->assertSame([], $pending->getValue($server));
    }

    /**
     * A dispatcher that throws does not take the server with it, and its batch is
     * not re-sent forever.
     *
     * The buffer is cleared before dispatching precisely so one unreachable
     * endpoint cannot turn into an unbounded resend loop, growing by every
     * subsequent event.
     */
    public function testAThrowingDispatcherIsContainedAndTheBatchIsNotRetriedForever(): void
    {
        // Arrange
        $dispatcher = new class implements WebhookDispatcherInterface {
            public int $calls = 0;

            public function dispatch(array $events): void
            {
                $this->calls++;
                throw new \RuntimeException('endpoint down');
            }
        };

        [$server] = $this->server(1, $dispatcher);
        $this->subscribe($server, 1, 'ops');

        // Act
        $this->flush($server);
        $this->flush($server);

        // Assert
        $this->assertSame(1, $dispatcher->calls, 'the failed batch is dropped, not replayed');
        $this->assertSame([], (new \ReflectionProperty($server, 'pendingWebhooks'))->getValue($server));
    }

    /**
     * Flushing with nothing pending does not call the dispatcher.
     */
    public function testEmptyFlushIsANoOp(): void
    {
        // Arrange
        $recorder = $this->recorder();
        [$server] = $this->server(1, $recorder);

        // Act
        $this->flush($server);

        // Assert
        $this->assertSame([], $recorder->batches);
    }

    // -------------------------------------------------------------------------
    // Signing
    // -------------------------------------------------------------------------

    /**
     * A signed body round-trips through verify().
     */
    public function testSignedBodyVerifies(): void
    {
        // Arrange
        $signer = new WebhookSigner(new BroadcastApp('k', 's'));
        $body   = $signer->body([['name' => 'channel_occupied', 'channel' => 'ops']], 1_700_000_000_000);

        // Act
        $headers = $signer->headers($body);

        // Assert
        $this->assertSame('k', $headers['X-Pusher-Key']);
        $this->assertTrue($signer->verify($body, $headers['X-Pusher-Signature']));
    }

    /**
     * The body carries a timestamp and the events, so a receiver can discard a
     * delivery whose meaning has expired — a `member_added` from four minutes ago
     * is not news.
     */
    public function testBodyCarriesTimeAndEvents(): void
    {
        // Arrange
        $signer = new WebhookSigner(new BroadcastApp('k', 's'));

        // Act
        $decoded = json_decode($signer->body([['name' => 'x']], 1_700_000_000_000), true);

        // Assert
        $this->assertSame(1_700_000_000_000, $decoded['time_ms']);
        $this->assertSame([['name' => 'x']], $decoded['events']);
    }

    /**
     * A tampered body does not verify, and neither does a body verified against
     * another app's secret.
     */
    public function testTamperedOrForeignBodyDoesNotVerify(): void
    {
        // Arrange
        $signer  = new WebhookSigner(new BroadcastApp('k', 's'));
        $other   = new WebhookSigner(new BroadcastApp('k', 'different-secret'));
        $body    = $signer->body([['name' => 'x']], 1);
        $good    = $signer->headers($body)['X-Pusher-Signature'];

        // Act & Assert
        $this->assertFalse($signer->verify($body . ' ', $good), 'a changed body must not verify');
        $this->assertFalse($other->verify($body, $good), 'another secret must not verify');
        $this->assertFalse($signer->verify($body, ''), 'an empty signature must not verify');
    }

    /**
     * An app with no secret cannot verify anything.
     *
     * Returning false rather than comparing against an empty key: an HMAC with an
     * empty secret is a real, computable value, so a misconfigured receiver would
     * otherwise accept deliveries signed by anybody who noticed.
     */
    public function testAppWithoutSecretCannotVerify(): void
    {
        // Arrange
        $signer = new WebhookSigner(new BroadcastApp('k', ''));

        // Act & Assert
        $this->assertFalse($signer->verify('{}', hash_hmac('sha256', '{}', '')));
    }

    // -------------------------------------------------------------------------
    // The queue dispatcher
    // -------------------------------------------------------------------------

    /**
     * A batch becomes one queue job carrying the URL, the signed body and headers.
     *
     * The worker does no signing and holds no secret — everything it needs travels
     * in the payload, so the secret stays in the process that already has it.
     */
    public function testBatchBecomesAQueueJob(): void
    {
        // Arrange
        $queue = new class extends \Pramnos\Queue\DelayedQueue {
            /** @var list<array{type:string,payload:array<string,mixed>}> */
            public array $pushed = [];

            public function __construct()
            {
                // Deliberately not calling the parent: no driver is needed, since
                // push() is overridden.
            }

            public function push(string $type, array $payload, int $delaySeconds = 0): string
            {
                $this->pushed[] = ['type' => $type, 'payload' => $payload];

                return 'job-1';
            }
        };

        $signer     = new WebhookSigner(new BroadcastApp('k', 's'));
        $dispatcher = new QueueWebhookDispatcher('https://hooks.test/realtime', $signer, 'broadcasting', $queue);

        // Act
        $dispatcher->dispatch([['name' => 'channel_occupied', 'channel' => 'ops']]);

        // Assert
        $this->assertCount(1, $queue->pushed);
        $this->assertSame(QueueWebhookDispatcher::JOB_TYPE, $queue->pushed[0]['type']);

        $payload = $queue->pushed[0]['payload'];
        $this->assertSame('https://hooks.test/realtime', $payload['url']);
        $this->assertTrue(
            $signer->verify($payload['body'], $payload['headers']['X-Pusher-Signature']),
            'the queued body and signature must agree'
        );
        $this->assertStringContainsString('channel_occupied', $payload['body']);
    }

    /**
     * An empty batch, or a dispatcher with no URL, pushes nothing.
     */
    public function testNothingIsQueuedWithoutEventsOrAUrl(): void
    {
        // Arrange
        $queue = new class extends \Pramnos\Queue\DelayedQueue {
            public array $pushed = [];

            public function __construct()
            {
            }

            public function push(string $type, array $payload, int $delaySeconds = 0): string
            {
                $this->pushed[] = $payload;

                return 'x';
            }
        };

        $signer = new WebhookSigner(new BroadcastApp('k', 's'));

        // Act
        (new QueueWebhookDispatcher('https://hooks.test', $signer, 'b', $queue))->dispatch([]);
        (new QueueWebhookDispatcher('', $signer, 'b', $queue))->dispatch([['name' => 'x']]);

        // Assert
        $this->assertSame([], $queue->pushed);
    }

    /**
     * A queue that cannot be reached is logged, not thrown.
     *
     * Losing a webhook degrades an application's bookkeeping; letting the exception
     * out of here would drop every connected client instead.
     */
    public function testAFailingQueueIsContained(): void
    {
        // Arrange
        $queue = new class extends \Pramnos\Queue\DelayedQueue {
            public function __construct()
            {
            }

            public function push(string $type, array $payload, int $delaySeconds = 0): string
            {
                throw new \RuntimeException('redis down');
            }
        };

        $dispatcher = new QueueWebhookDispatcher(
            'https://hooks.test',
            new WebhookSigner(new BroadcastApp('k', 's')),
            'b',
            $queue
        );

        // Act & Assert — the absence of a thrown exception is the assertion
        $dispatcher->dispatch([['name' => 'x']]);
        $this->assertTrue(true, 'dispatch() must not propagate a queue failure');
    }
}
