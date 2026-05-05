<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Migration;

/**
 * Characterization tests for Migration base class — TimescaleDB-specific path.
 *
 * These tests document the behaviour of migrations that use TimescaleDB-native
 * features: hypertable creation, ifCapable() branching, and the fallback path
 * on plain PostgreSQL. This is the third database in the "× 3 databases"
 * requirement and the only one that exercises SchemaBuilder::createHypertable().
 *
 * A hypertable is physically different from a regular table: TimescaleDB
 * partitions it by the time column and registers it in
 * timescaledb_information.hypertables. After down(), it must not appear there.
 *
 * All table names carry the "cmig_tsdb_" prefix to avoid collisions.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432)
 * with the TimescaleDB extension installed and available.
 */
#[CoversClass(Migration::class)]
class MigrationTimescaleDBCharacterizationTest extends TestCase
{
    /** @var Database Live TimescaleDB connection. */
    protected Database $db;

    /** @var Application Mock application injected into migrations. */
    protected Application $app;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('TimescaleDB container not reachable (timescaledb:5432)');
        }

        // Skip if TimescaleDB extension is not actually installed.
        $caps = new DatabaseCapabilities($this->db);
        if (!$caps->has(DatabaseCapabilities::TIMESCALEDB)) {
            $this->markTestSkipped('TimescaleDB extension not present in this PostgreSQL instance');
        }

        $this->db->query('DROP TABLE IF EXISTS "cmig_tsdb_events" CASCADE');
        $this->db->query('DROP TABLE IF EXISTS "cmig_tsdb_ifcap" CASCADE');

        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "cmig_tsdb_events" CASCADE');
        $this->db->query('DROP TABLE IF EXISTS "cmig_tsdb_ifcap" CASCADE');
    }

    // -------------------------------------------------------------------------
    // Hypertable creation
    // -------------------------------------------------------------------------

    /**
     * A migration that calls SchemaBuilder::createHypertable() must register
     * the table in timescaledb_information.hypertables — not just create a
     * regular PostgreSQL table. This is the fundamental difference between a
     * hypertable migration and a plain DDL migration.
     */
    public function testMigrationUpCreatesHypertableRegisteredInTimescaleDB(): void
    {
        // Arrange
        $migration = new CharTsdbHypertableMigration($this->app);

        // Act
        $migration->up();

        // Assert – table exists as a regular PostgreSQL relation
        $tableCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_tsdb_events'
            )
        );
        $this->assertSame('1', (string) $tableCheck->fields['cnt'],
            'up() must create the base table in PostgreSQL');

        // Assert – table is registered as a TimescaleDB hypertable
        $htCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                'public',
                'cmig_tsdb_events'
            )
        );
        $this->assertSame('1', (string) $htCheck->fields['cnt'],
            'createHypertable() must register the table in timescaledb_information.hypertables');
    }

    /**
     * The time-partitioning column must be the one declared in createHypertable().
     * This prevents silent misconfiguration where a different column is used for
     * time-ordering than what the application assumes.
     */
    public function testHypertableUsesCorrectTimeColumn(): void
    {
        // Arrange + Act
        (new CharTsdbHypertableMigration($this->app))->up();

        // Assert – time dimension points to event_time
        $dimCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT column_name
                 FROM timescaledb_information.dimensions
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                'public',
                'cmig_tsdb_events'
            )
        );
        $this->assertNotNull($dimCheck->fields,
            'timescaledb_information.dimensions must have a row for the hypertable');
        $this->assertSame('event_time', $dimCheck->fields['column_name'],
            'The hypertable time dimension must be event_time, not any other column');
    }

    /**
     * down() must drop the hypertable and remove it from
     * timescaledb_information.hypertables. A hypertable that is dropped via
     * DROP TABLE CASCADE is automatically deregistered by TimescaleDB.
     */
    public function testMigrationDownDropsHypertable(): void
    {
        // Arrange
        $migration = new CharTsdbHypertableMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert – table gone from information_schema
        $tableCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_tsdb_events'
            )
        );
        $this->assertSame('0', (string) $tableCheck->fields['cnt'],
            'down() must drop the hypertable from PostgreSQL');

        // Assert – entry removed from hypertables view
        $htCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                'public',
                'cmig_tsdb_events'
            )
        );
        $this->assertSame('0', (string) $htCheck->fields['cnt'],
            'TimescaleDB must deregister the hypertable entry when the table is dropped');
    }

    // -------------------------------------------------------------------------
    // ifCapable branching inside a migration
    // -------------------------------------------------------------------------

    /**
     * A migration that uses DatabaseCapabilities::ifCapable(TIMESCALEDB, ...)
     * must take the TimescaleDB-specific branch on this container and create a
     * hypertable — not just a plain table.
     *
     * This characterises the current behaviour of the ifCapable conditional path
     * so that a future refactoring of ifCapable() does not silently degrade to
     * the fallback on a TimescaleDB-capable backend.
     */
    public function testIfCapableTakesTimescaleDBPathOnTimescaleDBBackend(): void
    {
        // Arrange
        $migration = new CharTsdbIfCapableMigration($this->app);

        // Act
        $migration->up();

        // Assert – the TimescaleDB path was taken: table is a hypertable
        $htCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                'public',
                'cmig_tsdb_ifcap'
            )
        );
        $this->assertSame('1', (string) $htCheck->fields['cnt'],
            'ifCapable(TIMESCALEDB, ...) must execute the hypertable branch on a TimescaleDB backend');
    }

    /**
     * down() of an ifCapable migration must drop both the table and its
     * hypertable registration, regardless of which branch created it.
     */
    public function testIfCapableMigrationDownDropsHypertable(): void
    {
        // Arrange
        $migration = new CharTsdbIfCapableMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert – table gone
        $tableCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_tsdb_ifcap'
            )
        );
        $this->assertSame('0', (string) $tableCheck->fields['cnt'],
            'down() must drop the table created by the ifCapable TimescaleDB branch');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return $app;
    }
}

