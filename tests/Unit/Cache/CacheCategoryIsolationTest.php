<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * `Cache::getInstance()` gives you the category you asked for.
 *
 * It held a single `static $instance` and returned it whatever category was
 * requested, so the **first** caller in the process decided the category for
 * everybody. In any application that boots providers, that first caller is
 * `CacheServiceProvider`, which asks for none.
 *
 * `$this->category` is what `_generateCacheName()` puts in the key, and what `save()`
 * and `remember()` use — `save()` has no category parameter at all. So the effect was
 * silent in both directions:
 *
 *   - `View::cache()` believed it was writing under `views`. It was not, so
 *     `cache:clear --category=views` never matched a single view fragment;
 *   - two subsystems asking for different categories shared one namespace, where a
 *     key collision between them is possible rather than prevented.
 *
 * Nothing errored, and nothing looked wrong. A category is an argument that was being
 * accepted and discarded.
 */
class CacheCategoryIsolationTest extends TestCase
{
    /**
     * Two categories are two instances.
     *
     * @return void
     */
    public function testDifferentCategoriesGiveDifferentInstances(): void
    {
        // Act
        $views  = Cache::getInstance('views');
        $schema = Cache::getInstance('schema');

        // Assert
        $this->assertNotSame(
            $views,
            $schema,
            'A category that is accepted and discarded is worse than one refused.'
        );
    }

    /**
     * The same category is still one instance.
     *
     * The point of `getInstance()` is not to be a factory; asking twice must not open
     * two connections to the cache server.
     *
     * @return void
     */
    public function testTheSameCategoryIsShared(): void
    {
        // Act & Assert
        $this->assertSame(Cache::getInstance('views'), Cache::getInstance('views'));
    }

    /**
     * Each instance keeps the category it was asked for.
     *
     * The assertion that would have failed before the fix, and the one that matters:
     * it is `$this->category` that reaches the cache key.
     *
     * @return void
     */
    public function testEachInstanceReportsItsOwnCategory(): void
    {
        // Act — read the property the cache key is built from. `getCategory()` is a
        // sanitiser for its argument rather than an accessor, which the first draft
        // of this test used as one and got '' from both.
        $category = new \ReflectionProperty(Cache::class, 'category');

        // Assert
        $this->assertSame('views', $category->getValue(Cache::getInstance('views')));
        $this->assertSame('schema', $category->getValue(Cache::getInstance('schema')));
    }

    /**
     * Asking for no category does not hand back somebody else's.
     *
     * This is the direction the bug actually arrived from: a boot-time
     * `getInstance()` with no argument became every later caller's instance. The
     * reverse must not happen either — whoever boots first must not be handed the
     * category of whoever asked first.
     *
     * @return void
     */
    public function testTheDefaultInstanceIsNotACategorisedOne(): void
    {
        // Arrange — make sure a categorised instance exists first
        Cache::getInstance('views');

        // Act
        $default = Cache::getInstance();

        // Assert
        $this->assertNotSame(Cache::getInstance('views'), $default);
    }

    /**
     * Values written under one category are not read back under another.
     *
     * The behavioural half. The instance identity above is the mechanism; this is
     * what it was for — and it is what `cache:clear --category=…` depends on being
     * true.
     *
     * @return void
     */
    public function testAValueInOneCategoryIsNotVisibleInAnother(): void
    {
        // Arrange
        $a = Cache::getInstance('isolation_a');
        $b = Cache::getInstance('isolation_b');

        if (!$a->testConnection()) {
            $this->markTestSkipped('No cache backend available in this environment.');
        }

        // Act
        $a->save('from-a', 'shared-key');

        // Assert — b must not see a's value under the same id
        $this->assertNotSame('from-a', $b->load('shared-key'));

        // Cleanup
        $a->delete('shared-key');
    }
}
