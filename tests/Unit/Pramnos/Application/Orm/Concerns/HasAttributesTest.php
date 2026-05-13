<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Concerns\HasAttributes;

/**
 * Unit tests for Pramnos\Application\Orm\Concerns\HasAttributes.
 *
 * HasAttributes is a trait that provides three orthogonal features tested here:
 *
 * 1. Mass assignment — fill() + isFillable() + isGuarded() with $fillable
 *    (allow-list), $guarded (deny-list), and $guarded=['*'] (block-all) policies.
 * 2. Casting — castAttribute() converts values to declared types; decastAttribute()
 *    reverses the conversion for storage; null is always returned as-is.
 * 3. Accessor / Mutator resolution — getAccessorValue() calls getXxxAttribute()
 *    when defined; getMutatorValue() calls setXxxAttribute() when defined.
 *    studly() converts snake_case keys to StudlyCase method name fragments.
 *
 * All tests use an anonymous class that `use HasAttributes` and exposes protected
 * helpers as public methods so they can be exercised from outside.
 */
#[CoversClass(HasAttributes::class)]
class HasAttributesTest extends TestCase
{
    // =========================================================================
    // Test fixture
    // =========================================================================

    /**
     * Build a minimal object that uses the trait.
     *
     * @param array<string,string> $casts    Cast map to configure on the object.
     * @param string[]             $fillable Allow-list (overrides guarded).
     * @param string[]             $guarded  Deny-list (defaults to ['*']).
     */
    private function makeModel(
        array $casts    = [],
        array $fillable = [],
        array $guarded  = ['*']
    ): object {
        return new class($casts, $fillable, $guarded) {
            use HasAttributes;

            // Declare properties that fill() may set so PHP 8.4 does not warn
            // about dynamic property creation during mass-assignment tests.
            public ?string $name     = null;
            public ?string $email    = null;

            public function __construct(array $casts, array $fillable, array $guarded)
            {
                $this->casts    = $casts;
                $this->fillable = $fillable;
                $this->guarded  = $guarded;
            }

            // Expose protected methods as public for testing
            public function exposeCast(string $key, mixed $value): mixed
            {
                return $this->castAttribute($key, $value);
            }

            public function exposeDecast(string $key, mixed $value): mixed
            {
                return $this->decastAttribute($key, $value);
            }

            public function exposeHasCast(string $key): bool
            {
                return $this->hasCast($key);
            }

            public function exposeStudly(string $key): string
            {
                return $this->studly($key);
            }

            public function exposeGetAccessor(string $key, mixed $raw): array
            {
                return $this->getAccessorValue($key, $raw);
            }

            public function exposeGetMutator(string $key, mixed $value): array
            {
                return $this->getMutatorValue($key, $value);
            }
        };
    }

    // =========================================================================
    // isFillable / isGuarded
    // =========================================================================

    /**
     * When $fillable is non-empty, only listed keys may be filled.
     * Any key not in the list is guarded, regardless of $guarded.
     */
    public function testIsFillableReturnsTrueForAllowedKeyInFillable(): void
    {
        // Arrange
        $m = $this->makeModel(fillable: ['name', 'email'], guarded: []);

        // Act / Assert — listed key
        $this->assertTrue($m->isFillable('name'));
        $this->assertTrue($m->isFillable('email'));

        // Not listed — blocked even though $guarded is empty
        $this->assertFalse($m->isFillable('password'));
    }

    /**
     * When $fillable is empty and $guarded=['*'], everything is guarded.
     */
    public function testIsFillableReturnsFalseWhenGuardedStar(): void
    {
        // Arrange — default: guarded=['*'], fillable=[]
        $m = $this->makeModel();

        // Assert — nothing is fillable
        $this->assertFalse($m->isFillable('name'));
        $this->assertFalse($m->isFillable('email'));
    }

    /**
     * When $fillable is empty and $guarded=[], everything not in guarded is fillable.
     */
    public function testIsFillableReturnsTrueWhenGuardedEmpty(): void
    {
        // Arrange — open: nothing guarded
        $m = $this->makeModel(fillable: [], guarded: []);

        // Assert — any key is fillable
        $this->assertTrue($m->isFillable('anything'));
    }

