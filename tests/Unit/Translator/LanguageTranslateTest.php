<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Translator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Translator\Language;

/**
 * Unit tests for Language::_(), the translation lookup.
 *
 * Why this file exists. The method called sprintf() on every translation it
 * found, whether or not the caller passed anything to format with, and passed
 * the arguments as a single *array*. On PHP 8 a format mismatch throws, so a
 * translation containing '%s' could not be looked up at all: sprintf('%s', [])
 * is an ArgumentCountError. The failure only appeared once a translation for
 * the key existed, which meant a string worked in development against the
 * source language and answered 500 the day the language file gained the key.
 *
 * The contract these tests pin down:
 *   - no arguments  → the translation, verbatim, placeholders and all
 *   - arguments     → the translation formatted with them, vsprintf-style
 *   - no translation→ the key, unchanged (the pre-existing behaviour)
 *   - a mismatch    → the unformatted translation, not a fatal
 *
 * The strings are injected with addlang() rather than loaded from a file: the
 * behaviour under test is the lookup, and a fixture file would only add a way
 * for the test to fail for an unrelated reason.
 */
#[CoversClass(Language::class)]
class LanguageTranslateTest extends TestCase
{
    private Language $lang;

    protected function setUp(): void
    {
        // Arrange — a Language with no file behind it; load() finds nothing and
        // returns false, which is fine: addlang() supplies the strings.
        $this->lang = new Language('english', __DIR__);
        $this->lang->addlang([
            'plain'                => 'Καλημέρα',
            '%s is on air'         => '%s εκπέμπει τώρα',
            '%s — %s. Live.'       => '%s — %s. Ζωντανά.',
            'literal percent'      => '100%% done',
            'positional %1$s'      => 'δεύτερο: %2$s, πρώτο: %1$s',
        ]);
    }

    // ── The reported failure ────────────────────────────────────────────────

    /**
     * The filed reproduction: a translation containing '%s', looked up with no
     * arguments, must come back as it is.
     *
     * This is the call that used to be an ArgumentCountError. Nothing about the
     * call site is unusual — it is what every lookup of a key whose formatting
     * the caller does itself looks like.
     */
    public function testTranslationWithAPlaceholderAndNoArgumentsIsReturnedAsIs(): void
    {
        // Act
        $result = $this->lang->_('%s is on air');

        // Assert — the placeholder survives, for the call site to fill in.
        $this->assertSame('%s εκπέμπει τώρα', $result);
    }

    /**
     * The second half of the same bug: arguments were passed to sprintf as one
     * array, so the first placeholder printed the word 'Array'.
     */
    public function testArgumentsFormatTheTranslation(): void
    {
        // Act
        $result = $this->lang->_('%s is on air', 'Aroma');

        // Assert — the value, not 'Array'.
        $this->assertSame('Aroma εκπέμπει τώρα', $result);
    }

    /**
     * More than one argument is spread across the placeholders in order, which
     * is what vsprintf does and what sprintf-with-an-array never could.
     */
    public function testSeveralArgumentsAreSpreadInOrder(): void
    {
        // Act
        $result = $this->lang->_('%s — %s. Live.', 'Aroma', '90.1');

        // Assert
        $this->assertSame('Aroma — 90.1. Ζωντανά.', $result);
    }

    /**
     * Positional specifiers work, so a translation may reorder the arguments
     * its source string used — which is the reason they exist in translations
     * and the reason the arguments must reach vsprintf as a list.
     */
    public function testPositionalSpecifiersReorderArguments(): void
    {
        // Act
        $result = $this->lang->_('positional %1$s', 'ένα', 'δύο');

        // Assert — the translation swapped them.
        $this->assertSame('δεύτερο: δύο, πρώτο: ένα', $result);
    }

    // ── The unchanged behaviours ────────────────────────────────────────────

    /**
     * A key with no translation is returned unchanged. This path never had the
     * bug, and the fix must not have moved it.
     */
    public function testUntranslatedKeyIsReturnedUnchanged(): void
    {
        // Act
        $result = $this->lang->_('no translation for this');

        // Assert
        $this->assertSame('no translation for this', $result);
    }

