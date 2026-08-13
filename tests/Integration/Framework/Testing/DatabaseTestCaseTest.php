<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * `DatabaseTestCase` — the lifecycle itself, asserted against a real database.
 *
 * This class is infrastructure that other tests trust, and the failure modes are quiet:
 * a schema that is not created makes some other class fail, and a table that is not
 * emptied makes some other class pass for the wrong reason. So the guarantees are asserted
 * here rather than assumed by the fifteen classes that will rely on them.
 *
 * It runs against MySQL because that is the engine whose DDL cost motivated the class;
 * {@see DatabaseTestCasePostgreSQLTest} covers the other quoting and sequence paths.
 */
class DatabaseTestCaseTest extends DatabaseTestCase
{
    /**
     * MySQL, in the project's own container.
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
     * A parent and a child, child first.
     *
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['dtc_children', 'dtc_parents', 'dtc_keyless'];
    }

    /**
     * Two tables with a foreign key between them, and one with no generated key.
     *
     * The foreign key is the point of the first two: emptying them in the wrong order, or
     * with checks enabled, would fail. The third exists to prove that a table with no
     * auto-increment column does not break the optional sequence reset.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE `dtc_parents` (
                id   INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )',
            'CREATE TABLE `dtc_children` (
                id        INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NOT NULL,
                CONSTRAINT fk_dtc_child FOREIGN KEY (parent_id) REFERENCES `dtc_parents` (id)
            )',
            'CREATE TABLE `dtc_keyless` (
                code VARCHAR(10) NOT NULL
            )',
        ];
    }

    /**
     * The class under test resets counters, so the reset paths are exercised.
     *
     * @return bool Always true here
     */
    protected static function resetAutoIncrement(): bool
    {
        return true;
    }

    /**
     * The schema exists before the first test runs.
     *
     * `setUpBeforeClass()` has already run by the time any test body does, so an
     * `INSERT` succeeding is the proof — and a more useful proof than reading
     * `information_schema`, because it is what every subclass actually depends on.
     */
    public function testTheSchemaIsBuiltBeforeTheFirstTest(): void
    {
        // Act
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('first')");

        // Assert
        $result = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_parents`');
        $this->assertSame(1, (int) $result->fields['n']);
    }

    /**
     * Each test starts with empty tables, even though the schema is shared.
     *
     * This test and the one before it both write to `dtc_parents`. If `setUp()` did not
     * empty it, whichever ran second would see two rows — which is exactly the failure
     * this class exists to prevent, and the reason it is asserted from two directions.
     */
    public function testEachTestStartsWithEmptyTables(): void
    {
        // Assert — before writing anything
        $result = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_parents`');
        $this->assertSame(
            0,
            (int) $result->fields['n'],
            'A row written by another test in this class survived into this one.'
        );

        // Act
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('second')");

        // Assert
        $result = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_parents`');
        $this->assertSame(1, (int) $result->fields['n']);
    }

    /**
     * A foreign key does not stop the tables being emptied.
     *
     * The `DELETE`s run inside `SET FOREIGN_KEY_CHECKS = 0`, so a child row referencing a
     * parent cannot make the next test's `setUp()` fail. Without that, this test would
     * poison every test after it — and the error would name the wrong test.
     */
    public function testAForeignKeyDoesNotBlockTheReset(): void
    {
        // Arrange — a child pointing at a parent, left behind deliberately
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('referenced')");
        $parentId = (int) $this->db->query(
            'SELECT id FROM `dtc_parents` ORDER BY id DESC LIMIT 1'
        )->fields['id'];
        $this->db->query("INSERT INTO `dtc_children` (parent_id) VALUES ({$parentId})");

        // Act — the reset the *next* test would run
        $this->emptyOwnedTables();

        // Assert
        $children = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_children`');
        $parents  = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_parents`');
        $this->assertSame(0, (int) $children->fields['n']);
        $this->assertSame(0, (int) $parents->fields['n']);
    }

    /**
     * With the reset enabled, generated keys restart at 1.
     *
     * The opt-in exists for tests that assert on the sequence. Proving it works matters
     * more than it looks: the default is *off*, so a class that switches it on is a class
     * whose assertions depend on this behaviour being real.
     */
    public function testTheOptionalResetRestartsTheCounter(): void
    {
        // Arrange — burn some ids, then reset the way setUp() does
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('a'), ('b'), ('c')");
        $this->emptyOwnedTables();

        // Act
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('after reset')");

        // Assert
        $result = $this->db->query('SELECT id FROM `dtc_parents`');
        $this->assertSame(1, (int) $result->fields['id']);
    }

    /**
     * A table with no generated key survives the reset.
     *
     * `resetSequence()` swallows the error, because "this table has no counter" is not a
     * failure — and a class listing one such table among ten should not have to know that
     * the reset is per-table.
     */
    public function testATableWithNoGeneratedKeyIsNotAProblem(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `dtc_keyless` (code) VALUES ('x')");

        // Act — this is where a thrown ALTER would surface
        $this->emptyOwnedTables();

        // Assert
        $result = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_keyless`');
        $this->assertSame(0, (int) $result->fields['n']);
    }

    /**
     * `tearDownAfterClass()` removes the tables.
     *
     * Called from inside a test on purpose. PHPUnit collects coverage per test, so code
     * that only ever runs in `setUpBeforeClass()` or `tearDownAfterClass()` is executed
     * but never attributed — and an untested teardown is how a table outlives its class
     * and confuses whatever runs next.
     *
     * The schema is rebuilt before returning, so the tests after this one are unaffected.
     */
    public function testTearDownAfterClassRemovesTheTables(): void
    {
        // Act
        static::tearDownAfterClass();

        // Assert — the table is gone, proven by a query that must now fail
        $failed = false;
        try {
            $this->db->query('SELECT 1 FROM `dtc_parents`');
        } catch (\Throwable) {
            $failed = true;
        }
        $this->assertTrue($failed, 'tearDownAfterClass() left the tables behind.');

        // Cleanup — put the class back the way the remaining tests expect it
        static::setUpBeforeClass();
        $this->db = static::openConnection();
    }

    /**
     * `setUpBeforeClass()` can run twice without complaint.
     *
     * It drops before it creates, which is what makes it safe when a previous class left a
     * table of the same name behind — the situation that made this framework's own
     * `MediaObjectTest` drop the whole user stack on every single test.
     */
    public function testSetUpBeforeClassIsSafeToRunTwice(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `dtc_parents` (name) VALUES ('doomed')");

        // Act
        static::setUpBeforeClass();
        $this->db = static::openConnection();

        // Assert — schema present, and the row went with the old table
        $result = $this->db->query('SELECT COUNT(*) AS n FROM `dtc_parents`');
        $this->assertSame(0, (int) $result->fields['n']);
    }

    /**
     * Identifiers are quoted for the engine in use.
     *
     * Backticks on MySQL, double quotes elsewhere. Asserted directly because the wrong
     * quote character produces a syntax error in `setUpBeforeClass()`, which PHPUnit
     * reports against the class rather than against anything readable.
     */
    public function testItQuotesIdentifiersForTheEngine(): void
    {
        // Act & Assert
        $this->assertSame('`widgets`', self::quote($this->db, 'widgets'));
    }
}
