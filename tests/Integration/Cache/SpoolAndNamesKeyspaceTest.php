<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\Cache;
use Pramnos\Database\WriteSpool;

/**
 * Two structures that must not walk the keyspace, and must not grow for ever.
 *
 * Filed seven hours after an installation adopted the cache invalidation work, as a
 * follow-up rather than a dispute — and it opened with the good news, which is worth
 * repeating because it is what makes the rest small:
 *
 * ```
 * cmdstat_scan  87,945,372  immediately after adopting the fix
 * cmdstat_scan  87,945,378  six hours fifty minutes later     ← six calls
 * ```
 *
 * Under the previous build a single process drove that counter at 2,549 calls a second.
 *
 * What was left were two edges at the sides of it, both the same mistake as the one that
 * had just been fixed, one file over:
 *
 * - **`WriteSpool::redisKeys()` used `KEYS`**, and `spool:drain` is scheduled `everyMinute`
 *   — so a blocking keyspace walk sixty times an hour, for ever. Measured at 8.3 ms
 *   average, and **118 of the 128 slowest commands the server had recorded** were this one
 *   call. The slowlog was at its cap, so it had stopped saying anything about anything else.
 *   And that installation's spool backend is `file`: the rows never reached redis, the drain
 *   probed it anyway, and got an empty list. The whole cost with none of the purpose.
 * - **`catnames` had no TTL.** 5,304 members after seven hours, 88% of the keyspace, and
 *   the slowest single entry in the slowlog at 26.7 ms.
 */
