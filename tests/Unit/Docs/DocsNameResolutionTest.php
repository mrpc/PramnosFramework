<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Every `\Pramnos\…` name a guide mentions must resolve to something that ships.
 *
 * This test exists because of a specific failure. `Pramnos_Authorization_Guide.md` documented
 * `Gate::define()`, policy classes and an `AuthorizationException`, none of which existed —
 * the page came out of a documentation reorganisation describing an API that was planned
 * rather than built. A consumer found it by doing exactly what the docs ask: picking a page by
 * its `use_cases` and building on the first API it named.
 *
 * **Why that is worse than a typo.** Most wrong names in these guides are namespace slips —
 * the class exists, the path is spelled wrong — and they are self-correcting: the reader greps
 * the class name, finds it one namespace over, and moves on. A name that resolves to *nothing*
 * is different. The reader greps it, gets nothing back, and cannot tell **"I searched wrong"**
 * from **"this does not exist"**. That is the state in which somebody keeps looking for another
 * hour, and it lands hardest on whoever is doing the work where people reach for a framework
 * instead of inventing something.
 *
 * The check is the one the filing suggested: take the names a guide uses and grep the source
 * for them. It is trivial to run and nobody ran it, which is the argument for making it a test
 * rather than a habit.
 *
 * **Scope.** Guides only. Changelog posts are dated records of what was true when written, and
 * several deliberately quote names that were wrong or have since moved — correcting them would
 * falsify the history they exist to keep.
 */
class DocsNameResolutionTest extends TestCase
{
    /**
     * Pages this check does not apply to, and why.
     *
     * Enumerated rather than inferred, so a new page cannot become silently exempt — the same
     * reasoning as {@see DocsRetrievabilityTest}.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // A frozen reference for a released version. Editing it would misrepresent what that
        // release documented, and it is marked as frozen in the project's own rules.
        '1.2-new-features.md' => 'frozen v1.2 reference',
    ];

    /**
     * Names a guide may mention although they do not exist in `src/`.
     *
     * Every entry needs a reason. This list is meant to stay short: an entry here is a name a
     * reader will grep for and not find, so the bar is that the page itself explains the
     * absence.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ABSENT = [
        // Named in the Authorization guide's own account of what that page used to claim
        // wrongly. The page states in the same paragraph that they never existed.
        'Pramnos\Auth\Gate::define' => 'quoted in the guide’s record of its own error',
        // Named in the Testing guide's note about the class that section used to show.
        'Pramnos\Testing\HttpTest' => 'quoted in the guide’s record of its own error',
        // Named in the Theme guide's notes about two extension points that never existed.
        'Pramnos\Theme\Widget' => 'quoted in the guide’s record of its own error',
        'Pramnos\Theme\MenuWalker' => 'quoted in the guide’s record of its own error',
        // Named in the ORM guide's note about the custom-cast interface that never existed.
        'Pramnos\Database\Casts\Castable' => 'quoted in the guide’s record of its own error',
    ];

    /**
     * Guides, excluding changelog posts.
     *
     * @return array<string, string> Absolute path => path relative to `docs/`
     */
    private function guides(): array
    {
        $root  = dirname(__DIR__, 3) . '/docs';
        $found = [];

        foreach ((array) glob($root . '/*.md') as $path) {
            $name = basename((string) $path);
            if (isset(self::EXEMPT[$name])) {
                continue;
            }
            $found[(string) $path] = $name;
        }

        return $found;
    }

    /**
     * Every class, interface, trait and enum that ships in `src/`.
     *
     * Built by reading the files rather than by autoloading, so a name can be checked without
     * loading the class it points at — several would need a database or an application.
     *
     * @return array<string, true> Fully-qualified name => true
     */
    private function shippedTypes(): array
    {
        $types = [];
        $root  = dirname(__DIR__, 3) . '/src';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source    = (string) file_get_contents($file->getPathname());
            $namespace = '';
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1) {
                $namespace = trim($m[1]) . '\\';
            }

            preg_match_all(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
                $source,
                $matches
            );
            foreach ($matches[1] as $type) {
                $types[$namespace . $type] = true;
            }
        }

        return $types;
    }

    /**
     * Whether a documented name resolves to something real.
     *
     * A mention can be a class, a `Class::method`, or a namespace prefix used to point at a
     * group of classes — all three are legitimate in prose, so all three count as resolved.
     *
     * @param string               $name  The name as the page wrote it
     * @param array<string, true>  $types Everything that ships
     * @return bool True when a reader grepping this name would find something
     */
    private function resolves(string $name, array $types): bool
    {
        if (isset($types[$name])) {
            return true;
        }

        // `Pramnos\Auth\Gate::define` — strip the member and check the type.
        $withoutMember = implode('\\', array_slice(explode('\\', $name), 0, -1));
        if ($withoutMember !== '' && isset($types[$withoutMember])) {
            return true;
        }

        // A namespace rather than a class: `Pramnos\Framework\Migrations\Auth`.
        foreach (array_keys($types) as $type) {
            if (str_starts_with($type, $name . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * No guide names a `\Pramnos\…` type that does not ship.
     *
     * The failure message lists every unresolvable name with the page that used it, because
     * the useful output of this test is a work list rather than a single assertion.
     */
    public function testEveryDocumentedPramnosNameResolves(): void
    {
        // Arrange
        $types    = $this->shippedTypes();
        $problems = [];

        // Act
        foreach ($this->guides() as $path => $name) {
            $text = (string) file_get_contents($path);

            preg_match_all('/\\\\?(Pramnos(?:\\\\{1,2}[A-Za-z_]\w*)+)/', $text, $matches);

            foreach (array_unique($matches[1]) as $mention) {
                // Markdown and PHP string literals both escape the separator; normalise.
                $mention = str_replace('\\\\', '\\', $mention);

                if (isset(self::ALLOWED_ABSENT[$mention])) {
                    continue;
                }
                if ($this->resolves($mention, $types)) {
                    continue;
                }

                $problems[] = $name . ' — ' . $mention;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $problems,
            "These guides name types that do not exist in src/. A reader who greps one of\n"
            . "these gets nothing back and cannot tell a wrong search from a missing feature.\n"
            . "Fix the name, or remove the claim:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * The exemption lists stay small and explained.
     *
     * An exemption list nobody looks at becomes the place wrong names go to be forgotten. This
     * asserts that every entry carries a reason, and that the list has not quietly grown into
     * the mechanism for avoiding the test.
     */
    public function testTheExemptionsAreFewAndExplained(): void
    {
        // Assert — every entry gives a reason
        foreach (self::EXEMPT as $page => $reason) {
            $this->assertNotSame('', trim($reason), $page . ' is exempt without a reason.');
        }
        foreach (self::ALLOWED_ABSENT as $name => $reason) {
            $this->assertNotSame('', trim($reason), $name . ' is allowed absent without a reason.');
        }

        // Assert — and the lists are short enough to read
        $this->assertLessThanOrEqual(
            3,
            count(self::EXEMPT),
            'More than three exempt pages means the check is being avoided rather than satisfied.'
        );
        $this->assertLessThanOrEqual(
            5,
            count(self::ALLOWED_ABSENT),
            'More than five allowed-absent names means the guides are describing unbuilt APIs again.'
        );
    }
}
