<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\MemcachedAdapter;

/**
 * The Memcached counter, and what `clear()` empties.
 *
 * `increment()` was uncovered end to end — a documented four-path concurrency algorithm that had
 * never run. Memcached's own `increment` fails when the key does not exist, so creating the counter
 * goes through `add`, which is atomic: if two requests race to create it, exactly one `add`
 * succeeds and the loser increments what the winner made. No increment is lost either way, which is
 * the entire reason for using the server's counter rather than a read-modify-write.
 *
 * That is a rate limiter's correctness. A counter that silently resets on a race lets a client past
 * the limit; one that returns `false` where a number is expected puts `false` into arithmetic, and
 * `false + 1` is `1` — a limiter that counts from zero for ever.
 *
 * Each path is scripted rather than raced, because a test that actually races is a test that passes
 * most of the time.
 */
#[CoversClass(MemcachedAdapter::class)]
class MemcachedCounterAndIsolationTest extends TestCase
{
    /**
     * An adapter that believes it is connected, wired to a scripted client.
     *
     * @param array<string, list<mixed>> $script Return values per method, consumed in order
     */
    private function adapter(array $script, string $prefix = ''): MemcachedAdapter
    {
        $client = new ScriptedMemcached($script);

        return new class ($client, $prefix) extends MemcachedAdapter {
            public function __construct(public ScriptedMemcached $client, string $prefix)
            {
                // Fourth, not third: the third parameter is `$persistentId`. Passing the prefix
                // there leaves `$this->prefix` empty, and `clear()` then flushes the whole server
                // — correctly, which is how this test found its own mistake.
                parent::__construct('localhost', 11211, '', $prefix);
                $this->memcached = $client;
                $this->connected = true;
                $this->caching   = true;
            }
        };
    }

    /**
     * An existing counter is incremented server-side and the new value comes back.
     *
     * The ordinary path, and the one that must not go anywhere near `add`: an `add` on an existing
     * counter would fail harmlessly, but reaching for it would mean the first `increment` had been
     * ignored.
     */
    public function testAnExistingCounterIsIncrementedInOneCall(): void
    {
        // Arrange
        $adapter = $this->adapter(['increment' => [7]]);

        // Act
        $result = $adapter->increment('hits', 1);

        // Assert
        $this->assertSame(7, $result);
        $this->assertSame(['increment'], $adapter->client->calls, 'the counter existed; add was not needed');
    }

    /**
     * A counter that does not exist yet is created by `add`, with its expiry set once.
     *
     * The expiry belongs to the call that creates the counter and to no other, which is what makes
     * a fixed window fixed: a rate limit whose expiry is refreshed on every hit never resets, and
     * the client stays locked out for as long as it keeps trying.
     */
    public function testAnAbsentCounterIsCreatedWithItsExpiry(): void
    {
        // Arrange — increment fails because the key is absent, add succeeds
        $adapter = $this->adapter([
            'increment' => [false],
            'add'       => [true],
        ]);

        // Act
        $result = $adapter->increment('hits', 5, 60);

        // Assert
        $this->assertSame(5, $result, 'a new counter starts at the amount added');
        $this->assertSame(['increment', 'add'], $adapter->client->calls);
        $this->assertSame(
            ['hits', 5, 60],
            $adapter->client->argumentsFor('add'),
            'the expiry is set by the call that creates the counter'
        );
    }

    /**
     * With no TTL given, the counter is created without one.
     *
     * `0` is Memcached's "no expiry", and it is what `$ttl ?? 0` means — a counter with no stated
     * lifetime should outlive the request rather than expire immediately.
     */
    public function testACounterWithNoTtlIsCreatedWithoutOne(): void
    {
        // Arrange
        $adapter = $this->adapter(['increment' => [false], 'add' => [true]]);

        // Act
        $adapter->increment('hits', 1);

        // Assert
        $this->assertSame(['hits', 1, 0], $adapter->client->argumentsFor('add'));
    }

    /**
     * Losing the creation race counts on top of the winner's counter.
     *
     * The path the whole design exists for. Two requests find no counter, both call `add`, one
     * wins; the loser must not return `1` — that would discard the winner's increment and let a
     * rate limiter undercount by exactly one request per race.
     */
    public function testLosingTheCreationRaceCountsOnTopOfTheWinner(): void
    {
        // Arrange — absent, then somebody else created it between the two calls
        $adapter = $this->adapter([
            'increment' => [false, 9],
            'add'       => [false],
        ]);

        // Act
        $result = $adapter->increment('hits', 1);

        // Assert
        $this->assertSame(9, $result, 'the loser of the race discarded the winner\'s value');
        $this->assertSame(['increment', 'add', 'increment'], $adapter->client->calls);
    }

