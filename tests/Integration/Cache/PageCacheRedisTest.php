<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * The page cache on a real Redis (FW-171): flushing it removes its pages and nothing else in the
 * database — it was FLUSHDB, the application's cache and its rate limiters with them.
 */
#[CoversClass(PageCache::class)]
final class PageCacheRedisTest extends TestCase
{
    /**
     * A page stored, a key of somebody else's beside it: flush() takes the page and leaves the
     * other key, as `pagecache:purge --all` does.
     */
    public function testAFlushRemovesOnlyThePages(): void
    {
        // Arrange
        $host  = getenv('REDIS_HOST') ?: 'pramnos_redis';
        $redis = new \Redis();
        if (!@$redis->connect($host, 6379, 1.0)) {
            $this->markTestSkipped('Redis is not reachable.');
        }
        $redis->select(3);
        $other = 'pf-test-not-a-page:' . bin2hex(random_bytes(3));
        $redis->set($other, 'kept');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['REQUEST_URI']    = '/stations';
        PageCache::resetRuntime();
        Request::resetInstance();
        $prefix = 'pf-test-pages-' . bin2hex(random_bytes(3)) . ':';
        $cache  = new PageCache(['enabled' => true, 'store' => 'redis', 'prefix' => $prefix, 'redis' => ['host' => $host, 'database' => 3]]);
        $stored = $cache->store(new Request(), Response::make('<html>stations</html>', 200));
        $before = count($redis->keys($prefix . '*'));

        // Act
        $cache->flush();

        // Assert
        $this->assertTrue($stored);
        $this->assertGreaterThan(0, $before, 'the page is in Redis');
        $this->assertSame([], $redis->keys($prefix . '*'), 'the pages are gone');
        $this->assertSame('kept', $redis->get($other), 'somebody else\'s key is not');
        $redis->del($other);
        PageCache::resetRuntime();
        Request::resetInstance();
    }
}
