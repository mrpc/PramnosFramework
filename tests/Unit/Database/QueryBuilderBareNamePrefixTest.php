<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Grammar\Grammar;
use Pramnos\Database\QueryBuilder;

/**
 * A bare table name takes the configured prefix in the query builder, as it does in the schema
 * builder that created the table.
 *
 * `createTable('things')` on an installation prefixed `pf_` makes `pf_things`; a builder query on
 * `table('things')` that stays `things` reads a table that is not there — or worse, another
 * application's of that name in the same database, which is what a test once wrote into. The
 * two layers have to agree on what a table is called.
 *
 * What must not change: a name already prefixed, `#PREFIX#`, a qualified or catalogue name, a
 * subquery, a CTE of the query; and a SELECT that qualifies a column with the bare name
 * (`things.id`) must still find it, so the prefixed name keeps its own name as the alias.
 */
#[CoversClass(QueryBuilder::class)]
#[CoversClass(Grammar::class)]
#[CoversClass(Database::class)]
class QueryBuilderBareNamePrefixTest extends TestCase
{
    /** A builder over a connection with `$prefix`, bare names prefixed or not. */
    private function builder(string $type = 'mysql', string $prefix = 'pf_', bool $bare = true): QueryBuilder
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['schema'])
            ->getMock();
        $db->type            = $type;
        $db->prefix          = $prefix;
        $db->prefixBareNames = $bare;

        return new QueryBuilder($db);
    }

    /**
     * A SELECT reads the prefixed table, aliased by its bare name, so a qualified column and a
     * join's condition still name it; a joined bare table is treated the same; an alias given
     * is kept.
     */
    public function testASelectReadsThePrefixedTableUnderItsOwnName(): void
    {
        // Act
        $plain   = $this->builder()->table('things')->where('things.id', 1)->toSql();
        $joined  = $this->builder()->table('things')->join('owners', 'owners.id', '=', 'things.owner')->toSql();
        $aliased = $this->builder()->table('things t')->leftJoin('owners AS o', 'o.id', '=', 't.owner')->toSql();

        // Assert
        $this->assertStringContainsString('FROM pf_things things WHERE', $plain);
        $this->assertStringContainsString('JOIN pf_owners owners ON owners.id = things.owner', $joined);
        $this->assertStringContainsString('FROM pf_things t LEFT JOIN pf_owners AS o ON', $aliased);
    }

    /**
     * INSERT, UPDATE and DELETE write the prefixed table, without an alias — MySQL takes none in
     * an INSERT, and MariaDB none in a single-table DELETE.
     */
    public function testWritesGoToThePrefixedTable(): void
    {
        // Arrange
        $qb      = $this->builder('postgresql')->table('things');
        $grammar = $qb->getGrammar();

        // Act
        $insert = $grammar->compileInsert($qb, ['id' => 1]);
        $update = $grammar->compileUpdate($qb, ['id' => 2]);
        $delete = $grammar->compileDelete($qb);

        // Assert
        $this->assertStringStartsWith('INSERT INTO pf_things (', $insert);
        $this->assertStringStartsWith('UPDATE pf_things SET', $update);
        $this->assertStringStartsWith('DELETE FROM pf_things', $delete);
    }

    /**
     * Names that are not bare, or are already what they should be, are left: the prefixed name
     * (as `Model::getFullTableName()` hands it over), `#PREFIX#`, a PostgreSQL catalogue, a CTE
     * of the query — even one defined after the `from()` naming it — and a subquery.
     */
    public function testWhatIsNotABareTableIsLeft(): void
    {
        // Act
        $already = $this->builder()->table('pf_things')->toSql();
        $marker  = $this->builder()->table('#PREFIX#things')->toSql();
        $catalog = $this->builder('postgresql')->table('pg_class')->toSql();
        $cte     = $this->builder()->from('ranked')->with('ranked', 'SELECT 1 AS n')->toSql();
        $sub     = $this->builder()->fromSub($this->builder()->table('things'), 'x')->toSql();

        // Assert
        $this->assertStringContainsString('FROM pf_things', $already);
        $this->assertStringNotContainsString('pf_pf_', $already);
        $this->assertStringContainsString('FROM pf_things', $marker, '#PREFIX# substituted, not doubled');
        $this->assertStringContainsString('FROM pg_class', $catalog);
        $this->assertStringContainsString('FROM ranked', $cte, 'the CTE is not a table');
        $this->assertStringContainsString('FROM pf_things things', $sub, 'inside the subquery, a bare name as anywhere');
        $this->assertStringContainsString(') AS x', $sub);
    }

    /**
     * With `prefix_bare_names` off, or with no prefix at all, a bare name is the table's name
     * as written — the behaviour an installation whose tables have no prefix relies on.
     */
    public function testOffOrWithoutAPrefixABareNameStays(): void
    {
        // Act
        $off  = $this->builder('mysql', 'pf_', false)->table('things')->where('things.id', 1)->toSql();
        $none = $this->builder('mysql', '')->table('things')->toSql();

        // Assert
        $this->assertStringContainsString('FROM things WHERE', $off);
        $this->assertStringContainsString('FROM things', $none);
        $this->assertStringNotContainsString('things things', $none, 'no alias when nothing changed');
    }

    /**
     * The setting is read from the application's database settings, and is on unless turned
     * off.
     */
    public function testTheSettingIsReadFromTheDatabaseSettings(): void
    {
        // Arrange — the settings object answers `database` with the block app.php holds
        $settings = function (array $database): \Pramnos\Application\Settings {
            $mock = $this->getMockBuilder(\Pramnos\Application\Settings::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
            $mock->method('__get')->willReturnCallback(static fn (string $key): mixed => $key === 'database' ? (object) $database : null);

            return $mock;
        };
        $default = new Database($settings(['hostname' => 'h', 'database' => 'd', 'user' => 'u', 'password' => '', 'prefix' => 'pf', 'type' => 'mysql']));
        $off     = new Database($settings(['hostname' => 'h', 'database' => 'd', 'user' => 'u', 'password' => '', 'prefix' => 'pf', 'type' => 'mysql', 'prefix_bare_names' => false]));

        // Assert
        $this->assertTrue($default->prefixBareNames);
        $this->assertFalse($off->prefixBareNames);
    }
}
