<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * Integration tests for Trigger DDL against MySQL 8.0.
 *
 * Verifies that SchemaBuilder::createTrigger() / dropTrigger() actually
 * create and remove triggers in the live database — not just that the SQL
 * string looks correct. Every test:
 *   1. Creates the trigger via SchemaBuilder.
 *   2. Inspects information_schema.TRIGGERS to confirm it exists.
 *   3. Drops it (either via SchemaBuilder or implicitly when the table drops).
 *   4. Confirms it no longer appears in information_schema.TRIGGERS.
 *
 * Also verifies that the trigger actually FIRES and has the intended side-effect
 * (writing to an audit table) — this proves the trigger body was accepted by MySQL.
 *
 * Note: MySQL does NOT support sequences. The sequence methods (createSequence /
 * dropSequence) are expected to be silent no-ops on MySQL; this is verified in
 * TriggerSequencePostgreSQLTest via the SchemaGrammar returning '' for MySQL.
 *
 * Requires the Docker MySQL container to be running (host: db, port: 3306).
 */
class TriggerSequenceMySQLTest extends TestCase
{
    /** @var Database Live MySQL connection shared by all tests in the class. */
    protected Database $db;

    /** @var SchemaBuilder Schema builder bound to the live connection. */
    protected SchemaBuilder $schema;

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Connect to the test MySQL instance inside Docker.
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        $this->schema = new SchemaBuilder($this->db);

        // Drop any lingering triggers from a previous failed run before creating tables.
        // MySQL drops triggers automatically when their table is dropped, but we
        // also drop explicitly here to be safe.
        $this->silentlyDropTrigger('trg_log_insert', 'trg_items');

        // Main table: the table the trigger fires on.
        $this->db->query("DROP TABLE IF EXISTS `trg_items`");
        $this->db->query("CREATE TABLE `trg_items` (
            id   INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )");

