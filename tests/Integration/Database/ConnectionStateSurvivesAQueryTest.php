<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * The framework does not put a statement of its own between two of yours.
 *
 * `getConnection()` asked `isConnectionAlive()` on every call, and on MySQL that method
 * probes with **`SELECT 1`** — so every query the application ran became two queries on the
 * server. Reported with the MariaDB general log, from one page load:
 *
 * ```
 * total_queries=476   select1_pings=355
 * ```
 *
 * **75% of the statements were pings**, more than one per query because `getConnection()` is
 * called from outside `runQuery()` too.
 *
 * The round trip was the smaller half. `FOUND_ROWS()` is **per-connection state** — it
 * reports the row count of the *last* statement on that connection — so with a ping in
 * between, the last statement was always `SELECT 1`, one row. From that application's log,
 * in order, on one connection:
 *
 * ```
 * Query  select SQL_CALC_FOUND_ROWS *, c.* from … c …
 * Query  SELECT 1
 * Query  SELECT FOUND_ROWS() cnt
 * ```
 *
 * The same pair answers `0` in the mysql client and `1` through the framework. It surfaced
 * as a classified ad with **no** professionals in it reporting "All (1)" above an empty
 * list — a count that was not a count of anything, in a place a reader would never suspect
 * the database layer.
 *
 * These tests assert the contract rather than the mechanism: a second statement that reads
 * the connection's state must describe the caller's first statement. `ROW_COUNT()` and
 * `FOUND_ROWS()` are the two an application is most likely to reach for, and a statement
 * count pins the round trip itself.
 */
class ConnectionStateSurvivesAQueryTest extends DatabaseTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return ['connstate_probe'];
    }

    /** @return string[] */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE connstate_probe (
                id INTEGER NOT NULL,
                label VARCHAR(32) NOT NULL
            )',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('DELETE FROM connstate_probe');

        for ($i = 1; $i <= 7; $i++) {
            $this->db->query(
                "INSERT INTO connstate_probe (id, label) VALUES ({$i}, 'row-{$i}')"
            );
        }
    }

    /**
     * `FOUND_ROWS()` reports the caller's own query, not a ping.
     *
     * The reported symptom, at the level it was reported: a limited select and its total,
     * read back as two statements. Seven rows, a limit of two — so the honest answer is
     * seven, and the answer with a ping in between is one.
     *
     * `SQL_CALC_FOUND_ROWS` is deprecated in MySQL 8 and a separate `count(*)` is the
     * recommended replacement; it is used here because it is what the reporting application
     * had, and what the framework broke.
     */
    public function testFoundRowsDescribesTheCallersQuery(): void
    {
        // Act
        $rows = $this->db->query(
            'SELECT SQL_CALC_FOUND_ROWS id FROM connstate_probe ORDER BY id LIMIT 2'
        );
        $total = $this->db->query('SELECT FOUND_ROWS() AS cnt');

        // Assert
        $this->assertSame(2, (int) $rows->numRows, 'the fixture did not limit');
        $this->assertSame(
            7,
            (int) $total->fields['cnt'],
            'FOUND_ROWS() answered for a statement the caller did not run'
        );
    }

    /**
     * `ROW_COUNT()` describes the caller's own write.
     *
     * The same defect on the write side, and the one an application is more likely to read
     * without thinking about it: "how many rows did my UPDATE touch".
     */
    public function testRowCountDescribesTheCallersWrite(): void
    {
        // Act
        $this->db->query("UPDATE connstate_probe SET label = 'touched' WHERE id <= 3");
        $affected = $this->db->query('SELECT ROW_COUNT() AS n');

        // Assert
        $this->assertSame(
            3,
            (int) $affected->fields['n'],
            'ROW_COUNT() answered for a statement the caller did not run'
        );
    }

    /**
     * A user variable set by one statement is still there for the next.
     *
     * Not per-statement state like the two above — a ping does not clear it — but it is the
     * third thing the filing named, and asserting it says plainly that the connection is the
     * caller's and nothing else writes to it.
     */
    public function testAUserVariableSurvives(): void
    {
        // Act
        $this->db->query('SET @probe := 42');
        $read = $this->db->query('SELECT @probe AS v');

        // Assert
        $this->assertSame(42, (int) $read->fields['v']);
    }

    /**
     * One query is one statement on the server.
     *
     * The round trip, measured rather than inferred: `Questions` counts statements on this
     * session, so the delta across five queries is five. With a ping before each it was
     * ten — and the reporting installation's page ran 476 statements to do the work of 121.
     */
    public function testOneQueryIsOneStatement(): void
    {
        // Arrange
        $before = $this->questions();

        // Act
        for ($i = 0; $i < 5; $i++) {
            $this->db->query('SELECT id FROM connstate_probe WHERE id = ' . ($i + 1));
        }

        $after = $this->questions();

        // Assert — five queries, plus the two `Questions` reads that bracket them
        $this->assertSame(
            5,
            $after - $before - 1,
            'the framework sent statements of its own between the caller\'s'
        );
    }

    /**
     * `SHOW SESSION STATUS LIKE 'Questions'` — statements executed on this session.
     *
     * Read through the framework, so the reads themselves are counted the same way whatever
     * the connection layer does; the assertion above allows for exactly one of them falling
     * inside the measured window.
     */
    private function questions(): int
    {
        $result = $this->db->query("SHOW SESSION STATUS LIKE 'Questions'");

        return (int) $result->fields['Value'];
    }
}
