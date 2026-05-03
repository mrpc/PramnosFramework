<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * Integration tests for Trigger and Sequence DDL against PostgreSQL 14
 * (TimescaleDB container, which is a superset of standard PostgreSQL).
 *
 * PostgreSQL triggers differ from MySQL in two important ways:
 *   1. The trigger body must call a separately-created PL/pgSQL FUNCTION — the
 *      function is created first via raw SQL, and the trigger references it with
 *      "EXECUTE FUNCTION fn_name()".
 *   2. DROP TRIGGER requires the table name: DROP TRIGGER name ON table.
 *
 * This suite verifies:
 *   - createTrigger() / dropTrigger() produce working DDL (not just valid strings).
 *   - Triggers actually fire and produce side-effects.
 *   - createSequence() / dropSequence() create/remove real PostgreSQL SEQUENCE objects.
 *   - Sequences increment correctly (nextval works after creation).
 *   - The IF EXISTS guard prevents errors on repeated drops.
 *
 * Schema used:
 *   trg_pg_items  — table the trigger fires on
 *   trg_pg_audit  — audit table populated by the trigger body
 *
 * Requires the Docker TimescaleDB/PostgreSQL container to be running
 * (host: timescaledb, port: 5432).
 */
class TriggerSequencePostgreSQLTest extends TestCase
{
    /** @var Database Live PostgreSQL connection shared by all tests. */
    protected Database $db;

    /** @var SchemaBuilder Schema builder bound to the live connection. */
    protected SchemaBuilder $schema;

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Connect to the PostgreSQL/TimescaleDB test instance in Docker.
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
        $this->db->connect(true);

        $this->schema = new SchemaBuilder($this->db);

        // Drop any objects left over from a previous failed test run.
        // PostgreSQL requires explicit function/trigger cleanup.
        $this->db->execute("DROP TRIGGER IF EXISTS trg_pg_log_insert ON trg_pg_items");
        $this->db->execute("DROP FUNCTION IF EXISTS trg_pg_log_fn()");
        $this->db->execute("DROP SEQUENCE IF EXISTS test_seq");
        $this->db->execute("DROP SEQUENCE IF EXISTS test_seq_custom");
        $this->db->execute("DROP TABLE IF EXISTS trg_pg_audit");
        $this->db->execute("DROP TABLE IF EXISTS trg_pg_items");

        // Create the table the trigger fires on.
        $this->db->execute("CREATE TABLE trg_pg_items (
            id   SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )");

        // Create the audit table that the trigger populates.
        $this->db->execute("CREATE TABLE trg_pg_audit (
            id         SERIAL PRIMARY KEY,
            action     VARCHAR(50)  NOT NULL,
            item_name  VARCHAR(100),
            created_at TIMESTAMPTZ  DEFAULT NOW()
        )");

        // Create the PL/pgSQL function the trigger will call.
        // PostgreSQL triggers cannot contain inline SQL — they must delegate
        // to a FUNCTION that returns TRIGGER.
        $this->db->execute("
            CREATE OR REPLACE FUNCTION trg_pg_log_fn()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                INSERT INTO trg_pg_audit (action, item_name) VALUES ('insert', NEW.name);
                RETURN NEW;
            END;
            \$\$
        ");
    }

    protected function tearDown(): void
    {
        // Drop in reverse dependency order: trigger → function → tables.
        // Sequences are independent and dropped individually.
        $this->db->execute("DROP TRIGGER IF EXISTS trg_pg_log_insert ON trg_pg_items");
        $this->db->execute("DROP FUNCTION IF EXISTS trg_pg_log_fn()");
        $this->db->execute("DROP TABLE IF EXISTS trg_pg_audit");
        $this->db->execute("DROP TABLE IF EXISTS trg_pg_items");
        $this->db->execute("DROP SEQUENCE IF EXISTS test_seq");
        $this->db->execute("DROP SEQUENCE IF EXISTS test_seq_custom");
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the number of triggers in pg_trigger matching the given name and
     * table. Uses the catalog view rather than information_schema because
     * pg_trigger is more reliable for this lookup in PostgreSQL.
     *
     * Returns 1 if the trigger exists, 0 if it does not.
     */
    private function countTriggers(string $triggerName, string $tableName): int
    {
        $result = $this->db->execute(
            "SELECT COUNT(*) AS cnt
               FROM pg_trigger t
               JOIN pg_class c ON c.oid = t.tgrelid
              WHERE t.tgname = '{$triggerName}'
                AND c.relname = '{$tableName}'"
        );
        return (int)($result->fields['cnt'] ?? 0);
    }

    /**
     * Return the number of sequences in pg_sequences matching the given name.
     * Used to verify that createSequence / dropSequence actually took effect.
     */
    private function countSequences(string $seqName): int
    {
        $result = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM pg_sequences WHERE sequencename = '{$seqName}'"
        );
        return (int)($result->fields['cnt'] ?? 0);
    }

