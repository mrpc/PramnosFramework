<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * SchemaBuilder integration tests against MySQL 8.0.
 *
 * Every test verifies that the DDL emitted by SchemaBuilder actually takes
 * effect in the database — column types, nullability, defaults, indexes,
 * constraints, views, and ALTER operations are all confirmed via
 * information_schema queries, not just by checking that no exception was thrown.
 *
 * Table prefix used: sb_ (avoids collisions with other test suites).
 */
class SchemaBuilderMySQLTest extends TestCase
{
    protected Database $db;
    protected SchemaBuilder $schema;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        $this->schema = $this->db->schema();
        $this->dropAll();
    }

    protected function tearDown(): void
    {
        $this->dropAll();
        $this->db->close();
    }

    private function dropAll(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `sb_child`");
        $this->db->query("DROP TABLE IF EXISTS `sb_parent`");
        $this->db->query("DROP TABLE IF EXISTS `sb_alter`");
        $this->db->query("DROP TABLE IF EXISTS `sb_types`");
        $this->db->query("DROP TABLE IF EXISTS `sb_renamed`");
        $this->db->query("DROP TABLE IF EXISTS `sb_trunc`");
        $this->db->query("DROP VIEW  IF EXISTS `sb_view`");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns information_schema row for the given table + column, or null.
     */
    private function columnInfo(string $table, string $column): ?array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLUMN_COMMENT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                'pramnos_test', $table, $column
            )
        );
        return $result->numRows > 0 ? $result->fields : null;
    }

    private function tableExists(string $table): bool
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test', $table
            )
        );
        return (int) $r->fields['cnt'] === 1;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                'pramnos_test', $table, $indexName
            )
        );
        return (int) $r->fields['cnt'] > 0;
    }

    private function viewExists(string $name): bool
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.VIEWS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test', $name
            )
        );
        return (int) $r->fields['cnt'] === 1;
    }

    // -------------------------------------------------------------------------
    // hasTable / hasColumn
    // -------------------------------------------------------------------------

    /**
     * hasTable() must return false before creation and true after.
     * hasColumn() must reflect whether a column exists.
     */
    public function testHasTableAndHasColumn(): void
    {
        // Arrange
        $this->assertFalse($this->schema->hasTable('sb_parent'), 'table must not exist yet');

        // Act
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });

        // Assert
        $this->assertTrue($this->schema->hasTable('sb_parent'), 'table must exist after createTable()');
        $this->assertTrue($this->schema->hasColumn('sb_parent', 'id'));
        $this->assertTrue($this->schema->hasColumn('sb_parent', 'name'));
        $this->assertFalse($this->schema->hasColumn('sb_parent', 'nonexistent'));
    }

    // -------------------------------------------------------------------------
    // dropTableIfExists — idempotent
    // -------------------------------------------------------------------------

    /**
     * dropTableIfExists() must not throw when the table does not exist, and
     * must remove the table when it does.
     */
    public function testDropTableIfExistsIsIdempotent(): void
    {
        // Arrange — table does not exist
        $this->schema->dropTableIfExists('sb_parent'); // must not throw

        // Act — create then drop
        $this->schema->createTable('sb_parent', fn($t) => $t->increments('id'));
        $this->assertTrue($this->tableExists('sb_parent'));
        $this->schema->dropTableIfExists('sb_parent');

        // Assert
        $this->assertFalse($this->tableExists('sb_parent'));
        $this->schema->dropTableIfExists('sb_parent'); // second call must not throw
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Integer column types
    // -------------------------------------------------------------------------

    /**
     * tinyInteger, smallInteger, integer, bigInteger, unsignedInteger,
     * unsignedBigInteger must map to the correct MySQL DATA_TYPE values.
     */
    public function testIntegerColumnTypes(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->tinyInteger('tiny_col');
            $t->smallInteger('small_col');
            $t->integer('int_col');
            $t->bigInteger('big_col');
            $t->unsignedInteger('uint_col');
            $t->unsignedBigInteger('ubig_col');
        });

        // Assert
        $this->assertSame('tinyint',  $this->columnInfo('sb_types', 'tiny_col')['DATA_TYPE']);
        $this->assertSame('smallint', $this->columnInfo('sb_types', 'small_col')['DATA_TYPE']);
        $this->assertSame('int',      $this->columnInfo('sb_types', 'int_col')['DATA_TYPE']);
        $this->assertSame('bigint',   $this->columnInfo('sb_types', 'big_col')['DATA_TYPE']);
        $this->assertSame('int',      $this->columnInfo('sb_types', 'uint_col')['DATA_TYPE']);
        $this->assertSame('bigint',   $this->columnInfo('sb_types', 'ubig_col')['DATA_TYPE']);
        // unsigned columns carry UNSIGNED in COLUMN_TYPE
        $this->assertStringContainsString('unsigned', $this->columnInfo('sb_types', 'uint_col')['COLUMN_TYPE']);
        $this->assertStringContainsString('unsigned', $this->columnInfo('sb_types', 'ubig_col')['COLUMN_TYPE']);
    }

    // -------------------------------------------------------------------------
    // String / text column types
    // -------------------------------------------------------------------------

    /**
     * char, string (VARCHAR), text, mediumText, longText must produce the
     * correct MySQL DATA_TYPE values.
     */
    public function testStringColumnTypes(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->char('char_col', 10);
            $t->string('varchar_col', 120);
            $t->text('text_col');
            $t->mediumText('medium_col');
            $t->longText('long_col');
        });

        // Assert
        $this->assertSame('char',       $this->columnInfo('sb_types', 'char_col')['DATA_TYPE']);
        $this->assertSame('varchar',    $this->columnInfo('sb_types', 'varchar_col')['DATA_TYPE']);
        $this->assertSame('varchar(120)', $this->columnInfo('sb_types', 'varchar_col')['COLUMN_TYPE']);
        $this->assertSame('text',       $this->columnInfo('sb_types', 'text_col')['DATA_TYPE']);
        $this->assertSame('mediumtext', $this->columnInfo('sb_types', 'medium_col')['DATA_TYPE']);
        $this->assertSame('longtext',   $this->columnInfo('sb_types', 'long_col')['DATA_TYPE']);
    }

    // -------------------------------------------------------------------------
    // Boolean — MySQL-specific TINYINT(1)
    // -------------------------------------------------------------------------

    /**
     * boolean() must produce TINYINT(1) on MySQL, not a native BOOLEAN type.
     * This is the MySQL-specific dialect mapping.
     */
    public function testBooleanIsTinyInt1(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->boolean('flag');
        });

        // Assert
        $info = $this->columnInfo('sb_types', 'flag');
        $this->assertSame('tinyint',    $info['DATA_TYPE']);
        $this->assertSame('tinyint(1)', $info['COLUMN_TYPE']);
    }

    // -------------------------------------------------------------------------
    // JSON column
    // -------------------------------------------------------------------------

    /**
     * json() and jsonb() both map to MySQL JSON (no native JSONB on MySQL).
     */
    public function testJsonColumn(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->json('meta');
            $t->jsonb('data');
        });

        // Assert
        $this->assertSame('json', $this->columnInfo('sb_types', 'meta')['DATA_TYPE']);
        $this->assertSame('json', $this->columnInfo('sb_types', 'data')['DATA_TYPE']);
    }

    // -------------------------------------------------------------------------
    // ENUM column
    // -------------------------------------------------------------------------

    /**
     * enum() must produce a native MySQL ENUM type with the supplied values.
     */
    public function testEnumColumn(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->enum('status', ['pending', 'active', 'inactive']);
        });

        // Assert
        $info = $this->columnInfo('sb_types', 'status');
        $this->assertSame('enum', $info['DATA_TYPE']);
        $this->assertStringContainsString('pending',  $info['COLUMN_TYPE']);
        $this->assertStringContainsString('active',   $info['COLUMN_TYPE']);
        $this->assertStringContainsString('inactive', $info['COLUMN_TYPE']);
    }

    // -------------------------------------------------------------------------
    // Date/time column types
    // -------------------------------------------------------------------------

    /**
     * date, dateTime, timestamp, timestampTz must produce the correct MySQL
     * column types. timestampTz maps to TIMESTAMP on MySQL (no TZ support).
     */
    public function testDateTimeColumnTypes(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->date('birth_date');
            $t->dateTime('event_at');
            $t->timestamp('logged_at');
            $t->timestampTz('tz_at');
        });

        // Assert
        $this->assertSame('date',      $this->columnInfo('sb_types', 'birth_date')['DATA_TYPE']);
        $this->assertSame('datetime',  $this->columnInfo('sb_types', 'event_at')['DATA_TYPE']);
        $this->assertSame('timestamp', $this->columnInfo('sb_types', 'logged_at')['DATA_TYPE']);
        $this->assertSame('timestamp', $this->columnInfo('sb_types', 'tz_at')['DATA_TYPE']); // MySQL has no TZ
    }

    // -------------------------------------------------------------------------
    // Nullable and default values
    // -------------------------------------------------------------------------

    /**
     * nullable() must set IS_NULLABLE = YES; NOT NULL columns must have NO.
     * default() must store the value in COLUMN_DEFAULT.
     */
    public function testNullableAndDefault(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->string('required_col');                        // NOT NULL by default
            $t->string('optional_col')->nullable();
            $t->integer('count_col')->default(0);
            $t->string('label_col')->default('unknown')->nullable();
        });

        // Assert
        $this->assertSame('NO',  $this->columnInfo('sb_types', 'required_col')['IS_NULLABLE']);
        $this->assertSame('YES', $this->columnInfo('sb_types', 'optional_col')['IS_NULLABLE']);
        $this->assertSame('0',   $this->columnInfo('sb_types', 'count_col')['COLUMN_DEFAULT']);
        $this->assertSame('unknown', $this->columnInfo('sb_types', 'label_col')['COLUMN_DEFAULT']);
    }

    // -------------------------------------------------------------------------
    // increments() — AUTO_INCREMENT primary key
    // -------------------------------------------------------------------------

    /**
     * increments() must create an INT AUTO_INCREMENT PRIMARY KEY column.
     */
    public function testIncrements(): void
    {
        // Act
        $this->schema->createTable('sb_types', fn($t) => $t->increments('id'));

        // Assert
        $info = $this->columnInfo('sb_types', 'id');
        $this->assertSame('int',            $info['DATA_TYPE']);
        $this->assertSame('auto_increment', $info['EXTRA']);
        $this->assertSame('PRI',            $info['COLUMN_KEY']);
    }

    // -------------------------------------------------------------------------
    // timestamps() helper
    // -------------------------------------------------------------------------

    /**
     * timestamps() must create both created_at and updated_at TIMESTAMP columns.
     */
    public function testTimestampsHelper(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->timestamps();
        });

        // Assert
        $this->assertTrue($this->schema->hasColumn('sb_types', 'created_at'));
        $this->assertTrue($this->schema->hasColumn('sb_types', 'updated_at'));
        $this->assertSame('timestamp', $this->columnInfo('sb_types', 'created_at')['DATA_TYPE']);
    }

    // -------------------------------------------------------------------------
    // softDeletes() helper
    // -------------------------------------------------------------------------

    /**
     * softDeletes() must create a nullable deleted_at TIMESTAMP column.
     */
    public function testSoftDeletes(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->softDeletes();
        });

        // Assert
        $info = $this->columnInfo('sb_types', 'deleted_at');
        $this->assertNotNull($info, 'deleted_at column must exist');
        $this->assertSame('YES', $info['IS_NULLABLE'], 'deleted_at must be nullable');
    }

    // -------------------------------------------------------------------------
    // Table and column comments
    // -------------------------------------------------------------------------

    /**
     * comment() on a column must be stored in COLUMN_COMMENT.
     * comment() on a table must be stored in TABLE_COMMENT.
     * Apostrophes in comments must be stored correctly (no backslash escape).
     */
    public function testColumnAndTableComments(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->comment("User's main table");
            $t->increments('id');
            $t->string('title')->comment("Item's label");
        });

        // Assert — column comment
        $colInfo = $this->columnInfo('sb_types', 'title');
        $this->assertSame("Item's label", $colInfo['COLUMN_COMMENT']);

        // Assert — table comment
        $tableResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT TABLE_COMMENT FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test', 'sb_types'
            )
        );
        $this->assertSame("User's main table", $tableResult->fields['TABLE_COMMENT']);
    }

    // -------------------------------------------------------------------------
    // Indexes
    // -------------------------------------------------------------------------

    /**
     * createIndex() must create a named non-unique index that is queryable
     * from information_schema.STATISTICS.
     * dropIndex() must remove it.
     */
    public function testCreateAndDropIndex(): void
    {
        // Arrange
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->string('email');
        });

        // Act
        $this->schema->createIndex('sb_types', 'idx_sb_email', ['email']);

        // Assert
        $this->assertTrue($this->indexExists('sb_types', 'idx_sb_email'), 'index must exist');

        // Act — drop
        $this->schema->dropIndex('sb_types', 'idx_sb_email');

        // Assert
        $this->assertFalse($this->indexExists('sb_types', 'idx_sb_email'), 'index must be gone after drop');
    }

    /**
     * createUniqueIndex() must create a UNIQUE index (NON_UNIQUE = 0).
     */
    public function testCreateUniqueIndex(): void
    {
        // Arrange
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->string('slug');
        });

        // Act
        $this->schema->createUniqueIndex('sb_types', 'uniq_sb_slug', ['slug']);

        // Assert
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT NON_UNIQUE FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1",
                'pramnos_test', 'sb_types', 'uniq_sb_slug'
            )
        );
        $this->assertSame('0', (string) $r->fields['NON_UNIQUE'], 'unique index must have NON_UNIQUE = 0');
    }

    // -------------------------------------------------------------------------
    // Foreign key
    // -------------------------------------------------------------------------

    /**
     * A Blueprint FK must create a FOREIGN KEY constraint visible in
     * information_schema.KEY_COLUMN_USAGE.
     */
    public function testForeignKey(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });

        // Act
        $this->schema->createTable('sb_child', function ($t) {
            $t->increments('id');
            $t->integer('parent_id')->unsigned();
            $t->foreign('parent_id')->references('id')->on('sb_parent')->onDelete('cascade');
        });

        // Assert
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                   AND REFERENCED_TABLE_NAME = %s AND COLUMN_NAME = %s",
                'pramnos_test', 'sb_child', 'sb_parent', 'parent_id'
            )
        );
        $this->assertGreaterThan(0, $r->numRows, 'FK constraint must exist in KEY_COLUMN_USAGE');
    }

    // -------------------------------------------------------------------------
    // ALTER TABLE — add / drop column
    // -------------------------------------------------------------------------

    /**
     * alterTable() with addColumn must add a new column to an existing table.
     */
    public function testAlterTableAddColumn(): void
    {
        // Arrange
        $this->schema->createTable('sb_alter', fn($t) => $t->increments('id'));
        $this->assertFalse($this->schema->hasColumn('sb_alter', 'description'));

        // Act
        $this->schema->alterTable('sb_alter', fn($t) => $t->text('description')->nullable());

        // Assert
        $this->assertTrue($this->schema->hasColumn('sb_alter', 'description'));
        $this->assertSame('YES', $this->columnInfo('sb_alter', 'description')['IS_NULLABLE']);
    }

    /**
     * alterTable() with dropColumn must remove the column.
     */
    public function testAlterTableDropColumn(): void
    {
        // Arrange
        $this->schema->createTable('sb_alter', function ($t) {
            $t->increments('id');
            $t->string('to_remove');
        });
        $this->assertTrue($this->schema->hasColumn('sb_alter', 'to_remove'));

        // Act
        $this->schema->alterTable('sb_alter', fn($t) => $t->dropColumn('to_remove'));

        // Assert
        $this->assertFalse($this->schema->hasColumn('sb_alter', 'to_remove'));
    }

    // -------------------------------------------------------------------------
    // renameTable
    // -------------------------------------------------------------------------

    /**
     * renameTable() must move the table to its new name.
     */
    public function testRenameTable(): void
    {
        // Arrange
        $this->schema->createTable('sb_alter', fn($t) => $t->increments('id'));

        // Act
        $this->schema->renameTable('sb_alter', 'sb_renamed');

        // Assert
        $this->assertFalse($this->tableExists('sb_alter'),   'old name must not exist');
        $this->assertTrue($this->tableExists('sb_renamed'),  'new name must exist');
    }

    // -------------------------------------------------------------------------
    // truncate
    // -------------------------------------------------------------------------

    /**
     * truncate() must empty the table without dropping it.
     */
    public function testTruncate(): void
    {
        // Arrange
        $this->schema->createTable('sb_trunc', fn($t) => $t->increments('id'));
        $this->db->query("INSERT INTO `sb_trunc` VALUES (1), (2), (3)");
        $before = $this->db->query("SELECT COUNT(*) AS cnt FROM `sb_trunc`");
        $this->assertSame('3', (string) $before->fields['cnt']);

        // Act
        $this->schema->truncate('sb_trunc');

        // Assert
        $after = $this->db->query("SELECT COUNT(*) AS cnt FROM `sb_trunc`");
        $this->assertSame('0', (string) $after->fields['cnt']);
        $this->assertTrue($this->tableExists('sb_trunc'), 'table must still exist after truncate');
    }

    // -------------------------------------------------------------------------
    // Views
    // -------------------------------------------------------------------------

    /**
     * createView() must create a queryable view; dropView() must remove it.
     */
    public function testCreateAndDropView(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $this->db->query("INSERT INTO `sb_parent` (name) VALUES ('Alice')");

        // Act
        $this->schema->createView('sb_view', "SELECT id, name FROM `sb_parent` WHERE id > 0");

        // Assert — view exists in information_schema
        $this->assertTrue($this->viewExists('sb_view'), 'view must exist after createView()');

        // Assert — view is queryable
        $r = $this->db->query("SELECT * FROM `sb_view`");
        $this->assertSame(1, $r->numRows);

        // Act — drop
        $this->schema->dropView('sb_view');
        $this->assertFalse($this->viewExists('sb_view'), 'view must be gone after dropView()');
    }

    /**
     * createOrReplaceView() must update the view definition without error when
     * the view already exists.
     */
    public function testCreateOrReplaceView(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $this->schema->createView('sb_view', "SELECT id FROM `sb_parent`");

        // Act — replace with a wider definition
        $this->schema->createOrReplaceView('sb_view', "SELECT id, name FROM `sb_parent`");

        // Assert — view still exists and now exposes 'name'
        $this->assertTrue($this->viewExists('sb_view'));
        $r = $this->db->query("SELECT name FROM `sb_view` LIMIT 0");
        $this->assertNotNull($r, 'replaced view must expose the name column');
    }
}
