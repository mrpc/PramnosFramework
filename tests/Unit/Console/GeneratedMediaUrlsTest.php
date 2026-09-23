<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * Generated code asks for a media address; it never assembles one.
 *
 * WHAT: no stub concatenates `sURL` with a stored media path, and the generated detail view
 *       renders a picture through `MediaObject::urlFor()`.
 *
 * WHY:  `sURL . $media->url` is the obvious line and it is wrong twice, both times in a way
 *       that looks right on one machine:
 *
 *       - **`sURL` is the *script's* base**, not the site's. The same line inside an API
 *         request answers `https://site/api/uploads/x.png` — absolute, well-formed, and a
 *         404 that every "is this fetchable" check accepts. One reached an external service
 *         which fetched it, got a 404, and reported a container error naming no URL.
 *       - **Once a `media` storage disk is configured the file is on a bucket**, and the
 *         concatenation keeps working and keeps pointing at the local copy — which on more
 *         than one server is the node that happened to receive the upload.
 *
 *       Generated code is read as an example. Whatever it does, an application does in the
 *       next fifty views it writes by hand.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedMediaUrlsTest extends TestCase
{
    /** @return array<string, string> Stub name => contents */
    private function stubs(): array
    {
        $found = [];

        foreach (glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.stub') ?: [] as $path) {
            $found[basename($path)] = (string) file_get_contents($path);
        }

        return $found;
    }

    /**
     * No stub builds a media address by concatenation.
     *
     * The sweep is narrow on purpose: `sURL . 'Things/edit/'` is a link to a *page* of this
     * application and is entirely correct — `sURL` is exactly right for that, which is what
     * it is for. What is forbidden is joining it to a stored file path.
     */
    public function testNoStubConcatenatesASiteUrlWithAStoredPath(): void
    {
        // Arrange
        $stubs = $this->stubs();
        $this->assertNotEmpty($stubs, 'no stubs were read, so this checks nothing');

        $offenders = [];

        // Act — `sURL` (or SiteUrl) followed by something that is a stored media path
        foreach ($stubs as $name => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                // Comments out first. The stubs explain *why* this is forbidden, quoting
                // the forbidden line to do it — and a guard that reports its own
                // documentation is one somebody deletes. The same false positive caught
                // `GeneratedCodeNamesRealClassesTest` on the day it was written.
                if (preg_match('#^\s*(\*|//|/\*)#', $line)) {
                    continue;
                }

                if (!preg_match('/sURL\s*\.\s*\$\w+->url|sURL\s*\.\s*[\'"]uploads/', $line)) {
                    continue;
                }

                $offenders[] = $name . ':' . ($number + 1) . ' — ' . trim($line);
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These build a file's address out of the script's base URL. It is a 404 from\n"
            . "an API request, and it points at the local copy once a media disk exists:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * The detail view renders a picture through `urlFor()`.
     *
     * The other half. A sweep forbidding the wrong thing passes perfectly on generated code
     * that renders no pictures at all — which is what it did until the detail view learned
     * to, and is why this asserts the right thing is present rather than only that the
     * wrong one is absent.
     */
    public function testTheDetailViewRendersAPictureThroughUrlFor(): void
    {
        // Arrange
        $themes = ['tailwind', 'bootstrap', 'plain'];

        // Act + Assert
        foreach ($themes as $theme) {
            $stub = $this->stubs()['crud-view-' . $theme . '-show.stub'] ?? '';
            $this->assertNotSame('', $stub, 'the ' . $theme . ' detail stub was not found');

            $this->assertStringContainsString(
                'MediaObject::urlFor(',
                $stub,
                $theme . ' renders a media column without asking for its address'
            );
            $this->assertStringContainsString(
                '$mediaColumns',
                $stub,
                $theme . ' has no list of which columns are pictures'
            );
        }
    }

    /**
     * And the generator fills that list from the foreign keys.
     *
     * From the keys rather than from column names, because a foreign key to `media` **is**
     * the framework's statement that a column holds a picture. A name-based rule would
     * catch a column somebody called `logo` and meant as a word, and would miss
     * `header_id` — and guessing wrong renders a broken `<img>` where a value belonged.
     */
    public function testTheGeneratorFillsTheMediaColumnsFromForeignKeys(): void
    {
        // Arrange
        $generator = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/MakeCommandBase.php'
        );

        // Assert
        $this->assertStringContainsString("=== 'media'", $generator);
        $this->assertStringContainsString("'mediaColumns' =>", $generator);
    }

    /**
     * A table with no media column emits an empty list, not a missing token.
     *
     * Most tables have none, so this is the common case. An unreplaced `{{ mediaColumns }}`
     * would be a parse error in every generated view — a scaffold that does not load, which
     * is the loudest failure but also the one nobody should meet.
     */
    public function testATableWithNoMediaColumnStillProducesAValidList(): void
    {
        // Arrange — the generator's own expression for the empty case
        $generator = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/MakeCommandBase.php'
        );

        // Assert
        $this->assertStringContainsString("? '[]'", $generator, 'no empty-list branch');
    }
}
