<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * What the schema builder remembers about which tables exist, and `insertMany()`, against
 * MySQL — {@see SchemaExistenceMemoPostgreSQLTest} runs the same on PostgreSQL.
 *
 * `hasTable()` / `hasColumn()` remember a table or column **found**, per connection, so the guard
 * code that asks on every call costs one catalogue query a process. A missing one is never
 * remembered: a table created a moment later is seen at once. A DDL statement through the
 * connection — the builder's or hand-written — and the migration runner forget the answers.
 */
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(QueryBuilder::class)]
#[CoversClass(Database::class)]
class SchemaExistenceMemoTest extends DatabaseTestCase
{
    /** @return array<string, mixed> MySQL, in the project's container */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => TEST_DATABASE,
            'port'     => 3306,
        ];
    }

    /** @return string[] The tables this class makes */
    protected static function ownedTables(): array
    {
        return ['sem_rows'];
    }

    /** @return string[] One table for insertMany() */
    protected static function schemaStatements(): array
    {
        return ['CREATE TABLE sem_rows (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, qty INT NOT NULL, note VARCHAR(50) NULL)'];
    }

    /** The scratch table, created behind the tested connection's back. */
    protected function createScratchElsewhere(): void
    {
        $other = static::openConnection();
        $other->query('CREATE TABLE sem_scratch (id INT, label VARCHAR(10))');
        $other->close();
    }

    /** The scratch table dropped behind the tested connection's back. */
    protected function dropScratchElsewhere(): void
    {
        $other = static::openConnection();
        $other->query('DROP TABLE IF EXISTS sem_scratch');
        $other->close();
    }

    /** A column of the scratch table dropped behind the tested connection's back. */
    protected function dropLabelElsewhere(): void
    {
        $other = static::openConnection();
        $other->query('ALTER TABLE sem_scratch DROP COLUMN label');
        $other->close();
    }

    protected function tearDown(): void
    {
        $this->dropScratchElsewhere();
        SchemaBuilder::forgetWhatExists();
        parent::tearDown();
    }

    /**
     * A table found is remembered: dropped by another connection, this one still says it is
     * there — which is what proves no second catalogue query ran — until it is told to forget.
     */
    public function testAFoundTableIsRememberedUntilForgotten(): void
    {
        // Arrange
        $this->createScratchElsewhere();
        $this->assertTrue($this->db->schema()->hasTable('sem_scratch'));

        // Act
        $this->dropScratchElsewhere();
        $remembered = $this->db->schema()->hasTable('sem_scratch');
        SchemaBuilder::forgetWhatExists($this->db);
        $asked = $this->db->schema()->hasTable('sem_scratch');

        // Assert
        $this->assertTrue($remembered, 'the answer was asked of the catalogue again');
        $this->assertFalse($asked, 'forgetting did not make it ask again');
    }

    /**
     * A missing table is never remembered: created a moment later, it is seen at once — a
     * remembered "no" would hide a table a migration just made.
     */
    public function testAMissingTableIsAskedAgain(): void
    {
        // Arrange
        $this->assertFalse($this->db->schema()->hasTable('sem_scratch'));

        // Act
        $this->createScratchElsewhere();

        // Assert
        $this->assertTrue($this->db->schema()->hasTable('sem_scratch'));
    }

    /**
     * DDL through the connection forgets: a table dropped by hand-written SQL is not reported,
     * nor one the builder dropped.
     */
    public function testDdlThroughTheConnectionForgets(): void
    {
        // Arrange
        $this->createScratchElsewhere();
        $this->assertTrue($this->db->schema()->hasTable('sem_scratch'));

        // Act — by hand
        $this->db->query('  drop table sem_scratch');
        $afterRaw = $this->db->schema()->hasTable('sem_scratch');
        // Act — by the builder
        $this->createScratchElsewhere();
        $this->assertTrue($this->db->schema()->hasTable('sem_scratch'));
        $this->db->schema()->dropTableIfExists('sem_scratch');
        $afterBuilder = $this->db->schema()->hasTable('sem_scratch');

        // Assert
        $this->assertFalse($afterRaw, 'a DROP the connection ran left the table reported');
        $this->assertFalse($afterBuilder, 'dropTableIfExists() left the table reported');
    }

    /**
     * A table another process dropped is reported once more, and then the query on it fails —
     * and that failure makes the connection forget, so the process is wrong once and right from
     * then on. By hand-written SQL and through the query builder alike.
     */
    public function testAQueryOnAMissingTableForgetsWhatWasFound(): void
    {
        foreach (['raw', 'builder'] as $how) {
            // Arrange
            $this->createScratchElsewhere();
            $this->assertTrue($this->db->schema()->hasTable('sem_scratch'));
            $this->dropScratchElsewhere();
            $this->assertTrue($this->db->schema()->hasTable('sem_scratch'), 'remembered: nothing to test');

            // Act
            try {
                if ($how === 'raw') {
                    $this->db->query('SELECT * FROM sem_scratch');
                } else {
                    $this->db->queryBuilder()->table('sem_scratch')->where('id', 1)->get();
                }
            } catch (\Throwable) {
                // The failure is the point; how it surfaces is the driver's.
            }

            // Assert
            $this->assertFalse($this->db->schema()->hasTable('sem_scratch'), $how . ': the failed query left the table reported');
        }
    }

    /**
     * Columns the same way: a found column is remembered, a missing one asked again, and
     * forgetting every connection's answers works too.
     */
    public function testAFoundColumnIsRememberedAndAMissingOneIsNot(): void
    {
        // Arrange
        $this->createScratchElsewhere();
        $this->assertTrue($this->db->schema()->hasColumn('sem_scratch', 'label'));
        $this->assertFalse($this->db->schema()->hasColumn('sem_scratch', 'extra'));

        // Act
        $this->dropLabelElsewhere();
        $remembered = $this->db->schema()->hasColumn('sem_scratch', 'label');
        SchemaBuilder::forgetWhatExists();
        $asked = $this->db->schema()->hasColumn('sem_scratch', 'label');

        // Assert
        $this->assertTrue($remembered);
        $this->assertFalse($asked);
    }

    /**
     * `insertMany()` writes every row in one statement, in the first row's column order
     * whatever order a later row was written in, with an Expression inlined.
     */
    public function testInsertManyWritesEveryRow(): void
    {
        // Arrange
        $rows = [
            ['name' => 'a', 'qty' => 1, 'note' => null],
            ['qty' => 2, 'note' => 'second', 'name' => 'b'],
            ['name' => 'c', 'qty' => $this->db->queryBuilder()->raw('1 + 2'), 'note' => 'x'],
        ];

        // Act
        $written = $this->db->queryBuilder()->table('sem_rows')->insertMany($rows);

        // Assert
        $this->assertSame(3, $written);
        $found = $this->db->queryBuilder()->table('sem_rows')->select(['name', 'qty', 'note'])->orderBy('name')->getAll();
        $this->assertSame(
            [['a', 1, null], ['b', 2, 'second'], ['c', 3, 'x']],
            array_map(static fn (array $r): array => [$r['name'], (int) $r['qty'], $r['note']], $found)
        );
    }

    /**
     * Past 500 rows it splits into several statements, and writes them all.
     */
    public function testInsertManyChunksALongList(): void
    {
        // Arrange
        $rows = array_map(static fn (int $i): array => ['name' => 'n' . $i, 'qty' => $i, 'note' => null], range(1, 501));

        // Act
        $written = $this->db->queryBuilder()->table('sem_rows')->insertMany($rows);

        // Assert
        $this->assertSame(501, $written);
        $this->assertSame(501, (int) $this->db->queryBuilder()->table('sem_rows')->count());
    }

    /**
     * Rows with other columns than the first's are refused before anything is written, and
     * no rows write nothing.
     */
    public function testInsertManyRefusesMismatchedRowsAndAcceptsNone(): void
    {
        // Arrange
        $rows = [['name' => 'a', 'qty' => 1, 'note' => null], ['name' => 'b', 'qty' => 2]];

        // Act
        $none = $this->db->queryBuilder()->table('sem_rows')->insertMany([]);
        try {
            $this->db->queryBuilder()->table('sem_rows')->insertMany($rows);
            $refused = null;
        } catch (\InvalidArgumentException $exception) {
            $refused = $exception->getMessage();
        }

        // Assert
        $this->assertSame(0, $none);
        $this->assertNotNull($refused, 'a row missing a column was written');
        $this->assertStringContainsString('row 1', (string) $refused);
        $this->assertSame(0, (int) $this->db->queryBuilder()->table('sem_rows')->count(), 'the first row was written anyway');
    }
}
