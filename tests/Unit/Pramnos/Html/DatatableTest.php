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

    // =========================================================================
    // renderTable() — showHide variants
    // =========================================================================

    /**
     * renderTable() with showHide=true and jui=true uses jQuery UI button markup
     * for the show/hide toggle widget rather than Bootstrap or plain HTML.
     * This covers the $this->jui branch inside the showHide block.
     */
    public function testRenderTableShowHideJuiVariant(): void
    {
        // Arrange
        $dt = new Datatable('juiTable');
        $dt->showHide  = true;
        $dt->jui       = true;
        $dt->bootstrap = false;
        $dt->addColumn('Name');
        $dt->addColumn('Email');

        // Act
        $html = $dt->renderTable();

        // Assert — jQuery UI-specific class appears (ui-buttonset wraps the toggle)
        $this->assertStringContainsString('ui-buttonset', $html);
        // A per-column toggle link must be present
        $this->assertStringContainsString('ui-button', $html);
        $this->assertStringContainsString('psh_juiTable_0', $html);
    }

    /**
     * renderTable() with showHide=true, bootstrap=false, and jui=false produces
     * a plain-HTML show/hide widget (no Bootstrap or jQuery UI classes).
     * This covers the else-branch of the jui/bootstrap condition.
     */
    public function testRenderTableShowHidePlainVariant(): void
    {
        // Arrange
        $dt = new Datatable('plainTable');
        $dt->showHide  = true;
        $dt->jui       = false;
        $dt->bootstrap = false;
        $dt->addColumn('Name');

        // Act
        $html = $dt->renderTable();

        // Assert — plain show/hide uses "[" separator, no Bootstrap or jui classes
        $this->assertStringContainsString('psh_plainTable_0', $html);
        $this->assertStringNotContainsString('btn-group', $html);
        $this->assertStringNotContainsString('ui-buttonset', $html);
        // Plain variant appends " ]" at the end of the list
        $this->assertStringContainsString(']', $html);
    }

    /**
     * renderTable() with showHide=true but all columns having showHide=false
     * must NOT render the toggle widget at all ($t remains 0, so the widget
     * is suppressed).
     */
    public function testRenderTableShowHideWidgetSuppressedWhenNoTogglableColumns(): void
    {
        // Arrange
        $dt = new Datatable('noToggle');
        $dt->showHide = true;
        // Add column with showHide=false so $t never increments
        $dt->addColumn('Hidden', false, true, true, '', '', false);

        // Act
        $html = $dt->renderTable();

        // Assert — no toggle button IDs produced
        $this->assertStringNotContainsString('psh_noToggle_', $html);
    }

    /**
     * renderTable() with groupBySelector=true renders the group-by dropdown
     * picker HTML including the selector element ID.
     */
    public function testRenderTableGroupBySelectorRendered(): void
    {
        // Arrange
        $dt = new Datatable('gbTable');
        $dt->addColumn('Category');
        $dt->addColumn('Value');
        $dt->groupBySelector = true;

        // Act
        $html = $dt->renderTable();

        // Assert — selector element and label present
        $this->assertStringContainsString('id="pf40_groupby_gbTable"', $html);
        $this->assertStringContainsString('pramnos-groupby-selector', $html);
    }

    /**
     * renderGroupBySelector() selects the correct option when groupByColumn is
     * set to a specific column index before rendering.
     */
    public function testRenderTableGroupBySelectorPreselectedColumn(): void
    {
        // Arrange
        $dt = new Datatable('gbPreTable');
        $dt->addColumn('Category');
        $dt->addColumn('Value');
        $dt->groupBySelector = true;
        $dt->groupByColumn   = 1; // pre-select column index 1

        // Act
        $html = $dt->renderTable();

        // Assert — the option for column index 1 ("Value") is selected
        $this->assertStringContainsString('value="1" selected', $html);
        // "None" option must NOT be selected
        $this->assertStringNotContainsString('value="-1" selected', $html);
    }

    /**
     * renderGroupBySelector() uses the Bootstrap "form-control" class on the
     * <select> when bootstrap=true (the default).
     */
    public function testRenderTableGroupBySelectorBootstrapClass(): void
    {
        // Arrange
        $dt = new Datatable('gbBootTable');
        $dt->addColumn('Name');
        $dt->groupBySelector = true;
        $dt->bootstrap       = true;

        // Act
        $html = $dt->renderTable();

        // Assert — Bootstrap class applied to the selector
        $this->assertStringContainsString('class="form-control"', $html);
    }

    /**
     * renderGroupBySelector() omits the "form-control" class when bootstrap=false.
     */
    public function testRenderTableGroupBySelectorNoBootstrapClass(): void
    {
        // Arrange
        $dt = new Datatable('gbNoBootTable');
        $dt->addColumn('Name');
        $dt->groupBySelector = true;
        $dt->bootstrap       = false;

        // Act
        $html = $dt->renderTable();

        // Assert — Bootstrap class absent
        $this->assertStringNotContainsString('form-control', $html);
    }

    // =========================================================================
    // fixColumnSearch() edge cases
    // =========================================================================

    /**
     * fixColumnSearch() with a column whose footsearch is a string (external
     * element ID) but searchvalue is empty must still bind jQuery event handlers
     * without injecting fnFilter for a pre-filled value.
     */
    public function testFixColumnSearchWithExternalIdAndEmptySearchvalue(): void
    {
        // Arrange
        $dt = new Datatable('extSearch');
        // footsearch='mySelect' (external element id), searchvalue empty
        $dt->addColumn('Status', true, true, true, '', '', true, 'left', 'mySelect', '');
        $dt->stateSave = false;

        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->render();

        // Assert — jQuery change/keyup handlers bound, no pre-filter call
        $this->assertStringContainsString("jQuery('#mySelect').change", $html);
        $this->assertStringContainsString('DataTableDelay', $html);
        // No fnFilter injection because searchvalue is empty
        $this->assertStringNotContainsString("extSearch.fnFilter( '', 0 )", $html);
    }

    /**
     * fixColumnSearch() with stateSave=true and an external element ID must
     * inject the aoPreSearchCols state-restore snippet.
     */
    public function testFixColumnSearchWithStateSaveAndExternalId(): void
    {
        // Arrange
        $dt = new Datatable('stateSearch');
        $dt->addColumn('Status', true, true, true, '', '', true, 'left', 'stateEl', '');
        $dt->stateSave = true;

        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->render();

        // Assert — state-restore snippet present
        $this->assertStringContainsString('aoPreSearchCols', $html);
        $this->assertStringContainsString("jQuery('#stateEl')", $html);
    }

    /**
     * fixColumnSearch() with footsearch=true but searchvalue='' must add the
     * autofootsearch input without injecting a pre-filter fnFilter call.
     * This exercises the footsearch===true branch with empty searchvalue.
     */
    public function testFixColumnSearchFootsearchTrueNoSearchvalue(): void
    {
        // Arrange
        $dt = new Datatable('footNoVal');
        // footsearch=true, searchvalue='' (empty)
        $dt->addColumn('Name', true, true, true, '', '', true, 'left', true, '');

        $doc = new class {
            public function enqueueStyle(string $style): void {}
            public function enqueueScript(string $script): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->render();

        // Assert — input rendered in tfoot, no pre-filter call injected
        // (the general tfoot keyup handler does call fnFilter but that is expected;
        // what must be absent is the codeEmbed pre-filter like "fnFilter( '', 0 )")
        $this->assertStringContainsString('id="autofootsearch_0"', $html);
        // The codeEmbed pre-filter with a quoted empty value must not be injected
        $this->assertStringNotContainsString("footNoVal.fnFilter( '', 0 )", $html);
    }

    // =========================================================================
    // renderJs() — additional branches
    // =========================================================================

    /**
     * renderJs() with addcss=false and addjs=false must not call enqueueStyle
     * or enqueueScript on the Document (zero calls).
     */
    public function testRenderJsWithAddCssFalseAndAddJsFalse(): void
    {
        // Arrange
        $dt = new Datatable('noCssJs');
        $dt->addColumn('Name');
        $dt->addcss = false;
        $dt->addjs  = false;

        $doc = new class {
            public int $styleCalls  = 0;
            public int $scriptCalls = 0;
            public function enqueueStyle(string $s): void  { $this->styleCalls++; }
            public function enqueueScript(string $s): void { $this->scriptCalls++; }
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderJs();

        // Assert — no enqueue calls at all
        $this->assertSame(0, $doc->styleCalls);
        $this->assertSame(0, $doc->scriptCalls);
    }

    /**
     * renderJs() with addcss=true and jui=false must enqueue 'datatables' style
     * but NOT 'datatables-ui' or 'jquery-ui'.
     */
    public function testRenderJsWithAddCssTrueAndJuiFalse(): void
    {
        // Arrange
        $dt = new Datatable('cssNoJui');
        $dt->addColumn('Name');
        $dt->addcss = true;
        $dt->jui    = false;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderJs();

        // Assert — 'datatables' style enqueued; UI variants absent
        $this->assertContains('datatables', $doc->styles);
        $this->assertNotContains('datatables-ui', $doc->styles);
        $this->assertNotContains('jquery-ui', $doc->styles);
    }

    /**
     * renderJs() with addcss=true, jui=true, and addjQueryUICss=false must
     * enqueue 'datatables-ui' but NOT 'jquery-ui'.
     */
    public function testRenderJsWithJuiTrueAndJQueryUICssFalse(): void
    {
        // Arrange
        $dt = new Datatable('juiNoCss');
        $dt->addColumn('Name');
        $dt->addcss         = true;
        $dt->jui            = true;
        $dt->addjQueryUICss = false;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderJs();

        // Assert — datatables-ui present, jquery-ui absent
        $this->assertContains('datatables-ui', $doc->styles);
        $this->assertNotContains('jquery-ui', $doc->styles);
    }

    /**
     * renderJs() with resposive=true (note the typo matches the property name)
     * must include '"responsive": true' in the output JavaScript.
     */
    public function testRenderJsResponsiveTrue(): void
    {
        // Arrange
        $dt = new Datatable('respTable');
        $dt->addColumn('Name');
        $dt->resposive = true;   // intentional typo matching the property

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — responsive config key present
        $this->assertStringContainsString('"responsive": true', $js);
    }

    /**
     * renderJs() with tableTools=false must NOT include the buttons/dom config
     * in the generated script.
     */
    public function testRenderJsTableToolsFalseOmitsButtons(): void
    {
        // Arrange
        $dt = new Datatable('noTools');
        $dt->addColumn('Name');
        $dt->tableTools = false;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — buttons declaration absent
        $this->assertStringNotContainsString("buttons :", $js);
        $this->assertStringNotContainsString("'copy'", $js);
    }

    /**
     * renderJs() with tableTools=true and jui=true must use the jQuery UI sDom
     * string rather than the standard dom string.
     */
    public function testRenderJsTableToolsWithJuiUsesSdomString(): void
    {
        // Arrange
        $dt = new Datatable('juiTools');
        $dt->addColumn('Name');
        $dt->tableTools = true;
        $dt->jui        = true;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — jQuery UI sDom present; standard dom absent
        $this->assertStringContainsString('"sDom"', $js);
        $this->assertStringNotContainsString('"right"', $js);
    }

    /**
     * renderJs() with source='' (client-side mode) must NOT include
     * "serverSide" or "ajax" keys in the generated script.
     */
    public function testRenderJsClientSideModeNoServerSideKey(): void
    {
        // Arrange
        $dt = new Datatable('clientTable');
        $dt->addColumn('Name');
        // source is empty — client-side mode

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — no server-side config
        $this->assertStringNotContainsString('"serverSide"', $js);
        $this->assertStringNotContainsString('"ajax"', $js);
    }

    /**
     * renderJs() with a non-empty $search property must include an oSearch
     * object in the DataTable initialisation so the table opens pre-filtered.
     */
    public function testRenderJsWithSearchPrefillsOSearch(): void
    {
        // Arrange
        $dt = new Datatable('searchTable');
        $dt->addColumn('Name');
        $dt->search = 'alice';

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — oSearch config injected with the search term
        $this->assertStringContainsString('"oSearch"', $js);
        $this->assertStringContainsString('"sSearch": "alice"', $js);
    }

    /**
     * renderJs() with stateSave=true must include the fnInitComplete callback
     * that restores per-column filter values from the saved state.
     */
    public function testRenderJsStateSaveTrueIncludesFnInitComplete(): void
    {
        // Arrange
        $dt = new Datatable('stateTable');
        $dt->addColumn('Name');
        $dt->stateSave = true;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — state-restore init callback present
        $this->assertStringContainsString('fnInitComplete', $js);
        $this->assertStringContainsString('aoPreSearchCols', $js);
        // stateSave flag itself must be "true"
        $this->assertStringContainsString('"stateSave": true', $js);
    }

    /**
     * renderJs() with a table name containing hyphens must sanitise the JS
     * variable name by replacing hyphens with underscores to avoid syntax errors.
     */
    public function testRenderJsSanitisesHyphenatedNameToJsVar(): void
    {
        // Arrange
        $dt = new Datatable('dt-users');
        $dt->addColumn('Name');

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — JS variable uses underscore-sanitised name; raw hyphen absent
        // as a JS identifier (still in the CSS selector string though)
        $this->assertStringContainsString('var dt_users =', $js);
        // The original hyphenated name is used for the jQuery selector (#dt-users)
        $this->assertStringContainsString("jQuery('#dt-users')", $js);
    }

    /**
     * renderJs() with groupByColumn set to a specific index must inject the
     * pf40_doGroup JS function and hook it onto the draw.dt event.
     */
    public function testRenderJsGroupByColumnInjectsGroupByJs(): void
    {
        // Arrange
        $dt = new Datatable('gbJs');
        $dt->addColumn('Category');
        $dt->addColumn('Value');
        $dt->groupByColumn = 0;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — group-by init variable and function are present
        $this->assertStringContainsString('pf40_gc_gbJs = 0', $js);
        $this->assertStringContainsString('pf40_doGroup_gbJs', $js);
        $this->assertStringContainsString("on('draw.dt'", $js);
    }

    /**
     * renderJs() with groupBySelector=true must inject the change-event listener
     * that updates the group-by column index at runtime.
     */
    public function testRenderJsGroupBySelectorInjectsChangeListener(): void
    {
        // Arrange
        $dt = new Datatable('gbSel');
        $dt->addColumn('Category');
        $dt->groupBySelector = true;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — selector change listener and group-by function present
        $this->assertStringContainsString("pf40_groupby_gbSel", $js);
        $this->assertStringContainsString("pf40_gc_gbSel", $js);
        $this->assertStringContainsString('addEventListener', $js);
    }

    /**
     * renderJs() with showHide=true generates addEventListener click handlers
     * for each visible column with a non-empty label.
     */
    public function testRenderJsShowHideGeneratesClickHandlers(): void
    {
        // Arrange
        $dt = new Datatable('shJs');
        $dt->addColumn('Name');
        $dt->addColumn('Email');
        $dt->showHide = true;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — click-handler code present for both columns (index 0 and 1)
        $this->assertStringContainsString("psh_shJs_0", $js);
        $this->assertStringContainsString("psh_shJs_1", $js);
        $this->assertStringContainsString('addEventListener', $js);
        $this->assertStringContainsString('fnSetColumnVis', $js);
    }

    /**
     * renderJs() with no columns sets aoColumns to an empty string (not the
     * JSON array) so the DataTables init receives no column definitions.
     */
    public function testRenderJsNoColumnsProducesEmptyAoColumns(): void
    {
        // Arrange
        $dt = new Datatable('emptyCol');
        // No columns added

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — aoColumns block absent (empty-columns path taken)
        $this->assertStringNotContainsString('"aoColumns"', $js);
    }

    /**
     * renderJs() with source set injects the "data": N positional mapping for
     * each column in server-side mode so DataTables 1.10+ maps array responses.
     */
    public function testRenderJsServerSideInjectsDataIndexOnColumns(): void
    {
        // Arrange
        $dt = new Datatable('srvCols', '/api/rows');
        $dt->addColumn('Name');
        $dt->addColumn('Email');

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — positional data indices injected for both columns
        $this->assertStringContainsString('"data": 0', $js);
        $this->assertStringContainsString('"data": 1', $js);
    }

    /**
     * renderJs() with sortOrder='desc' (default) must use "desc" in the order
     * array and ignore invalid sort direction strings.
     */
    public function testRenderJsSortOrderDescDefault(): void
    {
        // Arrange
        $dt = new Datatable('sortDesc', '/api/rows');
        $dt->addColumn('Name');
        $dt->sortOrder  = 'desc';
        $dt->sortColumn = 2;

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $js = $dt->renderJs();

        // Assert — correct column index and direction
        $this->assertStringContainsString('"order": [[2, "desc"]]', $js);
    }

    // =========================================================================
    // renderExistingTable() — additional branches
    // =========================================================================

    /**
     * renderExistingTable() with jui=true and tableTools=true must use the
     * jQuery UI dom string rather than the standard TableTools dom string.
     */
    public function testRenderExistingTableJuiWithTableToolsUsesJuiDom(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->jui        = true;
        $dt->tableTools = true;

        $doc = new class {
            public array $styles  = [];
            public array $scripts = [];
            public function enqueueStyle(string $s): void  { $this->styles[]  = $s; }
            public function enqueueScript(string $s): void { $this->scripts[] = $s; }
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->renderExistingTable('tbl');

        // Assert — jQuery UI dom variant used
        $this->assertStringContainsString('"dom": \'<"clear"><"H"lfTr>t<"F"ip>\'', $html);
        // jQuery UI CSS styles enqueued
        $this->assertContains('datatables-ui', $doc->styles);
        // tabletools and zeroclipboard scripts enqueued
        $this->assertContains('tabletools', $doc->scripts);
        $this->assertContains('zeroclipboard', $doc->scripts);
    }

    /**
     * renderExistingTable() with jui=true and tableTools=true must enqueue the
     * tabletools-ui stylesheet (not the plain tabletools stylesheet).
     */
    public function testRenderExistingTableJuiEnqueuesTabletoolsUiStyle(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->jui        = true;
        $dt->tableTools = true;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderExistingTable('tbl2');

        // Assert — tabletools-ui (not tabletools) enqueued for jui mode
        $this->assertContains('tabletools-ui', $doc->styles);
        $this->assertNotContains('tabletools', $doc->styles);
    }

    /**
     * renderExistingTable() with jui=false and tableTools=true must enqueue
     * the plain tabletools stylesheet (not tabletools-ui).
     */
    public function testRenderExistingTableNoJuiEnqueuesTabletoolsStyle(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->jui        = false;
        $dt->tableTools = true;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderExistingTable('tbl3');

        // Assert — plain tabletools style enqueued; jui variant absent
        $this->assertContains('tabletools', $doc->styles);
        $this->assertNotContains('tabletools-ui', $doc->styles);
    }

    /**
     * renderExistingTable() with tableTools=false must not enqueue tabletools
     * or zeroclipboard scripts and must not include the dom config string.
     */
    public function testRenderExistingTableWithTableToolsFalseOmitsDom(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->tableTools = false;

        $doc = new class {
            public array $scripts = [];
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void { $this->scripts[] = $s; }
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->renderExistingTable('tbl4');

        // Assert — no dom configuration string
        $this->assertStringNotContainsString('"dom"', $html);
        // tabletools and zeroclipboard NOT enqueued
        $this->assertNotContains('tabletools', $doc->scripts);
        $this->assertNotContains('zeroclipboard', $doc->scripts);
    }

    /**
     * renderExistingTable() with addcss=false and addjs=false must produce the
     * JS init block without enqueueing any styles or scripts.
     */
    public function testRenderExistingTableWithAddCssFalseAndAddJsFalse(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->addcss = false;
        $dt->addjs  = false;

        $doc = new class {
            public int $styleCalls  = 0;
            public int $scriptCalls = 0;
            public function enqueueStyle(string $s): void  { $this->styleCalls++; }
            public function enqueueScript(string $s): void { $this->scriptCalls++; }
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->renderExistingTable('tbl5');

        // Assert — no enqueue calls, but JS init still produced
        $this->assertSame(0, $doc->styleCalls);
        $this->assertSame(0, $doc->scriptCalls);
        $this->assertStringContainsString("jQuery('#tbl5').dataTable", $html);
    }

    /**
     * renderExistingTable() with jui=true must include '"bJQueryUI": true' in
     * the output and must enqueue the datatables-ui stylesheet.
     */
    public function testRenderExistingTableJuiFlagSetsBjQueryUI(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->jui        = true;
        $dt->tableTools = false;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->renderExistingTable('tbl6');

        // Assert — bJQueryUI key present; datatables-ui style enqueued
        $this->assertStringContainsString('"bJQueryUI": true', $html);
        $this->assertContains('datatables-ui', $doc->styles);
    }

    /**
     * renderExistingTable() with jui=false must enqueue 'datatables' (not
     * 'datatables-ui') and must NOT output '"bJQueryUI"'.
     */
    public function testRenderExistingTableNoJuiEnqueuesPlainDatatablesStyle(): void
    {
        // Arrange
        $dt = new Datatable();
        $dt->jui        = false;
        $dt->tableTools = false;

        $doc = new class {
            public array $styles = [];
            public function enqueueStyle(string $s): void  { $this->styles[] = $s; }
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $html = $dt->renderExistingTable('tbl7');

        // Assert — plain datatables style, no jui flag
        $this->assertContains('datatables', $doc->styles);
        $this->assertNotContains('datatables-ui', $doc->styles);
        $this->assertStringNotContainsString('"bJQueryUI"', $html);
    }

    // =========================================================================
    // Default property values
    // =========================================================================

    /**
     * Verifies the default property values of a freshly constructed Datatable
     * so that future changes to defaults are immediately caught by tests.
     */
    public function testDefaultPropertyValues(): void
    {
        // Arrange / Act
        $dt = new Datatable();

        // Assert — check key defaults
        $this->assertTrue($dt->addcss);
        $this->assertTrue($dt->addjQueryUICss);
        $this->assertTrue($dt->addjs);
        $this->assertSame(50, $dt->iDisplayLength);
        $this->assertFalse($dt->stateSave);
        $this->assertSame(0, $dt->sortColumn);
        $this->assertSame('Desc', $dt->sortOrder);
        $this->assertTrue($dt->tableTools);
        $this->assertSame('full_numbers', $dt->sPaginationType);
        $this->assertSame('[]', $dt->aoData);
        $this->assertTrue($dt->showHide);
        $this->assertSame('|', $dt->separateChar);
        $this->assertNull($dt->aLengthMenu);
        $this->assertTrue($dt->bSort);
        $this->assertFalse($dt->resposive);
        $this->assertFalse($dt->bAutoWidth);
        $this->assertSame('display', $dt->tableClass);
        $this->assertSame('', $dt->search);
        $this->assertFalse($dt->footerTextSearch);
        $this->assertFalse($dt->editable);
        $this->assertFalse($dt->jui);
        $this->assertTrue($dt->bootstrap);
        $this->assertNull($dt->groupByColumn);
        $this->assertFalse($dt->groupBySelector);
    }

    // =========================================================================
    // aLengthMenu auto-population
    // =========================================================================

    /**
     * renderJs() must auto-populate aLengthMenu with a default two-row array
     * the first time it is called when aLengthMenu was NULL.
     */
    public function testRenderJsAutoPopulatesALengthMenuWhenNull(): void
    {
        // Arrange
        $dt = new Datatable('lmTable');
        $dt->addColumn('Name');
        $this->assertNull($dt->aLengthMenu); // precondition

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderJs();

        // Assert — aLengthMenu is now a string (no longer NULL)
        $this->assertNotNull($dt->aLengthMenu);
        $this->assertStringContainsString('[[10, 25, 50, 100, -1]', $dt->aLengthMenu);
    }

    /**
     * renderExistingTable() must also auto-populate aLengthMenu when NULL.
     */
    public function testRenderExistingTableAutoPopulatesALengthMenu(): void
    {
        // Arrange
        $dt = new Datatable();
        $this->assertNull($dt->aLengthMenu); // precondition

        $doc = new class {
            public function enqueueStyle(string $s): void {}
            public function enqueueScript(string $s): void {}
        };
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $doc;

        // Act
        $dt->renderExistingTable('existingTbl');

        // Assert — aLengthMenu populated
        $this->assertNotNull($dt->aLengthMenu);
        $this->assertStringContainsString('[[10, 25, 50, 100, -1]', $dt->aLengthMenu);
    }
}
