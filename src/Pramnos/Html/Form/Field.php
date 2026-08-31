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

    /**
     * The visible label.
     *
     * Named `$title` until 2026-08-27, which collided with the HTML `title` attribute and
     * meant this class could not offer one under its own name. `$title` now means what it
     * means everywhere else; this is the label.
     */
    public string $label = '';

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

    /**
     * The validation message for this field, when it has one.
     *
     * Set it and the field renders as invalid *and says why, to everybody*: `aria-invalid` marks
     * it, and the message is joined to the input by `aria-describedby` so a screen reader reads
     * it as part of the field rather than as loose text somewhere on the page.
     *
     * Validation itself stays where it is — `Validation\FormRequest` and `View::$errors` own
     * that. This is only the rendering end, which is the end that was missing.
     *
     * @var ?string
     */
    public ?string $error = null;

    /**
     * The ids this field's control points at, worked out while rendering.
     *
     * @var list<string>
     */
    private array $describedBy = [];

    /** @var mixed The current value */
    public $value = null;

    /** @var float|int|null Minimum, for numeric fields */
    public $min = null;

    /** @var float|int|null Maximum, for numeric fields */
    public $max = null;

    /** @var float|int|null Step, for numeric fields */
    public $step = null;

    /**
     * A `pattern` for native validation.
     *
     * Pair it with {@see $title}: without one the browser reports "Please match the
     * requested format", which tells the reader they are wrong and not what right looks
     * like.
     */
    public string $pattern = '';

    /**
     * The HTML `title` attribute — a tooltip, and the message a failed constraint shows.
     *
     * The same property name as {@see \Pramnos\Html\Input::$title} and
     * {@see \Pramnos\Html\Select::$title}, which is the point: a form field explains
     * itself the same way a standalone control does. It is also how `min`/`max` on a
     * number explains itself, which nothing else does.
     */
    public string $title = '';

    /** Lower bound on the length. Textual types only, like {@see $maxlength}. */
    public ?int $minlength = null;

    /** Upper bound on the length. */
    public ?int $maxlength = null;

    /** Placeholder text. Not a label — a field still needs {@see $title}. */
    public string $placeholder = '';

    /** An `autocomplete` token, e.g. `email`, `new-password`, `off`. */
    public string $autocomplete = '';

    /** The on-screen keyboard to offer — `numeric`, `tel`, `decimal`, … */
    public string $inputmode = '';

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
        ?string $label = null,
        string $type = 'textfield',
        $options = null,
        ?string $description = null,
        bool $required = false,
        $default = null
    ) {
        $this->name        = $name;
        $this->type        = strtolower($type);
        $this->label       = ($label === null || $label === '')
            ? ucwords(str_replace(['_', '-'], ' ', $name))
            : $label;
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
            // No wrapper, no label, no id: a hidden field has nothing to show and nothing
            // to point a label at.
            $hidden = new \Pramnos\Html\Input($this->name, (string) $this->effectiveValue());
            $hidden->type = 'hidden';

            return $hidden->render();
        }

        /*
         * The ids that join a field to the text about it.
         *
         * A description rendered next to an input is not attached to it: it is a `<small>` that
         * happens to sit nearby, which is a relationship only a sighted reader can see. Somebody
         * using a screen reader hears the label and the control and never the sentence that
         * explains what to type.
         *
         * `aria-describedby` is that relationship, and it needs an id on both sides. The input
         * has had one all along.
         */
        $hasDescription = $this->description !== null && $this->description !== '';
        $hasError       = $this->error !== null && $this->error !== '';

        $this->describedBy = [];

        if ($hasError) {
            // First, because it is read first: a field that is wrong should say so before it
            // explains what it wanted.
            $this->describedBy[] = $this->name . '-error';
        }

        if ($hasDescription) {
            $this->describedBy[] = $this->name . '-description';
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
                . $this->text($this->label)
                . ($this->required ? ' <span aria-hidden="true">*</span>' : '')
                . '</label>';
        }

        $out .= $control;

        if ($hasError) {
            /*
             * `role="alert"` on the message, not on the field.
             *
             * A message that appears after a failed submit has to be announced when it appears,
             * and an alert is the one role that interrupts to do it. Putting it on the field
             * would re-announce the entire field every time anything about it changed.
             */
            $out .= '<small id="' . $this->attr($this->name) . '-error" role="alert"'
                . $styles['help'] . '>' . $this->text((string) $this->error) . '</small>';
        }

        if ($hasDescription) {
            $out .= '<small id="' . $this->attr($this->name) . '-description"' . $styles['help']
                . '>' . $this->text($this->description) . '</small>';
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
        return $this->control(
            self::INPUT_TYPES[$this->type] ?? 'text',
            $styles['input']
        )->render();
    }

    /**
     * An {@see \Pramnos\Html\Input} configured from this field.
     *
     * The tag itself is built there rather than here, so the rules about *which*
     * attributes a type may carry live in one place. They were duplicated, and the copies
     * had already diverged: this class emitted `min`/`max`/`step` on every type, including
     * `text`, which is invalid markup that every browser accepts and every validator
     * rejects — the kind of wrong nobody notices.
     *
     * What stays here is everything that is about being *in a form*: the label, the
     * description, {@see effectiveValue()}, the style preset, and an `id` defaulted to the
     * name. `Input` refuses to invent an id, correctly — two controls for one field on a
     * page is ordinary — but a form field is the case where one is required, because
     * `<label for>` is an association by id.
     *
     * The **type is resolved before it is handed over**. This class's vocabulary is its
     * own (`textfield`, `datetime`, `image`), and `Input` would read `datetime` as
     * unrecognised and fall back to `text` — silently turning a date-time picker into a
     * text box.
     *
     * @param string $type       An HTML input type, already resolved
     * @param string $styleAttrs The preset's attribute string, leading space included
     */
    /**
     * `aria-describedby` and `aria-invalid` for whichever of the two texts this field has.
     *
     * Nothing when it has neither, which is most fields — an `aria-describedby` pointing at an
     * id that is not on the page is worse than no attribute at all: a screen reader announces
     * the field and then nothing, and the omission looks like a bug in the reader.
     */
    private function associationAttributes(): string
    {
        $attributes = [];

        if ($this->describedBy !== []) {
            $attributes[] = 'aria-describedby="'
                . $this->attr(implode(' ', $this->describedBy)) . '"';
        }

        if ($this->error !== null && $this->error !== '') {
            $attributes[] = 'aria-invalid="true"';
        }

        return implode(' ', $attributes);
    }

    private function control(string $type, string $styleAttrs): \Pramnos\Html\Input
    {
        $input = new \Pramnos\Html\Input($this->name, (string) $this->effectiveValue());

        $input->type = $type;
        // The id a form field needs, and the reason this is set rather than left alone.
        $input->id       = $this->name;
        $input->required = $this->required;

        $input->min  = $this->min;
        $input->max  = $this->max;
        $input->step = $this->step;

        $input->pattern      = $this->pattern;
        $input->title        = $this->title;
        $input->minlength    = $this->minlength;
        $input->maxlength    = $this->maxlength;
        $input->placeholder  = $this->placeholder;
        $input->autocomplete = $this->autocomplete;
        $input->inputmode    = $this->inputmode;

        /*
         * The preset is framework-authored markup, not caller input, which is why it goes through
         * the one property that is not escaped. The association attributes go with it, in front,
         * so a preset can never displace them.
         */
        $input->extraAttributes = trim($this->associationAttributes() . ' ' . $styleAttrs);

        return $input;
    }

    /**
     * A `<textarea>`.
     *
     * @param  array<string, string> $styles The preset
     * @return string
     */
    private function renderTextarea(array $styles): string
    {
        $input = $this->control('textarea', $styles['area']);
        $input->rows = 5;

        return $input->render();
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
        $current = (string) $this->effectiveValue();
        $checked = $current !== '0' && $current !== '';

        $box = $this->control('checkbox', $styles['check']);
        // `Input` checks a box when its value equals `checkedValue`. This class's rule is
        // broader — anything that is not '0' or '' counts as on, because a setting stored
        // as 'yes', '1' or 'true' all mean the same thing here. So the comparison is made
        // here and the answer, not the raw value, is handed over.
        $box->value = $checked ? '1' : '0';

        return '<input type="hidden" name="' . $this->attr($this->name) . '" value="0" />'
            . '<label for="' . $this->attr($this->name) . '"' . $styles['label'] . '>'
            . $box->render() . ' '
            . $this->text($this->label) . '</label>';
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
        $select = new \Pramnos\Html\Select($this->name, (string) $this->effectiveValue());

        $select->id              = $this->name;
        $select->required        = $this->required;
        $select->title           = $this->title;
        // The same association the input path gets: a dropdown's description and error are
        // no more attached to it by proximity than a text field's are.
        $select->extraAttributes = trim($this->associationAttributes() . ' ' . $styles['select']);

        // `addOption($label, $value)` — the label is the first argument. Reversed, this
        // would render every dropdown with labels and values swapped and no error
        // anywhere, which is why the order is stated rather than assumed.
        foreach ($this->optionPairs() as $value => $label) {
            $select->addOption((string) $label, (string) $value);
        }

        return $select->render();
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
