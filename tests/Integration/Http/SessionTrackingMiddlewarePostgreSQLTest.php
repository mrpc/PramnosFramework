<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Http\Middleware\BotDetector;
use Pramnos\Http\Middleware\SessionTrackingMiddleware;
use Pramnos\Http\Request;

/**
 * Integration tests for SessionTrackingMiddleware against PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors key tests from SessionTrackingMiddlewareMySQLTest but runs against
 * the timescaledb container (host: timescaledb, port: 5432) to cover the
 * PostgreSQL-specific SQL branch in track():
 *
 *   INSERT … ON CONFLICT (visitorid) DO UPDATE SET …
 *
 * This branch is only reachable when $database->type === 'postgresql' and
 * cannot be covered by the MySQL integration test.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[CoversClass(SessionTrackingMiddleware::class)]
#[RunTestsInSeparateProcesses]
class SessionTrackingMiddlewarePostgreSQLTest extends TestCase
{
    protected Database $db;
    private string $originalPrefix = '';

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
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testst_';

        $this->dropTable();
        $this->createTable();

        $_SESSION = [];
        $_SERVER['HTTP_USER_AGENT']  = 'Mozilla/5.0 (Windows NT 10.0) TestBrowser/1.0';
        $_SERVER['REMOTE_ADDR']      = '1.2.3.4';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    protected function tearDown(): void
    {
        $this->dropTable();
        $this->db->prefix = $this->originalPrefix;
        $_SESSION = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function dropTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "testst_sessions"');
    }

    protected function createTable(): void
    {
        $this->db->query(
            'CREATE TABLE "testst_sessions" (
                "visitorid"  VARCHAR(255) NOT NULL,
                "uname"      VARCHAR(128) NOT NULL DEFAULT \'\',
                "time"       INTEGER      NOT NULL DEFAULT 0,
                "host_addr"  VARCHAR(39)  NOT NULL DEFAULT \'\',
                "guest"      SMALLINT     NOT NULL DEFAULT 0,
                "agent"      VARCHAR(255) NOT NULL DEFAULT \'\',
                "userid"     BIGINT       NULL,
                "url"        VARCHAR(255) NOT NULL DEFAULT \'\',
                "history"    TEXT         NULL,
                "logout"     SMALLINT     NOT NULL DEFAULT 0,
                "sid"        VARCHAR(32)  NOT NULL DEFAULT \'\',
                PRIMARY KEY ("visitorid")
            )'
        );
    }

    protected function makeRequest(array $cookies = []): Request
    {
        // Arrange — build a minimal Request object
        $_COOKIE = $cookies;
        $request = new Request();
        return $request;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * track() on PostgreSQL must execute the ON CONFLICT … DO UPDATE path and
     * write a new session row into the sessions table. This test covers the
     * PostgreSQL-specific SQL branch at lines 191–203 of SessionTrackingMiddleware.
     */
    public function testTrackWritesNewSessionRowOnPostgreSQL(): void
    {
        // Arrange
        $middleware = new SessionTrackingMiddleware();
        $request    = $this->makeRequest();

        // Act
        $middleware->track($request);

        // Assert — a row was inserted by the PostgreSQL ON CONFLICT path
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM "testst_sessions"');
        $this->assertSame(1, (int) $result->fields['cnt'],
            'track() must insert exactly one session row via the PostgreSQL ON CONFLICT upsert');
    }

    /**
     * track() on PostgreSQL must update an existing row (ON CONFLICT … DO UPDATE)
     * rather than inserting a duplicate. Calling track() twice for the same
     * visitorid must still result in a single row in the table.
     */
    public function testTrackUpsertsSameRowOnSubsequentCallsForSameVisitor(): void
    {
        // Arrange — fix the visitorid cookie so both calls share the same visitor
        $visitorid  = substr(md5('1.2.3.4' . $_SERVER['HTTP_USER_AGENT']), 0, 16);
        $middleware = new SessionTrackingMiddleware();
        $request    = $this->makeRequest(['visitorid' => $visitorid]);

        // Act — call twice
        $middleware->track($request);
        $middleware->track($request);

        // Assert — exactly one row (second call was an UPDATE via ON CONFLICT)
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM "testst_sessions"');
        $this->assertSame(1, (int) $result->fields['cnt'],
            'Repeated track() calls for the same visitorid must upsert, not insert a second row');
    }

    /**
     * handle() on PostgreSQL must delegate to track() and then call $next.
     * Verifies the middleware pipeline integration on PostgreSQL.
     */
    public function testHandleCallsNextAndWritesSessionOnPostgreSQL(): void
    {
        // Arrange
        $middleware = new SessionTrackingMiddleware();
        $request    = $this->makeRequest();
        $nextCalled = false;
        $next       = function (Request $req) use (&$nextCalled): string {
            $nextCalled = true;
            return 'next-response';
        };

        // Act
        $result = $middleware->handle($request, $next);

        // Assert — $next was called and a session row was written
        $this->assertTrue($nextCalled, 'handle() must always call $next');
        $this->assertSame('next-response', $result);
        $countResult = $this->db->query('SELECT COUNT(*) AS cnt FROM "testst_sessions"');
        $this->assertGreaterThanOrEqual(1, (int) $countResult->fields['cnt'],
            'handle() must write a session row via track()');
    }
}
