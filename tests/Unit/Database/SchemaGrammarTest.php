<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Blueprint;
use Pramnos\Database\ColumnDefinition;
use Pramnos\Database\Expression;
use Pramnos\Database\ForeignKeyDefinition;
use Pramnos\Database\Grammar\SchemaGrammar;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;

/**
 * Unit tests for SchemaGrammar, MySQLSchemaGrammar, and PostgreSQLSchemaGrammar.
 *
 * All SchemaGrammar methods are pure DDL string generators — they accept Blueprint
 * and ColumnDefinition data objects (zero external dependencies) and return SQL
 * strings.  No database connection is required.
 *
 * Coverage:
 * - SchemaGrammar::compileColumn (all modifiers)
 * - SchemaGrammar::compileDefaultValue (all PHP types + Expression)
 * - SchemaGrammar::compileCreate / compileAlter
 * - SchemaGrammar::compileDrop / compileDropIfExists / compileRename
 * - SchemaGrammar::compileCreateIndex / compileDropIndex
 * - SchemaGrammar::compileCreateView / compileDropView
 * - SchemaGrammar::compileCreateMaterializedView / compileRefreshMaterializedView
 * - SchemaGrammar::compileCreateTrigger / compileDropTrigger
 * - SchemaGrammar::compileHasTable / compileHasColumn
 * - MySQLSchemaGrammar: quoting, column types, table options, inline FK/indexes,
 *   column positioning, DROP INDEX, RENAME TABLE, sequences (no-op)
 * - PostgreSQLSchemaGrammar: quoting (schema-qualified), column types (SERIAL,
 *   BOOLEAN, TEXT, BYTEA, JSONB, UUID, TIMESTAMPTZ), DEFAULT TRUE/FALSE,
 *   enum CHECK constraint, separate FK ALTER statements, column comments,
 *   compileModifyColumn (separate ALTER COLUMN clauses), DROP INDEX, sequences,
 *   materialized views, triggers
 */