    /**
     * When $fillable is empty and a specific key is in $guarded, that key
     * is blocked while others are allowed.
     */
    public function testIsFillableBlocksKeyInGuardedList(): void
    {
        // Arrange — only 'id' is guarded
        $m = $this->makeModel(fillable: [], guarded: ['id']);

        // Assert
        $this->assertFalse($m->isFillable('id'));
        $this->assertTrue($m->isFillable('name'));
    }

    /**
     * isGuarded() is the boolean inverse of isFillable().
     */
    public function testIsGuardedIsInverseOfIsFillable(): void
    {
        // Arrange
        $m = $this->makeModel(fillable: ['name']);

        // Assert — 'name' fillable → not guarded; 'id' not fillable → guarded
        $this->assertFalse($m->isGuarded('name'));
        $this->assertTrue($m->isGuarded('id'));
    }

    // =========================================================================
    // fill()
    // =========================================================================

    /**
     * fill() sets fillable attributes on the object and returns $this.
     */
    public function testFillSetsAllowedAttributesAndReturnsSelf(): void
    {
        // Arrange — fillable=['name', 'email']
        $m = $this->makeModel(fillable: ['name', 'email']);

        // Act
        $result = $m->fill(['name' => 'Alice', 'email' => 'a@b.com', 'id' => 99]);

        // Assert — fillable attributes set
        $this->assertSame('Alice', $m->name);
        $this->assertSame('a@b.com', $m->email);

        // Assert — guarded attribute NOT set
        $this->assertFalse(isset($m->id));

        // Assert — returns $this for chaining
        $this->assertSame($m, $result);
    }

    /**
     * fill() with guarded='*' sets nothing.
     */
    public function testFillSetsNothingWhenAllGuarded(): void
    {
        // Arrange — default: all guarded
        $m = $this->makeModel();

        // Act
        $m->fill(['name' => 'Alice']);

        // Assert — property not set
        $this->assertFalse(isset($m->name));
    }

    // =========================================================================
    // castAttribute()
    // =========================================================================

    /**
     * castAttribute() returns null unchanged regardless of the declared type.
     */
    public function testCastAttributeReturnsNullForNullValue(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['n' => 'int']);