    /**
     * Count rows in the audit table.
     * Used to verify that the trigger actually fired on table INSERT.
     */
    private function countAuditRows(): int
    {
        $result = $this->db->execute("SELECT COUNT(*) AS cnt FROM trg_pg_audit");
        return (int)($result->fields['cnt'] ?? 0);
    }

    /**
     * Call nextval() on a sequence and return the value.
     * Used to verify the sequence increments as configured after creation.
     */
    private function nextval(string $seqName): int
    {
        $result = $this->db->execute("SELECT nextval('{$seqName}') AS val");
        return (int)($result->fields['val'] ?? 0);
    }

    // =========================================================================
    // Trigger DDL — create and verify existence
    // =========================================================================

    /**
     * Verify that createTrigger() produces a trigger that PostgreSQL accepts
     * and that we can confirm its existence via pg_trigger.
     *
     * PostgreSQL trigger bodies use "EXECUTE FUNCTION fn_name()" — the function
     * is created separately in setUp(). The trigger references it by name.
     */
    public function testCreateTriggerExistsInPgCatalog(): void
    {
        // Act: create the trigger using the pre-existing trg_pg_log_fn function.
        $this->schema->createTrigger(
            'trg_pg_log_insert',
            'trg_pg_items',
            'AFTER',
            'INSERT',
            'EXECUTE FUNCTION trg_pg_log_fn()'
        );

        // Assert: trigger must appear in pg_trigger after creation.
        $this->assertEquals(
            1,
            $this->countTriggers('trg_pg_log_insert', 'trg_pg_items'),
            'Trigger trg_pg_log_insert must appear in pg_trigger after createTrigger()'
        );
    }

    /**
     * Verify the trigger body actually fires when an INSERT occurs.
     *
     * The trg_pg_log_fn() function writes to trg_pg_audit on every INSERT.
     * We insert two rows and check that the audit table grew by exactly two.
     */
    public function testTriggerFiresOnInsert(): void
    {
        // Arrange: create the trigger.
        $this->schema->createTrigger(
            'trg_pg_log_insert',
            'trg_pg_items',
            'AFTER',
            'INSERT',
            'EXECUTE FUNCTION trg_pg_log_fn()'
        );

        // Act: fire two INSERT statements on the watched table.
        $this->db->execute("INSERT INTO trg_pg_items (name) VALUES ('Widget')");
        $this->db->execute("INSERT INTO trg_pg_items (name) VALUES ('Gadget')");

        // Assert: exactly 2 audit rows — one per INSERT.
        $this->assertEquals(
            2,
            $this->countAuditRows(),
            'Trigger must produce exactly one audit row per INSERT statement'
        );
    }

    // =========================================================================
    // Trigger DDL — drop and verify removal
    // =========================================================================

    /**
     * Verify dropTrigger() removes the trigger from the database and that
     * subsequent INSERTs no longer produce audit side-effects.
     *
     * PostgreSQL DROP TRIGGER requires the table name (ON table_name).
     * This test confirms the grammar emits the correct syntax.
     */
    public function testDropTriggerRemovesItFromDatabase(): void
    {
        // Arrange: create the trigger, verify it's present.
        $this->schema->createTrigger(
            'trg_pg_log_insert',
            'trg_pg_items',
            'AFTER',
            'INSERT',
            'EXECUTE FUNCTION trg_pg_log_fn()'
        );
        $this->assertEquals(1, $this->countTriggers('trg_pg_log_insert', 'trg_pg_items'));

        // Act: drop it via SchemaBuilder.
        $this->schema->dropTrigger('trg_pg_log_insert', 'trg_pg_items', ifExists: true);

        // Assert 1: trigger must be gone from pg_trigger.
        $this->assertEquals(
            0,
            $this->countTriggers('trg_pg_log_insert', 'trg_pg_items'),
            'Trigger must be absent from pg_trigger after dropTrigger()'
        );

        // Assert 2: inserting a row must no longer write to the audit table.
        $this->db->execute("INSERT INTO trg_pg_items (name) VALUES ('Orphan')");
        $this->assertEquals(
            0,
            $this->countAuditRows(),
            'No audit rows should be written after the trigger has been dropped'
        );
    }

