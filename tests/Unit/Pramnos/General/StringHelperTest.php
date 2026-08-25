<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\General\StringHelper;

/**
 * Unit tests for Pramnos\General\StringHelper.
 *
 * StringHelper provides pure static string-manipulation utilities used by the
 * scaffolding wizard, ORM, and router. All methods are side-effect-free and
 * deterministic, so every test here is a simple input→output assertion.
 */
#[CoversClass(StringHelper::class)]
class StringHelperTest extends TestCase
{
    // =========================================================================
    // pluralize
    // =========================================================================

    /** @return array<string,array{string,string}> */
    public static function pluralizeProvider(): array
    {
        return [
            // irregular plurals from the lookup table
            'child→children'      => ['child',    'children'],
            'person→people'       => ['person',   'people'],
            'man→men'             => ['man',       'men'],
            'woman→women'         => ['woman',     'women'],
            'datum→data'          => ['datum',     'data'],
            // -y after consonant → -ies
            'category→categories' => ['category',  'categories'],
            'city→cities'         => ['city',       'cities'],
            // -is → -es (non-irregular: hits the dedicated -is branch, not $irregularPlurals)
            'axis→axes'           => ['axis',      'axes'],
            // Latin -us → -i for words NOT in $irregularPlurals but in $latinWords
            // Note: cactus/focus/fungus are in $irregularPlurals and skip this branch;
            // 'stimulus' and 'alumnus' are NOT in $irregularPlurals so they reach lines 88-93.
            'stimulus→stimuli'    => ['stimulus',  'stimuli'],
            'alumnus→alumni'      => ['alumnus',   'alumni'],
            // Words in $irregularPlurals that also happen to end in -us/-is (go through irregular path)
            'analysis→analyses'   => ['analysis',  'analyses'],
            'cactus→cacti'        => ['cactus',    'cacti'],
            // -ch/-sh/-ss/-x/-z → -es
            'church→churches'     => ['church',    'churches'],
            'box→boxes'           => ['box',       'boxes'],
            // -f → -ves for 'shelf' which is in $fWords but NOT in $irregularPlurals
            // (leaf/knife/wife are in $irregularPlurals and skip this branch)
            'shelf→shelves'       => ['shelf',     'shelves'],
            // Irregular -f/-fe words (go through $irregularPlurals, not the -f branch)
            'leaf→leaves'         => ['leaf',      'leaves'],
            'knife→knives'        => ['knife',     'knives'],
            // default: add -s
            'user→users'          => ['user',      'users'],
            'model→models'        => ['model',     'models'],
        ];
    }