#[CoversClass(SchemaGrammar::class)]
#[CoversClass(MySQLSchemaGrammar::class)]
#[CoversClass(PostgreSQLSchemaGrammar::class)]
class SchemaGrammarTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function mysqlGrammar(): MySQLSchemaGrammar
    {
        return new MySQLSchemaGrammar();
    }

    private function pgGrammar(): PostgreSQLSchemaGrammar
    {
        return new PostgreSQLSchemaGrammar();
    }

    /**
     * Build a minimal Blueprint with a single string column.
     */
    private function simpleBlueprint(string $table = 'users'): Blueprint
    {
        $bp = new Blueprint($table);
        $bp->string('name');
        return $bp;
    }

    // =========================================================================
    // SchemaGrammar::compileDefaultValue() (via MySQLSchemaGrammar)
    // =========================================================================

    /**
     * Default value for a string must be single-quoted.
     */
    public function testCompileDefaultValueForString(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($grammar, "O'Reilly");

        // Assert — special chars must be escaped inside the quotes
        $this->assertSame("'O\\'Reilly'", $result);
    }

    /**
     * NULL default produces the SQL NULL literal (no quotes).
     */
    public function testCompileDefaultValueForNull(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('NULL', $ref->invoke($grammar, null));
    }

    /**
     * Integer default produces an unquoted numeric literal.
     */
    public function testCompileDefaultValueForInt(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('42', $ref->invoke($grammar, 42));
    }

    /**
     * Float default produces an unquoted decimal literal.
     */
    public function testCompileDefaultValueForFloat(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('3.14', $ref->invoke($grammar, 3.14));
    }

    /**
     * MySQL: boolean true default uses integer 1.
     */
    public function testMysqlDefaultValueBoolTrueUses1(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('1', $ref->invoke($grammar, true));
    }

    /**
     * MySQL: boolean false default uses integer 0.
     */
    public function testMysqlDefaultValueBoolFalseUses0(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('0', $ref->invoke($grammar, false));
    }

    /**
     * Expression default is injected verbatim (e.g. CURRENT_TIMESTAMP).
     */
    public function testCompileDefaultValueForExpression(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($grammar, new Expression('CURRENT_TIMESTAMP'));

        // Assert — no quoting applied to an Expression
        $this->assertSame('CURRENT_TIMESTAMP', $result);
    }

    /**
     * PostgreSQL: boolean true default uses the TRUE literal (not 1).
     */
    public function testPgDefaultValueBoolTrueUsesTrue(): void
    {
        // Arrange
        $grammar = $this->pgGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('TRUE', $ref->invoke($grammar, true));
    }

    /**
     * PostgreSQL: boolean false default uses the FALSE literal.
     */
    public function testPgDefaultValueBoolFalseUsesFalse(): void
    {
        // Arrange
        $grammar = $this->pgGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert
        $this->assertSame('FALSE', $ref->invoke($grammar, false));
    }

    // =========================================================================
    // SchemaGrammar::compileColumn()
    // =========================================================================

    /**
     * A NOT NULL column without defaults emits the column name, type, NOT NULL.
     */
    public function testCompileColumnBasicNotNull(): void
    {
        // Arrange
        $col = new ColumnDefinition('email', 'string', ['length' => 255]);

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString('`email`', $sql);
        $this->assertStringContainsString('VARCHAR(255)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringNotContainsString('NULL ', $sql); // No bare NULL
    }

    /**
     * A nullable column emits NULL instead of NOT NULL.
     */
    public function testCompileColumnNullable(): void
    {
        // Arrange
        $col = (new ColumnDefinition('deleted_at', 'timestamp'))->nullable();

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString(' NULL', $sql);
        $this->assertStringNotContainsString('NOT NULL', $sql);
    }

    /**
     * A column with a scalar default emits DEFAULT value.
     */
    public function testCompileColumnWithDefault(): void
    {
        // Arrange
        $col = (new ColumnDefinition('status', 'string', ['length' => 20]))->default('pending');

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString("DEFAULT 'pending'", $sql);
    }

    /**
     * Auto-increment column emits the AUTO_INCREMENT keyword (MySQL).
     */
    public function testMysqlCompileColumnAutoIncrement(): void
    {
        // Arrange
        $col = new ColumnDefinition('id', 'increments', ['autoIncrement' => true, 'primary' => true]);

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    /**
     * PostgreSQL does NOT add AUTO_INCREMENT — SERIAL type handles it.
     */
    public function testPgCompileColumnNoAutoIncrementKeyword(): void
    {
        // Arrange
        $col = new ColumnDefinition('id', 'increments', ['autoIncrement' => true, 'primary' => true]);

        // Act
        $sql = $this->pgGrammar()->compileColumn($col);

        // Assert
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('SERIAL', $sql);
    }

    /**
     * A CHECK constraint is appended after the column definition.
     */
    public function testCompileColumnWithCheck(): void
    {
        // Arrange
        $col = (new ColumnDefinition('age', 'integer'))->check('age > 0');

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString('CHECK (age > 0)', $sql);
    }

    /**
     * A GENERATED ALWAYS AS ... STORED column short-circuits normal modifiers.
     */
    public function testCompileColumnStoredAs(): void
    {
        // Arrange
        $col = (new ColumnDefinition('full_name', 'string'))->storedAs("CONCAT(first, ' ', last)");

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString('GENERATED ALWAYS AS', $sql);
        $this->assertStringContainsString('STORED', $sql);
    }

    /**
     * A virtual generated column uses AS (...) syntax (no STORED).
     */
    public function testCompileColumnVirtualAs(): void
    {
        // Arrange
        $col = (new ColumnDefinition('total', 'decimal'))->virtualAs('qty * price');

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString('AS (qty * price)', $sql);
        $this->assertStringNotContainsString('STORED', $sql);
    }

    /**
     * MySQL inline column comment uses COMMENT '...' syntax.
     */
    public function testMysqlCompileColumnComment(): void
    {
        // Arrange
        $col = (new ColumnDefinition('email', 'string'))->comment("User's primary email");

        // Act
        $sql = $this->mysqlGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString("COMMENT 'User\\'s primary email'", $sql);
    }

    // =========================================================================
    // MySQLSchemaGrammar — quoting
    // =========================================================================

    /**
     * MySQL quoteTable wraps the table name in backticks.
     */
    public function testMysqlQuoteTableUsesBackticks(): void
    {
        $this->assertSame('`users`', $this->mysqlGrammar()->quoteTable('users'));
    }

    /**
     * MySQL quoteColumn wraps the column name in backticks.
     */
    public function testMysqlQuoteColumnUsesBackticks(): void
    {
        $this->assertSame('`id`', $this->mysqlGrammar()->quoteColumn('id'));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileColumnType()
    // =========================================================================

    /**
     * MySQL maps 'tinyInteger' to TINYINT.
     */
    public function testMysqlColumnTypeTinyInteger(): void
    {
        $col = new ColumnDefinition('flag', 'tinyInteger');
        $this->assertSame('TINYINT', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'smallInteger' to SMALLINT.
     */
    public function testMysqlColumnTypeSmallInteger(): void
    {
        $col = new ColumnDefinition('rank', 'smallInteger');
        $this->assertSame('SMALLINT', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'integer' to INT (with optional UNSIGNED).
     */
    public function testMysqlColumnTypeInteger(): void
    {
        $col = new ColumnDefinition('count', 'integer');
        $this->assertSame('INT', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps unsigned 'integer' to INT UNSIGNED.
     */
    public function testMysqlColumnTypeIntegerUnsigned(): void
    {
        $col = new ColumnDefinition('count', 'integer', ['unsigned' => true]);
        $this->assertSame('INT UNSIGNED', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'bigInteger' to BIGINT.
     */
    public function testMysqlColumnTypeBigInteger(): void
    {
        $col = new ColumnDefinition('user_id', 'bigInteger');
        $this->assertSame('BIGINT', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'increments' to INT UNSIGNED.
     */
    public function testMysqlColumnTypeIncrements(): void
    {
        $col = new ColumnDefinition('id', 'increments');
        $this->assertSame('INT UNSIGNED', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'bigIncrements' to BIGINT UNSIGNED.
     */
    public function testMysqlColumnTypeBigIncrements(): void
    {
        $col = new ColumnDefinition('id', 'bigIncrements');
        $this->assertSame('BIGINT UNSIGNED', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'string' to VARCHAR with the given length.
     */
    public function testMysqlColumnTypeString(): void
    {
        $col = new ColumnDefinition('name', 'string', ['length' => 100]);
        $this->assertSame('VARCHAR(100)', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'char' to CHAR with the given length.
     */
    public function testMysqlColumnTypeChar(): void
    {
        $col = new ColumnDefinition('code', 'char', ['length' => 3]);
        $this->assertSame('CHAR(3)', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps all text variants to their respective TEXT types.
     */
    public function testMysqlColumnTypeTextVariants(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('TEXT',       $grammar->compileColumnType(new ColumnDefinition('x', 'text')));
        $this->assertSame('MEDIUMTEXT', $grammar->compileColumnType(new ColumnDefinition('x', 'mediumText')));
        $this->assertSame('LONGTEXT',   $grammar->compileColumnType(new ColumnDefinition('x', 'longText')));
    }

    /**
     * MySQL maps 'decimal' to DECIMAL(total, places).
     */
    public function testMysqlColumnTypeDecimal(): void
    {
        $col = new ColumnDefinition('price', 'decimal', ['total' => 10, 'places' => 2]);
        $this->assertSame('DECIMAL(10, 2)', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'boolean' to TINYINT(1).
     */
    public function testMysqlColumnTypeBoolean(): void
    {
        $col = new ColumnDefinition('active', 'boolean');
        $this->assertSame('TINYINT(1)', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps date/time types to their SQL equivalents.
     */
    public function testMysqlColumnTypeDateTimeTypes(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('DATE',      $grammar->compileColumnType(new ColumnDefinition('x', 'date')));
        $this->assertSame('TIME',      $grammar->compileColumnType(new ColumnDefinition('x', 'time')));
        $this->assertSame('DATETIME',  $grammar->compileColumnType(new ColumnDefinition('x', 'dateTime')));
        $this->assertSame('TIMESTAMP', $grammar->compileColumnType(new ColumnDefinition('x', 'timestamp')));
        $this->assertSame('TIMESTAMP', $grammar->compileColumnType(new ColumnDefinition('x', 'timestampTz')));
        $this->assertSame('YEAR',      $grammar->compileColumnType(new ColumnDefinition('x', 'year')));
    }

    /**
     * MySQL maps 'binary' to BLOB.
     */
    public function testMysqlColumnTypeBinary(): void
    {
        $col = new ColumnDefinition('data', 'binary');
        $this->assertSame('BLOB', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps both 'json' and 'jsonb' to JSON.
     */
    public function testMysqlColumnTypeJson(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('JSON', $grammar->compileColumnType(new ColumnDefinition('x', 'json')));
        $this->assertSame('JSON', $grammar->compileColumnType(new ColumnDefinition('x', 'jsonb')));
    }

    /**
     * MySQL maps 'uuid' to CHAR(36).
     */
    public function testMysqlColumnTypeUUID(): void
    {
        $col = new ColumnDefinition('id', 'uuid');
        $this->assertSame('CHAR(36)', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL maps 'enum' to ENUM(...) with quoted values.
     */
    public function testMysqlColumnTypeEnum(): void
    {
        $col = new ColumnDefinition('status', 'enum', ['values' => ['pending', 'active', 'done']]);
        $sql = $this->mysqlGrammar()->compileColumnType($col);
        $this->assertSame("ENUM('pending', 'active', 'done')", $sql);
    }

    /**
     * MySQL maps spatial types to their MySQL names.
     */
    public function testMysqlColumnTypeSpatial(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('GEOMETRY', $grammar->compileColumnType(new ColumnDefinition('x', 'geometry')));
        $this->assertSame('POINT',    $grammar->compileColumnType(new ColumnDefinition('x', 'point')));
    }

    /**
     * Unknown column type falls back to uppercased type name.
     */
    public function testMysqlColumnTypeUnknownFallsBackToUppercased(): void
    {
        $col = new ColumnDefinition('x', 'custom_type');
        $this->assertSame('CUSTOM_TYPE', $this->mysqlGrammar()->compileColumnType($col));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileCreate()
    // =========================================================================

    /**
     * compileCreate() returns a single CREATE TABLE statement with column definitions.
     *
     * MySQL embeds ENGINE=InnoDB and charset in the same statement — no separate
     * table options statements are emitted.
     */
    public function testMysqlCompileCreateBasicTable(): void
    {
        // Arrange
        $bp = new Blueprint('users');
        $bp->increments('id');
        $bp->string('name', 100);
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'users');

        // Assert — single statement array
        $this->assertCount(1, $statements);
        $sql = $statements[0];
        $this->assertStringStartsWith('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('`id` INT UNSIGNED', $sql);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`name` VARCHAR(100)', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
    }

    /**
     * compileCreate() includes a PRIMARY KEY clause when increments() is used.
     */
    public function testMysqlCompileCreateWithPrimaryKey(): void
    {
        // Arrange
        $bp = new Blueprint('orders');
        $bp->increments('id');
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'orders')[0];

        // Assert
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    /**
     * compileCreate() includes a table comment in the ENGINE option string.
     */
    public function testMysqlCompileCreateWithTableComment(): void
    {
        // Arrange
        $bp = new Blueprint('logs');
        $bp->string('message');
        $bp->comment("System logs table");
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'logs')[0];

        // Assert
        $this->assertStringContainsString("COMMENT='System logs table'", $sql);
    }

    /**
     * MySQL embeds UNIQUE KEY inside CREATE TABLE (inlineIndexes = true).
     */
    public function testMysqlCompileCreateWithUniqueConstraint(): void
    {
        // Arrange
        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->unique('email', 'uq_users_email');
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'users')[0];

        // Assert — inline UNIQUE KEY (MySQL dialect)
        $this->assertStringContainsString("UNIQUE KEY `uq_users_email`", $sql);
    }

    /**
     * MySQL embeds non-unique KEY clauses inline in CREATE TABLE.
     */
    public function testMysqlCompileCreateWithInlineIndex(): void
    {
        // Arrange
        $bp = new Blueprint('logs');
        $bp->string('level');
        $bp->index('level', 'idx_logs_level');
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'logs')[0];

        // Assert — inline KEY (MySQL dialect)
        $this->assertStringContainsString('KEY `idx_logs_level` (`level`)', $sql);
    }

    /**
     * MySQL embeds foreign keys inline in CREATE TABLE.
     */
    public function testMysqlCompileCreateWithInlineForeignKey(): void
    {
        // Arrange
        $bp = new Blueprint('posts');
        $bp->string('title');
        $bp->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'posts');

        // Assert — FK is inline (one statement only)
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('FOREIGN KEY', $statements[0]);
        $this->assertStringContainsString('REFERENCES `users` (`id`)', $statements[0]);
        $this->assertStringContainsString('ON DELETE CASCADE', $statements[0]);
    }

    /**
     * TEMPORARY table produces CREATE TEMPORARY TABLE.
     */
    public function testMysqlCompileCreateTemporaryTable(): void
    {
        // Arrange
        $bp = new Blueprint('tmp_work');
        $bp->temporary();
        $bp->string('data');
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'tmp_work')[0];

        // Assert
        $this->assertStringContainsString('CREATE TEMPORARY TABLE', $sql);
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileAlter()
    // =========================================================================

    /**
     * ALTER TABLE ADD COLUMN is compiled from columns() added in alter mode.
     */
    public function testMysqlCompileAlterAddColumn(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->string('phone', 20)->nullable();
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('ALTER TABLE `users` ADD COLUMN', $statements[0]);
        $this->assertStringContainsString('`phone` VARCHAR(20)', $statements[0]);
    }

    /**
     * ALTER TABLE DROP COLUMN is compiled from dropColumn().
     */
    public function testMysqlCompileAlterDropColumn(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->dropColumn('legacy_field');
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE `users` DROP COLUMN `legacy_field`', $statements[0]);
    }

    /**
     * MySQL RENAME COLUMN uses RENAME COLUMN from ... TO ... syntax.
     */
    public function testMysqlCompileAlterRenameColumn(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->renameColumn('fname', 'first_name');
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertCount(1, $statements);
        $this->assertSame(
            'ALTER TABLE `users` RENAME COLUMN `fname` TO `first_name`',
            $statements[0]
        );
    }

    /**
     * MySQL MODIFY COLUMN emits a single MODIFY COLUMN statement.
     */
    public function testMysqlCompileAlterModifyColumn(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('email', 'string', ['length' => 320]);
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('MODIFY COLUMN', $statements[0]);
        $this->assertStringContainsString('VARCHAR(320)', $statements[0]);
    }

    /**
     * ADD UNIQUE INDEX via alterTable() compiles to CREATE UNIQUE INDEX.
     */
    public function testMysqlCompileAlterAddUniqueIndex(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->unique('email', 'uq_users_email');
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE UNIQUE INDEX uq_users_email', $statements[0]);
    }

    /**
     * DROP INDEX on a non-PRIMARY key produces ALTER TABLE DROP INDEX.
     */
    public function testMysqlCompileDropIndexNonPrimary(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileDropIndex('users', 'idx_users_email');

        // Assert
        $this->assertSame('ALTER TABLE `users` DROP INDEX `idx_users_email`', $sql);
    }

    /**
     * DROP PRIMARY KEY on MySQL uses ALTER TABLE DROP PRIMARY KEY.
     */
    public function testMysqlCompileDropIndexPrimary(): void
    {
        // Arrange
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileDropIndex('users', 'PRIMARY');

        // Assert
        $this->assertSame('ALTER TABLE `users` DROP PRIMARY KEY', $sql);
    }

    /**
     * COLUMN AFTER positioning uses AFTER `col` clause.
     */
    public function testMysqlColumnPositionAfter(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $col = $bp->string('phone')->after('email');
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertStringContainsString('AFTER `email`', $statements[0]);
    }

    /**
     * COLUMN FIRST positioning uses FIRST clause.
     */
    public function testMysqlColumnPositionFirst(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->string('code')->first();
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert
        $this->assertStringContainsString(' FIRST', $statements[0]);
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileDrop / compileDropIfExists / compileRename
    // =========================================================================

    /**
     * compileDrop() produces DROP TABLE `name`.
     */
    public function testMysqlCompileDrop(): void
    {
        $sql = $this->mysqlGrammar()->compileDrop('old_table');
        $this->assertSame('DROP TABLE `old_table`', $sql);
    }

    /**
     * compileDropIfExists() produces DROP TABLE IF EXISTS `name`.
     */
    public function testMysqlCompileDropIfExists(): void
    {
        $sql = $this->mysqlGrammar()->compileDropIfExists('old_table');
        $this->assertSame('DROP TABLE IF EXISTS `old_table`', $sql);
    }

    /**
     * MySQL RENAME TABLE uses the MySQL-specific RENAME TABLE … TO … syntax.
     */
    public function testMysqlCompileRenameTableSyntax(): void
    {
        $sql = $this->mysqlGrammar()->compileRename('old', 'new_name');
        $this->assertSame('RENAME TABLE `old` TO `new_name`', $sql);
    }

    // =========================================================================
    // MySQLSchemaGrammar — index / view / trigger DDL
    // =========================================================================

    /**
     * compileCreateIndex() produces CREATE INDEX / CREATE UNIQUE INDEX.
     */
    public function testMysqlCompileCreateIndex(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame(
            'CREATE INDEX idx_users_email ON `users` (`email`)',
            $grammar->compileCreateIndex('users', 'idx_users_email', ['email'], false)
        );
        $this->assertSame(
            'CREATE UNIQUE INDEX uq_users_email ON `users` (`email`)',
            $grammar->compileCreateIndex('users', 'uq_users_email', ['email'], true)
        );
    }

    /**
     * compileCreateView() produces CREATE [OR REPLACE] VIEW name AS sql.
     */
    public function testMysqlCompileCreateView(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame(
            'CREATE VIEW active_users AS SELECT * FROM users WHERE active = 1',
            $grammar->compileCreateView('active_users', 'SELECT * FROM users WHERE active = 1', false)
        );
        $this->assertStringStartsWith(
            'CREATE OR REPLACE VIEW',
            $grammar->compileCreateView('v', 'SELECT 1', true)
        );
    }

    /**
     * compileDropView() produces DROP VIEW [IF EXISTS] name.
     */
    public function testMysqlCompileDropView(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('DROP VIEW IF EXISTS old_view', $grammar->compileDropView('old_view', true));
        $this->assertSame('DROP VIEW old_view', $grammar->compileDropView('old_view', false));
    }

    /**
     * MySQL compileCreateMaterializedView() falls back to a regular VIEW.
     */
    public function testMysqlMaterializedViewFallsBackToRegularView(): void
    {
        $sql = $this->mysqlGrammar()->compileCreateMaterializedView('mv_stats', 'SELECT 1');
        $this->assertStringStartsWith('CREATE VIEW mv_stats', $sql);
        $this->assertStringNotContainsString('MATERIALIZED', $sql);
    }

    /**
     * MySQL compileRefreshMaterializedView() returns empty string (no-op).
     */
    public function testMysqlRefreshMaterializedViewIsNoOp(): void
    {
        $this->assertSame('', $this->mysqlGrammar()->compileRefreshMaterializedView('mv_stats', false));
    }

    /**
     * MySQL compileDropMaterializedView() falls back to DROP VIEW.
     */
    public function testMysqlDropMaterializedViewFallsBackToDropView(): void
    {
        $sql = $this->mysqlGrammar()->compileDropMaterializedView('mv_stats', true);
        $this->assertStringContainsString('DROP VIEW', $sql);
        $this->assertStringNotContainsString('MATERIALIZED', $sql);
    }

    /**
     * MySQL compileCreateTrigger() uses the standard MySQL BEFORE/AFTER trigger syntax.
     */
    public function testMysqlCompileCreateTrigger(): void
    {
        $sql = $this->mysqlGrammar()->compileCreateTrigger(
            'trg_update_audit',
            'orders',
            'BEFORE',
            'UPDATE',
            'BEGIN SET NEW.updated_at = NOW(); END'
        );
        $this->assertStringStartsWith('CREATE TRIGGER trg_update_audit', $sql);
        $this->assertStringContainsString('BEFORE UPDATE', $sql);
        $this->assertStringContainsString('ON `orders`', $sql);
    }

    /**
     * MySQL compileDropTrigger() uses DROP TRIGGER [IF EXISTS] name.
     */
    public function testMysqlCompileDropTrigger(): void
    {
        $sql = $this->mysqlGrammar()->compileDropTrigger('trg_update_audit', 'orders', true);
        $this->assertSame('DROP TRIGGER IF EXISTS trg_update_audit', $sql);
    }

    /**
     * MySQL sequence methods all return empty strings (no-op on MySQL).
     */
    public function testMysqlSequencesAreNoOps(): void
    {
        $grammar = $this->mysqlGrammar();
        $this->assertSame('', $grammar->compileCreateSequence('seq_users'));
        $this->assertSame('', $grammar->compileDropSequence('seq_users'));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileHasTable / compileHasColumn
    // =========================================================================

    /**
     * compileHasTable() includes a table_schema filter when schema is provided.
     */
    public function testMysqlCompileHasTableWithSchema(): void
    {
        $sql = $this->mysqlGrammar()->compileHasTable('orders', 'mydb');
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringContainsString("table_schema = 'mydb'", $sql);
    }

    /**
     * compileHasTable() omits the schema filter when no schema is given.
     */
    public function testMysqlCompileHasTableWithoutSchema(): void
    {
        $sql = $this->mysqlGrammar()->compileHasTable('orders', '');
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringNotContainsString('table_schema', $sql);
    }

    /**
     * compileHasColumn() includes both table and column filters.
     */
    public function testMysqlCompileHasColumn(): void
    {
        $sql = $this->mysqlGrammar()->compileHasColumn('users', 'email', '');
        $this->assertStringContainsString("table_name = 'users'", $sql);
        $this->assertStringContainsString("column_name = 'email'", $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — quoting
    // =========================================================================

    /**
     * PostgreSQL quoteTable wraps in double-quotes.
     */
    public function testPgQuoteTableSimple(): void
    {
        $this->assertSame('"users"', $this->pgGrammar()->quoteTable('users'));
    }

    /**
     * PostgreSQL quoteTable handles schema-qualified names: schema.table → "schema"."table".
     */
    public function testPgQuoteTableSchemaQualified(): void
    {
        $this->assertSame('"authserver"."roles"', $this->pgGrammar()->quoteTable('authserver.roles'));
    }

    /**
     * PostgreSQL quoteColumn wraps in double-quotes.
     */
    public function testPgQuoteColumnUsesDoubleQuotes(): void
    {
        $this->assertSame('"id"', $this->pgGrammar()->quoteColumn('id'));
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileColumnType()
    // =========================================================================

    /**
     * PostgreSQL 'increments' maps to SERIAL.
     */
    public function testPgColumnTypeIncrements(): void
    {
        $col = new ColumnDefinition('id', 'increments');
        $this->assertSame('SERIAL', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'bigIncrements' maps to BIGSERIAL.
     */
    public function testPgColumnTypeBigIncrements(): void
    {
        $col = new ColumnDefinition('id', 'bigIncrements');
        $this->assertSame('BIGSERIAL', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'integer' with autoIncrement maps to SERIAL.
     */
    public function testPgColumnTypeIntegerAutoIncrementMapsToSerial(): void
    {
        $col = new ColumnDefinition('id', 'integer', ['autoIncrement' => true]);
        $this->assertSame('SERIAL', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'bigInteger' with autoIncrement maps to BIGSERIAL.
     */
    public function testPgColumnTypeBigIntegerAutoIncrementMapsToBigSerial(): void
    {
        $col = new ColumnDefinition('id', 'bigInteger', ['autoIncrement' => true]);
        $this->assertSame('BIGSERIAL', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'boolean' maps to BOOLEAN (not TINYINT(1) like MySQL).
     */
    public function testPgColumnTypeBoolean(): void
    {
        $col = new ColumnDefinition('active', 'boolean');
        $this->assertSame('BOOLEAN', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL maps all text variants to TEXT.
     */
    public function testPgColumnTypeAllTextVariantsMapToText(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame('TEXT', $grammar->compileColumnType(new ColumnDefinition('x', 'text')));
        $this->assertSame('TEXT', $grammar->compileColumnType(new ColumnDefinition('x', 'mediumText')));
        $this->assertSame('TEXT', $grammar->compileColumnType(new ColumnDefinition('x', 'longText')));
    }

    /**
     * PostgreSQL 'float' maps to REAL.
     */
    public function testPgColumnTypeFloat(): void
    {
        $col = new ColumnDefinition('score', 'float');
        $this->assertSame('REAL', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'double' maps to DOUBLE PRECISION.
     */
    public function testPgColumnTypeDouble(): void
    {
        $col = new ColumnDefinition('score', 'double');
        $this->assertSame('DOUBLE PRECISION', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'timestampTz' maps to TIMESTAMPTZ.
     */
    public function testPgColumnTypeTimestampTz(): void
    {
        $col = new ColumnDefinition('ts', 'timestampTz');
        $this->assertSame('TIMESTAMPTZ', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'binary' maps to BYTEA (not BLOB).
     */
    public function testPgColumnTypeBinary(): void
    {
        $col = new ColumnDefinition('data', 'binary');
        $this->assertSame('BYTEA', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'jsonb' maps to JSONB (distinct from JSON).
     */
    public function testPgColumnTypeJsonb(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame('JSON',  $grammar->compileColumnType(new ColumnDefinition('x', 'json')));
        $this->assertSame('JSONB', $grammar->compileColumnType(new ColumnDefinition('x', 'jsonb')));
    }

    /**
     * PostgreSQL 'uuid' maps to UUID (not CHAR(36) like MySQL).
     */
    public function testPgColumnTypeUUID(): void
    {
        $col = new ColumnDefinition('id', 'uuid');
        $this->assertSame('UUID', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL has no UNSIGNED — unsignedInteger maps to INTEGER.
     */
    public function testPgColumnTypeNoUnsigned(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame('INTEGER', $grammar->compileColumnType(new ColumnDefinition('x', 'unsignedInteger')));
        $this->assertSame('BIGINT',  $grammar->compileColumnType(new ColumnDefinition('x', 'unsignedBigInteger')));
    }

    /**
     * PostgreSQL 'year' maps to INTEGER (no YEAR type in PG).
     */
    public function testPgColumnTypeYearMapsToInteger(): void
    {
        $col = new ColumnDefinition('yr', 'year');
        $this->assertSame('INTEGER', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'enum' uses VARCHAR with a length derived from the longest value.
     */
    public function testPgColumnTypeEnumUsesVarchar(): void
    {
        $col = new ColumnDefinition('status', 'enum', ['values' => ['pending', 'active', 'completed_successfully']]);
        $sql = $this->pgGrammar()->compileColumnType($col);
        // 'completed_successfully' is 21 chars — must be at least 50 (max() with 50 minimum)
        $this->assertStringStartsWith('VARCHAR(', $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileColumn() for enum (CHECK constraint)
    // =========================================================================

    /**
     * PostgreSQL enum column appends a CHECK (...) IN constraint.
     *
     * PostgreSQL doesn't have a native ENUM type — the grammar uses VARCHAR
     * with a CHECK constraint to enforce the allowed values at the database level.
     */
    public function testPgEnumColumnAddsCheckConstraint(): void
    {
        // Arrange
        $col = new ColumnDefinition('status', 'enum', ['values' => ['pending', 'active', 'done']]);

        // Act
        $sql = $this->pgGrammar()->compileColumn($col);

        // Assert
        $this->assertStringContainsString("CHECK", $sql);
        $this->assertStringContainsString("'pending'", $sql);
        $this->assertStringContainsString("'active'", $sql);
        $this->assertStringContainsString("'done'", $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileCreate()
    // =========================================================================

    /**
     * PostgreSQL CREATE TABLE emits FK as separate ALTER TABLE statements.
     *
     * Foreign keys are not inline in PostgreSQL — they must follow the CREATE TABLE
     * as separate statements.  The test verifies this structural difference from MySQL.
     */
    public function testPgCompileCreateForeignKeyInSeparateStatement(): void
    {
        // Arrange
        $bp = new Blueprint('posts');
        $bp->string('title');
        $bp->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'posts');

        // Assert — CREATE TABLE + separate ALTER TABLE for FK = at least 2 statements
        $this->assertGreaterThanOrEqual(2, count($statements));
        // First statement is CREATE TABLE (no inline FK)
        $this->assertStringNotContainsString('FOREIGN KEY', $statements[0]);
        // Subsequent statement is ALTER TABLE ADD CONSTRAINT
        $alterStatement = end($statements);
        $this->assertStringContainsString('ALTER TABLE', $alterStatement);
        $this->assertStringContainsString('FOREIGN KEY', $alterStatement);
    }

    /**
     * PostgreSQL compileCreate() emits COMMENT ON TABLE in a separate statement.
     */
    public function testPgCompileCreateTableComment(): void
    {
        // Arrange
        $bp = new Blueprint('users');
        $bp->string('name');
        $bp->comment('Application users');
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'users');

        // Assert — extra COMMENT ON TABLE statement
        $commentStmt = array_filter($statements, fn($s) => strpos($s, 'COMMENT ON TABLE') !== false);
        $this->assertNotEmpty($commentStmt, 'Expected a COMMENT ON TABLE statement');
        $this->assertStringContainsString("IS 'Application users'", array_values($commentStmt)[0]);
    }

    /**
     * PostgreSQL compileCreate() emits COMMENT ON COLUMN in a separate statement.
     */
    public function testPgCompileCreateColumnComment(): void
    {
        // Arrange
        $bp = new Blueprint('users');
        $bp->string('email')->comment('Primary email address');
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'users');

        // Assert — extra COMMENT ON COLUMN statement
        $commentStmt = array_filter($statements, fn($s) => strpos($s, 'COMMENT ON COLUMN') !== false);
        $this->assertNotEmpty($commentStmt);
        $this->assertStringContainsString("IS 'Primary email address'", array_values($commentStmt)[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileModifyColumn()
    // =========================================================================

    /**
     * PostgreSQL compileModifyColumn() emits a TYPE change statement.
     *
     * Unlike MySQL's single MODIFY COLUMN, PostgreSQL requires separate
     * ALTER TABLE … ALTER COLUMN statements for type, nullability, and default.
     */
    public function testPgCompileModifyColumnEmitsTypeChange(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('email', 'string', ['length' => 320]);
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — at least one ALTER COLUMN TYPE statement
        $typeStmt = array_filter($statements, fn($s) => strpos($s, 'ALTER COLUMN') !== false && strpos($s, 'TYPE') !== false);
        $this->assertNotEmpty($typeStmt, 'Expected ALTER COLUMN TYPE statement');
        $this->assertStringContainsString('VARCHAR(320)', array_values($typeStmt)[0]);
    }

    /**
     * PostgreSQL compileModifyColumn() with nullable emits DROP NOT NULL.
     */
    public function testPgCompileModifyColumnNullableEmitsDropNotNull(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('phone', 'string')->nullable();
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — one statement drops NOT NULL
        $nullStmt = array_filter($statements, fn($s) => strpos($s, 'DROP NOT NULL') !== false);
        $this->assertNotEmpty($nullStmt, 'Expected DROP NOT NULL statement');
    }

    /**
     * PostgreSQL compileModifyColumn() with a new default emits SET DEFAULT.
     */
    public function testPgCompileModifyColumnWithDefaultEmitsSetDefault(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->modifyColumn('active', 'boolean')->default(true);
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — one statement sets the default
        $defStmt = array_filter($statements, fn($s) => strpos($s, 'SET DEFAULT') !== false);
        $this->assertNotEmpty($defStmt, 'Expected SET DEFAULT statement');
        $this->assertStringContainsString('TRUE', array_values($defStmt)[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — DROP INDEX
    // =========================================================================

    /**
     * PostgreSQL DROP INDEX uses the standalone DROP INDEX IF EXISTS syntax.
     */
    public function testPgCompileDropIndexNonPrimary(): void
    {
        $sql = $this->pgGrammar()->compileDropIndex('users', 'idx_users_email');
        $this->assertSame('DROP INDEX IF EXISTS "idx_users_email"', $sql);
    }

    /**
     * PostgreSQL dropping the PRIMARY constraint uses ALTER TABLE DROP CONSTRAINT.
     */
    public function testPgCompileDropIndexPrimary(): void
    {
        $sql = $this->pgGrammar()->compileDropIndex('users', 'PRIMARY');
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('_pkey', $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileHasTable / compileHasColumn
    // =========================================================================

    /**
     * PostgreSQL compileHasTable() with an explicit schema includes a schema filter.
     */
    public function testPgCompileHasTableWithSchema(): void
    {
        $sql = $this->pgGrammar()->compileHasTable('orders', 'public');
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringContainsString("table_schema = 'public'", $sql);
    }

    /**
     * PostgreSQL compileHasTable() without schema excludes system schemas.
     */
    public function testPgCompileHasTableWithoutSchemaExcludesPgCatalog(): void
    {
        $sql = $this->pgGrammar()->compileHasTable('orders', '');
        $this->assertStringContainsString('pg_catalog', $sql);
        $this->assertStringContainsString('information_schema', $sql);
    }

    /**
     * PostgreSQL compileHasTable() with a schema-qualified name (schema.table)
     * splits the schema from the table name automatically.
     */
    public function testPgCompileHasTableSchemaQualified(): void
    {
        $sql = $this->pgGrammar()->compileHasTable('public.orders', '');
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringContainsString("table_schema = 'public'", $sql);
    }

    /**
     * PostgreSQL compileHasColumn() with a schema-qualified table name
     * splits the qualifier from the table name.
     */
    public function testPgCompileHasColumnSchemaQualified(): void
    {
        $sql = $this->pgGrammar()->compileHasColumn('public.orders', 'status', '');
        $this->assertStringContainsString("table_name = 'orders'", $sql);
        $this->assertStringContainsString("column_name = 'status'", $sql);
        $this->assertStringContainsString("table_schema = 'public'", $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — materialized views
    // =========================================================================

    /**
     * PostgreSQL compileCreateMaterializedView() uses CREATE MATERIALIZED VIEW.
     */
    public function testPgCreateMaterializedView(): void
    {
        $sql = $this->pgGrammar()->compileCreateMaterializedView('mv_stats', 'SELECT COUNT(*) FROM events');
        $this->assertSame('CREATE MATERIALIZED VIEW mv_stats AS SELECT COUNT(*) FROM events', $sql);
    }

    /**
     * PostgreSQL compileRefreshMaterializedView() produces a REFRESH statement.
     */
    public function testPgRefreshMaterializedView(): void
    {
        $sql = $this->pgGrammar()->compileRefreshMaterializedView('mv_stats', false);
        $this->assertSame('REFRESH MATERIALIZED VIEW mv_stats', $sql);
    }

    /**
     * REFRESH MATERIALIZED VIEW CONCURRENTLY when the concurrently flag is true.
     */
    public function testPgRefreshMaterializedViewConcurrently(): void
    {
        $sql = $this->pgGrammar()->compileRefreshMaterializedView('mv_stats', true);
        $this->assertSame('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_stats', $sql);
    }

    /**
     * PostgreSQL compileDropMaterializedView() uses DROP MATERIALIZED VIEW [IF EXISTS].
     */
    public function testPgDropMaterializedView(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame('DROP MATERIALIZED VIEW IF EXISTS mv_stats', $grammar->compileDropMaterializedView('mv_stats', true));
        $this->assertSame('DROP MATERIALIZED VIEW mv_stats', $grammar->compileDropMaterializedView('mv_stats', false));
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — triggers
    // =========================================================================

    /**
     * PostgreSQL trigger syntax uses CREATE OR REPLACE TRIGGER.
     */
    public function testPgCreateTrigger(): void
    {
        $sql = $this->pgGrammar()->compileCreateTrigger(
            'trg_audit',
            'orders',
            'AFTER',
            'UPDATE',
            'EXECUTE FUNCTION audit_fn()'
        );
        $this->assertStringStartsWith('CREATE OR REPLACE TRIGGER trg_audit', $sql);
        $this->assertStringContainsString('AFTER UPDATE', $sql);
        $this->assertStringContainsString('ON "orders"', $sql);
    }

    /**
     * PostgreSQL DROP TRIGGER includes the ON table clause.
     */
    public function testPgDropTrigger(): void
    {
        $sql = $this->pgGrammar()->compileDropTrigger('trg_audit', 'orders', true);
        $this->assertSame('DROP TRIGGER IF EXISTS trg_audit ON "orders"', $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — sequences
    // =========================================================================

    /**
     * PostgreSQL compileCreateSequence() builds a CREATE SEQUENCE statement.
     */
    public function testPgCreateSequenceBasic(): void
    {
        $sql = $this->pgGrammar()->compileCreateSequence('seq_users', 1, 1);
        $this->assertStringStartsWith('CREATE SEQUENCE IF NOT EXISTS seq_users', $sql);
        $this->assertStringContainsString('START WITH 1', $sql);
        $this->assertStringContainsString('INCREMENT BY 1', $sql);
        $this->assertStringContainsString('NO CYCLE', $sql);
    }

    /**
     * PostgreSQL compileCreateSequence() includes MINVALUE / MAXVALUE / CYCLE when specified.
     */
    public function testPgCreateSequenceWithBoundsAndCycle(): void
    {
        $sql = $this->pgGrammar()->compileCreateSequence('seq_orders', 100, 5, 1, 10000, true);
        $this->assertStringContainsString('MINVALUE 1', $sql);
        $this->assertStringContainsString('MAXVALUE 10000', $sql);
        $this->assertStringContainsString('CYCLE', $sql);
        $this->assertStringNotContainsString('NO CYCLE', $sql);
    }

    /**
     * PostgreSQL compileDropSequence() produces DROP SEQUENCE [IF EXISTS] name.
     */
    public function testPgDropSequence(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame('DROP SEQUENCE IF EXISTS seq_users', $grammar->compileDropSequence('seq_users', true));
        $this->assertSame('DROP SEQUENCE seq_users', $grammar->compileDropSequence('seq_users', false));
    }

    /**
     * PostgreSQL compileNextVal() produces SELECT nextval('name').
     */
    public function testPgNextVal(): void
    {
        $sql = $this->pgGrammar()->compileNextVal('seq_users');
        $this->assertSame("SELECT nextval('seq_users')", $sql);
    }

    /**
     * PostgreSQL compileSetVal() produces SELECT setval('name', value, is_called).
     */
    public function testPgSetVal(): void
    {
        $grammar = $this->pgGrammar();
        $this->assertSame("SELECT setval('seq_users', 42, true)",  $grammar->compileSetVal('seq_users', 42, true));
        $this->assertSame("SELECT setval('seq_users', 1, false)",  $grammar->compileSetVal('seq_users', 1, false));
    }

    // =========================================================================
    // MySQLSchemaGrammar — missing column type coverage
    // =========================================================================

    /**
     * MySQL 'unsignedInteger' type (direct ColumnDefinition type, not unsigned modifier)
     * maps to INT UNSIGNED.
     */
    public function testMysqlColumnTypeUnsignedInteger(): void
    {
        // Arrange — 'unsignedInteger' is a distinct type string (not 'integer' + unsigned attr)
        $col = new ColumnDefinition('count', 'unsignedInteger');

        // Act + Assert
        $this->assertSame('INT UNSIGNED', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL 'unsignedBigInteger' type maps to BIGINT UNSIGNED.
     */
    public function testMysqlColumnTypeUnsignedBigInteger(): void
    {
        // Arrange
        $col = new ColumnDefinition('user_id', 'unsignedBigInteger');

        // Act + Assert
        $this->assertSame('BIGINT UNSIGNED', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL 'float' type maps to FLOAT (no precision in the type itself).
     */
    public function testMysqlColumnTypeFloat(): void
    {
        // Arrange
        $col = new ColumnDefinition('score', 'float');

        // Act + Assert
        $this->assertSame('FLOAT', $this->mysqlGrammar()->compileColumnType($col));
    }

    /**
     * MySQL 'double' type maps to DOUBLE.
     */
    public function testMysqlColumnTypeDouble(): void
    {
        // Arrange
        $col = new ColumnDefinition('ratio', 'double');

        // Act + Assert
        $this->assertSame('DOUBLE', $this->mysqlGrammar()->compileColumnType($col));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileNextVal / compileSetVal (no-ops)
    // =========================================================================

    /**
     * MySQL compileNextVal() returns empty string — MySQL has no native sequences.
     */
    public function testMysqlCompileNextValIsNoOp(): void
    {
        // Act + Assert — MySQL cannot advance a sequence natively
        $this->assertSame('', $this->mysqlGrammar()->compileNextVal('seq_users'));
    }

    /**
     * MySQL compileSetVal() returns empty string — MySQL has no native sequences.
     */
    public function testMysqlCompileSetValIsNoOp(): void
    {
        // Act + Assert
        $this->assertSame('', $this->mysqlGrammar()->compileSetVal('seq_users', 42));
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileHasColumn with schema
    // =========================================================================

    /**
     * MySQL compileHasColumn() adds a table_schema filter when schema is given.
     */
    public function testMysqlCompileHasColumnWithSchema(): void
    {
        // Arrange + Act
        $sql = $this->mysqlGrammar()->compileHasColumn('users', 'email', 'mydb');

        // Assert — both table and column filters, plus schema filter
        $this->assertStringContainsString("table_name = 'users'", $sql);
        $this->assertStringContainsString("column_name = 'email'", $sql);
        $this->assertStringContainsString("table_schema = 'mydb'", $sql);
    }

    // =========================================================================
    // MySQLSchemaGrammar — compileAlter() DROP FOREIGN KEY
    // =========================================================================

    /**
     * MySQL ALTER TABLE DROP FOREIGN KEY uses the MySQL backtick + constraint syntax.
     *
     * This also exercises the SchemaGrammar::compileAlter() DROP FOREIGN KEY loop
     * which iterates getDroppedForeigns() and calls compileDropForeignKey().
     */
    public function testMysqlCompileAlterDropForeignKey(): void
    {
        // Arrange
        $bp = new Blueprint('orders', 'alter');
        $bp->dropForeign('fk_orders_user_id');
        $grammar = $this->mysqlGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'orders');

        // Assert — MySQL uses DROP FOREIGN KEY with backtick-quoted name
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('DROP FOREIGN KEY', $statements[0]);
        $this->assertStringContainsString('fk_orders_user_id', $statements[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — missing column type coverage
    // =========================================================================

    /**
     * PostgreSQL 'tinyInteger' and 'smallInteger' both map to SMALLINT.
     */
    public function testPgColumnTypeTinyAndSmallIntegerMapToSmallint(): void
    {
        // Arrange
        $grammar = $this->pgGrammar();

        // Act + Assert — both compact integer types map to SMALLINT in PG
        $this->assertSame('SMALLINT', $grammar->compileColumnType(new ColumnDefinition('x', 'tinyInteger')));
        $this->assertSame('SMALLINT', $grammar->compileColumnType(new ColumnDefinition('x', 'smallInteger')));
    }

    /**
     * PostgreSQL 'char' maps to CHAR(n) with the given length.
     */
    public function testPgColumnTypeChar(): void
    {
        // Arrange
        $col = new ColumnDefinition('code', 'char', ['length' => 5]);

        // Act + Assert
        $this->assertSame('CHAR(5)', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL 'decimal' maps to DECIMAL(total, places).
     */
    public function testPgColumnTypeDecimal(): void
    {
        // Arrange
        $col = new ColumnDefinition('price', 'decimal', ['total' => 12, 'places' => 4]);

        // Act + Assert
        $this->assertSame('DECIMAL(12, 4)', $this->pgGrammar()->compileColumnType($col));
    }

    /**
     * PostgreSQL date/time types map to their SQL equivalents.
     *
     * Unlike MySQL, PostgreSQL has no YEAR type (mapped to INTEGER) and
     * maps dateTime to TIMESTAMP (not DATETIME).
     */
    public function testPgColumnTypeDateTimeVariants(): void
    {
        // Arrange
        $grammar = $this->pgGrammar();

        // Act + Assert — all four date/time types have native PG equivalents
        $this->assertSame('DATE',      $grammar->compileColumnType(new ColumnDefinition('x', 'date')));
        $this->assertSame('TIME',      $grammar->compileColumnType(new ColumnDefinition('x', 'time')));
        $this->assertSame('TIMESTAMP', $grammar->compileColumnType(new ColumnDefinition('x', 'dateTime')));
        $this->assertSame('TIMESTAMP', $grammar->compileColumnType(new ColumnDefinition('x', 'timestamp')));
    }

    /**
     * PostgreSQL spatial types map to GEOMETRY and POINT, unknown types are uppercased.
     */
    public function testPgColumnTypeSpatialAndUnknown(): void
    {
        // Arrange
        $grammar = $this->pgGrammar();

        // Act + Assert
        $this->assertSame('GEOMETRY',    $grammar->compileColumnType(new ColumnDefinition('x', 'geometry')));
        $this->assertSame('POINT',       $grammar->compileColumnType(new ColumnDefinition('x', 'point')));
        $this->assertSame('CUSTOM_TYPE', $grammar->compileColumnType(new ColumnDefinition('x', 'custom_type')));
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileDefaultValue calling parent
    // =========================================================================

    /**
     * PostgreSQL compileDefaultValue delegates non-bool values to the base class.
     *
     * The PG override handles TRUE/FALSE for booleans; all other value types
     * (string, null, int, float, Expression) must flow through the base implementation.
     */
    public function testPgCompileDefaultValueDelegatesNonBoolToParent(): void
    {
        // Arrange — access via reflection since the method is protected
        $grammar = $this->pgGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDefaultValue');
        $ref->setAccessible(true);

        // Act + Assert — PG should delegate these to SchemaGrammar::compileDefaultValue
        $this->assertSame("'hello'", $ref->invoke($grammar, 'hello'));
        $this->assertSame('NULL',    $ref->invoke($grammar, null));
        $this->assertSame('99',      $ref->invoke($grammar, 99));
        $this->assertSame('3.14',    $ref->invoke($grammar, 3.14));
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileModifyColumn SET NOT NULL
    // =========================================================================

    /**
     * PostgreSQL compileModifyColumn() with nullable=false emits SET NOT NULL.
     *
     * When a column is changed to be explicitly NOT NULL, PG needs a separate
     * ALTER COLUMN SET NOT NULL statement (unlike MySQL's single MODIFY COLUMN).
     */
    public function testPgCompileModifyColumnNotNullableEmitsSetNotNull(): void
    {
        // Arrange — explicitly set nullable = false to trigger the SET NOT NULL path
        $bp  = new Blueprint('users', 'alter');
        $col = $bp->modifyColumn('phone', 'string')->nullable(false);
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — one statement sets NOT NULL
        $notNullStmt = array_filter($statements, fn($s) => strpos($s, 'SET NOT NULL') !== false);
        $this->assertNotEmpty($notNullStmt, 'Expected SET NOT NULL statement');
    }

    /**
     * PostgreSQL compileModifyColumn() with hasDefault=false emits DROP DEFAULT.
     *
     * When hasDefault is explicitly set to false (e.g., to remove a column default),
     * the grammar emits an ALTER COLUMN DROP DEFAULT statement.
     */
    public function testPgCompileModifyColumnDropDefault(): void
    {
        // Arrange — manually set hasDefault=false to simulate "remove existing default"
        $bp  = new Blueprint('users', 'alter');
        $col = $bp->modifyColumn('active', 'boolean');
        $col->attributes['hasDefault'] = false; // explicit false → DROP DEFAULT path

        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — one statement drops the existing default
        $dropStmt = array_filter($statements, fn($s) => strpos($s, 'DROP DEFAULT') !== false);
        $this->assertNotEmpty($dropStmt, 'Expected DROP DEFAULT statement');
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileAlter: ADD COLUMN (base compileColumnPosition)
    // =========================================================================

    /**
     * PostgreSQL compileAlter() ADD COLUMN does not emit AFTER/FIRST positioning.
     *
     * PostgreSQL does not support AFTER col / FIRST column positioning.
     * The base class compileColumnPosition() returns '' for PG (no override),
     * so no positioning clause appears in the ALTER statement.
     */
    public function testPgCompileAlterAddColumnNoPositioning(): void
    {
        // Arrange
        $bp = new Blueprint('users', 'alter');
        $bp->string('phone', 20)->nullable();
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'users');

        // Assert — ADD COLUMN emitted, no AFTER/FIRST clause (PG doesn't support it)
        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('ALTER TABLE "users" ADD COLUMN', $statements[0]);
        $this->assertStringNotContainsString('AFTER', $statements[0]);
        $this->assertStringNotContainsString('FIRST', $statements[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileAlter: ADD INDEX
    // =========================================================================

    /**
     * PostgreSQL compileAlter() ADD INDEX emits a CREATE INDEX statement.
     *
     * This exercises SchemaGrammar::compileAlter()'s ADD INDEX loop (line 306).
     */
    public function testPgCompileAlterAddIndex(): void
    {
        // Arrange
        $bp = new Blueprint('orders', 'alter');
        $bp->index('status', 'idx_orders_status');
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'orders');

        // Assert — CREATE INDEX statement produced
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE INDEX idx_orders_status ON "orders"', $statements[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileAlter: ADD FOREIGN KEY
    // =========================================================================

    /**
     * PostgreSQL compileAlter() ADD FOREIGN KEY emits ALTER TABLE ADD CONSTRAINT.
     *
     * This exercises SchemaGrammar::compileAlter()'s ADD FOREIGN KEY loop (line 311).
     */
    public function testPgCompileAlterAddForeignKey(): void
    {
        // Arrange
        $bp = new Blueprint('posts', 'alter');
        $bp->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'posts');

        // Assert — ALTER TABLE ADD CONSTRAINT ... FOREIGN KEY
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ALTER TABLE', $statements[0]);
        $this->assertStringContainsString('FOREIGN KEY', $statements[0]);
        $this->assertStringContainsString('REFERENCES "users" ("id")', $statements[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileAlter: DROP FOREIGN KEY
    // =========================================================================

    /**
     * PostgreSQL compileAlter() DROP FOREIGN KEY uses DROP CONSTRAINT syntax.
     *
     * This exercises both SchemaGrammar::compileAlter()'s DROP FOREIGN KEY loop
     * and PostgreSQLSchemaGrammar::compileDropForeignKey() override.
     */
    public function testPgCompileAlterDropForeignKey(): void
    {
        // Arrange
        $bp = new Blueprint('orders', 'alter');
        $bp->dropForeign('fk_orders_user_id');
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileAlter($bp, 'orders');

        // Assert — PostgreSQL uses DROP CONSTRAINT (not DROP FOREIGN KEY)
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('DROP CONSTRAINT', $statements[0]);
        $this->assertStringContainsString('fk_orders_user_id', $statements[0]);
        $this->assertStringNotContainsString('DROP FOREIGN KEY', $statements[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileRename (base class implementation)
    // =========================================================================

    /**
     * PostgreSQL compileRename() uses ALTER TABLE ... RENAME TO syntax.
     *
     * PostgreSQL does not override compileRename(), so the base SchemaGrammar
     * implementation is used.  This is distinct from MySQL which uses
     * RENAME TABLE ... TO ... syntax.
     */
    public function testPgCompileRenameTableUsesAlterTableSyntax(): void
    {
        // Act
        $sql = $this->pgGrammar()->compileRename('old_table', 'new_table');

        // Assert — PG uses ALTER TABLE ... RENAME TO (base class implementation)
        $this->assertSame('ALTER TABLE "old_table" RENAME TO "new_table"', $sql);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileCreate with non-unique index
    // =========================================================================

    /**
     * PostgreSQL compileCreate() emits a separate CREATE INDEX statement for
     * non-unique indexes (inlineIndexes() = false for PG).
     *
     * This exercises SchemaGrammar::compileCreate() line 184 where post-CREATE
     * index statements are appended for dialects that cannot inline indexes.
     */
    public function testPgCompileCreateWithIndexEmitsSeparateStatement(): void
    {
        // Arrange
        $bp = new Blueprint('logs');
        $bp->string('level');
        $bp->index('level', 'idx_logs_level');
        $grammar = $this->pgGrammar();

        // Act
        $statements = $grammar->compileCreate($bp, 'logs');

        // Assert — CREATE TABLE + separate CREATE INDEX (at least 2 statements)
        $this->assertGreaterThanOrEqual(2, count($statements));
        $indexStmt = array_filter($statements, fn($s) => strpos($s, 'CREATE INDEX') !== false);
        $this->assertNotEmpty($indexStmt, 'Expected separate CREATE INDEX statement');
        $this->assertStringContainsString('idx_logs_level', array_values($indexStmt)[0]);
    }

    // =========================================================================
    // PostgreSQLSchemaGrammar — compileHasColumn coverage
    // =========================================================================

    /**
     * PostgreSQL compileHasColumn() with explicit schema emits a table_schema filter.
     */
    public function testPgCompileHasColumnWithExplicitSchema(): void
    {
        // Act
        $sql = $this->pgGrammar()->compileHasColumn('users', 'email', 'public');

        // Assert
        $this->assertStringContainsString("table_name = 'users'", $sql);
        $this->assertStringContainsString("column_name = 'email'", $sql);
        $this->assertStringContainsString("table_schema = 'public'", $sql);
    }

    /**
     * PostgreSQL compileHasColumn() without schema excludes system schemas.
     *
     * When no schema is given and the table name is not dotted, the query excludes
     * pg_catalog and information_schema to avoid returning system columns.
     */
    public function testPgCompileHasColumnWithoutSchemaExcludesPgCatalog(): void
    {
        // Act
        $sql = $this->pgGrammar()->compileHasColumn('users', 'email', '');

        // Assert — system schemas are excluded
        $this->assertStringContainsString('pg_catalog', $sql);
        $this->assertStringContainsString('information_schema', $sql);
        $this->assertStringContainsString("column_name = 'email'", $sql);
    }

    // =========================================================================
    // SchemaGrammar — per-column unique() modifier in compileCreate
    // =========================================================================

    /**
     * A column with ->unique() modifier generates an inline UNIQUE constraint.
     *
     * This exercises SchemaGrammar::compileCreate() lines 147-153 where per-column
     * unique modifiers are collected and rendered.  The base compileInlineUnique()
     * emits UNIQUE (...); MySQL overrides to emit UNIQUE KEY name (...).
     * Also exercises generateIndexName() with the table name prefix.
     */
    public function testCompileCreatePerColumnUniqueModifier(): void
    {
        // Arrange — per-column .unique() (not blueprint-level ->unique())
        $bp = new Blueprint('users');
        $bp->string('email')->unique();
        $grammar = $this->pgGrammar(); // PG uses base compileInlineUnique

        // Act
        $sql = $grammar->compileCreate($bp, 'users')[0];

        // Assert — the CREATE TABLE contains an inline UNIQUE clause (base class form)
        $this->assertStringContainsString('UNIQUE (', $sql);
        // The generated name uses generateIndexName: table_column_unique
        $this->assertStringContainsString('"email"', $sql);
    }

    // =========================================================================
    // SchemaGrammar — blueprint-level primary key (explicit primary())
    // =========================================================================

    /**
     * Blueprint-level primary() creates an explicit PRIMARY KEY clause.
     *
     * This exercises SchemaGrammar::collectPrimaryKey() line 535 which returns
     * getPrimaryKey() directly when the blueprint has an explicit PK set.
     */
    public function testCompileCreateBlueprintLevelPrimaryKey(): void
    {
        // Arrange — composite PK set at blueprint level (not per-column ->primary())
        $bp = new Blueprint('order_items');
        $bp->integer('order_id');
        $bp->integer('product_id');
        $bp->primary(['order_id', 'product_id']); // explicit composite PK
        $grammar = $this->mysqlGrammar();

        // Act
        $sql = $grammar->compileCreate($bp, 'order_items')[0];

        // Assert — both columns appear in the PRIMARY KEY clause
        $this->assertStringContainsString('PRIMARY KEY (`order_id`, `product_id`)', $sql);
    }
}
