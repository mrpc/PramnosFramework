<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Html\Datatable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Datasource;

/**
 * Characterization tests for Datasource::render() observable behavior.
 *
 * These tests lock key DataTables contracts (paging metadata, filtering,
 * join support, and wildcard behavior) before further internal rewrites.
 */
#[CoversClass(Datasource::class)]
class DatasourceCharacterizationTest extends TestCase
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

        Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        // Reset the database singleton to ensure it picks up the clean settings
        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->resetRequestState();
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `dt_orders`');
        $this->db->query('DROP TABLE IF EXISTS `dt_customers`');
        $this->resetRequestState();

        // Reset singleton to prevent leak to other tests
        $singleton = &Factory::getDatabase();
        $singleton = null;

        Settings::clearSettings();
    }

    /**
     * Ensures render() returns DataTables paging metadata and limited rows.
     *
     * This behavior is consumed directly by legacy DataTables front-end code.
     */
    public function testRenderReturnsPagedRowsAndMetadata(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '2',
            'sEcho' => '7',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders',
            ['id', 'title', 'amount'],
            false,
            '',
            '',
            false
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertSame(7, $result['sEcho']);
        // COUNT(*) subquery returns the real totals now that the fix is in place.
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(4, $result['iTotalDisplayRecords']);
        $this->assertCount(2, $result['aaData']);
        // This proves DT_RowId is mapped from the first selected column.
        $this->assertSame($result['aaData'][0][0], $result['aaData'][0]['DT_RowId']);
    }

    /**
     * Ensures global search applies across searchable fields and works with joins.
     */
    public function testRenderAppliesGlobalSearchWithJoin(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '10',
            'sSearch' => 'Bob',
            'bSearchable_0' => 'true',
            'bSearchable_1' => 'true',
            'bSearchable_2' => 'true',
            'sEcho' => '3',
        ]);

        $datasource = new Datasource();
        $join = 'LEFT JOIN `dt_customers` b ON b.id = a.customer_id';

        // Act
        $result = $datasource->render(
            'dt_orders',
            ['id', 'a.title', 'b.name as customer_name'],
            false,
            '',
            $join,
            false
        );

        // Assert
        // SELECT 1 in count subquery avoids duplicate-column errors from the JOIN.
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(1, $result['iTotalDisplayRecords']);
        $this->assertCount(1, $result['aaData']);
        $this->assertStringContainsString('Bob', (string) $result['aaData'][0][2]);
    }

    /**
     * Ensures per-column filtering honors configured wildcard flags.
     *
     * With start/end wildcards disabled, filtering behaves as exact match.
     */
    public function testRenderRespectsColumnWildcardConfiguration(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '10',
            'sSearch_0' => 'Alpha',
            'bSearchable_0' => 'true',
            'sEcho' => '5',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders',
            [
                ['title', 'text', '', false, false],
                'id',
            ],
            false,
            '',
            '',
            false
        );

        // Assert
        // COUNT(*) subquery returns real totals; iTotalDisplayRecords reflects the filtered set.
        $this->assertSame(4, $result['iTotalRecords']);
        $this->assertSame(1, $result['iTotalDisplayRecords']);
        $this->assertCount(1, $result['aaData']);
        // This proves LIKE wildcards are not added when field config disables them.
        $this->assertSame('Alpha', $result['aaData'][0][0]);
    }

    /**
     * Ensures multi-column ordering inputs from DataTables request are applied.
     */
    public function testRenderAppliesRequestOrderingContract(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '10',
            'iSortCol_0' => '2',
            'sSortDir_0' => 'desc',
            'iSortingCols' => '1',
            'sEcho' => '9',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders',
            ['id', 'title', 'amount'],
            false,
            '',
            '',
            false
        );

        // Assert
        $this->assertCount(4, $result['aaData']);
        // This proves ordering by column index 2 (amount) desc is honored.
        $this->assertSame('Gamma', $result['aaData'][0][1]);
    }

    /**
     * Ensures distinctField mode returns unique values for that field.
     */
    public function testRenderDistinctFieldReturnsUniqueRows(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `dt_orders` (`title`, `amount`, `customer_id`, `created_ts`) VALUES ('Beta', 9.25, 2, 1714680300)");
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '20',
            'sEcho' => '10',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders',
            ['title'],
            false,
            '',
            '',
            false,
            5,
            'datatables',
            false,
            null,
            'title'
        );

        // Assert
        $titles = array_map(static function (array $row): string {
            return (string) $row[0];
        }, $result['aaData']);

        $this->assertCount(4, array_unique($titles));
        $this->assertCount(4, $titles);
    }

    /**
     * Ensures date field formatting uses configured formatdetails.
     */
    public function testRenderFormatsDateFieldsFromUnixTimestamp(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart' => '0',
            'iDisplayLength' => '1',
            'sEcho' => '11',
        ]);

        $datasource = new Datasource();

        // Act
        $result = $datasource->render(
            'dt_orders',
            [
                ['created_ts', 'date', 'Y-m-d', true, true],
                'title',
            ],
            false,
            '',
            '',
            false
        );

        // Assert
        $this->assertCount(1, $result['aaData']);
        $this->assertSame(date('Y-m-d', 1714680000), $result['aaData'][0][0]);
    }

    private function resetRequestState(): void
    {
        // Arrange
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $_FILES = [];
        $_COOKIE = [];
    }

    /**
     * @param array<string, string> $post
     */
    private function setPost(array $post): void
    {
        // Arrange
        $_POST = $post;
        $_REQUEST = $post;
    }

    private function createSchema(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `dt_orders`');
        $this->db->query('DROP TABLE IF EXISTS `dt_customers`');

        $this->db->query('CREATE TABLE `dt_customers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL
        )');

        $this->db->query('CREATE TABLE `dt_orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `customer_id` INT NOT NULL,
            `created_ts` INT NOT NULL
        )');
    }

    private function seedData(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `dt_customers` (`name`) VALUES ('Alice'), ('Bob')");

        $this->db->query("INSERT INTO `dt_orders` (`title`, `amount`, `customer_id`, `created_ts`) VALUES
            ('Alpha', 10.50, 1, 1714680000),
            ('AlphaX', 12.00, 1, 1714680100),
            ('Beta', 8.75, 2, 1714680200),
            ('Gamma', 20.00, 1, 1714680300)");
    }
}
