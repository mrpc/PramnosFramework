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
     * Use Bootstrap
     * @var boolean
     */
    public $bootstrap = true;

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
        parent::__construct();
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
        if ($this->bootstrap == true) {
            $this->tableClass .= ' table table-striped table-bordered '
                . 'table-hover ';
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
                $showHide = '<div class="btn-group" style="float: right; '
                    . 'font-size: x-small"><button type="button" '
                    . 'class="btn btn-default dropdown-toggle" '
                    . 'data-toggle="dropdown" aria-expanded="false">'
                    . $lang->_('Show')
                    . '<span class="caret"></span></button>'
                    . '<ul class="dropdown-menu" role="menu">';
            } else {
                $showHide = '<div style="float: right; font-size: x-small">'
                    . $lang->_('Show') . ": [ ";
            }
            $sep = "";
            foreach ($this->aoColumns as $column) {

                if ($column->showHide == true && trim($column->label) != "") {
                    $btnId = 'psh_' . $this->name . '_' . $n;
                    if ($this->jui == true) {
                        $showHide .= '<a href="#" id="' . $btnId . '">'
                            . '<span style="padding-left:3px; padding-right:3px;" '
                            . 'class="ui-button ui-state-default">' . $column->label
                            . '</span></a>';

                    } elseif ($this->bootstrap == true) {
                        $showHide .= '<li><a href="#" id="' . $btnId . '">'
                            . $column->label . '</a></li>';
                    } else {
                        $showHide .= $sep . ' <a href="#" id="' . $btnId . '">'
                            . $column->label . '</a> ';
                    }
                    $t+=1;
                    $sep = $this->separateChar;
                }
                $n+=1;
            }

            if ($this->jui != true && $this->bootstrap == false) {
                $showHide .= " ]";
            }
            if ($this->bootstrap == true) {
                $showHide .= '</ul>';
            }
            $showHide.="</div>";
            if ($t != 0) {
                $return .= $showHide;
            }
        }
        if ($this->groupBySelector === true) {
            $return .= $this->renderGroupBySelector($lang);
        }
        $return .= '<table id="' . $this->name . '" class="'
            . $this->tableClass . '" cellspacing="0" width="100%">
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

        return $return;
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
        foreach ($this->aoColumns as $key => $column) {
            if ($column->footsearch === true) {

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
                    $s = $column->searchvalue;
                    $this->codeEmbed .=<<<embed
            {$jsVar}.fnFilter( '$s', $c );
embed;
                }
            } elseif ($column->footsearch !== false) {
                $id = $column->footsearch;
                if ($column->searchvalue != "") {
                    $s = $column->searchvalue;
                    $this->codeEmbed .=<<<embed
            {$jsVar}.fnFilter( '$s', $c );
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
            $this->codeEmbed.=<<<embed
   $("tfoot input").keyup( DataTableDelay( function () {
        /* Filter on the column (the index) of this element */

        /*
         * Below the minimum, and not empty: too little to be worth a query on
         * every table this filters. Empty always passes — clearing the box has to
         * clear the filter.
         */
        if (this.value.length > 0 && this.value.length < {$minSearchLength}) {
            return;
        }

        {$jsVar}.fnFilter( this.value, $(this).attr('name') );
    } ) );
    /*
     * Support functions to provide a little bit of
     * 'user friendlyness' to the textboxes in
     * the footer
     */

    $("tfoot input").focus( function () {
        if ( this.className == "search_init" )
        {
            this.className = "";
            this.value = "";
        }
    } );

    $("tfoot input").blur( function (i) {
        if ( this.value == "" )
        {
            this.className = "search_init";
            this.value = asInitVals[$("tfoot input").index(this)];
        }
    } );
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
        $language = "";
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
            $tabletools = " buttons : [   'copy', 'excel', 'pdf', 'csv', 'print' ],";
            if ($this->jui == true) {
                $tabletools .= '"sDom": \'<"clear"><"H"lfBr>t<"F"ip>\',';
            } else {
                $tabletools .= " dom: '<\"right\">B<\"clear\">lfrtip', ";
            }
        }
        $sf = "";
        $search = "";
        if ($this->search != "") {
            $search = '"oSearch": {"sSearch": "' . trim($this->search) . '"},';
        }
        $ss = "";
        if ($this->stateSave == 'true') {
            $ss = <<<ss
            "fnInitComplete": function() {

                var oSettings = this.fnSettings();
                for ( var i=0 ; i<oSettings.aoPreSearchCols.length ; i++ ){
                    if(oSettings.aoPreSearchCols[i].sSearch.length>0){
                        $("#autofootsearch_"+i).val(oSettings.aoPreSearchCols[i].sSearch);
                        $("#autofootsearch_"+i).className = "";

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
                . '"ajax": {"url": "' . $this->source . '", "type": "POST",'
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


            var {$tableVar} = jQuery('#{$tableId}').dataTable( {
            $language
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
