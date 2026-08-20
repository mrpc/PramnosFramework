<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastEventStore;
use Pramnos\Broadcasting\Drivers\DatabaseDriver;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * In-memory event store that returns a scripted sequence of poll batches, so the
 * DatabaseDriver poll loop can be tested deterministically. append() records
 * writes; each fetchSince() returns the next scripted batch (then [] forever).
 */
class ScriptedEventStore implements BroadcastEventStore
{
    /** @var list<array{0:string,1:string,2:array}> */
    public array $appended = [];
    public int $fetchCalls = 0;

    /** @param list<list<array{id:int,channel:string,event:string,payload:array}>> $batches */
    public function __construct(private array $batches = [])
    {
    }

    public function append(string $channel, string $event, array $payload): void
    {
        $this->appended[] = [$channel, $event, $payload];
    }

    /** Pretend the table already holds this many rows. */
    public int $latest = 0;

    public function latestId(): int
    {
        return $this->latest;
    }

    /** The cursor the driver asked from on its first poll. */
    public ?int $firstFetchFrom = null;

    public function fetchSince(int $lastId, array $channels): array
    {
        if ($this->fetchCalls === 0) {
            $this->firstFetchFrom = $lastId;
        }
        $batch = $this->batches[$this->fetchCalls] ?? [];
        $this->fetchCalls++;
        // Honour the lastId cursor so already-delivered rows are not re-sent.
        return array_values(array_filter($batch, fn ($r) => $r['id'] > $lastId));
    }
}

/**
 * Unit tests for the database polling backplane driver, exercised against an
 * in-memory scripted store with an injected no-op sleeper (no real waiting).
 */
#[CoversClass(DatabaseDriver::class)]
class DatabaseDriverTest extends TestCase
{
    private function noSleep(): callable
    {
        return static function (int $ms): void {};
    }

    /**
     * broadcast() appends the event to the store as (channel, event, payload).
     */
    public function testBroadcastAppendsToStore(): void
    {
        $store  = new ScriptedEventStore();
        $driver = new DatabaseDriver($store, $this->noSleep());

        $driver->broadcast('chat.updates', 'message.created', ['body' => 'hi']);

        $this->assertSame([['chat.updates', 'message.created', ['body' => 'hi']]], $store->appended);
    }

    /**
     * subscribe() delivers polled rows to the consumer in id order and advances
     * its cursor; returning false stops the loop.
     */
    public function testSubscribeDeliversPolledRowsThenStops(): void
    {
        $store = new ScriptedEventStore([
            [ // first poll returns two events
                ['id' => 1, 'channel' => 'chat.updates', 'event' => 'a', 'payload' => ['n' => 1]],
                ['id' => 2, 'channel' => 'chat.updates', 'event' => 'b', 'payload' => ['n' => 2]],
            ],
        ]);
        $driver = new DatabaseDriver($store, $this->noSleep());

        $received = [];
        $driver->subscribe(['chat.updates'], function (string $c, string $e, array $p) use (&$received) {
            $received[] = [$c, $e, $p];
            return count($received) < 2 ? null : false; // stop after the 2nd
        });

        $this->assertSame(
            [['chat.updates', 'a', ['n' => 1]], ['chat.updates', 'b', ['n' => 2]]],
            $received
        );
    }

    /**
     * A poll that returns no rows fires the idle tick; when onIdle returns false
     * the loop stops (keep-alive/liveness hook for long-lived consumers).
     */
    public function testEmptyPollFiresIdleAndStopsWhenIdleReturnsFalse(): void
    {
        $store  = new ScriptedEventStore([]); // every poll returns []
        $driver = new DatabaseDriver($store, $this->noSleep());

        $idle = 0;
        $options = new SubscriptionOptions(
            readTimeout: 1,
            onIdle: function () use (&$idle) {
                $idle++;
                return $idle < 3; // allow two idle ticks, stop on the third
            },
        );

        $driver->subscribe(['chat.updates'], fn () => null, $options);

        $this->assertSame(3, $idle);
    }

