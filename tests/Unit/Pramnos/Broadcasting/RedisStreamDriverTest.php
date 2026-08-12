<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\RedisStreamDriver;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * A \Redis stand-in with just the stream commands the driver uses.
 *
 * `xAdd` records the append and the trimming arguments; `xRead` replays a
 * scripted sequence of results and remembers the cursors it was called with, so
 * a test can assert *where the driver asked to resume from* — which is the whole
 * behaviour under test.
 */
class FakeRedisStream
{
    /** @var list<array{key:string,fields:array,maxLen:int,approx:bool}> */
    public array $added = [];

    /** @var list<array<string,string>> One entry per xRead call. */
    public array $cursorsSeen = [];

    /** @var int How many times xRead has been called. */
    public int $reads = 0;

    /** @param list<mixed> $results Scripted xRead return values, in order. */
    public function __construct(private array $results = [])
    {
    }

    public function xAdd(string $key, string $id, array $fields, int $maxLen = 0, bool $approx = false): string
    {
        $this->added[] = ['key' => $key, 'fields' => $fields, 'maxLen' => $maxLen, 'approx' => $approx];
        return '1-' . count($this->added);
    }

    /**
     * @param  array<string,string> $cursors
     * @return mixed The next scripted result, then null (the "timed out" shape)
     */
    public function xRead(array $cursors, int $count = 0, int $blockMs = 0): mixed
    {
        $this->cursorsSeen[] = $cursors;
        $result = $this->results[$this->reads] ?? null;
        $this->reads++;
        return $result;
    }

    public function close(): void
    {
    }
}

/**
 * The Redis backplane that can replay.
 *
 * `RedisDriver` uses pub/sub, which keeps nothing: an event published while
 * nobody is subscribed is delivered to nobody, and `maxRuntime` guarantees a
 * window with no subscriber for every client on a schedule. This driver uses a
 * stream — a log with ids — so a client that says where it got to is given the
 * rest.
 *
 * These tests drive the loop against a fake connection: what matters is the
 * cursor arithmetic and the envelope, neither of which needs a live server.
 */
#[CoversClass(RedisStreamDriver::class)]
class RedisStreamDriverTest extends TestCase
{
    /**
     * Publishing appends to the channel's stream, capped.
     *
     * The cap is the difference between "history" and "a memory leak with a
     * schedule". `MAXLEN ~` lets Redis trim on node boundaries, which drifts
     * slightly above the cap and costs far less than exact trimming.
     */
    public function testBroadcastAppendsToACappedStream(): void
    {
        // Arrange
        $redis  = new FakeRedisStream();
        $driver = new RedisStreamDriver(
            ['prefix' => 'app:', 'maxLength' => 500],
            static fn (): object => $redis
        );

        // Act
        $driver->broadcast('chat.updates', 'message.created', ['id' => 7]);

        // Assert
        $this->assertCount(1, $redis->added);
        $this->assertSame('app:stream:chat.updates', $redis->added[0]['key']);
        $this->assertSame(500, $redis->added[0]['maxLen']);
        $this->assertTrue($redis->added[0]['approx'], 'exact trimming on every append is not worth its cost');

        $envelope = json_decode($redis->added[0]['fields']['envelope'], true);
        $this->assertSame('message.created', $envelope['event']);
        $this->assertSame(['id' => 7], $envelope['payload']);
    }

    /**
     * Without a cursor the driver asks for `$` — only what arrives next.
     *
     * Right for a first connection, and exactly wrong for a reconnect; the two
     * cases are told apart by whether the caller supplies a resume point.
     */
    public function testWithoutACursorItSubscribesFromNow(): void
    {
        // Arrange
        $redis  = new FakeRedisStream();
        $driver = new RedisStreamDriver(['prefix' => 'app:'], static fn (): object => $redis);

        // Act
        $driver->subscribe(
            ['chat.updates'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, onIdle: static fn (): bool => false),
        );

        // Assert
        $this->assertSame(['app:stream:chat.updates' => '$'], $redis->cursorsSeen[0]);
    }

