<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\User\Token;

/**
 * Integration tests for Token::updateAction() against a live PostgreSQL / TimescaleDB database.
 *
 * Mirrors the MySQL test class and adds a PostgreSQL-specific test for the
 * sync trigger (sync_tokenactions_time) that keeps servertime ↔ action_time
 * consistent when only one of the two columns is provided on INSERT.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class TokenActionPostgreSQLTest extends TestCase
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

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        $pgDb = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped(
                'TimescaleDB container not reachable ('
                . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432)
                . '): ' . $e->getMessage()
            );
        }

        // Replace the Database::getInstance() singleton so Factory::getDatabase()
        // (used internally by Token::updateAction()) reaches the PostgreSQL DB.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db = $pgDb;

        $this->dropTestTables();
        $this->runMigrations();
        $this->seedRows();
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();

        // Restore the Database singleton to MySQL so subsequent tests in the full
        // suite don't inherit the PostgreSQL connection. Re-load settings and let
        // Factory::getDatabase() re-initialize from the MySQL config.
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
    }

    // -------------------------------------------------------------------------
    // updateAction on PostgreSQL — mirrors MySQL tests
    // -------------------------------------------------------------------------

    /**
     * updateAction() on PostgreSQL must write return_status, execution_time_ms,
     * and return_data (stored as JSONB) to the tokenactions row.
     */
    public function testUpdateActionWritesMetrics(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act
        $token->updateAction($this->actionId, 201, 33.2, ['created' => true]);

        // Assert — use PostgreSQL double-quote identifiers
        $row = $this->db->query(
            "SELECT return_status, execution_time_ms, return_data"
            . ' FROM "tokenactions"'
            . " WHERE actionid = {$this->actionId}"
        );
        $this->assertSame(201, (int)$row->fields['return_status'],
            'return_status must be written to the tokenactions row on PostgreSQL');
        $this->assertEqualsWithDelta(33.2, (float)$row->fields['execution_time_ms'], 0.01,
            'execution_time_ms must be written on PostgreSQL');

        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertSame(['created' => true], $decoded,
            'return_data must be stored as JSONB and decode to the original array');
    }

    /**
     * updateAction() with null return_data stores {} on PostgreSQL.
     */
    public function testUpdateActionWithNullReturnDataStoresEmptyJson(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act
        $token->updateAction($this->actionId, 500);

        // Assert
        $row = $this->db->query(
            "SELECT return_data FROM \"tokenactions\" WHERE actionid = {$this->actionId}"
        );
        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertSame([], $decoded, 'null return_data must be stored as empty JSON object');
    }

    /**
     * updateAction() with actionid=0 must not touch any row.
     */
    public function testUpdateActionWithZeroActionIdIsNoOp(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = $this->tokenId;

        // Act
        $token->updateAction(0, 200, 5.0);

        // Assert — the seeded row must remain unchanged (return_status still NULL)
        $row = $this->db->query(
            "SELECT return_status FROM \"tokenactions\" WHERE actionid = {$this->actionId}"
        );
        $this->assertNull($row->fields['return_status'],
            'zero actionid must not update any tokenactions row');
    }

    // -------------------------------------------------------------------------
    // Sync trigger (PostgreSQL-specific)
    // -------------------------------------------------------------------------

    /**
     * The sync trigger must populate action_time from servertime when only
     * servertime is provided on INSERT, keeping the two columns consistent.
     *
     * This is the core TimescaleDB invariant: action_time is the partition key
     * and must always reflect the real event timestamp even when legacy code
     * writes the UNIX integer servertime instead.
     *
     * We use a "recent" timestamp (current time minus a few seconds) rather than
     * a historical one to ensure the INSERT lands in the same TimescaleDB chunk
     * as the seeded rows (avoiding chunk boundary check constraint issues).
     */
    public function testSyncTriggerPopulatesActionTimeFromServertime(): void
    {
        // Arrange — a unix timestamp a few seconds in the past (within current chunk)
        $knownTime = time() - 30;
        $urlId     = (int) $this->db->query('SELECT urlid FROM "urls" LIMIT 1')->fields['urlid'];

        // Act — insert with servertime only; action_time is populated by the trigger
        $this->db->query(
            'INSERT INTO "tokenactions" (tokenid, urlid, method, params, servertime)'
            . " VALUES ({$this->tokenId}, {$urlId}, 'POST', '{}', {$knownTime})"
        );
        $newId = (int) $this->db->getInsertId();

        // Assert — action_time must match the epoch represented by servertime
        $row = $this->db->query(
            "SELECT servertime, action_time FROM \"tokenactions\" WHERE actionid = {$newId}"
        );
        $this->assertSame($knownTime, (int)$row->fields['servertime']);
        $this->assertNotNull($row->fields['action_time'],
            'action_time must be populated by the sync trigger');

        // Convert action_time back to unix and compare (±2 seconds tolerance for TZ rounding)
        $actionTs = strtotime($row->fields['action_time']);
        $this->assertEqualsWithDelta($knownTime, $actionTs, 2,
            'action_time must correspond to the servertime unix epoch via sync trigger');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function runMigrations(): void
    {
        $authDir = dirname(__DIR__, 3) . '/database/migrations/framework/auth';

        // Create a minimal users table to satisfy the usertokens FK constraint.
        // We don't run the full users migration (it creates many columns); this
        // stub is enough to let usertokens' FOREIGN KEY REFERENCES users(userid) pass.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS "users" ("userid" BIGSERIAL PRIMARY KEY)'
        );

        $this->loadMigrationClass($authDir . '/2020_01_01_000014_create_usertokens_table.php');
        $this->loadMigrationClass($authDir . '/2020_01_01_000015_create_urls_table.php');
        $this->loadMigrationClass($authDir . '/2020_01_01_000016_create_tokenactions_table.php');
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
        $base  = basename($file, '.php');
        $parts = array_slice(explode('_', $base), 4);
        return 'Pramnos\\Framework\\Migrations\\Auth\\' . implode('', array_map('ucfirst', $parts));
    }

    protected function seedRows(): void
    {
        // Minimal users row to satisfy the usertokens FK constraint
        $this->db->query('INSERT INTO "users" (userid) VALUES (9999) ON CONFLICT DO NOTHING');

        // usertokens row
        $this->db->query(
            'INSERT INTO "usertokens" (userid, tokentype, token, created, status, deviceinfo, scope)'
            . " VALUES (9999, 'api', 'test_token_pg', " . time() . ", 1, '', '')"
        );
        $this->tokenId = (int) $this->db->getInsertId();

        // urls row
        $urlHash = crc32('/api/test');
        $this->db->query(
            "INSERT INTO \"urls\" (url, hash) VALUES ('/api/test', {$urlHash})"
        );
        $urlId = (int) $this->db->getInsertId();

        // tokenactions row — leave return_status / execution_time_ms / return_data NULL.
        // Use RETURNING actionid to reliably get the new PK regardless of getInsertId() behavior.
        $insertedTime = time();
        $result = $this->db->query(
            'INSERT INTO "tokenactions" (tokenid, urlid, method, params, servertime)'
            . " VALUES ({$this->tokenId}, {$urlId}, 'GET', '{}', {$insertedTime})"
            . ' RETURNING actionid'
        );
        $this->actionId = (int) $result->fields['actionid'];
    }

    protected function dropTestTables(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "tokenactions" CASCADE');
        $this->db->query('DROP TRIGGER IF EXISTS sync_tokenactions_time ON tokenactions');
        $this->db->query('DROP FUNCTION IF EXISTS sync_tokenactions_time()');
        $this->db->query('DROP TABLE IF EXISTS "urls" CASCADE');
        $this->db->query('DROP TABLE IF EXISTS "usertokens" CASCADE');
        $this->db->query('DROP TABLE IF EXISTS "users" CASCADE');
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
