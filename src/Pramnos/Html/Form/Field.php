<?php

declare(strict_types=1);

namespace Pramnos\Html\Form;

/**
 * One declared field on a settings form.
 *
 * ## What this is not
 *
 * It is not a general-purpose form-input abstraction. It renders the field types a *settings*
 * page needs, and the boundary is deliberate — see {@see SettingsForm} for why the framework
 * does not ship a general form builder.
 *
 * ## Escaping
 *
 * Everything is escaped, always: the value, the title, the description, and every option of a
 * select. That is worth stating because the legacy class this replaces did not, and the hole
 * was not subtle — `value="' . $this->value . '"` with a value that arrives from the request
 * on the previous submit, so a rejected form reflected whatever was typed straight back into
 * an attribute.
 *
 * A settings field is exactly where that matters: the values are administrator-supplied, they
 * are re-rendered after every save, and the page displaying them is an administration panel.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Field
{
    /**
     * Types that render as an `<input>` with that `type` attribute.
     *
     * @var array<string, string>
     */
    private const INPUT_TYPES = [
        'textfield' => 'text',
        'text'      => 'text',
        'number'    => 'number',
        'email'     => 'email',
        'url'       => 'url',
        'password'  => 'password',
        'date'      => 'date',
        'time'      => 'time',
        'datetime'  => 'datetime-local',
        'color'     => 'color',
        // The legacy type. It carried a path or URL, and nothing rendered a picker for it;
        // a text input is what it always was in practice.
        'image'     => 'text',
    ];

    /** @var string The field's name, as submitted */
    public string $name = '';

    /** @var string The label */
    public string $title = '';

    /** @var string One of the supported types */
    public string $type = 'textfield';

    /** @var array<int|string, mixed>|string Options for a select: array, or comma-separated */
    public $options = '';

    /** @var string|null Help text shown under the control */
    public ?string $description = null;

    /** @var bool Whether the browser should require a value */
    public bool $required = false;

    /** @var mixed The value used when none has been set */
    public $default = '';

    /** @var mixed The current value */
    public $value = null;

    /** @var float|int|null Minimum, for numeric fields */
    public $min = null;

    /** @var float|int|null Maximum, for numeric fields */
    public $max = null;

    /** @var float|int|null Step, for numeric fields */
    public $step = null;

    /**
     * @param string                        $name        Submitted name
     * @param string|null                   $title       Label; defaults to a humanised name
     * @param string                        $type        One of the supported types
     * @param array<int|string, mixed>|string|null $options Select options
     * @param string|null                   $description Help text
     * @param bool                          $required    Whether a value is required
     * @param mixed                         $default     Value used when none is set
     */
    public function __construct(
        string $name,
        ?string $title = null,
        string $type = 'textfield',
        $options = null,
        ?string $description = null,
        bool $required = false,
        $default = null
    ) {
        $this->name        = $name;
        $this->type        = strtolower($type);
        $this->title       = ($title === null || $title === '')
            ? ucwords(str_replace(['_', '-'], ' ', $name))
            : $title;
        $this->options     = $options ?? '';
        $this->description = $description;
        $this->required    = $required;
        $this->default     = $default ?? '';
    }

    /**
     * The value in effect — the current one, or the default.
     *
     * `null` rather than `''` is the test, so a setting deliberately saved as an empty string
     * stays empty instead of silently reverting to its default on every render.
     *
     * @return mixed
     */
    public function effectiveValue()
    {
        return $this->value === null ? $this->default : $this->value;
    }

    /**
     * Read this field's submitted value into {@see $value}.
     *
     * An absent checkbox means unchecked — browsers submit nothing for one — so it reads as
     * `'0'` rather than falling back to the default. Without that, a checkbox could be turned
     * on and never off.
     *
     * @param  string $method `post`, `get` or `request`
     * @return $this
     */
    public function readSubmitted(string $method = 'post'): self
    {
        $request = \Pramnos\Http\Request::getInstance();

        if ($this->type === 'checkbox') {
            $this->value = $request->get($this->name, '', $method) !== '' ? '1' : '0';

            return $this;
        }

        $this->value = $request->get($this->name, (string) $this->default, $method);

        return $this;
    }

    /**
     * The field as HTML.
     *
     * @param  array<string, string> $styles A preset from {@see FieldStyles::for()}
     * @return string
     */
    public function render(array $styles): string
    {
        if ($this->type === 'hidden') {
            return '<input type="hidden" name="' . $this->attr($this->name)
                . '" value="' . $this->attr((string) $this->effectiveValue()) . '" />';
        }

        $control = match ($this->type) {
            'select', 'selectbox' => $this->renderSelect($styles),
            'checkbox'            => $this->renderCheckbox($styles),
            'textarea'            => $this->renderTextarea($styles),
            default               => $this->renderInput($styles),
        };

        $out = '<div' . $styles['group'] . '>';

        // A checkbox labels itself, so it does not get a second label above it.
        if ($this->type !== 'checkbox') {
            $out .= '<label for="' . $this->attr($this->name) . '"' . $styles['label'] . '>'
                . $this->text($this->title)
                . ($this->required ? ' <span aria-hidden="true">*</span>' : '')
                . '</label>';
        }

        $out .= $control;

        if ($this->description !== null && $this->description !== '') {
            $out .= '<small' . $styles['help'] . '>' . $this->text($this->description)
                . '</small>';
        }

        return $out . '</div>';
    }

    /**
     * An `<input>`.
     *
     * @param  array<string, string> $styles The preset
     * @return string
     */
    private function renderInput(array $styles): string
    {
        $type = self::INPUT_TYPES[$this->type] ?? 'text';

        $out = '<input type="' . $type . '" id="' . $this->attr($this->name)
            . '" name="' . $this->attr($this->name)
            . '" value="' . $this->attr((string) $this->effectiveValue()) . '"'
            . $styles['input'];

        foreach (['min' => $this->min, 'max' => $this->max, 'step' => $this->step] as $k => $v) {
            if ($v !== null) {
                $out .= ' ' . $k . '="' . $this->attr((string) $v) . '"';
            }
        }

        return $out . ($this->required ? ' required' : '') . ' />';
    }

    /**
     * A `<textarea>`.
     *
     * @param  array<string, string> $styles The preset
     * @return string
     */
    private function renderTextarea(array $styles): string
    {
        return '<textarea id="' . $this->attr($this->name) . '" name="'
            . $this->attr($this->name) . '" rows="5"' . $styles['area']
            . ($this->required ? ' required' : '') . '>'
            . $this->text((string) $this->effectiveValue()) . '</textarea>';
    }

    /**
     * A checkbox, with a hidden companion so that unchecking is submitted.
     *
     * Without the hidden field an unchecked box submits nothing at all, which is
     * indistinguishable from the field not being on the form — so a setting could be switched
     * on and never off. The hidden input carries `0` and the checkbox overwrites it with `1`,
     * which relies on later values winning; that is how PHP parses a repeated name.
     *
     * @param  array<string, string> $styles The preset
     * @return string
     */
    private function renderCheckbox(array $styles): string
    {
        $checked = ((string) $this->effectiveValue() !== '0'
            && (string) $this->effectiveValue() !== '') ? ' checked' : '';

        return '<input type="hidden" name="' . $this->attr($this->name) . '" value="0" />'
            . '<label for="' . $this->attr($this->name) . '"' . $styles['label'] . '>'
            . '<input type="checkbox" id="' . $this->attr($this->name) . '" name="'
            . $this->attr($this->name) . '" value="1"' . $styles['check'] . $checked . ' /> '
            . $this->text($this->title) . '</label>';
    }

    /**
     * A `<select>`.
     *
     * Options are accepted in the three shapes the legacy API allowed, because both callers
     * of this class already pass all three: a comma-separated string, a flat list, and a map
     * of value => label.
     *
     * One case is genuinely undecidable and is worth knowing rather than discovering:
     * `[0 => 'No', 1 => 'Yes']` is indistinguishable from the list `['No', 'Yes']`, because
     * PHP represents them identically. It is read as a list. For a map whose keys are `0` and
     * `1` in that order, use the `[label, value]` pair form, which is unambiguous.
     *
     * @param  array<string, string> $styles The preset
     * @return string
     */
    private function renderSelect(array $styles): string
    {
        $out = '<select id="' . $this->attr($this->name) . '" name="'
            . $this->attr($this->name) . '"' . $styles['select']
            . ($this->required ? ' required' : '') . '>';

        $current = (string) $this->effectiveValue();

        foreach ($this->optionPairs() as $value => $label) {
            $out .= '<option value="' . $this->attr((string) $value) . '"'
                . ((string) $value === $current ? ' selected' : '') . '>'
                . $this->text((string) $label) . '</option>';
        }

        return $out . '</select>';
    }

    /**
     * The options as value => label, whichever shape they were declared in.
     *
     * @return array<int|string, mixed>
     */
    public function optionPairs(): array
    {
        if (is_array($this->options)) {
            // `array_is_list()`, not `is_int($key)`. PHP coerces a numeric string key to an
            // integer, so `['1' => 'Enabled', '0' => 'Disabled']` — the obvious way to declare
            // a boolean setting — arrives with integer keys and was read as a flat list,
            // rendering `value="Enabled"`. Caught by its own test.
            $isList = array_is_list($this->options);

            $pairs = [];
            foreach ($this->options as $key => $option) {
                if (is_array($option)) {
                    // The legacy [label, value] pair.
                    $pairs[$option[1] ?? $option[0]] = $option[0];
                    continue;
                }
                $pairs[$isList ? $option : $key] = $option;
            }

            return $pairs;
        }

        $pairs = [];
        foreach (explode(',', (string) $this->options) as $option) {
            $option = trim($option);
            if ($option !== '') {
                $pairs[$option] = $option;
            }
        }

        return $pairs;
    }

    /**
     * Escape for an attribute value.
     *
     * @param  string $value The raw value
     * @return string
     */
    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for text content.
     *
     * @param  string $value The raw value
     * @return string
     */
    private function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
