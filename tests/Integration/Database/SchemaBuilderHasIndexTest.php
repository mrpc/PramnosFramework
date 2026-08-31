<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * `SchemaBuilder::hasIndex()` against real databases.
 *
 * `hasTable()` and `hasColumn()` existed; the index question did not, so a migration
 * that needed to add an index idempotently had to either catch the driver's duplicate
 * error or skip the guard and hope. Both are ways of writing "I could not ask".
 *
 * Run against whichever driver the fixture points at, because this is exactly the
 * kind of introspection that differs: MySQL keeps indexes in
 * `information_schema.statistics`, one row per column; PostgreSQL exposes them
 * through `pg_indexes`, one row per index. A test on one driver proves nothing about
 * the other.
 */
#[CoversClass(SchemaBuilder::class)]
class SchemaBuilderHasIndexTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;
    protected ?\Pramnos\Database\Database $previousSingleton = null;

    protected const TABLE = 'pramnos_hasindex_probe';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $this->db = $this->connect();

        $schema = $this->db->schema();
        $schema->dropTableIfExists(self::TABLE);
        $schema->createTable(self::TABLE, function ($table) {
            $table->integer('id')->autoIncrement()->primary();
            $table->string('email', 190);
            $table->string('label', 100);
        });
    }

    protected function tearDown(): void
    {
        $this->db->schema()->dropTableIfExists(self::TABLE);

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    /**
     * The connection under test.
     *
     * Overridden by the PostgreSQL sibling. The index question is exactly the kind of
     * introspection that differs per driver — MySQL keeps one row per index *column*
     * in `information_schema.statistics`, PostgreSQL one row per index in
     * `pg_indexes` — so a pass on one proves nothing about the other.
     */
    protected function connect(): \Pramnos\Database\Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    /** An index that was never created is not reported as existing. */
    public function testAnAbsentIndexIsReportedAbsent(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->db->schema()->hasIndex(self::TABLE, 'idx_probe_nothing')
        );
    }

    /** A plain index is found by name. */
    public function testAPlainIndexIsFound(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->createIndex(self::TABLE, 'idx_probe_label', 'label');

        // Act + Assert
        $this->assertTrue($schema->hasIndex(self::TABLE, 'idx_probe_label'));
    }

    /**
     * A unique index is found too.
     *
     * On PostgreSQL a unique index and a unique *constraint* are different objects
     * that both surface in `pg_indexes`, which is what a caller asking "would
     * creating this collide" needs.
     */
    public function testAUniqueIndexIsFound(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->createUniqueIndex(self::TABLE, 'idx_probe_email', 'email');

        // Act + Assert
        $this->assertTrue($schema->hasIndex(self::TABLE, 'idx_probe_email'));
    }

    /**
     * The name is what is matched, not the columns.
     *
     * The distinction the method exists for: an index over `label` does not answer
     * yes for a differently named index over `label`. A guard that got this wrong
     * would skip creating the index a migration actually needs.
     */
    public function testTheNameIsWhatMatches(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->createIndex(self::TABLE, 'idx_probe_label', 'label');

        // Act + Assert
        $this->assertTrue($schema->hasIndex(self::TABLE, 'idx_probe_label'));
        $this->assertFalse($schema->hasIndex(self::TABLE, 'idx_probe_label_other'));
    }

    /** An index dropped is reported absent again. */
    public function testADroppedIndexIsReportedAbsent(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->createIndex(self::TABLE, 'idx_probe_label', 'label');
        $this->assertTrue($schema->hasIndex(self::TABLE, 'idx_probe_label'));

        // Act
        $schema->dropIndex(self::TABLE, 'idx_probe_label');

        // Assert
        $this->assertFalse($schema->hasIndex(self::TABLE, 'idx_probe_label'));
    }

    /** An index on a table that does not exist is absent, not an error. */
    public function testAnIndexOnAMissingTableIsAbsent(): void
    {
        // Act + Assert
        $this->assertFalse(
            $this->db->schema()->hasIndex('pramnos_no_such_table_here', 'idx_anything')
        );
    }

    /**
     * The guard makes the create idempotent, which is the whole point.
     *
     * Written as the migration writes it, so the pattern this method exists to
     * support is the thing under test rather than the method in isolation.
     */
    public function testTheGuardMakesCreationIdempotent(): void
    {
        // Arrange
        $schema = $this->db->schema();

        // Act — the same block twice, as a migration re-run would.
        for ($i = 0; $i < 2; $i++) {
            if (!$schema->hasIndex(self::TABLE, 'idx_probe_email')) {
                $schema->createUniqueIndex(self::TABLE, 'idx_probe_email', 'email');
            }
        }

        // Assert — no exception, and the index is there once.
        $this->assertTrue($schema->hasIndex(self::TABLE, 'idx_probe_email'));
    }
}