    /**
     * A counter that cannot be read or created answers `false`, not `0`.
     *
     * The distinction a caller depends on: `0` is "nothing has happened yet" and `false` is "I do
     * not know". A limiter told `0` by a broken cache lets everything through.
     */
    public function testACounterThatCannotBeReachedAnswersFalse(): void
    {
        // Arrange — nothing works
        $adapter = $this->adapter([
            'increment' => [false, false],
            'add'       => [false],
        ]);

        // Act + Assert
        $this->assertFalse($adapter->increment('hits', 1));
    }

    /** A server that raises is logged and answered `false`, like the rest of the adapter. */
    public function testAnIncrementThatRaisesAnswersFalse(): void
    {
        // Arrange
        $adapter = $this->adapter(['increment' => [new \RuntimeException('server gone')]]);

        // Act + Assert
        $this->assertFalse($adapter->increment('hits', 1));
    }

    /** And an adapter that knows it is not connected never calls the server. */
    public function testAnUnconnectedAdapterDoesNotCallTheServer(): void
    {
        // Arrange
        $client  = new ScriptedMemcached([]);
        $adapter = new class ($client) extends MemcachedAdapter {
            public function __construct(public ScriptedMemcached $client)
            {
                parent::__construct();
                $this->memcached = $client;
                $this->connected = false;
                $this->caching   = true;
            }
        };

        // Act + Assert
        $this->assertFalse($adapter->increment('hits'));
        $this->assertSame([], $adapter->client->calls);
    }

    /**
     * Without a key prefix, clearing the cache empties the whole server.
     *
     * Asserted because it is true and dangerous, not because it is desirable. Memcached cannot
     * enumerate its keys, so an installation that set no prefix has no way to clear only its own —
     * and `flush()` takes every co-tenant's data with it. The adapter says so in the log before
     * doing it, which is the only warning anybody gets.
     */
    public function testWithoutAPrefixClearFlushesTheEntireServer(): void
    {
        // Arrange
        $adapter = $this->adapter(['flush' => [true]], '');

        // Act
        $result = $adapter->clear();

        // Assert
        $this->assertTrue($result);
        $this->assertSame(['flush'], $adapter->client->calls);
    }

    /**
     * With a prefix, clearing does **not** flush the server.
     *
     * The isolation that the prefix buys: a prefixed installation clears the category indexes it
     * maintains itself and leaves everybody else's keys alone. A `flush` appearing in this call
     * list would mean one application's cache clear wiped another's.
     */
    public function testWithAPrefixClearLeavesTheServerAlone(): void
    {
        // Arrange
        $adapter = $this->adapter(['get' => [false], 'delete' => [true]], 'tenant_');

        // Act
        $adapter->clear();

        // Assert
        $this->assertNotContains(
            'flush',
            $adapter->client->calls,
            'a prefixed installation flushed the whole server and took its co-tenants with it'
        );
    }

    /** A flush that raises is logged and answered `false`. */
    public function testAFlushThatRaisesAnswersFalse(): void
    {
        // Arrange
        $adapter = $this->adapter(['flush' => [new \RuntimeException('server gone')]], '');

        // Act + Assert
        $this->assertFalse($adapter->clear());
    }
}

/**
 * A Memcached stand-in that returns what the test tells it to, in order.
 *
 * Scripted rather than stateful: what these tests assert is the adapter's reaction to each answer
 * the server can give, and a working fake would make the interesting answers — `increment` failing
 * on an absent key, `add` losing a race — the hard ones to arrange.
 */
class ScriptedMemcached
{
    /** @var list<string> Method names in call order */
    public array $calls = [];

    /** @var array<string, list<array<int, mixed>>> Arguments per method, in call order */
    public array $arguments = [];

    /** @param array<string, list<mixed>> $script */
    public function __construct(private array $script) {}

    public function __call(string $method, array $arguments): mixed
    {
        $this->calls[]              = $method;
        $this->arguments[$method][] = $arguments;

        // A method the script says nothing about answers `false`, which is what a Memcached
        // client returns for anything it could not do.
        $queue = $this->script[$method] ?? [];
        $next  = array_shift($queue) ?? false;
        $this->script[$method] = $queue;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * The arguments of the first call to a method.
     *
     * @return array<int, mixed>
     */
    public function argumentsFor(string $method): array
    {
        return $this->arguments[$method][0] ?? [];
    }
}
