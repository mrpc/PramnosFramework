<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Form\Field;
use Pramnos\Html\Form\FieldStyles;

/**
 * One field inside a form, built on the standalone controls.
 *
 * `Field` used to construct its own `<input>`, `<textarea>`, checkbox and `<select>`,
 * duplicating what `Html\Input` and `Html\Select` do. The copies had already diverged:
 * this class emitted `min`/`max`/`step` on **every** type, `text` included, which is
 * invalid markup that every browser accepts and every validator rejects.
 *
 * So the tag is delegated now and this class keeps what is actually about being in a
 * form — the label, the description, `effectiveValue()`, the style preset, the `id`, and
 * the hidden companion that makes unchecking a checkbox submittable.
 *
 * These tests are mostly about that boundary holding: what must survive delegation
 * (a vocabulary of its own, the checkbox semantics) and what must not come back (the
 * attributes on types that cannot use them).
 */
#[CoversClass(Field::class)]
class FieldTest extends TestCase
{
    /** @return array<string, string> */
    private function styles(string $theme = 'bootstrap'): array
    {
        return FieldStyles::for($theme);
    }

    private function render(Field $field, string $theme = 'bootstrap'): string
    {
        return $field->render($this->styles($theme));
    }

    /**
     * Numeric constraints appear only on types that can use them.
     *
     * The defect the delegation was really for. `min` on a text field is invalid markup,
     * and it was emitted here on every type because the rule lived in `Html\Input` and
     * this class had its own copy of the loop without it.
     */
    public function testNumericConstraintsNoLongerLeakOntoTextFields(): void
    {
        // Arrange
        $text = new Field('nickname', 'Nickname', 'textfield');
        $text->min = 1;
        $text->max = 10;

        $number = new Field('age', 'Age', 'number');
        $number->min  = 1;
        $number->max  = 120;
        $number->step = 1;

        // Act & Assert
        $textHtml = $this->render($text);
        $this->assertStringNotContainsString('min=', $textHtml);
        $this->assertStringNotContainsString('max=', $textHtml);

        $numberHtml = $this->render($number);
        $this->assertStringContainsString('min="1"', $numberHtml);
        $this->assertStringContainsString('max="120"', $numberHtml);
        $this->assertStringContainsString('step="1"', $numberHtml);
    }

    /**
     * This class's own type vocabulary survives being handed to `Input`.
     *
     * The regression the delegation could most easily have caused, and the reason the
     * type is resolved *before* it is passed on. `Input` has never heard of `datetime` or
     * `image`, and its documented behaviour for an unrecognised type is to fall back to
     * `text` — which would silently turn a date-time picker into a text box and an
     * upgrade into a bug report about "the calendar disappearing".
     *
     * @param string $declared
     * @param string $rendered
     */
    #[DataProvider('typeVocabularyProvider')]
    public function testTheFieldsOwnTypeVocabularyIsPreserved(string $declared, string $rendered): void
    {
        // Arrange
        $field = new Field('f', 'F', $declared);

        // Act & Assert
        $this->assertStringContainsString('type="' . $rendered . '"', $this->render($field));
    }

    /** @return array<string, array{string, string}> */
    public static function typeVocabularyProvider(): array
    {
        return [
            'textfield stays text'          => ['textfield', 'text'],
            'datetime becomes local'        => ['datetime', 'datetime-local'],
            'image was always a text box'   => ['image', 'text'],
            'unknown falls back'            => ['nonsense', 'text'],
            'color is native'               => ['color', 'color'],
            'date is native'                => ['date', 'date'],
        ];
    }

    /**
     * A form field always has an id, and it is the name.
     *
     * `Input` and `Select` deliberately invent none — two controls for one field on a page
     * is ordinary, and duplicate ids break `<label for>` silently. A form field is the
     * case where an id is required rather than risky, because the label points at it.
     */
    public function testEveryVisibleControlHasAnIdMatchingItsLabel(): void
    {
        // Act & Assert
        foreach (['textfield', 'textarea', 'select', 'number'] as $type) {
            $html = $this->render(new Field('thing', 'Thing', $type, ['a' => 'A']));

            $this->assertStringContainsString('<label for="thing"', $html, $type);
            $this->assertStringContainsString('id="thing"', $html, $type);
        }
    }

