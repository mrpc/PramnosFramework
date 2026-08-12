<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The documentation contract that keeps a page findable.
 *
 * The docs directory ships inside the composer package, which makes it the
 * retrieval corpus an assistant working in a project reads from: the vendored
 * docs always match the vendored code, so no version negotiation is needed.
 * That only holds if two invariants are maintained by hand, and both fail
 * silently when they are not:
 *
 *   - **`use_cases:` frontmatter.** A retrieval tool picks a page by *when you
 *     would need it*, not by its title. A page without that field can be read
 *     but not found, and an assistant that cannot find it concludes the feature
 *     does not exist and writes its own. That has already happened once, to the
 *     SPA debug panel.
 *   - **Presence in `mkdocs.yml` nav.** A page outside the nav is unreachable on
 *     the published site and reads as abandoned. Four pages were in that state
 *     when this test was written.
 *
 * Neither is checkable by reading the diff of a single change, which is why it
 * is a test. Exemptions are enumerated here, not inferred, so a new page cannot
 * become silently exempt.
 */
class DocsRetrievabilityTest extends TestCase
{
    /** Repository docs directory. */
    private string $docsDir;

    /**
     * Pages deliberately outside the retrieval corpus.
     *
     * @var array<string, string> filename => why
     */
    private const EXEMPT = [
        // Frozen v1.2 technical reference: never edited (so it cannot gain
        // frontmatter), and superseded for "how do I" questions by the topic
        // guides that cover the same features.
        '1.2-new-features.md' => 'frozen v1.2 reference — never edited',
        // A release index is history, not an answer. The changelog posts are a
        // separate corpus on purpose, so "when did this change" cannot outrank
        // "how does this work".
        'releases.md'         => 'release index — history, not guidance',
    ];

    protected function setUp(): void
    {
        $this->docsDir = dirname(__DIR__, 3) . '/docs';
    }

    /**
     * Every indexable page declares at least one use case.
     *
     * Asserted per page with the filename in the message: a collective count
     * would say "3 pages are broken" without saying which, and the point of the
     * test is to be actionable at the moment a page is added.
     */
    public function testEveryIndexablePageDeclaresUseCases(): void
    {
        // Arrange
        $pages = $this->indexablePages();
        $this->assertNotEmpty($pages, 'sanity: the docs directory was found');

        foreach ($pages as $file => $path) {
            // Act
            $cases = $this->readUseCases($path);

            // Assert
            $this->assertNotEmpty(
                $cases,
                "docs/$file has no use_cases: frontmatter — it can be read but not found. "
                . 'Add a `use_cases:` list describing when a reader needs this page.'
            );
            foreach ($cases as $case) {
                // A blank or placeholder entry passes a "not empty" check on the
                // list while telling a retrieval tool nothing.
                $this->assertGreaterThan(
                    10,
                    strlen(trim($case)),
                    "docs/$file has a use_cases entry too short to match against: '$case'"
                );
            }
        }
    }

    /**
     * Every indexable page is reachable from the published navigation.
     *
     * A page missing from the nav still builds — MkDocs reports it as INFO, not
     * a warning — so nothing fails and nobody notices.
     */
    public function testEveryIndexablePageIsInTheNav(): void
    {
        // Arrange
        $nav = $this->navTargets();

        // Act / Assert
        foreach (array_keys($this->indexablePages()) as $file) {
            $this->assertContains(
                $file,
                $nav,
                "docs/$file is not in mkdocs.yml nav — unreachable on the published site. "
                . 'Add it to the nav, or add it to this test\'s EXEMPT list with a reason.'
            );
        }
    }

    /**
     * Every nav entry points at a file that exists.
     *
     * The inverse failure: a renamed or removed page leaves a nav entry that
     * 404s, which the build also does not fail on.
     */
    public function testEveryNavEntryResolvesToAFile(): void
    {
        // Arrange / Act
        $missing = [];
        foreach ($this->navTargets() as $target) {
            if (!is_file($this->docsDir . '/' . $target)) {
                $missing[] = $target;
            }
        }

        // Assert
        $this->assertSame([], $missing, 'mkdocs.yml nav points at files that do not exist');
    }

    /**
     * The exemption list describes pages that are actually there.
     *
     * A stale exemption is worse than none: it silently excuses a filename that
     * may later be reused for a real page.
     */
    public function testExemptionsAreLive(): void
    {
        foreach (self::EXEMPT as $file => $why) {
            $this->assertFileExists(
                $this->docsDir . '/' . $file,
                "docs/$file is exempt ($why) but no longer exists — drop the exemption"
            );
        }
    }

    /**
     * Guide pages, excluding the enumerated exemptions.
     *
     * Only the top level of docs/ is scanned: `version-history/` is the dated
     * changelog stream, which is a corpus of its own, and its posts carry their
     * own frontmatter (date/categories) rather than use cases.
     *
     * @return array<string, string> filename => absolute path
     */
    private function indexablePages(): array
    {
        $pages = [];
        foreach (glob($this->docsDir . '/*.md') ?: [] as $path) {
            $file = basename($path);
            if (isset(self::EXEMPT[$file])) {
                continue;
            }
            $pages[$file] = $path;
        }

        return $pages;
    }

    /**
     * The `use_cases` entries of a page, or [] when it declares none.
     *
     * Hand-parsed rather than passed to a YAML library: symfony/yaml is not a
     * dependency, and the shape being read is one flat list of strings at the
     * top of the file.
     *
     * @return list<string>
     */
    private function readUseCases(string $path): array
    {
        $lines = explode("\n", (string) file_get_contents($path));
        if (($lines[0] ?? '') !== '---') {
            return [];
        }

        $cases    = [];
        $inCases  = false;
        foreach (array_slice($lines, 1) as $line) {
            if ($line === '---') {
                break;                     // end of frontmatter
            }
            if (preg_match('/^use_cases:\s*$/', $line)) {
                $inCases = true;
                continue;
            }
            if ($inCases && preg_match('/^\s+-\s+(.*)$/', $line, $m)) {
                $cases[] = $m[1];
                continue;
            }
            if ($inCases && trim($line) !== '' && !str_starts_with($line, ' ')) {
                $inCases = false;          // a sibling key ended the list
            }
        }

        return $cases;
    }

    /**
     * Every docs-relative file path referenced by mkdocs.yml's nav.
     *
     * Also hand-parsed: the nav is a plain two-level list of `Label: file.md`
     * entries, and adding a YAML dependency to read it would be the larger
     * change.
     *
     * @return list<string>
     */
    private function navTargets(): array
    {
        $yml = (string) file_get_contents(dirname($this->docsDir) . '/mkdocs.yml');
        // Only the nav: block — a stray `foo: bar.md` elsewhere in the config
        // (a logo path, a plugin option) must not be read as a page.
        $navPos = strpos($yml, "\nnav:");
        $nav    = $navPos === false ? '' : substr($yml, $navPos);

        preg_match_all('/:\s*([A-Za-z0-9_.\/-]+\.md)\s*$/m', $nav, $m);

        return $m[1];
    }
}