        // Audit table: records trigger side-effects so we can assert the trigger fired.
        $this->db->query("DROP TABLE IF EXISTS `trg_audit`");
        $this->db->query("CREATE TABLE `trg_audit` (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            action     VARCHAR(50) NOT NULL,
            item_name  VARCHAR(100),
            created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
        )");
    }

    protected function tearDown(): void
    {
        // Triggers are dropped automatically with their table, but we clean up
        // the audit table separately since it has no trigger.
        $this->db->query("DROP TABLE IF EXISTS `trg_audit`");
        $this->db->query("DROP TABLE IF EXISTS `trg_items`");
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to drop a trigger without throwing if it does not exist.
     * Used in setUp() to clear state from a previous crashed test run.
     */
    private function silentlyDropTrigger(string $name, string $table): void
    {
        try {
            $this->db->query("DROP TRIGGER IF EXISTS `{$name}`");
        } catch (\Throwable $e) {
            // Ignore — trigger may not exist.
        }
    }

    /**
     * Return the number of triggers in information_schema matching the given
     * name and table. Returns 0 if absent, 1 if present.
     *
     * This is how we verify that createTrigger / dropTrigger actually took effect.
     */
    private function countTriggers(string $triggerName, string $tableName): int
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.TRIGGERS
                  WHERE TRIGGER_SCHEMA = %s
                    AND TRIGGER_NAME   = %s
                    AND EVENT_OBJECT_TABLE = %s",
                'pramnos_test',
                $triggerName,
                $tableName
            )
        );
        return (int)($result->fields['cnt'] ?? 0);
    }

    /**
     * Count how many rows are in the audit table.
     * Used to verify the trigger actually fired when data was inserted.
     */
    private function countAuditRows(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM `trg_audit`");
        return (int)($result->fields['cnt'] ?? 0);
    }

    // =========================================================================
    // Trigger DDL — create and verify existence
    // =========================================================================

    /**
     * Verify that createTrigger() produces a trigger that MySQL accepts and
     * that we can confirm via information_schema.TRIGGERS.
     *
     * This is the fundamental "does the trigger exist after creation" test.
     * Without this, the remaining tests that check side-effects would be vacuous.
     */
    public function testCreateTriggerExistsInInformationSchema(): void
    {
        // Arrange: the trigger body inserts a row into the audit table on INSERT.
        $body = "BEGIN
            INSERT INTO `trg_audit` (action, item_name) VALUES ('insert', NEW.name);
        END";

        // Act: create the trigger via SchemaBuilder.
        $this->schema->createTrigger('trg_log_insert', 'trg_items', 'AFTER', 'INSERT', $body);

        // Assert: the trigger must appear in information_schema after creation.
        $this->assertEquals(
            1,
            $this->countTriggers('trg_log_insert', 'trg_items'),
            'Trigger trg_log_insert must exist in information_schema.TRIGGERS after createTrigger()'
        );
    }

    /**
     * Verify that the trigger body actually executes when an INSERT fires it.
     *
     * Creating a trigger with valid-looking SQL does not prove the body runs;
     * we must insert a row and check that the audit side-effect happened.
     */
    public function testTriggerFiresOnInsert(): void
    {
        // Arrange: create a trigger that logs every INSERT into trg_items.
        $body = "BEGIN
            INSERT INTO `trg_audit` (action, item_name) VALUES ('insert', NEW.name);
        END";
        $this->schema->createTrigger('trg_log_insert', 'trg_items', 'AFTER', 'INSERT', $body);

        // Act: insert two rows into the watched table.
        $this->db->query("INSERT INTO `trg_items` (name) VALUES ('Widget')");
        $this->db->query("INSERT INTO `trg_items` (name) VALUES ('Gadget')");

        // Assert: the audit table should have exactly 2 entries — one per INSERT.
        $this->assertEquals(
            2,
            $this->countAuditRows(),
            'Trigger must have fired once per INSERT, producing 2 audit rows'
        );
    }

    // =========================================================================
    // Trigger DDL — drop and verify removal
    // =========================================================================

    /**
     * Verify that dropTrigger() removes the trigger from the database, and that
     * after dropping it, firing the table INSERT no longer produces audit rows.
     *
     * This proves both that the DROP statement was issued and that the trigger
     * is actually gone (not just hidden).
     */
    public function testDropTriggerRemovesItFromDatabase(): void
    {
        // Arrange: create the trigger, verify it's there.
        $body = "BEGIN
            INSERT INTO `trg_audit` (action, item_name) VALUES ('insert', NEW.name);
        END";
        $this->schema->createTrigger('trg_log_insert', 'trg_items', 'AFTER', 'INSERT', $body);
        $this->assertEquals(1, $this->countTriggers('trg_log_insert', 'trg_items'));

        // Act: drop the trigger.
        $this->schema->dropTrigger('trg_log_insert', 'trg_items', ifExists: true);

        // Assert 1: trigger must no longer appear in information_schema.
        $this->assertEquals(
            0,
            $this->countTriggers('trg_log_insert', 'trg_items'),
            'Trigger must be absent from information_schema.TRIGGERS after dropTrigger()'
        );

        // Assert 2: inserting a row must no longer produce audit entries.
        $this->db->query("INSERT INTO `trg_items` (name) VALUES ('Orphan')");
        $this->assertEquals(
            0,
            $this->countAuditRows(),
            'No audit rows must be written after the trigger has been dropped'
        );
    }

    /**
     * Verify that dropTrigger() with ifExists=true does NOT throw when called
     * on a trigger that does not exist.
     *
     * The IF EXISTS guard is the standard "safe drop" pattern used in migrations
     * that may be re-run after partial failure.
     */
    public function testDropNonExistentTriggerWithIfExistsDoesNotThrow(): void
    {
        // Act + Assert: no exception should be thrown for a missing trigger.
        $this->expectNotToPerformAssertions();
        $this->schema->dropTrigger('ghost_trigger', 'trg_items', ifExists: true);
    }

    // =========================================================================
    // Sequences — MySQL silent no-op verification
    // =========================================================================

    /**
     * Verify that createSequence() is a silent no-op on MySQL — it does not
     * throw and it does not leave any object in the database.
     *
     * MySQL 8.0 does not support standalone CREATE SEQUENCE. The grammar returns
     * an empty string, and SchemaBuilder skips the query. This test confirms the
     * entire call path is safe on MySQL.
     */
    public function testCreateSequenceIsNoOpOnMySQL(): void
    {
        // Act: call createSequence on a MySQL connection — must not throw.
        $this->schema->createSequence('order_seq', start: 1000, increment: 5);

        // Assert: MySQL has no sequence objects, so information_schema returns nothing.
        //         We simply verify no exception was thrown (no assertions needed
        //         beyond "it didn't crash").
        $this->assertTrue(
            true,
            'createSequence() must be a silent no-op on MySQL without throwing'
        );
    }

    /**
     * Verify that dropSequence() is also a silent no-op on MySQL.
     *
     * If createSequence is a no-op, dropSequence must be too — otherwise code
     * that creates and then drops a sequence in a migration would fail on MySQL
     * at the drop step.
     */
    public function testDropSequenceIsNoOpOnMySQL(): void
    {
        // Act + Assert: must not throw, must not produce a MySQL error.
        $this->schema->dropSequence('order_seq', ifExists: true);
        $this->assertTrue(
            true,
            'dropSequence() must be a silent no-op on MySQL without throwing'
        );
    }
}
