<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Http\Middleware\BotDetector;
use Pramnos\Http\Middleware\SessionTrackingMiddleware;
use Pramnos\Http\Request;

/**
 * Integration tests for SessionTrackingMiddleware against MySQL 8.0.
 *
 * Verifies that the middleware actually writes to and reads from the sessions
 * table — pure unit tests cannot prove this because the behaviour depends on
 * the SQL layer (INSERT … ON DUPLICATE KEY UPDATE, DELETE for stale rows, etc.).
 *
 * Test setup creates a minimal sessions table with the `testst_` prefix so it
 * never conflicts with the real sessions table used by other tests.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class SessionTrackingMiddlewareMySQLTest extends TestCase
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

        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testst_';

        $this->dropTable();
        $this->createTable();

        // Initialise $_SESSION / $_SERVER stubs so the middleware does not error
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
        $this->db->query('DROP TABLE IF EXISTS `testst_sessions`');
    }

    protected function createTable(): void
    {
        $this->db->query(
            "CREATE TABLE `testst_sessions` (
                `visitorid`  VARCHAR(255) NOT NULL,
                `uname`      VARCHAR(128) NOT NULL DEFAULT '',
                `time`       INT UNSIGNED NOT NULL DEFAULT 0,
                `host_addr`  VARCHAR(39)  NOT NULL DEFAULT '',
                `guest`      TINYINT      NOT NULL DEFAULT 0,
                `agent`      VARCHAR(255) NOT NULL DEFAULT '',
                `userid`     BIGINT UNSIGNED NULL,
                `url`        VARCHAR(255) NOT NULL DEFAULT '',
                `history`    TEXT         NULL,
                `logout`     TINYINT      NOT NULL DEFAULT 0,
                `sid`        VARCHAR(32)  NOT NULL DEFAULT '',
                PRIMARY KEY (`visitorid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Build a minimal stub Request that returns predictable values for the
     * fields SessionTrackingMiddleware reads.
     */
    private function makeRequest(string $visitorid = ''): Request
    {
        $request = $this->createMock(Request::class);

        $request->method('cookieget')->willReturnCallback(
            function (string $key) use ($visitorid): ?string {
                if ($key === 'visitorid') {
                    return $visitorid !== '' ? $visitorid : null;
                }
                return null;
            }
        );
        $request->method('cookieset')->willReturn(null);
        $request->method('getURL')->willReturn('/test');

        return $request;
    }

    private function countRows(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM `testst_sessions`');
        return (int) ($result->fields['cnt'] ?? 0);
    }

    private function fetchRow(string $visitorid): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT * FROM `testst_sessions` WHERE `visitorid` = %s',
                base64_encode(hex2bin($visitorid))
            )
        );
        return $result->fields ?? [];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * SessionTrackingMiddleware::track() must write a row to the sessions table
     * for a new (first-visit) anonymous visitor.
     *
     * This is the primary contract: the middleware persists session data to the DB.
     */
    public function testTrackWritesNewSessionRowForGuestVisitor(): void
    {
        // Arrange — visitorid unknown (no cookie), guest session
        $request    = $this->makeRequest();
        $middleware  = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — one row was inserted into the sessions table
        $this->assertSame(1, $this->countRows(), 'A new session row must be inserted for a first-time visitor');
    }

    /**
     * A second call with the same visitorid must UPDATE the existing row rather
     * than inserting a duplicate.
     *
     * The ON DUPLICATE KEY UPDATE path must be exercised; a second INSERT would
     * violate the PRIMARY KEY constraint.
     */
    public function testTrackUpsertsSameRowOnSubsequentCallsForSameVisitor(): void
    {
        // Arrange — supply a fixed visitorid so both calls use the same row
        $visitorid  = 'abcdef1234567890';
        $request    = $this->makeRequest(visitorid: $visitorid);
        $middleware  = new SessionTrackingMiddleware();

        // Act — call twice
        $middleware->track($request);
        $middleware->track($request);

        // Assert — still only one row (upsert, not two inserts)
        $this->assertSame(1, $this->countRows(), 'Duplicate visitorid must UPSERT, not insert a second row');
    }

    /**
     * A Googlebot visitor must be recorded in the uname column with the bot name.
     *
     * Bot detection feeds into the sessions table so admins can distinguish
     * real users from crawlers in the session viewer.
     */
    public function testTrackRecordsBotNameInUnameForBotVisitor(): void
    {
        // Arrange — override User-Agent with a known bot string
        // visitorid must be a hex string (16 chars from md5 output) for hex2bin()
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $visitorid = '0123456789abcdef';
        $request   = $this->makeRequest(visitorid: $visitorid);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — uname must contain the Googlebot label, not 'Anonymous'
        $row = $this->fetchRow($visitorid);
        $this->assertNotEmpty($row, 'Session row must exist for the bot visit');
        $this->assertStringContainsString(
            'Googlebot',
            $row['uname'] ?? '',
            'Bot uname must contain "Googlebot"'
        );
    }

    /**
     * track() must pass control to $next when used as a real middleware
     * (handle() method), and the $next callable must receive the request.
     *
     * This confirms the middleware contract — it does not short-circuit the
     * pipeline.
     */
    public function testHandleCallsNextMiddleware(): void
    {
        // Arrange
        $request    = $this->makeRequest();
        $middleware  = new SessionTrackingMiddleware();
        $nextCalled  = false;

        // Act
        $middleware->handle($request, function (Request $req) use (&$nextCalled): string {
            $nextCalled = true;
            return 'response';
        });

        // Assert — $next was invoked
        $this->assertTrue($nextCalled, 'SessionTrackingMiddleware must call $next and not short-circuit');
    }

    /**
     * handle() must return the value produced by $next.
     *
     * The response from downstream middleware/actions must flow back unchanged.
     */
    public function testHandleReturnsNextReturnValue(): void
    {
        // Arrange
        $request    = $this->makeRequest();
        $middleware  = new SessionTrackingMiddleware();

        // Act
        $result = $middleware->handle($request, fn() => 'downstream_response');

        // Assert
        $this->assertSame('downstream_response', $result);
    }
}
