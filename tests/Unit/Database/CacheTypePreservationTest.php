<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Unit tests for Database cache type-preservation helpers.
 *
 * These methods are private, so we access them via ReflectionMethod.
 * The invariant being tested: data that enters prepareDataForCache() and then
 * passes through restoreDataFromCache() must be byte-for-byte identical to
 * the original — including empty strings, zero integers, false booleans and
 * null values.
 */
class CacheTypePreservationTest extends TestCase
{
    /** @var Database&\PHPUnit\Framework\MockObject\MockObject */
    private Database $db;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $castToType;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $getSimpleType;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $prepareDataForCache;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $restoreDataFromCache;

    protected function setUp(): void
    {
        $this->db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();

        $ref = new \ReflectionClass(Database::class);

        foreach (['castToType', 'getSimpleType', 'prepareDataForCache', 'restoreDataFromCache'] as $name) {
            $m = $ref->getMethod($name);
            $this->$name = $m;
        }
    }

    // =========================================================================
    // castToType — the key invariants
    // =========================================================================

    /**
     * PHP null must always round-trip to null regardless of declared type.
     * The cache serialises null as PHP null, so this should never change.
     */
    public function testCastToTypeNullValueAlwaysReturnsNull(): void
    {
        // Arrange
        $types = ['n', 'i', 'f', 's', 'b'];

        foreach ($types as $type) {
            // Act
            $result = $this->castToType->invoke($this->db, null, $type);

            // Assert — null must survive for every declared column type
            $this->assertNull($result, "Expected null for type '$type' with null value");
        }
    }

    /**
     * The string literal 'null' (can appear in legacy data) must also cast to null.
     * This prevents the string 'null' leaking into application code as a string.
     */
    public function testCastToTypeStringNullLiteralReturnsNull(): void
    {
        // Arrange / Act / Assert
        $this->assertNull($this->castToType->invoke($this->db, 'null', 's'));
        $this->assertNull($this->castToType->invoke($this->db, 'null', 'i'));
    }

    /**
     * Empty string '' is a valid value for string (VARCHAR) columns.
     * It must NOT become null after a cache round-trip.
     * Bug that was fixed: the old code had $value === '' → return null before
     * the switch, which silently nulled every empty-string field on restore.
     */
    public function testCastToTypeEmptyStringForStringTypeReturnsEmptyString(): void
    {
        // Arrange
        $value = '';

        // Act
        $result = $this->castToType->invoke($this->db, $value, 's');

        // Assert — empty string is a valid string value, must survive
        $this->assertSame('', $result, "Empty string must not become null for type 's'");
    }

    /**
     * Empty string for integer type is not a valid integer — must become null.
     * This matches the behaviour of inserting '' into an integer column (DB error
     * or coercion to 0), so null is the safest representation.
     */
    public function testCastToTypeEmptyStringForIntTypeReturnsNull(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, '', 'i');

