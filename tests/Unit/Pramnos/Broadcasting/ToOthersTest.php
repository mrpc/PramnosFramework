<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Drivers\DriverInterface;
use Pramnos\Broadcasting\Drivers\ExcludesSocketInterface;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * `toOthers()`: excluding the connection that caused a broadcast.
 *
 * The socket id was issued at handshake and then never came back — there was no way
 * to say "everyone but the originator", so an application rendering a change
 * optimistically rendered it twice.
 *
 * The awkward part is that the exclusion has to survive a process boundary: the
 * request that publishes and the daemon that fans out are different processes, so
 * anything held in PHP memory is gone by the time the edge sees the event. Hence
 * the envelope key, and hence the tests that assert it is on the wire.
 */
#[CoversClass(BroadcastingManager::class)]
#[CoversClass(LocalBroadcastServer::class)]
class ToOthersTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    /** @var array<string,mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        unset($_POST['socket_id'], $_GET['socket_id']);

        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    /** A driver that records what it was asked to publish, exclusion included. */
    private function recordingDriver(): DriverInterface&ExcludesSocketInterface
    {
        return new class implements ExcludesSocketInterface {
            /** @var list<array{channel:string,event:string,except:?string}> */
            public array $sent = [];

            public function broadcast(string $channel, string $event, array $payload): void
            {
                $this->sent[] = ['channel' => $channel, 'event' => $event, 'except' => null];
            }

            public function broadcastExcept(
                string $channel,
                string $event,
                array $payload,
                ?string $exceptSocketId
            ): void {
                $this->sent[] = ['channel' => $channel, 'event' => $event, 'except' => $exceptSocketId];
            }

            public function name(): string
            {
                return 'recording';
            }
        };
    }

    /** A driver with no exclusion support, like a third-party one. */
    private function plainDriver(): DriverInterface
    {
        return new class implements DriverInterface {
            /** @var list<string> */
            public array $sent = [];

            public function broadcast(string $channel, string $event, array $payload): void
            {
                $this->sent[] = $event;
            }

            public function name(): string
            {
                return 'plain';
            }
        };
    }

    /**
     * except() routes through the driver's exclusion path.
     */
    public function testExceptReachesTheDriver(): void
    {
        // Arrange
        $driver  = $this->recordingDriver();
        $manager = (new BroadcastingManager())->addDriver($driver)->setDefault('recording');

        // Act
        $manager->except('12.34')->broadcast('chat', 'message.created', ['a' => 1]);

        // Assert
        $this->assertSame([['channel' => 'chat', 'event' => 'message.created', 'except' => '12.34']], $driver->sent);
    }

    /**
     * Without except(), the plain broadcast path is used — so an ordinary broadcast
     * is unchanged by all of this.
     */
    public function testPlainBroadcastIsUnchanged(): void
    {
        // Arrange
        $driver  = $this->recordingDriver();
        $manager = (new BroadcastingManager())->addDriver($driver)->setDefault('recording');

        // Act
        $manager->broadcast('chat', 'message.created', []);

        // Assert
        $this->assertNull($driver->sent[0]['except']);
    }

    /**
     * except() returns a clone: the original manager keeps broadcasting to everyone.
     *
     * A shared singleton is resolved from the container, so mutating it would leak
     * one request's exclusion into every later broadcast in the same process — a
     * worker would drop events for a connection that had nothing to do with them.
     */
    public function testExceptDoesNotMutateTheSharedManager(): void
    {
        // Arrange
        $driver  = $this->recordingDriver();
        $manager = (new BroadcastingManager())->addDriver($driver)->setDefault('recording');

        // Act
        $manager->except('12.34')->broadcast('chat', 'first', []);
        $manager->broadcast('chat', 'second', []);

        // Assert
        $this->assertSame('12.34', $driver->sent[0]['except']);
        $this->assertNull($driver->sent[1]['except'], 'the original manager is untouched');
    }

    /**
     * except(null) and except('') clear the exclusion rather than excluding a
     * connection with an empty id.
     */
    public function testEmptyExclusionIsNoExclusion(): void
    {
        // Arrange
        $driver  = $this->recordingDriver();
        $manager = (new BroadcastingManager())->addDriver($driver)->setDefault('recording');

        // Act
        $manager->except(null)->broadcast('chat', 'a', []);
        $manager->except('')->broadcast('chat', 'b', []);

        // Assert
        $this->assertNull($driver->sent[0]['except']);
        $this->assertNull($driver->sent[1]['except']);
    }

    /**
     * A driver that cannot exclude still delivers the event.
     *
     * Degrading to "everyone" is the right call — dropping the broadcast would be
     * worse than a duplicate — and the manager logs it, because the only visible
     * symptom is one user seeing a duplicate of something they just did, which
     * reads as an application bug rather than a driver capability gap.
     */
    public function testDriverWithoutExclusionSupportStillDelivers(): void
    {
        // Arrange
        $driver  = $this->plainDriver();
        $manager = (new BroadcastingManager())->addDriver($driver)->setDefault('plain');

        // Act
        $manager->except('12.34')->broadcast('chat', 'message.created', []);

        // Assert
        $this->assertSame(['message.created'], $driver->sent);
    }

    /**
     * via() honours the exclusion too, so naming a driver explicitly does not
     * silently lose it.
     */
    public function testViaHonoursTheExclusion(): void
    {
        // Arrange
        $driver  = $this->recordingDriver();
        $manager = (new BroadcastingManager())->addDriver($driver);

        // Act
        $manager->except('12.34')->via('recording', 'chat', 'e', []);

        // Assert
        $this->assertSame('12.34', $driver->sent[0]['except']);
    }

    // -------------------------------------------------------------------------
    // socketIdFromRequest
    // -------------------------------------------------------------------------

    /**
     * The socket id is read from the X-Socket-ID header.
     */
    public function testReadsSocketIdFromTheHeader(): void
    {
        // Arrange
        $_SERVER['HTTP_X_SOCKET_ID'] = '12.34';

        // Act & Assert
        $this->assertSame('12.34', BroadcastingManager::socketIdFromRequest());
    }

    /**
     * A body or query field is accepted too — for a form post that cannot set a
     * header, and for EventSource, which cannot set one at all.
     */
    public function testReadsSocketIdFromBodyOrQuery(): void
    {
        // Arrange
        unset($_SERVER['HTTP_X_SOCKET_ID']);
        $_POST['socket_id'] = '5.6';

        // Act & Assert
        $this->assertSame('5.6', BroadcastingManager::socketIdFromRequest());

        unset($_POST['socket_id']);
        $_GET['socket_id'] = '7.8';
        $this->assertSame('7.8', BroadcastingManager::socketIdFromRequest());
    }

    /**
     * A malformed socket id is ignored rather than passed along.
     *
     * It ends up inside a driver envelope that an edge acts on, and it is compared
     * against connection ids — so it is data from a client that reaches another
     * process, and validating its shape is cheaper than reasoning about what a
     * hostile one could do there.
     */
    public function testMalformedSocketIdIsIgnored(): void
    {
        foreach (['abc', '1', '1.', '.2', '1.2.3', '<script>', ''] as $bad) {
            // Arrange
            $_SERVER['HTTP_X_SOCKET_ID'] = $bad;

            // Act & Assert
            $this->assertNull(BroadcastingManager::socketIdFromRequest(), 'value: ' . $bad);
        }
    }

    /**
     * With nothing present, there is no socket id.
     */
    public function testNoSocketIdWhenAbsent(): void
    {
        // Arrange
        unset($_SERVER['HTTP_X_SOCKET_ID'], $_POST['socket_id'], $_GET['socket_id']);

        // Act & Assert
        $this->assertNull(BroadcastingManager::socketIdFromRequest());
    }

    // -------------------------------------------------------------------------
    // The server edge
    // -------------------------------------------------------------------------

    /**
     * @return array{0:LocalBroadcastServer, 1:array<int,resource>}
     */
    private function serverWithClients(int $count): array
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
                'channels'  => ['room'],
                'socketId'  => $i . '.1',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ];
            $ends[$i] = $pair[0];
        }

        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);
        (new \ReflectionProperty($server, 'subscriptions'))
            ->setValue($server, ['room' => array_combine(range(1, $count), range(1, $count))]);

        return [$server, $ends];
    }

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
            if (is_array($decoded)) {
                $events[] = $decoded['event'] ?? null;
            }
        }

        return $events;
    }

    /**
     * broadcastExcept() skips the named connection and reaches the rest.
     */
    public function testServerSkipsTheExcludedConnection(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(3);

        // Act
        $server->broadcastExcept('room', 'message.created', ['a' => 1], '2.1');

        // Assert
        $this->assertSame(['message.created'], $this->received($ends[1]));
        $this->assertSame([], $this->received($ends[2]), 'the originator is skipped');
        $this->assertSame(['message.created'], $this->received($ends[3]));
    }

    /**
     * A null exclusion behaves exactly like broadcast().
     */
    public function testNullExclusionReachesEveryone(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);

        // Act
        $server->broadcastExcept('room', 'e', [], null);

        // Assert
        $this->assertSame(['e'], $this->received($ends[1]));
        $this->assertSame(['e'], $this->received($ends[2]));
    }

    /**
     * A socket id nobody holds excludes nobody.
     *
     * The originating connection may well have closed between the write and the
     * fan-out, and that must not cost anybody else the event.
     */
    public function testUnknownExclusionExcludesNobody(): void
    {
        // Arrange
        [$server, $ends] = $this->serverWithClients(2);

        // Act
        $server->broadcastExcept('room', 'e', [], '99.99');

        // Assert
        $this->assertSame(['e'], $this->received($ends[1]));
        $this->assertSame(['e'], $this->received($ends[2]));
    }

    /**
     * broadcast() keeps its exact three-argument signature, and remains the entry
     * point a subclass can intercept.
     *
     * This is the BC assertion, and it is not hypothetical: this framework's own
     * test suite subclasses the server and overrides `broadcast()` with that
     * signature, so a fourth parameter would have been a fatal error in our own
     * suite before it was one in anybody's application.
     */
    public function testBroadcastSignatureIsUnchangedAndStillInterceptable(): void
    {
        // Arrange
        $method = new \ReflectionMethod(LocalBroadcastServer::class, 'broadcast');

        // Assert
        $this->assertCount(3, $method->getParameters(), 'broadcast() must still take three arguments');

        // A subclass overriding the three-argument form must still intercept.
        $captured = [];
        $server   = new class('key', null, new AllowAllAuthorizer()) extends LocalBroadcastServer {
            public array $seen = [];

            public function broadcast(string $channel, string $event, $data): void
            {
                $this->seen[] = $event;
            }
        };

        // Act — the null-exclusion path must route through broadcast(), not past it
        $server->broadcastExcept('room', 'intercepted', [], null);

        // Assert
        $this->assertSame(['intercepted'], $server->seen);
    }
}
