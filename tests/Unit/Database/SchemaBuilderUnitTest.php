<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Database\Blueprint;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;
use Pramnos\Database\Grammar\TimescaleDBSchemaGrammar;

/**
 * Unit tests for SchemaBuilder, Blueprint, and SchemaGrammars.
 * No real database connection required.
 */
class SchemaBuilderUnitTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeDB(string $type = 'mysql', bool $timescale = false): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type      = $type;
        $db->timescale = $timescale;
        $db->prefix    = '';
        $db->schema    = '';
        return $db;
    }

    private function makeSB(string $type = 'mysql', bool $timescale = false): SchemaBuilder
    {
        return new SchemaBuilder($this->makeDB($type, $timescale));
    }

    // =========================================================================
    // Grammar selection
    // =========================================================================

    public function testMySQLGetsMySQLSchemaGrammar(): void
    {
        $sb = $this->makeSB('mysql');
        $this->assertInstanceOf(MySQLSchemaGrammar::class, $sb->getGrammar());
    }

    public function testPostgreSQLGetsPostgreSQLSchemaGrammar(): void
    {
        $sb = $this->makeSB('postgresql');
        $this->assertInstanceOf(PostgreSQLSchemaGrammar::class, $sb->getGrammar());
    }

    public function testTimescaleDBGetsTimescaleDBSchemaGrammar(): void
    {
        $sb = $this->makeSB('postgresql', true);
        $this->assertInstanceOf(TimescaleDBSchemaGrammar::class, $sb->getGrammar());
    }

    public function testSetGrammarReplacesGrammar(): void
    {
        $sb      = $this->makeSB('mysql');
        $grammar = new PostgreSQLSchemaGrammar();
        $sb->setGrammar($grammar);
        $this->assertSame($grammar, $sb->getGrammar());
    }

    // =========================================================================
    // MySQLSchemaGrammar — column types
    // =========================================================================

    private function mysqlColumn(string $type, array $attributes = []): string
    {
        $g   = new MySQLSchemaGrammar();
        $col = new \Pramnos\Database\ColumnDefinition('col', $type, $attributes);
        return $g->compileColumnType($col);
    }

    public function testMySQLInteger(): void
    {
        $this->assertEquals('INT', $this->mysqlColumn('integer'));
    }

    public function testMySQLIntegerUnsigned(): void
    {
        $this->assertEquals('INT UNSIGNED', $this->mysqlColumn('integer', ['unsigned' => true]));
    }

    public function testMySQLBigInteger(): void
    {
        $this->assertEquals('BIGINT', $this->mysqlColumn('bigInteger'));
    }

    public function testMySQLIncrements(): void
    {
        $this->assertEquals('INT UNSIGNED', $this->mysqlColumn('increments'));
    }

    public function testMySQLBigIncrements(): void
    {
        $this->assertEquals('BIGINT UNSIGNED', $this->mysqlColumn('bigIncrements'));
    }

    public function testMySQLString(): void
    {
        $this->assertEquals('VARCHAR(100)', $this->mysqlColumn('string', ['length' => 100]));
    }

    public function testMySQLStringDefaultLength(): void
    {
        $this->assertEquals('VARCHAR(255)', $this->mysqlColumn('string'));
    }

    public function testMySQLBoolean(): void
    {
        $this->assertEquals('TINYINT(1)', $this->mysqlColumn('boolean'));
    }

    public function testMySQLTimestampTzFallsBackToTimestamp(): void
    {
        $this->assertEquals('TIMESTAMP', $this->mysqlColumn('timestampTz'));
    }

    public function testMySQLJsonbFallsBackToJson(): void
    {
        $this->assertEquals('JSON', $this->mysqlColumn('jsonb'));
    }

    public function testMySQLUuid(): void
    {
        $this->assertEquals('CHAR(36)', $this->mysqlColumn('uuid'));
    }

    public function testMySQLEnum(): void
    {
        $result = $this->mysqlColumn('enum', ['values' => ['active', 'inactive']]);
        $this->assertEquals("ENUM('active', 'inactive')", $result);
    }

    public function testMySQLDecimal(): void
    {
        $result = $this->mysqlColumn('decimal', ['total' => 10, 'places' => 2]);
        $this->assertEquals('DECIMAL(10, 2)', $result);
    }

    public function testMySQLBinary(): void
    {
        $this->assertEquals('BLOB', $this->mysqlColumn('binary'));
    }

    public function testMySQLText(): void
    {
        $this->assertEquals('TEXT', $this->mysqlColumn('text'));
    }

    public function testMySQLMediumText(): void
    {
        $this->assertEquals('MEDIUMTEXT', $this->mysqlColumn('mediumText'));
    }

    public function testMySQLLongText(): void
    {
        $this->assertEquals('LONGTEXT', $this->mysqlColumn('longText'));
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — column types
    // =========================================================================

    private function pgColumn(string $type, array $attributes = []): string
    {
        $g   = new PostgreSQLSchemaGrammar();
        $col = new \Pramnos\Database\ColumnDefinition('col', $type, $attributes);
        return $g->compileColumnType($col);
    }

    public function testPGInteger(): void
    {
        $this->assertEquals('INTEGER', $this->pgColumn('integer'));
    }

    public function testPGBigInteger(): void
    {
        $this->assertEquals('BIGINT', $this->pgColumn('bigInteger'));
    }

    public function testPGIncrements(): void
    {
        $this->assertEquals('SERIAL', $this->pgColumn('increments'));
    }

    public function testPGBigIncrements(): void
    {
        $this->assertEquals('BIGSERIAL', $this->pgColumn('bigIncrements'));
    }

    public function testPGBoolean(): void
    {
        $this->assertEquals('BOOLEAN', $this->pgColumn('boolean'));
    }

    public function testPGTimestampTz(): void
    {
        $this->assertEquals('TIMESTAMPTZ', $this->pgColumn('timestampTz'));
    }

    public function testPGJsonb(): void
    {
        $this->assertEquals('JSONB', $this->pgColumn('jsonb'));
    }

    public function testPGUuid(): void
    {
        $this->assertEquals('UUID', $this->pgColumn('uuid'));
    }

    public function testPGBinary(): void
    {
        $this->assertEquals('BYTEA', $this->pgColumn('binary'));
    }

    public function testPGMediumTextFallsBackToText(): void
    {
        $this->assertEquals('TEXT', $this->pgColumn('mediumText'));
    }

    public function testPGLongTextFallsBackToText(): void
    {
        $this->assertEquals('TEXT', $this->pgColumn('longText'));
    }

    public function testPGUnsignedIntegerStripsModifier(): void
    {
        $this->assertEquals('INTEGER', $this->pgColumn('unsignedInteger'));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileCreate
    // =========================================================================

    public function testMySQLCreateTableContainsEngineAndCharset(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'create');
        $bp->bigIncrements('userid');
        $bp->string('username', 80)->unique();

        $stmts = $g->compileCreate($bp, 'users');
        $sql   = $stmts[0];

        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
        $this->assertStringContainsString('`userid` BIGINT UNSIGNED', $sql);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`userid`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY', $sql);
    }

    public function testMySQLCreateTableHasNotNullByDefault(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->string('name');

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    public function testMySQLCreateTableNullableColumn(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->string('bio')->nullable();

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('`bio` VARCHAR(255) NULL', $sql);
    }

    public function testMySQLCreateTableDefaultValue(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->integer('age')->default(0);

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }

    public function testMySQLCreateTableBooleanDefaultTrue(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->boolean('active')->default(true);

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('DEFAULT 1', $sql);
    }

    public function testMySQLCreateTableProducesPostCreateIndexStatements(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('logs', 'create');
        $bp->bigIncrements('logid');
        $bp->index(['user_id', 'created_at']);

        $stmts = $g->compileCreate($bp, 'logs');
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('CREATE INDEX', $stmts[1]);
        $this->assertStringContainsString('`user_id`', $stmts[1]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileCreate
    // =========================================================================

    public function testPGCreateTableNoEngineOptions(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'create');
        $bp->bigIncrements('userid');

        $sql = $g->compileCreate($bp, 'users')[0];
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringNotContainsString('ENGINE', $sql);
    }

    public function testPGCreateTableBigIncrementsIsBigserial(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'create');
        $bp->bigIncrements('userid');

        $sql = $g->compileCreate($bp, 'users')[0];
        $this->assertStringContainsString('"userid" BIGSERIAL', $sql);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    public function testPGCreateTableBooleanDefaultTrue(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->boolean('active')->default(true);

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('DEFAULT TRUE', $sql);
    }

    public function testPGCreateTableEnumAddsCheckConstraint(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('t', 'create');
        $bp->enum('status', ['active', 'inactive']);

        $sql = $g->compileCreate($bp, 't')[0];
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringContainsString("'active'", $sql);
        $this->assertStringContainsString("'inactive'", $sql);
    }

    public function testPGCreateTableForeignKeyAsAlterStatement(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('orders', 'create');
        $bp->bigIncrements('orderid');
        $bp->integer('userid');
        $bp->foreign('userid')->references('userid')->on('users')->cascadeOnDelete();

        $stmts = $g->compileCreate($bp, 'orders');
        // At least: CREATE TABLE + ALTER TABLE ... ADD CONSTRAINT
        $this->assertGreaterThanOrEqual(2, count($stmts));
        $alterSql = end($stmts);
        $this->assertStringContainsString('ALTER TABLE', $alterSql);
        $this->assertStringContainsString('FOREIGN KEY', $alterSql);
        $this->assertStringContainsString('CASCADE', $alterSql);
    }

    // =========================================================================
    // ALTER TABLE
    // =========================================================================

    public function testMySQLAlterAddColumn(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->string('phone', 20)->nullable();

        $stmts = $g->compileAlter($bp, 'users');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('ALTER TABLE `users` ADD COLUMN', $stmts[0]);
        $this->assertStringContainsString('`phone` VARCHAR(20) NULL', $stmts[0]);
    }

    public function testMySQLAlterAddColumnAfter(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->string('phone')->nullable()->after('email');

        $sql = $g->compileAlter($bp, 'users')[0];
        $this->assertStringContainsString('AFTER `email`', $sql);
    }

    public function testMySQLAlterDropColumn(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->dropColumn('old_field');

        $sql = $g->compileAlter($bp, 'users')[0];
        $this->assertStringContainsString('DROP COLUMN `old_field`', $sql);
    }

    public function testMySQLAlterRenameColumn(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->renameColumn('old_name', 'new_name');

        $sql = $g->compileAlter($bp, 'users')[0];
        $this->assertStringContainsString('RENAME COLUMN', $sql);
        $this->assertStringContainsString('`old_name`', $sql);
        $this->assertStringContainsString('`new_name`', $sql);
    }

    public function testPGAlterRenameColumn(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->renameColumn('email', 'email_address');

        $sql = $g->compileAlter($bp, 'users')[0];
        $this->assertStringContainsString('ALTER TABLE "users"', $sql);
        $this->assertStringContainsString('RENAME COLUMN', $sql);
        $this->assertStringContainsString('"email"', $sql);
        $this->assertStringContainsString('"email_address"', $sql);
    }

    // =========================================================================
    // DROP / RENAME table
    // =========================================================================

    public function testMySQLDropTable(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertEquals('DROP TABLE `users`', $g->compileDrop('users'));
    }

    public function testMySQLDropTableIfExists(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertEquals('DROP TABLE IF EXISTS `users`', $g->compileDropIfExists('users'));
    }

    public function testMySQLRenameTable(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertEquals('RENAME TABLE `old` TO `new`', $g->compileRename('old', 'new'));
    }

    public function testPGRenameTable(): void
    {
        $g = new PostgreSQLSchemaGrammar();
        $this->assertEquals('ALTER TABLE "old" RENAME TO "new"', $g->compileRename('old', 'new'));
    }

    // =========================================================================
    // Index DDL
    // =========================================================================

    public function testMySQLCreateIndex(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateIndex('users', 'idx_email', ['email'], false);
        $this->assertStringContainsString('CREATE INDEX idx_email ON `users`', $sql);
        $this->assertStringContainsString('`email`', $sql);
    }

    public function testMySQLCreateUniqueIndex(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateIndex('users', 'uq_email', ['email'], true);
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $sql);
    }

    public function testMySQLDropIndex(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileDropIndex('users', 'idx_email');
        $this->assertStringContainsString('ALTER TABLE `users` DROP INDEX', $sql);
    }

    public function testMySQLDropPrimary(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileDropIndex('users', 'PRIMARY');
        $this->assertStringContainsString('DROP PRIMARY KEY', $sql);
    }

    public function testPGDropIndex(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileDropIndex('users', 'idx_email');
        $this->assertStringContainsString('DROP INDEX IF EXISTS "idx_email"', $sql);
    }

    // =========================================================================
    // View DDL
    // =========================================================================

    public function testCreateView(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateView('active_users', 'SELECT * FROM users WHERE active = 1', false);
        $this->assertStringContainsString('CREATE VIEW active_users', $sql);
        $this->assertStringNotContainsString('OR REPLACE', $sql);
    }

    public function testCreateOrReplaceView(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateView('v', 'SELECT 1', true);
        $this->assertStringContainsString('CREATE OR REPLACE VIEW', $sql);
    }

    public function testDropView(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertStringContainsString('DROP VIEW IF EXISTS active_users',
            $g->compileDropView('active_users', true));
        $this->assertStringContainsString('DROP VIEW active_users',
            $g->compileDropView('active_users', false));
    }

    // =========================================================================
    // Materialized views
    // =========================================================================

    public function testPGCreateMaterializedView(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileCreateMaterializedView('mv_stats', 'SELECT count(*) FROM logs');
        $this->assertStringContainsString('CREATE MATERIALIZED VIEW', $sql);
    }

    public function testPGRefreshMaterializedView(): void
    {
        $g = new PostgreSQLSchemaGrammar();
        $this->assertStringContainsString('REFRESH MATERIALIZED VIEW mv_stats',
            $g->compileRefreshMaterializedView('mv_stats', false));
        $this->assertStringContainsString('CONCURRENTLY',
            $g->compileRefreshMaterializedView('mv_stats', true));
    }

    public function testPGDropMaterializedView(): void
    {
        $g = new PostgreSQLSchemaGrammar();
        $this->assertStringContainsString('DROP MATERIALIZED VIEW IF EXISTS mv_stats',
            $g->compileDropMaterializedView('mv_stats', true));
    }

    public function testMySQLMaterializedViewFallsBackToView(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateMaterializedView('mv', 'SELECT 1');
        $this->assertStringContainsString('CREATE VIEW mv', $sql);
        $this->assertStringNotContainsString('MATERIALIZED', $sql);
    }

    public function testMySQLRefreshMaterializedViewIsNoop(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertEquals('', $g->compileRefreshMaterializedView('mv', false));
    }

    // =========================================================================
    // SchemaBuilder — prefix resolution
    // =========================================================================

    public function testSchemaBuilderResolvesPrefix(): void
    {
        $db = $this->makeDB('mysql');
        $db->prefix = 'app_';

        $db->expects($this->atLeastOnce())
            ->method('query')
            ->with($this->stringContains('app_users'));

        $sb = new SchemaBuilder($db);
        $sb->dropTableIfExists('#PREFIX#users');
    }

    // =========================================================================
    // SchemaBuilder — ifCapable
    // =========================================================================

    public function testIfCapableCallsCallbackWhenCapabilityPresent(): void
    {
        $sb     = $this->makeSB('mysql');
        $called = false;
        $sb->ifCapable(DatabaseCapabilities::ENGINE_MYSQL, function ($schema) use (&$called) {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function testIfCapableCallsFallbackWhenAbsent(): void
    {
        $sb     = $this->makeSB('mysql');
        $called = false;
        $sb->ifCapable(
            DatabaseCapabilities::ENGINE_POSTGRESQL,
            function ($s) {},
            function ($s) use (&$called) { $called = true; }
        );
        $this->assertTrue($called);
    }

    public function testIfCapableReturnsNullWhenAbsentNoFallback(): void
    {
        $sb     = $this->makeSB('mysql');
        $result = $sb->ifCapable(DatabaseCapabilities::ENGINE_POSTGRESQL, fn($s) => 'yes');
        $this->assertNull($result);
    }

    public function testIfCapablePassesSchemaBuilderToCallback(): void
    {
        $sb       = $this->makeSB('mysql');
        $received = null;
        $sb->ifCapable(DatabaseCapabilities::ENGINE_MYSQL, function ($schema) use (&$received) {
            $received = $schema;
        });
        $this->assertSame($sb, $received);
    }

    // =========================================================================
    // DatabaseCapabilities — new constants and methods
    // =========================================================================

    private function makeCaps(string $type): DatabaseCapabilities
    {
        return new DatabaseCapabilities($this->makeDB($type));
    }

    public function testMaterializedViewsSupportedOnPostgreSQL(): void
    {
        $this->assertTrue($this->makeCaps('postgresql')->has(DatabaseCapabilities::MATERIALIZED_VIEWS));
    }

    public function testMaterializedViewsNotSupportedOnMySQL(): void
    {
        $this->assertFalse($this->makeCaps('mysql')->has(DatabaseCapabilities::MATERIALIZED_VIEWS));
    }

    public function testEnumsSupportedOnPostgreSQL(): void
    {
        $this->assertTrue($this->makeCaps('postgresql')->has(DatabaseCapabilities::ENUMS));
    }

    public function testEnumsNotSupportedOnMySQL(): void
    {
        $this->assertFalse($this->makeCaps('mysql')->has(DatabaseCapabilities::ENUMS));
    }

    public function testHasMaterializedViewsDelegatesToHas(): void
    {
        $this->assertTrue($this->makeCaps('postgresql')->hasMaterializedViews());
        $this->assertFalse($this->makeCaps('mysql')->hasMaterializedViews());
    }

    public function testHasEnumsDelegatesToHas(): void
    {
        $this->assertTrue($this->makeCaps('postgresql')->hasEnums());
        $this->assertFalse($this->makeCaps('mysql')->hasEnums());
    }

    // =========================================================================
    // Blueprint helpers
    // =========================================================================

    public function testTimestampsAddsCreatedAndUpdated(): void
    {
        $bp = new Blueprint('t');
        $bp->timestamps();
        $names = array_map(fn($c) => $c->name, $bp->getColumns());
        $this->assertContains('created_at', $names);
        $this->assertContains('updated_at', $names);
    }

    public function testSoftDeletesAddsDeletedAt(): void
    {
        $bp = new Blueprint('t');
        $bp->softDeletes();
        $names = array_map(fn($c) => $c->name, $bp->getColumns());
        $this->assertContains('deleted_at', $names);
    }

    public function testBlueprintIndexIsRecorded(): void
    {
        $bp = new Blueprint('t');
        $bp->integer('user_id');
        $bp->index('user_id', 'idx_user');
        $this->assertCount(1, $bp->getIndexes());
        $this->assertEquals('idx_user', $bp->getIndexes()[0]['name']);
    }

    public function testBlueprintForeignKeyIsRecorded(): void
    {
        $bp = new Blueprint('orders');
        $bp->foreign('user_id')->references('userid')->on('users');
        $this->assertCount(1, $bp->getForeignKeys());
        $fk = $bp->getForeignKeys()[0];
        $this->assertEquals('user_id', $fk->column);
        $this->assertEquals('userid', $fk->referencedColumn);
        $this->assertEquals('users', $fk->referencedTable);
    }

    public function testBlueprintDropColumnIsRecorded(): void
    {
        $bp = new Blueprint('t', 'alter');
        $bp->dropColumn(['a', 'b']);
        $this->assertEquals(['a', 'b'], $bp->getDroppedColumns());
    }

    public function testForeignKeyDefinitionCascades(): void
    {
        $fk = new \Pramnos\Database\ForeignKeyDefinition('user_id');
        $fk->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        $this->assertEquals('CASCADE', $fk->onDelete);
        $this->assertEquals('CASCADE', $fk->onUpdate);
    }

    // =========================================================================
    // Trigger DDL — grammar compilation
    // =========================================================================

    public function testMySQLTriggerCompilation(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileCreateTrigger(
            'trg_audit',
            'orders',
            'AFTER',
            'INSERT',
            'BEGIN INSERT INTO audit_log SET action = \'insert\'; END'
        );
        $this->assertStringContainsString('CREATE TRIGGER trg_audit', $sql);
        $this->assertStringContainsString('AFTER INSERT', $sql);
        $this->assertStringContainsString('ON `orders`', $sql);
        $this->assertStringContainsString('FOR EACH ROW', $sql);
    }

    public function testMySQLDropTrigger(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileDropTrigger('trg_audit', 'orders', true);
        $this->assertEquals('DROP TRIGGER IF EXISTS trg_audit', $sql);
    }

    public function testMySQLDropTriggerWithoutIfExists(): void
    {
        $g   = new MySQLSchemaGrammar();
        $sql = $g->compileDropTrigger('trg_audit', 'orders', false);
        $this->assertEquals('DROP TRIGGER trg_audit', $sql);
    }

    public function testPostgreSQLTriggerCompilation(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileCreateTrigger(
            'trg_notify',
            'events',
            'AFTER',
            'INSERT',
            'EXECUTE FUNCTION notify_event()'
        );
        $this->assertStringContainsString('CREATE OR REPLACE TRIGGER trg_notify', $sql);
        $this->assertStringContainsString('AFTER INSERT', $sql);
        $this->assertStringContainsString('ON "events"', $sql);
        $this->assertStringContainsString('EXECUTE FUNCTION notify_event()', $sql);
    }

    public function testPostgreSQLDropTrigger(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileDropTrigger('trg_notify', 'events', true);
        $this->assertStringContainsString('DROP TRIGGER IF EXISTS trg_notify', $sql);
        $this->assertStringContainsString('ON "events"', $sql);
    }

    public function testPostgreSQLStatementLevelTrigger(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileCreateTrigger('trg_stmt', 'logs', 'BEFORE', 'DELETE', 'EXECUTE FUNCTION check_delete()', 'STATEMENT');
        $this->assertStringContainsString('FOR EACH STATEMENT', $sql);
    }

    // =========================================================================
    // Sequence DDL — grammar compilation
    // =========================================================================

    public function testMySQLSequenceReturnsEmpty(): void
    {
        $g = new MySQLSchemaGrammar();
        $this->assertSame('', $g->compileCreateSequence('my_seq'));
        $this->assertSame('', $g->compileDropSequence('my_seq'));
    }

    public function testPostgreSQLCreateSequenceBasic(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileCreateSequence('order_id_seq');
        $this->assertStringContainsString('CREATE SEQUENCE IF NOT EXISTS order_id_seq', $sql);
        $this->assertStringContainsString('START WITH 1', $sql);
        $this->assertStringContainsString('INCREMENT BY 1', $sql);
        $this->assertStringContainsString('NO CYCLE', $sql);
    }

    public function testPostgreSQLCreateSequenceWithOptions(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileCreateSequence('my_seq', 100, 5, 1, 1000, true);
        $this->assertStringContainsString('START WITH 100', $sql);
        $this->assertStringContainsString('INCREMENT BY 5', $sql);
        $this->assertStringContainsString('MINVALUE 1', $sql);
        $this->assertStringContainsString('MAXVALUE 1000', $sql);
        $this->assertStringContainsString(' CYCLE', $sql);
        $this->assertStringNotContainsString('NO CYCLE', $sql);
    }

    public function testPostgreSQLDropSequence(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileDropSequence('order_id_seq', true);
        $this->assertEquals('DROP SEQUENCE IF EXISTS order_id_seq', $sql);
    }

    public function testPostgreSQLDropSequenceWithoutIfExists(): void
    {
        $g   = new PostgreSQLSchemaGrammar();
        $sql = $g->compileDropSequence('order_id_seq', false);
        $this->assertEquals('DROP SEQUENCE order_id_seq', $sql);
    }
}