// =============================================================================
// Concrete migration stubs
// =============================================================================

/**
 * Creates a regular PostgreSQL table and converts it to a hypertable.
 * The event_time column is the time dimension for TimescaleDB partitioning.
 */
class CharTsdbHypertableMigration extends Migration
{
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('cmig_tsdb_events')) {
            return;
        }

        $schema->createTable('cmig_tsdb_events', function ($table) {
            $table->bigInteger('id')->autoIncrement();
            $table->timestamp('event_time');
            $table->string('event_type', 50)->nullable();
            $table->text('payload')->nullable();
        });

        // Convert to hypertable — this is what makes it TimescaleDB-specific.
        // On plain PostgreSQL the SchemaBuilder emits a silent no-op.
        $schema->createHypertable('cmig_tsdb_events', 'event_time');
    }

    public function down(): void
    {
        // CASCADE removes the hypertable registration automatically.
        $this->application->database->schema()->dropTableIfExists('cmig_tsdb_events');
    }
}

/**
 * Uses DatabaseCapabilities::ifCapable(TIMESCALEDB, ...) to branch between
 * a hypertable and a plain table at migration time.
 */
class CharTsdbIfCapableMigration extends Migration
{
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('cmig_tsdb_ifcap')) {
            return;
        }

        $schema->createTable('cmig_tsdb_ifcap', function ($table) {
            $table->bigInteger('id')->autoIncrement();
            $table->timestamp('recorded_at');
            $table->string('source', 100)->nullable();
        });

        // ifCapable() branch: on TimescaleDB → hypertable; on MySQL/plain PG → no-op.
        $caps = new DatabaseCapabilities($this->application->database);
        $caps->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('cmig_tsdb_ifcap', 'recorded_at');
            }
            // No $ifFalse — plain table is already created above; no further action needed.
        );
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('cmig_tsdb_ifcap');
    }
}