        // Assert — '' is not a number, must become null
        $this->assertNull($result);
    }

    /**
     * Empty string for float type must also become null for the same reason.
     */
    public function testCastToTypeEmptyStringForFloatTypeReturnsNull(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, '', 'f');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Integer zero must round-trip correctly.
     * Old code: '' was treated as null but 0 (int) was fine; verify 0 stays 0.
     */
    public function testCastToTypeZeroIntegerRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, 0, 'i');

        // Assert — zero is a valid integer, not null
        $this->assertSame(0, $result);
    }

    /**
     * False boolean must round-trip correctly.
     * false is a valid boolean value distinct from null.
     */
    public function testCastToTypeFalseBooleanRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, false, 'b');

        // Assert — false must stay false
        $this->assertSame(false, $result);
        $this->assertNotNull($result);
    }

    /**
     * True boolean must round-trip correctly.
     */
    public function testCastToTypeTrueBooleanRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, true, 'b');

        // Assert
        $this->assertSame(true, $result);
    }

    /**
     * A normal integer must survive the cast.
     */
    public function testCastToTypePositiveIntegerRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, 142970, 'i');

        // Assert
        $this->assertSame(142970, $result);
    }

    /**
     * A normal float must survive the cast.
     */
    public function testCastToTypeFloatRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, 3.14, 'f');

        // Assert
        $this->assertSame(3.14, $result);
    }

    /**
     * A non-empty string must survive the cast unchanged.
     */
    public function testCastToTypeStringRoundTrips(): void
    {
        // Arrange / Act
        $result = $this->castToType->invoke($this->db, '6912345678', 's');

        // Assert — phone numbers and other numeric-looking strings must stay strings
        $this->assertSame('6912345678', $result);
        $this->assertIsString($result);
    }

    // =========================================================================
    // getSimpleType — type code assignment from PHP native values
    // =========================================================================

    /**
     * getSimpleType uses PHP native types (is_int, is_float etc.), NOT is_numeric.
     * This is intentional: fetchAll() already converts DB values to proper PHP
     * types before caching, so getSimpleType sees PHP ints/floats, not strings.
     */
    public function testGetSimpleTypeReturnsNForNull(): void
    {
        $this->assertSame('n', $this->getSimpleType->invoke($this->db, null));
    }

    public function testGetSimpleTypeReturnsIForInt(): void
    {
        $this->assertSame('i', $this->getSimpleType->invoke($this->db, 42));
    }

    public function testGetSimpleTypeReturnsIForZero(): void
    {
        $this->assertSame('i', $this->getSimpleType->invoke($this->db, 0));
    }

    public function testGetSimpleTypeReturnsFForFloat(): void
    {
        $this->assertSame('f', $this->getSimpleType->invoke($this->db, 3.14));
    }

    public function testGetSimpleTypeReturnsBForBool(): void
    {
        $this->assertSame('b', $this->getSimpleType->invoke($this->db, true));
        $this->assertSame('b', $this->getSimpleType->invoke($this->db, false));
    }

    /**
     * A numeric-looking string (e.g. a phone number or a raw PostgreSQL value
     * that fetchAll() did not convert) must be classified as 's', NOT 'i'.
     * This prevents phone numbers and other text fields from being cast to int.
     */
    public function testGetSimpleTypeReturnsStringForNumericString(): void
    {
        // Arrange — a phone number that happens to be numeric
        $phone = '6912345678';

        // Act
        $type = $this->getSimpleType->invoke($this->db, $phone);

        // Assert — must be classified as string, NOT integer
        $this->assertSame('s', $type,
            "Numeric-looking string '$phone' must be classified as 's', not 'i'. " .
            "getSimpleType uses PHP native types, not is_numeric()."
        );
    }

    public function testGetSimpleTypeReturnsStringForEmptyString(): void
    {
        $this->assertSame('s', $this->getSimpleType->invoke($this->db, ''));
    }

    // =========================================================================
    // Full round-trip: prepareDataForCache → restoreDataFromCache
    // =========================================================================

    /**
     * A complete row with mixed types must survive a prepare/restore cycle with
     * all values and types preserved exactly.
     * This is the critical invariant: the cache layer must be transparent.
     */
    public function testFullRoundTripPreservesAllTypes(): void
    {
        // Arrange — simulate a DB row with varied PHP-typed values
        $originalData = [
            [
                'id'          => 142970,      // PHP int
                'amount'      => 3.14,        // PHP float
                'active'      => true,        // PHP bool
                'name'        => 'Test',      // non-empty string
                'description' => '',          // empty string — the key bug case
                'deleted_at'  => null,        // null
            ],
        ];

        // Act — simulate cacheStore then cacheRead
        $prepared  = $this->prepareDataForCache->invoke($this->db, $originalData);
        $restored  = $this->restoreDataFromCache->invoke($this->db, $prepared);

        // Assert — every field must be identical to the original
        $this->assertSame($originalData[0]['id'],          $restored[0]['id'],
            'Integer field must survive round-trip');
        $this->assertSame($originalData[0]['amount'],      $restored[0]['amount'],
            'Float field must survive round-trip');
        $this->assertSame($originalData[0]['active'],      $restored[0]['active'],
            'Boolean field must survive round-trip');
        $this->assertSame($originalData[0]['name'],        $restored[0]['name'],
            'String field must survive round-trip');
        $this->assertSame($originalData[0]['description'], $restored[0]['description'],
            'Empty string must NOT become null after round-trip (was the bug)');
        $this->assertNull($restored[0]['deleted_at'],
            'Null field must remain null after round-trip');
    }

    /**
     * A phone number stored in a VARCHAR column must survive as a string.
     * This verifies that getSimpleType correctly identifies it as 's'
     * (not 'i' via is_numeric), so castToType returns the original string.
     */
    public function testPhoneNumberRoundTripsAsString(): void
    {
        // Arrange — phone number looks numeric but is a VARCHAR field
        $originalData = [
            ['phone' => '6912345678'],
        ];

        // Act
        $prepared = $this->prepareDataForCache->invoke($this->db, $originalData);
        $restored = $this->restoreDataFromCache->invoke($this->db, $prepared);

        // Assert — must remain a string, not become int 6912345678
        $this->assertSame('6912345678', $restored[0]['phone']);
        $this->assertIsString($restored[0]['phone'],
            'Phone number must remain a string after cache round-trip');
    }

    /**
     * Zero integer in a numeric column must not be confused with null or false.
     */
    public function testZeroIntegerRoundTripsCorrectly(): void
    {
        // Arrange
        $originalData = [
            ['count' => 0, 'score' => 0.0],
        ];

        // Act
        $prepared = $this->prepareDataForCache->invoke($this->db, $originalData);
        $restored = $this->restoreDataFromCache->invoke($this->db, $prepared);

        // Assert — zero must not become null
        $this->assertSame(0,   $restored[0]['count'], 'Integer 0 must not become null');
        $this->assertSame(0.0, $restored[0]['score'], 'Float 0.0 must not become null');
    }
}
