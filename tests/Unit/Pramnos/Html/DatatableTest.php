<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable;

/**
 * Unit tests for Pramnos\Html\Datatable.
 *
 * Tests the structural/data methods of the Datatable class that do not require
 * an active Document singleton or a real HTTP environment.  renderJs() and
 * render() are excluded because they call Factory::getDocument() which needs
 * the full application bootstrap.
 */
#[CoversClass(Datatable::class)]
class DatatableTest extends TestCase
{
    /** @var object Original Document singleton saved before each test that replaces it. */
    private object $originalDoc;

    protected function setUp(): void
    {
        // Save the current Document singleton so it can be restored in tearDown.
        // Tests that replace the singleton via reference must not pollute later tests.
        $this->originalDoc = \Pramnos\Framework\Factory::getDocument('html');
    }

    protected function tearDown(): void
    {
        // Restore the Document singleton to prevent test pollution.
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $this->originalDoc;
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * __construct() trims and stores name and source, and JSON-encodes the
     * initial empty aoData array to the string '[]'.
     */
    public function testConstructorStoresNameSourceAndEmptyAoData(): void
    {
        // Arrange / Act
        $dt = new Datatable('myTable', 'http://example.com/data.json');

        // Assert
        $this->assertSame('myTable', $dt->name);
        $this->assertSame('http://example.com/data.json', $dt->source);
        // aoData is JSON-encoded in the constructor
        $this->assertSame('[]', $dt->aoData);
    }

    /**
     * __construct() strips leading/trailing whitespace from name and source.
     */
    public function testConstructorTrimsNameAndSource(): void
    {
        // Arrange / Act
        $dt = new Datatable('  trimmed  ', '  /api/data  ');

        // Assert
        $this->assertSame('trimmed', $dt->name);
        $this->assertSame('/api/data', $dt->source);
    }

    /**
     * __construct() with no arguments leaves name and source empty.
     */
    public function testConstructorDefaultsToEmpty(): void
    {
        // Arrange / Act
        $dt = new Datatable();

        // Assert
        $this->assertSame('', $dt->name);
        $this->assertSame('', $dt->source);
    }

    // =========================================================================
    // addColumn()
    // =========================================================================

    /**
     * addColumn() creates a Column entry in aoColumns and returns $this for
     * fluent chaining.
     */
    public function testAddColumnCreatesColumnAndReturnsFluentSelf(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $result = $dt->addColumn('Name');

        // Assert – fluent return
        $this->assertSame($dt, $result);
        // Assert – column exists in aoColumns
        $this->assertArrayHasKey('Name', $dt->aoColumns);
    }

    /**
     * addColumn() stores the label as the Column's label property.
     */
    public function testAddColumnStoresLabel(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $dt->addColumn('Email');

        // Assert
        $this->assertSame('Email', $dt->aoColumns['Email']->label);
    }

    /**
     * addColumn() sets visibility (bVisible) and sortability (bSortable)
     * from the provided arguments.
     */
    public function testAddColumnSetsVisibilityAndSortable(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act — hidden, non-sortable column
        $dt->addColumn('InternalId', false, false);

        // Assert
        $this->assertFalse($dt->aoColumns['InternalId']->bVisible);
        $this->assertFalse($dt->aoColumns['InternalId']->bSortable);
    }

    /**
     * addColumn() with an array as $bVisible delegates all keys to the Column
     * object properties via the array-options form.
     */
    public function testAddColumnAcceptsArrayOptions(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $dt->addColumn('Status', ['bVisible' => false, 'align' => 'center']);

        // Assert – array options applied to the column
        $this->assertFalse($dt->aoColumns['Status']->bVisible);
        $this->assertSame('center', $dt->aoColumns['Status']->align);
    }

    /**
     * Multiple addColumn() calls accumulate in aoColumns.
     */
    public function testAddColumnAccumulatesColumns(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $dt->addColumn('A')->addColumn('B')->addColumn('C');

        // Assert
        $this->assertCount(3, $dt->aoColumns);
        $this->assertArrayHasKey('A', $dt->aoColumns);
        $this->assertArrayHasKey('B', $dt->aoColumns);
        $this->assertArrayHasKey('C', $dt->aoColumns);
    }

    // =========================================================================
    // addRow()
    // =========================================================================

    /**
     * addRow() appends a row to the rows array and returns $this.
     */
    public function testAddRowAppendsRowAndReturnsSelf(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $result = $dt->addRow(['Alice', 'alice@example.com']);

        // Assert – fluent return
        $this->assertSame($dt, $result);
        // Assert – row stored
        $this->assertCount(1, $dt->rows);
        $this->assertSame(['Alice', 'alice@example.com'], $dt->rows[0]);
    }

    /**
     * addRow() called multiple times accumulates all rows in order.
     */
    public function testAddRowAccumulatesMultipleRows(): void
    {
        // Arrange
        $dt = new Datatable('t');

        // Act
        $dt->addRow(['Row1A', 'Row1B']);
        $dt->addRow(['Row2A', 'Row2B']);
        $dt->addRow(['Row3A', 'Row3B']);

        // Assert – all three rows present in order
        $this->assertCount(3, $dt->rows);
        $this->assertSame('Row1A', $dt->rows[0][0]);
        $this->assertSame('Row2A', $dt->rows[1][0]);
        $this->assertSame('Row3A', $dt->rows[2][0]);
    }

    // =========================================================================
    // renderTable()
    // =========================================================================

    /**
     * renderTable() produces a string containing a <table> element with the
     * datatable id set to the configured name.
     */
    public function testRenderTableContainsTableWithConfiguredId(): void
    {
        // Arrange
        $dt = new Datatable('myGrid', '/api/data');
        $dt->addColumn('Name');
        $dt->addColumn('Email');

        // Act
        $html = $dt->renderTable();

        // Assert – table id present
        $this->assertIsString($html);
        $this->assertStringContainsString('id="myGrid"', $html);
    }

    /**
     * renderTable() includes <thead> and <tbody> structural sections.
     */
    public function testRenderTableHasTheadAndTbody(): void
    {
        // Arrange
        $dt = new Datatable('t');
        $dt->addColumn('Name');

        // Act
        $html = $dt->renderTable();

        // Assert – structural HTML present
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
    }

    /**
     * renderTable() includes all column labels in <th> elements inside thead.
     */
    public function testRenderTableRendersColumnHeaders(): void
    {
        // Arrange
        $dt = new Datatable('t');
        $dt->addColumn('First Name');
        $dt->addColumn('Last Name');
        $dt->addColumn('Email');

        // Act
        $html = $dt->renderTable();

        // Assert – all three header labels present
        $this->assertStringContainsString('First Name', $html);
        $this->assertStringContainsString('Last Name', $html);
        $this->assertStringContainsString('Email', $html);
    }

    /**
     * renderTable() renders each row as a <tr> with <td> cells.
     */
    public function testRenderTableRendersRowsAsTrTd(): void
    {
        // Arrange
        $dt = new Datatable('t');
        $dt->addColumn('Name');
        $dt->addColumn('Score');
        $dt->addRow(['Alice', '95']);
        $dt->addRow(['Bob', '87']);

        // Act
        $html = $dt->renderTable();

        // Assert – row data present in the HTML
        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('<td>', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringContainsString('95', $html);
    }

    /**
     * renderTable() with showHide=false produces simpler HTML without the
     * column-visibility toggle widget.
     */
    public function testRenderTableWithShowHideFalseOmitsToggleWidget(): void
    {
        // Arrange
        $dt = new Datatable('t');
        $dt->showHide = false;
        $dt->addColumn('Name');

        // Act
        $html = $dt->renderTable();

        // Assert – no fnShowHide call (the toggle JS function)
        $this->assertStringNotContainsString('fnShowHide_', $html);
    }

    /**
     * renderTable() with bootstrap=false uses 'display' as the only table class.
     */
    public function testRenderTableWithBootstrapFalseUsesDisplayClass(): void
    {
        // Arrange
        $dt = new Datatable('t');
        $dt->bootstrap  = false;
        $dt->showHide   = false;
        $dt->addColumn('Name');

        // Act
        $html = $dt->renderTable();

        // Assert – bootstrap classes NOT added
        $this->assertStringNotContainsString('table-striped', $html);
        // Base 'display' class still present
        $this->assertStringContainsString('class="display', $html);
    }

    // =========================================================================
    // renderJs() and render() and renderExistingTable()
    // =========================================================================

    public function testRenderJsProducesScriptTagAndEnqueuesScripts(): void
    {
        $dt = new Datatable('myTable');
        $dt->addColumn('Name');
        $dt->jui = true;
        
        $doc = new class {
            public array $styles = [];
            public array $scripts = [];
            public function enqueueStyle(string $style): void { $this->styles[] = $style; }
            public function enqueueScript(string $script): void { $this->scripts[] = $script; }
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $js = $dt->renderJs();
        
        $this->assertStringContainsString('<script>', $js);
        $this->assertStringContainsString('window.addEventListener("load"', $js);
        $this->assertStringContainsString('jQuery(\'#myTable\').dataTable', $js);
        
        $this->assertContains('datatables-ui', $doc->styles);
        $this->assertContains('jquery-ui', $doc->styles);
        $this->assertContains('datatables', $doc->scripts);
    }

    public function testRenderCombinesTableAndJs(): void
    {
        $dt = new Datatable('myTable2');
        $dt->addColumn('Name');
        
        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $html = $dt->render();
        
        $this->assertStringContainsString('<table id="myTable2"', $html);
        $this->assertStringContainsString('<script>', $html);
    }

    public function testRenderExistingTable(): void
    {
        $dt = new Datatable();
        $dt->tableTools = true;
        
        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $html = $dt->renderExistingTable('myExistingTable');
        
        $this->assertStringContainsString('jQuery(\'#myExistingTable\').dataTable', $html);
        $this->assertStringContainsString('"dom": \'T<"clear">lfrtip\'', $html);
    }

    public function testGroupBySelector(): void
    {
        $dt = new Datatable('myTableGroup');
        $dt->addColumn('Name');
        $dt->groupBySelector = true;
        
        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $html = $dt->render();
        
        $this->assertStringContainsString('id="pf40_groupby_myTableGroup"', $html);
        $this->assertStringContainsString('pf40_doGroup_myTableGroup', $html);
    }

    public function testFixColumnSearch(): void
    {
        $dt = new Datatable('mySearchTable');
        $dt->addColumn('Name', true, true, true, '', '', true, 'left', true, 'searchme');
        $dt->addColumn('Email', true, true, true, '', '', true, 'left', 'emailsearch', 'foo');
        $dt->stateSave = true;
        
        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $html = $dt->render();
        
        $this->assertStringContainsString('id="autofootsearch_0"', $html);
        $this->assertStringContainsString('value="searchme"', $html);
        $this->assertStringContainsString('mySearchTable.fnFilter( \'searchme\', 0 )', $html);
        $this->assertStringContainsString('jQuery(\'#emailsearch\').change', $html);
        $this->assertStringContainsString('$("tfoot input").keyup(', $html);
    }

    public function testRenderJsServerSideAndEditable(): void
    {
        $dt = new Datatable('myServerTable', '/api/data');
        $dt->addColumn('Name');
        $dt->editable = true;
        $dt->sortOrder = 'asc';
        
        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        $js = $dt->renderJs();
        
        $this->assertStringContainsString('"serverSide": true', $js);
        $this->assertStringContainsString('url": "/api/data"', $js);
        $this->assertStringContainsString('fnDrawCallback', $js);
        $this->assertStringContainsString('.editable(', $js);
        $this->assertStringContainsString('"order": [[0, "asc"]]', $js);
    }
}