    /**
     * subscribe() with no channels is a programming error and must throw.
     */
    public function testSubscribeRequiresChannels(): void
    {
        $driver = new DatabaseDriver(new ScriptedEventStore(), $this->noSleep());
        $this->expectException(\InvalidArgumentException::class);
        $driver->subscribe([], fn () => false);
    }

    /**
     * Without a cursor, the loop starts at the end of the table.
     *
     * This is the historical behaviour and still the right default for a first
     * connection: a client with nothing on screen wants what happens next, not
     * the last thousand events.
     */
    public function testWithoutACursorTheLoopStartsFromNow(): void
    {
        // Arrange — a store that already holds 42 rows
        $store = new ScriptedEventStore([]);
        $store->latest = 42;
        $driver = new DatabaseDriver($store, $this->noSleep());

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, onIdle: static fn (): bool => false),
        );

        // Assert — it asked for what comes after row 42, not for row 1 onwards
        $this->assertSame(42, $store->firstFetchFrom);
    }

    /**
     * With a cursor, the loop resumes after it — which is the reconnect gap
     * closed.
     *
     * `maxRuntime` ends every SSE stream on purpose, so a client reconnects on a
     * schedule. The events published while it was away are in the table the
     * whole time; before this the loop started at `latestId()` and stepped over
     * them, and nothing anywhere reported a loss.
     */
    public function testACursorResumesAfterThatEventAndReplaysWhatWasMissed(): void
    {
        // Arrange — three rows were written while the client was reconnecting
        $store = new ScriptedEventStore([[
            ['id' => 8,  'channel' => 'chat', 'event' => 'missed.one', 'payload' => ['n' => 1]],
            ['id' => 9,  'channel' => 'chat', 'event' => 'missed.two', 'payload' => ['n' => 2]],
            ['id' => 10, 'channel' => 'chat', 'event' => 'live',       'payload' => ['n' => 3]],
        ]]);
        $store->latest = 10;
        $driver = new DatabaseDriver($store, $this->noSleep());

        $seen = [];

        // Act — the client says it last saw event 7
        $driver->subscribe(
            ['chat'],
            function (string $channel, string $event, array $payload, ?string $id) use (&$seen): bool {
                $seen[] = [$event, $id];
                return count($seen) < 3;   // stop once all three have arrived
            },
            new SubscriptionOptions(readTimeout: 1, sinceId: '7', onIdle: static fn (): bool => false),
        );

        // Assert — everything after 7, in order, none of it skipped
        $this->assertSame(
            [['missed.one', '8'], ['missed.two', '9'], ['live', '10']],
            $seen
        );
        // And it really resumed rather than starting from the end of the table
        $this->assertSame(7, $store->firstFetchFrom);
    }

    /**
     * The event's id reaches the consumer, which is what lets it be written into
     * the transport and handed back on the next connection. A driver that
     * delivers events without ids can never be resumed from.
     */
    public function testTheEventIdIsPassedToTheConsumer(): void
    {
        // Arrange
        $store = new ScriptedEventStore([[
            ['id' => 5, 'channel' => 'chat', 'event' => 'message.created', 'payload' => []],
        ]]);
        $driver = new DatabaseDriver($store, $this->noSleep());
        $received = null;

        // Act
        $driver->subscribe(
            ['chat'],
            function (string $channel, string $event, array $payload, ?string $id) use (&$received): bool {
                $received = $id;
                return false;
            },
            new SubscriptionOptions(readTimeout: 1),
        );

        // Assert
        $this->assertSame('5', $received);
    }

    /**
     * The driver names itself, so a config dump or a log line says which
     * backplane is in use — and `database` versus `redis` differs in latency by
     * a poll interval.
     */
    public function testItNamesItself(): void
    {
        // Arrange & Act
        $driver = new DatabaseDriver(new ScriptedEventStore([]), $this->noSleep());

        // Assert
        $this->assertSame('database', $driver->name());
    }

    /**
     * The runtime ceiling ends the loop, and ends it mid-batch if it has to.
     *
     * `maxRuntime` exists to close an SSE stream before an edge proxy does. A
     * channel that keeps producing must not be able to hold the connection open
     * past that point — the events are durable and the client resumes from its
     * cursor, so stopping costs nothing.
     */
    public function testTheRuntimeCeilingStopsTheLoopMidBatch(): void
    {
        // Arrange — a batch of three, and a one-second ceiling
        $store = new ScriptedEventStore([[
            ['id' => 1, 'channel' => 'chat', 'event' => 'a', 'payload' => []],
            ['id' => 2, 'channel' => 'chat', 'event' => 'b', 'payload' => []],
            ['id' => 3, 'channel' => 'chat', 'event' => 'c', 'payload' => []],
        ]]);
        $driver = new DatabaseDriver($store, $this->noSleep());

        $seen = 0;

        // Act
        $driver->subscribe(
            ['chat'],
            static function () use (&$seen): bool {
                $seen++;
                sleep(1);   // push past the deadline inside the batch
                return true;
            },
            new SubscriptionOptions(readTimeout: 1, maxRuntime: 1),
        );

        // Assert — it stopped rather than draining the batch
        $this->assertSame(1, $seen);
    }

    /**
     * A consumer that returns false ends the loop there and then, without
     * waiting for the next poll — an SSE stream whose client has gone must not
     * keep querying for another twenty seconds.
     */
    public function testAConsumerCanEndTheLoopImmediately(): void
    {
        // Arrange
        $store = new ScriptedEventStore([[
            ['id' => 1, 'channel' => 'chat', 'event' => 'a', 'payload' => []],
            ['id' => 2, 'channel' => 'chat', 'event' => 'b', 'payload' => []],
        ]]);
        $driver = new DatabaseDriver($store, $this->noSleep());

        $seen = 0;

        // Act
        $driver->subscribe(
            ['chat'],
            static function () use (&$seen): bool {
                $seen++;
                return false;
            },
            new SubscriptionOptions(readTimeout: 1),
        );

        // Assert — one delivery, then out; the second row is left for a later
        // subscription to resume from
        $this->assertSame(1, $seen);
        $this->assertSame(1, $store->fetchCalls, 'and no further polling');
    }

    /**
     * A quiet channel still stops at the runtime ceiling.
     *
     * The busy case is covered above; this is the one that actually happens on a
     * chat page nobody is typing in — nothing arrives for ninety-five seconds
     * and the stream has to close itself anyway, or the edge proxy does it less
     * politely.
     */
    public function testAQuietLoopStopsAtTheRuntimeCeiling(): void
    {
        // Arrange — nothing to deliver, a one-second ceiling, and a sleeper that
        // really sleeps so the deadline is reached the way it is in production
        $store  = new ScriptedEventStore([]);
        $driver = new DatabaseDriver($store, static function (int $ms): void {
            usleep(min($ms, 1100) * 1000);
        });

        $start = time();

        // Act
        $driver->subscribe(
            ['chat'],
            static fn (): bool => true,
            new SubscriptionOptions(readTimeout: 1, maxRuntime: 1),
        );

        // Assert — it came back on its own rather than running until something
        // else stopped it.
        //
        // The bound is generous on purpose. The invariant worth protecting is
        // "the loop ends itself"; how many seconds that takes is a property of
        // the machine, and a tight bound here fails on a loaded one for a reason
        // that has nothing to do with the driver. This failed a full-suite run at
        // 4 seconds while passing in isolation, which is the worst kind of red:
        // it teaches the reader to re-run rather than to look.
        $this->assertLessThan(15, time() - $start, 'the ceiling ended the loop');
        $this->assertGreaterThan(0, $store->fetchCalls, 'it did poll at least once');
    }
}
