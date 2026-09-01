<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\AdminerBridge;

/**
 * The repair that fixes a page somebody has already broken — 25 statements, never executed.
 *
 * Adminer starts a session only when none is active, so the first version of the `/adminer` route
 * handed it ours. One of the keys it uses is `token`; ours is a hex string and its is
 * `rand() ^ $_SESSION["token"]`, which gives «A non-numeric value encountered» twice a page and a
 * CSRF check that cannot work.
 *
 * Closing our session fixed it for a new visitor and did nothing for anybody who had already
 * loaded the broken page: the bad value was sitting in their `adminer_sid` session, waiting for
 * every later request. So the value is repaired rather than merely prevented — the alternative is
 * telling people to clear their cookies, which is what software says when it cannot fix itself.
 *
 * Which makes this a routine whose correctness is invisible: it runs before Adminer, touches one
 * key in a session that belongs to another package, and leaves no trace when it works. The parts
 * worth pinning are all about restraint —
 *
 *   - **only `token`, and only when it is not a number.** Everything else in there is Adminer's;
 *   - **only when nothing is already open**, because opening a second session over a live one
 *     would take the visitor's own with it;
 *   - **only for a cookie shaped like a session id**, because anything else is somebody editing
 *     their own cookie and `session_id()` rejects it noisily;
 *   - **and it puts the session name and two ini settings back**, which is the one this file
 *     exists for. `session_start($options)` applies its options as ini settings for the rest of
 *     the request, so a repair that did not restore them left Adminer warning «Session cookies
 *     cannot be used when session.use_cookies is disabled» at the top of every page. A repair
 *     that leaves a warning behind has not repaired anything.
 */
#[CoversClass(AdminerBridge::class)]
#[RunTestsInSeparateProcesses]
class AdminerSessionRepairTest extends TestCase
{
    private string $savedName = '';

    private string $savedSavePath = '';

    private array $savedCookie = [];

    private string $sessionPath = '';

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->savedName   = session_name();
        $this->savedCookie = $_COOKIE;

        // A directory of this test's own, so nothing here can read or write a session belonging
        // to the rest of the suite.
        $this->savedSavePath = (string) ini_get('session.save_path');
        $this->sessionPath   = sys_get_temp_dir() . '/pf-adminer-session-' . bin2hex(random_bytes(6));
        mkdir($this->sessionPath, 0777, true);
        ini_set('session.save_path', $this->sessionPath);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name($this->savedName);
        ini_set('session.save_path', $this->savedSavePath);
        $_COOKIE = $this->savedCookie;
        $_SESSION = [];

        foreach (glob($this->sessionPath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionPath);
    }

    // ── What it repairs ───────────────────────────────────────────────────────

    /**
     * A framework token left in Adminer's session is removed.
     *
     * The whole point: this value is already on somebody's disk, and every later request reads it
     * back and fails the same way. Nothing the visitor does short of clearing cookies would have
     * cleared it.
     */
    public function testANonNumericTokenIsTakenOut(): void
    {
        // Arrange — the broken session, as the first version of the route left it.
        $id = $this->seedAdminerSession([
            'token'     => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'something' => 'that belongs to Adminer',
        ]);

        // Act
        AdminerBridge::repairSession();

        // Assert
        $after = $this->readAdminerSession($id);
        $this->assertArrayNotHasKey('token', $after, 'the bad token is still there');
        $this->assertSame(
            'that belongs to Adminer',
            $after['something'] ?? null,
            "the repair took something that was not ours"
        );
    }

    /**
     * A numeric token is Adminer's own, and is left alone.
     *
     * `rand() ^ $_SESSION["token"]` is an integer. Removing it would log the visitor out of
     * Adminer on every request and look exactly like the bug this repairs.
     */
    public function testANumericTokenIsLeftAlone(): void
    {
        // Arrange
        $id = $this->seedAdminerSession(['token' => 1234567890]);

        // Act
        AdminerBridge::repairSession();

        // Assert
        $this->assertSame(
            1234567890,
            $this->readAdminerSession($id)['token'] ?? null,
            "Adminer's own token was removed"
        );
    }

    // ── What it refuses to touch ──────────────────────────────────────────────

