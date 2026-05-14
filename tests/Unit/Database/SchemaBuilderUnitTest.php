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
        // bigInteger without autoIncrement must remain plain BIGINT
        $this->assertEquals('BIGINT', $this->pgColumn('bigInteger'));
    }

    /**
     * bigInteger()->autoIncrement() is the cross-compatible pattern used by
     * migrations that need a signed BIGINT auto-increment on both MySQL and
     * PostgreSQL. On PostgreSQL this must compile to BIGSERIAL (not plain BIGINT),
     * otherwise the column has no sequence and INSERT without an explicit userid
     * fails with "null value in column violates not-null constraint".
     */
    public function testPGBigIntegerWithAutoIncrementMapsToBigSerial(): void
    {
        $this->assertEquals('BIGSERIAL', $this->pgColumn('bigInteger', ['autoIncrement' => true]));
    }

    /**
     * integer()->autoIncrement() must compile to SERIAL on PostgreSQL.
     */
    public function testPGIntegerWithAutoIncrementMapsToSerial(): void
    {
        $this->assertEquals('SERIAL', $this->pgColumn('integer', ['autoIncrement' => true]));
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

    /**
     * Non-unique indexes must be embedded inline as KEY clauses inside the
     * CREATE TABLE statement for MySQL — NOT emitted as separate CREATE INDEX
     * statements.
     *
     * Inline KEY clauses make the entire DDL operation atomic: there is no
     * window between CREATE TABLE and a follow-up CREATE INDEX where a
     * connection interruption could leave the table without its indexes.
     * MySQL has supported inline KEY syntax since the earliest InnoDB releases.
     */
    public function testMySQLCreateTableEmbeddsNonUniqueIndexesInline(): void
    {
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('logs', 'create');
        $bp->bigIncrements('logid');
        $bp->index(['user_id', 'created_at'], 'idx_logs_user_created');

        $stmts = $g->compileCreate($bp, 'logs');

        // MySQL must return exactly ONE statement — the full CREATE TABLE with
        // inline KEY, not a separate post-CREATE CREATE INDEX.
        $this->assertCount(1, $stmts,
            'MySQL compileCreate() must return a single CREATE TABLE statement with inline KEY clauses');

        // The inline KEY clause must appear inside the CREATE TABLE body.
        $this->assertStringContainsString('KEY `idx_logs_user_created`', $stmts[0],
            'Non-unique index must be declared as inline KEY inside CREATE TABLE');
        $this->assertStringContainsString('`user_id`', $stmts[0],
            'First column of the index must appear inside the CREATE TABLE body');
        $this->assertStringContainsString('`created_at`', $stmts[0],
            'Second column of the index must appear inside the CREATE TABLE body');

        // No separate CREATE INDEX must be emitted.
        $this->assertStringNotContainsString('CREATE INDEX', $stmts[0],
            'No separate CREATE INDEX statement must be present for MySQL');
    }

    /**
     * PostgreSQL must still emit separate post-CREATE CREATE INDEX statements
     * because the PostgreSQL CREATE TABLE syntax does not support inline KEY clauses.
     *
     * This test guards the symmetry: changing the MySQL grammar must not
     * accidentally break the PostgreSQL grammar which must continue to use
     * separate CREATE INDEX statements.
     */
    public function testPostgreSQLCreateTableStillEmitsPostCreateIndexStatements(): void
    {
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('logs', 'create');
        $bp->bigIncrements('logid');
        $bp->index(['user_id', 'created_at'], 'idx_logs_user_created');

        $stmts = $g->compileCreate($bp, 'logs');

        // PostgreSQL must return at least 2 statements: CREATE TABLE + CREATE INDEX.
        $this->assertGreaterThanOrEqual(2, count($stmts),
            'PostgreSQL compileCreate() must emit CREATE TABLE plus separate CREATE INDEX');

        // The CREATE INDEX must be a separate statement.
        $indexStmt = $stmts[1];
        $this->assertStringContainsString('CREATE INDEX', $indexStmt,
            'PostgreSQL must emit a separate CREATE INDEX statement for non-unique indexes');
        $this->assertStringContainsString('idx_logs_user_created', $indexStmt);
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
    // modifyColumn — MySQL grammar
    // =========================================================================

    /**
     * MySQL uses a single MODIFY COLUMN statement that rewrites the full column
     * definition. The output must include the column name, new type, and any
     * modifiers provided.
     */
    public function testMySQLModifyColumnEmitsSingleStatement(): void
    {
        // Arrange
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('bio', 'text')->nullable();

        // Act
        $stmts = $g->compileAlter($bp, 'users');

        // Assert — exactly one statement, MODIFY COLUMN syntax
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('ALTER TABLE `users`', $stmts[0]);
        $this->assertStringContainsString('MODIFY COLUMN', $stmts[0]);
        $this->assertStringContainsString('`bio`', $stmts[0]);
        $this->assertStringContainsString('TEXT', $stmts[0]);
    }

    /**
     * MySQL MODIFY COLUMN respects the nullable modifier on the column
     * definition — NOT NULL must appear when nullable is false.
     */
    public function testMySQLModifyColumnNotNull(): void
    {
        // Arrange
        $g  = new MySQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('status', 'string', ['length' => 255])->nullable(false)->default('active');

        // Act
        $sql = $g->compileAlter($bp, 'users')[0];

        // Assert — NOT NULL + DEFAULT in the statement
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }

    /**
     * Blueprint::modifyColumn() returns the ColumnDefinition it creates,
     * allowing fluent chaining of modifiers without a separate variable.
     */
    public function testModifyColumnReturnsFluent(): void
    {
        // Arrange
        $bp  = new Blueprint('users', 'alter');

        // Act — chain modifiers directly on the return value
        $col = $bp->modifyColumn('email', 'string', ['length' => 320])
                  ->nullable(false)
                  ->default('');

        // Assert — the returned object is a ColumnDefinition
        $this->assertInstanceOf(\Pramnos\Database\ColumnDefinition::class, $col);
        $this->assertSame('email', $col->name);
        $this->assertSame('string', $col->type);
        $this->assertFalse($col->get('nullable'));
    }

    /**
     * Blueprint::getModifiedColumns() returns all columns registered via
     * modifyColumn() — the grammar reads this list during compileAlter().
     */
    public function testGetModifiedColumnsReturnsAllRegistered(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('email', 'string');
        $bp->modifyColumn('bio', 'text');

        // Act
        $cols = $bp->getModifiedColumns();

        // Assert — two entries in order
        $this->assertCount(2, $cols);
        $this->assertSame('email', $cols[0]->name);
        $this->assertSame('bio',   $cols[1]->name);
    }

    // =========================================================================
    // modifyColumn — PostgreSQL grammar
    // =========================================================================

    /**
     * PostgreSQL modifyColumn emits a TYPE sub-statement (ALTER COLUMN … TYPE).
     * Unlike MySQL it does NOT emit a single MODIFY COLUMN — that syntax does
     * not exist in PostgreSQL.
     */
    public function testPGModifyColumnEmitsTypeStatement(): void
    {
        // Arrange
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('bio', 'text');

        // Act
        $stmts = $g->compileAlter($bp, 'users');

        // Assert — at least one statement, TYPE syntax, no MODIFY COLUMN
        $this->assertGreaterThan(0, count($stmts));
        $typeStmt = $stmts[0];
        $this->assertStringContainsString('ALTER TABLE "users"', $typeStmt);
        $this->assertStringContainsString('ALTER COLUMN "bio" TYPE', $typeStmt);
        $this->assertStringNotContainsString('MODIFY COLUMN', $typeStmt);
    }

    /**
     * PostgreSQL emits separate ALTER COLUMN statements for nullability and
     * default when those attributes are explicitly set.
     */
    public function testPGModifyColumnEmitsSeparateNullabilityAndDefault(): void
    {
        // Arrange
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('score', 'integer')->nullable(false)->default(0);

        // Act
        $stmts = $g->compileAlter($bp, 'users');

        // Assert — three statements: TYPE, SET NOT NULL, SET DEFAULT
        $this->assertCount(3, $stmts);
        $this->assertStringContainsString('TYPE', $stmts[0]);
        $this->assertStringContainsString('SET NOT NULL', $stmts[1]);
        $this->assertStringContainsString('SET DEFAULT', $stmts[2]);
    }

    /**
     * When only the type is changed (no nullable/default attributes set),
     * PostgreSQL emits exactly one statement — no spurious nullability or
     * default clauses are generated.
     */
    public function testPGModifyColumnTypeOnlyEmitsOneStatement(): void
    {
        // Arrange — no nullable, no default set
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('bio', 'text'); // no chained modifiers

        // Act
        $stmts = $g->compileAlter($bp, 'users');

        // Assert — exactly one TYPE statement
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('ALTER COLUMN "bio" TYPE', $stmts[0]);
    }

    /**
     * PostgreSQL nullable(true) emits DROP NOT NULL, not SET NOT NULL.
     */
    public function testPGModifyColumnNullableTrueEmitsDropNotNull(): void
    {
        // Arrange
        $g  = new PostgreSQLSchemaGrammar();
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('email', 'string', ['length' => 255])->nullable(true);

        // Act
        $stmts = $g->compileAlter($bp, 'users');

        // Assert — second statement is DROP NOT NULL
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('DROP NOT NULL', $stmts[1]);
    }

    // =========================================================================
    // ColumnDefinition::has()
    // =========================================================================

    /**
     * has() returns true only when the attribute was explicitly set, regardless
     * of its value. This is necessary to distinguish "nullable not set" from
     * "nullable explicitly set to false" in the PostgreSQL grammar.
     */
    public function testColumnDefinitionHasDistinguishesSetFromUnset(): void
    {
        // Arrange
        $col = new \Pramnos\Database\ColumnDefinition('x', 'integer');

        // Assert — unset attribute returns false
        $this->assertFalse($col->has('nullable'));

        // Act — set to false
        $col->nullable(false);

        // Assert — now has() returns true even though value is false
        $this->assertTrue($col->has('nullable'));
        $this->assertFalse($col->get('nullable'));
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
        // Arrange — on MySQL, dropTableIfExists wraps the DROP in FK-check toggles, so
        // query() is called three times: SET FK_CHECKS=0, DROP TABLE, SET FK_CHECKS=1.
        // We verify that at least one call contains the resolved table name.
        $db = $this->makeDB('mysql');
        $db->prefix = 'app_';

        $dropCalled = false;
        $db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use (&$dropCalled) {
                if (str_contains((string) $sql, 'app_users')) {
                    $dropCalled = true;
                }
                return null;
            });

        // Act
        $sb = new SchemaBuilder($db);
        $sb->dropTableIfExists('#PREFIX#users');

        // Assert — prefix was resolved and the DROP SQL contained the resolved name
        $this->assertTrue($dropCalled, 'query() must be called with SQL containing the resolved table name "app_users"');
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

    // =========================================================================
    // Helpers for the sections below
    // =========================================================================

    /**
     * Build a mock result object whose numRows, fields, and fetchAll() values
     * are fully controlled by the caller.
     *
     * SchemaBuilder methods use three facets of the query result:
     *   - numRows  — to distinguish empty from non-empty results
     *   - fields   — for single-value results (setval, nextval, isHypertable cnt)
     *   - fetchAll — for multi-row introspection queries (getHypertables, etc.)
     */
    private function fakeResult(array $rows = [], array $fields = []): object
    {
        return new class($rows, $fields) {
            public int   $numRows;
            public array $fields;
            private array $rows;

            public function __construct(array $rows, array $fields)
            {
                $this->rows    = $rows;
                $this->numRows = count($rows);
                $this->fields  = $fields;
            }

            public function fetchAll(): array { return $this->rows; }
        };
    }

    /**
     * Build a mock Database configured for the given dialect.
     * No query()/prepareQuery() defaults are set — each test must configure them.
     * This avoids PHPUnit stub-overriding issues when a test needs a specific return.
     */
    private function makeDBMock(string $type = 'mysql', bool $timescale = false): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery', 'queryBuilder', 'getInsertId'])
            ->getMock();
        $db->type      = $type;
        $db->timescale = $timescale;
        $db->prefix    = '';
        $db->schema    = '';
        $db->database  = 'testdb';
        // Return the SQL unchanged from prepareQuery (passthrough)
        $db->method('prepareQuery')->willReturnCallback(
            fn(string $sql, ...$args) => $sql
        );
        return $db;
    }

    // =========================================================================
    // Deprecated wrappers: create() → createTable(), drop() → dropTableIfExists()
    // =========================================================================

    /**
     * create() is a deprecated alias for createTable().
     *
     * Calling it must not throw and must forward the blueprint callback
     * to the grammar so that query() is invoked on the DB connection.
     */
    public function testCreateDeprecatedWrapperDelegatestoCreateTable(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $db->method('query')->willReturn(null); // accept all queries
        $sb = new SchemaBuilder($db);

        // Act — must run without exceptions (DB mock accepts any query() call)
        $sb->create('legacy_table', function (Blueprint $bp) {
            $bp->increments('id');
            $bp->string('name');
        });

        // Assert — reaching here means the wrapper delegated successfully
        $this->assertTrue(true);
    }

    /**
     * drop() is a deprecated alias for dropTableIfExists().
     *
     * Like create(), it must forward to the real method without throwing.
     */
    public function testDropDeprecatedWrapperDelegatesToDropTableIfExists(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $db->method('query')->willReturn(null); // accept any query
        $sb = new SchemaBuilder($db);

        // Act + Assert — should reach here without exception
        $sb->drop('legacy_table');
        $this->assertTrue(true);
    }

    // =========================================================================
    // setVal() — sequence current-value setter
    // =========================================================================

    /**
     * setVal() on MySQL returns 0 immediately because the grammar returns ''
     * for compileSetVal and the method short-circuits before issuing any query.
     *
     * This is the "no sequence support" no-op path.
     */
    public function testSetValOnMySQLReturnsZeroWithoutQuerying(): void
    {
        // Arrange — MySQL grammar's compileSetVal returns ''; query() must NOT be called
        $db = $this->makeDBMock('mysql');
        // No query() configuration needed — if called, the mock throws by default
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame(0, $sb->setVal('seq_users', 100));
    }

    /**
     * setVal() on PostgreSQL executes the compileSetVal SQL and returns the
     * value that the database reports as current.
     */
    public function testSetValOnPostgreSQLReturnsSetValue(): void
    {
        // Arrange — PG grammar compiles a real SQL string; mock returns value 42
        $db = $this->makeDBMock('postgresql');
        $db->method('query')->willReturn($this->fakeResult(
            [['setval' => 42]],
            ['setval' => 42]
        ));
        $sb = new SchemaBuilder($db);

        // Act + Assert — must return the value reported by setval()
        $this->assertSame(42, $sb->setVal('seq_users', 42));
    }

    /**
     * setVal() returns 0 when the query returns no rows (e.g. sequence missing).
     */
    public function testSetValReturnsZeroOnEmptyResult(): void
    {
        // Arrange — PG grammar compiles SQL; mock returns empty result
        $db = $this->makeDBMock('postgresql');
        $db->method('query')->willReturn($this->fakeResult([]));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame(0, $sb->setVal('seq_users', 100));
    }

    // =========================================================================
    // addRetentionPolicy() — TimescaleDB vs. software emulation
    // =========================================================================

    /**
     * addRetentionPolicy() on a TimescaleDB backend delegates to TimescaleDB's
     * native add_retention_policy() function via db->query().
     *
     * Returns the truthiness of the query result (null → false in the mock).
     */
    public function testAddRetentionPolicyOnTimescaleDBCallsNativeFunction(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null); // (bool)null = false
        $sb = new SchemaBuilder($db);

        // Act — TimescaleDB path returns (bool)db->query(...)
        $result = $sb->addRetentionPolicy('metrics', '90 days');

        // Assert — mock query() returns null → (bool)null = false, which is fine
        $this->assertFalse($result);
    }

    /**
     * addRetentionPolicy() on MySQL/plain PG inserts a row into
     * pramnos.framework_policies via the query builder and returns true when
     * getInsertId() > 0.
     */
    public function testAddRetentionPolicyNonTimescaleDBInsertsPolicy(): void
    {
        // Arrange — MySQL, no TimescaleDB
        $db = $this->makeDBMock('mysql');
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['table', 'insert', 'raw'])
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('insert')->willReturn(null);
        $qb->method('raw')->willReturn(new \Pramnos\Database\Expression('NOW()'));
        $db->method('queryBuilder')->willReturn($qb);
        $db->method('getInsertId')->willReturn('5');
        $sb = new SchemaBuilder($db);

        // Act
        $result = $sb->addRetentionPolicy('logs', '30 days');

        // Assert — insertId = 5 > 0 → true
        $this->assertTrue($result);
    }

    /**
     * addRetentionPolicy() returns false when getInsertId() returns 0 (insert
     * was a no-op, e.g. due to a duplicate or disabled trigger).
     */
    public function testAddRetentionPolicyReturnsFalseOnZeroInsertId(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['table', 'insert', 'raw'])
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('insert')->willReturn(null);
        $qb->method('raw')->willReturn(new \Pramnos\Database\Expression('NOW()'));
        $db->method('queryBuilder')->willReturn($qb);
        $db->method('getInsertId')->willReturn('0');
        $sb = new SchemaBuilder($db);

        // Act + Assert — insertId = 0 → false
        $this->assertFalse($sb->addRetentionPolicy('logs', '30 days'));
    }

    // =========================================================================
    // addContinuousAggregatePolicy()
    // =========================================================================

    /**
     * addContinuousAggregatePolicy() on TimescaleDB calls the native
     * add_continuous_aggregate_policy() function.
     */
    public function testAddContinuousAggregatePolicyOnTimescaleDB(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null); // (bool)null = false
        $sb = new SchemaBuilder($db);

        // Act — result is (bool)null = false (mock), which is the expected fallback
        $result = $sb->addContinuousAggregatePolicy('mv_hourly', '2 hours', '1 hour', '1 hour');
        $this->assertFalse($result);
    }

    /**
     * addContinuousAggregatePolicy() on MySQL/plain PG inserts a policy row.
     */
    public function testAddContinuousAggregatePolicyNonTimescaleDBInsertsPolicy(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['table', 'insert', 'raw'])
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('insert')->willReturn(null);
        $qb->method('raw')->willReturn(new \Pramnos\Database\Expression('NOW()'));
        $db->method('queryBuilder')->willReturn($qb);
        $db->method('getInsertId')->willReturn('7');
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertTrue($sb->addContinuousAggregatePolicy('mv_hourly', '2 hours', '1 hour', '1 hour'));
    }

    // =========================================================================
    // createContinuousAggregate() — three-way dispatch
    // =========================================================================

    /**
     * createContinuousAggregate() on TimescaleDB emits a CREATE MATERIALIZED
     * VIEW WITH (timescaledb.continuous = true) statement.
     */
    public function testCreateContinuousAggregateOnTimescaleDB(): void
    {
        // Arrange — TimescaleDB grammar selected automatically (timescale=true)
        $db = $this->makeDBMock('postgresql', true);
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act
        $sb->createContinuousAggregate('mv_stats', 'SELECT 1');

        // Assert — the CREATE MATERIALIZED VIEW WITH (timescaledb.continuous...) must appear
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString('timescaledb.continuous', $allSql);
        $this->assertStringContainsString('AS SELECT 1', $allSql);
    }

    /**
     * createContinuousAggregate() on plain PostgreSQL (no TimescaleDB) emits a
     * plain CREATE MATERIALIZED VIEW without the WITH clause.
     */
    public function testCreateContinuousAggregateOnPlainPostgreSQL(): void
    {
        // Arrange — plain PG, no TimescaleDB (timescale=false)
        $db = $this->makeDBMock('postgresql', false);
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function ($sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act
        $sb->createContinuousAggregate('mv_stats', 'SELECT 1');

        // Assert — must be CREATE MATERIALIZED VIEW without TimescaleDB options
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString('CREATE MATERIALIZED VIEW mv_stats AS SELECT 1', $allSql);
        $this->assertStringNotContainsString('timescaledb.continuous', $allSql);
    }

    /**
     * createContinuousAggregate() on MySQL falls back to a regular CREATE VIEW
     * since MySQL has no materialized view support.
     */
    public function testCreateContinuousAggregateOnMySQLFallsBackToView(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act
        $sb->createContinuousAggregate('mv_stats', 'SELECT 1');

        // Assert — plain VIEW, no MATERIALIZED keyword
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString('CREATE VIEW mv_stats AS SELECT 1', $allSql);
        $this->assertStringNotContainsString('MATERIALIZED', $allSql);
    }

    // =========================================================================
    // TimescaleDB introspection: getHypertables()
    // =========================================================================

    /**
     * getHypertables() returns [] immediately on non-TimescaleDB backends.
     */
    public function testGetHypertablesReturnsEmptyOnNonTimescaleDB(): void
    {
        // Arrange
        $sb = $this->makeSB('mysql');

        // Act + Assert — no DB query issued; early return
        $this->assertSame([], $sb->getHypertables());
    }

    /**
     * getHypertables() with no schema filter returns [] when the query finds
     * no hypertables (e.g. fresh installation).
     */
    public function testGetHypertablesNoRowsReturnsEmptyArray(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null); // null → !result → return []
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame([], $sb->getHypertables());
    }

    /**
     * getHypertables() with a schema filter passes the schema to prepareQuery
     * and returns mapped objects when rows are found.
     */
    public function testGetHypertablesWithSchemaReturnsObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['hypertable_schema' => 'public', 'hypertable_name' => 'metrics'],
            ['hypertable_schema' => 'public', 'hypertable_name' => 'events'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act
        $result = $sb->getHypertables('public');

        // Assert — two rows mapped to objects
        $this->assertCount(2, $result);
        $this->assertIsObject($result[0]);
        $this->assertSame('metrics', $result[0]->hypertable_name);
    }

    /**
     * getHypertables() without a schema argument queries all hypertables.
     */
    public function testGetHypertablesWithoutSchemaReturnsAllObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['hypertable_schema' => 'public', 'hypertable_name' => 'logs'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act + Assert — returns 1 object covering the no-schema branch
        $result = $sb->getHypertables();
        $this->assertCount(1, $result);
        $this->assertSame('logs', $result[0]->hypertable_name);
    }

    // =========================================================================
    // TimescaleDB introspection: isHypertable()
    // =========================================================================

    /**
     * isHypertable() returns false immediately on non-TimescaleDB backends.
     */
    public function testIsHypertableReturnsFalseOnNonTimescaleDB(): void
    {
        // Arrange + Act + Assert
        $this->assertFalse($this->makeSB('mysql')->isHypertable('metrics'));
    }

    /**
     * isHypertable() returns true when the count query reports at least one row.
     */
    public function testIsHypertableReturnsTrueWhenFound(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult(
            [['cnt' => 1]],
            ['cnt' => 1]
        ));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertTrue($sb->isHypertable('metrics', 'public'));
    }

    /**
     * isHypertable() uses resolveSchema() when no schema arg is given.
     *
     * On PostgreSQL with schema='', resolveSchema() returns '' — the grammar
     * adds the schema via prepareQuery. The test just verifies the code path runs.
     */
    public function testIsHypertableWithEmptySchemaUsesResolvedSchema(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        // Return a zero-count result — isHypertable checks (int)fields['cnt'] > 0
        $db->method('query')->willReturn($this->fakeResult([['cnt' => 0]], ['cnt' => 0]));
        $sb = new SchemaBuilder($db);

        // Act + Assert — count = 0 → false
        $this->assertFalse($sb->isHypertable('metrics'));
    }

    // =========================================================================
    // TimescaleDB introspection: getContinuousAggregates()
    // =========================================================================

    /**
     * getContinuousAggregates() returns [] on non-TimescaleDB backends.
     */
    public function testGetContinuousAggregatesReturnsEmptyOnNonTimescaleDB(): void
    {
        $this->assertSame([], $this->makeSB('mysql')->getContinuousAggregates());
    }

    /**
     * getContinuousAggregates() with a schema filter returns mapped objects.
     */
    public function testGetContinuousAggregatesWithSchemaReturnsObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['view_schema' => 'public', 'view_name' => 'mv_hourly'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $result = $sb->getContinuousAggregates('public');
        $this->assertCount(1, $result);
        $this->assertSame('mv_hourly', $result[0]->view_name);
    }

    /**
     * getContinuousAggregates() without a schema queries all aggregates.
     */
    public function testGetContinuousAggregatesWithoutSchemaCoversNoSchemaBranch(): void
    {
        // Arrange — query returns null (no rows) → returns []
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert — no-rows path returns []
        $this->assertSame([], $sb->getContinuousAggregates());
    }

    // =========================================================================
    // TimescaleDB introspection: getHypertableDimensions()
    // =========================================================================

    /**
     * getHypertableDimensions() returns [] on non-TimescaleDB backends.
     */
    public function testGetHypertableDimensionsReturnsEmptyOnNonTimescaleDB(): void
    {
        $this->assertSame([], $this->makeSB('mysql')->getHypertableDimensions('metrics'));
    }

    /**
     * getHypertableDimensions() resolves schema and returns mapped rows.
     */
    public function testGetHypertableDimensionsReturnsObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['dimension_type' => 'Time', 'column_name' => 'created_at', 'column_type' => 'TIMESTAMPTZ'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act + Assert — explicit schema skips resolveSchema()
        $result = $sb->getHypertableDimensions('metrics', 'public');
        $this->assertCount(1, $result);
        $this->assertSame('created_at', $result[0]->column_name);
    }

    /**
     * getHypertableDimensions() with empty schema calls resolveSchema() internally.
     */
    public function testGetHypertableDimensionsEmptySchemaUsesResolveSchema(): void
    {
        // Arrange — empty-schema branch triggers resolveSchema()
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert — empty schema triggers resolveSchema(); result is []
        $this->assertSame([], $sb->getHypertableDimensions('metrics'));
    }

    // =========================================================================
    // TimescaleDB introspection: getTimescaleJobs()
    // =========================================================================

    /**
     * getTimescaleJobs() returns [] on non-TimescaleDB backends.
     */
    public function testGetTimescaleJobsReturnsEmptyOnNonTimescaleDB(): void
    {
        $this->assertSame([], $this->makeSB('mysql')->getTimescaleJobs());
    }

    /**
     * getTimescaleJobs() with a process-name filter uses ILIKE.
     */
    public function testGetTimescaleJobsWithProcNameFilterReturnsObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['job_id' => 1, 'application_name' => 'Retention Policy'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act + Assert — filter path
        $result = $sb->getTimescaleJobs('Retention');
        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->job_id);
    }

    /**
     * getTimescaleJobs() without a filter queries all jobs.
     */
    public function testGetTimescaleJobsWithoutFilterCoversNoFilterBranch(): void
    {
        // Arrange — no rows returned
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame([], $sb->getTimescaleJobs());
    }

    // =========================================================================
    // TimescaleDB introspection: getChunks()
    // =========================================================================

    /**
     * getChunks() returns [] on non-TimescaleDB backends.
     */
    public function testGetChunksReturnsEmptyOnNonTimescaleDB(): void
    {
        $this->assertSame([], $this->makeSB('mysql')->getChunks());
    }

    /**
     * getChunks() without a table arg queries all chunks.
     */
    public function testGetChunksWithoutTableCoversAllChunksBranch(): void
    {
        // Arrange — no rows returned
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert — empty-table branch
        $this->assertSame([], $sb->getChunks());
    }

    /**
     * getChunks() with a table name and explicit schema returns mapped rows.
     */
    public function testGetChunksWithTableAndSchemaReturnsObjects(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn($this->fakeResult([
            ['hypertable_name' => 'metrics', 'chunk_name' => '_hyper_1_1_chunk'],
        ]));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $result = $sb->getChunks('metrics', 'public');
        $this->assertCount(1, $result);
        $this->assertSame('_hyper_1_1_chunk', $result[0]->chunk_name);
    }

    /**
     * getChunks() with table name but empty schema calls resolveSchema().
     */
    public function testGetChunksWithTableAndEmptySchemaUsesResolveSchema(): void
    {
        // Arrange — empty schema triggers resolveSchema()
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert — triggers resolveSchema() for the empty-schema branch
        $this->assertSame([], $sb->getChunks('metrics'));
    }

    // =========================================================================
    // getCapabilities() — accessor
    // =========================================================================

    /**
     * getCapabilities() returns the DatabaseCapabilities instance created during
     * construction. Callers use this to check which features are available.
     */
    public function testGetCapabilitiesReturnsDatabaseCapabilitiesInstance(): void
    {
        // Arrange
        $sb = $this->makeSB('mysql');

        // Act + Assert — must return a DatabaseCapabilities object
        $this->assertInstanceOf(DatabaseCapabilities::class, $sb->getCapabilities());
    }

    // =========================================================================
    // dropTable() — error-if-not-exists variant
    // =========================================================================

    /**
     * dropTable() emits DROP TABLE (no IF EXISTS guard) via db->query().
     *
     * Unlike dropTableIfExists(), this method does NOT wrap with FK checks —
     * callers are expected to know the table exists.
     */
    public function testDropTableEmitsDropTableWithoutIfExists(): void
    {
        // Arrange
        $db = $this->makeDBMock('mysql');
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act
        $sb->dropTable('users');

        // Assert — must NOT contain IF EXISTS (that's dropTableIfExists())
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString('DROP TABLE', $allSql);
        $this->assertStringNotContainsString('IF EXISTS', $allSql);
    }

    // =========================================================================
    // createHypertable() — TimescaleDB with options
    // =========================================================================

    /**
     * createHypertable() on non-TimescaleDB returns false without querying.
     */
    public function testCreateHypertableReturnsfalseOnNonTimescaleDB(): void
    {
        // Arrange + Act + Assert — no TimescaleDB → immediate false
        $this->assertFalse($this->makeSB('mysql')->createHypertable('metrics', 'time'));
    }

    /**
     * createHypertable() on TimescaleDB with bool and string options exercises
     * all three branches inside the foreach loop.
     *
     * The bool branch converts true/false to 'true'/'false'.
     * The interval-string branch wraps the value in INTERVAL '...'.
     * The non-interval string branch wraps the value in single quotes.
     */
    public function testCreateHypertableWithMixedOptionsCoversAllOptionBranches(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act — exercise all three option-encoding branches
        $result = $sb->createHypertable('metrics', 'time', [
            'if_not_exists'       => true,                  // bool → 'true'
            'chunk_time_interval' => '7 days',              // interval-string → INTERVAL '7 days'
            'associated_schema'   => 'public',              // plain-string → 'public'
        ]);

        // Assert — all options should appear in the captured SQL
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString("if_not_exists => true", $allSql);
        $this->assertStringContainsString("chunk_time_interval => INTERVAL '7 days'", $allSql);
        $this->assertStringContainsString("associated_schema => 'public'", $allSql);
    }

    // =========================================================================
    // addSpaceDimension() — TimescaleDB
    // =========================================================================

    /**
     * addSpaceDimension() returns false on non-TimescaleDB backends.
     */
    public function testAddSpaceDimensionReturnsFalseOnNonTimescaleDB(): void
    {
        $this->assertFalse($this->makeSB('mysql')->addSpaceDimension('metrics', 'device_id'));
    }

    /**
     * addSpaceDimension() on TimescaleDB queries add_dimension().
     */
    public function testAddSpaceDimensionOnTimescaleDBCallsAddDimension(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null); // (bool)null = false
        $sb = new SchemaBuilder($db);

        // Act — result is (bool)null = false
        $result = $sb->addSpaceDimension('metrics', 'device_id', 8);
        $this->assertFalse($result);
    }

    // =========================================================================
    // enableCompression() — TimescaleDB
    // =========================================================================

    /**
     * enableCompression() returns false on non-TimescaleDB backends.
     */
    public function testEnableCompressionReturnsFalseOnNonTimescaleDB(): void
    {
        $this->assertFalse($this->makeSB('mysql')->enableCompression('metrics'));
    }

    /**
     * enableCompression() on TimescaleDB with compression options exercises
     * the foreach loop that builds compress_* clauses.
     */
    public function testEnableCompressionOnTimescaleDBWithOptions(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $capturedSqls = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$capturedSqls) {
            $capturedSqls[] = $sql;
            return null;
        });
        $sb = new SchemaBuilder($db);

        // Act — options build timescaledb.compress_segmentby etc.
        $result = $sb->enableCompression('metrics', ['segmentby' => 'device_id', 'orderby' => 'time DESC']);

        // Assert — compress clause and options must appear
        $allSql = implode(' ', $capturedSqls);
        $this->assertStringContainsString('timescaledb.compress', $allSql);
        $this->assertStringContainsString('timescaledb.compress_segmentby', $allSql);
    }

    // =========================================================================
    // addCompressionPolicy() — TimescaleDB
    // =========================================================================

    /**
     * addCompressionPolicy() returns false on non-TimescaleDB backends.
     */
    public function testAddCompressionPolicyReturnsFalseOnNonTimescaleDB(): void
    {
        $this->assertFalse($this->makeSB('mysql')->addCompressionPolicy('metrics', '7 days'));
    }

    /**
     * addCompressionPolicy() on TimescaleDB queries add_compression_policy().
     */
    public function testAddCompressionPolicyOnTimescaleDB(): void
    {
        // Arrange
        $db = $this->makeDBMock('postgresql', true);
        $db->method('query')->willReturn(null);
        $sb = new SchemaBuilder($db);

        // Act + Assert — (bool)null = false
        $this->assertFalse($sb->addCompressionPolicy('metrics', '7 days'));
    }

    // =========================================================================
    // nextVal() — sequence next value
    // =========================================================================

    /**
     * nextVal() on MySQL returns 0 because the grammar returns '' for compileNextVal.
     */
    public function testNextValOnMySQLReturnsZero(): void
    {
        // Arrange — MySQL grammar compileNextVal returns ''
        $db = $this->makeDBMock('mysql');
        $sb = new SchemaBuilder($db);

        // Act + Assert — must return 0 without querying
        $this->assertSame(0, $sb->nextVal('seq_users'));
    }

    /**
     * nextVal() on PostgreSQL returns the sequence value from the result's first field.
     */
    public function testNextValOnPostgreSQLReturnsNextValue(): void
    {
        // Arrange — PG grammar compileNextVal returns real SQL; mock returns a value
        $db = $this->makeDBMock('postgresql');
        $db->method('query')->willReturn($this->fakeResult(
            [['nextval' => 7]],
            ['nextval' => 7]
        ));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame(7, $sb->nextVal('seq_users'));
    }

    /**
     * nextVal() returns 0 when the query returns no rows (sequence does not exist).
     */
    public function testNextValReturnsZeroOnEmptyResult(): void
    {
        // Arrange — PG grammar; mock returns empty result
        $db = $this->makeDBMock('postgresql');
        $db->method('query')->willReturn($this->fakeResult([]));
        $sb = new SchemaBuilder($db);

        // Act + Assert
        $this->assertSame(0, $sb->nextVal('seq_missing'));
    }
}
