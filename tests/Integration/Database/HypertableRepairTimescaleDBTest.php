<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\HypertableRegistry;

/**
 * Integration tests for the hypertable repair, against a real TimescaleDB.
 *
 * WHAT: converting an existing, populated, plain table into a hypertable and
 *       applying compression and retention — then doing it again.
 * WHY:  this is the situation the repair exists for. A database that ran the
 *       framework migrations **before** TimescaleDB was installed keeps plain
 *       tables for ever: the migrations are recorded as applied and never run
 *       again, so those tables are never partitioned, never compressed, and
 *       their retention policies never apply — they grow without bound.
 *
 * Unit tests can prove the sequencing; only a real database can prove that
 * `migrate_data => true` keeps the rows, that the guards actually see what
 * TimescaleDB reports, and — the one that bites — that a **second** run does
 * not raise. `add_compression_policy()` and `add_retention_policy()` error on a
 * duplicate rather than no-opping, so an unguarded repair works exactly once.
 */
class HypertableRepairTimescaleDBTest extends TestCase
{
    /** @var Database Connection to the TimescaleDB service */
    private Database $db;

    /** @var \Pramnos\Database\SchemaBuilder The builder under test */
    private $schema;

    /** Plain table name used throughout; dropped in tearDown. */
    private const TABLE = 'repair_probe';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = $_ENV['PG_HOST'] ?? (getenv('PG_HOST') ?: 'timescaledb');
        $this->db->port     = 5432;
        $this->db->user     = $_ENV['PG_USER'] ?? (getenv('PG_USER') ?: 'postgres');
        $this->db->password = $_ENV['PG_PASS'] ?? (getenv('PG_PASS') ?: 'secret');
        $this->db->database = $_ENV['PG_NAME'] ?? (getenv('PG_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('TimescaleDB not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('TimescaleDB not reachable: ' . $e->getMessage());
        }

        $this->schema = $this->db->schema();

        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('The TimescaleDB extension is not installed here');
        }

        $this->dropProbe();
        HypertableRegistry::register(self::TABLE, [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '30 days',
            'retention'      => '1 year',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropProbe();
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
        // Leave the framework's own declarations as the next test finds them.
        HypertableRegistry::reset();
        parent::tearDown();
    }

    /** Remove the probe table and anything TimescaleDB attached to it. */
    private function dropProbe(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE . ' CASCADE');
    }

    /**
     * Create the probe exactly as the framework creates these tables: a plain
     * table with a composite `(id, <time column>)` primary key, populated.
     *
     * @param int $rows How many rows to spread over the past $rows days
     */
    private function createPopulatedPlainTable(int $rows = 40): void
    {
        $this->db->query(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id BIGSERIAL, '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), '
            . 'payload TEXT, '
            . 'PRIMARY KEY (id, created_at))'
        );

        for ($i = 0; $i < $rows; $i++) {
            $this->db->query(
                'INSERT INTO ' . self::TABLE . " (created_at, payload) "
                . "VALUES (NOW() - INTERVAL '{$i} days', 'row {$i}')"
            );
        }
    }

