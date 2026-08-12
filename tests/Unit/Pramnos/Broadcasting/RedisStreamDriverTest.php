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

    /** The stream's last entry id, as xRevRange() will report it. */
    public string $lastId = '';

    public function xAdd(string $key, string $id, array $fields, int $maxLen = 0, bool $approx = false): string
    {
        $this->added[] = ['key' => $key, 'fields' => $fields, 'maxLen' => $maxLen, 'approx' => $approx];
        return '1-' . count($this->added);
    }

    /**
     * The newest entry, which the driver reads to turn "start from now" into a
     * fixed id — `$` would re-anchor on every read and skip whatever was
     * published in between.
     *
     * @return array<string, array<string, string>>
     */
    public function xRevRange(string $key, string $end, string $start, int $count = 0): array
    {
        return $this->lastId === '' ? [] : [$this->lastId => ['envelope' => '{}']];
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
     * Without a cursor the driver starts from the stream's newest entry —
     * as an id, not as `$`.
     *
     * `$` means "whatever is newest at the moment *this* read is issued", so a
     * loop that re-reads after every timeout would silently skip anything
     * published in between: the very gap this driver exists to close, reopened
     * once per read timeout. A live-server test caught that; this pins it.
     */
    public function testWithoutACursorItStartsFromTheStreamsNewestEntry(): void
    {
        // Arrange — a stream that already has history
        $redis = new FakeRedisStream();
        $redis->lastId = '1699-4';
        $driver = new RedisStreamDriver(['prefix' => 'app:'], static fn (): object => $redis);

        // Act
        $driver->subscribe(
            ['chat.updates'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, onIdle: static fn (): bool => false),
        );

        // Assert — a fixed point, so the next read continues rather than
        // re-anchoring
        $this->assertSame(['app:stream:chat.updates' => '1699-4'], $redis->cursorsSeen[0]);
        $this->assertNotContains('$', $redis->cursorsSeen[0]);
    }

    /**
     * An empty stream starts at `0-0`, which is correct precisely because there
     * is no history for it to replay.
     */
    public function testAnEmptyStreamStartsAtZero(): void
    {
        // Arrange
        $redis  = new FakeRedisStream();
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, onIdle: static fn (): bool => false),
        );

        // Assert
        $this->assertSame(['stream:chat' => '0-0'], $redis->cursorsSeen[0]);
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
        $this->assertSame(['stream:chat' => '0-0'], $redis->cursorsSeen[0]);
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

    /**
     * The driver names itself, so a manager can tell which backplane is in use.
     *
     * `redis` and `redis-stream` differ in exactly the property that matters —
     * whether an event survives having no subscriber — so telling them apart in
     * a log line or a config dump is not cosmetic.
     */
    public function testItNamesItselfDistinctlyFromPubSub(): void
    {
        // Arrange & Act
        $driver = new RedisStreamDriver([], static fn (): object => new FakeRedisStream());

        // Assert
        $this->assertSame('redis-stream', $driver->name());
    }

    /**
     * A read that throws is reported and treated as an idle tick.
     *
     * Some clients surface a blocked read that timed out as an exception, and a
     * transient network error looks identical from here. Either way the loop
     * must tick and carry on rather than end the stream — a consumer that dies
     * on the first hiccup is worse than no keep-alive at all.
     */
    public function testAReadThatThrowsIsReportedAndBecomesAnIdleTick(): void
    {
        // Arrange
        $redis = new class extends FakeRedisStream {
            public function xRead(array $cursors, int $count = 0, int $blockMs = 0): mixed
            {
                $this->reads++;
                throw new \RuntimeException('read timed out');
            }
        };
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        $errors = 0;
        $ticks  = 0;

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(
                readTimeout: 1,
                onIdle: static function () use (&$ticks): bool {
                    $ticks++;
                    return $ticks < 2;
                },
                onError: static function (\Throwable $e) use (&$errors): void {
                    $errors++;
                },
            ),
        );

        // Assert — the caller heard about it, and the loop kept its cadence
        $this->assertSame(2, $errors);
        $this->assertSame(2, $ticks);
    }

    /**
     * The runtime ceiling ends the loop even while entries are still arriving.
     *
     * `maxRuntime` exists to close the stream before an edge proxy does, and a
     * busy channel must not be able to hold the connection open past it.
     */
    public function testTheRuntimeCeilingStopsALoopThatKeepsReceiving(): void
    {
        // Arrange — the deadline has already passed when subscribe() is called
        $envelope = ['envelope' => json_encode(['event' => 'e', 'payload' => [], 'timestamp' => 1])];
        $redis = new FakeRedisStream([
            ['stream:chat' => ['1-1' => $envelope, '1-2' => $envelope]],
            ['stream:chat' => ['1-3' => $envelope]],
        ]);
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        $seen = 0;

        // Act — a one-second ceiling, and time() is already past it after the
        // first delivery
        $driver->subscribe(
            ['chat'],
            static function () use (&$seen): bool {
                $seen++;
                sleep(1);   // push past the deadline mid-batch
                return true;
            },
            new SubscriptionOptions(readTimeout: 1, maxRuntime: 1),
        );

        // Assert — it stopped inside the batch rather than draining it
        $this->assertSame(1, $seen);
    }

    /**
     * A password in the config is accepted rather than ignored.
     *
     * Not behaviour worth a test on its own, except that the branch decides
     * whether a production Redis is reachable at all, and a driver that silently
     * drops the password fails at the worst possible moment.
     */
    public function testAPasswordIsAccepted(): void
    {
        // Arrange & Act
        $driver = new RedisStreamDriver(
            ['password' => 'secret', 'prefix' => 'x:'],
            static fn (): object => new FakeRedisStream()
        );

        // Assert — it constructs and still works; the password reaches the
        // connection factory, which a live server exercises
        $driver->broadcast('chat', 'e', []);
        $this->assertSame('redis-stream', $driver->name());
    }

    /**
     * A quiet stream stops at the runtime ceiling too.
     *
     * Nothing arrives, the block times out, and the loop has to end itself —
     * `maxRuntime` is there to close the stream before an edge proxy does.
     */
    public function testAQuietStreamStopsAtTheRuntimeCeiling(): void
    {
        // Arrange — a read that blocks for its timeout and returns nothing
        $redis = new class extends FakeRedisStream {
            public function xRead(array $cursors, int $count = 0, int $blockMs = 0): mixed
            {
                $this->cursorsSeen[] = $cursors;
                $this->reads++;
                usleep(1100 * 1000);   // the block, as a real server would
                return null;
            }
        };
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        $start = time();

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, maxRuntime: 1),
        );

        // Assert
        $this->assertLessThan(4, time() - $start);
        $this->assertGreaterThan(0, $redis->reads);
    }

    /**
     * A connection that throws while closing does not become the caller's
     * problem.
     *
     * The stream is over either way; a Redis that has already gone away and
     * complains about it must not turn a finished SSE response into a fatal
     * error on the way out.
     */
    public function testAConnectionThatThrowsOnCloseIsIgnored(): void
    {
        // Arrange
        $redis = new class extends FakeRedisStream {
            public function close(): void
            {
                throw new \RuntimeException('already gone');
            }
        };
        $driver = new RedisStreamDriver([], static fn (): object => $redis);

        // Act & Assert — reaching the end without throwing is the assertion
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, onIdle: static fn (): bool => false),
        );

        $this->assertGreaterThan(0, $redis->reads);
    }
}