    /** With no Adminer cookie there is nothing to repair, and no session is opened. */
    public function testWithNoCookieNothingHappens(): void
    {
        // Arrange
        unset($_COOKIE[AdminerBridge::SESSION_NAME]);

        // Act
        AdminerBridge::repairSession();

        // Assert
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'a session was opened for nothing');
    }

    /**
     * With a session already open, it does nothing.
     *
     * Starting a second one over a live session would take the visitor's own with it — and this
     * runs on a route they reached while signed in.
     */
    public function testWithASessionAlreadyOpenNothingHappens(): void
    {
        // Arrange
        $id = $this->seedAdminerSession(['token' => 'not-a-number']);
        $before = $this->rawSessionFile($id);

        session_name('a_session_of_our_own');
        session_id('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        @session_start(['use_cookies' => false]);
        $_SESSION['ours'] = 'untouched';

        // Act
        AdminerBridge::repairSession();

        // Assert
        $this->assertSame('untouched', $_SESSION['ours'] ?? null, 'the live session was replaced');
        $this->assertSame(
            $before,
            $this->rawSessionFile($id),
            'the repair rewrote the Adminer session while another one was open'
        );
    }

    /**
     * A cookie that is not shaped like a session id is refused.
     *
     * It is a value the visitor controls, and `session_id()` rejects anything else noisily —
     * a warning on a page that is meant to be repairing a warning.
     */
    public function testACookieThatIsNotASessionIdIsRefused(): void
    {
        // Act & Assert
        foreach (['../../etc/passwd', 'short', str_repeat('x', 200), 'has spaces', '<script>'] as $bad) {
            $_COOKIE[AdminerBridge::SESSION_NAME] = $bad;

            AdminerBridge::repairSession();

            $this->assertSame(
                PHP_SESSION_NONE,
                session_status(),
                'a session was opened for cookie ' . var_export($bad, true)
            );
        }
    }

    // ── What it puts back ─────────────────────────────────────────────────────

    /**
     * The session name and the two cookie ini settings are restored.
     *
     * The reason this method has a `finally`. `session_start($options)` applies its options as
     * ini settings **for the rest of the request**, so without the restore Adminer's own
     * `session_set_cookie_params()` warned «Session cookies cannot be used when
     * session.use_cookies is disabled» at the top of every page — a repair that leaves a warning
     * behind has not repaired anything. The name matters for whatever runs next, which in a web
     * request is Adminer and in this suite is the next test.
     */
    public function testTheSessionNameAndCookieSettingsAreRestored(): void
    {
        // Arrange — the state a real request is in when the repair runs: a session name of its
        // own, and cookies enabled. (Seeding the fixture disables them as a side effect of
        // `session_start(['use_cookies' => false])`, which is exactly why they are set back here
        // rather than captured before it.)
        $this->seedAdminerSession(['token' => 'not-a-number']);

        session_name('the_name_before');
        ini_set('session.use_cookies', '1');

        // Act
        AdminerBridge::repairSession();

        // Assert
        $this->assertSame('the_name_before', session_name(), 'the session name was left changed');
        $this->assertSame(
            '1',
            ini_get('session.use_cookies'),
            'session.use_cookies was left disabled, which warns on every later page'
        );
        $this->assertSame(
            '1',
            ini_get('session.use_only_cookies'),
            'the repair still touches session.use_only_cookies, which it has no reason to'
        );
    }

    /** And it leaves no session open behind it. */
    public function testItLeavesNoSessionOpen(): void
    {
        // Arrange
        $this->seedAdminerSession(['token' => 'not-a-number']);

        // Act
        AdminerBridge::repairSession();

        // Assert
        $this->assertSame(
            PHP_SESSION_NONE,
            session_status(),
            'the repair left its session open for whatever runs next'
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * Write a session under Adminer's name, and leave its id in the cookie.
     *
     * @param array<string, mixed> $data
     * @return string the session id
     */
    private function seedAdminerSession(array $data): string
    {
        $id = str_repeat('a', 32);

        session_name(AdminerBridge::SESSION_NAME);
        session_id($id);
        @session_start(['use_cookies' => false]);
        $_SESSION = $data;
        session_write_close();

        $_COOKIE[AdminerBridge::SESSION_NAME] = $id;

        return $id;
    }

    /** Read that session back from disk. @return array<string, mixed> */
    private function readAdminerSession(string $id): array
    {
        session_name(AdminerBridge::SESSION_NAME);
        session_id($id);
        @session_start(['use_cookies' => false]);
        $data = $_SESSION;
        session_write_close();

        return $data;
    }

    /**
     * The session file exactly as it is on disk.
     *
     * Read raw rather than decoded, because decoding would mean opening the session — which is
     * the very thing the assertion is checking did not happen.
     */
    private function rawSessionFile(string $id): string
    {
        $file = $this->sessionPath . '/sess_' . $id;

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
