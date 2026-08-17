<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html\Form;

use PHPUnit\Framework\TestCase;
use Pramnos\Html\Form\Field;
use Pramnos\Html\Form\FieldStyles;
use Pramnos\Html\Form\SettingsForm;

/**
 * A form described by declared fields — the narrow thing that was actually missing.
 *
 * `Theme::addSetting()` and `Addon::addSetting()` have declared this API since the day those
 * files were transferred from the legacy framework, and both fatalled: the collaborator they
 * called into, `pramnos_html_form`, was never ported, and the line building it arrived already
 * commented out. Five public methods in each, non-functional for the whole life of the files,
 * which is why no addon could have settings.
 *
 * The tests that matter most here are the escaping ones. The legacy class interpolated values
 * straight into attributes — `value="' . $this->value . '"` — and a settings field is the worst
 * place for that: the values are administrator-supplied, they are re-rendered after every save,
 * and the page showing them is an administration panel.
 */
class SettingsFormTest extends TestCase
{
    /**
     * A form with no CSRF requirement, since most tests are about rendering.
     *
     * @param  string $theme A style preset
     * @return SettingsForm
     */
    private function form(string $theme = 'plain'): SettingsForm
    {
        $form = new SettingsForm('settings_test', $theme);
        $form->checkToken = false;

        return $form;
    }

    /**
     * Nothing declared is an empty form, not an error.
     *
     * A theme or addon with no settings is the common case, and it used to be a fatal.
     *
     * @return void
     */
    public function testAnEmptyFormHasNoFields(): void
    {
        // Arrange
        $form = $this->form();

        // Assert
        $this->assertFalse($form->hasFields());
        $this->assertSame('', $form->renderFields());
        $this->assertSame([], $form->fields());
        $this->assertNull($form->value('nothing'));
    }

    /**
     * A declared field renders a labelled control with a prefixed name.
     *
     * The prefix is what lets two forms coexist on one administration page without reading
     * each other's values.
     *
     * @return void
     */
    public function testADeclaredFieldRendersALabelledControl(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('accent', 'Accent colour', 'color', default: '#3366ff');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringContainsString('type="color"', $markup);
        $this->assertStringContainsString('name="settings_test_accent"', $markup);
        $this->assertStringContainsString('value="#3366ff"', $markup);
        $this->assertStringContainsString('>Accent colour', $markup);
    }

    /**
     * A value cannot end the attribute it sits in.
     *
     * The hole in the class this replaces. `x" onfocus="alert(1)` would otherwise close
     * `value` and attach a handler — on an administration page, to a field the administrator
     * themselves typed, re-rendered on every visit.
     *
     * @return void
     */
    public function testAValueCannotEndTheAttribute(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('tagline', 'Tagline', 'textfield', value: 'x" onfocus="alert(1)');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringNotContainsString('onfocus="alert(1)"', $markup);
        $this->assertStringContainsString('&quot;', $markup);
        $this->assertSame(
            1,
            preg_match_all('/<input[^>]*name="settings_test_tagline"/', $markup),
            'One input, not one plus whatever the value opened.'
        );
    }

