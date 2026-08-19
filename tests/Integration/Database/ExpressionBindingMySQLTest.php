<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * A raw fragment as a `where` value, against real MySQL.
 *
 * The unit tests assert that the Expression is not appended to the bindings. This
 * asserts the consequence: the statement **executes and returns the right rows**.
 *
 * MySQL was expected to tolerate the surplus argument and did not: `Database::execute()`
 * prepends the statement's type string to the arguments, so a statement with no
 * placeholders reached `mysqli_stmt::bind_param()` with an empty type string and threw
 * *"Argument #1 (\$types) must not be empty"*. Different message from PostgreSQL's,
 * same verdict — which is why this class exists rather than only its PostgreSQL twin.
 *
 * The query is the one from the DevPanel's login-lockout panel — "rows whose lock has
 * not expired yet" — because that panel was empty on every PostgreSQL installation and
 * the exception was swallowed into a "could not load" line.
 */
class ExpressionBindingMySQLTest extends DatabaseTestCase
{
    /**
     * MySQL, in the db container.
     *
     * @return array<string, mixed> Connection properties
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /**
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['expr_locks'];
    }

    /**
     * One table with a timestamp column, so `NOW()` has something to compare against.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            "CREATE TABLE expr_locks (
                id INTEGER NOT NULL,
                label VARCHAR(32) NOT NULL,
                attempts INTEGER NOT NULL,
                lockoutuntil TIMESTAMP NULL
            )",
        ];
    }

    /**
     * Two rows either side of now, plus one that is not locked at all.
     *
     * Fixed dates rather than an interval expression: the arithmetic syntax differs per
     * dialect, and this test is about the fragment in the WHERE, not about seeding. The
     * future date is 2037 rather than something comfortably distant because MySQL's
     * TIMESTAMP range ends in January 2038.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [1, 'expired', 3, "'2020-01-01 00:00:00'"],
            [2, 'active', 5, "'2037-12-31 00:00:00'"],
            [3, 'never', 1, 'NULL'],
        ] as [$id, $label, $attempts, $until]) {
            $this->db->query(
                'INSERT INTO expr_locks (id, label, attempts, lockoutuntil) VALUES ('
                . $id . ", '" . $label . "', " . $attempts . ', ' . $until . ')'
            );
        }
    }

    /**
     * `where(col, '>', raw('NOW()'))` executes and returns only the live lock.
     *
     * Before the fix the Expression was appended to the bindings, so the statement carried
     * one value more than it had placeholders and `bind_param()` refused it before the
     * query ever reached the server.
     *
     * @return void
     */
    public function testARawValueInWhereExecutes(): void
    {
        // Arrange + Act
        $qb   = $this->db->queryBuilder();
        $rows = $qb->from('expr_locks')
            ->where('lockoutuntil', '>', $qb->raw('NOW()'))
            ->getAll();

        // Assert — the future lock, and neither the past one nor the NULL
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['label']);
    }

    /**
     * A bound scalar beside the fragment still filters on its own value.
     *
     * The mismatch could equally have been fixed by dropping a real binding, which
     * would be the same bug with the sign reversed: this row is only returned when the
     * `%i` placeholder receives 5 and not the Expression.
     *
     * @return void
     */
    public function testAScalarBesideTheFragmentIsStillBound(): void
    {
        // Arrange + Act
        $qb   = $this->db->queryBuilder();
        $rows = $qb->from('expr_locks')
            ->where('lockoutuntil', '>', $qb->raw('NOW()'))
            ->where('attempts', 5)
            ->getAll();

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['label']);

        // And the same query with an attempts value no row has returns nothing,
        // which proves the scalar reached the server rather than being ignored.
        $qb2 = $this->db->queryBuilder();
        $this->assertCount(
            0,
            $qb2->from('expr_locks')
                ->where('lockoutuntil', '>', $qb2->raw('NOW()'))
                ->where('attempts', 99)
                ->getAll()
        );
    }

    /**
     * A half-raw `BETWEEN` — one literal endpoint, one fragment.
     *
     * `whereBetween()` binds both endpoints through the same method, so it failed the
     * same way and is worth executing rather than only compiling.
     *
     * @return void
     */
    public function testAHalfRawBetweenExecutes(): void
    {
        // Arrange + Act
        $qb   = $this->db->queryBuilder();
        $rows = $qb->from('expr_locks')
            ->whereBetween('lockoutuntil', ['2019-01-01 00:00:00', $qb->raw('NOW()')])
            ->getAll();

        // Assert — only the lock that has already expired falls in that range
        $this->assertCount(1, $rows);
        $this->assertSame('expired', $rows[0]['label']);
    }
}
