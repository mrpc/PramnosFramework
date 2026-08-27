<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * One form control that stands on its own, with no form around it.
 *
 * ```php
 * $search = new \Pramnos\Html\Input('q', $current);
 * $search->placeholder = 'Search…';
 * echo $search->render();
 * ```
 *
 * The companion to {@see Select}, and there for the same reason: {@see Form\Field} is the
 * right thing for a control **in a form** — it carries a label, a title, validation state,
 * and its `render()` requires the form's style preset — and none of that applies to a
 * filter above a table or a search box in a header.
 *
 * ## One class, not five
 *
 * This replaces four legacy classes — `input`, `checkbox`, `colorpicker` and the `time`
 * widget — and covers more than they did.
 *
 * The legacy `input` was a **dispatcher**: `type` decided, and for `date`, `time`,
 * `checkbox` and `color` it constructed a different class and forwarded a dozen properties
 * to it. Most of that existed because those input types did not work in browsers when it
 * was written, so each one needed JavaScript. `<input type="color">` and `type="time"`
 * have been native for years. What is left, once the widgets are not needed, is a tag with
 * attributes — which is one class.
 *
 * `textarea` is accepted as a type even though it is not an `<input>`. It is the same job
 * from a caller's point of view, and {@see Form\Field} already treats it that way.
 *
 * ## What it does not do
 *
 * No `validate` / `addcss` / `addjs`. The legacy properties of those names made an element
 * push CSS and JavaScript into the document while rendering itself, which means echoing a
 * search box changed the page's asset list. `Document::addScript()` and `addStyle()` are
 * how a page declares what it needs, and a control is not the place to decide it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class Input extends \Pramnos\Framework\Base
{
    /**
     * Types rendered as `<input type="…">`.
     *
     * The native set, so a `date` or a `color` is the browser's own control rather than a
     * script. An unrecognised type falls back to `text`, because a typo should produce a
     * usable field rather than `<input type="txet">`, which browsers treat as text anyway
     * while validators complain.
     *
     * @var list<string>
     */
    public const TYPES = [
        'text', 'hidden', 'password', 'email', 'url', 'tel', 'search', 'number',
        'range', 'date', 'time', 'datetime-local', 'month', 'week', 'color',
        'checkbox', 'radio', 'file',
    ];

    /** Types whose state is `checked` rather than `value`. */
    protected const CHECKABLE = ['checkbox', 'radio'];

    /** The `name` attribute. `[]` is appended when {@see $multiple} is on. */
    public string $name = '';

    /** The current value. For a checkbox or radio, what it is compared against. */
    public string $value = '';

    /** One of {@see TYPES}, or `textarea`. */
    public string $type = 'text';

    /** The `id`, or null to emit none. */
    public ?string $id = null;

    /**
     * A label rendered before the control, or '' for none.
     *
     * Escaped, and given a `for` only when {@see $id} is set — a `for` pointing at nothing
     * is worse than no label, because a screen reader announces the association and then
     * finds no control.
     */
    public string $label = '';

    /**
     * The value submitted when a checkbox or radio is checked.
     *
     * `'1'`, matching the legacy default. The control is checked when {@see $value}
     * equals this — so a checkbox is `new Input('active', $row['active'])` with nothing
     * else to configure.
     */
    public string $checkedValue = '1';

    public bool $required = false;
    public bool $readonly = false;
    public bool $disabled = false;

    /** Appends `[]` to the name, for a control that submits several values. */
    public bool $multiple = false;

    public string $placeholder = '';

    /** @var string|int|float|null */
    public $min = null;

    /** @var string|int|float|null */
    public $max = null;

    /** @var string|int|float|null */
    public $step = null;

    public ?int $maxlength = null;

    /**
     * A lower bound on the length, the sibling of {@see $maxlength}.
     *
     * Both halves of the same HTML constraint, so a field that can declare a ceiling can
     * declare a floor. `minlength` is not enforced on a field the user never touched —
     * the browser applies it on input, not on an untouched empty field — so pair it with
     * {@see $required} when the field must be filled at all.
     */
    public ?int $minlength = null;

    public ?int $size = null;

    /** A `pattern` for client-side validation. */
    public string $pattern = '';

    /**
     * The tooltip, and the message a failed `pattern` shows.
     *
     * Worth setting whenever {@see $pattern} is: without it the browser reports "Please
     * match the requested format", which tells the user that they are wrong and not what
     * right would look like. It is text written for a person, so it is escaped — which is
     * the reason it is a property here rather than something to push through
     * {@see $extraAttributes}.
     */
    public string $title = '';

    /**
     * The on-screen keyboard to offer — `numeric`, `tel`, `email`, `decimal`, `search`.
     *
     * Not validation. It is the mobile counterpart of {@see $pattern}: a field that only
     * accepts digits should not open a QWERTY keyboard, and `type="text"` with a numeric
     * pattern is exactly the combination that does.
     */
    public string $inputmode = '';

    public string $autocomplete = '';

    /** Rows for a textarea. Ignored by every other type. */
    public ?int $rows = null;

    /** Rendered verbatim inside the opening tag, for anything not modelled here. */
    public string $extraAttributes = '';

    /**
     * @param string $name  The field name
     * @param mixed  $value The current value
     */
    public function __construct(string $name = '', $value = '')
    {
        parent::__construct();

        $this->name  = trim($name);
        $this->value = trim((string) $value);
    }

    /**
     * The control, as markup.
     */
    public function render(): string
    {
        $type = $this->effectiveType();

        $out = $this->labelMarkup();

        return $out . ($type === 'textarea'
            ? $this->renderTextarea()
            : $this->renderInput($type));
    }

    /** `echo $input;` renders it. */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * The type actually rendered.
     *
     * `textfield` is accepted as a spelling of `text`: the legacy classes used it and
     * call sites pass it. An unrecognised type becomes `text` rather than being emitted
     * as-is — a browser treats an unknown type as text anyway, so passing the typo
     * through only adds an invalid attribute to the page.
     */
    protected function effectiveType(): string
    {
        $type = strtolower(trim($this->type));

        if ($type === 'textfield' || $type === '') {
            return 'text';
        }
        if ($type === 'textarea') {
            return 'textarea';
        }
        if ($type === 'colorpicker') {
            return 'color';
        }

        return in_array($type, self::TYPES, true) ? $type : 'text';
    }

    /**
     * `<input …>`.
     */
    protected function renderInput(string $type): string
    {
        $out = '<input type="' . $this->attr($type) . '" name="' . $this->attr($this->fieldName()) . '"';

        $out .= $this->idAttribute();

        if (in_array($type, self::CHECKABLE, true)) {
            // The submitted value is `checkedValue`; `value` is the current state it is
            // compared against. Getting this backwards renders a checkbox that submits
            // whatever the row already held, which looks right until somebody unticks it.
            $out .= ' value="' . $this->attr($this->checkedValue) . '"';
            if ($this->value === $this->checkedValue) {
                $out .= ' checked';
            }
        } elseif ($type !== 'file') {
            // A `value` on a file input is ignored by every browser and rejected by
            // validators; it is also the one type where a value could only have come from
            // the server, which is not something to echo back.
            $out .= ' value="' . $this->attr($this->value) . '"';
        }

        return $out . $this->sharedAttributes($type) . ' />';
    }

    /**
     * `<textarea>…</textarea>`.
     *
     * The value goes between the tags, not in an attribute — which is the reason this is
     * not just another type in the list above.
     */
    protected function renderTextarea(): string
    {
        $out = '<textarea name="' . $this->attr($this->fieldName()) . '"' . $this->idAttribute();

        if ($this->rows !== null) {
            $out .= ' rows="' . (int) $this->rows . '"';
        }
        if ($this->size !== null) {
            $out .= ' cols="' . (int) $this->size . '"';
        }

        return $out . $this->sharedAttributes('textarea') . '>'
            . $this->attr($this->value)
            . '</textarea>';
    }

    /**
     * The attributes every type may carry.
     *
     * Each is emitted only when set, so a text input does not arrive with `min=""` on it.
     * The numeric ones are skipped for types that have no use for them rather than being
     * left to the browser to ignore: `min` on a text field is invalid markup, and a
     * validator is the only thing that will ever tell you.
     */
    protected function sharedAttributes(string $type): string
    {
        $out = '';

        if ($this->placeholder !== '' && !in_array($type, ['checkbox', 'radio', 'file', 'color'], true)) {
            $out .= ' placeholder="' . $this->attr($this->placeholder) . '"';
        }

        $rangeable = in_array($type, ['number', 'range', 'date', 'time', 'datetime-local', 'month', 'week'], true);
        foreach (['min' => $this->min, 'max' => $this->max, 'step' => $this->step] as $name => $value) {
            if ($value !== null && $value !== '' && $rangeable) {
                $out .= ' ' . $name . '="' . $this->attr((string) $value) . '"';
            }
        }

        $textual = in_array($type, ['text', 'password', 'email', 'url', 'tel', 'search', 'textarea'], true);
        if ($this->maxlength !== null && $textual) {
            $out .= ' maxlength="' . (int) $this->maxlength . '"';
        }
        if ($this->minlength !== null && $textual) {
            $out .= ' minlength="' . (int) $this->minlength . '"';
        }
        if ($this->size !== null && $type !== 'textarea' && $textual) {
            $out .= ' size="' . (int) $this->size . '"';
        }
        if ($this->pattern !== '' && $textual) {
            $out .= ' pattern="' . $this->attr($this->pattern) . '"';
        }
        // Not restricted to textual types: `title` is a tooltip on any element, and it is
        // the only way a failed constraint of any kind explains itself.
        if ($this->title !== '') {
            $out .= ' title="' . $this->attr($this->title) . '"';
        }
        if ($this->inputmode !== '') {
            $out .= ' inputmode="' . $this->attr($this->inputmode) . '"';
        }
        if ($this->autocomplete !== '') {
            $out .= ' autocomplete="' . $this->attr($this->autocomplete) . '"';
        }

        if ($this->required) {
            $out .= ' required';
        }
        if ($this->readonly) {
            $out .= ' readonly';
        }
        if ($this->disabled) {
            $out .= ' disabled';
        }
        if ($this->multiple && $type === 'file') {
            $out .= ' multiple';
        }
        if ($this->extraAttributes !== '') {
            $out .= ' ' . $this->extraAttributes;
        }

        return $out;
    }

    /**
     * The submitted name.
     *
     * `[]` appended when several values are expected, because without it PHP keeps only
     * the last one and the control silently behaves like a single-value field. A name that
     * already ends in `[]` is left alone rather than becoming `x[][]`.
     */
    protected function fieldName(): string
    {
        if (!$this->multiple || str_ends_with($this->name, '[]')) {
            return $this->name;
        }

        return $this->name . '[]';
    }

    /** ` id="…"`, or nothing. */
    protected function idAttribute(): string
    {
        return $this->id === null || $this->id === ''
            ? ''
            : ' id="' . $this->attr($this->id) . '"';
    }

    /**
     * The label, or ''.
     *
     * No trailing colon. The legacy appended one unconditionally, which is a typographic
     * decision that belongs to whoever is writing the page — and one that cannot be undone
     * from outside the class.
     */
    protected function labelMarkup(): string
    {
        if ($this->label === '') {
            return '';
        }

        $for = $this->id === null || $this->id === ''
            ? ''
            : ' for="' . $this->attr($this->id) . '"';

        return '<label' . $for . '>' . $this->attr($this->label) . '</label>';
    }

    /** Escape for an attribute or for text content — the same rule serves both. */
    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
