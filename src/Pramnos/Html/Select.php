<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * A `<select>` that stands on its own, with no form around it.
 *
 * ```php
 * $status = new \Pramnos\Html\Select('statusSelect', $current);
 * $status->addOption('All', '');
 * $status->addOption('Active', 1);
 * echo $status->render();
 * ```
 *
 * ## Why this is not `Html\Form\Field`
 *
 * `Field` renders a `<select>` too, and it is the right thing for a field **in a form**:
 * it carries a label, a title, validation state, and its `render()` requires a `$styles`
 * preset because a form decides how its fields look.
 *
 * The case this class exists for has no form. The commonest one is a footer filter in a
 * {@see Datatable}, where the rendered string is handed to `addColumn()` as markup:
 *
 * ```php
 * $table->addColumn('Status', true, false, true, '', $status->render(), …);
 * ```
 *
 * Requested with a count: 37 files in a consuming application use a standalone select,
 * 35 of them through `addOption()`. Asking each of those to construct a form preset in
 * order to render one dropdown is the kind of coupling that gets a class copied instead
 * of used.
 *
 * ## Two things the class it replaces did not do
 *
 * **It escapes.** The legacy implementation concatenated option labels and values into
 * markup untouched, and its documented use is a filter populated from a database column.
 * Every value and every label goes through `htmlspecialchars()` here.
 *
 * **It compares as strings.** The legacy matched the current value with `==`, whose
 * answer for `0 == ''` changed between PHP 7 and 8 — so a select with an "All" option of
 * `''` and a current value of `0` selected different things depending on the interpreter.
 * `(string) $a === (string) $b` is the rule, the same one `Field` uses.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class Select extends \Pramnos\Framework\Base
{
    /** The `name` attribute. `[]` is appended when {@see $multiple} is on. */
    public string $name = '';

    /**
     * The selected value, or values.
     *
     * An array selects every option it contains, which is what `multiple` is for.
     *
     * @var string|int|float|array<int|string, mixed>|null
     */
    public $value = null;

    /**
     * The `id` attribute, or null to emit none.
     *
     * Null rather than falling back to the name: two selects for the same field on one
     * page is ordinary — a filter above a table and another below it — and duplicate ids
     * are invalid HTML that breaks `<label for>` and `getElementById` silently.
     */
    public ?string $id = null;

    /** Whether more than one option may be chosen. */
    public bool $multiple = false;

    /** Whether the browser should refuse an empty submission. */
    public bool $required = false;

    /**
     * The HTML `title` attribute — a tooltip.
     *
     * The counterpart of {@see Input::$title}, so a control in a form can explain itself
     * whichever kind it is. Escaped: it is text written for a person.
     */
    public string $title = '';

    /** Rendered verbatim inside the opening tag, for anything not modelled here. */
    public string $extraAttributes = '';

    /**
     * @var list<array{label: string, value: string, selected: bool}>
     */
    protected array $options = [];

    /**
     * @param string $name  The field name
     * @param mixed  $value The current value, or an array of them
     */
    public function __construct(string $name = '', $value = '')
    {
        parent::__construct();

        $this->name  = trim($name);
        $this->value = is_array($value) ? $value : trim((string) $value);
    }

    /**
     * Add one option.
     *
     * **The label comes first.** That is the legacy argument order, kept deliberately: it
     * is the order 35 files already pass, and reversing it to the more obvious
     * `(value, label)` would compile, run, and silently render every dropdown with its
     * labels and values swapped.
     *
     * `$selected` forces the option on regardless of the current value — for a list where
     * the selection is not a value comparison at all.
     *
     * @param  string $label    What the reader sees
     * @param  mixed  $value    The submitted value; null means the label is the value
     * @param  bool   $selected Select it whatever the current value is
     * @return $this
     */
    public function addOption(string $label, $value = null, bool $selected = false): static
    {
        $this->options[] = [
            'label'    => $label,
            'value'    => (string) ($value ?? $label),
            'selected' => $selected,
        ];

        return $this;
    }

    /**
     * Add several options at once, as value => label.
     *
     * The shape a query result or a config array already has, so the common loop does not
     * have to be written at every call site.
     *
     * @param  array<int|string, string> $options value => label
     * @return $this
     */
    public function addOptions(array $options): static
    {
        foreach ($options as $value => $label) {
            $this->addOption($label, $value);
        }

        return $this;
    }

    /** Whether any option has been added. */
    public function hasOptions(): bool
    {
        return $this->options !== [];
    }

    /**
     * The `<select>`, as markup.
     *
     * @return string
     */
    public function render(): string
    {
        $name = $this->multiple ? $this->name . '[]' : $this->name;

        $out = '<select name="' . $this->attr($name) . '"';

        if ($this->id !== null && $this->id !== '') {
            $out .= ' id="' . $this->attr($this->id) . '"';
        }
        if ($this->multiple) {
            $out .= ' multiple';
        }
        if ($this->required) {
            $out .= ' required';
        }
        if ($this->title !== '') {
            $out .= ' title="' . $this->attr($this->title) . '"';
        }
        if ($this->extraAttributes !== '') {
            $out .= ' ' . $this->extraAttributes;
        }

        $out .= '>';

        foreach ($this->options as $option) {
            $out .= '<option value="' . $this->attr($option['value']) . '"'
                . (($option['selected'] || $this->isCurrent($option['value'])) ? ' selected' : '')
                . '>' . $this->attr($option['label']) . '</option>';
        }

        return $out . '</select>';
    }

    /**
     * `echo $select;` renders it.
     *
     * The legacy class did this and call sites use it. Kept because the alternative is an
     * empty string or "Object of class … could not be converted", neither of which is
     * what somebody echoing a select meant.
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Whether this option's value is the one currently selected.
     *
     * Compared as strings, for the reason in the class docblock: `==` gave a different
     * answer for `0 == ''` on PHP 7 than on PHP 8, so the legacy behaviour depended on
     * the interpreter.
     *
     * An array current value selects every option it holds, which is what `multiple`
     * means. Compared with `in_array(..., true)` after casting, so `'1'` and `1` match
     * each other but `'a'` does not match `0` — which loose `in_array()` would have
     * claimed on PHP 7.
     */
    protected function isCurrent(string $value): bool
    {
        if (is_array($this->value)) {
            return in_array($value, array_map('strval', $this->value), true);
        }

        return $value === (string) $this->value;
    }

    /** Escape for an attribute or for text content — the same rule serves both. */
    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
