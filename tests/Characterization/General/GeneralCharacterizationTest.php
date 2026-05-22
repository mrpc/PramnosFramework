<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\General\StringHelper;
use Pramnos\General\Helpers;

/**
 * Characterization tests for the General subsystem.
 *
 * Covers StringHelper (pluralize, singularize, isPlural, case conversion,
 * getProperClassName, getModelTableName, getFullTableName,
 * containsGreekCharacters) and Helpers (pure-logic static utilities).
 *
 * All tests are pure-logic with no DB or network.
 */
#[CoversClass(StringHelper::class)]
#[CoversClass(Helpers::class)]
class GeneralCharacterizationTest extends TestCase
{
    // =======================================================================
    // StringHelper – pluralize
    // =======================================================================

    /** @return array<string,array{string,string}> */
    public static function pluralizeProvider(): array
    {
        return [
            'regular word'          => ['car',    'cars'],
            'word ending in -y'     => ['city',   'cities'],
            'word ending in -is'    => ['analysis','analyses'],
            'word ending in -ch'    => ['church', 'churches'],
            'word ending in -sh'    => ['brush',  'brushes'],
            'word ending in -x'     => ['box',    'boxes'],
            'word ending in -o'     => ['potato', 'potatoes'],
            'irregular: child'      => ['child',  'children'],
            'irregular: person'     => ['person', 'people'],
            'irregular: datum'      => ['datum',  'data'],
            'irregular: quiz'       => ['quiz',   'quizzes'],
            'already plural'        => ['cars',   'cars'],
        ];
    }

    /**
     * pluralize() produces the correct plural form for various inputs.
     *
     * @param string $singular Input
     * @param string $expected Expected plural
     */
    #[DataProvider('pluralizeProvider')]
    public function testPluralize(string $singular, string $expected): void
    {
        $this->assertSame($expected, StringHelper::pluralize($singular));
    }

    // =======================================================================
    // StringHelper – singularize
    // =======================================================================

    /** @return array<string,array{string,string}> */
    public static function singularizeProvider(): array
    {
        return [
            // Standard patterns
            'regular -s'              => ['cars',       'car'],
            '-ies → -y'               => ['cities',     'city'],
            '-es from -ch'            => ['churches',   'church'],
            '-es from -sh'            => ['dishes',     'dish'],
            '-es from -ss'            => ['classes',    'class'],
            '-xes → strip es'         => ['foxes',      'fox'],
            'irregular: children'     => ['children',   'child'],
            'irregular: people (known limitation – unchanged)' => ['people', 'people'],
            'irregular: data'         => ['data',       'datum'],
            'already singular'        => ['car',        'car'],
            'singular with s: news'   => ['news',       'news'],
            // Silent-e root (consonant before final e): strip only 's'
            '-les: articles'          => ['articles',   'article'],
            '-les: modules'           => ['modules',    'module'],
            '-les: files'             => ['files',      'file'],
            '-les: roles'             => ['roles',      'role'],
            '-les: tables'            => ['tables',     'table'],
            '-res: whores'            => ['whores',     'whore'],
            '-res: horses'            => ['horses',     'horse'],
            '-res: structures'        => ['structures', 'structure'],
            '-res: procedures'        => ['procedures', 'procedure'],
            '-ges: images'            => ['images',     'image'],
            '-ges: pages'             => ['pages',      'page'],
            '-ces: services'          => ['services',   'service'],
            '-ces: devices'           => ['devices',    'device'],
            '-ces: resources'         => ['resources',  'resource'],
            '-nes: machines'          => ['machines',   'machine'],
            '-tes: templates'         => ['templates',  'template'],
            '-des: modes'             => ['modes',      'mode'],
            '-mes: names'             => ['names',      'name'],
            '-zes: mazes'             => ['mazes',      'maze'],
            '-ses: courses'           => ['courses',    'course'],
            '-ses: responses'         => ['responses',  'response'],
            // Vowel before 'e' → strip 'es' (not a silent-e root)
            '-oes: tomatoes'          => ['tomatoes',   'tomato'],
            '-oes: potatoes'          => ['potatoes',   'potato'],
            '-oes: heroes'            => ['heroes',     'hero'],
        ];
    }

    /**
     * singularize() produces the correct singular form for various inputs.
     */
    #[DataProvider('singularizeProvider')]
    public function testSingularize(string $plural, string $expected): void
    {
        $this->assertSame($expected, StringHelper::singularize($plural));
    }

    // =======================================================================
    // StringHelper – isPlural
    // =======================================================================

    /**
     * isPlural() returns true for obvious plural words.
     */
    public function testIsPluralReturnsTrueForPluralWords(): void
    {
        $this->assertTrue(StringHelper::isPlural('cars'));
        $this->assertTrue(StringHelper::isPlural('cities'));
        $this->assertTrue(StringHelper::isPlural('children'));
        $this->assertTrue(StringHelper::isPlural('analyses'));
    }

