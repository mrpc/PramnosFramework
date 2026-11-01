<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * Streams sitemap entries to disk, sharding at the protocol's limits and checking the
 * all-or-nothing rule for hreflang groups — the mechanics behind `sitemap:generate`, kept
 * separate from the command so they are testable with a handful of fake entries instead of
 * fifty thousand real ones.
 *
 * ```php
 * $writer = new SitemapWriter($outputPath, baseUrl: 'https://example.com');
 * $result = $writer->write($entries, allowEmpty: false);
 * // ['count' => int, 'shards' => int, 'warnings' => list<string>]
 * ```
 *
 * **Wholesale, not incremental.** Every call rewrites every shard from scratch, numbered in
 * the order `$entries` yields them; a previous run's now-unneeded shard files are deleted.
 * Rewriting a few MB of XML is cheap — the complexity of tracking which URL lives in which
 * shard across runs is not worth it for a cost that isn't real
 * (`docs/Hreflang-And-Sitemap-Design.md` §9.3).
 *
 * **One plain file under the limit.** A site with one shard's worth of URLs gets exactly
 * `$outputPath` as an ordinary `<urlset>` — no index, no numbered file. The index only exists
 * once sharding is actually needed.
 *
 * **Streamed, never buffered.** `\XMLWriter::openURI()` writes straight to the shard file as
 * `$entries` is iterated; nothing assembles the whole document in memory first
 * (`docs/Hreflang-And-Sitemap-Design.md` §7).
 *
 * **§6 reciprocity**, checked without a second pass over `$entries` (which may be a
 * one-shot generator): every `loc` is remembered in a small set as it streams, and every
 * entry's `alternates` in an equally small list, so the check after the stream ends is a
 * loop over strings already in memory, not a second trip through whatever produced them.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class SitemapWriter
{
    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * @param string      $outputPath       Where the final sitemap (or index) is written.
     * @param int         $maxUrlsPerShard  Protocol limit is 50,000; lowered in tests to
     *                                      exercise sharding without 50,000 real entries.
     * @param int         $maxBytesPerShard Protocol limit is 50MB.
     * @param string|null $baseUrl          Required only when more than one shard is
     *                                      produced — the index's `<loc>` entries need an
     *                                      absolute URL, not a file path.
     */
    public function __construct(
        private readonly string $outputPath,
        private readonly int $maxUrlsPerShard = 50000,
        private readonly int $maxBytesPerShard = 50_000_000,
        private readonly ?string $baseUrl = null
    ) {
    }

    /**
     * @param  iterable<array<string, mixed>> $entries  Each at least `['loc' => string]`;
     *                                                    see {@see Sitemap} for the full shape.
     * @param  bool $allowEmpty  Write an empty `<urlset>` instead of refusing, when $entries
     *                           yields nothing — the same guard `api:docs --allow-empty` has,
     *                           for the same reason: an empty file silently overwriting a good
     *                           one is the worst available outcome.
     * @return array{count: int, shards: int, warnings: list<string>}
     * @throws \RuntimeException  Empty with $allowEmpty false, or an index is needed but no
     *                            $baseUrl was given.
     */
    public function write(iterable $entries, bool $allowEmpty = false): array
    {
        $dir = dirname($this->outputPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory does not exist: {$dir}");
        }
        $base = (string) pathinfo($this->outputPath, PATHINFO_FILENAME);

        $shardPaths = [];
        $locSet     = [];
        $pending    = [];
        $count      = 0;
        $writer     = null;
        $urlsInShard = 0;

        foreach ($entries as $entry) {
            $loc = trim((string) ($entry['loc'] ?? ''));
            if ($loc === '') {
                continue; // malformed entry — no URL to list, nothing to do with it
            }

            if ($writer === null) {
                $shardPaths[] = $dir . '/' . $base . '-' . (count($shardPaths) + 1) . '.xml';
                $writer = $this->openShard($shardPaths[count($shardPaths) - 1]);
                $urlsInShard = 0;
            }

            $this->writeUrlElement($writer, $loc, $entry);

            $locSet[$loc] = true;
            if (!empty($entry['alternates']) && is_array($entry['alternates'])) {
                $pending[] = [$loc, $entry['alternates']];
            }
            $count++;
            $urlsInShard++;

            $writer->flush();
            $currentShardPath = $shardPaths[count($shardPaths) - 1];
            if ($urlsInShard >= $this->maxUrlsPerShard || filesize($currentShardPath) >= $this->maxBytesPerShard) {
                $this->closeShard($writer);
                $writer = null;
            }
        }

        if ($writer !== null) {
            $this->closeShard($writer);
        }

        if ($count === 0) {
            // $shardPaths is only ever populated alongside $count, so there is nothing
            // to clean up here — an empty write never opened a shard in the first place.
            if (!$allowEmpty) {
                throw new \RuntimeException(
                    'Refusing to write an empty sitemap: pass allowEmpty to write one anyway.'
                );
            }
            $this->writeEmptyUrlset();
            $this->removeStaleShards($dir, $base, []);
            return ['count' => 0, 'shards' => 0, 'warnings' => []];
        }

        $warnings = $this->checkReciprocity($locSet, $pending);

        if (count($shardPaths) === 1) {
            rename($shardPaths[0], $this->outputPath);
            $finalShards = [$this->outputPath];
        } else {
            $this->writeIndex($shardPaths);
            $finalShards = $shardPaths;
        }

        $this->removeStaleShards($dir, $base, $finalShards);

        return ['count' => $count, 'shards' => count($shardPaths), 'warnings' => $warnings];
    }

    private function openShard(string $path): \XMLWriter
    {
        $writer = new \XMLWriter();
        $writer->openURI($path);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::XMLNS);
        $writer->writeAttribute('xmlns:xhtml', self::XMLNS_XHTML);

        return $writer;
    }

    private function closeShard(\XMLWriter $writer): void
    {
        $writer->endElement(); // urlset
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function writeUrlElement(\XMLWriter $writer, string $loc, array $entry): void
    {
        $writer->startElement('url');
        $writer->writeElement('loc', $loc);

        if (!empty($entry['lastmod'])) {
            $writer->writeElement('lastmod', (string) $entry['lastmod']);
        }
        if (!empty($entry['changefreq'])) {
            $writer->writeElement('changefreq', (string) $entry['changefreq']);
        }
        if (isset($entry['priority']) && $entry['priority'] !== '') {
            $writer->writeElement('priority', (string) $entry['priority']);
        }

        if (!empty($entry['alternates']) && is_array($entry['alternates'])) {
            foreach ($entry['alternates'] as $locale => $url) {
                $writer->startElement('xhtml:link');
                $writer->writeAttribute('rel', 'alternate');
                $writer->writeAttribute('hreflang', (string) $locale);
                $writer->writeAttribute('href', (string) $url);
                $writer->endElement();
            }
        }

        $writer->endElement(); // url
    }

    private function writeEmptyUrlset(): void
    {
        $writer = new \XMLWriter();
        $writer->openURI($this->outputPath);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::XMLNS);
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * @param  list<string> $shardPaths
     */
    private function writeIndex(array $shardPaths): void
    {
        if ($this->baseUrl === null || trim($this->baseUrl) === '') {
            throw new \RuntimeException(
                'More than one shard was produced, which needs a sitemap index — pass baseUrl '
                . 'to the constructor so its <loc> entries can be absolute URLs.'
            );
        }

        $writer = new \XMLWriter();
        $writer->openURI($this->outputPath);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', self::XMLNS);

        foreach ($shardPaths as $path) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', rtrim($this->baseUrl, '/') . '/' . basename($path));
            $writer->endElement();
        }

        $writer->endElement(); // sitemapindex
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * Every alternate URL an entry named must itself be listed somewhere in this sitemap —
     * the all-or-nothing rule for a locale group (§6). A violation becomes a warning, not a
     * failure: the crawler-side cost of a non-reciprocal set is already contained to that
     * group's own hreflang being discarded (§3); failing the whole sitemap over it would be
     * worse than publishing it with the inconsistency named.
     *
     * @param  array<string, true>                          $locSet
     * @param  list<array{0: string, 1: array<string, string>}> $pending
     * @return list<string>
     */
    private function checkReciprocity(array $locSet, array $pending): array
    {
        $warnings = [];
        foreach ($pending as [$loc, $alternates]) {
            foreach ($alternates as $locale => $url) {
                $url = (string) $url;
                if ($url !== $loc && !isset($locSet[$url])) {
                    $warnings[] = sprintf(
                        '"%s" names "%s" (%s) as an alternate, but that URL is not itself '
                        . 'listed in the sitemap — the all-or-nothing rule for a locale group '
                        . 'is broken.',
                        $loc,
                        $url,
                        $locale
                    );
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Deletes numbered shard files from a previous, larger run that this run didn't
     * recreate — wholesale regeneration means the old ones should not keep being crawled.
     *
     * @param list<string> $keep
     */
    private function removeStaleShards(string $dir, string $base, array $keep): void
    {
        foreach (glob($dir . '/' . $base . '-*.xml') ?: [] as $existing) {
            if (!in_array($existing, $keep, true)) {
                @unlink($existing);
            }
        }
    }
}
