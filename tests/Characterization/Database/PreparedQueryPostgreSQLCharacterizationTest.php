<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Application\Application;
use Pramnos\Framework\Factory;

/**
 * Characterization tests for Database::preparedQuery() and Result::fetchColumn().
 *
 * preparedQuery() is the PDO-style bridge added for applications migrating off a
 * raw \PDO handle: it accepts SQL verbatim with either :named or ? placeholders
 * and runs it through the native prepared-statement engine. These tests pin down
 * the behaviours that migrating code depends on for byte-for-byte parity with
 * PDO — placeholder parsing (named, repeated, positional, '::' casts, quoted
 * literals), null/boolean binding, RETURNING, PostgreSQL type-casting of the
 * returned rows, and the error cases — plus fetchColumn()'s PDO-compatible
 * "value or false when exhausted" contract.
 */
#[\PHPUnit\Framework\Attributes\Group('postgresql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PreparedQueryPostgreSQLCharacterizationTest extends TestCase
{
    /** @var \Pramnos\Database\Database */
    private $db;

    private const CREATE_SQL = "
        CREATE TABLE pq_items (
            id       BIGSERIAL PRIMARY KEY,
            name     VARCHAR(100) NOT NULL DEFAULT '',
            qty      INTEGER      NOT NULL DEFAULT 0,
            enabled  BOOLEAN      NOT NULL DEFAULT false,
            note     TEXT         DEFAULT NULL,
            UNIQUE (name)
        )
    ";

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DIRECTORY_SEPARATOR . 'var');
        }
        if (!is_dir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs')) {
            @mkdir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        }

        Settings::loadSettings(ROOT . '/tests/fixtures/app/pg_settings.php');
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->db->query('DROP TABLE IF EXISTS pq_items CASCADE');
        $this->db->query(self::CREATE_SQL);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS pq_items CASCADE');
    }

    // ── Named placeholders ──────────────────────────────────────────────────

    /**
     * A named placeholder binds its value as a real parameter, and the returned
     * Result exposes the row via fetch()/fetchAll(), just like a PDO statement.
     */
    public function testNamedPlaceholderRoundTrips(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:name, :qty)',
            ['name' => 'apple', 'qty' => 3]
        );

        $result = $this->db->preparedQuery(
            'SELECT name, qty FROM pq_items WHERE name = :name',
            ['name' => 'apple']
        );

        $this->assertNotFalse($result);
        $row = $result->fetch();
        $this->assertSame('apple', $row['name']);
        // qty is an integer column → the framework Result casts it to a PHP int.
        $this->assertSame(3, $row['qty']);
    }

    /**
     * Binding keys may be given with or without the leading ':'.
     */
    public function testNamedPlaceholderKeysToleratePrefixColon(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:name, :qty)',
            [':name' => 'pear', ':qty' => 7]
        );

        $row = $this->db->preparedQuery(
            'SELECT qty FROM pq_items WHERE name = :name',
            [':name' => 'pear']
        )->fetch();

        $this->assertSame(7, $row['qty']);
    }

    /**
     * A named placeholder that appears more than once binds its value to every
     * occurrence — the pattern used by ON CONFLICT ... DO UPDATE SET x = :v.
     */
    public function testRepeatedNamedPlaceholderBindsEachOccurrence(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:v, 1)
             ON CONFLICT (name) DO UPDATE SET name = :v',
            ['v' => 'banana']
        );

        // Run it again: the second call hits the ON CONFLICT branch and must
        // still resolve both :v occurrences to the same bound value.
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:v, 1)
             ON CONFLICT (name) DO UPDATE SET name = :v',
            ['v' => 'banana']
        );

        $count = $this->db->preparedQuery(
            'SELECT COUNT(*) AS c FROM pq_items WHERE name = :v',
            ['v' => 'banana']
        )->fetch();
        $this->assertSame(1, $count['c']);
    }

    // ── Positional placeholders ─────────────────────────────────────────────

    /**
     * Positional ? placeholders consume the binding list in order — the pattern
     * used for IN (?, ?, ...) clauses.
     */
    public function testPositionalPlaceholdersInClause(): void
    {
        foreach (['x', 'y', 'z'] as $i => $n) {
            $this->db->preparedQuery(
                'INSERT INTO pq_items (name, qty) VALUES (?, ?)',
                [$n, $i]
            );
        }

        $rows = $this->db->preparedQuery(
            'SELECT name FROM pq_items WHERE name IN (?, ?) ORDER BY name',
            ['x', 'z']
        )->fetchAll();

        $this->assertSame(['x', 'z'], array_column($rows, 'name'));
    }

    // ── Parsing edge cases ──────────────────────────────────────────────────

    /**
     * A ':' that is part of a '::type' cast must NOT be treated as a named
     * placeholder (PostgreSQL cast syntax must survive verbatim).
     */
    public function testDoubleColonCastIsNotAPlaceholder(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:name, :qty)',
            ['name' => 'castme', 'qty' => 42]
        );

        $row = $this->db->preparedQuery(
            "SELECT (qty::text) AS q FROM pq_items WHERE name = :name",
            ['name' => 'castme']
        )->fetch();

        $this->assertSame('42', $row['q']);
    }

    /**
     * A token that looks like a placeholder but sits inside a single-quoted
     * string literal is left untouched.
     */
    public function testPlaceholderInsideStringLiteralIsIgnored(): void
    {
        $row = $this->db->preparedQuery(
            "SELECT ':notabind' AS lit, :real AS bound",
            ['real' => 'ok']
        )->fetch();

        $this->assertSame(':notabind', $row['lit']);
        $this->assertSame('ok', $row['bound']);
    }

    // ── Value binding semantics ─────────────────────────────────────────────

    /**
     * A null binding stores SQL NULL (not the string "null" or empty string).
     */
    public function testNullBindingStoresSqlNull(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, note) VALUES (:name, :note)',
            ['name' => 'nn', 'note' => null]
        );

        $row = $this->db->preparedQuery(
            'SELECT note FROM pq_items WHERE name = :name',
            ['name' => 'nn']
        )->fetch();

        $this->assertNull($row['note']);
    }

    /**
     * A boolean binding stores the driver-native boolean, and the returned
     * Result casts a boolean column back to a PHP bool.
     */
    public function testBooleanBindingAndCasting(): void
    {
        $this->db->preparedQuery(
            'INSERT INTO pq_items (name, enabled) VALUES (:name, :enabled)',
            ['name' => 'flag', 'enabled' => true]
        );

        $row = $this->db->preparedQuery(
            'SELECT enabled FROM pq_items WHERE name = :name',
            ['name' => 'flag']
        )->fetch();

        $this->assertTrue($row['enabled']);
    }

    /**
     * RETURNING is passed through verbatim; the generated id comes back as a row.
     */
    public function testReturningClause(): void
    {
        $result = $this->db->preparedQuery(
            'INSERT INTO pq_items (name, qty) VALUES (:name, :qty) RETURNING id',
            ['name' => 'ret', 'qty' => 9]
        );

        $row = $result->fetch();
        $this->assertArrayHasKey('id', $row);
        $this->assertGreaterThan(0, (int) $row['id']);
    }

    /**
     * With no bindings, preparedQuery() behaves like a plain query().
     */
    public function testNoBindingsBehavesLikeQuery(): void
    {
        $this->db->preparedQuery('INSERT INTO pq_items (name, qty) VALUES (:n, :q)', ['n' => 'a', 'q' => 1]);
        $result = $this->db->preparedQuery('SELECT COUNT(*) AS c FROM pq_items');
        $this->assertSame(1, $result->fetch()['c']);
    }

    // ── Error handling ──────────────────────────────────────────────────────

    /**
     * A named placeholder with no matching binding is a programming error and
     * must throw, rather than silently produce a malformed statement.
     */
    public function testMissingNamedBindingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->preparedQuery(
            'SELECT * FROM pq_items WHERE name = :name AND qty = :qty',
            ['name' => 'x'] // :qty missing
        );
    }

    /**
     * Passing more positional bindings than there are ? placeholders is a
     * mismatch and must throw.
     */
    public function testPositionalCountMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->preparedQuery(
            'SELECT * FROM pq_items WHERE name = ?',
            ['x', 'surplus']
        );
    }

    // ── Result::fetchColumn() ────────────────────────────────────────────────

    /**
     * fetchColumn() returns the first column of the next row, then false once
     * the rows are exhausted — matching PDOStatement::fetchColumn().
     */
    public function testFetchColumnReturnsValueThenFalse(): void
    {
        $this->db->preparedQuery('INSERT INTO pq_items (name, qty) VALUES (?, ?)', ['solo', 5]);

        $result = $this->db->preparedQuery(
            'SELECT qty FROM pq_items WHERE name = :name',
            ['name' => 'solo']
        );

        $this->assertSame(5, $result->fetchColumn());
        // Only one row → the next fetchColumn() must report exhaustion as false.
        $this->assertFalse($result->fetchColumn());
    }

    /**
     * fetchColumn() honours the zero-based column index argument.
     */
    public function testFetchColumnByIndex(): void
    {
        $this->db->preparedQuery('INSERT INTO pq_items (name, qty) VALUES (?, ?)', ['idx', 8]);

        $value = $this->db->preparedQuery(
            'SELECT name, qty FROM pq_items WHERE name = :name',
            ['name' => 'idx']
        )->fetchColumn(1);

        $this->assertSame(8, $value);
    }

    /**
     * fetchColumn() on an empty result returns false immediately.
     */
    public function testFetchColumnOnEmptyResultIsFalse(): void
    {
        $result = $this->db->preparedQuery(
            'SELECT qty FROM pq_items WHERE name = :name',
            ['name' => 'does-not-exist']
        );

        $this->assertFalse($result->fetchColumn());
    }

    // ── inTransaction() ──────────────────────────────────────────────────────

    /**
     * inTransaction() tracks the state set by start/commit/rollback: false when
     * idle, true between start and commit, false again after commit.
     */
    public function testInTransactionReflectsCommitLifecycle(): void
    {
        $this->assertFalse($this->db->inTransaction());

        $this->db->startTransaction();
        $this->assertTrue($this->db->inTransaction());

        $this->db->commitTransaction();
        $this->assertFalse($this->db->inTransaction());
    }

    /**
     * A rollback clears the flag and actually reverts the write, and
     * inTransaction() reads false afterwards.
     */
    public function testRollbackClearsFlagAndRevertsWrite(): void
    {
        $this->db->startTransaction();
        $this->db->preparedQuery('INSERT INTO pq_items (name) VALUES (:n)', ['n' => 'rollme']);
        $this->assertTrue($this->db->inTransaction());

        $this->db->rollbackTransaction();
        $this->assertFalse($this->db->inTransaction());

        $count = $this->db->preparedQuery(
            'SELECT COUNT(*) AS c FROM pq_items WHERE name = :n',
            ['n' => 'rollme']
        )->fetch();
        $this->assertSame(0, $count['c'], 'the rolled-back insert must not persist');
    }
}
