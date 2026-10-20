<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * A cache whose prefix holds a character a key's name cannot — the `:` of `server:5432`, which
 * the SQL cache's prefix carries for any connection with a port — is cleared all the same.
 *
 * A key was stored under the sanitized prefix and `clear()` scanned for the raw one, so nothing
 * matched: on PostgreSQL `cacheflush()`, the administration's Clear Cache and `cache:clear` left
 * every cached query in place until it expired, and MySQL without a port hid it.
 */
#[CoversClass(Cache::class)]
class CachePrefixWithAColonTest extends TestCase
{
    private const PREFIX = 'pfcolon:5432dbpublic';

    /** @return array<string, array{string}> */
    public static function stores(): array
    {
        return ['redis' => ['redis'], 'file' => ['file']];
    }

    protected function tearDown(): void
    {
        foreach (['redis', 'file'] as $store) {
            try {
                $this->cache($store, 'sql')->clear('');
            } catch (\Throwable) {
                // Not reachable: nothing was written there.
            }
        }
        \Pramnos\Redis\ConnectionManager::resetPool();
        parent::tearDown();
    }

    /** A cache built as `Database::sqlCache()` builds one: the prefix assigned after construction. */
    private function cache(string $store, string $category): Cache
    {
        $cache = new Cache($category, 'sql', $store, $store === 'redis'
            ? ['hostname' => 'redis', 'port' => 6379, 'database' => 0, 'password' => null, 'prefix' => self::PREFIX]
            : []);
        $cache->category = $category;
        $cache->prefix   = self::PREFIX;
        if (!$cache->caching || $cache->getAdapter() === null) {
            $this->markTestSkipped('This build could not reach the ' . $store . ' store through Cache.');
        }

        return $cache;
    }

    /**
     * Saved under a prefix with a colon, a value is gone after the whole prefix is cleared, and
     * after its category is.
     */
    #[DataProvider('stores')]
    public function testAClearFindsWhatWasSavedUnderAPrefixWithAColon(string $store): void
    {
        // Arrange
        $all      = $this->cache($store, 'sql');
        $settings = $this->cache($store, 'settings');
        $this->assertTrue($all->save('a value', 'k1'), 'the fixture could not write');
        $this->assertTrue($settings->save('a setting', 'k2'), 'the fixture could not write');
        $this->assertSame('a value', $all->load('k1'), 'the fixture could not read back');

        // Act
        $settings->clear('settings');
        $afterCategory = $settings->load('k2');
        $all->clear('');
        $afterAll = $all->load('k1');

        // Assert
        $this->assertFalse($afterCategory, 'the category flush left its value');
        $this->assertFalse($afterAll, 'the whole flush left the value');
    }
}
