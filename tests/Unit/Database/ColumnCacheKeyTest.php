<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * The cache key a table's schema is stored under resolves the prefix.
 *
 * `getColumns()` caches a table's column list, and callers name tables the way
 * the rest of the framework does — with `#PREFIX#`. The marker used to reach the
 * cache untouched, and the adapter's name sanitiser strips `#`, so the category
 * became `schema_columns_PREFIXusers`.
 *
 * Two things followed from that. It showed the marker in the DevPanel's namespace
 * list, which is how it was noticed. And — the part that matters — **two
 * installations with different prefixes shared one entry**: `#PREFIX#users` is
 * `alpha_users` on one and `beta_users` on another, and both wrote their column
 * list to the same key. On a shared Redis that is one installation's schema
 * answering the other's `getColumns()`, and those columns decide model fields and
 * generated forms.
 */
class ColumnCacheKeyTest extends TestCase
{
    /**
     * Two prefixes, two keys.
     *
     * This is the whole bug in one assertion: with the marker left in, both of
     * these produced the same string.
     */
    public function testDifferentPrefixesGetDifferentKeys(): void
    {
        // Act
        $alpha = Database::columnCacheCategory('#PREFIX#users', 'alpha_');
        $beta  = Database::columnCacheCategory('#PREFIX#users', 'beta_');

        // Assert
        $this->assertNotSame($alpha, $beta,
            'two installations must not share one schema cache entry');
        $this->assertSame('schema_columns_alpha_users', $alpha);
        $this->assertSame('schema_columns_beta_users', $beta);
    }

    /**
     * The marker never reaches the key.
     *
     * Asserted on the marker itself rather than on the whole string, because what
     * makes it wrong is that it is unresolved — the exact spelling that comes out
     * of the sanitiser (`PREFIXusers`) is incidental.
     */
    public function testTheMarkerIsNeverPartOfTheKey(): void
    {
        // Act
        $key = Database::columnCacheCategory('#PREFIX#usertokens', 'msd_');

        // Assert
        $this->assertStringNotContainsString('#PREFIX#', $key);
        $this->assertStringNotContainsString('PREFIX', $key,
            'the sanitiser strips the hashes and leaves the word behind');
    }

    /**
     * An empty prefix is a real answer, not a missing one.
     *
     * Most installations have none, and `schema_columns_users` is the correct key
     * for them — this must not become a special case that behaves differently.
     */
    public function testAnEmptyPrefixResolvesToThePlainName(): void
    {
        // Act & Assert
        $this->assertSame(
            'schema_columns_users',
            Database::columnCacheCategory('#PREFIX#users', '')
        );
    }

    /**
     * A name with no marker is left alone.
     *
     * `authserver.roles` and any already-resolved name reach this method too, and
     * substituting nothing must leave them exactly as they are.
     */
    public function testANameWithoutTheMarkerIsUnchanged(): void
    {
        // Act & Assert
        $this->assertSame(
            'schema_columns_authserver.roles',
            Database::columnCacheCategory('authserver.roles', 'msd_')
        );
    }
}
