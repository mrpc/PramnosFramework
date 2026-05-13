<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html\Datatable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable\Column;

/**
 * Unit tests for Pramnos\Html\Datatable\Column.
 *
 * Column represents one column definition in a server-side DataTable. It stores
 * visibility, sortability, searchability, alignment, and a footer value, and
 * serialises them to a DataTables-compatible JavaScript object via getJs().
 *
 * Tests cover:
 *   - Constructor defaults and property assignment
 *   - sType default ('html') when empty string is passed
 *   - getJs() output format for visible/hidden and sortable/non-sortable columns
 *   - The js property is pre-computed in the constructor
 */
#[CoversClass(Column::class)]
class ColumnTest extends TestCase
{
    // =========================================================================
    // Constructor — default values
    // =========================================================================

    /**
     * When constructed with no arguments every property takes its default.
     * Knowing the defaults matters because Datatable::addColumn() relies on
     * them when only the label is supplied.
     */
    public function testConstructorDefaultsAreCorrect(): void
    {
        // Arrange / Act
        $col = new Column();

        // Assert — structural defaults
        $this->assertSame('',     $col->label);
        $this->assertSame('',     $col->sTitle);
        $this->assertTrue($col->bVisible);
        $this->assertTrue($col->bSortable);
        $this->assertTrue($col->bSearchable);
        $this->assertSame('html', $col->sType);
        $this->assertSame('',     $col->footer);
        $this->assertSame('left', $col->align);
        $this->assertFalse($col->footsearch);
        $this->assertSame('',     $col->searchvalue);
    }

    /**
     * Passing only a label sets label and sTitle to that string while all
     * other properties keep their defaults.
     */
    public function testConstructorWithLabelSetsLabelAndSTitle(): void
    {
        // Arrange / Act
        $col = new Column('Email');

        // Assert — label drives both label and sTitle
        $this->assertSame('Email', $col->label);
        $this->assertSame('Email', $col->sTitle);
        // Defaults unchanged
        $this->assertTrue($col->bVisible);
        $this->assertSame('html', $col->sType);
    }

    /**
     * When all constructor arguments are supplied they override every default.
     */
    public function testConstructorWithAllArgumentsStoresEachValue(): void
    {
        // Arrange / Act
        $col = new Column(
            'Status',   // label
            false,      // bVisible
            false,      // bSortable
            false,      // bSearchable
            'string',   // sType
            'Total',    // footer
            false,      // showHide
            'center',   // align
            true,       // footsearch
            'active'    // searchvalue
        );

        // Assert
        $this->assertSame('Status', $col->label);
        $this->assertFalse($col->bVisible);
        $this->assertFalse($col->bSortable);
        $this->assertFalse($col->bSearchable);
        $this->assertSame('string', $col->sType);
        $this->assertSame('Total',  $col->footer);
        $this->assertSame('center', $col->align);
        $this->assertTrue($col->footsearch);
        $this->assertSame('active', $col->searchvalue);
    }

    /**
     * When sType is passed as an empty string, the constructor defaults it to
     * 'html' — the DataTables renderer for arbitrary HTML cell content.
     */
    public function testConstructorDefaultsSTypeToHtmlWhenEmptyStringPassed(): void
    {
        // Arrange / Act
        $col = new Column('Name', true, true, true, '');

        // Assert — empty '' treated as "not specified", defaulting to html
        $this->assertSame('html', $col->sType);
    }

    // =========================================================================
    // getJs()
    // =========================================================================

    /**
     * getJs() produces a DataTables column-definition object literal with all
     * four required keys: bVisible, bSortable, bSearchable, sTitle, sType.
     * The format must be a valid JS object literal (curly-braces, quoted keys).
     */
    public function testGetJsReturnsValidDataTablesObjectForDefaultColumn(): void
    {
        // Arrange
        $col = new Column('Price');

        // Act
        $js = $col->getJs();

        // Assert — all DataTables keys present with correct values
        $this->assertIsString($js);
        $this->assertStringContainsString('"bVisible": true',    $js);
        $this->assertStringContainsString('"bSortable": true',   $js);
        $this->assertStringContainsString('"bSearchable": true', $js);
        $this->assertStringContainsString('"sTitle": "Price"',   $js);
        $this->assertStringContainsString('"sType": "html"',     $js);
    }

    /**
     * When bVisible and bSortable are false, getJs() renders 'false' for those
     * keys so the DataTable correctly hides and locks the column.
     */
    public function testGetJsReflectsHiddenNonSortableColumn(): void
    {
        // Arrange
        $col = new Column('InternalId', false, false, false);

        // Act
        $js = $col->getJs();

        // Assert — hidden + non-sortable + non-searchable
        $this->assertStringContainsString('"bVisible": false',    $js);
        $this->assertStringContainsString('"bSortable": false',   $js);
        $this->assertStringContainsString('"bSearchable": false', $js);
    }

    /**
     * The $js property is set in the constructor by calling getJs(), so it
     * matches the return value of a fresh getJs() call.
     */
    public function testJsPropertyIsPrecomputedInConstructor(): void
    {
        // Arrange
        $col = new Column('Name');

        // Assert — $col->js populated in constructor equals getJs() output
        $this->assertSame($col->getJs(), $col->js);
    }

    /**
     * getJs() encodes the sType correctly when a non-default type is used.
     * This matters because DataTables uses sType to select the sorting plugin.
     */
    public function testGetJsEncodesCustomSType(): void
    {
        // Arrange
        $col = new Column('Date', true, true, true, 'date');

        // Act
        $js = $col->getJs();

        // Assert — custom sType appears in the JS object
        $this->assertStringContainsString('"sType": "date"', $js);
    }
}
