<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * There is somewhere to record a breaking change between tags, and it is not empty.
 *
 * WHAT: `docs/Pramnos_Upgrade_Guide.md` carries a `## Since <version> — unreleased`
 *       section, and that section contains a table with rows.
 *
 * WHY:  the guide is strictly version-to-version and covered only tagged releases. v1.2 was
 *       tagged on 2026-07-12; a change on 2026-08-14 altered the shape of a decoded JSON
 *       body, and the only record of it was a daily changelog post filed under `Fixed:`.
 *       Applications track `dev-main` — this framework's own instructions are to
 *       `composer update` — so an upgrade crosses everything since the tag.
 *
 *       A mobile application failed the day before a municipal presentation on a payload it
 *       had been sending unchanged for months. **"Breaking" and "fix" are orthogonal
 *       labels**, and filing under one had been excluding an item from the other by
 *       construction. By then there were 41 posts and 37,907 lines since the tag.
 *
 * This is a test rather than prose in CLAUDE.md because the failure mode is *the section
 * quietly not being there* — after a tag is cut and the old one is renamed, or after
 * somebody trims it. Neither is visible in the diff of the change that needed it, which is
 * the same reason `DocsRetrievabilityTest` exists.
 *
 * It deliberately does **not** check that any particular change is listed. Which changes
 * are breaking is a judgement, and a test that tried to make it would either be a list to
 * maintain twice or a heuristic on headings — the third of the three asks behind this, and
 * the one that becomes unnecessary once the table is kept by rule.
 */
class UpgradeGuideHasAnUnreleasedSectionTest extends TestCase
{
    private function guide(): string
    {
        $path = dirname(__DIR__, 3) . '/docs/Pramnos_Upgrade_Guide.md';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The section exists, and it is a top-level one.
     *
     * `## `, so it sits beside the `v1.1 → v1.2` sections rather than inside one — a
     * subsection of the last release is a section that gets renamed with it.
     */
    public function testThereIsAnUnreleasedSection(): void
    {
        // Act & Assert
        $this->assertMatchesRegularExpression(
            '/^## Since v[\d.]+ — unreleased$/m',
            $this->guide(),
            'the Upgrade Guide has nowhere to record a breaking change made since the last '
            . 'tag. Applications track dev-main; a daily changelog post is not a place '
            . 'somebody reads before bumping'
        );
    }

    /**
     * And it has rows, not just a heading.
     *
     * An empty section is worse than none: it reads as "nothing has changed since the tag",
     * which is a claim rather than an absence. When a release genuinely carries nothing
     * breaking, say so in a sentence — this asserts the table has content, so a bare
     * heading is what fails.
     */
    public function testTheUnreleasedSectionCarriesATable(): void
    {
        // Arrange — the section, up to the next top-level heading
        $guide = $this->guide();
        $start = preg_match('/^## Since v[\d.]+ — unreleased$/m', $guide, $m, PREG_OFFSET_CAPTURE)
            ? (int) $m[0][1]
            : -1;
        $this->assertGreaterThan(-1, $start, 'no unreleased section to read');

        $rest = substr($guide, $start + strlen($m[0][0]));
        $next = preg_match('/^## /m', $rest, $n, PREG_OFFSET_CAPTURE)
            ? (int) $n[0][1]
            : strlen($rest);
        $section = substr($rest, 0, $next);

        // Act — table rows: a line that starts and ends with a pipe, minus the header rules
        $rows = 0;
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|') && str_ends_with($line, '|')
                && !str_contains($line, '---')
                && !str_contains($line, 'Action required')
            ) {
                $rows++;
            }
        }

        // Assert
        $this->assertGreaterThan(
            0,
            $rows,
            'the unreleased section is a heading with no rows, which reads as "nothing has '
            . 'changed since the tag" — a claim, not an absence'
        );
    }

    /**
     * Each row says what to do, in the third column.
     *
     * The column that makes the table worth reading. A row that names a change and not the
     * action is the daily post again, one table cell shorter — and the reason the filing
     * asked for a table at all was that *"any handler that reads an element of a JSON body
     * with `->` now receives an array"* is grep-able and a paragraph of prose is not.
     */
    public function testEveryRowSaysWhatToDo(): void
    {
        // Arrange
        $guide = $this->guide();
        preg_match('/^## Since v[\d.]+ — unreleased$/m', $guide, $m, PREG_OFFSET_CAPTURE);
        $rest = substr($guide, (int) $m[0][1] + strlen($m[0][0]));
        $next = preg_match('/^## /m', $rest, $n, PREG_OFFSET_CAPTURE)
            ? (int) $n[0][1]
            : strlen($rest);
        $section = substr($rest, 0, $next);

        // Act & Assert
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|') || str_contains($line, '---')
                || str_contains($line, 'Action required')
            ) {
                continue;
            }

            $cells = array_values(array_filter(
                array_map('trim', explode('|', $line)),
                static fn(string $cell): bool => $cell !== ''
            ));

            $this->assertCount(
                3,
                $cells,
                'a row must be area, change, action — got: ' . $line
            );
            $this->assertNotSame('', $cells[2], 'a row with no action is not worth a table');
        }
    }
}
