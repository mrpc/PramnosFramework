<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\User\Token;

/**
 * Integration tests for Token::updateAction() against a real MySQL 8.0 database.
 *
 * These tests verify that updateAction() correctly writes return_status,
 * execution_time_ms, and return_data to the tokenactions table — the behavior
 * that was previously skipped for MySQL with an early `return`.
 *
 * NOTE: This file is named TokenActionMySQLTest (M < P) so it runs BEFORE
 * TokenActionPostgreSQLTest, preventing the PostgreSQL singleton replacement
 * from corrupting the MySQL connection for these tests.
 *
 * setUp creates minimal urls + tokenactions tables via the framework migrations
 * and seeds one row each so that updateAction() has a real row to update.
 * tearDown drops both tables to leave the database clean for the next test.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class TokenActionMySQLTest extends TestCase
{
    protected Database $db;

    /** @var int Seeded tokenactions.actionid for update tests */
    protected int $actionId;

    /** @var int Seeded usertokens.tokenid */
    protected int $tokenId;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        // Ensure the singleton points to the MySQL DB configured in settings.php.
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->dropTestTables();
        $this->runMigrations();
        $this->seedRows();
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();
    }

    // -------------------------------------------------------------------------
    // updateAction — MySQL
    // -------------------------------------------------------------------------

    /**
     * updateAction() must write return_status, execution_time_ms, and
     * return_data to the tokenactions row identified by actionid.
     *
     * Previously updateAction() returned immediately on MySQL (early `return`),
     * leaving the row permanently in the default NULL state. This test proves
     * that the fix allows MySQL to record response metrics — essential for the
     * slow_api_calls view and performance dashboards.
     */
    public function testUpdateActionWritesMetricsToMysql(): void
    {
        // Arrange — Token object pointing to the seeded token
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act
        $token->updateAction($this->actionId, 200, 42.7, ['rows' => 5]);

        // Assert — verify DB row directly
        $row = $this->db->query(
            "SELECT return_status, execution_time_ms, return_data"
            . " FROM `#PREFIX#tokenactions`"
            . " WHERE actionid = {$this->actionId}"
        );
        $this->assertSame(200, (int)$row->fields['return_status'],
            'return_status must be written to the tokenactions row');
        $this->assertEqualsWithDelta(42.7, (float)$row->fields['execution_time_ms'], 0.01,
            'execution_time_ms must be written to the tokenactions row');

        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertSame(['rows' => 5], $decoded,
            'return_data must be stored as JSON and decode to the original array');
    }

    /**
     * updateAction() with $return_data = null must store an empty JSON object ({})
     * rather than NULL, preserving a defined, parseable value in the column.
     */
    public function testUpdateActionWithNullReturnDataStoresEmptyJson(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act — no return_data passed
        $token->updateAction($this->actionId, 404, 8.1);

        // Assert
        $row = $this->db->query(
            "SELECT return_status, return_data FROM `#PREFIX#tokenactions`"
            . " WHERE actionid = {$this->actionId}"
        );
        $this->assertSame(404, (int)$row->fields['return_status']);
        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertSame([], $decoded,
            'null return_data must be stored as empty JSON object');
    }

    /**
     * updateAction() with actionid=0 must silently return without touching the DB.
     *
     * This guard exists because addAction() stores the insert ID in lastActionId,
     * which is null if the URL was not found (urlid=0). Callers may pass 0 when
     * no action row was created.
     */
    public function testUpdateActionWithZeroActionIdIsNoOp(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act — zero actionid should short-circuit
        $token->updateAction(0, 200, 5.0);

        // Assert — the seeded row must remain unchanged (return_status still NULL)
        $row = $this->db->query(
            "SELECT return_status FROM `#PREFIX#tokenactions`"
            . " WHERE actionid = {$this->actionId}"
        );
        $this->assertNull($row->fields['return_status'],
            'a zero actionid must not modify any tokenactions row');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function runMigrations(): void
    {
        $authDir = dirname(__DIR__, 3) . '/database/migrations/framework/auth';

        // Run without FK checks so usertokens can be created before users table
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->loadMigrationClass($authDir . '/2020_01_01_000014_create_usertokens_table.php');
        $this->loadMigrationClass($authDir . '/2020_01_01_000015_create_urls_table.php');
        $this->loadMigrationClass($authDir . '/2020_01_01_000016_create_tokenactions_table.php');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function loadMigrationClass(string $file): void
    {
        require_once $file;
        $class = $this->classFromFile($file);
        if (class_exists($class)) {
            $app = $this->makeApp();
            (new $class($app))->up();
        }
    }

    protected function classFromFile(string $file): string
    {
        $base = basename($file, '.php');
        // e.g. 2020_01_01_000016_create_tokenactions_table → CreateTokenactionsTable
        $parts = array_slice(explode('_', $base), 4);
        return 'Pramnos\\Framework\\Migrations\\Auth\\' . implode('', array_map('ucfirst', $parts));
    }

    protected function seedRows(): void
    {
        // Disable FK checks so we can insert usertokens without a parent users row.
        // This keeps the test focused on the tokenactions update path without requiring
        // the full users schema to be populated with every mandatory column.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // usertokens row — tokenid is AUTO_INCREMENT; save the generated id.
        // deviceinfo and scope are TEXT NOT NULL with no default — provide empty strings.
        $this->db->query(
            "INSERT INTO `#PREFIX#usertokens`"
            . " (userid, tokentype, token, created, status, deviceinfo, scope)"
            . " VALUES (9999, 'api', 'test_token_abc', " . time() . ", 1, '', '')"
        );
        $this->tokenId = (int) $this->db->getInsertId();

        // urls row
        $this->db->query(
            "INSERT INTO `#PREFIX#urls` (url, hash) VALUES ('/api/test', " . crc32('/api/test') . ")"
        );
        $urlId = (int) $this->db->getInsertId();

        // tokenactions row — leave return_status / execution_time_ms / return_data NULL
        $this->db->query(
            "INSERT INTO `#PREFIX#tokenactions`"
            . " (tokenid, urlid, method, params, servertime)"
            . " VALUES ({$this->tokenId}, {$urlId}, 'GET', '{}', " . time() . ")"
        );
        $this->actionId = (int) $this->db->getInsertId();
    }

    protected function dropTestTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `#PREFIX#tokenactions`');
        $this->db->query('DROP TABLE IF EXISTS `#PREFIX#urls`');
        $this->db->query('DROP TABLE IF EXISTS `#PREFIX#usertokens`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }
}
