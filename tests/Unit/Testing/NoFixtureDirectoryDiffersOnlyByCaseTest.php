<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;

/**
 * No two directories under `tests/` differ only in letter case.
 *
 * The suite keeps two fixture roots on purpose: `tests/fixtures` (files reached by path — an
 * application directory, documents served over HTTP) and `tests/Fixtures` (PSR-4 classes under
 * `Pramnos\Tests\Fixtures`). On macOS and Windows those are **one** directory, so a file written
 * to `tests/fixtures/app/…` there can be recorded by git as `tests/Fixtures/app/…` and pass every
 * run on that machine. On Linux — CI, the Docker image, production — it lands in the other root,
 * and the tests that reach it by path fail with a missing class or a 404 that names nothing.
 *
 * Seven fixture files were in exactly that state. This test finds the next one on the machine
 * that can see it, rather than on the one that cannot.
 */
class NoFixtureDirectoryDiffersOnlyByCaseTest extends TestCase
{
    /**
     * Pairs allowed to differ only by case, because their contents never collide when merged.
     *
     * The two fixture roots, which have different jobs; and the fixture application's
     * `Migrations` (legacy migration classes, loaded by name) beside `migrations` (timestamped
     * files, loaded by scan) — on a case-insensitive disk they share a directory and each loader
     * still finds only its own.
     */
    private const ALLOWED = [
        ['tests/fixtures', 'tests/Fixtures'],
        ['tests/fixtures/app/Migrations', 'tests/fixtures/app/migrations'],
    ];

    /**
     * Every directory under `tests/` has a spelling of its own, case aside.
     *
     * Grouped by lower-cased path: a group with more than one spelling is two directories on
     * Linux and one on a case-insensitive disk. `tests/Fixtures/app` beside `tests/fixtures/app`
     * is the shape that broke.
     */
    public function testNoTwoDirectoriesDifferOnlyByCase(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3);
        $groups = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Act
        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $groups[strtolower($relative)][] = $relative;
        }
        $collisions = array_filter(
            $groups,
            static function (array $spellings): bool {
                sort($spellings);
                foreach (self::ALLOWED as $pair) {
                    sort($pair);
                    if ($spellings === $pair) {
                        return false;
                    }
                }

                return count($spellings) > 1;
            }
        );

        // Assert — each collision is one directory on macOS/Windows and two on Linux
        $this->assertSame(
            [],
            array_values($collisions),
            'these directories differ only by case; move the files into the root the tests reach'
        );
    }
}