    /**
     * An untranslated key **is** formatted with the caller's arguments.
     *
     * This assertion used to be the opposite, on the reasoning that "there is nothing to
     * format" and that formatting a miss would make the missing-translation path behave
     * differently from the present one. Both halves are backwards. The key *is* a
     * translation — the framework's own keys are the English wording — so there is
     * something to format; and formatting it is what makes the two paths behave the
     * **same**, which was the stated goal.
     *
     * What the old behaviour actually produced: every installation that had not translated
     * a particular string got a literal `%s` on the page where a value belonged.
     * `l('You have %d items', $count)` printed `You have %d items`, which reads as a broken
     * template rather than as a missing translation — and every string in the framework's
     * bundled screens is in exactly that state until a project translates it.
     */
    public function testUntranslatedKeyIsFormattedWithTheCallersArguments(): void
    {
        // Act
        $result = $this->lang->_('%s has no translation', 'Aroma');

        // Assert
        $this->assertSame('Aroma has no translation', $result);
    }

    /**
     * A translation with no placeholders is returned as it is, both with and
     * without arguments. The no-argument case is the overwhelmingly common one.
     */
    public function testTranslationWithoutPlaceholders(): void
    {
        // Act / Assert
        $this->assertSame('Καλημέρα', $this->lang->_('plain'));
        // Surplus arguments are ignored by vsprintf, as they were by sprintf.
        $this->assertSame('Καλημέρα', $this->lang->_('plain', 'unused'));
    }

    /**
     * '%%' is an escaped percent sign, and only becomes a literal '%' when the
     * translation is actually formatted. Without arguments the raw translation
     * is returned, so the escape is still spelled out — the price of not
     * formatting what nobody asked to format, and the correct trade: the call
     * site that passes no arguments is the one that did not write '%%' either.
     */
    public function testEscapedPercentIsResolvedOnlyWhenFormatting(): void
    {
        // Act / Assert — unformatted, the escape survives verbatim.
        $this->assertSame('100%% done', $this->lang->_('literal percent'));

        // Act / Assert — formatted, vsprintf resolves it.
        $this->assertSame(
            '100% done', $this->lang->_('literal percent', 'ignored')
        );
    }

    // ── Mismatches are content errors, not fatals ───────────────────────────

    /**
     * A translation asking for more placeholders than the call site passes is a
     * mismatch between code and a language file. Language files are content and
     * are edited by translators, so a stray '%s' must not be able to take a
     * page down: the unformatted translation comes back instead.
     *
     * This is the case that would still be a 500 if the fix had only swapped
     * sprintf for vsprintf.
     */
    public function testTooFewArgumentsReturnsTheUnformattedTranslation(): void
    {
        // Act — the translation wants two, the call site gives one.
        $result = $this->lang->_('%s — %s. Live.', 'Aroma');

        // Assert — no throw, and the translation is legible rather than half
        // formatted.
        $this->assertSame('%s — %s. Ζωντανά.', $result);
    }

    /**
     * The same protection for a malformed specifier, which raises ValueError
     * rather than ArgumentCountError. Both are caught, because both come from
     * the same place — text somebody typed into a language file.
     */
    public function testMalformedSpecifierReturnsTheUnformattedTranslation(): void
    {
        // Arrange — '%q' is not a conversion vsprintf knows.
        $this->lang->addlang(['broken' => 'μισό %q σπασμένο']);

        // Act
        $result = $this->lang->_('broken', 'x');

        // Assert
        $this->assertSame('μισό %q σπασμένο', $result);
    }

    /**
     * An empty key — the parameter's own default — is not a translation and is
     * returned as the empty string rather than reaching the format step.
     */
    public function testEmptyKeyIsReturnedEmpty(): void
    {
        // Act / Assert
        $this->assertSame('', $this->lang->_());
    }

    // ── A key that is not a string ──────────────────────────────────────────

    /**
     * `_(null)` is an empty translation, not a white page.
     *
     * The signature is `_($string = '')` — no type and a default, which says *anything is
     * accepted and nothing is fine*. The body then used the value as an array offset and
     * handed it to `onMissingString(string $string)`, so `null` was a deprecation followed by
     * a `TypeError`:
     *
     * ```
     * Deprecated:  Using null as an array offset
     * Fatal error: onMissingString(): Argument #1 ($string) must be of type string, null given
     * ```
     *
     * Reported from an application whose signed-in search page answered `500` because of it:
     * fifteen templates unserialise a model property, wrap a non-array in `array($value)`,
     * and translate each item — and the property is `null` whenever the user left the field
     * empty, because the values live in a separate key/value table and *blank* means *no
     * row*. On the legacy class the same call came back empty and the page rendered.
     */
    public function testANullKeyTranslatesToAnEmptyString(): void
    {
        // Act
        $translated = $this->lang->_(null);

        // Assert
        $this->assertSame('', $translated);
    }

