<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * `Cache::clear($category)` removes what `save()` wrote when the prefix has an underscore.
 *
 * The key names are built with the prefix stripped of underscores, and that stripping happened
 * only while naming a key, by rewriting `$this->prefix`. `clear()` names no key, so a fresh
 * instance handed the adapter the prefix as configured — `nk_` — while `save()` had written
 * under `nk`. The clear matched nothing and returned as though it had worked, so a model that
 * writes and then clears its category kept serving the old answer. Every installation whose
 * database prefix carries an underscore was affected, on every adapter.
 *
 * Each step uses its own instance, as the requests of an application do.
 */
#[CoversClass(Cache::class)]
class ClearWithAnUnderscoredPrefixTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>}> */
    public static function methods(): array
    {
        return [
            'file'  => ['file', []],
            'redis' => ['redis', ['hostname' => getenv('REDIS_HOST') ?: 'pramnos_redis', 'port' => 6379]],
        ];
    }

    /** A new instance in the category, with the prefix an installation configures. */
    private function cache(string $category, string $method, array $settings): Cache
    {
        $cache = new Cache($category, 'sql', $method, $settings);
        $cache->prefix  = 'nk_';
        $cache->timeout = 60;

        return $cache;
    }

    /**
     * Save, clear the category from another instance, and the value is gone.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('methods')]
    public function testClearingTheCategoryRemovesWhatWasSaved(string $method, array $settings): void
    {
        // Arrange
        if ($method === 'redis' && !class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }
        $category = 'cat_' . bin2hex(random_bytes(4));
        $writer   = $this->cache($category, $method, $settings);
        if ($writer->method !== $method) {
            $this->markTestSkipped("No {$method} cache available here.");
        }
        $writer->save('VALUE', 'k');
        $this->assertSame('VALUE', $this->cache($category, $method, $settings)->load('k'), 'the entry must exist first');

        // Act
        $this->cache($category, $method, $settings)->clear($category);

        // Assert — another instance, as the next request, finds nothing
        $this->assertFalse($this->cache($category, $method, $settings)->load('k'));
    }
}