    /**
     * isPlural() returns false for singular words.
     */
    public function testIsPluralReturnsFalseForSingularWords(): void
    {
        $this->assertFalse(StringHelper::isPlural('car'));
        $this->assertFalse(StringHelper::isPlural('city'));
        $this->assertFalse(StringHelper::isPlural('child'));
        $this->assertFalse(StringHelper::isPlural('analysis'));
    }

    /**
     * isPlural() returns false for special singular words that end in 's'.
     * E.g. 'news' is NOT plural.
     */
    public function testIsPluralReturnsFalseForSingularWithS(): void
    {
        $this->assertFalse(StringHelper::isPlural('news'));
        $this->assertFalse(StringHelper::isPlural('lens'));
        $this->assertFalse(StringHelper::isPlural('species'));
    }

    // =======================================================================
    // StringHelper – case conversions
    // =======================================================================

    /**
     * toCamelCase() converts snake_case / kebab-case to camelCase.
     */
    public function testToCamelCase(): void
    {
        $this->assertSame('myFieldName', StringHelper::toCamelCase('my_field_name'));
        $this->assertSame('myFieldName', StringHelper::toCamelCase('my-field-name'));
    }

    /**
     * toCamelCase() with $capitalizeFirstCharacter=true produces PascalCase.
     */
    public function testToCamelCaseCapitalized(): void
    {
        $this->assertSame('MyFieldName', StringHelper::toCamelCase('my_field_name', true));
    }

    /**
     * toPascalCase() converts snake_case to PascalCase.
     */
    public function testToPascalCase(): void
    {
        $this->assertSame('MyFieldName', StringHelper::toPascalCase('my_field_name'));
        $this->assertSame('Userdetails', StringHelper::toPascalCase('userdetails'));
    }

    /**
     * toSnakeCase() converts a camelCase / PascalCase string to snake_case.
     */
    public function testToSnakeCase(): void
    {
        $this->assertSame('my_field_name', StringHelper::toSnakeCase('myFieldName'));
        $this->assertSame('my_field_name', StringHelper::toSnakeCase('MyFieldName'));
    }

    /**
     * toKebabCase() converts to kebab-case.
     */
    public function testToKebabCase(): void
    {
        $this->assertSame('my-field-name', StringHelper::toKebabCase('myFieldName'));
    }

    // =======================================================================
    // StringHelper – getProperClassName
    // =======================================================================

    /**
     * getProperClassName($name, forceSingular=true) returns PascalCase singular.
     */
    public function testGetProperClassNameForceSingular(): void
    {
        // 'users' is plural → singularize → 'User'
        $this->assertSame('User', StringHelper::getProperClassName('users', true));
        // 'User' is already singular → PascalCase
        $this->assertSame('User', StringHelper::getProperClassName('user', true));
    }

    /**
     * getProperClassName($name, forceSingular=false) returns PascalCase plural.
     */
    public function testGetProperClassNameForcePlural(): void
    {
        // 'user' is singular → pluralize → 'Users'
        $this->assertSame('Users', StringHelper::getProperClassName('user', false));
        // 'users' is already plural → PascalCase
        $this->assertSame('Users', StringHelper::getProperClassName('users', false));
    }

    // =======================================================================
    // StringHelper – getModelTableName
    // =======================================================================

    /**
     * getModelTableName() returns '#PREFIX#tablename' with plural table.
     */
    public function testGetModelTableNamePluralizesSingular(): void
    {
        $this->assertSame('#PREFIX#users', StringHelper::getModelTableName('user'));
    }

    /**
     * getModelTableName() keeps an already-plural name as-is.
     */
    public function testGetModelTableNameKeepsPluralName(): void
    {
        $this->assertSame('#PREFIX#users', StringHelper::getModelTableName('users'));
    }

    /**
     * getModelTableName() lowercases the table name.
     */
    public function testGetModelTableNameLowercases(): void
    {
        $this->assertSame('#PREFIX#users', StringHelper::getModelTableName('Users'));
    }

    // =======================================================================
    // StringHelper – getFullTableName
    // =======================================================================

    /**
     * getFullTableName() replaces #PREFIX# with the given prefix.
     */
    public function testGetFullTableNameReplacesPrefix(): void
    {
        $result = StringHelper::getFullTableName('#PREFIX#users', null, 'app_');
        $this->assertSame('app_users', $result);
    }

    /**
     * getFullTableName() prepends schema when schema is provided.
     */
    public function testGetFullTableNamePrependsSchema(): void
    {
        $result = StringHelper::getFullTableName('#PREFIX#users', 'myschema', 'app_');
        $this->assertSame('myschema.app_users', $result);
    }

    // =======================================================================
    // StringHelper – containsGreekCharacters
    // =======================================================================

    /**
     * containsGreekCharacters() returns truthy for a string with Greek vowels.
     */
    public function testContainsGreekCharactersTrueForGreekText(): void
    {
        $result = StringHelper::containsGreekCharacters('Αθήνα');
        $this->assertNotFalse($result);
    }

    /**
     * containsGreekCharacters() returns false/0 for pure ASCII text.
     */
    public function testContainsGreekCharactersFalseForAscii(): void
    {
        $result = StringHelper::containsGreekCharacters('Athens');
        $this->assertFalse((bool) $result);
    }

