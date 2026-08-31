<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\JoinClause;
use Pramnos\Framework\Factory;

/**
 * Joins with more than one condition, and joins with an alias.
 *
 * Two limitations that arrived together, because they are needed together. A
 * join on two columns — a composite key, a membership row matched on both the
 * user and the organisation — could not be expressed at all: `join($table,
 * $first, $op, $second)` says one thing. And the moment a join was given an
 * alias, the table name stopped being resolved: the check that turned
 * `authserver.roles` into its physical name skipped anything containing a space,
 * so the qualified name went to the driver untranslated and MySQL was asked for a
 * schema it does not have.
 *
 * Aliases are exactly what a multi-condition join needs, so either one alone
 * would have been half a feature.
 */
#[CoversClass(JoinClause::class)]
class JoinClauseTest extends TestCase
{
    /** The SQL a builder produces, for assertions about its shape. */
    private function sqlFor(callable $build): string
    {
        $qb = Factory::getDatabase()->queryBuilder();

        return $build($qb)->toSql();
    }

    // ── JoinClause on its own ─────────────────────────────────────────────────

    /**
     * Conditions are recorded in order, with the operator and boolean each one was
     * given.
     */
    public function testConditionsAreCollectedInOrder(): void
    {
        // Arrange
        $clause = new JoinClause();

        // Act
        $clause->on('a.x', '=', 'b.x')
               ->on('a.y', '>=', 'b.y')
               ->orOn('a.z', '=', 'b.z');

        // Assert
        $conditions = $clause->getConditions();
        $this->assertCount(3, $conditions);
        $this->assertSame(
            ['first' => 'a.x', 'operator' => '=', 'second' => 'b.x', 'boolean' => 'and'],
            $conditions[0]
        );
        $this->assertSame('>=', $conditions[1]['operator']);
        $this->assertSame('or', $conditions[2]['boolean']);
    }

    /**
     * The two-argument form means equality.
     *
     * `on('a.x', 'b.x')` is the shape people write without thinking about it, and
     * reading the second argument as an operator would produce
     * `ON a.x b.x` — SQL that fails at the driver with nothing pointing back here.
     */
    public function testTheTwoArgumentFormMeansEquals(): void
    {
        // Arrange
        $clause = new JoinClause();

        // Act
        $clause->on('a.x', 'b.x');

        // Assert
        $this->assertSame(
            ['first' => 'a.x', 'operator' => '=', 'second' => 'b.x', 'boolean' => 'and'],
            $clause->getConditions()[0]
        );
    }

    /** A clause nobody added to renders nothing, rather than a stray keyword. */
    public function testAnEmptyClauseHasNoConditions(): void
    {
        // Act + Assert
        $this->assertSame([], (new JoinClause())->getConditions());
    }

    // ── The compiled SQL ──────────────────────────────────────────────────────

    /**
     * A two-condition join compiles to one ON with an AND.
     *
     * The first condition must carry no boolean: `ON AND uo.userid = …` is a syntax
     * error, and it is the kind that only appears once a join has a second
     * condition, which is to say the first time anybody uses this.
     */
    public function testAMultiConditionJoinCompilesToOneOnClause(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb
            ->table('authserver.user_roles ur')
            ->select(['ur.roleid'])
            ->leftJoin('authserver.user_organizations uo', function (JoinClause $j) {
                $j->on('uo.userid', '=', 'ur.userid')
                  ->on('uo.organization_id', '=', 'rd.organization_id');
            }));

        // Assert
        $this->assertStringContainsString(
            'ON uo.userid = ur.userid AND uo.organization_id = rd.organization_id',
            $sql
        );
        $this->assertStringNotContainsString('ON AND', $sql);
    }

    /** `orOn()` renders OR, so an either-or join is expressible. */
    public function testOrOnRendersOr(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb
            ->table('a')
            ->join('b', function (JoinClause $j) {
                $j->on('b.x', '=', 'a.x')->orOn('b.y', '=', 'a.y');
            }));

        // Assert
        $this->assertStringContainsString('ON b.x = a.x OR b.y = a.y', $sql);
    }

    /** The join type is honoured for a closure join as for a simple one. */
    public function testTheJoinTypeIsHonoured(): void
    {
        // Act
        $left  = $this->sqlFor(fn($qb) => $qb->table('a')->leftJoin('b', fn(JoinClause $j) => $j->on('b.x', '=', 'a.x')));
        $inner = $this->sqlFor(fn($qb) => $qb->table('a')->join('b', fn(JoinClause $j) => $j->on('b.x', '=', 'a.x')));

        // Assert
        $this->assertStringContainsString('LEFT JOIN', $left);
        $this->assertStringContainsString('INNER JOIN', $inner);
    }

    // ── Alias resolution ──────────────────────────────────────────────────────

    /**
     * A qualified name keeps being resolved when an alias follows it.
     *
     * This is the regression: `authserver.roles` resolves to its physical name, and
     * `authserver.roles rd` used to not, because the resolver skipped anything with
     * a space in it. On MySQL that reaches the driver as a schema reference to a
     * schema that does not exist.
     */
    public function testAnAliasedQualifiedNameIsStillResolved(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb
            ->table('users')
            ->join('authserver.roles rd', 'rd.roleid', '=', 'users.roleid'));

        // Assert — the alias survives, and the qualified name did not.
        $this->assertStringContainsString(' rd ON rd.roleid = users.roleid', $sql);

        $driver = Factory::getDatabase()->type;
        if ($driver === 'postgresql') {
            // A schema is a real thing there, so the name is left alone.
            $this->assertStringContainsString('authserver.roles rd', $sql);
        } else {
            $this->assertStringNotContainsString('authserver.roles', $sql);
            $this->assertStringContainsString('authserver_roles rd', $sql);
        }
    }

    /** An unaliased qualified name resolves exactly as it always did. */
    public function testAnUnaliasedQualifiedNameIsUnchangedInBehaviour(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb
            ->table('users')
            ->join('authserver.roles', 'authserver.roles.roleid', '=', 'users.roleid'));

        // Assert
        if (Factory::getDatabase()->type !== 'postgresql') {
            $this->assertStringContainsString('authserver_roles', $sql);
        }
        $this->assertStringContainsString('JOIN', $sql);
    }

    /** An unqualified table is left alone, alias or not. */
    public function testAnUnqualifiedTableIsLeftAlone(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb->table('a')->join('b bb', 'bb.x', '=', 'a.x'));

        // Assert
        $this->assertStringContainsString('JOIN b bb ON bb.x = a.x', $sql);
    }

    /** Extra whitespace between name and alias does not defeat the split. */
    public function testExtraWhitespaceAroundTheAliasIsTolerated(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb
            ->table('users')
            ->join('  authserver.roles    rd  ', 'rd.roleid', '=', 'users.roleid'));

        // Assert
        $this->assertStringContainsString(' rd ON rd.roleid = users.roleid', $sql);
    }

    /** The simple four-argument join is untouched by any of this. */
    public function testTheSimpleJoinStillWorks(): void
    {
        // Act
        $sql = $this->sqlFor(fn($qb) => $qb->table('a')->join('b', 'b.x', '=', 'a.x'));

        // Assert
        $this->assertStringContainsString('INNER JOIN b ON b.x = a.x', $sql);
    }
}
