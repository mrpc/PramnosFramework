<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;
use Pramnos\DevPanel\AdminerBridge;

/**
 * Adminer resumes its own session — not ours, and not a new one each request.
 *
 * There are three states a session id can be in when Adminer's bootstrap runs, and
 * two of them are bugs. Measured on PHP 8.5, with `session.use_strict_mode` both on
 * and off and a valid `adminer_sid` cookie present:
 *
 * | before `session_start()`                        | result |
 * |---|---|
 * | nothing                                         | the cookie's session, resumed |
 * | `session_id('')`                                | **a new session** |
 * | a session started and closed, no `session_id()` | the closed session's id, inherited |
 *
 * `session_id('')` does not mean *work it out from the cookie*. It means the id is
 * set, to nothing, and PHP never looks at the cookie again.
 *
 * This route has been in both wrong states. Leaving the id alone handed Adminer the
 * framework's session — one file holding both applications, each destroying the
 * other's `token`, and the application's **database password in cleartext** in the
 * signed-in user's session file. Clearing it stopped that and started the opposite
 * failure: five distinct Adminer sessions in four seconds from one browser, each
 * holding a complete, valid Adminer session that was never read again — so the CSRF
 * token never survived a round trip and every POST answered *«Invalid CSRF token»*.
 *
 * Navigation is `GET` and unchecked, which is why both bugs looked like a working
 * tool until somebody ran a query.
 */
#[CoversClass(Adminer::class)]
#[CoversClass(AdminerBridge::class)]
class AdminerSessionPerRequestTest extends TestCase
{
    private const OURS    = 'frameworksessionid00000000000001';
    private const ADMINER = 'adminerownsessionid0000000000002';

    /**
     * The controller's handover, reachable without serving Adminer.
     */
    private function controller(): object
    {
        return new class extends Adminer {
            public function __construct()
            {
                // The real constructor wants an application; the handover wants nothing.
            }

            public function exposeHandOver(): void
            {
                $this->handOverSession();
            }
        };
    }

    /**
     * One request through the route, up to the moment Adminer starts its session.
     *
     * The framework's session, the handover, the repair, and then Adminer's bootstrap
     * condition verbatim — `session_status() == PHP_SESSION_NONE`, its own session name,
     * `session_start()`. Driven end to end because every one of these bugs lived in the
     * seam between two of those steps and none of them is visible from one alone.
     *
     * @param string|null $adminerCookie What the browser sends as `adminer_sid`, or none.
     * @param array<string, mixed> $ourSession What our session file holds on the way in.
     * @return array{id: string, session: array<string, mixed>, ours: array<string, mixed>}
     */
    private function oneRequest(?string $adminerCookie, array $ourSession = array()): array
    {
        // Arrange — a signed-in visitor, mid-request
        $_COOKIE = array('PHPSESSID' => self::OURS);

        if ($adminerCookie !== null) {
            $_COOKIE[AdminerBridge::SESSION_NAME] = $adminerCookie;
        }

        session_name('PHPSESSID');
        @session_id(self::OURS);
        @session_start();
        $_SESSION = $ourSession + array('logged' => true, 'uid' => 2, 'token' => str_repeat('a', 64));

        // Adminer's own session, as a previous request left it
        $path = (string) session_save_path();
        $path = $path === '' ? sys_get_temp_dir() : $path;
        file_put_contents($path . '/sess_' . self::ADMINER, 'token|i:461520;');

        // Act — the route, then Adminer's bootstrap
        $this->controller()->exposeHandOver();
        AdminerBridge::repairSession();

        if (session_status() === PHP_SESSION_NONE) {
            session_name(AdminerBridge::SESSION_NAME);
            @session_start();
        }

        $id      = (string) session_id();
        $session = $_SESSION;

        session_write_close();

        return array(
            'id'      => $id,
            'session' => $session,
            'ours'    => $this->decode((string) @file_get_contents($path . '/sess_' . self::OURS)),
        );
    }