    /**
     * And with arguments it is still empty rather than a formatting error.
     *
     * `vsprintf('', ['x'])` raises `ValueError` on PHP 8 for too many arguments, so the empty
     * key had to reach the same catch every other mismatch does — which the filing's own
     * regression test pins on their side too.
     */
    public function testANullKeyWithArgumentsIsStillEmpty(): void
    {
        // Act
        $translated = $this->lang->_(null, 'ignored');

        // Assert
        $this->assertSame('', $translated);
    }

    /**
     * `false` and `''` are the same absence as `null`.
     *
     * Which is how a template spells it: `$x ?: false`, an `unserialize()` that failed, a
     * model property that was never set. Absence is absence however it was written, and none
     * of the three is a call site worth a log line.
     */
    public function testTheOtherSpellingsOfAbsenceAreEmptyToo(): void
    {
        // Assert
        $this->assertSame('', $this->lang->_(false));
        $this->assertSame('', $this->lang->_(''));
    }

    /**
     * A number is a usable key and becomes its own string.
     *
     * The control for the coercion: a catalogue may legitimately be keyed by number, and
     * treating every non-string as absence would silently stop translating those. `0` is the
     * one to watch — it is falsy, and a falsiness test would have made it disappear.
     *
     * This is also the test that found `addlang()` losing numeric keys. PHP stores `'7'` in an
     * array literal as the integer `7`, and `array_merge()` **renumbers** integer keys rather
     * than preserving them — so the entry was filed under whatever index came next and could
     * never be looked up. Invisible until `_()` started asking for `'7'` correctly.
     */
    public function testANumericKeyIsUsedAsItsOwnString(): void
    {
        // Arrange
        $this->lang->addlang(['7' => 'επτά', '0' => 'μηδέν']);

        // Assert
        $this->assertSame('επτά', $this->lang->_(7));
        $this->assertSame('μηδέν', $this->lang->_(0), 'a zero key was read as absence');
        $this->assertSame('12', $this->lang->_(12), 'an untranslated number lost its own value');
    }

    /**
     * A stringable object is asked for its string.
     *
     * Cheap and it is what a caller means. A key arriving as a value object is a shape worth
     * accepting rather than logging.
     */
    public function testAStringableKeyIsAsked(): void
    {
        // Arrange
        $key = new class {
            public function __toString(): string
            {
                return 'plain';
            }
        };

        // Assert
        $this->assertSame('Καλημέρα', $this->lang->_($key));
    }

    /**
     * What cannot be a key at all: empty answer, and a log line rather than a fatal.
     *
     * A call site passing the wrong thing, which is worth *seeing* — so it is recorded — and
     * not worth *serving*, so it does not take the page down. `true` is here rather than with
     * the absences above: it returns the same empty string, and unlike `false` it is nobody's
     * way of spelling "no value", so it is the one boolean worth a log line.
     */
    public function testWhatCannotBeAKeyIsRefusedWithoutRaising(): void
    {
        // Assert
        $this->assertSame('', $this->lang->_(['plain']));
        $this->assertSame('', $this->lang->_(true));
        $this->assertSame('', $this->lang->_(new \stdClass()));
    }

    /**
     * `onMissingString()` is only ever handed a string, whatever `_()` was given.
     *
     * The invariant the fix exists to restore, asserted at the seam rather than through the
     * symptom: the hook is typed, an application may override it, and it must not have to
     * defend itself against a `null` the framework let through.
     */
    public function testTheMissingStringHookOnlyEverSeesAString(): void
    {
        // Arrange
        $lang = new class ('english', __DIR__) extends Language {
            /** @var list<mixed> */
            public array $seen = [];

            protected function onMissingString(string $string): string
            {
                $this->seen[] = $string;

                return $string;
            }
        };

        // Act
        foreach ([null, false, true, ['x'], 12] as $key) {
            $lang->_($key);
        }

        // Assert
        $this->assertSame(['', '', '', '', '12'], $lang->seen);
    }
}
