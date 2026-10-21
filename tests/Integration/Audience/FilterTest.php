<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Comparison;
use Pramnos\Audience\Filter;

/**
 * A filter, compiled to the query builder and run against real tables on MySQL and PostgreSQL:
 * every operator selects the people it names, side tables are joined or asked with EXISTS, and
 * explain() agrees with the query.
 *
 * The four accounts are {@see AudienceProbe}'s.
 */
#[CoversClass(Filter::class)]
#[CoversClass(Comparison::class)]
#[CoversClass(Attributes::class)]
#[CoversClass(Attribute::class)]
class FilterTest extends TestCase
{
    use AudienceProbe;

    /**
     * Each condition with the people it must select, on each backend.
     *
     * @return iterable<string, array{string, string, int, string, array<string, mixed>, list<string>}>
     */
    public static function conditions(): iterable
    {
        $cases = [
            'number is'                => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2], ['bob', 'cara']],
            'number is not'            => [['attribute' => 'usertype', 'op' => 'is_not', 'value' => 2], ['anna']],
            'number gt'                => [['attribute' => 'usertype', 'op' => 'gt', 'value' => 1], ['bob', 'cara']],
            'number lt'                => [['attribute' => 'usertype', 'op' => 'lt', 'value' => 2], ['anna']],
            'number between'           => [['attribute' => 'pillar', 'op' => 'between', 'value' => [1, 2]], ['cara']],
            'number one of'            => [['attribute' => 'pillar', 'op' => 'one_of', 'value' => [3, 9]], ['bob']],
            // anna has no profile row: "not one of" includes her, "is empty" names her.
            'side not one of'          => [['attribute' => 'pillar', 'op' => 'not_one_of', 'value' => [3]], ['anna', 'cara']],
            'side empty'               => [['attribute' => 'pillar', 'op' => 'empty'], ['anna']],
            'side not empty'           => [['attribute' => 'pillar', 'op' => 'not_empty'], ['bob', 'cara']],
            'text is'                  => [['attribute' => 'language', 'op' => 'is', 'value' => 'el'], ['anna', 'cara']],
            'text one of'              => [['attribute' => 'language', 'op' => 'one_of', 'value' => ['en']], ['bob']],
            'text contains any case'   => [['attribute' => 'city', 'op' => 'contains', 'value' => 'THESS'], ['cara']],
            'text not contains'        => [['attribute' => 'city', 'op' => 'not_contains', 'value' => 'thess'], ['anna', 'bob']],
            'text starts with'         => [['attribute' => 'city', 'op' => 'starts_with', 'value' => 'Ath'], ['bob']],
            'text like is literal'     => [['attribute' => 'city', 'op' => 'contains', 'value' => '%'], []],
            'text empty'               => [['attribute' => 'city', 'op' => 'empty'], ['anna']],
            'list is'                  => [['attribute' => 'active', 'op' => 'is', 'value' => 0], ['cara']],
            'date more than days ago'  => [['attribute' => 'lastlogin', 'op' => 'more_than_days_ago', 'value' => 30], ['anna']],
            'date less than days ago'  => [['attribute' => 'lastlogin', 'op' => 'less_than_days_ago', 'value' => 30], ['bob']],
            // cara never signed in: "is empty", not "more than 30 days ago".
            'unix date empty'          => [['attribute' => 'lastlogin', 'op' => 'empty'], ['cara']],
            'date after'               => [['attribute' => 'regdate', 'op' => 'after', 'value' => '2026-01-15'], ['bob', 'cara']],
            'date before'              => [['attribute' => 'regdate', 'op' => 'before', 'value' => '2026-01-16'], ['anna']],
            'date between whole days'  => [['attribute' => 'regdate', 'op' => 'between', 'value' => ['2026-01-15', '2026-02-01']], ['anna', 'bob']],
            'datetime after'           => [['attribute' => 'joined', 'op' => 'after', 'value' => '2026-03-01'], ['cara']],
            'date compared as a day'   => [['attribute' => 'joinedday', 'op' => 'after', 'value' => '2026-03-01'], ['cara']],
            'datetime more than days'  => [['attribute' => 'joined', 'op' => 'more_than_days_ago', 'value' => 1], ['bob', 'cara']],
            'expression true'          => [['attribute' => 'validated', 'op' => 'true'], ['anna', 'bob']],
            'expression false'         => [['attribute' => 'validated', 'op' => 'false'], ['cara']],
            'expression one of'        => [['attribute' => 'level', 'op' => 'one_of', 'value' => [2]], ['bob', 'cara']],
            'expression not contains'  => [['attribute' => 'initial', 'op' => 'not_contains', 'value' => 'A'], ['bob', 'cara']],
            'many any of'              => [['attribute' => 'interests', 'op' => 'any_of', 'value' => [1, 3]], ['bob']],
            'many all of'              => [['attribute' => 'interests', 'op' => 'all_of', 'value' => [1, 2]], ['bob']],
            'many none of'             => [['attribute' => 'interests', 'op' => 'none_of', 'value' => [1]], ['anna', 'cara']],
            'many empty'               => [['attribute' => 'interests', 'op' => 'empty'], ['anna']],
            'many not empty'           => [['attribute' => 'interests', 'op' => 'not_empty'], ['bob', 'cara']],
        ];
        foreach (self::databases() as $db => $connection) {
            foreach ($cases as $name => [$condition, $expected]) {
                yield "{$db}: {$name}" => [...$connection, $condition, $expected];
            }
        }
    }

    /**
     * Every operator of every type selects exactly the people it names, and the count, the ids
     * and matches() agree with each other.
     *
     * The cases that matter most are the edges: a person with no row in a one-to-one table is
     * "empty" and is included by a negative operator; a date of 0 is empty rather than old; a
     * LIKE wildcard in the value is a literal; text compares ignoring case on both backends.
     */
    #[DataProvider('conditions')]
    public function testEveryOperatorSelectsThePeopleItNames(string $type, string $host, int $port, string $user, array $condition, array $expected): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray(['match' => 'all', 'conditions' => [$condition]]);

        // Act
        $ids = $filter->ids();

        // Assert
        $this->assertSame($expected, $this->names($ids));
        $this->assertSame(count($expected), $filter->count());
        foreach ($this->ids as $name => $id) {
            // matches() is the one-person form of the same query.
            $this->assertSame(in_array($name, $expected, true), $filter->matches($id), $name);
        }
    }

    /**
     * Groups nest: an "any" inside an "all" is one parenthesised term, so its OR does not leak
     * into the conditions beside it.
     */
    #[DataProvider('databases')]
    public function testNestedGroupsKeepTheirOwnParentheses(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray(['match' => 'all', 'conditions' => [
            ['attribute' => 'language', 'op' => 'is', 'value' => 'el'],
            ['match' => 'any', 'conditions' => [
                ['attribute' => 'usertype', 'op' => 'is', 'value' => 1],
                ['attribute' => 'pillar', 'op' => 'is', 'value' => 3],
            ]],
        ]]);

        // Act
        $names = $this->names($filter->ids());

        // Assert — bob is pillar 3 but not Greek; an OR at the top level would let him in.
        $this->assertSame(['anna'], $names);
    }

    /**
     * An empty filter is everybody in scope, and the scope is applied to every answer: dan, staff,
     * is in no count, no page and no sample.
     */
    #[DataProvider('databases')]
    public function testAnEmptyFilterIsEverybodyInScope(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray('');

        // Act
        $sample = $filter->sample(10);

        // Assert
        $this->assertSame(['anna', 'bob', 'cara'], $this->names($filter->ids()));
        $this->assertSame(3, $filter->count());
        $this->assertSame(['cara', 'bob', 'anna'], array_column($sample, 'username'), 'newest first');
        $this->assertSame('Cara', $sample[0]['firstname']);
        $this->assertFalse($filter->matches($this->ids['dan']));
    }

    /**
     * A one-to-many condition is asked with EXISTS, so a person with two matching rows is
     * counted once — the count is of people, not of rows.
     */
    #[DataProvider('databases')]
    public function testAPersonWithManyMatchingRowsIsCountedOnce(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray(['conditions' => [['attribute' => 'interests', 'op' => 'any_of', 'value' => [1, 2]]]]);

        // Act
        $count = $filter->count();

        // Assert — bob has both interests, cara one: two people, three rows.
        $this->assertSame(2, $count);
    }

    /**
     * Pages of ids are a keyset: each starts after the last id of the one before, and together
     * they are every id once.
     */
    #[DataProvider('databases')]
    public function testIdsComeInKeysetPages(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray([]);

        // Act
        $first  = $filter->ids(0, 2);
        $second = $filter->ids(end($first), 2);
        $third  = $filter->ids(end($second), 2);

        // Assert
        $this->assertSame([$this->ids['anna'], $this->ids['bob']], $first);
        $this->assertSame([$this->ids['cara']], $second);
        $this->assertSame([], $third);
    }

    /**
     * The groups the framework declares are a one-to-many over `userstogroups`: "in the group"
     * is a condition like any other.
     */
    #[DataProvider('databases')]
    public function testTheFrameworksGroupsAttributeReadsMembership(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $in  = Filter::fromArray(['conditions' => [['attribute' => 'groups', 'op' => 'any_of', 'value' => [$this->groups['Press']]]]]);
        $out = Filter::fromArray(['conditions' => [['attribute' => 'groups', 'op' => 'none_of', 'value' => [$this->groups['Volunteers']]]]]);

        // Act
        $labels = Attributes::get('groups')->valueLabels();

        // Assert
        $this->assertSame(['bob'], $this->names($in->ids()));
        $this->assertSame(['cara'], $this->names($out->ids()));
        $this->assertSame('Press', $labels[$this->groups['Press']], 'the choices are the groups, by id');
    }

    /**
     * A breakdown counts the people who match per value, largest first, with the attribute's
     * labels — for a column, a one-to-one column, and a one-to-many.
     */
    #[DataProvider('databases')]
    public function testABreakdownCountsPeoplePerValue(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray([]);

        // Act
        $languages = $filter->breakdown('language');
        $pillars   = $filter->breakdown('pillar');
        $interests = $filter->breakdown('interests');

        // Assert
        $this->assertSame(['value' => 'el', 'label' => 'el', 'count' => 2], $languages[0]);
        $this->assertSame(['value' => 'en', 'label' => 'en', 'count' => 1], $languages[1]);
        $this->assertContains(['value' => null, 'label' => '(empty)', 'count' => 1], $pillars, 'anna has no profile');
        $this->assertSame('Sport', $interests[0]['label']);
        $this->assertSame(2, $interests[0]['count']);
        $this->assertSame(1, $interests[1]['count']);
    }

    /**
     * explain() answers "why" for one person: each condition with ✓/✗ and the value it was
     * compared with, groups combined as the query combines them, and whether the person is in
     * scope at all — a staff account that matches every condition still is not in the audience.
     */
    #[DataProvider('databases')]
    public function testExplainShowsEachConditionWithTheActualValue(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = Filter::fromArray(['match' => 'all', 'conditions' => [
            ['attribute' => 'language', 'op' => 'is', 'value' => 'el'],
            ['match' => 'any', 'conditions' => [
                ['attribute' => 'pillar', 'op' => 'is', 'value' => 3],
                ['attribute' => 'interests', 'op' => 'any_of', 'value' => [2]],
            ]],
        ]]);

        // Act
        $cara = $filter->explain($this->ids['cara']);
        $bob  = $filter->explain($this->ids['bob']);
        $dan  = $filter->explain($this->ids['dan']);

        // Assert
        $this->assertTrue($cara['ok']);
        $this->assertTrue($cara['inScope']);
        $this->assertSame('el', $cara['conditions'][0]['actual']);
        $this->assertTrue($cara['conditions'][0]['ok']);
        $this->assertSame('any', $cara['conditions'][1]['match']);
        $this->assertFalse($cara['conditions'][1]['conditions'][0]['ok'], 'pillar 1, not 3');
        $this->assertEquals(1, $cara['conditions'][1]['conditions'][0]['actual']);
        $this->assertEquals([2], $cara['conditions'][1]['conditions'][1]['actual']);
        $this->assertTrue($cara['conditions'][1]['ok'], 'one of an any is enough');

        $this->assertFalse($bob['ok']);
        $this->assertSame('en', $bob['conditions'][0]['actual']);
        $this->assertFalse($bob['conditions'][0]['ok']);

        $this->assertFalse($dan['ok'], 'staff are out of scope');
        $this->assertFalse($dan['inScope']);
        $this->assertTrue($dan['conditions'][0]['ok']);
    }
}
