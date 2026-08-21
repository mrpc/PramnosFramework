<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Cluster;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Cluster\RedisClusterTransport;
use Pramnos\Broadcasting\Drivers\DriverInterface;

/**
 * The gossip transport.
 *
 * It takes the driver rather than constructing one, and that is the whole design: a
 * node publishing gossip with a different Redis primitive than its peers read with
 * produces a cluster where every node believes it is alone — a perfectly healthy
 * subscription that is never delivered anything, with nothing in any log. Handing it
 * the same object the application publishes with is what makes that impossible.
 */
#[CoversClass(RedisClusterTransport::class)]
class RedisClusterTransportTest extends TestCase
{
    /** A driver that records, or throws. */
    private function driver(bool $throw = false): DriverInterface
    {
        return new class($throw) implements DriverInterface {
            /** @var list<array{channel:string,event:string,payload:array<string,mixed>}> */
            public array $sent = [];

            public function __construct(private bool $throw)
            {
            }

            public function broadcast(string $channel, string $event, array $payload): void
            {
                if ($this->throw) {
                    throw new \RuntimeException('redis is down');
                }

                $this->sent[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
            }

            public function name(): string
            {
                return 'recording';
            }
        };
    }

    /**
     * A message goes out on the gossip channel, through the supplied driver.
     */
    public function testPublishesThroughTheSuppliedDriver(): void
    {
        // Arrange
        $driver    = $this->driver();
        $transport = new RedisClusterTransport($driver, 'cluster-bus');

        // Act
        $transport->publish(['type' => 'heartbeat', 'node' => 'a']);

        // Assert
        $this->assertSame(
            [['channel' => 'cluster-bus', 'event' => 'cluster', 'payload' => ['type' => 'heartbeat', 'node' => 'a']]],
            $driver->sent
        );
    }

    /**
     * The default channel is a name no application would choose.
     *
     * A collision would feed cluster messages to browsers — the server's routing check
     * is by channel name, so the name is load-bearing.
     */
    public function testDefaultChannelIsUnlikelyToCollide(): void
    {
        // Assert
        $this->assertSame('__pramnos_cluster', (new RedisClusterTransport($this->driver()))->channel());
    }

    /**
     * The configured channel is reported, so the server can tell gossip from an
     * application event.
     */
    public function testChannelIsReported(): void
    {
        // Assert
        $this->assertSame('bus', (new RedisClusterTransport($this->driver(), 'bus'))->channel());
    }

    /**
     * A backplane failure is swallowed rather than propagated.
     *
     * This is safe precisely because of how the gossip protocol is built: a lost
     * message is corrected by the next periodic full state, so the consequence is
     * bounded. A lost *application* event has no such repair, which is why that one
     * is not swallowed anywhere.
     */
    public function testABackplaneFailureDoesNotPropagate(): void
    {
        // Arrange
        $transport = new RedisClusterTransport($this->driver(throw: true), 'bus');

        // Act & Assert — the absence of a thrown exception is the assertion
        $transport->publish(['type' => 'heartbeat']);
        $this->assertTrue(true, 'publish() must not propagate a driver failure');
    }
}
