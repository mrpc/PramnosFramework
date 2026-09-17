<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\Middleware\SessionTrackingMiddleware;
use Pramnos\Http\Request;

/**
 * The session-tracking upsert, against a real database.
 *
 * `SessionTrackingCostTest` covers the decision *whether* to sweep and how often, with no database
 * behind it. What had never run is the statement this middleware exists to issue — and it is written
 * twice, once per dialect: `ON DUPLICATE KEY UPDATE` for MySQL and `ON CONFLICT (visitorid) DO UPDATE
 * … RETURNING logout` for PostgreSQL.
 *
 * Neither had been executed against a server, and the PostgreSQL half is the one that matters: it is
 * hand-written dialect SQL, so a mistake in it is not a wrong number but a silent nothing. Session
 * tracking would fail on every PostgreSQL installation, the DevPanel's active-visitor list would be
 * empty, and the only symptom is an absence.
 *
 * ## `RETURNING logout` is a feature, not a flourish
 *
 * The upsert deliberately leaves `logout` alone, and reads back what it was. That is how «an
 * administrator ended this session» reaches the visitor: the flag is set in the table by somebody
 * else, and the next request this visitor makes finds it and is signed out. Overwriting it — which an
 * upsert setting every column would do — silently removes the ability to eject anybody.
 *
 * Both lanes, and here that is the whole point rather than diligence.
 */