    /**
     * The style preset reaches every kind of control.
     *
     * Four different keys — `input`, `area`, `check`, `select` — and delegation is exactly
     * where one of them could quietly stop being passed. An unstyled control inside a
     * styled form looks like a CSS problem.
     */
    public function testThePresetReachesEveryKindOfControl(): void
    {
        // Act & Assert
        $this->assertStringContainsString('class="form-control"', $this->render(new Field('a', 'A', 'textfield')));
        $this->assertStringContainsString('class="form-control"', $this->render(new Field('b', 'B', 'textarea')));
        $this->assertStringContainsString('class="form-check-input"', $this->render(new Field('c', 'C', 'checkbox')));
        $this->assertStringContainsString('class="form-select"', $this->render(new Field('d', 'D', 'select', ['x' => 'X'])));
    }

    /**
     * A checkbox keeps its hidden companion and its broad idea of "on".
     *
     * Two behaviours that belong to this class and not to `Input`, so both had to be kept
     * on this side of the boundary:
     *
     * The **companion** carries `0` so that an unchecked box submits something. Without
     * it a browser submits nothing at all, which is indistinguishable from the field not
     * being on the form — a setting could be switched on and never off.
     *
     * The **truthiness** is broader than `Input`'s equality test: anything that is not
     * `'0'` or `''` counts as on, because a setting stored as `1`, `yes` or `true` all
     * mean the same thing to a settings form.
     *
     * @param string $stored
     * @param bool   $expected
     */
    #[DataProvider('checkboxValueProvider')]
    public function testACheckboxKeepsItsCompanionAndItsTruthiness(string $stored, bool $expected): void
    {
        // Arrange
        $field = new Field('active', 'Active', 'checkbox');
        $field->value = $stored;

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertStringContainsString('<input type="hidden" name="active" value="0" />', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('value="1"', $html);
        $this->assertSame($expected, str_contains($html, 'checked'), 'stored value: ' . $stored);
    }

    /** @return array<string, array{string, bool}> */
    public static function checkboxValueProvider(): array
    {
        return [
            "'1' is on"     => ['1', true],
            "'yes' is on"   => ['yes', true],
            "'true' is on"  => ['true', true],
            "'0' is off"    => ['0', false],
            "'' is off"     => ['', false],
        ];
    }

    /**
     * A checkbox labels itself and gets no second label above it.
     */
    public function testACheckboxLabelsItselfOnce(): void
    {
        // Act
        $html = $this->render(new Field('active', 'Active', 'checkbox'));

        // Assert
        $this->assertSame(1, substr_count($html, '<label'));
        $this->assertStringContainsString('Active</label>', $html);
    }

    /**
     * A select renders its options and marks the current one.
     */
    public function testASelectRendersItsOptionsAndTheCurrentValue(): void
    {
        // Arrange
        $field = new Field('role', 'Role', 'select', ['admin' => 'Admin', 'user' => 'User']);
        $field->value = 'user';

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertStringContainsString('<option value="admin">Admin</option>', $html);
        $this->assertStringContainsString('<option value="user" selected>User</option>', $html);
        // Labels are not values: reversing the pair would render every dropdown with the
        // two swapped and produce no error anywhere.
        $this->assertStringNotContainsString('value="Admin"', $html);
    }

    /**
     * A hidden field is bare — no wrapper, no label, no id.
     *
     * There is nothing to show and nothing for a label to point at, and a `<div>` with a
     * margin around an invisible control still moves the fields below it.
     */
    public function testAHiddenFieldIsBare(): void
    {
        // Arrange
        $field = new Field('token', null, 'hidden');
        $field->value = 'abc';

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertSame('<input type="hidden" name="token" value="abc" />', $html);
    }

    /**
     * The native-validation attributes reach the control.
     *
     * Requested for replacing a JavaScript validation library: this class had none of
     * them, so a form built through it could not express a constraint the browser
     * already understands.
     */
    public function testTheValidationAttributesReachTheControl(): void
    {
        // Arrange
        $field = new Field('code', 'Code', 'textfield', required: true);
        $field->pattern      = '[0-9]{4}';
        $field->title        = 'Four digits';
        $field->minlength    = 4;
        $field->maxlength    = 4;
        $field->placeholder  = '1234';
        $field->autocomplete = 'one-time-code';
        $field->inputmode    = 'numeric';

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertStringContainsString('pattern="[0-9]{4}"', $html);
        $this->assertStringContainsString('title="Four digits"', $html);
        $this->assertStringContainsString('minlength="4"', $html);
        $this->assertStringContainsString('maxlength="4"', $html);
        $this->assertStringContainsString('placeholder="1234"', $html);
        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('required', $html);
    }

    /**
     * `$label` is the label and `$title` is the HTML attribute.
     *
     * `$title` meant *label* here until the collision was resolved, which left this class
     * unable to offer an HTML `title` under its own name. Now the two are separate and
     * `$title` means what it means in `Input`, `Select` and HTML itself.
     *
     * Worth its own test because the two are both strings and both plausible in the same
     * position: a mix-up renders the tooltip as the visible label and no tooltip at all,
     * which reads as a content mistake rather than a wrong property.
     */
    public function testTheLabelAndTheTitleAttributeAreDifferentThings(): void
    {
        // Arrange
        $field = new Field('f', 'Visible label', 'textfield');
        $field->title = 'Hover text';

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertStringContainsString('>Visible label', $html);
        $this->assertStringContainsString('title="Hover text"', $html);
        $this->assertStringNotContainsString('title="Visible label"', $html);
    }

    /**
     * The old spelling fails loudly rather than quietly doing the wrong thing.
     *
     * `$title` used to be the label. Positional construction — which is how `SettingsForm`
     * and every documented example build a field — is unaffected by the rename, so the
     * common case simply keeps working.
     *
     * The case that could have gone wrong silently is a **named argument**: `title:
     * 'Email'` would now be assigning a label to the HTML `title` attribute, rendering an
     * empty label and a tooltip nobody asked for. PHP refuses an unknown named parameter
     * instead, which turns a rendering mystery into a stack trace naming the property.
     */
    public function testTheOldLabelSpellingIsRefusedRatherThanMisread(): void
    {
        // Assert — the rename is visible to the caller, not swallowed.
        $this->expectException(\Error::class);

        // Act
        new Field(name: 'email', title: 'Email');
    }

    /**
     * Positional construction is unaffected by the rename.
     *
     * The other half of the statement above, and the reason this was a safe change to
     * make: every caller in the framework, and every documented example, passes the label
     * as the second argument.
     */
    public function testPositionalConstructionStillSetsTheLabel(): void
    {
        // Act
        $html = $this->render(new Field('email', 'Email address', 'email'));

        // Assert
        $this->assertStringContainsString('>Email address', $html);
        $this->assertStringNotContainsString('title=', $html, 'the label must not become a tooltip');
    }

    /**
     * A select can carry a tooltip too.
     *
     * The property was added to `Html\Select` for this: a control in a form should be able
     * to explain itself whichever kind it is.
     */
    public function testASelectCanCarryATooltip(): void
    {
        // Arrange
        $field = new Field('role', 'Role', 'select', ['a' => 'A']);
        $field->title = 'Who this account is';

        // Act & Assert
        $this->assertStringContainsString('title="Who this account is"', $this->render($field));
    }

    /**
     * Values and labels are escaped, whichever control renders them.
     *
     * A settings form is populated from stored settings, so its values are exactly the
     * kind that arrive from somewhere else.
     */
    public function testEverythingIsEscapedAcrossControlKinds(): void
    {
        // Arrange
        $text = new Field('f', '<script>x</script>', 'textfield');
        $text->value = '" onfocus="alert(1)';

        $select = new Field('s', 'S', 'select', ['" onmouseover="x' => '<b>bold</b>']);

        // Act & Assert
        $textHtml = $this->render($text);
        $this->assertStringNotContainsString('onfocus="alert', $textHtml);
        $this->assertStringNotContainsString('<script>', $textHtml);

        $selectHtml = $this->render($select);
        $this->assertStringNotContainsString('onmouseover="x', $selectHtml);
        $this->assertStringNotContainsString('<b>bold</b>', $selectHtml);
    }

    /**
     * The description and the required marker still render around the control.
     *
     * The parts that were never delegated, asserted so that a later change to the
     * delegation cannot quietly drop the wrapper they live in.
     */
    public function testTheWrapperKeepsItsDescriptionAndRequiredMarker(): void
    {
        // Arrange
        $field = new Field('email', 'Email', 'email', description: 'We never share it.', required: true);

        // Act
        $html = $this->render($field);

        // Assert
        $this->assertStringContainsString('<div class="mb-3">', $html);
        $this->assertStringContainsString('<small class="form-text">We never share it.</small>', $html);
        $this->assertStringContainsString('<span aria-hidden="true">*</span>', $html);
    }

    /**
     * The default is used until a value is set, and an empty string is a value.
     *
     * `effectiveValue()` tests against `null`, not emptiness, so a setting deliberately
     * saved as `''` stays empty instead of reverting to its default on every render — the
     * kind of bug that only shows up after somebody clears a field and saves.
     */
    public function testAnEmptyStringIsAValueAndNotAnAbsentOne(): void
    {
        // Arrange
        $untouched = new Field('f', 'F', 'textfield', default: 'fallback');

        $cleared = new Field('f', 'F', 'textfield', default: 'fallback');
        $cleared->value = '';

        // Act & Assert
        $this->assertStringContainsString('value="fallback"', $this->render($untouched));
        $this->assertStringContainsString('value=""', $this->render($cleared));
    }
}
