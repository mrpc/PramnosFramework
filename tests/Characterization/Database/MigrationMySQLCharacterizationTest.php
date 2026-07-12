<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;

/**
 * Characterization tests for Migration base class against MySQL 8.0.
 *
 * These tests lock in the observable behaviour of up()/down(),
 * addQuery()/executeQueries(), and SchemaBuilder access before the planned
 * refactoring that adds a $this->schema() convenience helper to the base class.
 * The addQuery/executeQueries mechanism must continue to work after any
 * refactoring to preserve BC for existing migrations in the wild.
 *
 * All table names carry the "cmig_my_" prefix to avoid collisions with other
 * test suites running concurrently.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
#[CoversClass(Migration::class)]
class MigrationMySQLCharacterizationTest extends TestCase
{
    /** @var Database Live MySQL connection. */
    protected Database $db;

    /** @var Application Mock application injected into migrations. */
    protected Application $app;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Arrange – Logger::log() inside executeQueries() requires LOG_PATH.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;

        if (!$this->db->connect(true)) {
            $this->markTestSkipped('MySQL container not reachable (db:3306)');
        }

        $this->db->query('DROP TABLE IF EXISTS `cmig_my_legacy`');
        $this->db->query('DROP TABLE IF EXISTS `cmig_my_schema`');

        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `cmig_my_legacy`');
        $this->db->query('DROP TABLE IF EXISTS `cmig_my_schema`');
    }

    // -------------------------------------------------------------------------
    // Legacy addQuery / executeQueries path
    // -------------------------------------------------------------------------

    /**
     * The legacy addQuery()+executeQueries() approach must physically create a
     * table in MySQL. This path is the original migration mechanism and must
     * continue to work after any base-class refactoring — existing deployments
     * have migrations that rely on it.
     */
    public function testLegacyAddQueryExecuteQueriesCreatesMySQLTable(): void
    {
        // Arrange
        $migration = new CharMyLegacyMigration($this->app);

        // Act
        $migration->up();

        // Assert – table was physically created
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'cmig_my_legacy'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'addQuery()+executeQueries() must create a real MySQL table');
    }

    /**
     * The legacy down() must drop the table created by up().
     * This proves the addQuery/executeQueries round-trip is reversible.
     */
    public function testLegacyDownDropsMySQLTable(): void
    {
        // Arrange
        $migration = new CharMyLegacyMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert – table is gone
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'cmig_my_legacy'
            )
        );
        $this->assertSame('0', (string) $result->fields['cnt'],
            'Legacy down() must physically drop the table from MySQL');
    }

    // -------------------------------------------------------------------------
    // SchemaBuilder path ($this->application->database->schema())
    // -------------------------------------------------------------------------

    /**
     * Migrations that use SchemaBuilder via $this->application->database->schema()
     * must create a MySQL table with correctly mapped column types.
     * This is the pattern used by all framework system migrations.
     */
    public function testSchemaBuilderUpCreatesMySQLTable(): void
    {
        // Arrange
        $migration = new CharMySchemaBuilderMigration($this->app);

        // Act
        $migration->up();

        // Assert – table exists
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'cmig_my_schema'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'SchemaBuilder up() must create the table in MySQL');

        // Assert – SchemaBuilder integer() maps to an integer column type
        $colResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'created_at'",
                'pramnos_test',
                'cmig_my_schema'
            )
        );
        $this->assertNotNull($colResult->fields, 'created_at column must exist after up()');
        $this->assertContains($colResult->fields['DATA_TYPE'], ['int', 'bigint'],
            'integer() must map to an INT or BIGINT column in MySQL');
    }

    /**
     * SchemaBuilder down() must drop the table from MySQL, proving the
     * dropTableIfExists helper is idempotent and functional.
     */
    public function testSchemaBuilderDownDropsMySQLTable(): void
    {
        // Arrange
        $migration = new CharMySchemaBuilderMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert – table no longer in information_schema
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'cmig_my_schema'
            )
        );
        $this->assertSame('0', (string) $result->fields['cnt'],
            'SchemaBuilder down() must drop table from MySQL');
    }

    /**
     * Calling up() twice must be idempotent — hasTable() guard prevents a
     * duplicate CREATE TABLE error that would crash the runner on re-run.
     */
    public function testSchemaBuilderUpIsIdempotent(): void
    {
        // Arrange
        $migration = new CharMySchemaBuilderMigration($this->app);
        $migration->up();

        // Act – second up() must not throw
        $migration->up();

        // Assert – no exception and table still exists (proves guard works)
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'cmig_my_schema'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'up() called twice must not crash and table must still exist');
    }

    // -------------------------------------------------------------------------
    // Metadata defaults
    // -------------------------------------------------------------------------

    /**
     * Phase 4 metadata defaults must match the documented specification.
     * MigrationRunner reads these to perform topological sort, autorun
     * filtering, and history recording — any deviation would break runner logic.
     */
    public function testMetadataDefaultsMatchPhase4Spec(): void
    {
        // Arrange – a migration that does not override any metadata
        $migration = new CharMySchemaBuilderMigration($this->app);

        // Assert – base-class defaults
        $this->assertSame('app',  $migration->scope,
            'scope must default to "app" for application-level migrations');
        $this->assertSame('',     $migration->feature,
            'feature must default to empty string for app-level migrations');
        $this->assertSame(50,     $migration->priority,
            'priority must default to 50 (mid-range)');
        $this->assertSame([],     $migration->dependencies,
            'dependencies must default to empty array');
        $this->assertTrue($migration->autoExecute,
            'autoExecute must default to true so the runner includes it without --force');
        $this->assertFalse($migration->transactional,
            'transactional must default to false — opt-in only; TimescaleDB and MySQL DDL cannot run inside a transaction');
    }

    /**
     * getSlug() on an inline CamelCase class must return its snake_case equivalent.
     * Migration history rows are keyed by this slug — the conversion logic must
     * remain stable or already-recorded rows become unresolvable.
     */
    public function testGetSlugConvertsClassNameToSnakeCase(): void
    {
        // Arrange
        $migration = new CharMySchemaBuilderMigration($this->app);

        // Act
        $slug = $migration->getSlug();

        // Assert – CamelCase → snake_case
        $this->assertSame('char_my_schema_builder_migration', $slug,
            'getSlug() must convert CamelCase class names to snake_case for history keying');
    }

    /**
     * getDescription() must return the public $description property value.
     */
    public function testGetDescriptionReturnsDescriptionProperty(): void
    {
        // Arrange
        $migration = new CharMyLegacyMigration($this->app);
        $migration->description = 'Creates the legacy test table';

        // Act + Assert
        $this->assertSame('Creates the legacy test table', $migration->getDescription());
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
// Concrete migration stubs — defined at file scope so ReflectionClass can read
// their filename (needed for getSlug() / getTimestamp()).
// =============================================================================

/**
 * Uses the legacy addQuery()/executeQueries() approach.
 * Documents BC requirement: existing migrations that queue raw SQL must continue
 * to work after any base-class refactoring.
 */
class CharMyLegacyMigration extends Migration
{
    public function up(): void
    {
        $this->addQuery(
            'CREATE TABLE IF NOT EXISTS `cmig_my_legacy`'
            . ' (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))'
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery('DROP TABLE IF EXISTS `cmig_my_legacy`');
        $this->executeQueries();
    }
}

/**
 * Uses the SchemaBuilder via $this->application->database->schema().
 * Documents the pattern employed by all framework system migrations.
 */
class CharMySchemaBuilderMigration extends Migration
{
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('cmig_my_schema')) {
            return;
        }
        $schema->createTable('cmig_my_schema', function ($table) {
            $table->increments('id');
            $table->string('name', 100)->nullable();
            $table->integer('created_at')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('cmig_my_schema');
    }
}
