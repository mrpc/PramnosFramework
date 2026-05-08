<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * SchemaBuilder integration tests against PostgreSQL 14 / TimescaleDB.
 *
 * Every test verifies that the DDL emitted by SchemaBuilder actually takes
 * effect in the database — column types, nullability, defaults, indexes,
 * constraints, materialized views, and ALTER operations are confirmed via
 * information_schema and pg_* catalog queries.
 *
 * Table prefix used: sb_ (avoids collisions with other test suites).
 *
 * PostgreSQL-specific dialect assertions:
 *   - boolean() → native BOOLEAN, not TINYINT(1)
 *   - increments() → SERIAL (stored as integer in info_schema)
 *   - bigIncrements() → BIGSERIAL (stored as bigint)
 *   - jsonb() → native JSONB
 *   - timestampTz() → TIMESTAMPTZ (timestamp with time zone)
 *   - uuid() → UUID
 *   - enum() → VARCHAR + CHECK constraint (no native ENUM)
 *   - FK constraints added as separate ALTER TABLE statements
 *   - COMMENT ON TABLE / COLUMN (apostrophe-safe with '' not \')
 *   - Materialized views
 */
class SchemaBuilderPostgreSQLTest extends TestCase
{
    protected Database $db;
    protected SchemaBuilder $schema;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
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
        $this->db->execute("DROP MATERIALIZED VIEW IF EXISTS sb_matview");
        $this->db->execute("DROP VIEW  IF EXISTS sb_view");
        $this->db->execute("DROP TABLE IF EXISTS sb_child");
        $this->db->execute("DROP TABLE IF EXISTS sb_parent");
        $this->db->execute("DROP TABLE IF EXISTS sb_alter");
        $this->db->execute("DROP TABLE IF EXISTS sb_types");
        $this->db->execute("DROP TABLE IF EXISTS sb_renamed");
        $this->db->execute("DROP TABLE IF EXISTS sb_trunc");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns information_schema.columns row for table + column, or null.
     * All column names are lowercase in PostgreSQL.
     */
    private function columnInfo(string $table, string $column): ?array
    {
        $result = $this->db->execute(
            "SELECT data_type, udt_name, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = $1 AND column_name = $2",
            $table, $column
        );
        return ($result && $result->numRows > 0) ? $result->fields : null;
    }

    private function tableExists(string $table): bool
    {
        $r = $this->db->execute(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = $1",
            $table
        );
        return $r && $r->numRows > 0;
    }

    private function indexExists(string $indexName): bool
    {
        $r = $this->db->execute(
            "SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = $1",
            $indexName
        );
        return $r && $r->numRows > 0;
    }

    private function viewExists(string $name): bool
    {
        $r = $this->db->execute(
            "SELECT 1 FROM information_schema.views
             WHERE table_schema = 'public' AND table_name = $1",
            $name
        );
        return $r && $r->numRows > 0;
    }

    private function matviewExists(string $name): bool
    {
        $r = $this->db->execute(
            "SELECT 1 FROM pg_matviews WHERE schemaname = 'public' AND matviewname = $1",
            $name
        );
        return $r && $r->numRows > 0;
    }

    private function checkConstraintExists(string $table, string $column): bool
    {
        // Looks for a CHECK constraint referencing the given column
        $r = $this->db->execute(
            "SELECT 1 FROM information_schema.constraint_column_usage ccu
             JOIN information_schema.table_constraints tc
               ON tc.constraint_name = ccu.constraint_name
                  AND tc.table_schema = ccu.table_schema
             WHERE tc.constraint_type = 'CHECK'
               AND ccu.table_schema = 'public'
               AND ccu.table_name = $1
               AND ccu.column_name = $2",
            $table, $column
        );
        return $r && $r->numRows > 0;
    }

    // -------------------------------------------------------------------------
    // hasTable / hasColumn
    // -------------------------------------------------------------------------

    /**
     * hasTable() / hasColumn() must correctly reflect the current schema state.
     */
    public function testHasTableAndHasColumn(): void
    {
        // Arrange
        $this->assertFalse($this->schema->hasTable('sb_parent'));

        // Act
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });

        // Assert
        $this->assertTrue($this->schema->hasTable('sb_parent'));
        $this->assertTrue($this->schema->hasColumn('sb_parent', 'id'));
        $this->assertTrue($this->schema->hasColumn('sb_parent', 'name'));
        $this->assertFalse($this->schema->hasColumn('sb_parent', 'nonexistent'));
    }

    // -------------------------------------------------------------------------
    // dropTableIfExists — idempotent
    // -------------------------------------------------------------------------

    /**
     * dropTableIfExists() must succeed even when the table does not exist.
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
    // SERIAL / BIGSERIAL (increments / bigIncrements)
    // -------------------------------------------------------------------------

    /**
     * increments() must create an INTEGER column with a SERIAL sequence.
     * info_schema reports data_type = 'integer'; the sequence is implicit.
     */
    public function testIncrements(): void
    {
        // Act
        $this->schema->createTable('sb_types', fn($t) => $t->increments('id'));

        // Assert — info_schema reports integer; sequence default applied
        $info = $this->columnInfo('sb_types', 'id');
        $this->assertSame('integer', $info['data_type']);
        $this->assertStringContainsString('nextval', (string) $info['column_default'],
            'SERIAL column must have a nextval() sequence default');
    }

    /**
     * bigIncrements() must create a BIGINT column with a BIGSERIAL sequence.
     */
    public function testBigIncrements(): void
    {
        // Act
        $this->schema->createTable('sb_types', fn($t) => $t->bigIncrements('id'));

        // Assert
        $info = $this->columnInfo('sb_types', 'id');
        $this->assertSame('bigint', $info['data_type']);
        $this->assertStringContainsString('nextval', (string) $info['column_default']);
    }

    // -------------------------------------------------------------------------
    // Boolean — native BOOLEAN, not TINYINT(1)
    // -------------------------------------------------------------------------

    /**
     * boolean() must produce native PostgreSQL BOOLEAN, not TINYINT(1).
     * This distinguishes the PG grammar from MySQL.
     */
    public function testBooleanIsNativeBoolean(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->boolean('active');
        });

        // Assert
        $info = $this->columnInfo('sb_types', 'active');
        $this->assertSame('boolean', $info['data_type'], 'PG boolean must be native BOOLEAN, not tinyint');
    }

    // -------------------------------------------------------------------------
    // JSONB
    // -------------------------------------------------------------------------

    /**
     * jsonb() must produce a native JSONB column; json() produces JSON.
     * These are distinct types in PostgreSQL — JSONB is indexable.
     */
    public function testJsonAndJsonbColumns(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->json('meta');
            $t->jsonb('data');
        });

        // Assert
        $this->assertSame('json',  $this->columnInfo('sb_types', 'meta')['data_type']);
        $this->assertSame('jsonb', $this->columnInfo('sb_types', 'data')['data_type']);
    }

    // -------------------------------------------------------------------------
    // TIMESTAMPTZ
    // -------------------------------------------------------------------------

    /**
     * timestampTz() must produce TIMESTAMPTZ (timestamp with time zone),
     * unlike MySQL where it maps to plain TIMESTAMP.
     */
    public function testTimestampTzColumn(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->timestamp('ts');
            $t->timestampTz('ts_tz');
        });

        // Assert
        $this->assertSame('timestamp without time zone',
            $this->columnInfo('sb_types', 'ts')['data_type']);
        $this->assertSame('timestamp with time zone',
            $this->columnInfo('sb_types', 'ts_tz')['data_type'],
            'timestampTz() must map to TIMESTAMPTZ on PostgreSQL');
    }

    // -------------------------------------------------------------------------
    // UUID
    // -------------------------------------------------------------------------

    /**
     * uuid() must produce a native UUID column (not CHAR(36) as on MySQL).
     */
    public function testUuidColumn(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->uuid('external_id');
        });

        // Assert
        $info = $this->columnInfo('sb_types', 'external_id');
        $this->assertSame('uuid', $info['data_type'], 'uuid() must be native UUID on PostgreSQL');
    }

    // -------------------------------------------------------------------------
    // ENUM → VARCHAR + CHECK constraint
    // -------------------------------------------------------------------------

    /**
     * enum() on PostgreSQL must produce a VARCHAR column with a CHECK constraint
     * restricting values to the enum list — no native ENUM type.
     */
    public function testEnumIsVarcharWithCheckConstraint(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->enum('status', ['pending', 'active', 'inactive']);
        });

        // Assert — column is VARCHAR
        $info = $this->columnInfo('sb_types', 'status');
        $this->assertSame('character varying', $info['data_type'],
            'PG enum() must be VARCHAR, not a native ENUM type');

        // Assert — CHECK constraint exists for the column
        $this->assertTrue(
            $this->checkConstraintExists('sb_types', 'status'),
            'enum() must add a CHECK constraint on PostgreSQL'
        );
    }

    // -------------------------------------------------------------------------
    // Nullable and default values
    // -------------------------------------------------------------------------

    /**
     * nullable() and default() must be reflected in information_schema.columns.
     */
    public function testNullableAndDefault(): void
    {
        // Act
        $this->schema->createTable('sb_types', function ($t) {
            $t->increments('id');
            $t->string('required');
            $t->string('optional')->nullable();
            $t->integer('count')->default(0);
            $t->boolean('active')->default(true);
        });

        // Assert
        $this->assertSame('NO',  $this->columnInfo('sb_types', 'required')['is_nullable']);
        $this->assertSame('YES', $this->columnInfo('sb_types', 'optional')['is_nullable']);
        $this->assertStringStartsWith('0', $this->columnInfo('sb_types', 'count')['column_default']);
        // PG boolean default is TRUE literal
        $this->assertStringStartsWith('true', $this->columnInfo('sb_types', 'active')['column_default']);
    }

    // -------------------------------------------------------------------------
    // Table and column comments (apostrophe escaping)
    // -------------------------------------------------------------------------

    /**
     * comment() on table and column must store via COMMENT ON TABLE/COLUMN.
     * Apostrophes must be escaped as '' (standard SQL), not \' (MySQL syntax).
     * This is the bug fixed in PostgreSQLSchemaGrammar::compileCommentStatements().
     */
    public function testCommentsWithApostrophe(): void
    {
        // Act — both the table and a column carry apostrophes in their comments
        $this->schema->createTable('sb_types', function ($t) {
            $t->comment("User's profile table");
            $t->increments('id');
            $t->string('title')->comment("Item's display name");
        });

        // Assert — column comment via pg_description
        $colComment = $this->db->execute(
            "SELECT pg_description.description
             FROM pg_description
             JOIN pg_attribute ON pg_attribute.attrelid = pg_description.objoid
                               AND pg_attribute.attnum  = pg_description.objsubid
             JOIN pg_class     ON pg_class.oid = pg_attribute.attrelid
             WHERE pg_class.relname = 'sb_types' AND pg_attribute.attname = 'title'"
        );
        $this->assertNotNull($colComment, 'column comment query must succeed');
        $this->assertSame("Item's display name", $colComment->fields['description'],
            'apostrophe in column comment must be stored correctly');

        // Assert — table comment via pg_description
        $tblComment = $this->db->execute(
            "SELECT pg_description.description
             FROM pg_description
             JOIN pg_class ON pg_class.oid = pg_description.objoid
             WHERE pg_class.relname = 'sb_types' AND pg_description.objsubid = 0"
        );
        $this->assertNotNull($tblComment, 'table comment query must succeed');
        $this->assertSame("User's profile table", $tblComment->fields['description'],
            'apostrophe in table comment must be stored correctly');
    }

    // -------------------------------------------------------------------------
    // Indexes
    // -------------------------------------------------------------------------

    /**
     * createIndex() and dropIndex() must work; dropIndex() uses standalone
     * DROP INDEX (not per-table as in MySQL).
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
        $this->assertTrue($this->indexExists('idx_sb_email'), 'index must exist after createIndex()');

        // Act — drop (PG: standalone DROP INDEX)
        $this->schema->dropIndex('sb_types', 'idx_sb_email');
        $this->assertFalse($this->indexExists('idx_sb_email'), 'index must be gone after dropIndex()');
    }

    /**
     * createUniqueIndex() must create a UNIQUE index in pg_indexes.
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

        // Assert — pg_indexes records it as unique
        $r = $this->db->execute(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = 'public' AND indexname = 'uniq_sb_slug'"
        );
        $this->assertNotNull($r, 'unique index must appear in pg_indexes');
        $this->assertStringContainsString('UNIQUE', $r->fields['indexdef'],
            'index definition must contain UNIQUE keyword');
    }

    // -------------------------------------------------------------------------
    // Foreign key — separate ALTER TABLE on PostgreSQL
    // -------------------------------------------------------------------------

    /**
     * FK constraints in PostgreSQL are emitted as separate ALTER TABLE
     * ADD CONSTRAINT statements (not inline). They must appear in
     * information_schema.table_constraints.
     */
    public function testForeignKeyIsSeparateAlterStatement(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });

        // Act
        $this->schema->createTable('sb_child', function ($t) {
            $t->increments('id');
            $t->integer('parent_id');
            $t->foreign('parent_id')->references('id')->on('sb_parent')->onDelete('cascade');
        });

        // Assert — FK constraint exists in information_schema
        $r = $this->db->execute(
            "SELECT tc.constraint_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name
                  AND kcu.table_schema = tc.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name
                  AND rc.constraint_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public'
               AND tc.table_name = 'sb_child'"
        );
        $this->assertNotNull($r, 'FK constraint query must succeed');
        $this->assertGreaterThan(0, $r->numRows, 'FK constraint must exist in information_schema');
    }

    // -------------------------------------------------------------------------
    // ALTER TABLE — add / drop column
    // -------------------------------------------------------------------------

    /**
     * alterTable() must add a column to an existing table.
     */
    public function testAlterTableAddColumn(): void
    {
        // Arrange
        $this->schema->createTable('sb_alter', fn($t) => $t->increments('id'));
        $this->assertFalse($this->schema->hasColumn('sb_alter', 'notes'));

        // Act
        $this->schema->alterTable('sb_alter', fn($t) => $t->text('notes')->nullable());

        // Assert
        $this->assertTrue($this->schema->hasColumn('sb_alter', 'notes'));
        $this->assertSame('YES', $this->columnInfo('sb_alter', 'notes')['is_nullable']);
    }

    /**
     * alterTable() must remove a column from an existing table.
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
        $this->assertFalse($this->tableExists('sb_alter'),  'old name must not exist');
        $this->assertTrue($this->tableExists('sb_renamed'), 'new name must exist');
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
        $this->db->execute("INSERT INTO sb_trunc VALUES (DEFAULT), (DEFAULT), (DEFAULT)");
        $before = $this->db->execute("SELECT COUNT(*) AS cnt FROM sb_trunc");
        $this->assertSame(3, (int) $before->fields['cnt']);

        // Act
        $this->schema->truncate('sb_trunc');

        // Assert
        $after = $this->db->execute("SELECT COUNT(*) AS cnt FROM sb_trunc");
        $this->assertSame(0, (int) $after->fields['cnt']);
        $this->assertTrue($this->tableExists('sb_trunc'), 'table must still exist after truncate');
    }

    // -------------------------------------------------------------------------
    // Views
    // -------------------------------------------------------------------------

    /**
     * createView() must produce a queryable view; dropView() must remove it.
     */
    public function testCreateAndDropView(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $this->db->execute("INSERT INTO sb_parent (name) VALUES ('Alice')");

        // Act
        $this->schema->createView('sb_view', 'SELECT id, name FROM sb_parent WHERE id > 0');
        $this->assertTrue($this->viewExists('sb_view'), 'view must exist after createView()');

        // Assert — view is queryable
        $r = $this->db->execute("SELECT * FROM sb_view");
        $this->assertSame(1, $r->numRows);

        // Act — drop
        $this->schema->dropView('sb_view');
        $this->assertFalse($this->viewExists('sb_view'), 'view must be gone after dropView()');
    }

    // -------------------------------------------------------------------------
    // Materialized views (PostgreSQL-specific)
    // -------------------------------------------------------------------------

    /**
     * createMaterializedView() must create a materialized view queryable via
     * pg_matviews. refreshMaterializedView() must re-populate it.
     * dropMaterializedView() must remove it.
     */
    public function testMaterializedViewLifecycle(): void
    {
        // Arrange
        $this->schema->createTable('sb_parent', function ($t) {
            $t->increments('id');
            $t->integer('amount');
        });
        $this->db->execute("INSERT INTO sb_parent (amount) VALUES (10), (20), (30)");

        // Act — create
        $this->schema->createMaterializedView(
            'sb_matview',
            'SELECT id, amount FROM sb_parent WHERE amount > 0'
        );

        // Assert — exists
        $this->assertTrue($this->matviewExists('sb_matview'),
            'materialized view must appear in pg_matviews');

        // Assert — queryable and populated
        $r = $this->db->execute("SELECT COUNT(*) AS cnt FROM sb_matview");
        $this->assertSame(3, (int) $r->fields['cnt'], 'materialized view must contain all rows');

        // Arrange — add a new row to source
        $this->db->execute("INSERT INTO sb_parent (amount) VALUES (40)");

        // Act — refresh (WITHOUT CONCURRENTLY since there is no unique index)
        $this->schema->refreshMaterializedView('sb_matview', false);

        // Assert — refresh picked up the new row
        $r2 = $this->db->execute("SELECT COUNT(*) AS cnt FROM sb_matview");
        $this->assertSame(4, (int) $r2->fields['cnt'], 'refresh must include the new row');

        // Act — drop
        $this->schema->dropMaterializedView('sb_matview');
        $this->assertFalse($this->matviewExists('sb_matview'),
            'materialized view must be gone after drop');
    }

    // -------------------------------------------------------------------------
    // quoteTable / resolveTableName — schema-qualified names (PostgreSQL)
    // -------------------------------------------------------------------------

    /**
     * quoteTable() on PostgreSQL must produce "schema"."table" double-quoted form.
     *
     * On PostgreSQL the schema.table notation is preserved (no prefix flattening).
     * The grammar splits on '.' and wraps each part in double quotes, which is the
     * correct PostgreSQL identifier quoting for schema-qualified references.
     */
    public function testQuoteTableProducesDoubleQuotedSchemaTableOnPostgres(): void
    {
        // Act
        $quoted = $this->schema->quoteTable('authserver.roles');

        // Assert — both schema and table parts are individually double-quoted
        $this->assertSame('"authserver"."roles"', $quoted);
    }

    /**
     * resolveTableName() must return the schema.table string unchanged on PostgreSQL.
     *
     * PostgreSQL supports the dot notation natively via search_path and schema
     * qualification. Unlike MySQL (which has no schema concept), no prefix
     * flattening occurs — the dot is preserved for the grammar to handle.
     */
    public function testResolveTableNamePreservesSchemaOnPostgres(): void
    {
        // Act
        $name = $this->schema->resolveTableName('authserver.roles');

        // Assert — returned verbatim; PostgreSQL grammar handles the dot
        $this->assertSame('authserver.roles', $name);
    }

    /**
     * createTable() and hasTable() must both accept schema.table notation on PostgreSQL.
     *
     * 'public.sb_types' creates the table in the public schema (always present in
     * the test database), and hasTable('public.sb_types') must find it.
     * Verifies the full round-trip: resolve → grammar quote → DDL → introspect.
     */
    public function testSchemaQualifiedCreateAndHasTableOnPostgres(): void
    {
        // Arrange — use the 'public' schema which is always present in the test database
        $this->assertFalse(
            $this->schema->hasTable('public.sb_types'),
            'table must not exist before createTable()'
        );

        // Act — create using schema.table notation
        $this->schema->createTable('public.sb_types', function ($t) {
            $t->increments('id');
            $t->string('label');
        });

        // Assert — hasTable with schema.table notation finds the table via schema filter
        $this->assertTrue(
            $this->schema->hasTable('public.sb_types'),
            'hasTable(public.sb_types) must return true for the created table'
        );
        // Confirm the physical table is in the public schema (visible without schema prefix too)
        $this->assertTrue(
            $this->tableExists('sb_types'),
            'table must be visible in the public schema'
        );

        // Cleanup is handled by dropAll() in tearDown()
    }
}
