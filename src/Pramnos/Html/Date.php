<?php

namespace Pramnos\Html;

/**
 * Date widget. It can use bootstrap datepicker.
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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

    /**
     * Show a time field beside the date.
     *
     * Declared, and that is the point of this block. `Base::__set()` accepts any property, so
     * `$date->time = true` was stored, no warning was raised, and `render()` never read it —
     * every such form silently lost its time. `getDate()` *did* read `$this->time`, so the
     * reader half was there the whole time, waiting for a value nothing could set.
     * @var bool
     */
    public $time = false;
    /**
     * Put the time field on its own line
     * @var bool
     */
    public $timeChangeLine = true;
    /**
     * Render a datepicker (the default) or three dropdowns
     * @var bool
     */
    public $calendar = true;
    /**
     * Let the datepicker's year be changed directly
     *
     * Read by `render()`, which is worth saying because it was not: the option was written into
     * the datepicker as a hardcoded `changeYear: true` while this property sat here unread, so
     * setting it to `false` did nothing at all and looked like a datepicker that ignored its
     * configuration.
     * @var bool
     */
    public $changeyear = true;
    /**
     * Let the datepicker's month be changed directly
     * @var bool
     */
    public $changemonth = true;
    /**
     * Label each dropdown with D:, M:, Y:
     * @var bool
     */
    public $dropdownLabels = true;
    /**
     * Year as a dropdown too, rather than a number field
     * @var bool
     */
    public $dropdownYear = false;
    /**
     * Start the dropdowns on an empty option, so "unset" is distinguishable
     * from "today" — a birth date nobody has chosen must not read as today's.
     * @var bool
     */
    public $dropdownRequireSelect = false;
    /**
     * Accept a bare year, and treat it as one
     * @var bool
     */
    public $onlyyear = false;
    /**
     * The time of day a bare year is stored at. Not midnight on purpose: it is the marker
     * that says "this value is a year", which `render()` reads back to decide whether to
     * show four digits or a full date.
     * @var string
     */
    public $onlyyearhour = '01';
    /** @var string */
    public $onlyyearminute = '11';
    /** @var string */
    public $onlyyearsecond = '33';

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

        if ($this->calendar == false) {
            return $this->getDropdownDate($request, $requestType);
        }

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
     * The date as three posted dropdowns, when `$calendar` is false.
     *
     * A separate reader rather than a branch inside `getDate()`, because the two wire formats
     * have nothing in common: the datepicker posts one `dd/mm/yyyy` string, this posts three
     * numbers under three names.
     *
     * @param  \Pramnos\Http\Request $request
     * @param  string $requestType Form method
     * @return int Unix timestamp, or 0 when nothing usable was posted
     */
    protected function getDropdownDate($request, $requestType = 'request')
    {
        $day   = (int) $request->get($this->name . 'day', 0, $requestType);
        $month = (int) $request->get($this->name . 'month', 0, $requestType);
        $year  = (int) $request->get($this->name . 'year', 0, $requestType);

        if ($day < 1 || $month < 1 || $year < 1) {
            // An empty option was left selected. Zero rather than a guess: with
            // `dropdownRequireSelect` on, "not chosen" is a state the caller asked to be able
            // to see, and inventing today's date would hide exactly what it was for.
            return 0;
        }

        if ($this->time == true) {
            $posted = explode(':', (string) $request->get(
                $this->name . '_timepicker', date('H:i', $this->date), $requestType
            ));

            return (int) mktime(
                (int) ($posted[0] ?? 0),
                (int) ($posted[1] ?? 0),
                0,
                $month,
                $day,
                $year
            );
        }

        return (int) mktime(0, 0, 0, $month, $day, $year);
    }

    /**
     * Three `<select>` boxes instead of a datepicker.
     *
     * Not decoration: a birth date is the case this exists for. A datepicker asking somebody
     * to page back forty years is worse than picking a year from a list, and
     * `dropdownRequireSelect` is what keeps an unanswered field from reading as today.
     *
     * @return string
     */
    protected function renderDropdowns(\Pramnos\Translator\Language $lang)
    {
        $required = $this->required == true ? ' required' : '';
        $blank    = ($this->dropdownRequireSelect && $this->_originalValue == 0)
            ? '<option selected value=""></option>'
            : '';

        /*
         * "Selected" has to mean chosen.
         *
         * Under `dropdownRequireSelect` nothing is pre-selected unless there was a real value
         * to begin with. Both of this class's stand-ins for "nothing was set" have to be
         * caught: `0`, and `time()`, which `render()` substitutes for it on a required field.
         * The original condition tested only the second, so an optional field with no value
         * came back with day 1 and month 1 pre-selected — read from a zero timestamp, which is
         * 1 January 1970, and offered as though the visitor had picked it.
         */
        $preselect = !$this->dropdownRequireSelect
            || ($this->_originalValue != 0 && $this->date != time());

        $box = function (string $suffix, int $from, int $to, string $format) use (
            $required, $blank, $preselect
        ): string {
            $name = htmlspecialchars($this->name . $suffix, ENT_QUOTES);
            $html = '<select' . $required . ' name="' . $name . '" id="' . $name . '">' . "\n"
                . $blank;

            for ($value = $from; $value <= $to; $value++) {
                $selected = ($preselect && $value == (int) date($format, $this->date))
                    ? ' selected'
                    : '';
                $html .= '<option value="' . $value . '"' . $selected . '>'
                    . $value . '</option>' . "\n";
            }

            return $html . '</select> ';
        };

        $return = ($this->dropdownLabels == true ? $lang->_('D') . ': ' : '')
            . $box('day', 1, 31, 'd')
            . ($this->dropdownLabels == true ? $lang->_('M') . ': ' : '')
            . $box('month', 1, 12, 'm')
            . ($this->dropdownLabels == true ? $lang->_('Y') . ': ' : '');

        if ($this->dropdownYear == true) {
            return $return . $box('year', (int) $this->minyear, (int) $this->maxyear, 'Y');
        }

        // A number field rather than a two-thousand-option list. The browser enforces the
        // bounds itself, and the four digits posted are the same either way.
        $name = htmlspecialchars($this->name . 'year', ENT_QUOTES);

        return $return . '<input type="number" name="' . $name . '" id="' . $name . '"'
            . ' value="' . ($this->_originalValue == 0 && $this->dropdownRequireSelect
                ? '' : date('Y', $this->date)) . '"'
            . ' size="4" maxlength="4" min="' . (int) $this->minyear . '"'
            . ' max="' . (int) $this->maxyear . '" inputmode="numeric"'
            . ($this->required == true ? ' required' : '') . ' />' . "\n";
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


        if ($this->calendar == false) {
            // Three dropdowns instead of a datepicker, plus the time field if asked for.
            return $this->renderDropdowns($lang) . $this->renderTime();
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

        /*
         * `changeYear` and `changeMonth` come from the properties, and the day is kept when the
         * month changes.
         *
         * The first was hardcoded `true` while `$changeyear` was declared and never read — a
         * property that could be set and did nothing. The second did not exist.
         *
         * `onChangeMonthYear` is not optional and not behind a flag. Without it the widget can
         * land on a date that does not exist: pick the 31st, change the month to February, and
         * the field says `31/02`. What happens next depends on the receiving end — a `strtotime`
         * rolls it into March, a database refuses it — and neither is what the visitor chose. The
         * day is clamped to the last one the new month has, which is the only answer that keeps
         * their intent.
         *
         * Written into the input's value rather than through `setDate()`, because `setDate()`
         * inside this handler re-enters it.
         */
        $changeYear  = $this->changeyear ? 'true' : 'false';
        $changeMonth = $this->changemonth ? 'true' : 'false';

        $return .= "
            <script>
            window.addEventListener(\"load\", function () {
            jQuery( \"#" . $this->name . $unique . "_datepicker\" ).datepicker({
                    autoclose: true,
                    changeYear: " . $changeYear . ",
                    changeMonth: " . $changeMonth . ",
                    yearRange: \"" . (int) $this->minyear . ":" . (int) $this->maxyear . "\",
                    dateFormat: 'dd/mm/yyyy',
                    format: 'dd/mm/yyyy',
                    onChangeMonthYear: function (year, month, inst) {
                        var last = new Date(year, month, 0).getDate();
                        var day = Math.min(inst && inst.selectedDay ? inst.selectedDay : 1, last);
                        jQuery(this).val(
                            (day < 10 ? '0' : '') + day + '/'
                            + (month < 10 ? '0' : '') + month + '/' + year
                        );
                    },
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


        /*
         * The browser validates this field, because nothing else in the page does.
         *
         * `type` stays `text` deliberately: a native `<input type="date">` submits `YYYY-MM-DD`
         * and every receiving end here parses `dd/mm/yyyy`, so switching it would silently
         * change the wire format of every form. While it is `text`, `pattern` is the *only*
         * validation a browser performs — without it the field accepts anything and the first
         * thing to notice is `strtotime()` on the server, which does not report back to the
         * person typing.
         *
         * The dropdown branch below has had `required`, `min`, `max` and `inputmode` all along.
         * This branch — the one 77 forms in one application take — had none of them: a required
         * date field submitted empty with the browser saying nothing.
         *
         * `title` goes with `pattern` and is not decoration. Without it the browser says «please
         * match the requested format» and never says what the format is.
         */
        $pattern = '(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[012])/(19|20)\d\d';

        if ($this->onlyyear == true) {
            // The same allowance the `onlyyear` reader makes: a bare year is a valid entry.
            $pattern .= '|(19|20)\d\d';
        }

        $title = $lang->_('Invalid Format') . ' (dd/mm/yyyy, '
            . (int) $this->minyear . '-' . (int) $this->maxyear . ')';

        $return .= '<input type="text" maxlength="10" name="'
            . $name
            . '" id="'
            . $this->name
            . $unique . '_datepicker'
            . '" class="form-control '
            . $this->class
            . '" data-inputmask="\'alias\': \'dd/mm/yyyy\'" data-mask value="'
            . $value
            . '" pattern="' . $pattern . '"'
            . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
            . ' inputmode="numeric"';

        if ($this->required == true) {
            $return .= ' required';
        }

        // `$tabindex` is declared on `Html`, this class's parent, rather than here — it is a
        // global HTML attribute and every component wants it. Read, not magic.
        if ($this->tabindex != null) {
            $return .=" tabindex=\"".$this->tabindex."\" ";
        }
        $return .= " />";

        return $return . $this->renderTime();
    }

    /**
     * The time field beside the date, when `$time` is on.
     *
     * Its own method because both branches of `render()` end with it, and because the widget
     * it delegates to owns the field name `getDate()` reads back. Nothing at all when `$time`
     * is false, which is the default.
     *
     * @return string
     */
    protected function renderTime()
    {
        if ($this->time != true) {
            return '';
        }

        $time = new Time($this->name, $this->date);
        $time->required = $this->required;
        $time->class = $this->class;

        return ($this->timeChangeLine == true ? "<br />\n" : '') . $time->render();
    }
}
