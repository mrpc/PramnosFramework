<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * A raw fragment with bindings, against a real database.
 *
 * The unit tests assert the compiled SQL. This asserts the thing that actually
 * failed: the statement **executes and returns the right rows**. A `?` left in the
 * SQL is not a compilation detail — it is a statement the server rejects, and the
 * bug report that started this was about `first()` returning `false` for a query
 * that should have matched one row.
 *
 * Runs against whichever engine `DB_TYPE` selects; the suite covers MySQL,
 * PostgreSQL and TimescaleDB in turn, which matters here because the placeholders
 * being fixed are the ones each driver quotes differently.
 */
class QueryBuilderRawBindingIntegrationTest extends TestCase
{
    private Database $db;

    /** Unprefixed name; the builder resolves the prefix per driver. */
    private const TBL = 'qb_raw_binding_test';

    private bool $created = false;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $isPg   = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db           = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        $table = $this->db->prefix . self::TBL;
        $this->db->query('DROP TABLE IF EXISTS ' . $table);
        $this->db->query(
            'CREATE TABLE ' . $table . ' ('
            . ' id INT NOT NULL,'
            . ' station_id INT NOT NULL,'
            . ' label VARCHAR(64) NOT NULL,'
            . ' score DOUBLE PRECISION NULL'
            . ')'
        );
        $this->created = true;

        // Three rows across two stations, so a filter that binds the wrong value
        // returns the wrong count rather than nothing — a test that can only
        // distinguish "some rows" from "no rows" would pass on a broken binding
        // that happened to match everything.
        foreach ([[1, 10, 'alpha', 1.5], [2, 10, 'beta', 2.5], [3, 20, 'gamma', 3.5]] as $row) {
            $this->db->query(sprintf(
                'INSERT INTO %s (id, station_id, label, score) VALUES (%d, %d, %s, %s)',
                $table,
                $row[0],
                $row[1],
                "'" . $row[2] . "'",
                (string) $row[3]
            ));
        }
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefix . self::TBL);
        }
    }

    /**
     * The reported shape: two `where()` calls and a raw fragment with a `?`.
     *
     * Before the fix the compiled statement carried a literal `?` and one binding
     * too many, so the server rejected it, `get()` returned false, and the caller
     * saw a property read on false. Here it has to come back with exactly the two
     * rows that match.
     */
    public function testARawFragmentWithBindingsExecutesAndMatchesTheRightRows(): void
    {
        // Arrange
        $table = self::TBL;

        // Act
        $rows = $this->db->queryBuilder()
            ->from($table)
            ->where('id', '>=', 1)
            ->where('id', '<=', 3)
            ->whereRaw('station_id = ?', [10])
            ->orderBy('id')
            ->getAll();

        // Assert — the two station-10 rows, and not the station-20 one
        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['label']);
        $this->assertSame('beta', $rows[1]['label']);
    }

    /**
     * A raw fragment between two bound `where()` calls binds in the right order.
     *
     * The ordering half of the bug. If the raw value were appended after the
     * later `where()`'s value instead of taking its own position, this query would
     * filter on the wrong column pair and return the wrong row.
     */
    public function testBindingsKeepTheirOrderAroundARawFragment(): void
    {
        // Arrange & Act
        $rows = $this->db->queryBuilder()
            ->from(self::TBL)
            ->where('label', 'beta')
            ->whereRaw('station_id = ?', [10])
            ->where('id', 2)
            ->getAll();

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame('beta', $rows[0]['label']);
    }

    /**
     * Mixed types in one fragment reach the database as those types.
     *
     * `%i` and `%s` are quoted and cast differently by every driver, which is why
     * the placeholder follows the binding's type rather than being `%s` for
     * everything: a string bound through `%i` arrives as 0.
     */
    public function testMixedTypesInOneFragmentAreBoundCorrectly(): void
    {
        // Arrange & Act
        $rows = $this->db->queryBuilder()
            ->from(self::TBL)
            ->whereRaw('station_id = ? AND label = ? AND score > ?', [20, 'gamma', 3.0])
            ->getAll();

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame('gamma', $rows[0]['label']);
    }

    /**
     * A raw HAVING with a binding executes too.
     *
     * Its bindings are a separate bucket merged after the WHERE's, so a `?` here
     * was wrong in the same way and in a place that is harder to notice: an
     * aggregate that quietly returns nothing looks like data that is not there.
     */
    public function testARawHavingWithABindingExecutes(): void
    {
        // Arrange & Act
        $rows = $this->db->queryBuilder()
            ->from(self::TBL)
            ->select(['station_id', 'COUNT(*) AS total'])
            ->where('id', '>=', 1)
            ->groupBy('station_id')
            ->havingRaw('COUNT(*) > ?', [1])
            ->getAll();

        // Assert — only station 10 has more than one row
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['station_id']);
        $this->assertSame(2, (int) $rows[0]['total']);
    }

    /**
     * A raw subquery with a binding — the exact query from the report.
     *
     * It is worth having as it was written, because the fragment is long enough
     * that its `?` sits well away from the values bound before it.
     */
    public function testARawSubqueryWithABindingExecutes(): void
    {
        // Arrange
        $table = $this->db->prefix . self::TBL;

        // Act
        $rows = $this->db->queryBuilder()
            ->from(self::TBL)
            ->where('id', '>=', 1)
            ->whereRaw(
                'station_id IN (SELECT station_id FROM ' . $table . ' WHERE label = ?)',
                ['gamma']
            )
            ->getAll();

        // Assert — the station gamma belongs to has exactly one row
        $this->assertCount(1, $rows);
        $this->assertSame('gamma', $rows[0]['label']);
    }

    /**
     * `orWhereRaw()` executes, which is the point of adding it.
     *
     * It did not exist before — `orWhere`, `orWhereIn` and `orWhereNull` all did —
     * so the only route was passing `'or'` as `whereRaw()`'s third argument, which
     * reads as an internal detail because it is one.
     */
    public function testOrWhereRawExecutes(): void
    {
        // Arrange & Act
        $rows = $this->db->queryBuilder()
            ->from(self::TBL)
            ->where('label', 'alpha')
            ->orWhereRaw('station_id = ?', [20])
            ->orderBy('id')
            ->getAll();

        // Assert — alpha, plus the station-20 row
        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['label']);
        $this->assertSame('gamma', $rows[1]['label']);
    }
}
