<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;

/**
 * Characterization tests for Migration base class against PostgreSQL 14.
 *
 * Mirrors MigrationMySQLCharacterizationTest but exercises PostgreSQL-specific
 * behaviour: double-quote quoting, SERIAL auto-increment, TIMESTAMPTZ, and
 * information_schema catalog queries under the public schema.
 *
 * These tests lock in the observable behaviour before the planned refactoring
 * that adds a $this->schema() convenience helper to the base Migration class.
 *
 * All table names carry the "cmig_pg_" prefix to avoid collisions.
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb, port: 5432).
 */
#[CoversClass(Migration::class)]
class MigrationPostgreSQLCharacterizationTest extends TestCase
{
    /** @var Database Live PostgreSQL connection. */
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
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->db->query('DROP TABLE IF EXISTS "cmig_pg_legacy"');
        $this->db->query('DROP TABLE IF EXISTS "cmig_pg_schema"');

        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "cmig_pg_legacy"');
        $this->db->query('DROP TABLE IF EXISTS "cmig_pg_schema"');
    }

    // -------------------------------------------------------------------------
    // Legacy addQuery / executeQueries path
    // -------------------------------------------------------------------------

    /**
     * The legacy addQuery()+executeQueries() approach must create a real
     * PostgreSQL table. This confirms the raw-SQL path works under PG's
     * double-quote identifier quoting rules.
     */
    public function testLegacyAddQueryExecuteQueriesCreatesPostgreSQLTable(): void
    {
        // Arrange
        $migration = new CharPgLegacyMigration($this->app);

        // Act
        $migration->up();

        // Assert – table exists in public schema
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_pg_legacy'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'addQuery()+executeQueries() must create a real PostgreSQL table');
    }

    /**
     * Legacy down() must drop the PostgreSQL table.
     */
    public function testLegacyDownDropsPostgreSQLTable(): void
    {
        // Arrange
        $migration = new CharPgLegacyMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert – table gone
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_pg_legacy'
            )
        );
        $this->assertSame('0', (string) $result->fields['cnt'],
            'Legacy down() must drop the table from PostgreSQL');
    }

    // -------------------------------------------------------------------------
    // SchemaBuilder path
    // -------------------------------------------------------------------------

    /**
     * SchemaBuilder up() must create a PostgreSQL table with PG-native column
     * types: increments() → SERIAL (or BIGSERIAL), string() → VARCHAR.
     */
    public function testSchemaBuilderUpCreatesPostgreSQLTable(): void
    {
        // Arrange
        $migration = new CharPgSchemaBuilderMigration($this->app);

        // Act
        $migration->up();

        // Assert – table exists
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_pg_schema'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'SchemaBuilder up() must create the table in PostgreSQL');

        // Assert – auto-increment primary key mapped to an integer type
        $colResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT data_type FROM information_schema.columns
                 WHERE table_schema = %s AND table_name = %s AND column_name = 'id'",
                'public',
                'cmig_pg_schema'
            )
        );
        $this->assertNotNull($colResult->fields, 'id column must exist');
        $this->assertContains($colResult->fields['data_type'], ['integer', 'bigint'],
            'increments() must map to integer or bigint in PostgreSQL');
    }

    /**
     * SchemaBuilder down() must drop the PostgreSQL table.
     */
    public function testSchemaBuilderDownDropsPostgreSQLTable(): void
    {
        // Arrange
        $migration = new CharPgSchemaBuilderMigration($this->app);
        $migration->up();

        // Act
        $migration->down();

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_pg_schema'
            )
        );
        $this->assertSame('0', (string) $result->fields['cnt'],
            'SchemaBuilder down() must drop table from PostgreSQL');
    }

    /**
     * Calling up() twice must be idempotent under PostgreSQL.
     * The hasTable() guard prevents duplicate table errors on repeated runs.
     */
    public function testSchemaBuilderUpIsIdempotentOnPostgreSQL(): void
    {
        // Arrange
        $migration = new CharPgSchemaBuilderMigration($this->app);
        $migration->up();

        // Act – second call must not throw
        $migration->up();

        // Assert – table still present (guard worked, no error)
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'public',
                'cmig_pg_schema'
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'],
            'up() called twice must not crash and table must still exist');
    }

    // -------------------------------------------------------------------------
    // Metadata and helpers
    // -------------------------------------------------------------------------

    /**
     * Phase 4 metadata defaults must be identical across MySQL and PostgreSQL.
     * The runner uses these values regardless of the underlying database engine.
     */
    public function testMetadataDefaultsMatchPhase4Spec(): void
    {
        // Arrange
        $migration = new CharPgSchemaBuilderMigration($this->app);

        // Assert
        $this->assertSame('app', $migration->scope);
        $this->assertSame('',    $migration->feature);
        $this->assertSame(50,    $migration->priority);
        $this->assertSame([],    $migration->dependencies);
        $this->assertTrue($migration->autoExecute);
        $this->assertFalse($migration->transactional,
            'transactional must default to false — opt-in only; TimescaleDB DDL cannot run inside a transaction');
    }

    /**
     * getSlug() must convert CamelCase to snake_case on PostgreSQL just as it
     * does on MySQL — the slug derivation must be database-agnostic.
     */
    public function testGetSlugIsConsistentWithMySQLBehaviour(): void
    {
        // Arrange
        $migration = new CharPgSchemaBuilderMigration($this->app);

        // Act + Assert
        $this->assertSame('char_pg_schema_builder_migration', $migration->getSlug(),
            'getSlug() must produce the same snake_case conversion regardless of database backend');
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
 * Uses the legacy addQuery()/executeQueries() approach with PostgreSQL SQL.
 */
class CharPgLegacyMigration extends Migration
{
    public function up(): void
    {
        $this->addQuery(
            'CREATE TABLE IF NOT EXISTS "cmig_pg_legacy"'
            . ' (id SERIAL PRIMARY KEY, name VARCHAR(100))'
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery('DROP TABLE IF EXISTS "cmig_pg_legacy"');
        $this->executeQueries();
    }
}

/**
 * Uses the SchemaBuilder via $this->application->database->schema().
 */
class CharPgSchemaBuilderMigration extends Migration
{
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('cmig_pg_schema')) {
            return;
        }
        $schema->createTable('cmig_pg_schema', function ($table) {
            $table->increments('id');
            $table->string('name', 100)->nullable();
            $table->integer('created_at')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('cmig_pg_schema');
    }
}
