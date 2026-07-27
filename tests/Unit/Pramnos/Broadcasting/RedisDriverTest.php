<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * In-memory fake of the phpredis \Redis surface the RedisDriver uses.
 *
 * subscribe() delivers every queued inbox message to the callback and then
 * throws a \RedisException to emulate the OPT_READ_TIMEOUT idle signal the real
 * client raises when no message arrives — this is exactly the branch the driver
 * treats as "idle tick, maybe reconnect".
 */
class FakeRedisConnection
{
    /** @var list<array{0:string,1:string}> */
    public array $published = [];
    /** @var list<array{0:string,1:string}> Messages to deliver on the next subscribe(). */
    public array $inbox = [];
    public int $subscribeCalls = 0;
    public bool $closed = false;
    /** @var array<int,mixed> */
    public array $options = [];

    public function setOption(int $option, $value): bool
    {
        $this->options[$option] = $value;
        return true;
    }

    public function publish(string $channel, string $message): int
    {
        $this->published[] = [$channel, $message];
        return 1;
    }

    public function subscribe(array $channels, callable $callback): void
    {
        $this->subscribeCalls++;
        while ($this->inbox !== []) {
            [$channel, $message] = array_shift($this->inbox);
            if ($callback($this, $channel, $message) === false) {
                return; // consumer unsubscribed
            }
        }
        // Inbox drained → emulate a read timeout (the idle signal).
        throw new \RedisException('read error on connection');
    }

    public function close(): bool
    {
        $this->closed = true;
        return true;
    }
}

/**
 * Unit tests for the Redis pub/sub backplane driver.
 *
 * All tests inject a FakeRedisConnection through the driver's connection factory
 * so the publish/subscribe/envelope/reconnect logic is exercised deterministically
 * without a live Redis server.
 */
#[CoversClass(RedisDriver::class)]
#[CoversClass(SubscriptionOptions::class)]
class RedisDriverTest extends TestCase
{
    /**
     * broadcast() publishes a JSON envelope {event,payload,timestamp} on the
     * prefixed channel, and reuses a single publisher connection across calls.
     */
    public function testBroadcastPublishesPrefixedEnvelopeOnOneConnection(): void
    {
        $conn  = new FakeRedisConnection();
        $calls = 0;
        $driver = new RedisDriver(['prefix' => 'app:'], function () use ($conn, &$calls) {
            $calls++;
            return $conn;
        });

        $driver->broadcast('chat.updates', 'message.created', ['body' => 'hi']);
        $driver->broadcast('chat.updates', 'message.deleted', ['id' => 7]);

        $this->assertSame(1, $calls, 'publisher connection must be opened once and reused');
        $this->assertCount(2, $conn->published);

        [$channel, $raw] = $conn->published[0];
        $this->assertSame('app:chat.updates', $channel);
        $envelope = json_decode($raw, true);
        $this->assertSame('message.created', $envelope['event']);
        $this->assertSame(['body' => 'hi'], $envelope['payload']);
        $this->assertArrayHasKey('timestamp', $envelope);
    }

    /**
     * subscribe() decodes the envelope and hands (channel-without-prefix, event,
     * payload) to the consumer; returning false from the consumer stops the loop
     * after a single subscribe pass (no reconnect).
     */
    public function testSubscribeDeliversDecodedEnvelopeAndStopsOnFalse(): void
    {
        $conn = new FakeRedisConnection();
        $conn->inbox[] = ['app:chat.updates', json_encode(['event' => 'message.created', 'payload' => ['body' => 'hi']])];

        $driver = new RedisDriver(['prefix' => 'app:'], fn () => $conn);

        $received = [];
        $driver->subscribe(['chat.updates'], function (string $channel, string $event, array $payload) use (&$received) {
            $received[] = [$channel, $event, $payload];
            return false; // stop immediately
        });

        $this->assertSame([['chat.updates', 'message.created', ['body' => 'hi']]], $received);
        $this->assertSame(1, $conn->subscribeCalls);
        $this->assertTrue($conn->closed);
        $this->assertSame(20.0, $conn->options[\Redis::OPT_READ_TIMEOUT] ?? null);
    }

    /**
     * A non-enveloped (legacy) message is still delivered: empty event name and
     * the decoded body as the payload, so publishers can migrate incrementally.
     */
    public function testSubscribeDeliversRawLegacyMessage(): void
    {
        $conn = new FakeRedisConnection();
        $conn->inbox[] = ['chat.updates', json_encode(['type' => 'clear'])];

        $driver = new RedisDriver([], fn () => $conn);

        $received = null;
        $driver->subscribe(['chat.updates'], function (string $channel, string $event, array $payload) use (&$received) {
            $received = [$channel, $event, $payload];
            return false;
        });

        $this->assertSame(['chat.updates', '', ['type' => 'clear']], $received);
    }

    /**
     * On an idle read-timeout the driver fires onIdle; when onIdle returns true it
     * reconnects (fresh connection) and keeps consuming, and stops once onIdle
     * returns false. Verifies delivery + idle + reconnect + graceful stop together.
     */
    public function testSubscribeIdleTickReconnectsThenStops(): void
    {
        $first = new FakeRedisConnection();
        $first->inbox[] = ['chat.updates', json_encode(['event' => 'ping', 'payload' => []])];
        $second = new FakeRedisConnection(); // empty inbox → immediate idle timeout

        $conns = [$first, $second];
        $driver = new RedisDriver([], function () use (&$conns) {
            return array_shift($conns);
        });

        $delivered = 0;
        $idleTicks = 0;
        $options = new SubscriptionOptions(
            readTimeout: 1,
            onIdle: function () use (&$idleTicks) {
                $idleTicks++;
                return $idleTicks < 2; // continue after the 1st idle, stop on the 2nd
            },
        );

        $driver->subscribe(['chat.updates'], function () use (&$delivered) {
            $delivered++;
            // do not return false → let the loop hit the idle timeout
        }, $options);

        $this->assertSame(1, $delivered, 'the queued event should be delivered before the idle timeout');
        $this->assertSame(2, $idleTicks);
        $this->assertTrue($first->closed, 'first connection is closed on reconnect');
        $this->assertTrue($second->closed, 'second connection is closed on stop');
        $this->assertSame(1, $first->subscribeCalls);
        $this->assertSame(1, $second->subscribeCalls);
    }

    /**
     * subscribe() with no channels is a programming error and must throw.
     */
    public function testSubscribeRequiresChannels(): void
    {
        $driver = new RedisDriver([], fn () => new FakeRedisConnection());
        $this->expectException(\InvalidArgumentException::class);
        $driver->subscribe([], fn () => false);
    }
}
