<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable;

/**
 * Unit tests for the PF-40 client-side group-by feature on Pramnos\Html\Datatable.
 *
 * Tests cover:
 *   - Default property values: groupByColumn=null, groupBySelector=false
 *   - groupBySelector=true: selector <select> element appears in renderTable() output
 *   - groupBySelector=false: selector element is absent from renderTable() output
 *   - Selector options include one entry per column with non-empty label
 *   - groupByColumn pre-selects the correct option in the selector
 *   - groupBySelector=true with groupByColumn=null defaults to "None" selected
 *
 * renderJs() is not tested here because it requires Factory::getDocument() which
 * needs the full application bootstrap.  The property/selector HTML tests are
 * sufficient to verify the feature wiring without a browser.
 */
#[CoversClass(Datatable::class)]
class DatatableGroupByTest extends TestCase
{
    // =========================================================================
    // Default property values (PF-40 BC guard)
    // =========================================================================

    /**
     * groupByColumn defaults to null, meaning no grouping is applied on render.
     * This preserves full backward-compatibility for existing Datatable users.
     */
    public function testGroupByColumnDefaultIsNull(): void
    {
        // Arrange / Act
        $dt = new Datatable('t');

        // Assert — null = "no grouping" by default
        $this->assertNull($dt->groupByColumn);
    }

    /**
     * groupBySelector defaults to false, so no picker UI is rendered unless
     * the caller explicitly opts in.
     */
    public function testGroupBySelectorDefaultIsFalse(): void
    {
        // Arrange / Act
        $dt = new Datatable('t');

        // Assert — false = no selector widget rendered
        $this->assertFalse($dt->groupBySelector);
    }

    // =========================================================================
    // groupBySelector HTML rendering
    // =========================================================================

    /**
     * When groupBySelector=true, renderTable() must include a <select> element
     * whose id follows the 'pf40_groupby_{name}' naming convention.
     * The selector lets the user pick a group-by column without a page reload.
     */
    public function testRenderTableIncludesSelectorWhenGroupBySelectorIsTrue(): void
    {
        // Arrange
        $dt = new Datatable('myGrid');
        $dt->groupBySelector = true;
        $dt->addColumn('Status');

        // Act
        $html = $dt->renderTable();

        // Assert — selector element present with correct id
        $this->assertStringContainsString('id="pf40_groupby_myGrid"', $html);
        $this->assertStringContainsString('<select', $html);
    }

    /**
     * When groupBySelector=false (the default), renderTable() must NOT include
     * the group-by picker so that the output is identical to pre-PF-40 output.
     */
    public function testRenderTableOmitsSelectorWhenGroupBySelectorIsFalse(): void
    {
        // Arrange
        $dt = new Datatable('myGrid');
        $dt->groupBySelector = false;
        $dt->addColumn('Status');

        // Act
        $html = $dt->renderTable();

        // Assert — no group-by picker rendered
        $this->assertStringNotContainsString('pf40_groupby_', $html);
    }

    /**
     * The selector's option list must contain one <option> per column with a
     * non-empty label.  This lets the user pick any visible column as the
     * group-by key.
     */
    public function testSelectorContainsOneOptionPerLabelledColumn(): void
    {
        // Arrange
        $dt = new Datatable('grid2');
        $dt->groupBySelector = true;
        $dt->addColumn('Name');
        $dt->addColumn('Department');
        $dt->addColumn('Status');

        // Act
        $html = $dt->renderTable();

        // Assert — each column label appears as an option value
        $this->assertStringContainsString('value="0"', $html); // Name → index 0
        $this->assertStringContainsString('value="1"', $html); // Department → index 1
        $this->assertStringContainsString('value="2"', $html); // Status → index 2
        $this->assertStringContainsString('Name', $html);
        $this->assertStringContainsString('Department', $html);
        $this->assertStringContainsString('Status', $html);
    }

    /**
     * The selector always includes a "None" option (value="-1") as the first
     * entry.  This lets the user remove grouping interactively.
     */
    public function testSelectorAlwaysIncludesNoneOption(): void
    {
        // Arrange
        $dt = new Datatable('g');
        $dt->groupBySelector = true;
        $dt->addColumn('Category');

        // Act
        $html = $dt->renderTable();

        // Assert — "None" option (value="-1") is present
        $this->assertStringContainsString('value="-1"', $html);
    }

    /**
     * When groupByColumn is set alongside groupBySelector, the corresponding
     * column option must carry the 'selected' attribute so the table renders
     * with that grouping pre-applied on first load.
     */
    public function testGroupByColumnPreselectsCorrectSelectorOption(): void
    {
        // Arrange — group by column at index 1
        $dt = new Datatable('g');
        $dt->groupBySelector = true;
        $dt->groupByColumn   = 1;
        $dt->addColumn('Name');
        $dt->addColumn('Department');
        $dt->addColumn('Status');

        // Act
        $html = $dt->renderTable();

        // Assert — value="1" has 'selected', value="0" and value="2" do not
        $this->assertMatchesRegularExpression('/value="1"[^>]*selected/', $html);
        $this->assertDoesNotMatch('/value="0"[^>]*selected/', $html);
        $this->assertDoesNotMatch('/value="2"[^>]*selected/', $html);
    }

    /**
     * When groupBySelector=true but groupByColumn=null, the "None" option
     * should be selected (i.e., no pre-applied grouping — the selector starts
     * at the neutral state and the user picks a column manually).
     */
    public function testGroupBySelectorWithNullColumnDefaultsToNoneSelected(): void
    {
        // Arrange
        $dt = new Datatable('g');
        $dt->groupBySelector = true;
        $dt->groupByColumn   = null;
        $dt->addColumn('Category');

        // Act
        $html = $dt->renderTable();

        // Assert — value="-1" (None) has selected attribute
        $this->assertMatchesRegularExpression('/value="-1"[^>]*selected/', $html);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Negative assertion helper: asserts that the subject string does NOT match
     * the given regular expression.
     */
    private function assertDoesNotMatch(string $pattern, string $subject): void
    {
        $this->assertDoesNotMatchRegularExpression($pattern, $subject);
    }
}
