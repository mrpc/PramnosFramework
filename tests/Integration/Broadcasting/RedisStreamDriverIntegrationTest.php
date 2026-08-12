<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Broadcasting;

use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\RedisStreamDriver;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * The Redis stream backplane against a live server.
 *
 * The unit tests drive the loop through an injected fake, which proves the
 * cursor arithmetic and proves nothing about Redis. Everything this driver
 * depends on lives in the two commands it issues: whether `XADD … MAXLEN ~`
 * trims, whether `XREAD` with an explicit id returns what came *after* it, and
 * whether the entry ids it hands back sort the way the replay assumes. Those
 * are answers only a server can give.
 *
 * The reason to be sure is the failure mode. A backplane that silently replays
 * nothing looks exactly like a quiet channel: no error, no missing-data
 * warning, just a client that never hears about the two seconds it was away.
 * That is the bug this driver exists to fix, and it would be invisible.
 *
 * Requires the Docker Redis container (host: redis, port 6379).
 */
class RedisStreamDriverIntegrationTest extends TestCase
{
    /** Live connection used to set up and inspect, alongside the driver's own. */
    private \Redis $redis;

    /** Key prefix unique to this test class, so a stray key cannot mislead. */
    private string $prefix = 'pramnostest:';

    /** The channel every test here publishes on. */
    private string $channel = 'integration.chat';

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('The redis extension is not installed.');
        }

        $this->redis = new \Redis();
        if (!@$this->redis->connect('redis', 6379, 1.0)) {
            $this->markTestSkipped('No Redis server on redis:6379.');
        }

        $this->redis->del($this->key());
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del($this->key());
            $this->redis->close();
        }
    }

    /**
     * A published event lands in the stream, as the envelope consumers expect.
     *
     * `RedisDriver` publishes the same shape, so a project can move between
     * pub/sub and streams without touching a consumer.
     */
    public function testBroadcastWritesTheEnvelopeToTheStream(): void
    {
        // Arrange
        $driver = $this->driver();

        // Act
        $driver->broadcast($this->channel, 'message.created', ['id' => 7, 'text' => 'γειά']);

        // Assert — one entry, holding the envelope
        $entries = $this->redis->xRange($this->key(), '-', '+');
        $this->assertCount(1, $entries);

        $envelope = json_decode(reset($entries)['envelope'], true);
        $this->assertSame('message.created', $envelope['event']);
        $this->assertSame(7, $envelope['payload']['id']);
        // UTF-8 survives the round trip rather than arriving as γε…
        $this->assertSame('γειά', $envelope['payload']['text']);
    }

    /**
     * **The reason this driver exists.** Events published while nobody was
     * subscribed are delivered to a consumer that says where it got to.
     *
     * With `RedisDriver` this test is impossible to write: `PUBLISH` with no
     * subscriber is not stored anywhere, and the events would be gone. Here the
     * gap between the close of one stream and the opening of the next — which
     * `maxRuntime` opens on a schedule, for every client — is bridged.
     */
    public function testEventsPublishedWhileAwayAreReplayedFromACursor(): void
    {
        // Arrange — a client that has seen one event, then goes away
        $driver = $this->driver();
        $driver->broadcast($this->channel, 'seen.already', ['n' => 0]);
        $lastSeen = array_key_first($this->redis->xRange($this->key(), '-', '+'));

        // ...and two events published while it is not connected
        $driver->broadcast($this->channel, 'missed.one', ['n' => 1]);
        $driver->broadcast($this->channel, 'missed.two', ['n' => 2]);

        $received = [];

        // Act — it reconnects, saying where it got to
        $driver->subscribe(
            [$this->channel],
            function (string $channel, string $event, array $payload, ?string $id) use (&$received): bool {
                $received[] = [$channel, $event, $payload['n'], $id];
                return count($received) < 2;
            },
            new SubscriptionOptions(readTimeout: 1, sinceId: $lastSeen, maxRuntime: 5),
        );

        // Assert — both missed events, in order, attributed to the channel they
        // were published on rather than the Redis key they live in
        $this->assertCount(2, $received);
        $this->assertSame([$this->channel, 'missed.one', 1], array_slice($received[0], 0, 3));
        $this->assertSame([$this->channel, 'missed.two', 2], array_slice($received[1], 0, 3));

        // And the ids sort the way the replay assumes: each one is a valid
        // cursor to resume after
        $this->assertNotSame($received[0][3], $received[1][3]);
        $this->assertTrue($this->idPrecedes($received[0][3], $received[1][3]));
    }

    /**
     * Without a cursor, only what arrives next is delivered.
     *
     * A first connection must not be handed the last thousand events: it has
     * nothing on screen to reconcile them against, and the snapshot it renders
     * on connect is what covers that ground.
     */
    public function testWithoutACursorTheHistoryIsNotReplayed(): void
    {
        // Arrange — a stream with history already in it
        $driver = $this->driver();
        $driver->broadcast($this->channel, 'old.one', ['n' => 1]);
        $driver->broadcast($this->channel, 'old.two', ['n' => 2]);

        $received = [];

        // Act — a subscription that says nothing about where to start, ended by
        // its own idle tick after one quiet read
        $driver->subscribe(
            [$this->channel],
            function (string $channel, string $event) use (&$received): bool {
                $received[] = $event;
                return true;
            },
            new SubscriptionOptions(
                readTimeout: 1,
                onIdle: static fn (): bool => false,
                maxRuntime: 5,
            ),
        );

        // Assert
        $this->assertSame([], $received, 'history is for whoever asks for it');
    }

    /**
     * The cap trims the stream, so history covers a reconnect without growing
     * without bound.
     *
     * `MAXLEN ~` trims on node boundaries, so the length drifts a little above
     * the cap — asserting an exact count here would be asserting Redis's
     * internal geometry. What matters is that it is bounded and that the newest
     * entries are the ones kept.
     */
    public function testTheStreamIsTrimmedToRoughlyTheCap(): void
    {
        // Arrange — a deliberately tiny cap, and far more events than it
        $driver = $this->driver(['maxLength' => 10]);

        // Act
        for ($i = 0; $i < 200; $i++) {
            $driver->broadcast($this->channel, 'noise', ['n' => $i]);
        }

        // Assert — bounded well below what was written
        $length = $this->redis->xLen($this->key());
        $this->assertLessThan(200, $length, 'the stream is trimmed');

        // ...and it is the newest that survived
        $entries = $this->redis->xRange($this->key(), '-', '+');
        $last    = json_decode(end($entries)['envelope'], true);
        $this->assertSame(199, $last['payload']['n']);
    }

    /**
     * An event published *after* the subscription starts arrives live.
     *
     * Replay is the new half; this is the half that has to keep working — a
     * backplane that only ever answers about the past is a poll loop with extra
     * steps.
     */
    public function testAnEventPublishedDuringTheSubscriptionArrivesLive(): void
    {
        // Arrange — publish through a second connection while the loop runs.
        // The subscription blocks, so the write has to already be in the stream
        // by the time it reads: the driver's cursor starts at "now", so an entry
        // added between the two reads is what "live" means here.
        $driver = $this->driver();
        $received = [];
        $published = false;

        // Act
        $driver->subscribe(
            [$this->channel],
            function (string $channel, string $event) use (&$received): bool {
                $received[] = $event;
                return false;
            },
            new SubscriptionOptions(
                readTimeout: 1,
                maxRuntime: 5,
                onIdle: function () use ($driver, &$published): bool {
                    if ($published) {
                        return false;   // it did not arrive; stop rather than hang
                    }
                    $driver->broadcast($this->channel, 'live.one', ['n' => 1]);
                    $published = true;
                    return true;
                },
            ),
        );

        // Assert
        $this->assertSame(['live.one'], $received);
    }

    /**
     * A driver wired to this test's Redis.
     *
     * @param array<string,mixed> $config Overrides merged over the defaults
     */
    private function driver(array $config = []): RedisStreamDriver
    {
        return new RedisStreamDriver($config + [
            'host'   => 'redis',
            'port'   => 6379,
            'prefix' => $this->prefix,
        ]);
    }

    /** The Redis key this test's channel lives in. */
    private function key(): string
    {
        return $this->prefix . 'stream:' . $this->channel;
    }

    /**
     * Does one entry id come before another?
     *
     * Redis ids are `<milliseconds>-<sequence>`, so they compare numerically
     * per part rather than as strings — `9-0` is not after `10-0`.
     */
    private function idPrecedes(string $first, string $second): bool
    {
        [$aMs, $aSeq] = array_map('intval', explode('-', $first) + [1 => '0']);
        [$bMs, $bSeq] = array_map('intval', explode('-', $second) + [1 => '0']);

        return $aMs === $bMs ? $aSeq < $bSeq : $aMs < $bMs;
    }
}