#[CoversClass(RedisAdapter::class)]
#[CoversClass(WriteSpool::class)]
class SpoolAndNamesKeyspaceTest extends TestCase
{
    private const PREFIX = 'spoolprobe';

    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not installed here.');
        }

        $probe = new \Redis();

        if (!@$probe->connect('redis', 6379, 1)) {
            $this->markTestSkipped('No redis to connect to.');
        }

        $probe->close();
        \Pramnos\Redis\ConnectionManager::resetPool();
    }

    protected function tearDown(): void
    {
        $redis = new \Redis();

        if (@$redis->connect('redis', 6379, 1)) {
            foreach ((array) $redis->keys(self::PREFIX . '*') as $key) {
                $redis->del($key);
            }

            $redis->close();
        }

        \Pramnos\Redis\ConnectionManager::resetPool();
        WriteSpool::reset();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function cache(string $category, array $settings = array()): Cache
    {
        $cache = new Cache($category, 'sql', 'redis', $settings + array(
            'hostname' => 'redis',
            'port'     => 6379,
            'database' => 0,
            'password' => null,
            'prefix'   => self::PREFIX,
        ));

        $cache->category = $category;
        $cache->prefix   = self::PREFIX;

        if (!$cache->caching || $cache->getAdapter() === null) {
            $this->markTestSkipped('This build could not reach redis through Cache.');
        }

        return $cache;
    }

    /** How many `KEYS` and `SCAN` commands this redis has served. */
    private function walks(): int
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $stats = (array) $redis->info('commandstats');
        $redis->close();

        $calls = 0;

        foreach ($stats as $command => $line) {
            if ($command === 'cmdstat_keys' || $command === 'cmdstat_scan') {
                preg_match('/calls=(\d+)/', is_array($line) ? implode(',', $line) : (string) $line, $m);
                $calls += (int) ($m[1] ?? 0);
            }
        }

        return $calls;
    }

    /**
     * The names set has an expiry, pushed forward by each save.
     *
     * The reported defect in one assertion. `clearCategory()` removes a name, so a category
     * that is *cleared* leaves nothing — but the ordinary life of a cached read is to expire
     * on its own TTL, and nothing clears that. So the set accumulated one member per distinct
     * category ever cached, which on this codebase is per entity id: 5,304 in seven hours,
     * 88% of the keyspace, and the slowest command on the server.
     */
    public function testTheNamesSetExpires(): void
    {
        // Arrange
        $this->cache('settings')->save('blob', 'an-id');

        // Act
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $ttl = (int) $redis->ttl(self::PREFIX . 'catnames');
        $redis->close();

        // Assert — an expiry, and past the entry's own so the set outlives its members
        $this->assertGreaterThan(0, $ttl, 'the names set never expires');
        $this->assertGreaterThan(3600, $ttl);
    }

    /**
     * An entry with no expiry of its own makes the set permanent too.
     *
     * The one case where a permanent names set is correct: there is a member that will never
     * stop being true, so forgetting its name would list a category as absent while its
     * entries are still being served.
     */
    public function testAnImmortalEntryKeepsTheNamesSetPermanent(): void
    {
        // Arrange — timeout 0 means never
        $cache = $this->cache('settings');
        $cache->timeout = 0;
        $cache->save('blob', 'an-id', 0);

        // Act
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $ttl = (int) $redis->ttl(self::PREFIX . 'catnames');
        $redis->close();

        // Assert — -1 is "no expiry"
        $this->assertSame(-1, $ttl, 'a set holding an immortal member was given an expiry');
    }

    /**
     * The spool drain does not walk the keyspace.
     *
     * `redisKeys()` used `KEYS spool:*` and `spool:drain` runs every minute, so this was a
     * blocking O(database) command sixty times an hour on every installation running the
     * framework schedule. Measured with redis's own counters, because the cost is invisible
     * from PHP — which is exactly why it survived until somebody read the slowlog.
     */
    public function testDrainingTheSpoolDoesNotWalkTheKeyspace(): void
    {
        // Arrange — a table with rows waiting, so there is something to list
        ConnectedWriteSpool::reset();
        ConnectedWriteSpool::setDriver(WriteSpool::DRIVER_REDIS);

        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $names = \Pramnos\Redis\ConnectionManager::getInstance()->prefix() . 'spool:tables';
        $redis->sAdd($names, 'probe_table');
        $redis->close();

        $before = $this->walks();

        // Act — what the scheduled command does every minute
        $listed = $this->exposedRedisKeys();

        // Assert — the table was found, and no keyspace was walked to find it
        $this->assertSame($before, $this->walks(), 'the spool walked the keyspace');
        $this->assertCount(1, $listed, 'the spool found nothing, so it proves nothing');
        $this->assertStringContainsString('probe_table', $listed[0]);

        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $redis->del($names);
        $redis->close();
    }

    /**
     * And an installation on the `file` backend does not ask redis at all.
     *
     * **A backend nobody enabled should cost nothing.** The reporting installation spools to
     * files: the rows never reached redis, the drain probed it anyway, stalled the server for
     * 8 ms and got an empty list. Not a small waste because it is small work — it is work
     * that is *certain* to be pointless.
     */
    public function testAFileSpoolDoesNotProbeRedis(): void
    {
        // Arrange — a `file` spool, and a names set that would answer if it were asked
        ConnectedWriteSpool::reset();
        ConnectedWriteSpool::setDriver(WriteSpool::DRIVER_FILE);

        $before = $this->smembersCalls();

        // Act
        $listed = $this->exposedRedisKeys();

        // Assert — nothing listed, and the names set was never even read
        $this->assertSame(array(), $listed);
        $this->assertSame(
            $before,
            $this->smembersCalls(),
            'a file spool read the redis names set'
        );
    }

    /**
     * How many `SMEMBERS` this redis has served.
     *
     * The counter rather than a mock, because what is being asserted is that a *command was
     * not sent* — and a mock can only say that the code did not call the method it was given.
     */
    private function smembersCalls(): int
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $stats = (array) $redis->info('commandstats');
        $redis->close();

        $line = $stats['cmdstat_smembers'] ?? '';

        preg_match('/calls=(\d+)/', is_array($line) ? implode(',', $line) : (string) $line, $m);

        return (int) ($m[1] ?? 0);
    }

    /**
     * `redisKeys()` reachable from a test, on a spool whose Redis actually answers.
     *
     * **Through a subclass, and that is not incidental.** `WriteSpool::redis()` goes through
     * `ConnectionManager::getInstance()`, which reads the framework's own Redis configuration
     * — and in the suite that points nowhere, so every call throws `Connection refused` and
     * the `catch` returns an empty list.
     *
     * The first version of these tests called the real thing and passed for exactly that
     * reason: no command was sent, so "no command was sent" was true whatever the code did.
     * Removing the `file`-backend guard left them green. Late static binding is what makes
     * the override work, since `redisKeys()` asks `static::redis()`.
     *
     * @return array<int, string>
     */
    private function exposedRedisKeys(): array
    {
        return (array) (new \ReflectionMethod(ConnectedWriteSpool::class, 'redisKeys'))
            ->invoke(null);
    }
}

/**
 * A `WriteSpool` that can reach the container's Redis.
 */
class ConnectedWriteSpool extends WriteSpool
{
    protected static function redis(): object
    {
        static $redis = null;

        if ($redis === null) {
            $redis = new \Redis();
            $redis->connect('redis', 6379, 1);
        }

        return $redis;
    }
}
