<?php

declare(strict_types=1);

namespace Tests\Unit\Addon;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Addon;

/**
 * Declaring an addon's settings, and the one name that has to be rewritten.
 *
 * `addSetting()` had no covered line, and eight of its statements are the plumbing between an
 * addon and its settings form. One of them is a rule rather than plumbing:
 *
 * ```php
 * if (is_numeric(substr($name, 0, 1))) {
 *     $name = '_' . $name;
 * }
 * ```
 *
 * A setting called `2fa_enabled` is a perfectly reasonable thing for an addon author to write and
 * an impossible thing to be: the name becomes a form field and a property, and neither may begin
 * with a digit. So it becomes `_2fa_enabled` — which the author has to know about, because that is
 * the name they will read it back under.
 */
#[CoversClass(Addon::class)]
class AddonSettingNamesTest extends TestCase
{
    /** An addon with a name, since the form is keyed on it. */
    private function addon(): Addon
    {
        $addon = new class extends Addon {
            public function __construct()
            {
                $this->name = 'testaddon';
                parent::__construct();
            }

            /** The form is protected; a caller reads its settings back through it. */
            public function form(): \Pramnos\Html\Form\SettingsForm
            {
                return $this->settingsForm();
            }
        };

        return $addon;
    }

    /**
     * An ordinary name is declared as written.
     */
    public function testAnOrdinaryNameIsDeclaredAsWritten(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $addon->addSetting('api_key', 'API key');

        // Assert
        $this->assertNotNull($addon->form()->field('api_key'), 'the setting was not declared');
    }

    /**
     * A name beginning with a digit is prefixed with an underscore.
     *
     * The rule, and the reason an addon author needs to know it: `2fa_enabled` is read back as
     * `_2fa_enabled`. A field name starting with a digit is not a valid identifier, so the
     * alternative is a setting that cannot be rendered or a property that cannot be named.
     */
    public function testANameBeginningWithADigitIsPrefixed(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $addon->addSetting('2fa_enabled', 'Second factor');

        // Assert
        $this->assertNotNull(
            $addon->form()->field('_2fa_enabled'),
            'a numeric-leading name should be declared with an underscore in front'
        );
        $this->assertNull(
            $addon->form()->field('2fa_enabled'),
            'it should not also be declared under the name as written'
        );
    }

    /**
     * A digit anywhere but the first character is left alone.
     *
     * The check is on `substr($name, 0, 1)`, and this is what says so: prefixing `oauth2_key`
     * would rename a setting for no reason, and an addon upgrading to a version with the fix
     * would silently lose its stored value.
     */
    public function testADigitLaterInTheNameIsLeftAlone(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $addon->addSetting('oauth2_key', 'OAuth2 key');

        // Assert
        $this->assertNotNull($addon->form()->field('oauth2_key'));
        $this->assertNull($addon->form()->field('_oauth2_key'));
    }

    /**
     * The declaration carries the type, the options and the default through.
     *
     * `addSetting()` is a nine-argument pass-through, and the order is easy to get wrong — a
     * transposition would give every select box its description as its option list. Asserted on
     * the field the form built rather than on the call.
     */
    public function testTheDeclarationCarriesItsDetailsThrough(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $addon->addSetting(
            'mode',
            'Mode',
            'selectbox',
            'fast,slow',
            'How hard to try',
            true,
            'fast'
        );

        // Assert
        $field = $addon->form()->field('mode');
        $this->assertNotNull($field);
        $this->assertSame('selectbox', $field->type);
        $this->assertSame('How hard to try', $field->description);
        $this->assertTrue($field->required);
        $this->assertSame('fast', $field->default);
    }

    /**
     * It returns the addon, so declarations chain.
     *
     * Which is how every addon in the wild declares its settings — a broken return makes the
     * second call in a chain a fatal on `null`.
     */
    public function testItReturnsTheAddonSoDeclarationsChain(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $returned = $addon->addSetting('one')->addSetting('two');

        // Assert
        $this->assertSame($addon, $returned);
        $this->assertNotNull($addon->form()->field('one'));
        $this->assertNotNull($addon->form()->field('two'));
    }

    /**
     * The form is made once and reused.
     *
     * Two settings must land on the same form. A `settingsForm()` that built a new one each time
     * would leave every addon with exactly one setting — the last one declared.
     */
    public function testEverySettingLandsOnTheSameForm(): void
    {
        // Arrange
        $addon = $this->addon();

        // Act
        $addon->addSetting('first');
        $first = $addon->form();
        $addon->addSetting('second');

        // Assert
        $this->assertSame($first, $addon->form(), 'a second form was built');
        $this->assertNotNull($addon->form()->field('first'), 'the first setting was lost');
    }
}
