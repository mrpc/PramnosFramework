<?php

namespace Pramnos\Html;

/**
 * Date widget. It can use bootstrap datepicker.
 * @package     PramnosFramework
 * @subpackage  Html
 * @copyright   2005 - 2017 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Date extends Html
{
    /**
     * Date format
     * @var string
     */
    public $format = "d/m/Y";
    /**
     * Current date in unix timestamp
     * @var type
     */
    public $date = 0;
    /**
     * Field name
     * @var string
     */
    public $name = '';
    /**
     * Use javascript to validate the date
     * @var boolean
     */
    public $validate = true;
    /**
     * Automaticaly add all required css to the document
     * @var boolean
     */
    public $addcss = true;
    /**
     * Automaticaly add all required javascript to the document
     * @var type
     */
    public $addjs = true;
    /**
     * Minimum Year for validation
     * @var int
     */
    public $minyear = 1902;
    /**
     * Maximum year for validation
     * @var type
     */
    public $maxyear = 2037;

    public $array = false;
    /**
     * Is the field required?
     * @var bool
     */
    public $required = true;
    /**
     * Display the date
     * @var bool
     */
    public $showdate = NULL;
    public $arrayid = NULL;

    protected $_originalValue=0;

    /**
     * Convert object to string
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getDate();
    }


    /**
     * Return unix timestamp of any html5 date field
     * @param string $dateField
     * @return int
     */
    public static function getHtmlDate($dateField)
    {
        $date = date_create_from_format(
            'Y-m-d H:i:s', $dateField . ' 00:00:00'
        );
        return $date->getTimestamp();
    }

    /**
     * Retreive the date from a submited form
     * @param string $requestType Form method
     * @return int The date in unix timestamp
     */
    public function getDate($requestType = 'request')
    {
        $request = new \Pramnos\Http\Request();

        if ($this->array == true) {
            $date = $request->get($this->name . "_datepicker",
                date('d/m/Y', $this->date), $requestType);
            if (is_array($date)) {
                if (isset($date[$this->arrayid])) {
                    $date = $date[$this->arrayid];
                }
                else {
                    $date = date('d/m/Y', $this->date);
                }
            }
        }
        else {
            $date = $request->get(
                $this->name . "_datepicker",
                date('d/m/Y', $this->date), $requestType
            );
        }

        $date = explode("/", $date);

        if (isset($date[1])) {
            @$d = $date[0];
            @$m = $date[1];
            @$y = $date[2];

            if ($this->time == true) {
                $time = $request->get(
                    $this->name . "_timepicker",
                    date("H:i", $this->date), $requestType
                );
                $time = explode(":", $time);
                @$hour = $time[0];
                @$minute = $time[1];
                return strtotime(
                    $m . '/' . $d . '/' . $y
                    . ' ' . $hour . ":" . $minute . ":00"
                );
            } else {
                if ($d == "01" && $m == "01" && $y == "1970") {
                    return 2;
                }
                return strtotime($m . '/' . $d . '/' . $y);
            }
        }
        else {
            if ($this->onlyyear == true) {
                if (trim($date[0]) != "") {
                    return strtotime(
                        "01/01/" . $date[0] . " "
                        . $this->onlyyearhour . ":"
                        . $this->onlyyearminute . ":"
                        . $this->onlyyearsecond
                    );
                }
            } else {
                return 0;
            }
        }

    }


    /**
     * Html date field
     * @param string $name Field name
     * @param int $date Unix timestamp
     */
    public function __construct($name = '', $date = 0)
    {
        parent::__construct();
        $this->name = str_replace(" ", "", $name);
        $this->date = $date;
    }

    /**
     * Do the actual rendering of the widget
     * @return string
     */
    public function render()
    {
        $lang = new \Pramnos\Translator\Language();
        $this->_originalValue=$this->date;
        if ($this->required == true) {
            if ($this->date == 0) {
                $this->date = time();
            }
        }

        $value = date('d/m/Y', $this->date);

        if (($this->date == 0 || $this->date == time())
            && ($this->required == false || $this->showdate === false)) {
            $value = "";
        }
        if ($value != "" && $this->onlyyear == true) {
            if (date('H:i:s', $this->date) == $this->onlyyearhour
                . ":" . $this->onlyyearminute . ":" . $this->onlyyearsecond) {
                $value = date("Y", $this->date);
            }
        }


        $unique = "";

        if (strpos($this->name, '[]') !== FALSE) {
            $this->name = str_replace('[]', '', $this->name);
            $this->array = true;
        }
        if ($this->array == true) {
            $unique = '_' . uniqid();
        }

        if ($this->array == true) {
            if ($this->arrayid !== NULL) {
                $name = $this->name
                    . "_datepicker[" . $this->arrayid . "]";
            } else {
                $name = $this->name . "_datepicker[]";
            }
        } else {
            $name = $this->name . "_datepicker";
        }
        $document = \Pramnos\Document\Document::getInstance();
        if ($this->addjs == true) {
            $document->enqueueScript('bootstrap-datepicker');

        }

        if ($this->validate == true) {
            if ($this->addjs == true) {
                $document->enqueueScript('jquery-inputmask');
            }
        }

        $return = "";

        $return .= "
            <script>
            window.addEventListener(\"load\", function () {
            jQuery( \"#" . $this->name . $unique . "_datepicker\" ).datepicker({
                    autoclose: true,
                    dateFormat: 'dd/mm/yyyy'
            });\n";

        if ($this->validate) {
            $return .= 'jQuery("#'
                . $this->name
                . $unique
                . '_datepicker").inputmask("99/99/9999", {"placeholder": "'
                . $lang->_('DD/MM/YYYY')
                . '", alias: "dd/mm/yyyy"});';
        }

        $return .="\n});\n</script>";


        $return .= '<input type="text" maxlength="10" name="'
            . $name
            . '" id="'
            . $this->name
            . $unique . '_datepicker'
            . '" class="form-control '
            . $this->class
            . '" data-inputmask="\'alias\': \'dd/mm/yyyy\'" data-mask value="'
            . $value
            . '"';

        if ($this->tabindex != null) {
            $return .=" tabindex=\"".$this->tabindex."\" ";
        }
        $return .= " />";



        return $return;
    }
}
