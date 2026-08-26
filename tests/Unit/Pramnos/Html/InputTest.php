<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Input;

/**
 * One standalone form control, replacing four legacy classes.
 *
 * The companion to `Html\Select`, for the same reason: `Form\Field` is right for a control
 * inside a form and needs the form's style preset, and a filter above a table has no form.
 *
 * The legacy `input` class was a **dispatcher** — `type` decided, and for `date`, `time`,
 * `checkbox` and `color` it built a different class and forwarded a dozen properties. Most
 * of that existed because those input types did not work in browsers when it was written.
 * They are native now, so what is left is a tag with attributes.
 *
 * The tests that matter most here are the ones about attributes *not* appearing: a control
 * that emits `min=""` on a text field, or `value` on a file input, produces markup that
 * every browser accepts and every validator rejects — which is the kind of wrong nobody
 * notices.
 */
#[CoversClass(Input::class)]
class InputTest extends TestCase
{
    /**
     * A text input, which is the default and the common case.
     */
    public function testATextInputRenders(): void
    {
        // Arrange
        $input = new Input('q', 'hello');
        $input->placeholder = 'Search…';

        // Act
        $html = $input->render();

        // Assert
        $this->assertStringContainsString('<input type="text" name="q" value="hello"', $html);
        $this->assertStringContainsString('placeholder="Search…"', $html);
    }

    /**
     * A checkbox submits `checkedValue` and is checked when the current value matches.
     *
     * The semantics worth a test: `value` is the *current state*, `checkedValue` is what
     * gets submitted. Swapping them renders a checkbox that submits whatever the row
     * already held — which looks correct until somebody unticks it and the old value is
     * saved back.
     */
    public function testACheckboxSubmitsItsCheckedValue(): void
    {
        // Arrange
        $checked   = new Input('active', '1');
        $unchecked = new Input('active', '0');
        $checked->type = $unchecked->type = 'checkbox';

        // Act & Assert
        $this->assertStringContainsString('value="1" checked', $checked->render());
        $this->assertStringContainsString('value="1"', $unchecked->render());
        $this->assertStringNotContainsString('checked', $unchecked->render());
    }

    /**
     * A non-default `checkedValue` works.
     *
     * For a column that stores something other than 1 for true.
     */
    public function testANonDefaultCheckedValueWorks(): void
    {
        // Arrange
        $input = new Input('status', 'yes');
        $input->type = 'checkbox';
        $input->checkedValue = 'yes';

        // Act & Assert
        $this->assertStringContainsString('value="yes" checked', $input->render());
    }

    /**
     * A radio behaves the same way.
     */
    public function testARadioIsCheckedByValue(): void
    {
        // Arrange
        $input = new Input('choice', 'b');
        $input->type = 'radio';
        $input->checkedValue = 'b';

        // Act & Assert
        $this->assertStringContainsString('type="radio"', $input->render());
        $this->assertStringContainsString('checked', $input->render());
    }

    /**
     * `textarea` puts the value between the tags.
     *
     * The reason it is not simply another entry in the type list, and the reason it is
     * accepted at all: it is the same job from a caller's point of view, and `Form\Field`
     * already treats it as a type.
     */
    public function testATextareaPutsItsValueBetweenTheTags(): void
    {
        // Arrange
        $input = new Input('bio', 'line one');
        $input->type = 'textarea';
        $input->rows = 4;

        // Act
        $html = $input->render();

        // Assert
        $this->assertSame('<textarea name="bio" rows="4">line one</textarea>', $html);
    }

    /**
     * The native types the legacy needed a JavaScript widget for.
     *
     * `date`, `time` and `color` each had their own class, because the input types did not
     * work in browsers when those classes were written. They have for years.
     *
     * @param string $type
     */
    #[DataProvider('nativeTypeProvider')]
    public function testNativeTypesNeedNoWidget(string $type): void
    {
        // Arrange
        $input = new Input('f', 'v');
        $input->type = $type;

        // Act & Assert
        $this->assertStringContainsString('type="' . $type . '"', $input->render());
        $this->assertStringNotContainsString('<script', $input->render());
    }

    /** @return array<string, array{string}> */
    public static function nativeTypeProvider(): array
    {
        return [
            'date'  => ['date'],
            'time'  => ['time'],
            'color' => ['color'],
            'range' => ['range'],
            'month' => ['month'],
        ];
    }

    /**
     * The legacy spellings are accepted.
     *
     * `textfield` and `colorpicker` are what the classes this replaces used, and call sites
     * pass them. Rejecting them would turn a rename into a migration.
     *
     * @param string $given
     * @param string $expected
     */
    #[DataProvider('legacySpellingProvider')]
    public function testLegacySpellingsAreAccepted(string $given, string $expected): void
    {
        // Arrange
        $input = new Input('f', 'v');
        $input->type = $given;

        // Act & Assert
        $this->assertStringContainsString('type="' . $expected . '"', $input->render());
    }

    /** @return array<string, array{string, string}> */
    public static function legacySpellingProvider(): array
    {
        return [
            'textfield'   => ['textfield', 'text'],
            'colorpicker' => ['colorpicker', 'color'],
            'uppercase'   => ['EMAIL', 'email'],
            'empty'       => ['', 'text'],
        ];
    }