    /**
     * A label and a description are escaped as text.
     *
     * Separate from the attribute case because they are a different context and a different
     * escape: markup in a label would render as markup rather than as the label somebody wrote.
     *
     * @return void
     */
    public function testLabelsAndDescriptionsAreEscaped(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('x', 'Title <b>bold</b>', 'textfield', description: 'Help <script>x</script>');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringNotContainsString('<b>bold</b>', $markup);
        $this->assertStringNotContainsString('<script>', $markup);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $markup);
    }

    /**
     * Select options are accepted in all three shapes the legacy API allowed.
     *
     * Both callers already pass all three — a comma-separated string, a flat list, and a map
     * — so narrowing this would break the API being restored rather than restore it.
     *
     * @return void
     */
    public function testSelectOptionsAcceptEveryDeclaredShape(): void
    {
        // Arrange & Act & Assert — comma-separated
        $form = $this->form();
        $form->addField('a', 'A', 'select', 'one, two, three', value: 'two');
        $markup = $form->renderFields();
        $this->assertStringContainsString('<option value="two" selected>two</option>', $markup);

        // A flat list
        $form = $this->form();
        $form->addField('b', 'B', 'selectbox', ['red', 'green'], value: 'green');
        $this->assertStringContainsString(
            '<option value="green" selected>green</option>',
            $form->renderFields()
        );

        // A value => label map. This is the case that caught a real bug: PHP coerces the
        // numeric string keys to integers, so the first version read this as a flat list and
        // rendered `value="Enabled"`.
        $form = $this->form();
        $form->addField('c', 'C', 'select', ['1' => 'Enabled', '0' => 'Disabled'], value: '0');
        $markup = $form->renderFields();
        $this->assertStringContainsString('<option value="0" selected>Disabled</option>', $markup);
        $this->assertStringContainsString('<option value="1">Enabled</option>', $markup);

        // The legacy [label, value] pair, which is the unambiguous form — and the one to use
        // for a map keyed 0 and 1 in that order, which PHP cannot distinguish from a list.
        $form = $this->form();
        $form->addField('d', 'D', 'select', [['No', 0], ['Yes', 1]], value: '1');
        $this->assertStringContainsString(
            '<option value="1" selected>Yes</option>',
            $form->renderFields()
        );
    }

    /**
     * An option's label and value are escaped.
     *
     * @return void
     */
    public function testSelectOptionsAreEscaped(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('c', 'C', 'select', ['a" onmouseover="x' => '<b>label</b>']);

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringNotContainsString('onmouseover="x"', $markup);
        $this->assertStringNotContainsString('<b>label</b>', $markup);
    }

    /**
     * A checkbox carries a hidden companion so that unchecking is submitted.
     *
     * Browsers submit nothing for an unchecked box, which is indistinguishable from the field
     * not being on the form — so without the companion a setting could be switched on and
     * never off. The hidden `0` is overwritten by the checkbox's `1` because a later value
     * wins, which is how PHP parses a repeated name.
     *
     * @return void
     */
    public function testACheckboxCanBeUnchecked(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('enabled', 'Enabled', 'checkbox', value: '1');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringContainsString(
            '<input type="hidden" name="settings_test_enabled" value="0" />',
            $markup
        );
        $this->assertStringContainsString('type="checkbox"', $markup);
        $this->assertStringContainsString(' checked', $markup);
    }

    /**
     * An unchecked checkbox renders without `checked`.
     *
     * @return void
     */
    public function testAnUncheckedCheckboxIsNotChecked(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('enabled', 'Enabled', 'checkbox', value: '0');

        // Act & Assert
        $this->assertStringNotContainsString(' checked', $form->renderFields());
    }

    /**
     * A hidden field renders as one line and gets no label.
     *
     * @return void
     */
    public function testAHiddenFieldHasNoLabel(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('token_ish', 'Should not appear', 'hidden', value: 'v');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringNotContainsString('Should not appear', $markup);
        $this->assertStringContainsString('type="hidden"', $markup);
    }

    /**
     * An empty string is a value; only null falls back to the default.
     *
     * A setting deliberately cleared must stay cleared rather than reverting on every render,
     * which is the difference between `=== null` and `empty()` and is the sort of thing that
     * is only ever noticed as "my change did not save".
     *
     * @return void
     */
    public function testAnEmptyStringIsAValueAndNullIsNot(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A', 'textfield', default: 'fallback');
        $form->addField('b', 'B', 'textfield', default: 'fallback', value: '');

        // Assert
        $this->assertSame('fallback', $form->value('a'));
        $this->assertSame('', $form->value('b'));
    }

    /**
     * Stored values are applied, and keys the form does not declare are ignored.
     *
     * Settings outlive the code that declared them: a removed field leaves its value in
     * storage, and reading it back must not resurrect the field or fail.
     *
     * @return void
     */
    public function testStoredValuesAreAppliedAndUnknownKeysIgnored(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('accent', 'Accent', 'textfield', default: '#000');

        // Act
        $form->setValues(['accent' => '#fff', 'removed_last_year' => 'x']);

        // Assert
        $this->assertSame('#fff', $form->value('accent'));
        $this->assertArrayNotHasKey('removed_last_year', $form->fields());
    }

    /**
     * Anything that is not an array is ignored rather than fatal.
     *
     * Stored settings are `unserialize()`d, and that returns `false` for a corrupt or absent
     * value. A settings page that dies because a setting was never written is worse than one
     * that shows its defaults.
     *
     * @return void
     */
    public function testNonArrayStoredValuesAreIgnored(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A', 'textfield', default: 'd');

        // Act
        $form->setValues(false);
        $form->setValues('not an array');

        // Assert
        $this->assertSame('d', $form->value('a'));
    }

    /**
     * The complete form carries a submit marker and a submit button.
     *
     * The marker is what makes `wasSubmitted()` answer for *this* form: two settings forms on
     * one page would otherwise each read the other's submission.
     *
     * @return void
     */
    public function testTheCompleteFormCarriesItsSubmitMarker(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A');

        // Act
        $markup = $form->render();

        // Assert
        $this->assertStringContainsString('<form method="post"', $markup);
        $this->assertStringContainsString('name="settings_test__submitted" value="1"', $markup);
        $this->assertStringContainsString('<button type="submit">Save</button>', $markup);
        $this->assertStringContainsString('</form>', $markup);
    }

    /**
     * `__toString()` renders, so a template can echo the form.
     *
     * @return void
     */
    public function testTheFormCanBeEchoed(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A');

        // Act & Assert
        $this->assertSame($form->render(), (string) $form);
    }

    /**
     * The prefix can be turned off for a form that owns the whole page.
     *
     * @return void
     */
    public function testThePrefixCanBeDisabled(): void
    {
        // Arrange
        $form = $this->form();
        $form->addPrefix = false;
        $form->addField('accent', 'Accent');

        // Act & Assert
        $this->assertStringContainsString('name="accent"', $form->renderFields());
    }

    /**
     * With no session, a token-checking form refuses the submission.
     *
     * The safe direction. `getData()` returning `[]` means a caller that ignores the
     * difference between "nothing submitted" and "token wrong" writes nothing either way —
     * and `Theme::saveSettings()` explicitly refuses to write an empty result, so a rejected
     * submit cannot blank every setting.
     *
     * @return void
     */
    public function testATokenCheckingFormRefusesWithoutAValidToken(): void
    {
        // Arrange
        $form = new SettingsForm('settings_test');
        $form->addField('a', 'A', 'textfield', default: 'd');

        // Act
        $data = $form->getData();

        // Assert
        $this->assertSame([], $data);
    }

    /**
     * Every style preset produces markup, and they differ.
     *
     * The presets exist so a settings form matches the CRUD forms the scaffolder generates.
     * Asserting they differ is what would catch a preset silently falling back to `plain`.
     *
     * @return void
     */
    public function testEveryStylePresetRendersAndTheyDiffer(): void
    {
        $rendered = [];

        foreach (FieldStyles::themes() as $theme) {
            // Arrange
            $form = $this->form($theme);
            $form->addField('a', 'A');

            // Act
            $rendered[$theme] = $form->renderFields();

            // Assert
            $this->assertNotSame('', $rendered[$theme]);
        }

        // Assert — three presets, three distinct outputs
        $this->assertCount(count($rendered), array_unique($rendered));
        $this->assertStringContainsString('form-control', $rendered['bootstrap']);
        $this->assertStringContainsString('border-gray-300', $rendered['tailwind']);
        $this->assertStringContainsString('style=', $rendered['plain']);
    }

    /**
     * An unknown style preset falls back to `plain` rather than failing.
     *
     * The theme name reaches this from configuration, and a settings page that dies because
     * somebody typed `bootstrap5` is worse than one that renders unstyled.
     *
     * @return void
     */
    public function testAnUnknownPresetFallsBackToPlain(): void
    {
        // Act & Assert
        $this->assertSame(FieldStyles::for('plain'), FieldStyles::for('bootstrap5'));
    }

    /**
     * A field object is reachable, for a caller that needs more than its value.
     *
     * `Addon::getProperty()` needs exactly this — the field for one language — and asking for
     * an undeclared one answers null rather than throwing.
     *
     * @return void
     */
    public function testAFieldObjectIsReachable(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A', 'textfield', value: 'v');

        // Act & Assert
        $this->assertInstanceOf(Field::class, $form->field('a'));
        $this->assertSame('v', $form->field('a')->value);
        $this->assertNull($form->field('missing'));
        $this->assertNull($form->field('a', 'el'), 'Not declared multilanguage.');
    }

    /**
     * Numeric bounds reach the control.
     *
     * @return void
     */
    public function testNumericBoundsAreRendered(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('per_page', 'Per page', 'number', default: 20);
        $field = $form->field('per_page');
        $field->min  = 1;
        $field->max  = 100;
        $field->step = 5;

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertStringContainsString('min="1"', $markup);
        $this->assertStringContainsString('max="100"', $markup);
        $this->assertStringContainsString('step="5"', $markup);
    }

    /**
     * A required field says so to the browser.
     *
     * @return void
     */
    public function testARequiredFieldIsMarkedRequired(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A', 'textfield', required: true);

        // Act & Assert
        $this->assertStringContainsString(' required', $form->renderFields());
    }

    /**
     * A textarea renders its value as escaped text content, not as an attribute.
     *
     * A different escaping context from every other field here, and the one where a `</textarea>`
     * in the value would otherwise end the control.
     *
     * @return void
     */
    public function testATextareaEscapesItsContent(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('body', 'Body', 'textarea', value: 'a</textarea><script>x</script>');

        // Act
        $markup = $form->renderFields();

        // Assert
        $this->assertSame(
            1,
            preg_match_all('#</textarea>#', $markup),
            'The value must not close the textarea.'
        );
        $this->assertStringNotContainsString('<script>', $markup);
    }

    /**
     * An unrecognised type renders as a text input rather than nothing.
     *
     * The type reaches this from a theme's own code, and a typo should cost a wrong control,
     * not a missing setting.
     *
     * @return void
     */
    public function testAnUnknownTypeRendersAsText(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('a', 'A', 'somethingelse', value: 'v');

        // Act & Assert
        $this->assertStringContainsString('type="text"', $form->renderFields());
    }

    /**
     * A field with no title gets a humanised one from its name.
     *
     * Both callers allow a null title, and an unlabelled control on a settings page is not a
     * usable answer.
     *
     * @return void
     */
    public function testAMissingTitleIsDerivedFromTheName(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('items_per_page');

        // Act & Assert
        $this->assertStringContainsString('Items Per Page', $form->renderFields());
    }

    /**
     * Submitted values are read back, keyed by declared name.
     *
     * The half of the class that persistence depends on. `getData()` keys by the *declared*
     * name while the input is keyed by the prefixed one, so a caller stores what it declared
     * rather than what the HTML happened to be called — which is why {@see setValues()} round
     * trips.
     *
     * @return void
     */
    public function testSubmittedValuesAreReadByDeclaredName(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('accent', 'Accent', 'textfield', default: '#000');
        $form->addField('per_page', 'Per page', 'number', default: 10);

        $original = $_POST;
        $_POST = [
            'settings_test_accent'   => '#ff0000',
            'settings_test_per_page' => '25',
        ];

        try {
            // Act
            $data = $form->getData();

            // Assert
            $this->assertSame(['accent' => '#ff0000', 'per_page' => '25'], $data);
        } finally {
            $_POST = $original;
        }
    }

    /**
     * A round trip through storage keeps every value.
     *
     * `getData()` out, `setValues()` in — the two halves have to agree on their keys or a
     * settings page saves correctly and then displays its defaults, which reads as "it did not
     * save".
     *
     * @return void
     */
    public function testValuesRoundTripThroughStorage(): void
    {
        // Arrange
        $form = $this->form();
        $form->addField('accent', 'Accent', 'textfield', default: '#000');

        $original = $_POST;
        $_POST = ['settings_test_accent' => '#123456'];

        try {
            $stored = $form->getData();
        } finally {
            $_POST = $original;
        }

        // Act — a fresh form, as the next request would build
        $reloaded = $this->form();
        $reloaded->addField('accent', 'Accent', 'textfield', default: '#000');
        $reloaded->setValues($stored);

        // Assert
        $this->assertSame('#123456', $reloaded->value('accent'));
        $this->assertStringContainsString('value="#123456"', $reloaded->renderFields());
    }

    /**
     * An absent checkbox reads as off, not as its default.
     *
     * Browsers submit nothing for an unchecked box. Falling back to the default here would
     * make a setting that defaults to on impossible to switch off — the bug the hidden
     * companion field exists to prevent, asserted from the reading side.
     *
     * @return void
     */
    public function testAnAbsentCheckboxReadsAsOff(): void
    {
        // Arrange — declared on by default, and nothing submitted for it
        $form = $this->form();
        $form->addField('enabled', 'Enabled', 'checkbox', default: '1');

        $original = $_POST;
        $_POST = ['settings_test__submitted' => '1'];

        try {
            // Act
            $data = $form->getData();

            // Assert
            $this->assertSame('0', $data['enabled']);
        } finally {
            $_POST = $original;
        }
    }

    /**
     * `wasSubmitted()` answers for this form only.
     *
     * Two settings forms on one administration page would otherwise each read the other's
     * submission, and the second would overwrite settings nobody touched.
     *
     * @return void
     */
    public function testWasSubmittedAnswersForThisFormOnly(): void
    {
        // Arrange
        $mine  = $this->form();
        $other = new SettingsForm('settings_other');
        $other->checkToken = false;

        $original = $_POST;
        $_POST = ['settings_other__submitted' => '1'];

        try {
            // Act & Assert
            $this->assertTrue($other->wasSubmitted());
            $this->assertFalse($mine->wasSubmitted(), 'Not my submission.');
        } finally {
            $_POST = $original;
        }
    }

    /**
     * A submission with no valid token is reported as a token failure, not as no submission.
     *
     * The distinction a caller needs to tell an administrator "your session expired, try
     * again" rather than silently doing nothing — and `Theme::saveSettings()` relies on the
     * empty result to avoid blanking every setting on a rejected submit.
     *
     * @return void
     */
    public function testATokenFailureIsDistinguishableFromNoSubmission(): void
    {
        // Arrange — token checking on, and a submission with no token
        $form = new SettingsForm('settings_test');
        $form->addField('a', 'A', 'textfield', default: 'd');

        $original = $_POST;

        try {
            // Act & Assert — nothing submitted at all
            $_POST = [];
            $this->assertFalse($form->tokenFailed());

            // Act & Assert — submitted, but the token does not check out
            $_POST = ['settings_test__submitted' => '1'];
            $this->assertTrue($form->tokenFailed());
            $this->assertSame([], $form->getData());
        } finally {
            $_POST = $original;
        }
    }

    /**
     * A multilanguage field with no language list declared has no copies.
     *
     * `Language::getLanguages()` **throws** when `ROOT/language` does not exist, which is the
     * case in this suite and on any installation that ships no translations. A settings page
     * must not die because of that: the field is declared, it simply has no per-language
     * copies. `Addon::getProperty()` then falls back to the addon's own property, which is
     * what it did before this class existed.
     *
     * @return void
     */
    public function testAMultilanguageFieldWithoutLanguagesStillDeclaresTheBaseField(): void
    {
        // Arrange & Act
        $form = $this->form();
        $form->addField('tagline', 'Tagline', 'textfield', multilanguage: true, value: 'hello');

        // Assert — the base field exists…
        $this->assertSame('hello', $form->value('tagline'));
        // …with no per-language copies, and asking for one is null rather than an error
        $this->assertSame([], $form->multilanguageFields());
        $this->assertNull($form->field('tagline', 'en'));
    }

    /**
     * A token-checking form emits no token field when there is no session, and still renders.
     *
     * A CLI render or a preview. The form is visibly there; `getData()` refuses it, which is
     * the correct end state for a form that cannot be submitted safely.
     *
     * @return void
     */
    public function testAFormRendersWithoutASessionAndRefusesSubmission(): void
    {
        // Arrange
        $form = new SettingsForm('settings_test');
        $form->addField('a', 'A');

        // Act
        $markup = $form->render();

        // Assert
        $this->assertStringContainsString('name="settings_test_a"', $markup);
        $this->assertSame([], $form->getData());
    }

    /**
     * A multilanguage field gets one copy per declared language.
     *
     * `Language::getLanguages()` reads `ROOT/language/*.php`, so this test creates that
     * directory — inside the repository, because the code resolves the path from `ROOT` and a
     * fixture in the system temp directory would silently produce no languages. That mistake
     * was made once already this week, in the theme lazy-load tests, and cost four failing
     * assertions before the code was read instead of assumed.
     *
     * @return void
     */
    public function testAMultilanguageFieldIsCopiedPerLanguage(): void
    {
        // Arrange
        $dir     = ROOT . '/language';
        $created = [];
        $madeDir = false;

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $madeDir = true;
        }
        foreach (['en', 'el'] as $language) {
            $file = $dir . '/' . $language . '.php';
            if (!file_exists($file)) {
                file_put_contents($file, "<?php\nreturn [];\n");
                $created[] = $file;
            }
        }

        try {
            $form = $this->form();
            $form->addField('tagline', 'Tagline', 'textfield', multilanguage: true, default: 'base');

            // Assert — a copy per language, each with its own submitted name
            $copies = $form->multilanguageFields();
            $this->assertArrayHasKey('en', $copies);
            $this->assertArrayHasKey('el', $copies);
            $this->assertSame(
                'settings_test_tagline_en',
                $form->field('tagline', 'en')->name
            );

            // Act — stored values arrive keyed with the language suffix
            $form->setValues(['tagline' => 'Base', 'tagline_el' => 'Βάση']);

            // Assert
            $this->assertSame('Base', $form->value('tagline'));
            $this->assertSame('Βάση', $form->value('tagline', 'el'));

            // And rendering labels each language block
            $markup = $form->renderFields();
            $this->assertStringContainsString('<h3>En</h3>', $markup);
            $this->assertStringContainsString('<h3>El</h3>', $markup);
            $this->assertStringContainsString('name="settings_test_tagline_el"', $markup);

            // And getData() keys the copies with their suffix
            $original = $_POST;
            $_POST = [
                'settings_test_tagline'    => 'A',
                'settings_test_tagline_en' => 'B',
                'settings_test_tagline_el' => 'C',
            ];
            try {
                // Sorted before comparing: the language order comes from `readdir()`, which
                // is not deterministic, and the keys are what this asserts — not their order.
                $data = $form->getData();
                ksort($data);
                $this->assertSame(
                    ['tagline' => 'A', 'tagline_el' => 'C', 'tagline_en' => 'B'],
                    $data
                );
            } finally {
                $_POST = $original;
            }
        } finally {
            foreach ($created as $file) {
                @unlink($file);
            }
            if ($madeDir) {
                @rmdir($dir);
            }
        }
    }

    /**
     * With a valid token, the submission is accepted.
     *
     * The other side of every refusal above. Without this, the class could reject everything
     * and each token test would still pass.
     *
     * @return void
     */
    public function testAValidTokenIsAccepted(): void
    {
        // Arrange — a real session token, and a request carrying its fingerprint
        $_SESSION['token'] = bin2hex(random_bytes(32));
        $session = \Pramnos\Http\Session::getInstance();
        $session->start();

        $form = new SettingsForm('settings_test');
        $form->addField('accent', 'Accent', 'textfield', default: '#000');

        $original = $_POST;
        $_POST = [
            'settings_test__submitted' => '1',
            'settings_test_accent'     => '#abcdef',
            $_SESSION['token']         => $session->getFingerprint(),
        ];

        try {
            // Act
            $data = $form->getData();

            // Assert
            $this->assertFalse($form->tokenFailed());
            $this->assertSame(['accent' => '#abcdef'], $data);

            // And the rendered form carries the field that makes this possible
            $this->assertStringContainsString($_SESSION['token'], $form->render());
        } finally {
            $_POST = $original;
        }
    }
}
