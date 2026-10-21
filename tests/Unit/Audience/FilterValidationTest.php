<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Filter;
use Pramnos\Database\Database;

/**
 * A filter is checked when it is made, so a filter that loads always compiles: every refusal
 * names the condition's path, and accepted values are cast to what the operator compares.
 * Also the attribute registry: replacement by key, abilities, labels and lazy choices.
 */
#[CoversClass(Filter::class)]
#[CoversClass(Attribute::class)]
#[CoversClass(Attributes::class)]
class FilterValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        Attributes::reset();
    }

    /** A filter over one condition, on an unconnected database: nothing here runs a query. */
    private static function make(array $condition): Filter
    {
        return Filter::fromArray(['conditions' => [$condition]], new Database());
    }

    /** @return array<string, array{mixed, string}> */
    public static function refused(): array
    {
        return [
            'unknown attribute'      => [['attribute' => 'shoe_size', 'op' => 'is', 'value' => 1], 'filter.0: no attribute shoe_size.'],
            'operator of other type' => [['attribute' => 'usertype', 'op' => 'contains', 'value' => 'x'], 'filter.0: usertype has no operator contains.'],
            'not a number'           => [['attribute' => 'usertype', 'op' => 'is', 'value' => 'two'], 'filter.0: two is not a number.'],
            'no value'               => [['attribute' => 'language', 'op' => 'is'], 'filter.0: is needs a value.'],
            'array where one value'  => [['attribute' => 'language', 'op' => 'is', 'value' => ['el']], 'filter.0: is needs a value.'],
            'empty list'             => [['attribute' => 'language', 'op' => 'one_of', 'value' => []], 'filter.0: one_of needs at least one value.'],
            'between one value'      => [['attribute' => 'usertype', 'op' => 'between', 'value' => [1]], 'filter.0: between needs two values.'],
            'not a day'              => [['attribute' => 'regdate', 'op' => 'after', 'value' => '21/10/2026'], 'filter.0: a day is written YYYY-MM-DD.'],
            'impossible day'         => [['attribute' => 'regdate', 'op' => 'before', 'value' => '2026-02-30'], 'filter.0: a day is written YYYY-MM-DD.'],
            'negative days'          => [['attribute' => 'lastlogin', 'op' => 'less_than_days_ago', 'value' => -1], 'filter.0: days are a whole number, zero or more.'],
            'fractional days'        => [['attribute' => 'lastlogin', 'op' => 'less_than_days_ago', 'value' => 1.5], 'filter.0: days are a whole number, zero or more.'],
            'not a condition'        => ['usertype', 'filter.0: not a condition.'],
            'segment not an id'      => [['segment' => 'vip', 'op' => 'in'], 'filter.0: a segment is named by its id.'],
            'segment zero'           => [['segment' => 0, 'op' => 'in'], 'filter.0: a segment is named by its id.'],
            'segment operator'       => [['segment' => 3, 'op' => 'is'], 'filter.0: a segment condition is in or not_in.'],
        ];
    }

    /**
     * Each malformed condition is refused with a message that says what is wrong and where, so
     * the builder can put it beside the condition.
     */
    #[DataProvider('refused')]
    public function testAMalformedConditionIsRefusedWithItsPath(mixed $condition, string $message): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        // Act
        Filter::fromArray(['conditions' => [$condition]], new Database());

        // Assert — the expectation above.
    }

    /**
     * A segment condition is kept as an id and in / not_in — "in" when the operator is left out —
     * and segmentsIn() finds every segment a filter names, nested ones included, once each.
     */
    public function testSegmentConditionsAreFoundWhereverTheyNest(): void
    {
        // Arrange
        $filter = Filter::fromArray(['conditions' => [
            ['segment' => '4'],
            ['match' => 'any', 'conditions' => [
                ['segment' => 9, 'op' => 'not_in'],
                ['segment' => 4, 'op' => 'in'],
                ['attribute' => 'language', 'op' => 'is', 'value' => 'el'],
            ]],
        ]], new Database());

        // Act
        $named = Filter::segmentsIn($filter->toArray());

        // Assert
        $this->assertSame(['segment' => 4, 'op' => 'in'], $filter->toArray()['conditions'][0]);
        $this->assertSame([4, 9], $named);
        $this->assertSame([], Filter::segmentsIn([]));
    }

    /**
     * A one-to-many table matched on something other than userid — the opt-outs, keyed on the
     * address — cannot be broken down per value, and says so rather than joining wrongly.
     */
    public function testABreakdownOfATableMatchedOnTheAddressIsRefused(): void
    {
        // Arrange
        $filter = Filter::fromArray([], new Database());
        $this->expectExceptionMessage('email_optouts: a table matched on anything but userid cannot be broken down.');

        // Act
        $filter->breakdown('email_optouts');

        // Assert — the expectation above.
    }

    /** A group's match is all or any; anything else is refused at the group's path. */
    public function testAGroupMatchesAllOrAny(): void
    {
        // Arrange
        $this->expectExceptionMessage('filter.1: match is all or any.');

        // Act
        Filter::fromArray(['conditions' => [
            ['attribute' => 'usertype', 'op' => 'is', 'value' => 1],
            ['match' => 'some', 'conditions' => []],
        ]], new Database());

        // Assert — the expectation above.
    }

    /** Groups nest at most MAX_DEPTH deep: deeper is refused rather than compiled. */
    public function testGroupsNestOnlySoDeep(): void
    {
        // Arrange
        $group = ['conditions' => []];
        for ($i = 0; $i < Filter::MAX_DEPTH; $i++) {
            $group = ['conditions' => [$group]];
        }
        $this->expectExceptionMessage('groups nest at most 5 deep.');

        // Act
        Filter::fromArray($group, new Database());

        // Assert — the expectation above.
    }

    /** JSON that is not a filter is refused; an empty string is the empty filter. */
    public function testJsonIsReadAndCheckedToo(): void
    {
        // Arrange
        $json = '{"match":"any","conditions":[{"attribute":"language","op":"is","value":"el"}]}';

        // Act
        $filter = Filter::fromArray($json, new Database());
        $empty  = Filter::fromArray('', new Database());

        // Assert
        $this->assertSame('any', $filter->toArray()['match']);
        $this->assertSame(['match' => 'all', 'conditions' => []], $empty->toArray());
        $this->expectExceptionMessage('The filter is not valid JSON.');
        Filter::fromArray('{nope', new Database());
    }

    /**
     * Accepted values are cast to what they are compared with: numbers from a form arrive as
     * strings, a no-value operator drops a stray value, a days count becomes an int.
     */
    public function testValuesAreNormalised(): void
    {
        // Arrange
        Attributes::define(Attribute::column('status', 'status', Attribute::LIST)->values(['a' => 'A', 1 => 'One']));

        // Act
        $number = self::make(['attribute' => 'usertype', 'op' => 'one_of', 'value' => ['1', '2.5']])->toArray();
        $list   = self::make(['attribute' => 'status', 'op' => 'one_of', 'value' => ['a', '1']])->toArray();
        $empty  = self::make(['attribute' => 'language', 'op' => 'empty', 'value' => 'ignored'])->toArray();
        $days   = self::make(['attribute' => 'lastlogin', 'op' => 'more_than_days_ago', 'value' => '30'])->toArray();
        $range  = self::make(['attribute' => 'regdate', 'op' => 'between', 'value' => ['a' => '2026-01-01', 'b' => '2026-01-31']])->toArray();
        $text   = self::make(['attribute' => 'language', 'op' => 'is', 'value' => 5])->toArray();

        // Assert
        $this->assertSame([1, 2.5], $number['conditions'][0]['value']);
        $this->assertSame(['a', 1], $list['conditions'][0]['value']);
        $this->assertSame(['attribute' => 'language', 'op' => 'empty'], $empty['conditions'][0]);
        $this->assertSame(30, $days['conditions'][0]['value']);
        $this->assertSame(['2026-01-01', '2026-01-31'], $range['conditions'][0]['value']);
        $this->assertSame('5', $text['conditions'][0]['value'], 'text stays text');
    }

    /**
     * The registry: the framework declares the generic attributes, an application's attribute
     * under a taken key replaces it, and the builder sees what toArray() describes, with
     * choices read only when they are asked for.
     */
    public function testAnApplicationReplacesAFrameworkAttributeByKey(): void
    {
        // Arrange
        $asked = 0;
        Attributes::define(Attribute::column('usertype', 'usertype', Attribute::LIST)->label('Account type')->group('People')
            ->values(function () use (&$asked): array {
                $asked++;

                return [1 => 'Parent', 2 => 'Professional'];
            }));

        // Act
        $before   = $asked;
        $usertype = Attributes::get('usertype')->toArray();

        // Assert
        $this->assertSame(0, $before, 'the choices are not read until somebody needs them');
        $this->assertSame(1, $asked);
        $this->assertSame('list', $usertype['type']);
        $this->assertSame('Account type', $usertype['label']);
        $this->assertSame('People', $usertype['group']);
        $this->assertSame([['value' => 1, 'label' => 'Parent'], ['value' => 2, 'label' => 'Professional']], $usertype['values']);
        $this->assertSame(Attribute::OPERATORS['list'], $usertype['operators']);
        $this->assertArrayHasKey('lastlogin', Attributes::all(), 'the framework keeps the rest');
        $this->assertNull(Attributes::get('nothing'));
    }

    /**
     * An attribute behind an ability is not offered to an account without it; one without is
     * offered to everybody who has the screen.
     */
    public function testAnAttributeBehindAnAbilityIsHiddenWithoutIt(): void
    {
        // Arrange
        Attributes::define(Attribute::column('revenue', 'revenue', Attribute::NUMBER)->ability('audience.test-never-granted'));
        Attributes::define(Attribute::column('city', 'city', Attribute::TEXT));

        // Act
        $visible = Attributes::visible();

        // Assert
        $this->assertArrayNotHasKey('revenue', $visible);
        $this->assertArrayHasKey('city', $visible);
        $this->assertSame('audience.test-never-granted', Attributes::get('revenue')->requiredAbility());
        $this->assertSame('', Attributes::get('city')->groupName());
    }

    /**
     * Where the groups cannot be read — no table, no connection — the groups attribute offers
     * no choices rather than failing the builder that lists it.
     */
    public function testGroupChoicesAreEmptyWhenTheyCannotBeRead(): void
    {
        // Arrange
        $singleton = &Database::getInstance();
        $previous  = $singleton;
        $singleton = new class extends Database {
            /** A connection that cannot build a query. */
            public function queryBuilder(): \Pramnos\Database\QueryBuilder
            {
                throw new \RuntimeException('not connected');
            }
        };

        // Act
        try {
            $names = Attributes::groupNames();
        } finally {
            $singleton = $previous;
        }

        // Assert
        $this->assertSame([], $names);
    }

    /**
     * What an attribute compiles to: a users column, a side table's column, a per-driver
     * expression in parentheses; and an unknown type or date format is refused when declared.
     */
    public function testAnAttributeKnowsItsSql(): void
    {
        // Arrange
        $column = Attribute::column('language', 'language', Attribute::TEXT);
        $side   = Attribute::column('pillar', 'pillar', Attribute::NUMBER)->from('professionals', 'proid');
        $expr   = Attribute::expression('x', ['mysql' => 'A', 'postgresql' => 'B'], Attribute::NUMBER);
        $many   = Attribute::many('interests', 'userinterests', 'interestid');

        // Act
        $sql = [$column->sql('mysql'), $side->sql('mysql'), $expr->sql('mysql'), $expr->sql('postgresql')];

        // Assert
        $this->assertSame(['users.language', 'professionals.pillar', '(A)', '(B)'], $sql);
        $this->assertSame('proid', $side->foreignKey());
        $this->assertTrue($expr->isExpression());
        $this->assertSame('interestid', $many->valueColumn());
        $this->assertSame('userinterests', $many->table());
        $this->assertSame('language', $column->labelText(), 'the key when there is no label');
        $this->expectException(\InvalidArgumentException::class);
        Attribute::column('d', 'd', Attribute::DATE)->storedAs('weekday');
    }

    /** A type the filter does not know is refused when the attribute is declared. */
    public function testAnUnknownTypeIsRefused(): void
    {
        // Arrange
        $this->expectExceptionMessage('Attribute colour: unknown type colour.');

        // Act
        Attribute::column('colour', 'colour', 'colour');

        // Assert — the expectation above.
    }
}
