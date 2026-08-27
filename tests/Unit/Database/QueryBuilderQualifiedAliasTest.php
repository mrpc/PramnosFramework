<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * `from('authserver.foo a')` — a qualified name that carries an alias.
 *
 * On MySQL `schema.table` is a cross-database reference, so the framework flattens
 * `authserver.foo` to `{prefix}authserver_foo` in the current database. That resolution used
 * to be skipped whenever the string contained a space, on the reasoning that an aliased
 * expression is not something to rewrite.
 *
 * `Datasource::render()` builds exactly that string — `from($table . ' a')`. So every
 * datatable over an `authserver.*` table asked MySQL for a database named `authserver`; the
 * call sites catch the failure and answer an empty list, which on a screen is
 * indistinguishable from "this account has no history". The activity panel read as empty on
 * every MySQL installation, and the test fixture had papered over it by creating a database
 * called `authserver`.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderQualifiedAliasTest extends TestCase
{
    /**
     * A builder over a connection whose schema flattens names the way MySQL needs.
     */
    private function makeQB(string $type = 'mysql', string $prefix = ''): QueryBuilder
    {
        $schema = new class ($prefix) {
            public function __construct(private string $prefix)
            {
            }

            public function resolveTableName(string $table): string
            {
                return $this->prefix . str_replace('.', '_', $table);
            }
        };

        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['schema'])
            ->getMock();
        $db->type   = $type;
        $db->prefix = $prefix;
        $db->method('schema')->willReturn($schema);

        return new QueryBuilder($db);
    }

    /**
     * The table is flattened and the alias is kept.
     */
    public function testAnAliasedQualifiedNameIsResolvedWithItsAliasIntact(): void
    {
        // Act
        $sql = $this->makeQB()->from('authserver.user_activity_log a')->select(['a.action'])->toSql();

        // Assert
        $this->assertStringContainsString('authserver_user_activity_log a', $sql);
        $this->assertStringNotContainsString('authserver.user_activity_log', $sql,
            'the qualified form is a database reference on MySQL, which is the bug');
    }

    /**
     * `AS` is the same shape written out.
     */
    public function testTheAsFormIsResolvedToo(): void
    {
        // Act
        $sql = $this->makeQB()->from('authserver.permissions AS p')->select(['p.action'])->toSql();

        // Assert
        $this->assertStringContainsString('authserver_permissions AS p', $sql);
    }

    /**
     * A prefix applies to the flattened name, not around the alias.
     */
    public function testThePrefixLandsOnTheTableAndNotOnTheAlias(): void
    {
        // Act
        $sql = $this->makeQB('mysql', 'pramnos_')->from('authserver.mails m')->select(['m.id'])->toSql();

        // Assert
        $this->assertStringContainsString('pramnos_authserver_mails m', $sql);
    }

    /**
     * A plain qualified name still resolves — the behaviour that already worked.
     */
    public function testAPlainQualifiedNameStillResolves(): void
    {
        // Act
        $sql = $this->makeQB()->from('authserver.gdpr_requests')->select(['id'])->toSql();

        // Assert
        $this->assertStringContainsString('authserver_gdpr_requests', $sql);
    }

    /**
     * On PostgreSQL nothing is touched, alias or not.
     *
     * The schema *is* the namespace there, and flattening would rename tables the framework
     * addresses as `authserver.x` everywhere else.
     */
    public function testPostgresIsLeftAlone(): void
    {
        // Act
        $sql = $this->makeQB('postgresql')->from('authserver.user_activity_log a')
            ->select(['a.action'])->toSql();

        // Assert
        $this->assertStringContainsString('authserver', $sql);
        $this->assertStringNotContainsString('authserver_user_activity_log', $sql);
    }

    /**
     * Anything that is not "name" or "name alias" is left exactly as it was.
     *
     * The old guard existed for a reason — `from()` receives expressions — so the narrow
     * shapes are resolved and the rest is not. A join fragment or a function call keeps its
     * dots.
     */
    public function testAnExpressionIsNotRewritten(): void
    {
        // Arrange
        $expression = 'authserver.a JOIN authserver.b ON a.id = b.id';

        // Act
        $sql = $this->makeQB()->from($expression)->select(['*'])->toSql();

        // Assert
        $this->assertStringContainsString($expression, $sql);
    }
}