    /** How many rows the probe holds. */
    private function rowCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE
        )->fields['cnt'];
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * A populated plain table is converted, and every row survives.
     *
     * The row count is the assertion that matters: `migrate_data => true` is
     * what moves existing rows into chunks, and without it the conversion
     * either fails or (on other paths) leaves data behind.
     */
    public function testAPopulatedPlainTableIsConvertedWithoutLosingRows(): void
    {
        // Arrange
        $this->createPopulatedPlainTable(40);
        $this->assertFalse(
            $this->schema->hasHypertable(self::TABLE),
            'precondition: this is the plain table a late TimescaleDB install leaves behind'
        );

        // Act
        $done = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertTrue($this->schema->hasHypertable(self::TABLE));
        $this->assertSame(40, $this->rowCount(), 'no row may be lost in the rewrite');
        $this->assertContains('converted to hypertable', $done);
    }

    /**
     * The data really is partitioned, not merely registered.
     *
     * A hypertable with one chunk would satisfy `hasHypertable()` while telling
     * us nothing about whether the 40 days of rows were actually spread across
     * 7-day chunks — which is the whole point of the conversion, and what
     * retention later drops chunk by chunk.
     */
    public function testTheDataIsSpreadAcrossChunks(): void
    {
        // Arrange — 40 days of rows in 7-day chunks
        $this->createPopulatedPlainTable(40);

        // Act
        HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $chunks = (int) $this->db->query(
            $this->db->prepareQuery(
                'SELECT COUNT(*) AS cnt FROM timescaledb_information.chunks
                 WHERE hypertable_name = %s',
                self::TABLE
            )
        )->fields['cnt'];

        $this->assertGreaterThan(1, $chunks, 'the rows must be partitioned, not left in one chunk');
    }

    /**
     * Compression and both policies end up in place.
     *
     * The retention policy is the one that matters most: without it these
     * tables grow for ever, which is the actual cost of the bug being repaired.
     */
    public function testCompressionAndBothPoliciesAreApplied(): void
    {
        // Arrange
        $this->createPopulatedPlainTable(10);

        // Act
        HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertTrue($this->schema->isCompressionEnabled(self::TABLE), 'compression');
        $this->assertTrue($this->schema->hasCompressionPolicy(self::TABLE), 'compression policy');
        $this->assertTrue($this->schema->hasRetentionPolicy(self::TABLE), 'retention policy');
    }

    /**
     * Running it again changes nothing and, crucially, does not raise.
     *
     * This is the single most important property of the whole feature. Both
     * policy functions error on a duplicate instead of no-opping, so a repair
     * without existence guards works exactly once and fails ever after — which
     * is worse than not having one, because it fails *after* an operator has
     * been told to run it regularly.
     */
    public function testASecondRunIsANoOpAndDoesNotRaise(): void
    {
        // Arrange
        $this->createPopulatedPlainTable(10);
        HypertableRegistry::apply($this->schema, self::TABLE);

        // Act
        $second = HypertableRegistry::apply($this->schema, self::TABLE);
        $third  = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertSame([], $second, 'a repeat run must do nothing');
        $this->assertSame([], $third);
    }

    /**
     * An already-converted table is finished off rather than re-converted.
     *
     * A half-repaired database — converted by hand, policies never added — is a
     * realistic state, and re-issuing the conversion would raise.
     */
    public function testAHandConvertedTableGetsOnlyItsMissingPolicies(): void
    {
        // Arrange — converted, but nothing else. Empty, because a bare
        // create_hypertable() refuses a populated table: that refusal is the
        // very reason the repair passes migrate_data.
        $this->createPopulatedPlainTable(0);
        $this->db->query(
            "SELECT create_hypertable('" . self::TABLE . "', 'created_at')"
        );

        // Act
        $done = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertNotContains('converted to hypertable', $done);
        $this->assertTrue($this->schema->hasRetentionPolicy(self::TABLE));
    }

    /**
     * The primary key really is `(id, <time column>)` on the tables this repairs.
     *
     * TimescaleDB requires the partitioning column in every unique constraint,
     * so a key of `(id)` alone makes conversion impossible. The framework
     * creates these keys unconditionally — outside the `ifCapable()` block —
     * which is precisely what makes a late repair possible at all. It is worth
     * an assertion rather than an assumption.
     */
    /**
     * A changed declaration reaches the database.
     *
     * The whole point of the mechanism, against a real scheduler. Before it existed,
     * `apply()` skipped any table that already had a policy — so an interval could be
     * created once and never changed, and `timescale:ensure` reported "nothing missing"
     * while the number in the code and the number in the database disagreed for ever.
     *
     * Asserted by reading the interval back out of `timescaledb_information.jobs`, not
     * by trusting the return value: the removal and the re-add are two statements, and
     * a failure between them leaves a table with no policy at all.
     */
    public function testAChangedRetentionIntervalReachesTheDatabase(): void
    {
        // Arrange — a fully configured table
        $this->createPopulatedPlainTable();
        HypertableRegistry::apply($this->schema, self::TABLE);
        $this->assertSame(
            $this->normalised('1 year'),
            $this->normalised($this->schema->policyInterval(self::TABLE))
        );

        // Act — the declaration changes
        HypertableRegistry::register(self::TABLE, [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '30 days',
            'retention'      => '90 days',
        ]);
        $done = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertContains('retention policy changed to 90 days', $done);
        $this->assertSame(
            $this->normalised('90 days'),
            $this->normalised($this->schema->policyInterval(self::TABLE))
        );

        // And exactly one policy remains — a remove that did not remove would leave two,
        // and add_retention_policy() would have raised rather than replaced.
        $jobs = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM timescaledb_information.jobs
              WHERE proc_name = 'policy_retention' AND hypertable_name = '" . self::TABLE . "'"
        );
        $this->assertSame(1, (int) $jobs->fields['cnt']);
    }

    /**
     * Applying twice with an unchanged declaration does nothing the second time.
     *
     * The repair command runs on demand and sometimes on a schedule, so "already
     * correct" is by far its most common input. Replacing a policy that matches would
     * be constant churn against the scheduler for no change — and it is the failure a
     * naive string comparison produces, because PostgreSQL does not hand intervals back
     * spelt the way they were written.
     */
    public function testASecondApplyWithNoChangeIsANoOp(): void
    {
        // Arrange
        $this->createPopulatedPlainTable();
        HypertableRegistry::apply($this->schema, self::TABLE);

        // Act
        $done = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertSame([], $done, 'a correct database must not be rewritten');
    }

    /**
     * A changed compression interval reaches the database too.
     */
    public function testAChangedCompressionIntervalReachesTheDatabase(): void
    {
        // Arrange
        $this->createPopulatedPlainTable();
        HypertableRegistry::apply($this->schema, self::TABLE);

        // Act
        HypertableRegistry::register(self::TABLE, [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '3 days',
            'retention'      => '1 year',
        ]);
        $done = HypertableRegistry::apply($this->schema, self::TABLE);

        // Assert
        $this->assertContains('compression policy changed to 3 days', $done);
        $this->assertSame(
            $this->normalised('3 days'),
            $this->normalised($this->schema->policyInterval(self::TABLE, 'compression'))
        );
    }

    /**
     * Reduce an interval to a form two spellings share, for assertions.
     *
     * PostgreSQL returns `@ 90 days`, `90 days` or `90 day` depending on where it is
     * read from, and none of those differences are what these tests are about.
     */
    private function normalised(?string $interval): string
    {
        if ($interval === null) {
            return '';
        }

        $value = ltrim(strtolower(trim($interval)), '@ ');
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return preg_match('/^(\d+)\s*(second|minute|hour|day|week|month|year)s?$/', $value, $m)
            ? $m[1] . ' ' . $m[2] . 's'
            : $value;
    }

    public function testTheCompositeKeyThatMakesRepairPossibleIsReadable(): void
    {
        // Arrange
        $this->createPopulatedPlainTable(1);

        // Act
        $key = $this->schema->primaryKeyColumns(self::TABLE);

        // Assert
        $this->assertContains('id', $key);
        $this->assertContains('created_at', $key, 'the partitioning column must be in the key');
    }

    /**
     * A key without the time column is detectable, so a repair can explain
     * itself instead of surfacing a driver error.
     */
    public function testAKeyMissingTheTimeColumnIsVisible(): void
    {
        // Arrange — the shape that cannot be converted
        $this->db->query(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id BIGSERIAL PRIMARY KEY, '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())'
        );

        // Act
        $key = $this->schema->primaryKeyColumns(self::TABLE);

        // Assert
        $this->assertSame(['id'], $key);
        $this->assertNotContains('created_at', $key);
    }
}
