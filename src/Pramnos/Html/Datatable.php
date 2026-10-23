<?php

namespace Pramnos\Html;

use Pramnos\Framework\Base;
use Pramnos\Html\Datatable;

/**
 * Class for Datatable jquery library
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Datatable extends Base
{

    /**
     * Name of the table. This sets the initial IDs and variables
     * @var string
     */
    public $name = '';

    /**
     * JSON Source - a file to get ajax requests
     * @var string
     */
    public $source = '';

    /**
     * Include CSS files to the document
     * @var boolean
     */
    public $addcss = true;

    /**
     * Use JqueryUI CSS
     * @var boolean
     */
    public $addjQueryUICss = true;

    /**
     * Add javascript files to the document
     * @var boolean
     */
    public $addjs = true;

    /**
     * Default number of displayed columns
     * @var integer
     */
    public $iDisplayLength = 50;

    /**
     * Save state by cookie
     * @var boolean
     */
    public $stateSave = false;

    /**
     * Default column to sort
     * @var integer
     */
    public $sortColumn = 0;

    /**
     * Default sorting - Desc or Asc (Desc is the default)
     * @var string
     */
    public $sortOrder = "Desc";

    /**
     * Include tabletools js extension
     * @var boolean
     */
    public $tableTools = true;

    /**
     * The name an export (Excel, CSV, PDF) is saved under; empty for the page's title, which is
     * what Buttons uses by default.
     * @var string
     */
    public $exportName = '';

    /**
     * Pagination Type
     * @var string
     */
    public $sPaginationType = "full_numbers";

    /**
     * Columns of the table. Better handle this using the class methods.
     * @var array
     */
    public $aoColumns = array();

    /**
     * Filter specific values on db
     * @var array
     */
    public $aoData = array();

    /**
     * Display a "show/hide" menu for all columns
     * @var boolean
     */
    public $showHide = true;

    /**
     * @var string
     */
    public $separateChar = "|";

    /**
     * Default pagination length menu. Default is: 10, 25, 50, 100, All
     * Form: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]
     * @var string
     */
    public $aLengthMenu = NULL;

    /**
     * Enable or disable sorting of columns. Sorting of individual columns
     * can be disabled by the "bSortable" option for each column.
     * @var boolean
     */
    public $bSort = true;

    public $resposive = false;
    /**
     *
     * @var boolean
     */
    public $bAutoWidth = false;

    /**
     * Class of the table div
     * @var string
     */
    public $tableClass = 'display';

    /**
     * What this table is, for a reader who cannot see the heading above it.
     *
     * Rendered as a visually hidden `<caption>`. Empty by default rather than guessed at: a
     * caption invented from the table's internal name — `dt-emails` — is worse than none, because
     * a reader hears it and believes it was written for them.
     *
     * @var string
     */
    public string $caption = '';

    /**
     * @var string
     */
    public $codeEmbed;

    /**
     * @var string
     */
    public $search = "";

    /**
     * I don't even remember what is this for... :-(
     * @var boolean
     */
    public $footerTextSearch = false;

    /**
     * Milliseconds to wait after the last keystroke before searching.
     *
     * Emitted in two places, because the table debounces in two: the footer
     * filters' own `keyup` handler, and DataTables' `searchDelay` option for the
     * global search box.
     *
     * Not the same number for every table, which is why it is a property. A list
     * with six `LEFT JOIN`s behind it wants a second or more — a consuming
     * application sets `1200` on its heaviest admin list and `600` on two
     * reports — and with a fixed 500 the query rate on exactly those tables
     * doubles. The default stays 500.
     *
     * @var int
     */
    public $searchDelay = 500;

    /**
     * Characters a footer filter needs before it searches at all.
     *
     * The per-column inputs under `footerTextSearch` used to filter from the first
     * keystroke and without a debounce: typing `papadopoulos` into a column filter sent
     * twelve AJAX requests and twelve server-side queries, and the first of them was
     * `LIKE '%p%'` across the whole table. On a consuming application that is 23 admin
     * tables, the largest ones included.
     *
     * Three, like the global search box's own minimum, and `0` turns the guard off.
     *
     * **An empty box is always let through**, which is the one deliberate difference
     * from the value's older behaviour: clearing a filter has to clear the filter, and
     * a guard that blocks every length below three blocks that too — so the column
     * stayed filtered on a term no longer on screen.
     *
     * @var int
     */
    public $minSearchLength = 3;

    /**
     *
     * @var string
     */
    public $footer = "";

    /**
     * Allow live edits
     * @var boolean
     */
    public $editable = false;

    /**
     * Rows of the table
     * @var array
     */
    public $rows = array();

    /**
     * use jQuery UI
     * @var boolean
     */
    public $jui = false;
    /**
     * Bootstrap's table classes and its dropdown for the column picker.
     *
     * Follows the project's UI system: on by default under `scaffold_theme = bootstrap`, or with
     * no `scaffold_theme` at all; off under tailwind and plain-css, where Bootstrap's classes have
     * no stylesheet and its dropdown no script, and the table keeps DataTables' own `display`
     * look and a plain-link column picker. Set it after construction to choose either way.
     *
     * The dropdown carries both Bootstrap 3's and Bootstrap 5's attributes and classes, so it
     * opens under either.
     *
     * @var boolean
     */
    public $bootstrap = true;

    /**
     * How dense the table is under tailwind: `md`, the size the rest of a page reads at, or `sm`
     * for a wide table that has to fit. Bootstrap and plain-css draw as they always have.
     *
     * @var string
     */
    public $size = 'md';

    /**
     * Column index (0-based) to group rows by client-side.
     * null means no grouping. Set directly or let the user pick via $groupBySelector.
     * @var int|null
     */
    public $groupByColumn = null;

    /**
     * When true, renders a column-picker dropdown above the table so the user can
     * choose (or clear) the group-by column at runtime without a page reload.
     * @var bool
     */
    public $groupBySelector = false;

    public function __construct($name = '', $source = '')
    {
        $this->name = trim($name);
        $this->source = trim($source);
        $this->aoData = json_encode($this->aoData);
        $this->bootstrap = self::projectUsesBootstrap();
        parent::__construct();
    }

    /**
     * The classes DataTables draws its own controls with, per UI system — the search box, the
     * page-length select, the page buttons, the processing note and Buttons' buttons.
     *
     * DataTables writes its own DOM, so a theme's classes cannot reach it from the markup. Under
     * tailwind and plain-css these replace DataTables' defaults — and a Bootstrap integration's,
     * where the page loads one — so the controls draw as the rest of the page does. Bootstrap
     * keeps what its integration sets.
     */
    private const CONTROL_CLASSES = [
        'tailwind' => [
            'classes' => [
                'sFilterInput'        => 'input input-sm input-bordered',
                'sLengthSelect'       => 'select select-sm select-bordered',
                'sPageButton'         => 'paginate_button btn btn-sm',
                'sPageButtonActive'   => 'current btn-active',
                'sPageButtonDisabled' => 'disabled btn-disabled',
                'sProcessing'         => 'dataTables_processing pf-datatable-processing',
                'sWrapper'            => 'dataTables_wrapper',
            ],
            'button'   => 'btn btn-sm',
            'table'    => 'table table-zebra',
            'table-sm' => 'table-sm',
            'picker'   => ['details' => 'dropdown dropdown-end', 'summary' => 'btn btn-sm', 'menu' => 'menu menu-sm flex-nowrap bg-base-100 rounded-box shadow'],
        ],
        'plain-css' => [
            'classes' => [
                'sFilterInput'        => 'form-control pf-ui-input-sm',
                'sLengthSelect'       => 'form-control pf-ui-input-sm',
                'sPageButton'         => 'paginate_button btn btn-outline-secondary btn-sm',
                'sPageButtonActive'   => 'current pf-ui-current',
                'sPageButtonDisabled' => 'disabled pf-ui-disabled',
                'sProcessing'         => 'dataTables_processing pf-datatable-processing',
                'sWrapper'            => 'dataTables_wrapper',
            ],
            'button'   => 'btn btn-secondary btn-sm',
            'table'    => 'table table-hover',
            'table-sm' => '',
            'picker'   => ['details' => '', 'summary' => 'btn btn-outline-secondary btn-sm', 'menu' => ''],
        ],
    ];

    /**
     * A value as a JavaScript string literal, safe inside a `<script>`: quotes, `<`, `>` and `&`
     * escaped, so a value cannot end the string or the element it is written into.
     */
    private static function js(string $value): string
    {
        // Slashes left as they are: `<` is escaped, so `</script>` cannot form, and a URL reads as one.
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * DataTables' own words — the length menu, the count, the search, the page buttons — in the
     * application's language, as a JavaScript object.
     */
    private static function language(): string
    {
        return (string) json_encode([
            'lengthMenu'     => t('Show _MENU_ rows'),
            'info'           => t('Rows _START_ to _END_ of _TOTAL_'),
            'infoEmpty'      => t('No rows to show'),
            'infoFiltered'   => t('(found among _MAX_)'),
            'search'         => t('Search the table:'),
            'zeroRecords'    => t('Nothing matches the search.'),
            'emptyTable'     => t('Nothing in this table yet.'),
            'processing'     => t('Loading the rows…'),
            'loadingRecords' => t('Loading the rows…'),
            'paginate'       => ['first' => t('First page'), 'previous' => t('Previous page'), 'next' => t('Next page'), 'last' => t('Last page')],
            'buttons'        => ['copy' => t('Copy the rows'), 'print' => t('Print the rows')],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    }

    /** The project's UI system: `scaffold_theme`, or bootstrap where none is declared. */
    private static function uiSystem(): string
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $theme = is_object($app) ? (string) ($app->applicationInfo['scaffold_theme'] ?? '') : '';

        return $theme === '' ? 'bootstrap' : $theme;
    }

    /** Whether the project's UI system is Bootstrap — or undeclared, as before there was a choice. */
    private static function projectUsesBootstrap(): bool
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $theme = is_object($app) ? (string) ($app->applicationInfo['scaffold_theme'] ?? '') : '';

        return $theme === '' || $theme === 'bootstrap';
    }

    /**
     * Creates an instance of Column and adds it to the
     * columns array ($this->asColumns).
     * @param string $label
     * @param mixed $bVisible Boolean for Visibility or array with all options
     * @param boolean $bSortable
     * @param boolean $bSearchable
     * @param string $sType
     * @param string $footer
     * @param boolean $showHide
     * @param string $align
     * @param mixed $footsearch boolean for input or string to insert
     * @param string $searchvalue
     * @return Datatable
     */
    public function addColumn($label, $bVisible = true, $bSortable = true,
        $bSearchable = true, $sType = '', $footer = "", $showHide = true,
        $align = 'left', $footsearch = false, $searchvalue = "")
    {
        if ($sType == "") {
            $sType = 'html';
        }

        if (is_array($bVisible)) {
            $this->aoColumns[$label] = new Datatable\Column(
                $label
            );
            foreach ($bVisible as $key => $value) {
                $this->aoColumns[$label]->$key = $value;
                $this->aoColumns[$label]->js =
                    $this->aoColumns[$label]->getJs();
            }
        } else {
            $this->aoColumns[$label] = new Datatable\Column(
                $label, $bVisible, $bSortable, $bSearchable, $sType, $footer,
                $showHide, $align, $footsearch, $searchvalue
            );
        }
        $this->aoColumns[$label]->parent = &$this;
        return $this;
    }

    public function addRow(array $row)
    {
        $this->rows[] = $row;
        return $this;
    }

    /**
     * Renders the html part of the table
     * @return string
     */
    public function renderTable()
    {
        $controls = $this->controlClasses();
        if ($this->bootstrap == true) {
            $this->tableClass .= ' table table-striped table-bordered '
                . 'table-hover ';
        } elseif ($controls !== null && !str_contains(' ' . $this->tableClass . ' ', ' table ')) {
            $this->tableClass .= ' ' . $controls['table'] . ($this->size === 'sm' ? ' ' . $controls['table-sm'] : '') . ' ';
        }
        $this->fixColumnSearch();
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $return = "";
        if ($this->showHide == true) {
            $n = 0;
            $t = 0;
            if ($this->jui == true) {
                $showHide = '<div class="ui-buttonset" style="float: right; '
                    . 'font-size: x-small">' . $lang->_('Show') . ":";
            } elseif ($this->bootstrap == true) {
                // Bootstrap 3's `btn-default`/`data-toggle` and 5's `btn-secondary`/
                // `data-bs-toggle`: each reads its own and ignores the other's.
                $showHide = '<div class="btn-group" style="float: right; '
                    . 'font-size: x-small"><button type="button" '
                    . 'class="btn btn-default btn-secondary btn-sm dropdown-toggle" '
                    . 'data-toggle="dropdown" data-bs-toggle="dropdown" aria-expanded="false">'
                    . $lang->_('Show')
                    . '<span class="caret"></span></button>'
                    . '<ul class="dropdown-menu" role="menu">';
            } else {
                /*
                 * A "Show" button with the columns in a list, as Bootstrap's dropdown is: a line
                 * of links grew into four lines of text over a table of sixty columns. A
                 * `<details>`, so it opens with no script and from the keyboard; the links keep
                 * their ids, which the show/hide handlers bind by.
                 */
                $picker   = $this->pickerClasses();
                $showHide = '<details class="pf-datatable-columns ' . $picker['details'] . '">'
                    . '<summary class="' . $picker['summary'] . '">' . $lang->_('Show') . ' &#9662;</summary>'
                    . '<ul class="pf-datatable-columns-menu ' . $picker['menu'] . '">';
            }
            foreach ($this->aoColumns as $column) {

                if ($column->showHide == true && trim($column->label) != "") {
                    $btnId = 'psh_' . $this->name . '_' . $n;
                    if ($this->jui == true) {
                        $showHide .= '<a href="#" id="' . $btnId . '">'
                            . '<span style="padding-left:3px; padding-right:3px;" '
                            . 'class="ui-button ui-state-default">' . $column->label
                            . '</span></a>';

                    } elseif ($this->bootstrap == true) {
                        $showHide .= '<li><a class="dropdown-item" href="#" id="' . $btnId . '">'
                            . $column->label . '</a></li>';
                    } else {
                        $showHide .= '<li><a href="#" id="' . $btnId . '">'
                            . $column->label . '</a></li>';
                    }
                    $t+=1;
                }
                $n+=1;
            }

            if ($this->jui == true) {
                $showHide .= '</div>';
            } elseif ($this->bootstrap == true) {
                $showHide .= '</ul></div>';
            } else {
                $showHide .= '</ul></details>';
            }
            if ($t != 0) {
                $return .= $showHide;
            }
        }
        if ($this->groupBySelector === true) {
            $return .= $this->renderGroupBySelector($lang);
        }
        /*
         * A caption, and a live region for the rows that arrive later.
         *
         * Two separate gaps, both invisible to whoever built the screen.
         *
         * A table with no caption is announced as "table, 7 columns" and nothing else, so a
         * reader moving between the several tables an admin screen has cannot tell them apart.
         * `caption` is the element for that, and it is hidden visually rather than omitted —
         * the heading above the table serves a sighted reader already.
         *
         * And this table's rows are fetched. `aria-live="polite"` makes a reader announce that
         * the contents changed once the request comes back; without it, sorting or paging is
         * silence followed by different data under the same heading. Polite rather than assertive
         * because a result set is not an emergency: it waits for the reader to finish its
         * sentence.
         */
        $caption = $this->caption === ''
            ? ''
            : "\n" . '<caption class="pf-visually-hidden">'
                . htmlspecialchars($this->caption, ENT_QUOTES, 'UTF-8') . '</caption>';

        $return .= '<table id="' . $this->name . '" class="'
            . $this->tableClass . '" cellspacing="0" width="100%"'
            . ' aria-live="polite" aria-relevant="additions text">' . $caption . '
        <thead>
            <tr>';
        foreach ($this->aoColumns as $column) {
            $return .= "\n" . '<th align="' . $column->align . '">'
                . $column->label . '</th>';
        }
        $return .= "
            </tr>
        </thead>
        <tbody>
        ";
        foreach ($this->rows as $row) {
            $return .= "<tr>\n";
            foreach ($row as $c) {
                $return .= "<td>";
                $return .= $c;
                $return .= "</td>\n";
            }
            $return .= "</tr>\n";
        }
        $return .= "
        </tbody>
        <tfoot>
        ";
        foreach ($this->aoColumns as $column) {
            $return .= "\n" . '<th>' . $column->footer . '</th>';
        }
        $return .= "
        </tfoot>
    </table>" . $this->footer . "<br /><br />
        ";

        // A hook the themes' components.css styles DataTables' own DOM under: the wrapper, the
        // page buttons, the processing note, the sort marks.
        return '<div class="pf-datatable">' . $return . '</div>';
    }

    /**
     * The column picker's classes: the UI system's, or the plain stylesheet's where it has none.
     *
     * @return array{details: string, summary: string, menu: string}
     */
    private function pickerClasses(): array
    {
        return (self::CONTROL_CLASSES[self::uiSystem()] ?? self::CONTROL_CLASSES['plain-css'])['picker'];
    }

    /**
     * The control classes for this table's UI system, or null where DataTables keeps its own:
     * Bootstrap (its integration has them) and jQuery UI.
     *
     * @return array{classes: array<string, string>, button: string, table: string, table-sm: string, picker: array<string, string>}|null
     */
    private function controlClasses(): ?array
    {
        if ($this->bootstrap == true || $this->jui == true) {
            return null;
        }

        return self::CONTROL_CLASSES[self::uiSystem()] ?? null;
    }

    /**
     * The script that hands DataTables the UI system's classes, once per page, before the table
     * is drawn — and the options that keep a Bootstrap integration's renderer and grid out.
     *
     * @return array{0: string, 1: string} The script, and the table's extra options
     */
    private function controlScript(): array
    {
        $controls = $this->controlClasses();
        if ($controls === null) {
            return ['', ''];
        }
        $classes = json_encode($controls['classes'], JSON_UNESCAPED_SLASHES);
        $button  = json_encode($controls['button']);
        $script  = "if (jQuery.fn.dataTable && !jQuery.fn.dataTable.pfClasses) {\n"
            . "        jQuery.fn.dataTable.pfClasses = true;\n"
            . "        jQuery.extend(jQuery.fn.dataTable.ext.classes, {$classes});\n"
            . "        if (jQuery.fn.dataTable.Buttons) { jQuery.fn.dataTable.Buttons.defaults.dom.button.className = {$button}; }\n"
            . "    }";
        // A renderer no integration registered falls back to DataTables' own; and the layout
        // without a Bootstrap grid's rows and columns, unless the table asked for one of its own.
        $options = '"renderer": "pf",' . ($this->tableTools ? '' : "\n            \"dom\": 'lfrtip',");

        return [$script, $options];
    }

    /**
     * Fixes the footer
     */
    /**
     * The table's name as a JavaScript identifier.
     *
     * `renderJs()` declares the table into a variable named after it, and an id like
     * `dt-users` is not a legal identifier — `dt-users.fnFilter(…)` parses as
     * `dt - users.fnFilter(…)`, which is a `ReferenceError` for `dt` at the first
     * keystroke in a column filter and nothing at all in the console until then.
     *
     * One definition, used by every emitter: this method existed inline in
     * `renderJs()` while `fixColumnSearch()` interpolated the raw name, so the two
     * halves of the same script disagreed about what the table was called.
     */
    private function jsVar(): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_$]/', '_', $this->name);
    }

    private function fixColumnSearch()
    {
        // Cast at the boundary, like $searchDelay: this lands inside a <script>, so a
        // non-numeric value would be markup rather than a number.
        $minSearchLength = max(0, (int) $this->minSearchLength);
        // The JS identifier, not the table id: see jsVar().
        $jsVar = $this->jsVar();
        $c = 0;
        // Each search box's minimum, by its column's index — the box's `name`.
        $minimums = [];
        foreach ($this->aoColumns as $key => $column) {
            if ($column->footsearch === true) {
                $minimums[$c] = $column->minSearchLength === null
                    ? $minSearchLength
                    : max(0, (int) $column->minSearchLength);

                /**
                 * The column's own search box.
                 *
                 * `class="pf-footsearch"` rather than an inline width: a bare `<input>`
                 * in a modern CSS reset has no border, no padding and no background, so
                 * the box was **there and invisible** — a filter row that looked empty.
                 * Every bundled theme styles the class; a theme that does not still gets
                 * the browser's own control, because nothing here removes it.
                 */
                $this->aoColumns[$key]->footer .= '<input id="autofootsearch_'
                    . $c . '" value="' . htmlspecialchars(
                        (string) $this->aoColumns[$key]->searchvalue,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '" name="' . $c
                    . '" class="pf-footsearch" type="search"'
                    . ' placeholder="' . htmlspecialchars(
                        (string) $this->aoColumns[$key]->label,
                        ENT_QUOTES,
                        'UTF-8'
                    ) . '…" />';
                $this->footerTextSearch = true;
                if ($column->searchvalue != "") {
                    // A JavaScript string, encoded: the value often comes from the address, and a
                    // quote in it ended the string and ran what followed on the admin's page.
                    $s = self::js((string) $column->searchvalue);
                    $this->codeEmbed .=<<<embed
            {$jsVar}.fnFilter( $s, $c );
embed;
                }
            } elseif ($column->footsearch !== false) {
                $id = $column->footsearch;
                if ($column->searchvalue != "") {
                    // A JavaScript string, encoded: the value often comes from the address, and a
                    // quote in it ended the string and ran what followed on the admin's page.
                    $s = self::js((string) $column->searchvalue);
                    $this->codeEmbed .=<<<embed
            {$jsVar}.fnFilter( $s, $c );
embed;
                }

                if ($this->stateSave == true) {
                    $this->codeEmbed.=<<<embed
    if({$jsVar}.fnSettings().aoPreSearchCols[$c].sSearch.length>0){
        jQuery('#$id').val({$jsVar}.fnSettings().aoPreSearchCols[$c].sSearch);
    }
embed;
                }
#{$jsVar}.fnFilter( $('#$id').val(),$c ); inside next code embed
                $this->codeEmbed.=<<<embed

        jQuery('#$id').change( function () {
            {$jsVar}.fnFilter( jQuery(this).val(),$c );
        } );
        jQuery('#$id').keyup(DataTableDelay(function(){
            {$jsVar}.fnFilter( jQuery(this).val(),$c );
        } ));

embed;
            }

            $c++;
        }

        if ($this->footerTextSearch == true) {
            /*
             * This table's footer only: a page with two tables bound every footer box to both,
             * so typing under one filtered the other too. By element rather than by selector,
             * since the table's name is not escaped for CSS.
             *
             * No focus/blur pair: the boxes carry a `placeholder`, which is what that pair
             * imitated by writing the label into the value — and its blur read an `asInitVals`
             * nothing declared, a ReferenceError on every empty box left.
             *
             * Delegated from the table's wrapper, not bound to the boxes: with `scrollX` —
             * always on here — DataTables moves the visible footer into a table of its own and
             * leaves under this one a copy for the widths, rebuilt on every draw. Bound to the
             * table's own footer, the handler sat on that copy and was gone at the first draw,
             * and typing in the boxes on screen filtered nothing. The wrapper holds both and is
             * never rebuilt.
             */
            $minimumsJs = json_encode((object) $minimums);
            $this->codeEmbed.=<<<embed
   jQuery({$jsVar}.api().table().container()).on( "keyup", "tfoot input", DataTableDelay( function () {
        /* Filter on the column (the index) of this element */

        /*
         * Below the column's minimum, and not empty: too little to be worth a query
         * on every table this filters. Empty always passes — clearing the box has to
         * clear the filter.
         */
        var pfMin = {$minimumsJs}[jQuery(this).attr('name')];
        if (this.value.length > 0 && this.value.length < (pfMin === undefined ? {$minSearchLength} : pfMin)) {
            return;
        }

        {$jsVar}.fnFilter( this.value, jQuery(this).attr('name') );
    } ) );
embed;
        }
    }

    /**
     * Builds the group-by column-picker dropdown HTML.
     * Only called when $groupBySelector === true.
     * @param  \Pramnos\Language\Language $lang
     * @return string
     */
    private function renderGroupBySelector($lang): string
    {
        $selectorId  = 'pf40_groupby_' . $this->name;
        $selectedCol = $this->groupByColumn !== null ? (int) $this->groupByColumn : -1;

        $html  = '<div class="pramnos-groupby-selector" style="margin-bottom:8px;">';
        $html .= '<label for="' . $selectorId . '" style="margin-right:5px;">'
            . $lang->_('Group by') . ':</label>';
        $html .= '<select id="' . $selectorId . '"';
        if ($this->bootstrap === true) {
            $html .= ' class="form-control"';
        }
        $html .= ' style="display:inline-block;width:auto;margin-right:8px;">';
        $noneSelected = ($selectedCol === -1) ? ' selected' : '';
        $html .= '<option value="-1"' . $noneSelected . '>' . $lang->_('None') . '</option>';

        $n = 0;
        foreach ($this->aoColumns as $column) {
            if (trim($column->label) !== '') {
                $selected = ($selectedCol === $n) ? ' selected' : '';
                $html .= '<option value="' . $n . '"' . $selected . '>'
                    . htmlspecialchars($column->label, ENT_QUOTES, 'UTF-8')
                    . '</option>';
            }
            $n++;
        }
        $html .= '</select></div>';
        return $html;
    }

    /**
     * Renders the Javascript part of the table
     * @return string
     */
    public function renderJs()
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $document = \Pramnos\Framework\Factory::getDocument();
        // JS variable name — see jsVar(); a hyphen in the id is not an identifier.
        $jsVar = $this->jsVar();
        if ($this->aLengthMenu === NULL) {
            $this->aLengthMenu = '[[10, 25, 50, 100, -1], [10, 25, 50, 100, "'
                . $lang->_('All') . '"]]';
        }

        if ($this->addcss == true) {
            if ($this->jui == true) {
                $document->enqueueStyle('datatables-ui');
                if ($this->addjQueryUICss == true) {
                    $document->enqueueStyle('jquery-ui');
                }
            }

            $document->enqueueStyle('datatables');


        }
        if ($this->addjs == true) {
            $document->enqueueScript('datatables');
        }


        if (count($this->aoColumns) == 0) {
            $aoColumns = "";
        } else {
            $aoColumns = '"aoColumns": [' . "\n";
            $colIdx    = 0;
            $colTotal  = count($this->aoColumns);
            foreach ($this->aoColumns as $c) {
                // In server-side mode, inject "data": N so DataTables 1.10+ maps
                // positional array values to the correct column.
                if ($this->source !== '') {
                    $colJs = rtrim($c->js, '}') . ', "data": ' . $colIdx . '}';
                } else {
                    $colJs = $c->js;
                }
                $aoColumns .= $colJs;
                if ($colIdx + 1 < $colTotal) {
                    $aoColumns .= ', ';
                }
                $colIdx++;
            }
            $aoColumns .= '],';
        }
        $language = '"language": ' . self::language() . ',';
        if ($this->stateSave === true) {
            $this->stateSave = "true";
        } elseif ($this->stateSave === false) {
            $this->stateSave = 'false';
        }


        $bAutoWidth = '"bAutoWidth": '
            . \Pramnos\General\Helpers::bool2string($this->bAutoWidth) . ',';

        $jui = "";
        if ($this->jui == true) {
            $jui = '"bJQueryUI": true,';
        }

        if ($this->resposive == true) {
            $jui .= '"responsive": true,';
        }


        $tabletools = "";
        if ($this->tableTools == true) {
            $tabletools = $this->exportName === ''
                ? " buttons : [   'copy', 'excel', 'pdf', 'csv', 'print' ],"
                : ' buttons : [' . implode(', ', array_map(
                    fn (string $b): string => in_array($b, ['excel', 'pdf', 'csv'], true)
                        ? '{"extend": "' . $b . '", "filename": ' . self::js($this->exportName) . '}' : "'" . $b . "'",
                    ['copy', 'excel', 'pdf', 'csv', 'print']
                )) . '],';
            if ($this->jui == true) {
                $tabletools .= '"sDom": \'<"clear"><"H"lfBr>t<"F"ip>\',';
            } else {
                // The buttons, the page length and the search on one line: `.pf-datatable-top`.
                $tabletools .= " dom: '<\"pf-datatable-top\"Blf>rtip', ";
            }
        }
        $sf = "";
        $search = "";
        if ($this->search != "") {
            $search = '"oSearch": {"sSearch": ' . self::js(trim((string) $this->search)) . '},';
        }
        $ss = "";
        if ($this->stateSave == 'true') {
            $ss = <<<ss
            "fnInitComplete": function() {

                var oSettings = this.fnSettings();
                // The visible footer: with scrollX the id is also on the copy under the table,
                // which comes first and is not on screen.
                var footer = jQuery(this.api().table().footer());
                for ( var i=0 ; i<oSettings.aoPreSearchCols.length ; i++ ){
                    if(oSettings.aoPreSearchCols[i].sSearch.length>0){
                        footer.find("#autofootsearch_"+i).val(oSettings.aoPreSearchCols[i].sSearch);
                    }
                }
                },
ss;
        }

        $bSort = \Pramnos\General\Helpers::bool2string($this->bSort);

        $ajaxsource = '';
        if ($this->source != '') {
            $extraDataJson = json_encode($this->aoData ?: []);
            $sortDir       = strtolower($this->sortOrder) === 'asc' ? 'asc' : 'desc';
            $ajaxsource    = '"serverSide": true,' . "\n"
                . '"ajax": {"url": ' . self::js($this->source) . ', "type": "POST",'
                . '"data": function(d){'
                . 'var e=' . $extraDataJson . ';'
                . 'for(var i=0;i<e.length;i++){d[e[i].name]=e[i].value;}'
                . '}},' . "\n"
                . '"order": [[' . (int)$this->sortColumn . ', "' . $sortDir . '"]],' . "\n";
        }


        $fnDrawCallback = '';
        if ($this->editable == true) {
            $fnDrawCallback = <<<table
   "fnDrawCallback": function () {
            $('#$this->name tbody td').editable( '$this->source', {
                "callback": function( sValue, y ) {
                      var aPos = {$jsVar}.fnGetPosition( this );
                      {$jsVar}.fnUpdate( sValue, aPos[0], aPos[1] );
                },
                "submitdata": function ( value, settings ) {
            return {
                "row_id": this.parentNode.getAttribute('id'),
                "column": {$jsVar}.fnGetPosition( this )[2]
            };
        },
                "height": "14px",
                "width": "100%"
            } );
        },
table;
        }

        // Build client-side group-by JS (injected into the load handler below).
        $groupByInitJs = '';
        if ($this->groupByColumn !== null || $this->groupBySelector === true) {
            $initCol = $this->groupByColumn !== null ? (int) $this->groupByColumn : -1;
            $nCols   = count($this->aoColumns);
            $tName   = $this->name;   // original name for selectors (#id)
            $tVar    = $jsVar;        // sanitized name for JS variables/functions
            $groupByInitJs = "
    var pf40_gc_{$tVar} = {$initCol};
    function pf40_doGroup_{$tVar}() {
        var col = pf40_gc_{$tVar};
        var tbody = jQuery('#{$tName} tbody');
        tbody.find('tr.pramnos-group-row').remove();
        if (col < 0) return;
        var last = null;
        tbody.find('tr').each(function() {
            var cells = jQuery(this).find('td');
            if (!cells.length) return;
            var v = cells.eq(col).text();
            if (v !== last) {
                jQuery(this).before('<tr class=\"pramnos-group-row\" style=\"background:#f5f7fa;font-weight:bold;\"><td colspan=\"{$nCols}\" style=\"padding:6px 8px;\">' + v + '</td></tr>');
                last = v;
            }
        });
    }
    jQuery('#{$tName}').on('draw.dt', pf40_doGroup_{$tVar});
    pf40_doGroup_{$tVar}();";
        }

        // Build show/hide column handlers — must live inside the load callback so
        // the DataTable variable ($tableVar) is in scope.  e.preventDefault()
        // avoids CSP violations from href="#" navigation.
        $showHideJs = '';
        if ($this->showHide == true) {
            $n = 0;
            foreach ($this->aoColumns as $column) {
                if ($column->showHide == true && trim($column->label) != '') {
                    $btnId = 'psh_' . $this->name . '_' . $n;
                    $showHideJs .= "\ndocument.getElementById('" . $btnId . "') && "
                        . "document.getElementById('" . $btnId . "').addEventListener('click', function(e){"
                        . "e.preventDefault();"
                        . "var bVis=" . $jsVar . ".fnSettings().aoColumns[" . $n . "].bVisible;"
                        . $jsVar . ".fnSetColumnVis(" . $n . ",!bVis);"
                        . "});";
                }
                $n++;
            }
        }
        $groupBySelectorJs = '';
        if ($this->groupBySelector === true) {
            $sId = 'pf40_groupby_' . $this->name;
            $groupBySelectorJs = "\ndocument.getElementById('" . $sId . "') && "
                . "document.getElementById('" . $sId . "').addEventListener('change', function() {"
                . "pf40_gc_" . $jsVar . " = parseInt(this.value);"
                . $jsVar . ".fnDraw();"
                . "});";
        }

        $tableId   = $this->name;
        $tableVar  = $jsVar;
        [$controlScript, $controlOptions] = $this->controlScript();
        // Cast at the boundary: this lands inside a <script>, so a non-numeric
        // value would be markup rather than a number.
        $searchDelay = max(0, (int) $this->searchDelay);
        $return = <<<table
   <script>

            function DataTableDelay(fn) {
                var ms = {$searchDelay};
                let timer = 0;
                return function(...args) {
                  clearTimeout(timer);
                  timer = setTimeout(fn.bind(this, ...args), ms || 0);
                };
              }

    window.addEventListener("load", function () {

    {$controlScript}

            var {$tableVar} = jQuery('#{$tableId}').dataTable( {
            $language
            $controlOptions
            $jui
            $fnDrawCallback
            "scrollX": true,
            "bSort": $bSort,
            "bProcessing": true,
            $bAutoWidth
            "aLengthMenu": $this->aLengthMenu,
            $ajaxsource
            "iDisplayLength": $this->iDisplayLength,
            "searchDelay": {$searchDelay},
            "stateSave": $this->stateSave,
             $ss
            "sPaginationType": "$this->sPaginationType",
             $aoColumns
             $tabletools
             $search
        });

        $sf
        $this->codeEmbed;
        $groupByInitJs
        $showHideJs
        $groupBySelectorJs

    });
   </script>
table;
        return $return;
    }

    /**
     * Renders all table (html & javascript)
     * @return type
     */
    public function render()
    {
        return $this->renderTable() . $this->renderJs();
    }

    /**
     * Render a datatable based on an existing html table
     * @param  string $tableid Original table id
     * @return string
     */
    public function renderExistingTable($tableid)
    {
        $document = \Pramnos\Framework\Factory::getDocument();
        $lang = \Pramnos\Framework\Factory::getLanguage();
        if ($this->aLengthMenu === NULL) {
            $this->aLengthMenu = '[[10, 25, 50, 100, -1], [10, 25, 50, 100, "'
                . $lang->_('All') . '"]]';
        }
        if ($this->addjs == true) {
            $document->enqueueScript('datatables');
            if ($this->tableTools == true) {
                $document->enqueueScript('tabletools');
                $document->enqueueScript('zeroclipboard');
            }
        }
        if ($this->addcss == true) {
            if ($this->jui == true) {
                $document->enqueueStyle('datatables-ui');
                if ($this->addjQueryUICss == true) {
                    $document->enqueueStyle('jquery-ui');
                }
            } else {
                $document->enqueueStyle('datatables');
            }
            if ($this->tableTools == true) {
                if ($this->jui == true) {
                    $document->enqueueStyle('tabletools-ui');
                } else {
                    $document->enqueueStyle('tabletools');
                }
            }
        }
        if ($this->bStateSave === true) {
            $this->bStateSave = "true";
        }
        elseif ($this->bStateSave === false) {
            $this->bStateSave = 'false';
        }

        $content = '<script>'
            . 'window.addEventListener("load", function () { '
            . 'jQuery(\'#'.$tableid.'\').dataTable({';
        if ($this->jui == true) {
            $content .= '"bJQueryUI": true,';
        }
        if ($this->tableTools == true) {

            if ($this->jui == true) {
                $content .= '"dom": \'<"clear"><"H"lfTr>t<"F"ip>\',' . "\n";
            } else {
                $content .= '"dom": \'T<"clear">lfrtip\',' . "\n";
            }
        }
        $content .= '"aLengthMenu": '.$this->aLengthMenu.', ' . "\n"
            . '"iDisplayLength": '.$this->iDisplayLength.', ' . "\n"
            . '"bStateSave": "'.$this->bStateSave.'", ' . "\n"
            . '"sPaginationType": "'.$this->sPaginationType.'"';
        $content .= '});} );'
            . '</script>';
        return $content;
    }

}
