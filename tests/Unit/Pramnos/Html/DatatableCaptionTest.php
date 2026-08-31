<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable;

/**
 * What a table says it is, to somebody who cannot see the heading above it.
 *
 * Without a caption a table is announced as "table, 7 columns" and nothing else, so a reader moving
 * between the several tables an admin screen carries cannot tell them apart.
 */
#[CoversClass(Datatable::class)]
class DatatableCaptionTest extends TestCase
{
    /**
     * A caption is rendered, hidden visually.
     *
     * Hidden rather than shown, because the heading above the table already serves a sighted
     * reader — and `pf-visually-hidden` is the shared recipe, not `display: none`, which would
     * remove it from the accessibility tree along with the screen.
     */
    public function testACaptionIsRenderedVisuallyHidden(): void
    {
        // Arrange
        $dt = new Datatable('dt-probe');
        $dt->caption = 'Recent sign-ins';
        $dt->addColumn('When');

        // Act
        $html = $dt->renderTable();

        // Assert
        $this->assertStringContainsString(
            '<caption class="pf-visually-hidden">Recent sign-ins</caption>',
            $html
        );
    }

    /**
     * Empty by default, and no empty element rendered.
     *
     * A caption invented from the table's internal name — `dt-emails` — is worse than none: a
     * reader hears it and believes it was written for them. So the default is empty, and an empty
     * default emits nothing at all rather than `<caption></caption>`.
     */
    public function testNoCaptionMeansNoElement(): void
    {
        // Arrange
        $dt = new Datatable('dt-probe');
        $dt->addColumn('When');

        // Assert
        $this->assertStringNotContainsString('<caption', $dt->renderTable());
    }

    /**
     * The caption is escaped.
     *
     * It is a label an application supplies, and an application's labels come from settings,
     * translations and occasionally a database column.
     */
    public function testTheCaptionIsEscaped(): void
    {
        // Arrange
        $dt = new Datatable('dt-probe');
        $dt->caption = 'Bell & Sons <script>';
        $dt->addColumn('When');

        // Act
        $html = $dt->renderTable();

        // Assert
        $this->assertStringContainsString('Bell &amp; Sons', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * The table announces that its rows changed.
     *
     * They are fetched, so without a live region sorting or paging is silence followed by different
     * data under the same heading. Polite rather than assertive: a result set is not an emergency,
     * and it waits for the reader to finish its sentence.
     */
    public function testTheTableIsALiveRegion(): void
    {
        // Arrange
        $dt = new Datatable('dt-probe');
        $dt->addColumn('When');

        // Assert
        $this->assertStringContainsString('aria-live="polite"', $dt->renderTable());
    }
}
