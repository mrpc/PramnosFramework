<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * The scaffolded SPA stylesheet names its sources and scans nothing else.
 *
 * WHAT: `@import "tailwindcss" source(none);` wherever the stub also writes an `@source`.
 *
 * WHY:  in Tailwind v4 `@source` **adds to** automatic detection rather than replacing it,
 *       and detection starts at the git root. So a stylesheet that carefully named
 *       `./**​/*.{svelte,js}` also scanned `src/`, `app/`, `www/` and every previous build
 *       output still on disk.
 *
 *       Two costs. The bundle carried rules for classes that appear only in
 *       server-rendered `src/Views/*.php` — 63 KB, about 30%, that the SPA can never use,
 *       in a project whose two halves deliberately have separate stylesheets. And **two
 *       builds of identical sources produced different filenames**: the bundle is
 *       content-hashed and committed, so it reads as a non-deterministic build and was
 *       investigated as one twice. The scan reaches over a VirtioFS bind mount, where the
 *       container sees part of the tree a beat late and which part varies per run.
 *
 *       Naming the sources and then scanning everything anyway is not a combination
 *       anybody asks for.
 */
#[CoversClass(Init::class)]
class ScaffoldedTailwindSourcesTest extends TestCase
{
    private function stub(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/' . $name
        );
    }

    /**
     * A stub that names an `@source` imports Tailwind with `source(none)`.
     *
     * Asserted as the pairing rather than as one line, because either alone is fine: a
     * stylesheet with no `@source` should keep automatic detection, and `source(none)`
     * without an `@source` would scan nothing at all and produce an empty build.
     */
    public function testAStubThatNamesItsSourcesScansNothingElse(): void
    {
        // Arrange
        $offenders = [];
        $checked   = 0;

        // Act
        foreach (glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.css.stub') ?: [] as $path) {
            $css = (string) file_get_contents($path);

            if (!str_contains($css, '@source ')) {
                continue;
            }

            $checked++;

            if (!preg_match('/@import\s+"tailwindcss"\s+source\(none\)/', $css)) {
                $offenders[] = basename($path);
            }
        }

        // Assert — that something was examined first: a renamed stub would leave this
        // sweeping nothing, and nothing has no offenders.
        $this->assertGreaterThan(0, $checked, 'no stylesheet stub names an @source, so this checks nothing');
        $this->assertSame(
            [],
            $offenders,
            "These name their sources and then let Tailwind scan the whole repository\n"
            . "anyway — 30% of stylesheet nothing can use, and a hash that flaps:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * And the SPA stylesheet still names the sources it does want.
     *
     * The other half: `source(none)` with no `@source` scans nothing and builds an empty
     * stylesheet, which is a worse failure and a quieter one — the page renders unstyled
     * and the build reports success.
     */
    public function testTheSpaStylesheetStillNamesItsOwnSources(): void
    {
        // Arrange
        $css = $this->stub('spa-app.css.stub');

        // Assert
        $this->assertStringContainsString('@source "./**/*.{svelte,js}"', $css);
        $this->assertStringContainsString('@import "tailwindcss" source(none);', $css);
    }
}
