<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Html\Datatable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Datasource;

/**
 * Characterization tests for Datasource::render() against PostgreSQL.
 *
 * Mirrors DatasourceCharacterizationTest but exercises the PostgreSQL path
 * (timescaledb:5432). Because Factory::getDatabase() is a singleton, each test
 * method runs in a separate process so that pg_settings.php takes effect before
 * any MySQL singleton is created by a sibling test.
 *
 * TimescaleDB coverage: the "timescaledb" Docker container is PostgreSQL 14 with
 * the TimescaleDB extension. Running against it satisfies both PostgreSQL and
 * TimescaleDB requirements for Datasource — which has no TimescaleDB-specific paths.
 *
 * Key PostgreSQL differences from MySQL:
 *  - DDL uses SERIAL (no AUTO_INCREMENT), no ENGINE/CHARSET clause.
 *  - Raw JOIN strings passed to joinRaw() must use double-quote identifier quoting.
 *  - The Datasource QueryBuilder uses PostgreSQLGrammar automatically once the
 *    database connection type is detected.
 */
#[CoversClass(Datasource::class)]
#[RunTestsInSeparateProcesses]
class DatasourcePostgreSQLCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    public static function setUpBeforeClass(): void
    {
        // Arrange
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }
    }

    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $pgSettingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->resetRequestState();
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        // Cleanup — drop orders before customers to respect any implicit ordering
        $this->db->query('DROP TABLE IF EXISTS "dt_orders_pg"');
        $this->db->query('DROP TABLE IF EXISTS "dt_customers_pg"');
        $this->resetRequestState();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * render() returns DataTables paging metadata and limited rows on PostgreSQL.
     *
     * sEcho pass-through, iTotalRecords/iTotalDisplayRecords via COUNT(*) subquery,
     * iDisplayLength paging, and DT_RowId mapping must behave identically to MySQL.
     */
    public function testRenderReturnsPagedRowsAndMetadata(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '2',
            'sEcho'          => '7',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            ['id', 'title', 'amount'],
            false, '', '', false
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertSame(7, $result['sEcho']);
        // COUNT(*) subquery returns real totals on PostgreSQL too.
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(4, $result['iTotalDisplayRecords']);
        $this->assertCount(2, $result['aaData']);
        // DT_RowId is mapped from the first selected column regardless of dialect.
        $this->assertSame($result['aaData'][0][0], $result['aaData'][0]['DT_RowId']);
    }

    /**
     * Global search applies across searchable fields and works with JOINs on PostgreSQL.
     *
     * The raw JOIN string uses PostgreSQL double-quote identifier quoting since it is
     * passed via joinRaw() without further processing.
     */
    public function testRenderAppliesGlobalSearchWithJoin(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch'        => 'Bob',
            'bSearchable_0'  => 'true',
            'bSearchable_1'  => 'true',
            'bSearchable_2'  => 'true',
            'sEcho'          => '3',
        ]);

        $datasource = new Datasource();
        // joinRaw() receives the string verbatim — must use PG double-quote quoting.
        $join = 'LEFT JOIN "dt_customers_pg" b ON b.id = a.customer_id';

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            ['id', 'a.title', 'b.name as customer_name'],
            false, '', $join, false
        );

        // Assert
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(1, $result['iTotalDisplayRecords']);
        $this->assertCount(1, $result['aaData']);
        $this->assertStringContainsString('Bob', (string) $result['aaData'][0][2]);
    }

    /**
     * Per-column filtering honors configured wildcard flags on PostgreSQL.
     *
     * LIKE without leading/trailing wildcards must behave as an exact-match
     * prefix search — dialect-agnostic behavior locked here.
     */
    public function testRenderRespectsColumnWildcardConfiguration(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch_0'      => 'Alpha',
            'bSearchable_0'  => 'true',
            'sEcho'          => '5',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            [
                ['title', 'text', '', false, false],
                'id',
            ],
            false, '', '', false
        );

        // Assert
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(1, $result['iTotalDisplayRecords']);
        $this->assertCount(1, $result['aaData']);
        // No wildcards added — returns only the exact-match row.
        $this->assertSame('Alpha', $result['aaData'][0][0]);
    }

    /**
     * Multi-column ordering from DataTables request parameters is applied on PostgreSQL.
     *
     * ORDER BY amount DESC → Gamma (20.00) first. Same result as MySQL.
     */
    public function testRenderAppliesRequestOrderingContract(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'iSortCol_0'     => '2',
            'sSortDir_0'     => 'desc',
            'iSortingCols'   => '1',
            'sEcho'          => '9',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            ['id', 'title', 'amount'],
            false, '', '', false
        );

        // Assert
        $this->assertCount(4, $result['aaData']);
        // Column index 2 (amount) desc: Gamma (20.00) must be first on PG as on MySQL.
        $this->assertSame('Gamma', $result['aaData'][0][1]);
    }

    /**
     * distinctField mode returns unique values for that field on PostgreSQL.
     *
     * DISTINCT logic is handled at the PHP/QB level, not MySQL-specific.
     */
    public function testRenderDistinctFieldReturnsUniqueRows(): void
    {
        // Arrange — insert a duplicate title to verify deduplication
        $this->db->query($this->db->prepareQuery(
            'INSERT INTO "dt_orders_pg" ("title", "amount", "customer_id", "created_ts") VALUES (%s, %s, %d, %d)',
            'Beta', '9.25', 2, 1714680300
        ));
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '20',
            'sEcho'          => '10',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            ['title'],
            false, '', '', false, 5, 'datatables', false, null, 'title'
        );

        // Assert
        $titles = array_map(static function (array $row): string {
            return (string) $row[0];
        }, $result['aaData']);

        // 5 rows inserted (4 seed + 1 duplicate), but only 4 distinct titles.
        $this->assertCount(4, array_unique($titles));
        $this->assertCount(4, $titles);
    }

    /**
     * Date field formatting from Unix timestamp works on PostgreSQL.
     *
     * Date formatting is handled in PHP after the query — the integer timestamp
     * column value is the same on both MySQL and PostgreSQL.
     */
    public function testRenderFormatsDateFieldsFromUnixTimestamp(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '1',
            'sEcho'          => '11',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders_pg',
            [
                ['created_ts', 'date', 'Y-m-d', true, true],
                'title',
            ],
            false, '', '', false
        );

        // Assert
        $this->assertCount(1, $result['aaData']);
        // PHP date() formatting of the stored Unix timestamp — dialect-agnostic.
        $this->assertSame(date('Y-m-d', 1714680000), $result['aaData'][0][0]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resetRequestState(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
        $_FILES   = [];
        $_COOKIE  = [];
    }

    /** @param array<string, string> $post */
    private function setPost(array $post): void
    {
        $_POST    = $post;
        $_REQUEST = $post;
    }

    private function createSchema(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "dt_orders_pg"');
        $this->db->query('DROP TABLE IF EXISTS "dt_customers_pg"');

        $this->db->query('CREATE TABLE "dt_customers_pg" (
            "id"   SERIAL PRIMARY KEY,
            "name" VARCHAR(100) NOT NULL
        )');

        $this->db->query('CREATE TABLE "dt_orders_pg" (
            "id"          SERIAL PRIMARY KEY,
            "title"       VARCHAR(100) NOT NULL,
            "amount"      DECIMAL(10,2) NOT NULL,
            "customer_id" INTEGER NOT NULL,
            "created_ts"  INTEGER NOT NULL
        )');
    }

    private function seedData(): void
    {
        $this->db->query("INSERT INTO \"dt_customers_pg\" (\"name\") VALUES ('Alice'), ('Bob')");

        $this->db->query("INSERT INTO \"dt_orders_pg\" (\"title\", \"amount\", \"customer_id\", \"created_ts\") VALUES
            ('Alpha',  10.50, 1, 1714680000),
            ('AlphaX', 12.00, 1, 1714680100),
            ('Beta',    8.75, 2, 1714680200),
            ('Gamma',  20.00, 1, 1714680300)");
    }
}
