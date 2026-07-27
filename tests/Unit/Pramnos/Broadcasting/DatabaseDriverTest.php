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

    public function latestId(): int
    {
        return 0;
    }

    public function fetchSince(int $lastId, array $channels): array
    {
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
}
