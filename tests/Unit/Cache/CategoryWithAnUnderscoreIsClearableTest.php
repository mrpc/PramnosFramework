<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\FileAdapter;
use Pramnos\Cache\Cache;

/**
 * **A cache category containing an underscore could not be cleared.**
 *
 * `FileAdapter::getFilePath()` chose the directory for an entry by splitting the
 * key on its first underscore. The key is `{category}_{id}.{ext}`, so:
 *
 *   - `userlist_<id>.sql`                 → directory `userlist`   ✔
 *   - `schema_columns_things_<id>.sql`    → directory `schema`     ✘
 *
 * `clear($category)` builds its path from the category it was handed, so it
 * looked for a directory called `schema_columns_things`, found nothing, deleted
 * nothing, and returned as though it had worked. Every category with an
 * underscore in its name was permanently unclearable, silently — and the entries
 * went on being served until they expired.
 *
 * The framework's own categories are all single words (`permissions`,
 * `userlist`, `usertokens`, `media`, `settings`, `applications`), which is why
 * this was never noticed: they land on the ✔ line above. `getColumns()`'s
 * `schema_columns_<table>` is the first one that does not, and any application
 * category with an underscore in it has the same problem.
 *
 * Somebody had already met this parsing from the other side:
 * `Cache::_generateCacheName()` strips underscores out of the *prefix* before
 * building the key, for exactly this reason. The category never got the same
 * treatment.
 *
 * The fix is in two parts, and they guard different things:
 *
 *   1. The adapter is **told** its category rather than recovering it from the
 *      key, so new entries are filed under the whole category.
 *      Guarded by {@see testTheDirectoryIsNamedAfterTheWholeCategory()}.
 *   2. `clear()` additionally sweeps the place the old layout misfiled entries,
 *      so upgrading does not leave a pile of them being served until they
 *      expire. Guarded by {@see testAFlushCollectsEntriesTheOldLayoutMisfiled()}
 *      and {@see testTheLegacySweepDoesNotTakeASiblingsEntries()}.
 *
 * **Measured, not assumed:** reverting part 1 alone reddens exactly one test —
 * the directory-naming one. The `clear()` tests keep passing, because part 2
 * catches the misfiled entry. That is the two parts working as intended rather
 * than a gap in the tests, but it is worth writing down: a reader reversing part
 * 1 and seeing seven greens would otherwise conclude the tests were not
 * checking anything.
 */
class CategoryWithAnUnderscoreIsClearableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pramnos_cachecat_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->dir);
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }

    /** A file adapter over this test's own directory, in a given category. */
    private function adapter(string $category): FileAdapter
    {
        $adapter = new FileAdapter($this->dir);
        $adapter->connect();
        $adapter->setCategory($category);

        return $adapter;
    }

    /** Every file under the cache directory, relative to it. */
    private function files(): array
    {
        $found = [];
        $walk = function (string $path, string $rel) use (&$walk, &$found): void {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $full = $path . '/' . $entry;
                $next = $rel === '' ? $entry : $rel . '/' . $entry;
                is_dir($full) ? $walk($full, $next) : $found[] = $next;
            }
        };
        $walk($this->dir, '');
        sort($found);

        return $found;
    }

    // ── The reported case ───────────────────────────────────────────────────

    /**
     * The case that could not be cleared: a category with two underscores in it.
     *
     * This is `getColumns()`'s, and it is why a generated model could describe a
     * table as it was before the migration that changed it — the flush that was
     * supposed to prevent that did nothing.
     */
    public function testAnUnderscoredCategoryIsCleared(): void
    {
        // Arrange
        $adapter = $this->adapter('schema_columns_things');
        $adapter->save('schema_columns_things_abc123.sql', 'stale', 3600);
        $this->assertNotSame([], $this->files(), 'the entry must exist first');

        // Act
        $adapter->clear('schema_columns_things');

        // Assert
        $this->assertSame([], $this->files());
        $this->assertFalse(
            $adapter->load('schema_columns_things_abc123.sql', 3600),
            'and it must not be served after the flush'
        );
    }

    /**
     * A single-word category still clears — the case that always worked, and
     * which the fix must not break.
     *
     * Stated separately because "the new case works" and "the old case still
     * works" are different claims, and the framework's own six categories are
     * all the second kind.
     */
    public function testASingleWordCategoryStillClears(): void
    {
        // Arrange
        $adapter = $this->adapter('userlist');
        $adapter->save('userlist_abc123.sql', 'stale', 3600);
        $this->assertNotSame([], $this->files());

        // Act
        $adapter->clear('userlist');

        // Assert
        $this->assertSame([], $this->files());
    }

    /**
     * Clearing one category leaves the others alone.
     *
     * A flush that emptied the whole store would "pass" the test above while
     * throwing away every other subsystem's cache — which is worse than not
     * clearing at all, and is the failure a category exists to prevent.
     */
    public function testClearingOneCategoryLeavesTheOthers(): void
    {
        // Arrange
        $this->adapter('schema_columns_things')
            ->save('schema_columns_things_a.sql', 'x', 3600);
        $this->adapter('schema_columns_widgets')
            ->save('schema_columns_widgets_b.sql', 'y', 3600);
        $this->adapter('userlist')->save('userlist_c.sql', 'z', 3600);

        // Act
        $this->adapter('schema_columns_things')->clear('schema_columns_things');

        // Assert — only the one asked for is gone.
        $remaining = $this->files();
        $this->assertNotContains(
            'schema_columns_things/schema_columns_things_a.sql', $remaining
        );
        $this->assertTrue(
            (bool) $this->adapter('schema_columns_widgets')
                ->load('schema_columns_widgets_b.sql', 3600),
            'a sibling category must survive'
        );
        $this->assertTrue(
            (bool) $this->adapter('userlist')->load('userlist_c.sql', 3600),
            'an unrelated category must survive'
        );
    }

    /**
     * The entry lands in a directory named after the whole category, so a
     * reader looking at `var/cache/` can see which subsystem owns what.
     *
     * It used to land in the first segment, so everything beginning
     * `schema_columns_` piled into one `schema/` directory alongside anything
     * else whose category happened to start with the same word.
     */
    public function testTheDirectoryIsNamedAfterTheWholeCategory(): void
    {
        // Arrange / Act
        $this->adapter('schema_columns_things')
            ->save('schema_columns_things_a.sql', 'x', 3600);

        // Assert
        $this->assertSame(
            ['schema_columns_things/schema_columns_things_a.sql'],
            $this->files()
        );
    }

    // ── Entries the old layout already misfiled ─────────────────────────────

    /**
     * A flush also collects what the previous layout put in the wrong place.
     *
     * Without this, upgrading leaves every misfiled entry on disk, still being
     * served until it expires — so the bug would outlive its own fix for an
     * hour, which is exactly as long as the staleness that made it worth
     * fixing.
     *
     * The match is on the file name, which is where the whole category has
     * always been, and it is anchored with the same `_` separator the key is
     * built with — so it cannot take a neighbouring category's entries.
     */
    public function testAFlushCollectsEntriesTheOldLayoutMisfiled(): void
    {
        // Arrange — by hand, in the place the old getFilePath() would have
        // chosen: directory `schema`, file named for the full category.
        mkdir($this->dir . '/schema', 0777, true);
        $misfiled = $this->dir . '/schema/schema_columns_things_a.sql';
        file_put_contents(
            $misfiled,
            serialize(['data' => 'stale', 'time' => time(), 'timeout' => 3600])
        );

        // Act
        $this->adapter('schema_columns_things')->clear('schema_columns_things');

        // Assert
        $this->assertFileDoesNotExist($misfiled);
    }

    /**
     * The legacy sweep does not take a different category's entries out of the
     * same directory.
     *
     * `schema_columns_things` and `schema_columns_widgets` both landed in
     * `schema/` under the old layout, so a sweep that matched on the directory
     * — or on the first segment — would clear both when asked for one.
     */
    public function testTheLegacySweepDoesNotTakeASiblingsEntries(): void
    {
        // Arrange
        mkdir($this->dir . '/schema', 0777, true);
        $mine = $this->dir . '/schema/schema_columns_things_a.sql';
        $theirs = $this->dir . '/schema/schema_columns_widgets_b.sql';
        foreach ([$mine, $theirs] as $file) {
            file_put_contents(
                $file,
                serialize(['data' => 'x', 'time' => time(), 'timeout' => 3600])
            );
        }

        // Act
        $this->adapter('schema_columns_things')->clear('schema_columns_things');

        // Assert
        $this->assertFileDoesNotExist($mine);
        $this->assertFileExists($theirs);
    }

    // ── Through Cache, which is how everything reaches the adapter ──────────

    /**
     * A flush through `Cache` clears an underscored category.
     *
     * This is the path every caller actually uses —
     * `Database::cacheflush($category)` goes through here — so the
     * adapter-level tests above are necessary and not sufficient. The adapter
     * is built once and shared while the category is chosen per call
     * (`Database::cacheRead()` assigns it right before reading), so an adapter
     * that kept its constructor's category would file every entry under
     * whichever one happened to be first.
     *
     * It uses the configured cache directory rather than this test's own,
     * because that is the wiring under test; the category is unique to the run
     * so it cannot collide with anything real.
     */
    public function testAFlushThroughCacheClearsAnUnderscoredCategory(): void
    {
        // Arrange
        $category = 'probe_columns_' . bin2hex(random_bytes(4));
        $cache = new Cache($category, 'sql', 'file');
        $cache->timeout = 3600;
        $cache->save('payload', 'abc123');

        // Assert the premise: it was stored and can be read back.
        $this->assertSame('payload', $cache->load('abc123'));

        try {
            // Act
            $cache->clear($category);

            // Assert
            $this->assertFalse(
                $cache->load('abc123'),
                'a category with underscores must be clearable through Cache'
            );
        } finally {
            $cache->clear($category);
        }
    }

    /**
     * A Cache with no working adapter does nothing rather than fatalling.
     *
     * `initializeAdapter()` can leave the adapter null — every configured store
     * failed to connect and the file fallback could not be built either — and
     * `load()`, `save()` and `clear()` all already guard for it. Handing the
     * adapter its prefix and category has to guard for the same thing, or the
     * guard below it never runs.
     */
    public function testANullAdapterIsToleratedWhenSyncing(): void
    {
        // Arrange — reach in and take the adapter away, which is the state a
        // failed connection leaves behind.
        $cache = new Cache('probe_' . bin2hex(random_bytes(4)), 'sql', 'file');
        $property = new \ReflectionProperty(Cache::class, 'adapter');
        $property->setValue($cache, null);

        // Act + Assert — no error, and the documented "nothing happened"
        // answers rather than an exception.
        $this->assertFalse($cache->save('x', 'k'));
        $this->assertFalse($cache->load('k'));
        $this->assertFalse($cache->clear('anything'));
    }

    /**
     * Clearing one underscored category through `Cache` does not take its
     * neighbour's entries.
     *
     * Both share a first segment, which is the segment the old code filed them
     * under — so a fix that swept by that segment would clear both and pass
     * every other test here.
     */
    public function testAFlushThroughCacheSparesASiblingCategory(): void
    {
        // Arrange
        $suffix = bin2hex(random_bytes(4));
        $mine   = 'probe_columns_mine_' . $suffix;
        $theirs = 'probe_columns_theirs_' . $suffix;

        $mineCache = new Cache($mine, 'sql', 'file');
        $mineCache->timeout = 3600;
        $mineCache->save('a', 'k');

        $theirsCache = new Cache($theirs, 'sql', 'file');
        $theirsCache->timeout = 3600;
        $theirsCache->save('b', 'k');

        try {
            // Act
            $mineCache->clear($mine);

            // Assert
            $this->assertFalse($mineCache->load('k'));
            $this->assertSame('b', $theirsCache->load('k'));
        } finally {
            $mineCache->clear($mine);
            $theirsCache->clear($theirs);
        }
    }
}
