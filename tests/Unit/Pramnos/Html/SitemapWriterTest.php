<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\SitemapWriter;

/**
 * Streams sitemap entries to disk, sharding at the protocol's limits and checking the
 * all-or-nothing hreflang-group rule (docs/Hreflang-And-Sitemap-Design.md §6).
 *
 * maxUrlsPerShard/maxBytesPerShard are overridden to tiny values throughout, so sharding is
 * exercised with a handful of entries instead of 50,000 real ones.
 */
#[CoversClass(SitemapWriter::class)]
class SitemapWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sitemapwriter_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function entries(int $n): \Generator
    {
        for ($i = 1; $i <= $n; $i++) {
            yield ['loc' => "https://example.com/page-{$i}"];
        }
    }

    /**
     * Under the shard limit, exactly one plain file is written at
     * $outputPath — no index, no numbered shard file — the common case
     * design doc §7 calls for ("a lean sitemap" when sharding isn't needed).
     */
    public function testUnderTheLimitWritesOnePlainFileWithNoIndex(): void
    {
        // Arrange
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output, maxUrlsPerShard: 10);

        // Act
        $result = $writer->write($this->entries(3));

        // Assert
        $this->assertSame(['count' => 3, 'shards' => 1, 'warnings' => []], $result);
        $this->assertFileExists($output);
        $this->assertFileDoesNotExist($this->dir . '/sitemap-1.xml');
        $xml = (string) file_get_contents($output);
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringNotContainsString('<sitemapindex', $xml);
        $this->assertSame(3, substr_count($xml, '<loc>'));
    }

    /**
     * Past the per-shard URL limit, entries are split across numbered
     * shard files and $outputPath becomes a <sitemapindex> pointing at
     * them — the mandatory shape once sharding is actually needed.
     */
    public function testOverTheUrlLimitShardsAndWritesAnIndex(): void
    {
        // Arrange — 5 entries, 2 per shard → 3 shards
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output, maxUrlsPerShard: 2, baseUrl: 'https://example.com');

        // Act
        $result = $writer->write($this->entries(5));

        // Assert
        $this->assertSame(['count' => 5, 'shards' => 3, 'warnings' => []], $result);
        $this->assertFileExists($this->dir . '/sitemap-1.xml');
        $this->assertFileExists($this->dir . '/sitemap-2.xml');
        $this->assertFileExists($this->dir . '/sitemap-3.xml');

        $index = (string) file_get_contents($output);
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString('https://example.com/sitemap-1.xml', $index);
        $this->assertStringContainsString('https://example.com/sitemap-3.xml', $index);

        // Shards hold 2, 2 and 1 URLs respectively.
        $this->assertSame(2, substr_count((string) file_get_contents($this->dir . '/sitemap-1.xml'), '<loc>'));
        $this->assertSame(1, substr_count((string) file_get_contents($this->dir . '/sitemap-3.xml'), '<loc>'));
    }

    /**
     * Writing an index without a base URL refuses rather than emit a
     * <sitemapindex> whose <loc> entries are bare file paths — not a URL a
     * crawler could follow.
     */
    public function testIndexWithoutABaseUrlThrows(): void
    {
        // Arrange
        $writer = new SitemapWriter($this->dir . '/sitemap.xml', maxUrlsPerShard: 2);

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $writer->write($this->entries(5));
    }

    /**
     * Zero entries refuses by default — the same guard api:docs
     * --allow-empty has, and for the same reason: an empty file silently
     * overwriting a good one is the worst available outcome.
     */
    public function testEmptyRefusesByDefault(): void
    {
        // Arrange
        $writer = new SitemapWriter($this->dir . '/sitemap.xml');

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $writer->write($this->entries(0));
    }

    /**
     * allowEmpty writes a valid, empty <urlset> rather than refusing — the
     * stated case of an application that genuinely has nothing yet.
     */
    public function testAllowEmptyWritesAnEmptyUrlset(): void
    {
        // Arrange
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output);

        // Act
        $result = $writer->write($this->entries(0), allowEmpty: true);

        // Assert
        $this->assertSame(['count' => 0, 'shards' => 0, 'warnings' => []], $result);
        $this->assertFileExists($output);
        $this->assertStringContainsString('<urlset', (string) file_get_contents($output));
    }

    /**
     * An entry's alternates must all themselves be listed in the sitemap —
     * the all-or-nothing rule for a locale group (§6). One missing from
     * the set becomes one warning naming it, not a failure.
     */
    public function testReciprocityViolationIsWarnedNotFailed(): void
    {
        // Arrange — 'en' is named as an alternate but never itself listed
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output);
        $entries = (function (): \Generator {
            yield [
                'loc'        => 'https://example.com/el',
                'alternates' => ['el' => 'https://example.com/el', 'en' => 'https://example.com/en'],
            ];
        })();

        // Act
        $result = $writer->write($entries);

        // Assert — the sitemap is still written (a warning, not a failure)
        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('https://example.com/en', $result['warnings'][0]);
        $this->assertFileExists($output);
    }

    /**
     * A reciprocal group — every alternate also listed as its own entry —
     * produces no warning at all.
     */
    public function testReciprocalGroupHasNoWarnings(): void
    {
        // Arrange
        $writer = new SitemapWriter($this->dir . '/sitemap.xml');
        $alternates = ['el' => 'https://example.com/el', 'en' => 'https://example.com/en'];
        $entries = (function () use ($alternates): \Generator {
            yield ['loc' => 'https://example.com/el', 'alternates' => $alternates];
            yield ['loc' => 'https://example.com/en', 'alternates' => $alternates];
        })();

        // Act
        $result = $writer->write($entries);

        // Assert
        $this->assertSame([], $result['warnings']);
    }

    /**
     * A rerun with fewer shards than a previous run deletes the now-stale
     * numbered shard files — wholesale regeneration means an old one must
     * not keep being crawled.
     */
    public function testRerunWithFewerShardsDeletesTheStaleOnes(): void
    {
        // Arrange — first run produces 3 shards
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output, maxUrlsPerShard: 2, baseUrl: 'https://example.com');
        $writer->write($this->entries(5));
        $this->assertFileExists($this->dir . '/sitemap-3.xml');

        // Act — second run fits in one shard
        $writer = new SitemapWriter($output, maxUrlsPerShard: 10, baseUrl: 'https://example.com');
        $writer->write($this->entries(2));

        // Assert — the old numbered shards are gone, only the plain file remains
        $this->assertFileDoesNotExist($this->dir . '/sitemap-1.xml');
        $this->assertFileDoesNotExist($this->dir . '/sitemap-2.xml');
        $this->assertFileDoesNotExist($this->dir . '/sitemap-3.xml');
        $this->assertFileExists($output);
    }

    /**
     * A directory that does not exist refuses clearly rather than letting
     * \XMLWriter::openURI() fail silently.
     */
    public function testNonexistentDirectoryThrows(): void
    {
        // Arrange
        $writer = new SitemapWriter($this->dir . '/does-not-exist/sitemap.xml');

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $writer->write($this->entries(1));
    }

    /**
     * An entry with no `loc` at all is skipped rather than written as a
     * blank <url> — there is no URL to list.
     */
    public function testEntryWithoutLocIsSkipped(): void
    {
        // Arrange
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output);
        $entries = (function (): \Generator {
            yield ['loc' => 'https://example.com/a'];
            yield ['foo' => 'bar'];
        })();

        // Act
        $result = $writer->write($entries);

        // Assert
        $this->assertSame(1, $result['count']);
    }

    /**
     * lastmod, changefreq and priority reach the written XML when given.
     */
    public function testOptionalFieldsAreWritten(): void
    {
        // Arrange
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output);
        $entries = (function (): \Generator {
            yield [
                'loc'        => 'https://example.com/a',
                'lastmod'    => '2026-11-01',
                'changefreq' => 'weekly',
                'priority'   => 0.8,
            ];
        })();

        // Act
        $writer->write($entries);

        // Assert
        $xml = (string) file_get_contents($output);
        $this->assertStringContainsString('<lastmod>2026-11-01</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    /**
     * Refusing an empty write (allowEmpty: false) must not leave a shard
     * file half-written behind it.
     */
    public function testRefusedEmptyWriteLeavesNoShardFile(): void
    {
        // Arrange
        $output = $this->dir . '/sitemap.xml';
        $writer = new SitemapWriter($output);

        // Act
        try {
            $writer->write($this->entries(0));
        } catch (\RuntimeException) {
            // expected
        }

        // Assert
        $this->assertFileDoesNotExist($this->dir . '/sitemap-1.xml');
        $this->assertFileDoesNotExist($output);
    }
}
