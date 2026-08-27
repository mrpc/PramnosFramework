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
}
