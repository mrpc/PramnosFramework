<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * A test case whose schema belongs to the class and whose rows belong to the test.
 *
 * Integration tests are usually written the obvious way — `setUp()` drops and creates the
 * tables, the test writes rows, `tearDown()` drops them again — and that shape is the single
 * largest remaining cost in this framework's suite. It is also unnecessary for most classes:
 * a test asserting what the query builder returns is not asserting anything about `CREATE
 * TABLE`.
 *
 * The split that fits a database:
 *
 * - **schema once per class**, in `setUpBeforeClass()`. MySQL DDL is not transactional, so
 *   it cannot be rolled back and there is no point paying for it per test;
 * - **rows emptied per test**, with `DELETE`.
 *
 * Measured on this project's MySQL container, converting `QueryBuilderMySQLTest`:
 * **16.8 s → 1.0 s** for its 92 tests, with nothing about the assertions changed.
 *
 * ### Why `DELETE` and not `TRUNCATE`
 *
 * | Two tables, per cycle | |
 * | --- | --- |
 * | `DROP` + `CREATE` | 128.6 ms |
 * | `TRUNCATE` | **159.5 ms** |
 * | `DELETE` + `ALTER … AUTO_INCREMENT = 1` | 18.7 ms |
 * | `DELETE` | **0.22 ms** |
 *
 * `TRUNCATE` looks like the fast path and is slower than recreating the table: it is an
 * implicit DDL statement. The auto-increment reset is 18 ms of the 18.7, so it is opt-in
 * here via {@see resetAutoIncrement()} rather than automatic.
 *
 * ### The trap this introduces, and it is worth knowing before you hit it
 *
 * Auto-increment **keeps counting up between tests**. A fixture that writes `product_id = 1`
 * because the products table restarted at 1 every time will silently point at nothing. That
 * is exactly what happened converting the first class: six join tests began returning zero
 * rows. The fix is better than the workaround — look the id up, which is what the literal
 * meant — but if a class genuinely needs the reset, override
 * {@see resetAutoIncrement()} to return `true`.
 *
 * ### Using it
 *
 * ```php
 * class WidgetsMySQLTest extends DatabaseTestCase
 * {
 *     protected static function connectionConfig(): array
 *     {
 *         return ['type' => 'mysql', 'server' => 'db', 'user' => 'root',
 *                 'password' => 'secret', 'database' => 'pramnos_test', 'port' => 3306];
 *     }
 *
 *     protected static function ownedTables(): array
 *     {
 *         return ['widget_parts', 'widgets'];   // child first: the order empties safely
 *     }
 *
 *     protected static function schemaStatements(): array
 *     {
 *         return ['CREATE TABLE widgets (...)', 'CREATE TABLE widget_parts (...)'];
 *     }
 * }
 * ```
 *
 * `$this->db` is a connected handle, per test. A subclass overriding `setUp()` or
 * `tearDown()` must call `parent::`.
 *
 * @see BaseTestCase for the case that boots the framework rather than owning a schema
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * Connection for the test that is running.
     *
     * @var Database
     */
    protected Database $db;

    /**
     * Where this class's tables live.
     *
     * Returned as an array rather than taken from application settings, because an
     * integration class states which engine it is testing — that is the point of having a
     * MySQL class and a PostgreSQL class asserting the same behaviour.
     *
     * Recognised keys: `type`, `server`, `user`, `password`, `database`, `port`, `schema`.
     *
     * @return array<string, mixed> Properties to set on the {@see Database} handle
     */
    abstract protected static function connectionConfig(): array;

    /**
     * The tables this class owns, in an order safe to empty and drop.
     *
     * List children before parents. Foreign key checks are disabled around both operations
     * anyway, so the order is belt-and-braces rather than load-bearing.
     *
     * @return string[] Table names, unquoted
     */
    abstract protected static function ownedTables(): array;

    /**
     * DDL that creates this class's tables, in dependency order.
     *
     * @return string[] Complete statements, executed in the order given
     */
    abstract protected static function schemaStatements(): array;

    /**
     * Whether to restart auto-increment counters before each test.
     *
     * Off by default: the reset costs about 9 ms per table against 0.11 ms for the `DELETE`
     * alone, and a test that depends on the first row being id 1 is usually a test that
     * should be looking the id up instead. Override to `true` when the sequence itself is
     * what a test asserts.
     *
     * @return bool True to reset the counters in `setUp()`
     */
    protected static function resetAutoIncrement(): bool
    {
        return false;
    }

    /**
     * Builds the schema once for the whole class.
     *
     * Drops first, because a previous class may have left a table of the same name, or
     * dropped one this schema's foreign keys point at.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $db = static::openConnection();

        static::dropOwnedTables($db);
        foreach (static::schemaStatements() as $statement) {
            $db->query($statement);
        }

        $db->close();
    }

    /**
     * Drops the class's tables so the next class starts from nothing.
     *
     * A table that outlives the class that created it is how a suite acquires order
     * dependence — and how the next class to use that name gets a confusing failure.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $db = static::openConnection();
        static::dropOwnedTables($db);
        $db->close();
    }

    /**
     * Opens a connection from {@see connectionConfig()}.
     *
     * @return Database A connected handle
     */
    protected static function openConnection(): Database
    {
        $db = new Database();
        foreach (static::connectionConfig() as $property => $value) {
            $db->$property = $value;
        }
        $db->connect(true);

        return $db;
    }

    /**
     * Drops every owned table, ignoring foreign keys between them.
     *
     * @param Database $db An open connection
     * @return void
     */
    protected static function dropOwnedTables(Database $db): void
    {
        static::withoutForeignKeyChecks($db, function () use ($db): void {
            foreach (static::ownedTables() as $table) {
                $db->query('DROP TABLE IF EXISTS ' . static::quote($db, $table));
            }
        });
    }

    /**
     * Runs a callback with foreign key enforcement suspended.
     *
     * The statement differs per engine, and PostgreSQL has no session-wide equivalent worth
     * using here — `ownedTables()` is ordered, and `DELETE` in that order satisfies the
     * constraints without disabling anything.
     *
     * @param Database $db An open connection
     * @param callable $work What to run
     * @return void
     */
    protected static function withoutForeignKeyChecks(Database $db, callable $work): void
    {
        $isMysql = ($db->type ?? '') === 'mysql';

        if ($isMysql) {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            $work();
        } finally {
            if ($isMysql) {
                $db->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * Quotes a table name for the engine in use.
     *
     * @param Database $db    An open connection, for its type
     * @param string   $table Table name
     * @return string The name, quoted
     */
    protected static function quote(Database $db, string $table): string
    {
        return ($db->type ?? '') === 'mysql' ? '`' . $table . '`' : '"' . $table . '"';
    }

    /**
     * Connects and empties the tables.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = static::openConnection();
        $this->emptyOwnedTables();
    }

    /**
     * Closes the test's connection.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->db->close();
    }

    /**
     * Removes every row from the owned tables, leaving the schema alone.
     *
     * @return void
     */
    protected function emptyOwnedTables(): void
    {
        static::withoutForeignKeyChecks($this->db, function (): void {
            foreach (static::ownedTables() as $table) {
                $quoted = static::quote($this->db, $table);
                $this->db->query('DELETE FROM ' . $quoted);

                if (static::resetAutoIncrement()) {
                    $this->resetSequence($table, $quoted);
                }
            }
        });
    }

    /**
     * Restarts a table's generated-key counter.
     *
     * Engine-specific and deliberately narrow: MySQL takes an `ALTER TABLE`, PostgreSQL
     * needs the sequence behind the column, and the conventional name is the only one worth
     * guessing. A failure here is swallowed, because a table with no generated key is not
     * an error — it simply has no counter to reset.
     *
     * @param string $table  Unquoted table name
     * @param string $quoted The same name, quoted for the engine
     * @return void
     */
    protected function resetSequence(string $table, string $quoted): void
    {
        try {
            if (($this->db->type ?? '') === 'mysql') {
                $this->db->query('ALTER TABLE ' . $quoted . ' AUTO_INCREMENT = 1');

                return;
            }

            $this->db->query(
                'ALTER SEQUENCE IF EXISTS ' . $table . '_id_seq RESTART WITH 1'
            );
        } catch (\Throwable) {
            // @codeCoverageIgnoreStart
            // No generated key on this table, or none named by the convention. Not an
            // error: the DELETE above has already done the part that matters.
            //
            // Unreachable through either supported engine, which is why it is excluded
            // rather than tested: MySQL accepts `ALTER TABLE … AUTO_INCREMENT = 1` on a
            // table with no such column, and `ALTER SEQUENCE IF EXISTS` is a no-op when
            // the sequence is absent. It stays because a third engine, or a driver that
            // raises where these do not, must not take a whole class down over a counter
            // nobody asked about.
            // @codeCoverageIgnoreEnd
        }
    }
}