#[CoversClass(SessionTrackingMiddleware::class)]
class SessionTrackingUpsertTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([\Pramnos\Framework\Migrations\Core\CreateSessionsTable::class], $this->db);

        // No visitor cookie: a first-time visitor has none, and the middleware mints the id itself.
        // See visitorId() for why handing it one was the wrong fixture twice over.
        $_SESSION = [];

        $_SERVER['HTTP_USER_AGENT']      = 'PramnosTest/1.0';
        $_SERVER['REMOTE_ADDR']          = '198.51.100.20';
        $_SERVER['REQUEST_URI']          = '/a-page';
        $_SERVER['REQUEST_METHOD']       = 'GET';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'el-GR,el;q=0.9';

        Request::resetInstance();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        try {
            // Every row: this class owns the table for the length of a test, and a mint the test did
            // not predict would otherwise survive it.
            $this->db->queryBuilder()->table('#PREFIX#sessions')->delete();
        } catch (\Throwable) {
            // Nothing to remove.
        }

        $_COOKIE = [];
        $_SERVER = array_diff_key($_SERVER, array_flip([
            'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_URI',
            'HTTP_ACCEPT_LANGUAGE', 'HTTP_CF_IPCOUNTRY', 'SERVER_NAME',
        ]));
        Request::resetInstance();

        parent::tearDown();
    }

    /** The middleware, with the sweep forced on so its statement runs too. */
    private function middleware(): SessionTrackingMiddleware
    {
        return new class extends SessionTrackingMiddleware {
            protected function shouldCollectGarbage(): bool
            {
                // Forced, not random: the sweep is one-in-`session_gc_divisor` by design, and a test
                // that took the odds would pass ninety-nine times out of a hundred without running
                // the statement it is about.
                return true;
            }
        };
    }

    /**
     * The id the middleware minted, taken from where it puts it.
     *
     * Two things made handing it one the wrong fixture, and both cost a run to find:
     *
     *  - **the cookie is namespaced.** `cookieget()` reads
     *    `$_COOKIE[substr(md5('pcms'), 0, 10)][str_rot13($name)]`, so a plain `$_COOKIE['visitorid']`
     *    is invisible and the middleware mints its own — which is what happened, and the test then
     *    looked up an id nothing had written.
     *  - **the id is hex, and stored packed.** It is generated as `substr(md5(...), 0, 16)`, run
     *    through `hex2bin()` and stored base64, so the column holds twelve characters rather than
     *    sixteen. «Any unique string» is not a valid id at all: `hex2bin()` on one warns and returns
     *    false.
     *
     * So the middleware is left to mint it, and `$_SESSION['visitorid']` is where it says what it
     * chose.
     */
    private function visitorId(): string
    {
        return (string) ($_SESSION['visitorid'] ?? '');
    }

    /** How that id is stored: packed to bytes, then base64. */
    private function storedVisitorId(): string
    {
        $id = $this->visitorId();

        return $id === '' ? '' : base64_encode((string) hex2bin($id));
    }

    /** This visitor's row, or null. */
    private function row(): ?array
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('visitorid', $this->storedVisitorId())
            ->first();

        return $result && ($result->numRows ?? 0) > 0 ? (array) $result->fields : null;
    }

    /**
     * A first request writes the visitor's row, on either dialect.
     *
     * The statement is written twice — `ON DUPLICATE KEY UPDATE` and `ON CONFLICT … DO UPDATE` — and
     * neither had ever been issued against a server. A mistake in hand-written dialect SQL is not a
     * wrong number: it is a silent nothing, and the only symptom is an empty active-visitor list on
     * one engine.
     */
    public function testAFirstRequestWritesTheVisitorRow(): void
    {
        // Act
        $this->middleware()->track(Request::getInstance());

        // Assert
        $row = $this->row();
        $this->assertNotNull($row, 'the visitor was not recorded at all');
        $this->assertSame('PramnosTest/1.0', (string) $row['agent']);
        $this->assertSame('198.51.100.20', (string) $row['host_addr']);
        $this->assertGreaterThan(0, (int) $row['time']);
    }

    /**
     * A second request updates that row rather than adding another.
     *
     * `visitorid` is the primary key, so the alternative is not «two rows» but a failed insert — and
     * the upsert is what turns the second request into an update. A table with one row per *request*
     * would also mean the active-visitor list counted page views.
     */
    public function testASecondRequestUpdatesTheSameRow(): void
    {
        // Arrange
        $middleware = $this->middleware();
        $middleware->track(Request::getInstance());
        $first = $this->row();

        // Act — a later request from the same visitor
        $_SERVER['REQUEST_URI'] = '/another-page';
        Request::resetInstance();
        $middleware->track(Request::getInstance());

        // Assert
        $count = $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('visitorid', $this->storedVisitorId())
            ->count();

        $this->assertSame(1, (int) $count, 'the second request added a row instead of updating one');
        $this->assertNotNull($first);
        $this->assertGreaterThanOrEqual((int) $first['time'], (int) $this->row()['time']);
    }

    /**
     * The upsert leaves `logout` alone, which is how somebody can be ejected.
     *
     * The flag is set in the table by an administrator, and the visitor's *next* request is what acts
     * on it. So an upsert that wrote `logout = 0` along with everything else — which is what an upsert
     * setting every column looks like, and what this one used to do — would clear the instruction
     * before it was ever read, and «end this session» would silently do nothing.
     *
     * On PostgreSQL the flag is read back with `RETURNING`; on MySQL the row is read separately. Both
     * lanes assert the same outcome, because the outcome is the contract and the mechanism is not.
     */
    public function testAnAdministratorsLogoutFlagSurvivesTheNextRequest(): void
    {
        // Arrange — a live row, then somebody ends it
        $middleware = $this->middleware();
        $middleware->track(Request::getInstance());

        $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('visitorid', $this->storedVisitorId())
            ->update(['logout' => 1]);

        // Act — the visitor makes another request
        Request::resetInstance();
        $middleware->track(Request::getInstance());

        // Assert
        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertSame(
            1,
            (int) $row['logout'],
            'the upsert cleared the flag, so «end this session» does nothing'
        );
    }

    /**
     * The sweep removes rows that stopped being visitors, and only those.
     *
     * Five minutes, and the table is a live-visitor list rather than a record — so the sweep is the
     * only thing that keeps it from growing for ever. The second assertion is the one worth having: a
     * `time <` comparison written the wrong way round, or against the wrong column, would delete the
     * visitors who *are* here and leave the ones who left.
     */
    public function testTheSweepRemovesStaleRowsAndKeepsLiveOnes(): void
    {
        // Arrange — one row from ten minutes ago, and this visitor
        $stale = base64_encode(random_bytes(8));
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => $stale,
            'uname'     => 'Guest',
            'time'      => time() - 600,
            'host_addr' => '198.51.100.21',
            'guest'     => 1,
            'agent'     => 'PramnosTest/1.0',
            'userid'    => 0,
            'url'       => '/gone',
            'logout'    => 0,
            'sid'       => 'gone',
            'history'   => '',
        ]);

        // Act
        $this->middleware()->track(Request::getInstance());

        // Assert
        $this->assertNull(
            $this->db->queryBuilder()->table('#PREFIX#sessions')
                ->where('visitorid', $stale)->first()->fields['visitorid'] ?? null,
            'a visitor who left ten minutes ago is still listed'
        );
        $this->assertNotNull($this->row(), 'the sweep removed the visitor who is here');
    }

    /**
     * A 401-character URL is recorded, not refused.
     *
     * WHAT: the address of an OAuth callback carrying three scopes goes into `sessions.url`
     *       whole, and the row exists afterwards.
     *
     * WHY:  the column was `varchar(255)`. An OAuth callback is longer than that as a matter
     *       of course — `state`, `code`, `iss`, three scope URLs, `authuser`, `prompt` — and
     *       PostgreSQL refuses the entire statement with `value too long for type character
     *       varying(255)`. So does a long search query, and so does a UTM-laden campaign
     *       link; this is not an exotic input.
     *
     *       The length is the assertion. A test that recorded a short URL and called it
     *       covered would have passed on the broken column, which is how this reached a
     *       production sign-in.
     */
    public function testALongUrlIsRecordedRatherThanRefused(): void
    {
        // Arrange — the shape that actually failed: a Google OAuth callback, three scopes
        $longUrl = '/connect/callback/google?state=' . str_repeat('a', 43)
            . '&iss=https://accounts.google.com&code=' . str_repeat('b', 73)
            . '&scope=email+https://www.googleapis.com/auth/analytics.readonly'
            . '+https://www.googleapis.com/auth/webmasters.readonly'
            . '+https://www.googleapis.com/auth/userinfo.email+openid'
            . '&authuser=0&prompt=consent';
        $this->assertGreaterThan(
            255,
            strlen($longUrl),
            'the fixture has to exceed the old column width or it proves nothing'
        );
        $_SERVER['REQUEST_URI'] = $longUrl;

        // `getURL(false)` is absolute, and it builds the host from `SERVER_NAME`. Without one
        // it returns the seven characters `http://` and the path never reaches the column —
        // which is what the rest of this class has been recording all along, and why a test
        // for a long URL has to set it or it measures nothing.
        $_SERVER['SERVER_NAME'] = 'example.com';
        Request::resetInstance();

        // Act
        $this->middleware()->track(Request::getInstance());

        // Assert — the row exists, and the address in it was not cut short
        $row = $this->row();
        $this->assertNotNull($row, 'a long URL lost the visitor their row entirely');
        $this->assertGreaterThan(
            255,
            strlen((string) $row['url']),
            'the URL was truncated, so the column is still narrow somewhere'
        );
    }

    /**
     * A tracking write that fails does not sign the visitor out.
     *
     * WHAT: when the upsert raises, the request carries on — `$_SESSION['logged']` is still
     *       true and the framework's auth state is untouched.
     *
     * WHY:  this is the half of the bug that cost the afternoon. Both branches caught the
     *       exception and called `$session->reset()` and `$auth->logout()`, so **any** error
     *       writing a tracking row signed the visitor out: a deadlock, a connection blip, or
     *       the 401-character URL above. What the person sees is the sign-in page at the end
     *       of a successful OAuth consent, which reads as an expired session or a cookie
     *       problem — every explanation except a tracking table.
     *
     *       Driven by making the write fail rather than by asserting on the handler, because
     *       the handler is not the contract: «a tracking failure does not end a session» is.
     *       The failure is injected by dropping the table, which is the most honest available
     *       error — it is a real database exception on both dialects, raised from inside
     *       `query()` exactly where a width overflow would be.
     */
    public function testAFailedTrackingWriteLeavesTheSessionAlone(): void
    {
        // Arrange — a signed-in visitor, and a sessions table that is not there
        $_SESSION['logged']   = true;
        $_SESSION['uid']      = 42;
        $_SESSION['username'] = 'someone';

        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefix . 'sessions');

        // Act — the upsert raises inside the middleware
        $this->middleware()->track(Request::getInstance());

        // Assert — the session survived the tracking failure
        $this->assertTrue(
            $_SESSION['logged'] ?? false,
            'a failed tracking write signed the visitor out'
        );
        $this->assertSame(42, $_SESSION['uid'] ?? null);

        // Put it back for tearDown, which expects a table to empty.
        $this->runMigrations([\Pramnos\Framework\Migrations\Core\CreateSessionsTable::class], $this->db);
    }
}
