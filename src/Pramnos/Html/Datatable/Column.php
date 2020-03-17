<?php
namespace Pramnos\Html\Datatable;

use Pramnos\Framework\Base;

/**
 * @copyright   2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
            . '"}';
    }

}