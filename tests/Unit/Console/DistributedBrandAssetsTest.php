<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * **`brand/logos/` must ship in the Packagist dist archive — `Init::scaffoldLogo()` has
 * nothing to copy from otherwise.**
 *
 * `.gitattributes` once marked `/brand/logos export-ignore`, on the stated grounds that it
 * was "docs-only" art — it is not: `Init::scaffoldLogo()` copies
 * `pramnos-logo-wide{,-inverse}.png` from it into every scaffolded project's
 * `www/assets/img/`. That method's own "no-op when the brand directory is absent" branch
 * treated a trimmed dist install as an acceptable, silent possibility, so nothing in
 * `init`'s output ever said the logo was never written: `www/assets/img/logo.png`/
 * `logo-inverse.png` simply did not exist, and the theme header's `<img>` 404'd forever on
 * every project scaffolded from the published package. `brand/favicons/` was never excluded
 * and is the working comparison — `Init::scaffoldFavicons()` reads it the same way.
 *
 * A real `git archive` is not run here — this asserts the `.gitattributes` rule directly,
 * which is what actually controls Composer's dist tarball and is fast and hermetic.
 */
class DistributedBrandAssetsTest extends TestCase
{
    private function gitattributes(): string
    {
        return (string) file_get_contents(ROOT . DIRECTORY_SEPARATOR . '.gitattributes');
    }

    /** @return list<string> Paths after `/brand`, as found before ` export-ignore`. */
    private function exportIgnoredBrandPaths(): array
    {
        preg_match_all(
            '/^(\/brand\/\S+)\s+export-ignore\s*$/m',
            $this->gitattributes(),
            $matches
        );

        return $matches[1];
    }

    public function testBrandLogosIsNotExportIgnored(): void
    {
        $this->assertNotContains(
            '/brand/logos',
            $this->exportIgnoredBrandPaths(),
            '/brand/logos is copied at scaffold time by Init::scaffoldLogo() and must ship in the dist package'
        );
    }

    /** The working comparison: favicons were never excluded, and still must not be. */
    public function testBrandFaviconsIsNotExportIgnored(): void
    {
        $this->assertNotContains(
            '/brand/favicons',
            $this->exportIgnoredBrandPaths(),
            '/brand/favicons is copied at scaffold time by Init::scaffoldFavicons() and must ship in the dist package'
        );
    }

    /** The regeneration source art genuinely is docs-only and stays excluded. */
    public function testBrandFaviconSourceRemainsExcluded(): void
    {
        $this->assertContains('/brand/favicon-source', $this->exportIgnoredBrandPaths());
    }
}
