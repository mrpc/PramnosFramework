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

    // -------------------------------------------------------------------------
    // Cloudflare / language headers
    // -------------------------------------------------------------------------

    /**
     * Build a stub Request with an arbitrary cookie map (more flexible than
     * makeRequest, needed for the logged-in / authCheck branches).
     *
     * @param array<string, string|null> $cookies
     */
    private function makeRequestWithCookies(array $cookies): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('cookieget')->willReturnCallback(
            fn(string $key) => $cookies[$key] ?? null
        );
        $request->method('cookieset')->willReturn(null);
        $request->method('getURL')->willReturn('/test');
        return $request;
    }

    /**
     * When Cloudflare headers are present, track() must store the
     * CF-Connecting-IP (the real visitor IP) in host_addr instead of the
     * proxy's REMOTE_ADDR, and read country / language without errors.
     */
    public function testTrackUsesCloudflareConnectingIpAndHeaders(): void
    {
        // Arrange — simulate a request routed through Cloudflare
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
        $_SERVER['HTTP_CF_IPCOUNTRY']     = 'GR';
        $_SERVER['HTTP_ACCEPT_LANGUAGE']  = 'el-GR,el;q=0.9,en;q=0.8';
        $visitorid = 'aaaa111122223333';
        $request   = $this->makeRequest(visitorid: $visitorid);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — host_addr must be the CF-Connecting-IP, not REMOTE_ADDR
        $row = $this->fetchRow($visitorid);
        $this->assertSame('203.0.113.77', $row['host_addr'] ?? '',
            'host_addr must record the Cloudflare CF-Connecting-IP');
    }

    // -------------------------------------------------------------------------
    // Logged-in user branch
    // -------------------------------------------------------------------------

    /**
     * For a logged-in session with auth/username cookies present, track()
     * must record guest=0, the username in uname, the numeric uid in userid,
     * and refresh the login cookies.
     */
    public function testTrackRecordsLoggedInUser(): void
    {
        // Arrange — logged-in session for uid=42
        $_SESSION['logged']   = true;
        $_SESSION['uid']      = 42;
        $_SESSION['username'] = 'mrpc';
        $_SESSION['auth']     = 'authhash';
        $visitorid = 'bbbb111122223333';
        $request   = $this->makeRequestWithCookies([
            'visitorid' => $visitorid,
            'auth'      => 'authhash',
            'username'  => 'mrpc',
        ]);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — row reflects the authenticated identity
        $row = $this->fetchRow($visitorid);
        $this->assertSame('mrpc', $row['uname'] ?? '');
        $this->assertSame('0', (string) ($row['guest'] ?? ''),
            'A logged-in visitor must be recorded with guest=0');
        $this->assertSame('42', (string) ($row['userid'] ?? ''),
            'userid must hold the session uid for authenticated visitors');
    }

    /**
     * A logged-in session with uid=1 (anonymous placeholder) must store NULL
     * in userid — uid 1 is the Guest account and must not be linked.
     */
    public function testTrackStoresNullUserIdForUidOne(): void
    {
        // Arrange — "logged in" as the placeholder uid 1
        $_SESSION['logged']   = true;
        $_SESSION['uid']      = 1;
        $_SESSION['username'] = 'ghost';
        $visitorid = 'cccc111122223333';
        $request   = $this->makeRequest(visitorid: $visitorid);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — userid column must be NULL (uid 1 is never linked)
        $row = $this->fetchRow($visitorid);
        $this->assertNull($row['userid'],
            'uid=1 must be stored as NULL in the sessions table');
    }

    /**
     * Usernames longer than 128 characters must be truncated before the
     * INSERT — uname is a VARCHAR(128) column.
     */
    public function testTrackTruncatesLongUsername(): void
    {
        // Arrange — 200-char username
        $_SESSION['logged']   = true;
        $_SESSION['uid']      = 7;
        $_SESSION['username'] = str_repeat('x', 200);
        $visitorid = 'dddd111122223333';
        $request   = $this->makeRequest(visitorid: $visitorid);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert
        $row = $this->fetchRow($visitorid);
        $this->assertSame(128, strlen($row['uname'] ?? ''),
            'uname must be truncated to 128 characters');
    }

    // -------------------------------------------------------------------------
    // authCheck branch (cookies without active session)
    // -------------------------------------------------------------------------

    /**
     * A visitor with auth/username cookies but no active session must trigger
     * Auth::authCheck() (cookie-based re-login attempt). With no auth addons
     * registered the call is a no-op, and the visitor is recorded as guest.
     */
    public function testTrackTriggersAuthCheckForCookieOnlyVisitor(): void
    {
        // Arrange — no $_SESSION['logged'], but auth cookies present
        $visitorid = 'eeee111122223333';
        $request   = $this->makeRequestWithCookies([
            'visitorid' => $visitorid,
            'auth'      => 'somehash',
            'username'  => 'cookieuser',
        ]);
        $middleware = new SessionTrackingMiddleware();

        // Act — must not throw even though no auth addon is registered
        $middleware->track($request);

        // Assert — row written; authCheck() no-op leaves the visitor a guest
        $row = $this->fetchRow($visitorid);
        $this->assertNotEmpty($row, 'Session row must be written after authCheck path');
        $this->assertSame('1', (string) ($row['guest'] ?? ''),
            'Visitor remains guest when authCheck cannot re-authenticate');
    }

    // -------------------------------------------------------------------------
    // Force-logout branch
    // -------------------------------------------------------------------------

    /**
     * When the existing session row has logout=1 (admin kicked the visitor),
     * track() must reset the session, log the user out, and record the
     * visitor as "Kicked Out".
     */
    public function testTrackForceLogoutKicksVisitorOut(): void
    {
        // Arrange — pre-insert a row flagged logout=1 for this visitor
        $visitorid = 'ffff111122223333';
        $this->db->query($this->db->prepareQuery(
            "INSERT INTO `testst_sessions`
             (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
              `userid`, `url`, `logout`, `sid`, `history`)
             VALUES (%s, 'victim', %d, '1.2.3.4', 0, 'agent', NULL, '/x', 1, 'sid', '')",
            base64_encode(hex2bin($visitorid)),
            time()
        ));
        $_SESSION['logged']   = true;
        $_SESSION['uid']      = 9;
        $_SESSION['username'] = 'victim';
        $request   = $this->makeRequest(visitorid: $visitorid);
        $middleware = new SessionTrackingMiddleware();

        // Act
        $middleware->track($request);

        // Assert — uname rewritten to "Kicked Out", guest forced back to 1,
        // and the logout flag cleared by the upsert (one-shot kick).
        $row = $this->fetchRow($visitorid);
        $this->assertSame('Kicked Out', $row['uname'] ?? '');
        $this->assertSame('1', (string) ($row['guest'] ?? ''),
            'Force-logout must demote the visitor to guest');
        $this->assertSame('0', (string) ($row['logout'] ?? ''),
            'The logout flag must be reset after the kick is applied');
    }
}
