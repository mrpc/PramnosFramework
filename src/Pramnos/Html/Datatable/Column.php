<?php
namespace Pramnos\Html\Datatable;

use Pramnos\Framework\Base;

/**
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */

class Column extends Base {

    public $bVisible=true;
    public $bSortable=true;
    public $bSearchable=true;
    public $sTitle="";
    public $sType="html";
    public $label="";
    public $footer="";
    public $align="left";
    public $showHide="true";
    public $footsearch=false;
    public $searchvalue="";
    public $js="";

    /**
     * Classes for the column's cells, heading included. `pf-no-export` keeps a column — row
     * actions — out of what Buttons copies, prints and saves, and out of a background export
     * (`Datatable::$backgroundExport`) the same way.
     * @var string
     */
    public $sClass = '';

    /**
     * The key a background export reads this column's value from, in each row
     * `Exportable::exportBatch()` returns. Defaults to this column's position among
     * `aoColumns` (visible or not, in declaration order) — the same positional index
     * `renderJs()` injects as `"data": N` for server-side mode, which is what a row from
     * `Datasource::getList()` is keyed by. Set explicitly for an `exportBatch()` that
     * returns assoc rows keyed by field name instead.
     * @var int|string|null
     */
    public $exportKey = null;

    /**
     * Characters before this column's footer search box sends a query; null for the table's
     * `minSearchLength`. An id column wants 1 — an id of 7 is a whole answer — while a phone
     * column keeps the table's, since every mobile number starts with the same two digits.
     *
     * @var int|null
     */
    public $minSearchLength = null;

    public $parent=NULL;


    public function __construct($label="", $bVisible = true,
            $bSortable = true, $bSearchable = true,
            $sType = '', $footer = "", $showHide = true, $align='left',
            $footsearch=false, $searchvalue="") {
        parent::__construct();
        if ($sType==""){
            $sType='html';
        }
        $this->label=$label;
        $this->bVisible=$bVisible;
        $this->bSortable=$bSortable;
        $this->bSearchable=$bSearchable;
        $this->sTitle=$label;
        $this->sType=$sType;
        $this->footer=$footer;
        $this->align=$align;
        $this->showHide=$showHide;
        $this->footsearch=$footsearch;
        $this->searchvalue=$searchvalue;
        $this->js=$this->getJs();
    }

    public function getJs(){
        return '{ "bVisible": '
            . \Pramnos\General\Helpers::bool2string($this->bVisible)
            . ', "bSortable": '
            . \Pramnos\General\Helpers::bool2string($this->bSortable)
            . ', "bSearchable": '
            . \Pramnos\General\Helpers::bool2string($this->bSearchable)
            . ', "sTitle": "' . $this->label
            . '", "sType": "' . $this->sType
            . '"' . ($this->sClass !== '' ? ', "sClass": ' . json_encode((string) $this->sClass, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : '')
            . '}';
    }

}