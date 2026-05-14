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
}
