<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Redis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Redis\ConnectionManager;

/**
 * One socket per redis endpoint, however many caches ask for it.
 *
 * `Cache::getInstance()` keeps one instance per category — rightly, so that
 * `cache:clear --category=views` matches something — and every one of them built
 * its own `ConnectionManager` and asked it for `newConnection()`, which is
 * documented as being for a blocking `SUBSCRIBE` that monopolises its socket.
 *
 * In a request that is invisible: the process exits. In a daemon it is one socket
 * per category for the life of the worker. On the installation that reported it, a
 * `queue:process --daemon` worker reached **1,016 open redis connections in five
 * minutes**, against a default `LimitNOFILE` of 1024.
 *
 * **What happens at that ceiling is why the previous incident looked like a missing
 * class.** With no descriptors left, `connect()` cannot open a socket, the fallback
 * path constructs `MemcachedAdapter`, and the autoloader cannot open *that class
 * file* either — so PHP reports `Class "…\MemcachedAdapter" not found` about a
 * class that is present and that `class_exists()` confirms from a fresh process on
 * the same machine while the error is being logged.
 */
#[CoversClass(ConnectionManager::class)]
#[CoversClass(RedisAdapter::class)]
class CacheConnectionSharingTest extends TestCase
{
    private const HOST = 'redis';

    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not installed here.');
        }

        $probe = new \Redis();

        if (!@$probe->connect(self::HOST, 6379, 1)) {
            $this->markTestSkipped('No redis to connect to.');
        }

        $probe->close();
        ConnectionManager::resetPool();
    }

    protected function tearDown(): void
    {
        ConnectionManager::resetPool();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function config(array $extra = array()): array
    {
        return $extra + array('host' => self::HOST, 'port' => 6379, 'database' => 0, 'password' => null);
    }

    /**
     * The same endpoint gives the same manager, and therefore the same connection.
     *
     * `new ConnectionManager($config)` builds an object with a pool of its own, so two
     * callers naming one server got two managers and two sockets. That is the leak in one
     * line.
     */
    public function testOneEndpointGivesOneConnection(): void
    {
        // Act
        $first  = ConnectionManager::forConfig($this->config());
        $second = ConnectionManager::forConfig($this->config());

        // Assert
        $this->assertSame($first, $second, 'two managers for one endpoint');
        $this->assertSame(
            $first->connection(),
            $second->connection(),
            'two sockets for one endpoint'
        );
    }

    /**
     * A different endpoint still gets its own.
     *
     * The control. A pool keyed on nothing would satisfy the test above and quietly send
     * every cache to whichever server happened to be asked for first — which is the
     * failure the *previous* filing was about, arrived at from the other direction.
     */
    public function testADifferentEndpointGetsItsOwn(): void
    {
        // Act
        $here      = ConnectionManager::forConfig($this->config());
        $otherDb   = ConnectionManager::forConfig($this->config(array('database' => 1)));
        $otherPort = ConnectionManager::forConfig($this->config(array('port' => 6380)));

        // Assert
        $this->assertNotSame($here, $otherDb, 'two databases shared one manager');
        $this->assertNotSame($here, $otherPort, 'two ports shared one manager');
    }

    /**
     * The password is part of the identity, and is not in the key.
     *
     * Two credentials against one server are two different sessions. And an array key
     * ends up in a `var_dump`, in a stack trace, and in whatever prints one — so it is
     * hashed rather than placed there.
     */
    public function testThePasswordSeparatesEndpointsWithoutBeingStored(): void
    {
        // Act
        $none = ConnectionManager::forConfig($this->config());
        $with = ConnectionManager::forConfig($this->config(array('password' => 'a-secret')));

        // Assert
        $this->assertNotSame($none, $with);

        $pool = (new \ReflectionProperty(ConnectionManager::class, 'pool'));
        $keys = implode('|', array_keys((array) $pool->getValue()));

        $this->assertStringNotContainsString('a-secret', $keys, 'the password is in a key');
    }

    /**
     * Many cache adapters, one socket — which is the whole point.
     *
     * Driven through `RedisAdapter` rather than the manager, because the manager was
     * always capable of sharing and the adapter was the thing not asking it to. Ten
     * adapters is what ten cache categories look like in a worker.
     */
    public function testTenAdaptersShareOneConnection(): void
    {
        // Arrange
        $connections = array();

        // Act
        for ($i = 0; $i < 10; $i++) {
            $adapter = new RedisAdapter(self::HOST, 6379, 0, null, 'probe' . $i . ':');

            $this->assertTrue($adapter->connect(), 'adapter ' . $i . ' could not connect');

            $connections[] = $adapter->getConnection();
        }

        // Assert — one object, ten adapters
        $this->assertCount(10, $connections);
        $this->assertCount(
            1,
            array_unique(array_map('spl_object_id', $connections)),
            'each adapter opened its own socket'
        );
    }

    /**
     * Sharing the socket does not merge the categories.
     *
     * It is safe here for a reason worth stating, because the obvious implementation
     * would not be: the prefix is **not** set on the connection with
     * `Redis::OPT_PREFIX`. If it were, a shared socket would carry whichever adapter
     * spoke last and every category would read another's keys. Instead `Cache` builds
     * the prefixed name and hands the adapter a finished key, so the namespace travels
     * with the call.
     *
     * Written first with one key for both adapters, which failed — correctly, since that
     * is one key. The prefixes are in the keys because that is where the real caller puts
     * them.
     */
    public function testSharingTheSocketDoesNotMergeTheCategories(): void
    {
        // Arrange — the shape `Cache::_generateCacheName()` produces
        $views = new RedisAdapter(self::HOST, 6379, 0, null, 'probe:views:');
        $rows  = new RedisAdapter(self::HOST, 6379, 0, null, 'probe:rows:');
        $views->connect();
        $rows->connect();

        try {
            // Act
            $views->save('probe:views:same-key', 'from-views', 60);
            $rows->save('probe:rows:same-key', 'from-rows', 60);

            // Assert — one socket, two namespaces
            $this->assertSame($views->getConnection(), $rows->getConnection());
            $this->assertSame('from-views', $views->load('probe:views:same-key'));
            $this->assertSame('from-rows', $rows->load('probe:rows:same-key'));
        } finally {
            $views->delete('probe:views:same-key');
            $rows->delete('probe:rows:same-key');
        }
    }

    /**
     * And the prefix is not set on the connection, which is what makes the above true.
     *
     * Asserted directly rather than inferred: `Redis::OPT_PREFIX` on a shared socket is
     * the one change that would turn this fix into a cross-category data leak, and it is
     * a one-line change somebody could make in good faith.
     */
    public function testThePrefixIsNotSetOnTheSharedConnection(): void
    {
        // Arrange
        $adapter = new RedisAdapter(self::HOST, 6379, 0, null, 'probe:views:');
        $adapter->connect();

        // Act
        $onTheSocket = $adapter->getConnection()->getOption(\Redis::OPT_PREFIX);

        // Assert
        $this->assertSame('', (string) $onTheSocket, 'the prefix was set on a shared socket');
    }
}