    /**
     * Verify that dropTrigger() with ifExists=true does not throw when called
     * on a non-existent trigger.
     *
     * Migrations that may be re-run after partial failure rely on IF EXISTS.
     */
    public function testDropNonExistentTriggerWithIfExistsDoesNotThrow(): void
    {
        // Act + Assert: calling dropTrigger on an absent trigger must not throw.
        $this->expectNotToPerformAssertions();
        $this->schema->dropTrigger('ghost_trigger', 'trg_pg_items', ifExists: true);
    }

    // =========================================================================
    // Sequence DDL — create and verify
    // =========================================================================

    /**
     * Verify that createSequence() creates a real PostgreSQL SEQUENCE object
     * that is visible in pg_sequences and functional via nextval().
     *
     * A sequence that exists in the catalog but cannot be incremented would be
     * useless; this test verifies both existence and operability.
     */
    public function testCreateSequenceExistsAndIsUsable(): void
    {
        // Act: create a sequence with default start=1, increment=1.
        $this->schema->createSequence('test_seq');

        // Assert 1: sequence must appear in pg_sequences.
        $this->assertEquals(
            1,
            $this->countSequences('test_seq'),
            'Sequence test_seq must appear in pg_sequences after createSequence()'
        );

        // Assert 2: nextval() must return an integer (proves the sequence is live).
        $val = $this->nextval('test_seq');
        $this->assertIsInt($val, 'nextval(test_seq) must return an integer');
        $this->assertGreaterThanOrEqual(
            1,
            $val,
            'First nextval() call must return a value >= the configured START (1)'
        );
    }

    /**
     * Verify that createSequence() respects the start, increment, minValue,
     * maxValue, and cycle options.
     *
     * This tests the full option set — a sequence configured to start at 100
     * with step 5 should return 100 on first nextval, then 105.
     */
    public function testCreateSequenceWithCustomOptions(): void
    {
        // Act: create a sequence starting at 100, incrementing by 5.
        $this->schema->createSequence(
            'test_seq_custom',
            start: 100,
            increment: 5,
            minValue: 100,
            maxValue: 500,
            cycle: false
        );

        // Assert: first nextval returns exactly 100 (the configured start).
        $first = $this->nextval('test_seq_custom');
        $this->assertEquals(
            100,
            $first,
            'First nextval must equal the configured START WITH value (100)'
        );

        // Assert: second nextval returns 100 + 5 = 105 (the configured increment).
        $second = $this->nextval('test_seq_custom');
        $this->assertEquals(
            105,
            $second,
            'Second nextval must equal start + increment (100 + 5 = 105)'
        );
    }

    // =========================================================================
    // Sequence DDL — drop and verify removal
    // =========================================================================

    /**
     * Verify that dropSequence() removes the sequence from pg_sequences and
     * that referencing it afterwards would fail (we verify absence, not failure).
     *
     * This is the symmetrical counterpart to testCreateSequenceExistsAndIsUsable.
     */
    public function testDropSequenceRemovesItFromDatabase(): void
    {
        // Arrange: create the sequence, verify it's there.
        $this->schema->createSequence('test_seq');
        $this->assertEquals(1, $this->countSequences('test_seq'));

        // Act: drop the sequence.
        $this->schema->dropSequence('test_seq', ifExists: true);

        // Assert: sequence must no longer appear in pg_sequences.
        $this->assertEquals(
            0,
            $this->countSequences('test_seq'),
            'Sequence test_seq must be absent from pg_sequences after dropSequence()'
        );
    }

    /**
     * Verify that dropSequence() with ifExists=true does not throw when called
     * on a sequence that has already been dropped or was never created.
     *
     * This is the "safe drop" pattern required for idempotent migrations.
     */
    public function testDropNonExistentSequenceWithIfExistsDoesNotThrow(): void
    {
        // Act + Assert: must not throw even though the sequence does not exist.
        $this->expectNotToPerformAssertions();
        $this->schema->dropSequence('ghost_sequence', ifExists: true);
    }

    /**
     * Verify that CREATE SEQUENCE IF NOT EXISTS is idempotent — calling
     * createSequence() twice on the same name must not throw.
     *
     * The grammar uses "CREATE SEQUENCE IF NOT EXISTS", so a second call
     * should be silently ignored by PostgreSQL.
     */
    public function testCreateSequenceIsIdempotent(): void
    {
        // Act: create the same sequence twice.
        $this->schema->createSequence('test_seq');
        $this->schema->createSequence('test_seq'); // must not throw

        // Assert: only one sequence exists (not two).
        $this->assertEquals(
            1,
            $this->countSequences('test_seq'),
            'Calling createSequence() twice must not create duplicates'
        );
    }
}
