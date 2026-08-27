<?php

declare(strict_types=1);

namespace Pramnos\Html\Form;

/**
 * A form described by the fields somebody declared, for settings pages.
 *
 * ## The narrow scope is the design, not a limitation
 *
 * This is **not** a general form builder, and the framework deliberately does not ship one.
 * The reasoning, since it decides what belongs here:
 *
 * - **Laravel removed its form builder from core** — it lives on as an unmaintained package —
 *   because markup in PHP objects fights every front-end toolchain. Symfony keeps a full Form
 *   component and it is the single most common complaint about its learning curve.
 * - This framework already generates CRUD forms, from real column introspection, with
 *   foreign keys becoming a `<select>` and three theme presets:
 *   {@see \Pramnos\Console\Commands\MakeCommandBase::buildWizardFormFields()}. A runtime
 *   builder would be a *third* way to render a field, after that and an SPA's own components.
 * - Validation, error surfacing and old input are already owned by
 *   {@see \Pramnos\Validation\FormRequest} and `View::$errors`. Reinventing them here would
 *   create two answers to "why was this rejected".
 *
 * What was genuinely missing is the shape Django's `Form`, Rails' model validations and
 * WordPress's Settings API all describe: **the caller declares fields, and the framework
 * renders, reads and persists them.** Two subsystems here had already declared exactly that
 * API — `Theme::addSetting()` and `Addon::addSetting()` — and both fatalled, because the
 * collaborator they called into was a legacy class that was never ported. Five public methods
 * in each, non-functional since the day the file was transferred.
 *
 * So: settings forms, declared as a schema. Not CRUD, not arbitrary markup.
 *
 * ## Usage
 *
 * ```php
 * $form = new SettingsForm('theme_settings');
 *
 * $form->addField('accent', 'Accent colour', 'color', default: '#3366ff')
 *      ->addField('per_page', 'Items per page', 'number', default: 20)
 *      ->addField('tagline', 'Tagline', 'textfield', multilanguage: true);
 *
 * $form->setValues($stored);
 * echo $form->render();            // the whole form, with a CSRF field
 *
 * if ($form->wasSubmitted()) {
 *     $data = $form->getData();    // [] when the CSRF token does not check out
 * }
 * ```
 *
 * ## What it does not decide
 *
 * Where the values are stored. `Theme` serialises them into a setting; an application may
 * want a row per key. Persistence belongs to whoever declared the fields.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class SettingsForm
{
    /** @var string Form name, used as the id and as the field-name prefix */
    public string $name;

    /** @var string The submit method */
    public string $method = 'post';

    /** @var string Where the form posts; the same page by default */
    public string $action = '';

    /** @var bool Whether field names are prefixed with the form name */
    public bool $addPrefix = true;

    /** @var bool Whether a CSRF token is emitted and required */
    public bool $checkToken = true;

    /** @var bool Whether a submit button is rendered */
    public bool $autoAddSubmit = true;

    /** @var string The submit button's label */
    public string $submitLabel = 'Save';

    /** @var string Which style preset to render with */
    public string $theme = 'plain';

    /** @var array<string, Field> Declared fields, keyed by their declared name */
    private array $fields = [];

    /**
     * Per-language copies of the fields declared as multilanguage.
     *
     * @var array<string, array<string, Field>>
     */
    private array $multilanguage = [];

    /**
     * @param string $name  Form name; also the id and the field-name prefix
     * @param string $theme `plain`, `bootstrap` or `tailwind`
     */
    public function __construct(string $name = 'settings', string $theme = 'plain')
    {
        $this->name  = $name;
        $this->theme = $theme;
    }

    /**
     * Declare a field.
     *
     * Named `addField()` after the legacy form class's own method, deliberately. The legacy
     * chain was `Theme::addSetting()` → `pramnos_html_form::addField()`, and the Theme Guide
     * documented the *inner* call as though it were the theme's own — which is how it came to
     * describe a method `Theme` has never had. Keeping the name here means anyone carrying that
     * knowledge lands on the right object rather than the wrong one.
     *
     * @param  string                               $name          Setting name
     * @param  string|null                          $label         Visible label. Named
     *         `$title` until 2026-08-27 — see {@see Field::$label} for why it changed.
     *         Positional callers are unaffected; a named argument `title:` now errors,
     *         which is the intended outcome: the alternative was assigning a label to
     *         what is now the HTML `title` attribute and rendering an empty one.
     * @param  string                               $type          Field type
     * @param  array<int|string, mixed>|string|null $options       Select options
     * @param  string|null                          $description   Help text
     * @param  bool                                 $required      Whether a value is required
     * @param  mixed                                $default       Default value
     * @param  mixed                                $value         Current value
     * @param  bool                                 $multilanguage Declare one per language
     * @return $this
     */
    public function addField(
        string $name,
        ?string $label = null,
        string $type = 'textfield',
        $options = null,
        ?string $description = null,
        bool $required = false,
        $default = null,
        $value = null,
        bool $multilanguage = false
    ): self {
        $field = new Field(
            $this->fieldName($name),
            $label,
            $type,
            $options,
            $description,
            $required,
            $default
        );

        if ($value !== null) {
            $field->value = $value;
        }

        $this->fields[$name] = $field;

        if ($multilanguage) {
            foreach ($this->languages() as $language) {
                $copy = new Field(
                    $this->fieldName($name) . '_' . $language,
                    $label,
                    $type,
                    $options,
                    $description,
                    $required,
                    $default
                );
                $this->multilanguage[$language][$name] = $copy;
            }
        }

        return $this;
    }

    /**
     * Whether any field has been declared.
     *
     * @return bool
     */
    public function hasFields(): bool
    {
        return $this->fields !== [];
    }

    /**
     * A declared field, or null.
     *
     * @param  string      $name     The declared name
     * @param  string|null $language A language, for a multilanguage copy
     * @return Field|null
     */
    public function field(string $name, ?string $language = null): ?Field
    {
        if ($language !== null) {
            return $this->multilanguage[$language][$name] ?? null;
        }

        return $this->fields[$name] ?? null;
    }

    /**
     * A field's value, or null when it was never declared.
     *
     * @param  string      $name     The declared name
     * @param  string|null $language A language, for a multilanguage copy
     * @return mixed
     */
    public function value(string $name, ?string $language = null)
    {
        $field = $this->field($name, $language);

        return $field === null ? null : $field->effectiveValue();
    }

    /**
     * Every declared field, keyed by declared name.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The per-language fields, keyed by language then declared name.
     *
     * @return array<string, array<string, Field>>
     */
    public function multilanguageFields(): array
    {
        return $this->multilanguage;
    }

    /**
     * Set values from stored data, ignoring keys this form does not declare.
     *
     * Both the declared name and the submitted (prefixed) name are accepted, because stored
     * data comes from {@see getData()}, which keys by declared name, while a repopulation
     * after a rejected submit may carry the prefixed one.
     *
     * @param  array<string, mixed>|mixed $data Stored values
     * @return $this
     */
    public function setValues($data): self
    {
        if (!is_array($data)) {
            return $this;
        }

        foreach ($data as $key => $value) {
            if (isset($this->fields[$key])) {
                $this->fields[$key]->value = $value;
            }

            foreach ($this->multilanguage as $language => $fields) {
                $bare = (string) preg_replace('/_' . preg_quote($language, '/') . '$/', '', (string) $key);
                if (isset($fields[$bare])) {
                    $this->multilanguage[$language][$bare]->value = $value;
                }
            }
        }

        return $this;
    }

    /**
     * Whether this request carries a submission of this form.
     *
     * Checks for the submit marker rather than for any POST data, so two forms on one page do
     * not read each other's submission.
     *
     * @return bool
     */
    public function wasSubmitted(): bool
    {
        $marker = \Pramnos\Http\Request::getInstance()
            ->get($this->fieldName('_submitted'), '', $this->method);

        return $marker !== '';
    }

    /**
     * The submitted values, keyed by declared name.
     *
     * **Returns an empty array when the CSRF token does not check out**, rather than throwing
     * or returning partial data. A caller that ignores the difference between "nothing was
     * submitted" and "the token was wrong" saves nothing either way, which is the safe
     * failure; a caller that cares can ask {@see tokenFailed()}.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->checkToken && !$this->tokenIsValid()) {
            return [];
        }

        $data = [];
        foreach ($this->fields as $name => $field) {
            $data[$name] = $field->readSubmitted($this->method)->effectiveValue();
        }
        foreach ($this->multilanguage as $language => $fields) {
            foreach ($fields as $name => $field) {
                $data[$name . '_' . $language]
                    = $field->readSubmitted($this->method)->effectiveValue();
            }
        }

        return $data;
    }

    /**
     * Whether a submission was rejected for its token.
     *
     * @return bool
     */
    public function tokenFailed(): bool
    {
        return $this->checkToken && $this->wasSubmitted() && !$this->tokenIsValid();
    }

    /**
     * The fields, without the surrounding `<form>`.
     *
     * For a caller that supplies its own form tag — an administration panel that wraps several
     * of these in one submit, which is what `Theme::renderSettingsForm()` does.
     *
     * @return string
     */
    public function renderFields(): string
    {
        $styles = FieldStyles::for($this->theme);
        $out    = '';

        foreach ($this->fields as $field) {
            $out .= $field->render($styles);
        }

        foreach ($this->multilanguage as $language => $fields) {
            $out .= '<h3>' . htmlspecialchars(
                ucfirst($language),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '</h3>';
            foreach ($fields as $field) {
                $out .= $field->render($styles);
            }
        }

        return $out;
    }

    /**
     * The complete form.
     *
     * @return string
     */
    public function render(): string
    {
        $out = '<form method="' . htmlspecialchars($this->method, ENT_QUOTES, 'UTF-8')
            . '" action="' . htmlspecialchars($this->action, ENT_QUOTES, 'UTF-8')
            . '" id="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '">';

        $out .= '<input type="hidden" name="'
            . htmlspecialchars($this->fieldName('_submitted'), ENT_QUOTES, 'UTF-8')
            . '" value="1" />';

        if ($this->checkToken) {
            $out .= $this->tokenField();
        }

        $out .= $this->renderFields();

        if ($this->autoAddSubmit) {
            $out .= '<button type="submit">'
                . htmlspecialchars($this->submitLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</button>';
        }

        return $out . '</form>';
    }

    /**
     * The form as its rendered HTML.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * The submitted name for a declared name.
     *
     * @param  string $name The declared name
     * @return string
     */
    private function fieldName(string $name): string
    {
        return $this->addPrefix ? $this->name . '_' . $name : $name;
    }

    /**
     * The framework's CSRF field.
     *
     * `Session::getTokenField()` rather than a token of this class's own — the legacy form put
     * the token in the field's **name**, which meant nothing else could verify it. This is the
     * same field every other form in the framework posts.
     *
     * @return string
     */
    private function tokenField(): string
    {
        try {
            return \Pramnos\Framework\Factory::getSession()->getTokenField();
        } catch (\Throwable $exception) {
            // No session — a CLI render, or a preview. The form still renders; `getData()`
            // will refuse it, which is the correct end state for a form nobody can submit
            // safely.
            return '';
        }
    }

    /**
     * Whether the submitted CSRF token checks out.
     *
     * @return bool
     */
    private function tokenIsValid(): bool
    {
        try {
            return (bool) \Pramnos\Framework\Factory::getSession()
                ->checkToken($this->method);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * The languages a multilanguage field is copied for.
     *
     * @return list<string>
     */
    private function languages(): array
    {
        try {
            $languages = \Pramnos\Translator\Language::getLanguages();

            return is_array($languages) && $languages !== []
                ? array_values(array_map('strval', $languages))
                : [];
        } catch (\Throwable $exception) {
            // A multilanguage field on an installation with no language list declared is a
            // field with no copies, not an error.
            return [];
        }
    }
}
