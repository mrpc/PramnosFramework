<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Grammar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;
use Pramnos\Database\Grammar\SchemaGrammar;

/**
 * The query behind `hasIndex()`, which every migration asks before creating one.
 *
 * A wrong answer is not an error — it is a migration that skips an index the schema needs, or one
 * that tries to create a duplicate and fails on a name that is already taken. Both look like a
 * migration problem rather than a grammar problem.
 *
 * The base implementation had no covered line. It is the fallback for a driver that is neither
 * MySQL nor PostgreSQL — both of those override it, and the assertion that they *do* is here too,
 * because a PostgreSQL grammar that inherited the base would query
 * `information_schema.statistics`, which does not exist there: every index check in every
 * migration would raise.
 */
#[CoversClass(SchemaGrammar::class)]
class HasIndexQueryTest extends TestCase
{
    /**
     * A grammar for a driver the framework does not know.
     *
     * Which is exactly what the base implementation is for, so this stand-in is the honest shape
     * of the case under test rather than a convenience: the five abstract members are the ones a
     * real driver would have to answer, and none of them is reached by `compileHasIndex()`.
     */
    private function baseGrammar(): SchemaGrammar
    {
        return new class extends SchemaGrammar {
            public function quoteTable(string $table): string
            {
                return '"' . $table . '"';
            }

            public function quoteColumn(string $column): string
            {
                return '"' . $column . '"';
            }

            public function compileColumnType(\Pramnos\Database\ColumnDefinition $column): string
            {
                return 'TEXT';
            }

            public function compileNextVal(string $name): string
            {
                return "SELECT nextval('" . $name . "')";
            }

            public function compileSetVal(string $name, int $value, bool $isCalled = true): string
            {
                return "SELECT setval('" . $name . "', " . $value . ')';
            }
        };
    }

    /**
     * The default asks `information_schema.statistics`, which is the MySQL shape.
     *
     * Deliberate: `information_schema` has no index view, because indexes are not part of the
     * standard — so the fallback resembles the driver an unknown one is most likely to resemble.
     */
    public function testTheDefaultUsesTheMysqlShapedStatisticsTable(): void
    {
        // Act
        $sql = $this->baseGrammar()->compileHasIndex('orders', 'idx_orders_created', '');

        // Assert
        $this->assertStringContainsString('information_schema.statistics', $sql);
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringContainsString("index_name = 'idx_orders_created'", $sql);
        $this->assertStringEndsWith('LIMIT 1', $sql);
    }

    /**
     * A schema is added to the filter, and left out when there is none.
     *
     * Both halves, because the wrong one is silent either way: without the filter, an index of the
     * same name in another schema answers yes and the migration skips a table that has no index;
     * with an empty filter added, `table_schema = ''` matches nothing and every check answers no.
     */
    public function testASchemaNarrowsTheQueryAndAnEmptyOneIsOmitted(): void
    {
        // Act
        $scoped = $this->baseGrammar()->compileHasIndex('orders', 'idx_o', 'shop');
        $plain  = $this->baseGrammar()->compileHasIndex('orders', 'idx_o', '');

        // Assert
        $this->assertStringContainsString("table_schema = 'shop'", $scoped);
        $this->assertStringNotContainsString('table_schema', $plain, "an empty schema must not become table_schema = ''");
    }

    /**
     * A quote in a name is escaped rather than closing the string.
     *
     * These names reach the grammar from a migration, so this is not a boundary an attacker
     * crosses — but an unescaped apostrophe makes the query a syntax error, and the failure lands
     * on whoever ran the migration with no hint of where it came from.
     */
    public function testAQuoteInANameIsEscaped(): void
    {
        // Act
        $sql = $this->baseGrammar()->compileHasIndex("o'rders", "idx_o'x", '');

        // Assert
        $this->assertStringContainsString("o\\'rders", $sql);
        $this->assertStringContainsString("idx_o\\'x", $sql);
    }

    /**
     * MySQL and PostgreSQL each override the default.
     *
     * The assertion that matters most in this file. PostgreSQL has no
     * `information_schema.statistics`; a grammar that inherited the base would make every
     * `hasIndex()` raise, which is every migration that guards an index — and the error would name
     * a view nobody in the project ever wrote.
     */
    public function testBothRealGrammarsOverrideTheDefault(): void
    {
        // Act
        $default = $this->baseGrammar()->compileHasIndex('orders', 'idx_o', '');
        $mysql   = (new MySQLSchemaGrammar())->compileHasIndex('orders', 'idx_o', '');
        $postgres = (new PostgreSQLSchemaGrammar())->compileHasIndex('orders', 'idx_o', '');

        // Assert
        $this->assertNotSame($default, $postgres, 'PostgreSQL inherited a query for a view it does not have');
        $this->assertStringContainsString('pg_indexes', $postgres);
        $this->assertStringNotContainsString('information_schema.statistics', $postgres);

        // MySQL may legitimately resemble the default; what matters is that it answers.
        $this->assertNotSame('', $mysql);
    }

    /**
     * PostgreSQL reads a schema out of a qualified table name.
     *
     * `authserver.usertokens` is how this framework names its own tables, and on PostgreSQL that
     * is a real schema. Without the split, `tablename = 'authserver.usertokens'` matches nothing
     * and every index on those tables reads as absent.
     */
    public function testPostgresqlSplitsAQualifiedTableName(): void
    {
        // Act
        $sql = (new PostgreSQLSchemaGrammar())
            ->compileHasIndex('authserver.usertokens', 'idx_token_lookup', '');

        // Assert
        $this->assertStringContainsString("tablename = 'usertokens'", $sql);
        $this->assertStringContainsString("schemaname = 'authserver'", $sql);
    }

    /**
     * With no schema, PostgreSQL excludes the system ones rather than searching everything.
     *
     * `pg_catalog` and `information_schema` carry thousands of indexes, and a name that collides
     * with one of them would answer yes for a table the project owns.
     */
    public function testPostgresqlExcludesTheSystemSchemasWhenNoneIsGiven(): void
    {
        // Act
        $sql = (new PostgreSQLSchemaGrammar())->compileHasIndex('orders', 'idx_o', '');

        // Assert
        $this->assertStringContainsString("schemaname NOT IN ('pg_catalog','information_schema')", $sql);
    }
}