        // Assert
        $this->assertNull($m->exposeCast('n', null));
    }

    /** @return array<string, array{string, mixed, mixed}> */
    public static function castProvider(): array
    {
        return [
            'int'      => ['int',      '42',   42],
            'integer'  => ['integer',  '7',    7],
            'float'    => ['float',    '3.14', 3.14],
            'double'   => ['double',   '2.71', 2.71],
            'bool'     => ['bool',     1,      true],
            'boolean'  => ['boolean',  0,      false],
            'string'   => ['string',   123,    '123'],
            'array'    => ['array',    '{"a":1}', ['a' => 1]],
            'json'     => ['json',     '{"b":2}', ['b' => 2]],
            'default'  => ['unknown',  'raw',  'raw'],
        ];
    }

    /**
     * castAttribute() converts values to the declared type.
     * 'array'/'json' decode JSON strings; 'unknown' passes the value through.
     *
     * @param string $type     The cast type declared in $casts.
     * @param mixed  $input    The raw value to cast.
     * @param mixed  $expected The expected cast output.
     */
    #[DataProvider('castProvider')]
    public function testCastAttributeCastsToExpectedType(string $type, mixed $input, mixed $expected): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['field' => $type]);

        // Act
        $result = $m->exposeCast('field', $input);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * castAttribute() with 'datetime' type returns a DateTimeImmutable for a
     * date string.
     */
    public function testCastAttributeDatetimeReturnsDateTimeImmutable(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['ts' => 'datetime']);

        // Act
        $result = $m->exposeCast('ts', '2024-01-15 12:00:00');

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    /**
     * castAttribute() with 'datetime' returns a DateTimeInterface unchanged
     * when the value is already a DateTimeInterface.
     */
    public function testCastAttributeDatetimePassesThroughExistingDateTimeInterface(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['ts' => 'datetime']);
        $dt = new \DateTimeImmutable('2024-06-01');

        // Act
        $result = $m->exposeCast('ts', $dt);

        // Assert — same object reference returned
        $this->assertSame($dt, $result);
    }

    /**
     * castAttribute() with 'timestamp' converts a date string to a Unix integer.
     */
    public function testCastAttributeTimestampConvertsStringToInt(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['ts' => 'timestamp']);

        // Act
        $result = $m->exposeCast('ts', '2024-01-01 00:00:00');

        // Assert — result is an integer (Unix timestamp)
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    /**
     * castAttribute() with 'timestamp' and a numeric string passes it through as int.
     */
    public function testCastAttributeTimestampPassesThroughNumericValue(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['ts' => 'timestamp']);

        // Act
        $result = $m->exposeCast('ts', 1234567890);

        // Assert — already numeric → cast to int
        $this->assertSame(1234567890, $result);
    }

    /**
     * castAttribute() with 'array'/'json' and a non-string (already an array)
     * casts it to array directly.
     */
    public function testCastAttributeJsonWithArrayInputCastsToArray(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['data' => 'json']);

        // Act — pass an object (non-string), triggers (array)$value branch
        $result = $m->exposeCast('data', ['x' => 1]);

        // Assert
        $this->assertSame(['x' => 1], $result);
    }

    /**
     * castAttribute() for an undeclared key returns the value unchanged.
     */
    public function testCastAttributeWithNoCastDeclarationReturnsRawValue(): void
    {
        // Arrange — no casts declared
        $m = $this->makeModel();

        // Act
        $result = $m->exposeCast('anything', 'rawValue');

        // Assert
        $this->assertSame('rawValue', $result);
    }

    // =========================================================================
    // decastAttribute()
    // =========================================================================

    /**
     * decastAttribute() returns null unchanged.
     */
    public function testDecastAttributeReturnsNullForNullValue(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['n' => 'int']);

        // Assert
        $this->assertNull($m->exposeDecast('n', null));
    }

    /**
     * decastAttribute() serializes arrays to JSON for 'array'/'json' fields.
     */
    public function testDecastAttributeSerializesArrayToJson(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['data' => 'json']);

        // Act
        $result = $m->exposeDecast('data', ['key' => 'val']);

        // Assert
        $this->assertSame('{"key":"val"}', $result);
    }

    /**
     * decastAttribute() with 'array' and a non-array value returns it as-is.
     */
    public function testDecastAttributeJsonWithNonArrayReturnsValueUnchanged(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['data' => 'array']);

        // Act — passing a non-array (already a string) → returned as-is
        $result = $m->exposeDecast('data', 'already a string');

        // Assert
        $this->assertSame('already a string', $result);
    }

    /**
     * decastAttribute() formats DateTimeInterface as 'Y-m-d H:i:s' for datetime fields.
     */
    public function testDecastAttributeDatetimeFormatsDateTime(): void
    {
        // Arrange
        $m  = $this->makeModel(casts: ['ts' => 'datetime']);
        $dt = new \DateTimeImmutable('2024-03-15 09:30:00');

        // Act
        $result = $m->exposeDecast('ts', $dt);

        // Assert
        $this->assertSame('2024-03-15 09:30:00', $result);
    }

    /**
     * decastAttribute() for a 'datetime' field with a non-DateTime value returns it unchanged.
     */
    public function testDecastAttributeDatetimeWithNonDateTimeReturnsValueUnchanged(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['ts' => 'datetime']);

        // Act
        $result = $m->exposeDecast('ts', '2024-01-01');

        // Assert
        $this->assertSame('2024-01-01', $result);
    }

    /**
     * decastAttribute() for 'timestamp' extracts the Unix int from a DateTimeInterface.
     */
    public function testDecastAttributeTimestampExractsUnixTimestamp(): void
    {
        // Arrange
        $m  = $this->makeModel(casts: ['ts' => 'timestamp']);
        $dt = new \DateTimeImmutable('@1234567890');

        // Act
        $result = $m->exposeDecast('ts', $dt);

        // Assert
        $this->assertSame(1234567890, $result);
    }

    /**
     * decastAttribute() for an undeclared key returns the value unchanged.
     */
    public function testDecastAttributeWithNoCastReturnsRawValue(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposeDecast('x', 'hello');

        // Assert
        $this->assertSame('hello', $result);
    }

    // =========================================================================
    // hasCast()
    // =========================================================================

    /**
     * hasCast() returns true only when the key is declared in $casts.
     */
    public function testHasCastReturnsTrueForDeclaredKey(): void
    {
        // Arrange
        $m = $this->makeModel(casts: ['age' => 'int']);

        // Assert
        $this->assertTrue($m->exposeHasCast('age'));
        $this->assertFalse($m->exposeHasCast('name'));
    }

    // =========================================================================
    // studly()
    // =========================================================================

    /** @return array<string, array{string, string}> */
    public static function studlyProvider(): array
    {
        return [
            'single word'       => ['name',       'Name'],
            'two words'         => ['first_name',  'FirstName'],
            'three words'       => ['some_key_name', 'SomeKeyName'],
            'no underscore'     => ['x',           'X'],
        ];
    }

    /**
     * studly() converts snake_case attribute names to StudlyCase, matching the
     * accessor/mutator naming convention (e.g. 'first_name' → 'FirstName').
     *
     * @param string $input    Snake-case field name.
     * @param string $expected Expected StudlyCase fragment.
     */
    #[DataProvider('studlyProvider')]
    public function testStudlyConvertsSnakeCaseToStudlyCase(string $input, string $expected): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposeStudly($input);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // getAccessorValue() / getMutatorValue()
    // =========================================================================

    /**
     * getAccessorValue() returns [false, rawValue] when no accessor method exists.
     */
    public function testGetAccessorValueReturnsFalseWhenNoAccessorDefined(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        [$has, $value] = $m->exposeGetAccessor('name', 'raw');

        // Assert — no accessor found
        $this->assertFalse($has);
        $this->assertSame('raw', $value);
    }

    /**
     * getAccessorValue() detects and calls a getXxxAttribute() method when present.
     */
    public function testGetAccessorValueCallsAccessorMethodWhenDefined(): void
    {
        // Arrange — anonymous class with a getNameAttribute() accessor
        $m = new class {
            use HasAttributes;

            public function exposeGetAccessor(string $key, mixed $raw): array
            {
                return $this->getAccessorValue($key, $raw);
            }

            public function getNameAttribute(mixed $value): string
            {
                return strtoupper((string) $value);
            }
        };

        // Act
        [$has, $value] = $m->exposeGetAccessor('name', 'alice');

        // Assert — accessor called; result transformed
        $this->assertTrue($has);
        $this->assertSame('ALICE', $value);
    }

    /**
     * getMutatorValue() returns [false, value] when no mutator method is defined.
     */
    public function testGetMutatorValueReturnsFalseWhenNoMutatorDefined(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        [$has, $value] = $m->exposeGetMutator('name', 'raw');

        // Assert
        $this->assertFalse($has);
        $this->assertSame('raw', $value);
    }

    /**
     * getMutatorValue() detects and calls a setXxxAttribute() method when present.
     */
    public function testGetMutatorValueCallsMutatorMethodWhenDefined(): void
    {
        // Arrange — anonymous class with a setEmailAttribute() mutator
        $m = new class {
            use HasAttributes;

            public function exposeGetMutator(string $key, mixed $value): array
            {
                return $this->getMutatorValue($key, $value);
            }

            public function setEmailAttribute(mixed $value): string
            {
                return strtolower((string) $value);
            }
        };

        // Act
        [$has, $value] = $m->exposeGetMutator('email', 'ALICE@EXAMPLE.COM');

        // Assert
        $this->assertTrue($has);
        $this->assertSame('alice@example.com', $value);
    }
}