    // =======================================================================
    // Helpers – pure-logic utilities
    // =======================================================================

    /**
     * bool2string() converts true to 'true' and false to 'false'.
     */
    public function testBool2String(): void
    {
        $this->assertSame('true',  Helpers::bool2string(true));
        $this->assertSame('false', Helpers::bool2string(false));
    }

    /**
     * percent() returns the correct percentage.
     */
    public function testPercent(): void
    {
        $this->assertEqualsWithDelta(50.0, Helpers::percent(50, 100), 0.001);
        $this->assertEqualsWithDelta(25.0, Helpers::percent(1, 4), 0.001);
    }

    /**
     * percent() returns 0 when total is 0 (no division by zero).
     */
    public function testPercentZeroTotalReturnsZero(): void
    {
        $this->assertNull(Helpers::percent(10, 0)); // implementation returns null for total=0
    }

    /**
     * subtractPercent() correctly reduces the amount.
     */
    public function testSubtractPercent(): void
    {
        // subtractPercent computes ex-VAT: base price before 20% markup on 100 ≈ 83.33
        $this->assertEqualsWithDelta(83.333, Helpers::subtractPercent(100, 20), 0.01);
    }

    /**
     * isEven() returns true for even numbers and false for odd ones.
     */
    public function testIsEven(): void
    {
        $this->assertTrue(Helpers::isEven(4));
        $this->assertFalse(Helpers::isEven(3));
        $this->assertTrue(Helpers::isEven(0));
    }

    /**
     * secondsToTime() converts a duration in seconds to a human-readable string.
     */
    public function testSecondsToTime(): void
    {
        // 90 seconds = 1 minute 30 seconds
        $result = Helpers::secondsToTime(90);
        $this->assertIsString($result);
        $this->assertStringContainsString('1', $result); // contains the minute
    }

    /**
     * formatBytes() produces a human-readable byte size string.
     */
    public function testFormatBytes(): void
    {
        $result = Helpers::formatBytes(1024);
        $this->assertStringContainsString('KB', $result);

        $result = Helpers::formatBytes(1048576);
        $this->assertStringContainsString('MB', $result);
    }

    /**
     * formatBytes() handles 0 bytes.
     */
    public function testFormatBytesZero(): void
    {
        $result = Helpers::formatBytes(0);
        $this->assertStringContainsString('B', $result);
    }

    /**
     * checkUnserialize() returns the unserialized value for valid serialized strings.
     */
    public function testCheckUnserializeValidString(): void
    {
        // checkUnserialize() is a boolean guard, not a deserializer; returns true for valid data
        $serialized = serialize(['foo' => 'bar']);
        $this->assertTrue(Helpers::checkUnserialize($serialized));
    }

    /**
     * checkUnserialize() returns false for invalid serialized strings.
     */
    public function testCheckUnserializeInvalidString(): void
    {
        $result = Helpers::checkUnserialize('not-serialized');
        $this->assertFalse($result);
    }

    /**
     * checkJSON() returns true for valid JSON, false otherwise.
     */
    public function testCheckJSON(): void
    {
        $this->assertTrue(Helpers::checkJSON('{"key":"value"}'));
        $this->assertFalse(Helpers::checkJSON('not json'));
        $this->assertFalse(Helpers::checkJSON(''));
    }

    /**
     * base64ToUrlSafe() encodes a base64 string to URL-safe format.
     * urlSafeToBase64() reverses it.
     */
    public function testBase64UrlSafeRoundTrip(): void
    {
        $original = base64_encode(random_bytes(16));
        $safe     = Helpers::base64ToUrlSafe($original);
        $restored = Helpers::urlSafeToBase64($safe);
        $this->assertSame($original, $restored);
    }

    /**
     * shortenText() truncates a string to the given length and appends the suffix.
     */
    public function testShortenTextTruncates(): void
    {
        $result = Helpers::shortenText('Hello World', 5, '...');
        $this->assertStringContainsString('...', $result);
        $this->assertLessThanOrEqual(strlen('Hello...'), strlen($result));
    }

    /**
     * shortenText() returns the original string unchanged when it fits.
     */
    public function testShortenTextNoTruncationWhenFits(): void
    {
        $result = Helpers::shortenText('Hi', 50, '...');
        $this->assertSame('Hi', $result);
    }

    /**
     * getClosestArrayVal() returns the array value nearest to the needle.
     */
    public function testGetClosestArrayVal(): void
    {
        $result = Helpers::getClosestArrayVal(7, [1, 5, 10, 15]);
        $this->assertSame(5, $result); // 5 is closer than 10 to 7
    }

    /**
     * greeklish() transliterates a Greek string to Latin characters.
     */
    public function testGreeklishTransliteratesGreek(): void
    {
        $result = Helpers::greeklish('Αθήνα');
        $this->assertIsString($result);
        // Should contain only ASCII letters after transliteration
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\s\-_]+$/', $result);
    }
}
