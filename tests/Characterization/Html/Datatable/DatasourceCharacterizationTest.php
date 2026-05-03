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

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

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
        // Current behavior: count subqueries fail and fallback to 0 via catch path.
        $this->assertSame(0, $result['iTotalRecords']);
        $this->assertSame(0, $result['iTotalDisplayRecords']);
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
        // Current behavior: count subqueries fail and fallback to 0 via catch path.
        $this->assertSame(0, $result['iTotalRecords']);
        $this->assertSame(0, $result['iTotalDisplayRecords']);
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
        // Current behavior: count subqueries fail and fallback to 0 via catch path.
        $this->assertSame(0, $result['iTotalRecords']);
        $this->assertSame(0, $result['iTotalDisplayRecords']);
        $this->assertCount(1, $result['aaData']);
        // This proves LIKE wildcards are not added when field config disables them.
        $this->assertSame('Alpha', $result['aaData'][0][0]);
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
            `customer_id` INT NOT NULL
        )');
    }

    private function seedData(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `dt_customers` (`name`) VALUES ('Alice'), ('Bob')");

        $this->db->query("INSERT INTO `dt_orders` (`title`, `amount`, `customer_id`) VALUES
            ('Alpha', 10.50, 1),
            ('AlphaX', 12.00, 1),
            ('Beta', 8.75, 2),
            ('Gamma', 20.00, 1)");
    }
}