    /**
     * An unrecognised type becomes text rather than being emitted.
     *
     * A browser treats an unknown type as text anyway, so passing a typo through only adds
     * an invalid attribute — visible to a validator and to nobody else.
     */
    public function testAnUnknownTypeFallsBackToText(): void
    {
        // Arrange
        $input = new Input('f', 'v');
        $input->type = 'txet';

        // Act
        $html = $input->render();

        // Assert
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringNotContainsString('txet', $html);
    }

    /**
     * Numeric constraints appear only on types that can use them.
     *
     * `min` on a text field is invalid markup. Emitting it and letting the browser ignore
     * it is the difference between "works" and "correct", and only a validator ever says
     * so.
     */
    public function testNumericConstraintsOnlyAppearWhereTheyApply(): void
    {
        // Arrange
        $number = new Input('n', '5');
        $number->type = 'number';
        $number->min = 1;
        $number->max = 10;
        $number->step = 1;

        $text = new Input('t', 'x');
        $text->min = 1;
        $text->max = 10;

        // Act & Assert
        $numberHtml = $number->render();
        $this->assertStringContainsString('min="1"', $numberHtml);
        $this->assertStringContainsString('max="10"', $numberHtml);
        $this->assertStringContainsString('step="1"', $numberHtml);

        $this->assertStringNotContainsString('min=', $text->render());
        $this->assertStringNotContainsString('max=', $text->render());
    }

    /**
     * A file input carries no value.
     *
     * Every browser ignores it and validators reject it — and it is the one type whose
     * value could only have come from the server, which is not something to echo back into
     * the page.
     */
    public function testAFileInputCarriesNoValue(): void
    {
        // Arrange
        $input = new Input('upload', '/etc/passwd');
        $input->type = 'file';

        // Act
        $html = $input->render();

        // Assert
        $this->assertStringNotContainsString('value=', $html);
        $this->assertStringNotContainsString('passwd', $html);
    }

    /**
     * Nothing unset is emitted.
     *
     * The plainest input is the plainest markup. A control that arrives with eight empty
     * attributes is what makes generated HTML unreadable.
     */
    public function testNothingUnsetIsEmitted(): void
    {
        // Act
        $html = (new Input('f'))->render();

        // Assert
        $this->assertSame('<input type="text" name="f" value="" />', $html);
    }

    /**
     * `multiple` appends `[]`, once.
     *
     * Without the brackets PHP keeps only the last submitted value. And a name that already
     * has them must not become `x[][]`.
     */
    public function testMultipleAppendsBracketsOnce(): void
    {
        // Arrange
        $plain = new Input('files');
        $plain->type = 'file';
        $plain->multiple = true;

        $already = new Input('files[]');
        $already->type = 'file';
        $already->multiple = true;

        // Act & Assert
        $this->assertStringContainsString('name="files[]"', $plain->render());
        $this->assertStringContainsString('name="files[]"', $already->render());
        $this->assertStringNotContainsString('[][]', $already->render());
    }

    /**
     * A label gets a `for` only when there is an id to point at.
     *
     * A `for` pointing at nothing is worse than no label: a screen reader announces the
     * association and then finds no control.
     */
    public function testALabelIsOnlyAssociatedWhenThereIsAnId(): void
    {
        // Arrange
        $withId = new Input('f');
        $withId->label = 'Name';
        $withId->id = 'field-f';

        $without = new Input('f');
        $without->label = 'Name';

        // Act & Assert
        $this->assertStringContainsString('<label for="field-f">Name</label>', $withId->render());
        $this->assertStringContainsString('<label>Name</label>', $without->render());
    }

    /**
     * No `id` is invented from the name.
     *
     * The same reason as `Select`: two controls for one field on a page is ordinary, and
     * duplicate ids break `<label for>` and `getElementById` with no visible error. The
     * legacy generated one from the name plus a `uniqid()`, which made the id unstable
     * between renders and therefore useless to a stylesheet or a test.
     */
    public function testNoIdIsInvented(): void
    {
        // Act & Assert
        $this->assertStringNotContainsString('id=', (new Input('f'))->render());
    }

    /**
     * Values, labels and attributes are escaped.
     *
     * The legacy escaped none of them, and a value here comes from a request or a database
     * by definition.
     */
    public function testEverythingIsEscaped(): void
    {
        // Arrange
        $input = new Input('f', '" onfocus="alert(1)');
        $input->label = '<script>x</script>';
        $input->placeholder = '" autofocus';

        // Act
        $html = $input->render();

        // Assert
        $this->assertStringNotContainsString('onfocus="alert', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('" autofocus', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * The boolean attributes are emitted as bare words.
     */
    public function testBooleanAttributesAreEmitted(): void
    {
        // Arrange
        $input = new Input('f');
        $input->required = true;
        $input->readonly = true;
        $input->disabled = true;
        $input->extraAttributes = 'data-role="filter"';

        // Act
        $html = $input->render();

        // Assert
        $this->assertStringContainsString(' required', $html);
        $this->assertStringContainsString(' readonly', $html);
        $this->assertStringContainsString(' disabled', $html);
        $this->assertStringContainsString('data-role="filter"', $html);
    }

    /**
     * `echo $input` renders it.
     */
    public function testItRendersWhenCastToString(): void
    {
        // Arrange
        $input = new Input('f', 'v');

        // Act & Assert
        $this->assertSame($input->render(), (string) $input);
    }
}
