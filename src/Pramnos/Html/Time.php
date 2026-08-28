<?php

namespace Pramnos\Html;

/**
 * Time widget — a companion to {@see Date}, and what `Date::$time` renders.
 *
 * A native `<input type="time">`. The browser draws the clock, validates the value and
 * localises how it is displayed, and it submits `HH:MM` on every platform that matters — which
 * is exactly what `Date::getDate()` parses back. The widget this replaces was three select
 * boxes or a text field with a Spry validator attached: a JavaScript library that has not
 * shipped in years, whose stylesheet was the only thing keeping its four error messages
 * hidden.
 *
 * The field name is `{name}_timepicker`, unchanged, because `Date::getDate()` has always read
 * that name and forms already post it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Time extends Html
{
    /**
     * Time format used when rendering the current value
     * @var string
     */
    public $format = 'H:i';

    /**
     * Current time as a unix timestamp
     * @var int
     */
    public $date = 0;

    /**
     * Element name
     * @var string
     */
    public $name = '';

    /**
     * Is this field required in a form?
     * @var bool
     */
    public $required = false;

    /**
     * Smallest step the field accepts, in seconds. 60 hides the seconds box.
     * @var int
     */
    public $step = 60;

    /**
     * @param string     $name Element name
     * @param int|string $date Unix timestamp, or a time as `HH:MM`
     */
    public function __construct($name, $date = 0)
    {
        parent::__construct();
        $this->name = str_replace(' ', '', $name);

        if (!is_numeric($date)) {
            $parts = explode(':', (string) $date);
            $this->date = isset($parts[1])
                ? (int) mktime((int) $parts[0], (int) $parts[1])
                : 0;

            return;
        }

        $this->date = (int) $date;
    }

    /**
     * Render the widget
     * @return string
     */
    public function render()
    {
        $name = $this->name . '_timepicker';

        $return = '<input type="time" name="' . htmlspecialchars($name, ENT_QUOTES)
            . '" id="' . htmlspecialchars($name, ENT_QUOTES)
            . '" value="' . date($this->format, $this->date) . '"'
            . ' step="' . (int) $this->step . '"';

        if ($this->class != '') {
            $return .= ' class="' . htmlspecialchars((string) $this->class, ENT_QUOTES) . '"';
        }

        if ($this->tabindex != null) {
            $return .= ' tabindex="' . (int) $this->tabindex . '"';
        }

        if ($this->required == true) {
            $return .= ' required';
        }

        return $return . ' />';
    }
}