    /**
     * pluralize() returns the correct English plural for a given singular word.
     * This covers irregular plurals, Latin forms, -y→-ies, and the default +s.
     *
     * @param string $singular  Input word
     * @param string $expected  Expected plural
     */
    #[DataProvider('pluralizeProvider')]
    public function testPluralizeReturnsCorrectPlural(string $singular, string $expected): void
    {
        // Arrange / Act
        $result = StringHelper::pluralize($singular);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * pluralize() is idempotent: passing an already-plural word returns it
     * unchanged (because isPlural() guards the entry point).
     */
    public function testPluralizeIsIdempotentForAlreadyPluralWord(): void
    {
        // Arrange / Act
        $result = StringHelper::pluralize('users');

        // Assert – unchanged (already plural)
        $this->assertSame('users', $result);
    }

    // =========================================================================
    // singularize
    // =========================================================================

    /** @return array<string,array{string,string}> */
    public static function singularizeProvider(): array
    {
        return [
            // Note: 'people' is NOT singularized to 'person' because isPlural('people')
            // returns false (the word has no recognised plural suffix), so singularize()
            // returns it unchanged.  That is a known limitation of the regex-based
            // isPlural() guard, not tested here.
            'children→child'      => ['children',   'child'],
            'men→man'             => ['men',         'man'],
            'categories→category' => ['categories',  'category'],
            'analyses→analysis'   => ['analyses',    'analysis'],
            'cacti→cactus'        => ['cacti',        'cactus'],
            'leaves→leaf'         => ['leaves',       'leaf'],
            'users→user'          => ['users',        'user'],
            'models→model'        => ['models',       'model'],
            'boxes→box'           => ['boxes',        'box'],
        ];
    }

    /**
     * singularize() returns the correct English singular for a given plural word.
     * Words recognised by isPlural() (ending in -s, -es, -ies, -i, -en, -a, -ves)
     * are singularized via irregular-lookup or suffix rules.
     *
     * @param string $plural    Input word (plural)
     * @param string $expected  Expected singular
     */
    #[DataProvider('singularizeProvider')]
    public function testSingularizeReturnsCorrectSingular(string $plural, string $expected): void
    {
        // Arrange / Act
        $result = StringHelper::singularize($plural);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * singularize() is idempotent: a singular word is returned unchanged.
     */
    public function testSingularizeIsIdempotentForSingularWord(): void
    {
        // Arrange / Act
        $result = StringHelper::singularize('user');

        // Assert – 'user' is singular; returned as-is
        $this->assertSame('user', $result);
    }

    /**
     * Words in the $singularWithS list ('news', 'lens', etc.) are NOT treated
     * as plurals even though they end with 's'.
     */
    public function testSingularizePreservesWordsInSingularWithSList(): void
    {
        // Arrange / Act & Assert – 'news' ends in 's' but is singular
        $this->assertSame('news', StringHelper::singularize('news'));
        $this->assertSame('lens', StringHelper::singularize('lens'));
    }

    // =========================================================================
    // isPlural
    // =========================================================================

    /** @return array<string,array{string,bool}> */
    public static function isPluralProvider(): array
    {
        return [
            'users is plural'    => ['users',     true],
            'categories plural'  => ['categories', true],
            'children plural'    => ['children',  true],
            'cacti plural'       => ['cacti',     true],
            'user is singular'   => ['user',      false],
            'category singular'  => ['category',  false],
            'news is NOT plural' => ['news',      false],
            'lens is NOT plural' => ['lens',      false],
        ];
    }

    /**
     * isPlural() correctly identifies plural vs singular words, respecting the
     * $singularWithS exception list so 'news' and 'lens' are not mis-classified.
     *
     * @param string $word      Word to test
     * @param bool   $expected  Whether the word is plural
     */
    #[DataProvider('isPluralProvider')]
    public function testIsPluralClassifiesCorrectly(string $word, bool $expected): void
    {
        // Arrange / Act
        $result = StringHelper::isPlural($word);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // toCamelCase
    // =========================================================================

    /** @return array<string,array{string,bool,string}> */
    public static function camelCaseProvider(): array
    {
        return [
            'snake → camelCase'          => ['my_model_name',  false, 'myModelName'],
            'kebab → camelCase'          => ['my-model-name',  false, 'myModelName'],
            'spaces → camelCase'         => ['my model name',  false, 'myModelName'],
            'snake → PascalCase'         => ['my_model_name',  true,  'MyModelName'],
            'single word camel'          => ['model',          false, 'model'],
            'single word pascal'         => ['model',          true,  'Model'],
            'already pascal → unchanged' => ['MyModelName',    false, 'myModelName'],
        ];
    }

    /**
     * toCamelCase() converts underscore-, hyphen-, or space-separated strings
     * to camelCase. When $capitalizeFirstCharacter is true it produces PascalCase.
     *
     * @param string $input  Input string
     * @param bool   $caps   Whether to capitalize the first character
     * @param string $expected Expected result
     */
    #[DataProvider('camelCaseProvider')]
    public function testToCamelCaseConvertsCorrectly(string $input, bool $caps, string $expected): void
    {
        // Arrange / Act
        $result = StringHelper::toCamelCase($input, $caps);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // toSnakeCase
    // =========================================================================

    /** @return array<string,array{string,string}> */
    public static function snakeCaseProvider(): array
    {
        return [
            'camel → snake'  => ['myModelName',   'my_model_name'],
            'pascal → snake' => ['MyModelName',   'my_model_name'],
            'single word'    => ['model',          'model'],
            'already snake'  => ['my_model_name',  'my_model_name'],
        ];
    }

    /**
     * toSnakeCase() converts CamelCase/PascalCase strings to snake_case.
     *
     * @param string $input    Input string
     * @param string $expected Expected snake_case result
     */
    #[DataProvider('snakeCaseProvider')]
    public function testToSnakeCaseConvertsCorrectly(string $input, string $expected): void
    {
        // Arrange / Act
        $result = StringHelper::toSnakeCase($input);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // toKebabCase
    // =========================================================================

    /**
     * toKebabCase() converts to snake_case then replaces underscores with
     * hyphens — so it inherits the same CamelCase→snake logic.
     */
    public function testToKebabCaseConvertsFromCamelCase(): void
    {
        // Arrange / Act
        $result = StringHelper::toKebabCase('myModelName');

        // Assert
        $this->assertSame('my-model-name', $result);
    }

    /**
     * toKebabCase() on an already snake_case string simply swaps underscores.
     */
    public function testToKebabCaseConvertsFromSnakeCase(): void
    {
        // Arrange / Act
        $result = StringHelper::toKebabCase('my_model_name');

        // Assert
        $this->assertSame('my-model-name', $result);
    }

    // =========================================================================
    // toPascalCase
    // =========================================================================

    /**
     * toPascalCase() is an alias for toCamelCase(..., true) — first letter
     * is always capitalised.
     */
    public function testToPascalCaseCapitalisesFirstLetter(): void
    {
        // Arrange / Act
        $result = StringHelper::toPascalCase('my_model_name');

        // Assert
        $this->assertSame('MyModelName', $result);
    }

    /**
     * toPascalCase() on a single lowercase word capitalises just that word.
     */
    public function testToPascalCaseSingleWordCapitalises(): void
    {
        // Arrange / Act
        $result = StringHelper::toPascalCase('model');

        // Assert
        $this->assertSame('Model', $result);
    }

    // =========================================================================
    // getProperClassName
    // =========================================================================

    /**
     * getProperClassName() with $forceSingular=true (default) singularizes a
     * plural input and PascalCases it — this is the ORM model naming convention.
     */
    public function testGetProperClassNameSingularizesPluralInput(): void
    {
        // Arrange / Act
        $result = StringHelper::getProperClassName('users');

        // Assert – 'users' singularized to 'user', then PascalCased
        $this->assertSame('User', $result);
    }

    /**
     * getProperClassName() with $forceSingular=true leaves a singular input
     * unchanged (it is already singular).
     */
    public function testGetProperClassNameLeavesSingularInputUnchanged(): void
    {
        // Arrange / Act
        $result = StringHelper::getProperClassName('user');

        // Assert – 'user' → PascalCase 'User', no singularization needed
        $this->assertSame('User', $result);
    }

    /**
     * getProperClassName() with $forceSingular=false pluralizes a singular
     * input — useful for generating collection class names.
     */
    public function testGetProperClassNamePluralizesSingularWhenForceSingularFalse(): void
    {
        // Arrange / Act
        $result = StringHelper::getProperClassName('user', false);

        // Assert – 'user' → 'users' → 'Users'
        $this->assertSame('Users', $result);
    }

    /**
     * getProperClassName() with $forceSingular=false and a plural input keeps
     * it plural and PascalCases it.
     */
    public function testGetProperClassNameKeepsPluralWhenForceSingularFalse(): void
    {
        // Arrange / Act
        $result = StringHelper::getProperClassName('users', false);

        // Assert
        $this->assertSame('Users', $result);
    }

    // =========================================================================
    // getModelTableName
    // =========================================================================

    /**
     * getModelTableName() always returns a plural, lowercased table name with
     * the '#PREFIX#' placeholder prepended.
     */
    public function testGetModelTableNameReturnsPluralWithPrefix(): void
    {
        // Arrange / Act
        $result = StringHelper::getModelTableName('user');

        // Assert – singular input is pluralized
        $this->assertSame('#PREFIX#users', $result);
    }

    /**
     * getModelTableName() with an already-plural input keeps it plural (no
     * double-pluralization).
     */
    public function testGetModelTableNameDoesNotDoublePluralize(): void
    {
        // Arrange / Act
        $result = StringHelper::getModelTableName('users');

        // Assert – still '#PREFIX#users', not '#PREFIX#userss'
        $this->assertSame('#PREFIX#users', $result);
    }

    // =========================================================================
    // getFullTableName
    // =========================================================================

    /**
     * getFullTableName() with no schema simply replaces '#PREFIX#' with the
     * given prefix string.
     */
    public function testGetFullTableNameReplacesPrefix(): void
    {
        // Arrange / Act
        $result = StringHelper::getFullTableName('#PREFIX#users', null, 'app_');

        // Assert
        $this->assertSame('app_users', $result);
    }

    /**
     * getFullTableName() with a schema prepends schema.table and still
     * replaces '#PREFIX#'.
     */
    public function testGetFullTableNamePrependsSchemaWhenProvided(): void
    {
        // Arrange / Act
        $result = StringHelper::getFullTableName('#PREFIX#users', 'public', 'app_');

        // Assert – schema prepended, prefix replaced
        $this->assertSame('public.app_users', $result);
    }

    /**
     * getFullTableName() with an empty prefix just strips '#PREFIX#'.
     */
    public function testGetFullTableNameWithEmptyPrefixStripsPlaceholder(): void
    {
        // Arrange / Act
        $result = StringHelper::getFullTableName('#PREFIX#users', null, '');

        // Assert
        $this->assertSame('users', $result);
    }

    // =========================================================================
    // containsGreekCharacters
    // =========================================================================

    /**
     * containsGreekCharacters() returns truthy for a string that contains
     * Greek vowels or their accented forms.
     */
    public function testContainsGreekCharactersReturnsTruthyForGreekText(): void
    {
        // Arrange / Act
        $result = StringHelper::containsGreekCharacters('Καλημέρα');

        // Assert
        $this->assertNotFalse($result);
    }

    /**
     * containsGreekCharacters() returns falsy for a plain ASCII string with
     * no Greek vowels.
     */
    public function testContainsGreekCharactersReturnsFalsyForAsciiText(): void
    {
        // Arrange / Act
        $result = StringHelper::containsGreekCharacters('Hello World');

        // Assert
        $this->assertFalse((bool) $result);
    }

    /**
     * containsGreekCharacters() detects accented Greek characters (Greek
     * Extended Unicode block U+1F00–U+1FFE).
     */
    public function testContainsGreekCharactersDetectsAccentedGreek(): void
    {
        // Arrange – polytonic Greek with extended accents
        $text = 'ἄνθρωπος';

        // Act
        $result = StringHelper::containsGreekCharacters($text);

        // Assert
        $this->assertNotFalse($result);
    }

    // =========================================================================
    // Extra coverage: singularize -i block and fallback, isPlural -a ending
    // =========================================================================

    /**
     * singularize() must convert Latin -i plurals (e.g. 'stimuli') back to -us
     * via the possibleSingulars table at lines 177–180.
     *
     * 'cacti' is in $irregularPlurals and uses the flip-map shortcut, so it
     * never reaches this block. Words like 'stimuli' and 'alumni' that are NOT
     * in $irregularPlurals are the only way to exercise lines 176–182.
     */
    public function testSingularizeConvertsLatinIPluralsToUs(): void
    {
        // Arrange / Act
        $stimuli = StringHelper::singularize('stimuli');
        $alumni  = StringHelper::singularize('alumni');

        // Assert — -i → -us via possibleSingulars lookup
        $this->assertSame('stimulus', $stimuli,
            'stimuli is a Latin -i plural not in $irregularPlurals; must use the possibleSingulars table');
        $this->assertSame('alumnus', $alumni,
            'alumni is a Latin -i plural not in $irregularPlurals; must use the possibleSingulars table');
    }

    /**
     * singularize() must return the word unchanged when isPlural() recognised it
     * as plural but none of the suffix-stripping rules can revert it.
     *
     * Words ending in '-a' (like 'trivia', 'trilobita') satisfy isPlural() via
     * the -a suffix match but have no singular rule to apply → hit line 201.
     */
    public function testSingularizeFallsBackToOriginalForUnsupportedPlural(): void
    {
        // Arrange — 'trivia' ends in 'a' so isPlural() returns true, but
        // singularize() has no reverse rule for it.
        $result = StringHelper::singularize('trivia');

        // Assert — must not throw; returns the word as-is (line 201 fallback)
        $this->assertSame('trivia', $result,
            'singularize() must return the word unchanged when no suffix rule applies');
    }

    /**
     * isPlural() must return true for words ending in '-a' (Latin/Greek neuter
     * plurals like 'media', 'criteria').
     *
     * Covers the substr($word, -1) === 'a' branch at line 227.
     */
    public function testIsPluralReturnsTrueForWordsEndingInA(): void
    {
        // Arrange / Act
        $media    = StringHelper::isPlural('media');
        $criteria = StringHelper::isPlural('criteria');

        // Assert — both end in 'a' so isPlural() must return true
        $this->assertTrue((bool) $media,
            'isPlural() must recognise -a ending (Latin/Greek plural) as plural');
        $this->assertTrue((bool) $criteria,
            'isPlural() must recognise -a ending (Latin/Greek plural) as plural');
    }

    // ── excerpt() ────────────────────────────────────────────────────────────

    /**
     * A word longer than the limit is cut, not thrown away.
     *
     * The bug this method was written to fix, reported twice — once as FW-018 from a
     * consuming application with these exact strings measured, and once found here while
     * moving the method out of `Helpers`.
     *
     * `mb_strrpos()` finds no space, returns `false`, `mb_substr()` reads that as 0, and
     * the result is the ellipsis on its own: **the whole text is lost**. One long word is
     * ordinary — a Greek compound, a name with no space, a URL, a hashtag — and the
     * reporting application called this in 20 places, all of them user-facing lists. The
     * symptom is not an error, it is a column of "…" where titles should be.
     *
     * The legacy framework this was ported from had exactly this guard and the port
     * dropped it.
     *
     * @param string $text
     * @param int    $length
     * @param string $expected
     * @return void
     */
    #[DataProvider('longWordProvider')]
    public function testAWordLongerThanTheLimitIsCutRatherThanLost(
        string $text, int $length, string $expected
    ): void {
        // Act
        $result = StringHelper::excerpt($text, $length);

        // Assert
        $this->assertSame($expected, $result);
        $this->assertNotSame('…', $result, 'the text must not be replaced by the ellipsis');
    }

    /** @return array<string, array{string, int, string}> */
    public static function longWordProvider(): array
    {
        // The three strings FW-018 measured, which all returned '&hellip;' before.
        return [
            'greek compound'  => ['Καθηγητήςμαθηματικών', 10, 'Καθηγητής…'],
            'english long'    => ['Supercalifragilisticexpialidocious', 12, 'Supercalifr…'],
            'short greek'     => ['Παιδαγωγός', 5, 'Παιδ…'],
        ];
    }

    /**
     * The result never exceeds the requested length.
     *
     * The guarantee the old implementation did not make: it cut to `$length` and *then*
     * appended the suffix, so the result was longer than the number asked for. A caller
     * sizing a column or a meta description cannot use that.
     *
     * Every length from 0 up, so an off-by-one anywhere in the budget arithmetic fails
     * here rather than in somebody's layout.
     *
     * @return void
     */
    public function testTheResultNeverExceedsTheRequestedLength(): void
    {
        // Arrange
        $text = 'The quick brown fox jumps over the lazy dog';

        for ($length = 0; $length <= 45; $length++) {
            // Act
            $result = StringHelper::excerpt($text, $length);

            // Assert
            $this->assertLessThanOrEqual(
                $length,
                mb_strlen($result),
                "excerpt(\$length = $length) returned " . mb_strlen($result) . ' characters'
            );
        }
    }

    /**
     * A boundary that falls exactly on the budget keeps the last whole word.
     *
     * The off-by-one this had while being written: `"The quick"` is nine characters and a
     * complete phrase, so a limit of ten — nine plus the ellipsis — should keep it.
     * Searching backwards from the cut without checking whether the cut was already on a
     * space threw the last word away and returned `"The…"`.
     *
     * @return void
     */
    public function testAWordEndingExactlyOnTheBudgetIsKept(): void
    {
        // Act
        $result = StringHelper::excerpt('The quick brown fox', 10);

        // Assert
        $this->assertSame('The quick…', $result);
    }

    /**
     * Text that fits comes back untouched, with no ellipsis.
     *
     * @return void
     */
    public function testTextThatFitsIsUnchanged(): void
    {
        // Act & Assert
        $this->assertSame('Hi there', StringHelper::excerpt('Hi there', 50));
        $this->assertSame('Exactly8', StringHelper::excerpt('Exactly8', 8));
    }

    /**
     * HTML is stripped before the length is measured.
     *
     * The reason the method exists rather than `mb_substr()`: the length is a length of
     * *visible* text, so an excerpt of markup gives prose rather than an unclosed
     * `<span class="…">`.
     *
     * @return void
     */
    public function testHtmlIsStrippedBeforeMeasuring(): void
    {
        // Act
        $result = StringHelper::excerpt('<p>Hello <b>brave</b> new world</p>', 14);

        // Assert
        $this->assertSame('Hello brave…', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    /**
     * Null is an empty string, not a TypeError.
     *
     * A nullable database column reaches this directly in the reporting application, and
     * a listing page is not the place to discover a missing description.
     *
     * @return void
     */
    public function testNullIsTreatedAsEmpty(): void
    {
        // Act & Assert
        $this->assertSame('', StringHelper::excerpt(null, 10));
    }

    /**
     * A length too small for the ellipsis still bounds the result.
     *
     * The degenerate end of the budget arithmetic. With nothing left after paying for the
     * ellipsis there is no honest answer but a hard cut of the text — returning the
     * ellipsis alone would be the very failure this method was written to remove.
     *
     * @return void
     */
    public function testALengthTooSmallForTheEllipsisStillBounds(): void
    {
        // Act & Assert
        $this->assertSame('T', StringHelper::excerpt('The quick brown fox', 1));
        $this->assertSame('', StringHelper::excerpt('The quick brown fox', 0));
    }

    /**
     * A negative length is rejected rather than guessed at.
     *
     * @return void
     */
    public function testANegativeLengthIsRejected(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        StringHelper::excerpt('anything', -1);
    }
}