    /**
     * What a session file holds, read without starting it.
     *
     * `session_decode()` needs an active session and writes into `$_SESSION`, which is the
     * thing under test here — so the file is parsed instead. PHP's default format is
     * `name|<serialized>` repeated, and a name cannot contain `|`.
     *
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = array();

        while ($raw !== '' && ($bar = strpos($raw, '|')) !== false) {
            $name  = substr($raw, 0, $bar);
            $rest  = substr($raw, $bar + 1);
            // `@` for the "extra data" notice: everything after this value is the next
            // entry, which is exactly what the loop goes on to read.
            $value = @unserialize($rest, array('allowed_classes' => false));

            $decoded[$name] = $value;

            // `serialize()` is self-delimiting, so re-serialising says how far it read.
            $raw = substr($rest, strlen(serialize($value)));
        }

        return $decoded;
    }

    /**
     * Adminer's own cookie is resumed, which is the whole fix.
     *
     * Its CSRF token is written into a session it reads back on the next request, so
     * `verify_token()` compares a token against the secret it was made from. Without this
     * the token is written into a file nobody opens again and every POST fails.
     */
    #[RunInSeparateProcess]
    public function testAdminersOwnSessionIsResumed(): void
    {
        // Act
        $result = $this->oneRequest(self::ADMINER);

        // Assert
        $this->assertSame(self::ADMINER, $result['id'], 'Adminer did not resume its own session');
        $this->assertSame(461520, $result['session']['token'] ?? null, 'the token did not survive');
    }

    /**
     * With no cookie yet, Adminer gets a session of its own — not ours.
     *
     * The first visit, and the control for the test above: "resume the cookie" must not be
     * implemented as "keep whatever id is lying around", which is precisely the collision
     * this route started with.
     */
    #[RunInSeparateProcess]
    public function testWithNoCookieAdminerStartsItsOwn(): void
    {
        // Act
        $result = $this->oneRequest(null);

        // Assert
        $this->assertNotSame(self::OURS, $result['id'], 'Adminer inherited the framework session');
        $this->assertNotSame('', $result['id']);
        $this->assertSame(array(), $result['session'], 'a fresh session came up populated');
    }

    /**
     * A cookie naming **our** session is refused, and the browser's copy is dropped.
     *
     * This is not hypothetical: the original collision made Adminer write the framework's
     * session id into `adminer_sid`, and that cookie is still in the browser of everybody
     * who used this route before the two were separated. Honouring it walks back into the
     * collision deliberately, on an installation that has already been fixed.
     */
    #[RunInSeparateProcess]
    public function testACookieNamingOurSessionIsRefused(): void
    {
        // Act
        $result = $this->oneRequest(self::OURS);

        // Assert
        $this->assertNotSame(self::OURS, $result['id'], 'the poisoned cookie was honoured');
        $this->assertSame(array(), $result['session']);
        $this->assertArrayNotHasKey(
            AdminerBridge::SESSION_NAME,
            $_COOKIE,
            'the stale cookie was left for the repair to act on'
        );
    }

    /**
     * And our own session survives it intact.
     *
     * `repairSession()` unsets a non-numeric `token`, which is right for Adminer's session
     * and destroys ours — a 64-character hex string is exactly what it strips. With the
     * poisoned cookie refused before the repair reads it, ours is not touched.
     */
    #[RunInSeparateProcess]
    public function testOurOwnSessionIsNotRepairedByMistake(): void
    {
        // Act
        $result = $this->oneRequest(self::OURS);

        // Assert
        $this->assertTrue($result['ours']['logged'] ?? false, 'the visitor was signed out');
        $this->assertSame(
            str_repeat('a', 64),
            $result['ours']['token'] ?? null,
            'our CSRF token was stripped as if it were Adminer\'s'
        );
    }

    /**
     * A hand-edited cookie is refused rather than passed to `session_id()`.
     *
     * It would be rejected noisily, and the warning would be printed above Adminer's page —
     * on a route where the page is the only thing the visitor can see.
     */
    #[RunInSeparateProcess]
    public function testAHandEditedCookieIsRefused(): void
    {
        // Act
        $result = $this->oneRequest('../../etc/passwd');

        // Assert
        $this->assertNotSame(self::OURS, $result['id']);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9,\-]{16,128}$/', $result['id']);
    }

    /**
     * The database password Adminer left in the visitor's session is removed.
     *
     * A disclosure independent of the CSRF symptom and the more urgent half: while the two
     * sessions were one, `AdminerBridge::plugin()` seeded `$_SESSION['pwds']` — the
     * application's own database password, in cleartext — into the file holding `logged`,
     * `uid` and the signed-in user's auth hash. Fixing the collision stops it being written
     * again; it does not remove what is already there, and a session file does not expire
     * on its own.
     */
    #[RunInSeparateProcess]
    public function testThePasswordLeftInOurSessionIsRemoved(): void
    {
        // Arrange — a session poisoned by the version of this route that shared one
        $poisoned = array('pwds' => array('pgsql' => array('db' => array('user' => 'the-password'))));

        // Act
        $result = $this->oneRequest(self::ADMINER, $poisoned);

        // Assert
        $this->assertArrayNotHasKey('pwds', $result['ours'], 'the password is still in the session file');
        $this->assertTrue($result['ours']['logged'] ?? false, 'the rest of the session went with it');
    }
}
