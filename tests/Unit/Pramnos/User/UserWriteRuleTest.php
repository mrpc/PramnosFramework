<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\User;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\User\User;

/**
 * Which columns an UPDATE of a loaded user writes, for every type a column comes back as and a
 * caller sets: strings, numbers as text or as numbers, booleans (PostgreSQL gives true/false,
 * MySQL '1'/'0'), floats and null.
 *
 * The rule has two ways to be wrong, and they are not equal. Writing a column that did not need it
 * costs one column in the UPDATE. Not writing one that did loses the caller's value — so every
 * case where the value really changed must be written, and only a value that means the same
 * thing may be skipped.
 */
final class UserWriteRuleTest extends TestCase
{
    /**
     * Whether one column is written, given what the object held when it loaded and what it holds now.
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: bool}> loaded, now, written
     */
    public static function cases(): array
    {
        return [
            // Untouched — the point of the safeguard
            'same string'                         => ['abc', 'abc', false],
            'both null'                           => [null, null, false],
            'same bool'                           => [true, true, false],
            // The same value in another type: no change
            'int set over the same as text'       => ['5', 5, false],
            'float set over the same as text'     => ['1', 1.0, false],
            'true over MySQL 1'                   => ['1', true, false],
            'PostgreSQL true, set 1'              => [true, 1, false],
            // Real changes — each must be written
            'string changed'                      => ['abc', 'abd', true],
            'leading zero added'                  => ['690', '0690', true],
            'equal as numbers, not as text'       => ['1.0', '1', true],
            'null to empty'                       => [null, '', true],
            'empty to null'                       => ['', null, true],
            'null to zero'                        => [null, 0, true],
            'zero to null'                        => ['0', null, true],
            'PostgreSQL true, set false'          => [true, false, true],
            'PostgreSQL false, set true'          => [false, true, true],
            'PostgreSQL true, set 0'              => [true, 0, true],
            'MySQL 1, set false'                  => ['1', false, true],
            'MySQL 0, set true'                   => ['0', true, true],
            'float changed'                       => ['1.5', 1.25, true],
            'zero to empty'                       => ['0', '', true],
            // The same meaning, written anyway — harmless, the value is the same
            'PostgreSQL false, set 0'             => [false, 0, true],
            'MySQL 0, set false'                  => ['0', false, true],
            'same array'                          => [[1], [1], false],
            'array changed'                       => [[1], [2], true],
        ];
    }

    /**
     * Every type combination is written when its value changed, and skipped only when it did not.
     */
    #[DataProvider('cases')]
    public function testAColumnIsWrittenExactlyWhenItsValueChanged(mixed $loaded, mixed $now, bool $written): void
    {
        // Arrange — a loaded user whose `phone` held $loaded
        $user = new User();
        (new \ReflectionProperty(User::class, '_loadedValues'))->setValue($user, ['phone' => $loaded, 'email' => 'x']);

        // Act
        $out = (new \ReflectionMethod(User::class, 'writtenOnUpdate'))
            ->invoke($user, [['fieldName' => 'phone', 'value' => $now, 'type' => 'string']]);

        // Assert
        $this->assertSame($written, $out !== [], var_export($loaded, true) . ' → ' . var_export($now, true));
    }

    /**
     * A column the load did not see is written when the object holds a value for it, and not when
     * it holds null — writing that null is how a column nobody read is blanked.
     */
    public function testAColumnTheLoadDidNotSeeIsWrittenOnlyWithAValue(): void
    {
        // Arrange
        $user = new User();
        (new \ReflectionProperty(User::class, '_loadedValues'))->setValue($user, ['email' => 'x']);
        $write = new \ReflectionMethod(User::class, 'writtenOnUpdate');

        // Act
        $withValue = $write->invoke($user, [['fieldName' => 'phone', 'value' => '', 'type' => 'string']]);
        $withNull  = $write->invoke($user, [['fieldName' => 'phone', 'value' => null, 'type' => 'string']]);

        // Assert
        $this->assertCount(1, $withValue, 'a value the code set, even empty, is written');
        $this->assertSame([], $withNull, 'null over a column nobody loaded is not');
    }

    /**
     * With no baseline at all — a subclass whose own load() does not go through the base class's —
     * every column is written, as before the safeguard.
     */
    public function testWithNoBaselineEveryColumnIsWritten(): void
    {
        // Arrange
        $user = new User();
        $items = [['fieldName' => 'phone', 'value' => null, 'type' => 'string'], ['fieldName' => 'email', 'value' => 'x', 'type' => 'string']];

        // Act
        $out = (new \ReflectionMethod(User::class, 'writtenOnUpdate'))->invoke($user, $items);

        // Assert
        $this->assertSame($items, $out);
    }
}