    /**
     * With a cursor, everything after it is delivered before anything live.
     *
     * The ordering is the point: a reconnecting client is caught up first, so it
     * never has to reassemble a sequence that arrived out of order.
     */
    public function testACursorReplaysWhatWasPublishedDuringTheGap(): void
    {
        // Arrange — two entries written while the client was away
        $envelope = static fn (string $event, array $payload): array => [
            'envelope' => json_encode(['event' => $event, 'payload' => $payload, 'timestamp' => 1]),
        ];
        $redis = new FakeRedisStream([[
            'app:stream:chat.updates' => [
                '1699-1' => $envelope('missed.one', ['n' => 1]),
                '1699-2' => $envelope('missed.two', ['n' => 2]),
            ],
        ]]);
        $driver = new RedisStreamDriver(['prefix' => 'app:'], static fn (): object => $redis);

        $seen = [];

        // Act — resuming from the last id the client saw
        $driver->subscribe(
            ['chat.updates'],
            function (string $channel, string $event, array $payload, ?string $id) use (&$seen): bool {
                $seen[] = [$channel, $event, $payload['n'], $id];
                return count($seen) < 2;
            },
            new SubscriptionOptions(readTimeout: 1, sinceId: '1699-0', onIdle: static fn (): bool => false),
        );

        // Assert — asked from the client's id, and both missed events arrived
        // with the channel name it published on, not the Redis key
        $this->assertSame(['app:stream:chat.updates' => '1699-0'], $redis->cursorsSeen[0]);
        $this->assertSame([
            ['chat.updates', 'missed.one', 1, '1699-1'],
            ['chat.updates', 'missed.two', 2, '1699-2'],
        ], $seen);
    }

    /**
     * The cursor advances, so a second read does not repeat what was delivered.
     *
     * Advancing *before* the consumer is called is deliberate: an event that
     * makes the consumer stop has still been seen, and replaying it on the next
     * connection would be a duplicate rather than a recovery.
     */
    public function testTheCursorAdvancesPastDeliveredEntries(): void
    {
        // Arrange — two reads, each with one entry
        $envelope = static fn (string $event): array => [
            'envelope' => json_encode(['event' => $event, 'payload' => [], 'timestamp' => 1]),
        ];
        // No prefix on this driver, so the key is the bare one it builds itself.
        $redis = new FakeRedisStream([
            ['stream:chat' => ['5-1' => $envelope('first')]],
            ['stream:chat' => ['5-2' => $envelope('second')]],
        ]);
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        $seen = 0;

        // Act
        $driver->subscribe(
            ['chat'],
            static function () use (&$seen): bool {
                $seen++;
                return $seen < 2;
            },
            new SubscriptionOptions(readTimeout: 1),
        );

        // Assert — the second read resumed after the first entry
        $this->assertSame(['stream:chat' => '$'], $redis->cursorsSeen[0]);
        $this->assertSame(['stream:chat' => '5-1'], $redis->cursorsSeen[1]);
    }

    /**
     * A read that returns nothing is the idle tick — where an SSE consumer sends
     * its keep-alive ping and checks whether the client is still there.
     */
    public function testAnEmptyReadFiresTheIdleTick(): void
    {
        // Arrange
        $redis  = new FakeRedisStream([null, null]);
        $driver = new RedisStreamDriver([], static fn (): object => $redis);
        $ticks  = 0;

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(
                readTimeout: 1,
                onIdle: static function () use (&$ticks): bool {
                    $ticks++;
                    return $ticks < 2;      // stop on the second tick
                },
            ),
        );

        // Assert
        $this->assertSame(2, $ticks);
    }

    /**
     * An entry written by something that does not use the envelope is delivered
     * rather than dropped, so a project can migrate publishers incrementally.
     */
    public function testANonEnvelopedEntryIsStillDelivered(): void
    {
        // Arrange
        $redis = new FakeRedisStream([[
            'stream:chat' => ['1-1' => ['envelope' => '{"anything":"else"}']],
        ]]);
        $driver = new RedisStreamDriver([], static fn (): object => $redis);
        $payload = null;

        // Act
        $driver->subscribe(
            ['chat'],
            function (string $channel, string $event, array $data) use (&$payload): bool {
                $payload = $data;
                return false;
            },
            new SubscriptionOptions(readTimeout: 1),
        );

        // Assert
        $this->assertSame(['anything' => 'else'], $payload);
    }

    /**
     * Subscribing to nothing is a programming error, not an empty loop that
     * blocks for ever.
     */
    public function testSubscribeRequiresChannels(): void
    {
        // Arrange
        $driver = new RedisStreamDriver([], static fn (): object => new FakeRedisStream());

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $driver->subscribe([], static fn (): bool => true);
    }
}
