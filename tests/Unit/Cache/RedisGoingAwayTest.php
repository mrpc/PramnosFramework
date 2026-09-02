<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * What the Redis adapter does when Redis stops answering.
 *
 * Every method wraps its call in the same guard — log the exception, return an empty value of the
 * method's own type — and sixteen of those arms had never executed. The contract they add up to is
 * the one that matters most about a cache: **losing it degrades the application, it does not stop
 * it.** A `get()` that raises instead of missing takes down every page that reads through the
 * cache, which is all of them.
 *
 * The type of the empty value is the substance here, not the fact that something was returned.
 * `counter()` answering `false` instead of `0` puts `false` into arithmetic; `hashGetAll()`
 * answering `false` instead of `[]` puts it into a `foreach`; `hashGet()` has to honour the caller's
 * own `$default` rather than inventing `null`. Each of those is a second failure, in the caller,
 * a step away from the one that actually happened.
 *
 * The client throws from `__call`, so it covers every method name without naming any — including
 * the ones added after this test was written.
 */
#[CoversClass(RedisAdapter::class)]
class RedisGoingAwayTest extends TestCase
{
    /** An adapter that believes it is connected and whose client always throws. */
    private function adapterThatLostRedis(): RedisAdapter
    {
        $client = new class {
            public function __call(string $method, array $arguments): mixed
            {
                throw new \RuntimeException('Connection lost while calling ' . $method);
            }
        };

        return $this->adapterWith($client);
    }

    /** An adapter that believes it is connected, wired to a given client. */
    private function adapterWith(object $client): RedisAdapter
    {
        return new class ($client) extends RedisAdapter {
            public function __construct(object $client)
            {
                parent::__construct();

                // Both, because every method guards on `caching && connected` before it reaches
                // the call — an adapter that knows Redis is gone never gets as far as the arms
                // this test is about.
                $this->redis     = $client;
                $this->connected = true;
                $this->caching   = true;
            }
        };
    }

    /**
     * Every method answers with an empty value of its own type.
     *
     * One case per arm, and the expectation is written out rather than derived, because "what does
     * this method mean by nothing" is exactly the thing a shared helper would paper over.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>, 2: mixed}>
     */
    public static function methodsAndTheirEmptyAnswers(): array
    {
        return [
            'increment'       => ['increment',       ['k', 1],        false],
            'decrement'       => ['decrement',       ['k', 1],        false],
            'counter'         => ['counter',         ['k'],           0],
            'swap'            => ['swap',            ['k', 'v'],      null],
            'hashGetAll'      => ['hashGetAll',      ['k'],           []],
            'listPush'        => ['listPush',        ['k', 'v'],      0],
            'listRange'       => ['listRange',       ['k', 0, -1],    []],
            'keys'            => ['keys',            ['prefix:*'],    []],
            'flushEverything' => ['flushEverything', [],              false],
        ];
    }

    #[DataProvider('methodsAndTheirEmptyAnswers')]
    public function testEachMethodAnswersWithAnEmptyValueOfItsOwnType(
        string $method,
        array $arguments,
        mixed $expected
    ): void {
        // Arrange
        $adapter = $this->adapterThatLostRedis();

        // Act
        $result = $adapter->{$method}(...$arguments);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            $method . '() answered ' . var_export($result, true) . ' instead of '
            . var_export($expected, true) . ' — the wrong type of nothing is a second failure '
            . 'in whatever called it'
        );
    }

    /**
     * `hashGet()` returns the caller's default, not `null`.
     *
     * The one method whose empty value is not its own to choose. A caller writing
     * `hashGet($k, $f, 0)` has said what a miss means to it, and a cache that answers `null`
     * because Redis is down has silently disagreed.
     */
    public function testHashGetHonoursTheCallersDefault(): void
    {
        // Arrange
        $adapter = $this->adapterThatLostRedis();

        // Act + Assert
        $this->assertSame(0, $adapter->hashGet('k', 'f', 0));
        $this->assertSame('fallback', $adapter->hashGet('k', 'f', 'fallback'));
        $this->assertNull($adapter->hashGet('k', 'f'), 'null is the default default');
    }

    /**
     * The write methods swallow the failure rather than raising through the caller.
     *
     * These return nothing in particular — what is asserted is that they *return*. A cache write
     * that raises turns an optional side effect into a failed request, which is the shape of an
     * outage caused entirely by the mitigation.
     */
    public function testTheWriteMethodsReturnRatherThanRaise(): void
    {
        // Arrange
        $adapter = $this->adapterThatLostRedis();

        // Act + Assert — the assertion is that none of these throws
        $adapter->hashSet('k', 'f', 'v');
        $adapter->hashDelete('k', 'f');
        $adapter->listTrim('k', 0, 10);
        $adapter->expire('k', 60);

        $this->addToAssertionCount(1);
    }

    /**
     * `getAllItems()` skips a key it cannot read instead of abandoning the listing.
     *
     * A `catch (\Throwable) { continue; }` inside the loop, which is the right shape for a
     * diagnostic screen: one unreadable key — a type SCAN found and `get` cannot decode — should
     * cost that row, not the page. The outer guard is what handles the case of Redis going away
     * mid-listing.
     */
    public function testGetAllItemsSurvivesRedisGoingAway(): void
    {
        // Arrange
        $adapter = $this->adapterThatLostRedis();

        // Act
        $items = $adapter->getAllItems();

        // Assert
        $this->assertIsArray($items, 'the listing should come back empty rather than raise');
    }

    /**
     * A `scan()` that fails stops the sweep instead of looping for ever.
     *
     * `scan` returns `false` on error, and the cursor is only `0` when the walk finished — so
     * without the `break` a failed scan leaves a non-zero cursor and the `do … while` calls it
     * again, and again. Not an exception and not caught by the guard below it: an infinite loop
     * holding a request open.
     */
    public function testAFailedScanStopsTheSweep(): void
    {
        // Arrange — a client whose scan fails without ever finishing the walk
        $client = new class {
            public int $scanCalls = 0;

            /** By reference, like the real one: `scan` advances the caller's cursor. */
            public function scan(&$cursor, $pattern = null, $count = 0): mixed
            {
                $this->scanCalls++;
                $cursor = 42;          // never 0, so the loop's own condition cannot end it

                return false;
            }

            public function __call(string $method, array $arguments): mixed
            {
                return null;
            }
        };

        $adapter = $this->adapterWith($client);

        // Act
        $found = $adapter->keys('prefix:*');

        // Assert
        $this->assertSame([], $found);
        $this->assertSame(1, $client->scanCalls, 'the sweep did not stop on the failed scan');
    }

    /**
     * An adapter that knows Redis is gone does not call it at all.
     *
     * The guard above every arm tested here, and the reason those arms are about a connection lost
     * *mid-request*: a cache that was never reachable answers from the flag, without a round trip
     * to fail.
     */
    public function testAnUnconnectedAdapterAnswersWithoutCallingRedis(): void
    {
        // Arrange
        $client = new class {
            public bool $called = false;

            public function __call(string $method, array $arguments): mixed
            {
                $this->called = true;

                return null;
            }
        };

        $adapter = new class ($client) extends RedisAdapter {
            public function __construct(object $client)
            {
                parent::__construct();
                $this->redis     = $client;
                $this->connected = false;
                $this->caching   = true;
            }
        };

        // Act
        $result = $adapter->increment('k');

        // Assert
        $this->assertFalse($result);
        $this->assertFalse($client->called, 'an unconnected adapter reached for the server anyway');
    }
}
