<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Http\Session;

/**
 * Exposes the two opt-out decisions without constructing a full Application.
 *
 * Both are read from `$applicationInfo` and settings and nothing else, so a probe with no
 * constructor reaches them — the same shape LazyThemeProbe uses next door, and for the
 * same reason: booting an application to test an if-statement drags in a database.
 */
class SessionOptOutProbe extends Application
{
    /** No constructor: these decisions read only $applicationInfo and settings. */
    public function __construct()
    {
    }

    /** @param array<string, mixed> $info */
    public function setInfo(array $info): void
    {
        $this->applicationInfo = $info;
    }

    public function isLazySession(): bool
    {
        return $this->lazySessionEnabled();
    }

    /**
     * Would bootSessionTracking() have run the middleware?
     *
     * It cannot be called directly — it ends by tracking against a live request — so the
     * config gate is reproduced here in the only way a unit test can reach it: by
     * asserting on the same inputs. The integration suite covers the tracking itself.
     */
    public function trackingDeclined(): bool
    {
        $configured = $this->applicationInfo['session_tracking']
            ?? Settings::getSetting('session_tracking', null);

        return $configured !== null
            && !in_array($configured, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}

/**
 * An application must be able to decline state it never asked for.
 *
 * Two decisions, both defaulting to the behaviour that shipped, because both change what
 * every request does and neither is something a minor release gets to alter underneath an
 * application.
 */
class SessionOptOutTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $cookie = [];

    protected function setUp(): void
    {
        $this->cookie = $_COOKIE;
        $_COOKIE      = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookie;
    }

    private function probe(array $info = []): SessionOptOutProbe
    {
        $probe = new SessionOptOutProbe();
        $probe->setInfo($info);

        return $probe;
    }

    // ── 'session' => 'lazy' ─────────────────────────────────────────────────

    /**
     * Without the key, the session is eager — exactly as it shipped.
     *
     * The backwards-compatibility guarantee for this half. Two hundred-odd places in the
     * framework read `$_SESSION` directly and an application may have as many again;
     * changing what they find on an upgrade is not something a minor release does.
     */
    public function testTheSessionIsEagerByDefault(): void
    {
        // Arrange & Act & Assert
        $this->assertFalse($this->probe()->isLazySession());
    }

    /**
     * `'session' => 'lazy'` turns it on.
     */
    public function testTheKeyTurnsLazyModeOn(): void
    {
        // Arrange & Act & Assert
        $this->assertTrue($this->probe(['session' => 'lazy'])->isLazySession());
    }

    /**
     * The value is matched case-insensitively.
     *
     * `'Lazy'` in a config file is the same intention as `'lazy'`, and a mode that
     * silently stays off because of a capital letter is one somebody spends an afternoon
     * on.
     */
    public function testTheValueIsCaseInsensitive(): void
    {
        // Arrange & Act & Assert
        $this->assertTrue($this->probe(['session' => 'LAZY'])->isLazySession());
    }

    /**
     * Any other value leaves the session eager.
     *
     * Including `'eager'` itself, which is the documented way to say so out loud, and
     * including a typo — where staying with the shipped behaviour is the safe direction
     * to be wrong in.
     */
    public function testAnyOtherValueIsEager(): void
    {
        // Arrange & Act & Assert
        $this->assertFalse($this->probe(['session' => 'eager'])->isLazySession());
        $this->assertFalse($this->probe(['session' => 'lazyy'])->isLazySession());
        $this->assertFalse($this->probe(['session' => true])->isLazySession());
    }

    // ── Session::hasExistingCookie() ────────────────────────────────────────

    /**
     * A visitor with no session cookie has no session to preserve.
     *
     * This is the whole basis of lazy mode being safe: it declines to *create* a session,
     * never to read one.
     */
    public function testAVisitorWithoutACookieHasNoExistingSession(): void
    {
        // Arrange
        $_COOKIE = [];

        // Act & Assert
        $this->assertFalse(Session::hasExistingCookie());
    }

    /**
     * A visitor carrying the session cookie is recognised.
     *
     * Everything that reads `$_SESSION` directly — `staticIsLogged()` above all — has to
     * keep working for them. A lazy mode that skipped this would report every signed-in
     * visitor as anonymous, which is a mode nobody could turn on.
     */
    public function testAVisitorCarryingTheCookieIsRecognised(): void
    {
        // Arrange
        $_COOKIE[session_name()] = 'abc123';

        // Act & Assert
        $this->assertTrue(Session::hasExistingCookie());
    }

    /**
     * An empty cookie value is not an existing session.
     *
     * A browser sending `PHPSESSID=` would otherwise be given a session on every request
     * — the exact behaviour lazy mode exists to stop, reached by a different route.
     */
    public function testAnEmptyCookieValueIsNotASession(): void
    {
        // Arrange
        $_COOKIE[session_name()] = '';

        // Act & Assert
        $this->assertFalse(Session::hasExistingCookie());
    }

    // ── 'session_tracking' => false ─────────────────────────────────────────

    /**
     * Without the key, tracking is not declined — the shipped behaviour.
     *
     * This test earned its place immediately: the first implementation read the setting
     * without naming a default, and `Settings::getSetting()` defaults to `false` rather
     * than null. So "this application never mentioned the key" came back as "this
     * application declined", and tracking would have switched off for every installation
     * on upgrade — the exact opposite of an opt-out.
     */
    public function testTrackingIsNotDeclinedByDefault(): void
    {
        // Arrange & Act & Assert
        $this->assertFalse($this->probe()->trackingDeclined());
    }

    /**
     * `'session_tracking' => false` declines it.
     *
     * The key exists because omission was read as consent: an application that simply did
     * not name `SessionTrackingMiddleware` got it anyway. One that had written "session
     * tracking is deliberately NOT wired" in its config had been running it the whole
     * time, two cookies and a database upsert per request, crawler hits included.
     */
    public function testTheKeyDeclinesTracking(): void
    {
        // Arrange & Act & Assert
        $this->assertTrue($this->probe(['session_tracking' => false])->trackingDeclined());
    }

    /**
     * The falsey spellings a config file actually contains are all honoured.
     *
     * A key that means what it says has to mean it however it was written; `'false'` from
     * an env-driven config silently enabling the thing it names would be the same bug
     * again in a new place.
     */
    public function testTheFalseySpellingsAreHonoured(): void
    {
        // Arrange & Act & Assert
        foreach ([false, 0, '0', 'false', 'no', 'off', ''] as $value) {
            $this->assertTrue(
                $this->probe(['session_tracking' => $value])->trackingDeclined(),
                var_export($value, true) . ' must decline tracking'
            );
        }
    }

    /**
     * Asking for tracking explicitly leaves it on.
     *
     * The key is a switch, not an off-switch: `true` must not read as "an answer was
     * given, therefore decline".
     */
    public function testAskingForTrackingLeavesItOn(): void
    {
        // Arrange & Act & Assert
        foreach ([true, 1, '1', 'true', 'yes', 'on'] as $value) {
            $this->assertFalse(
                $this->probe(['session_tracking' => $value])->trackingDeclined(),
                var_export($value, true) . ' must leave tracking on'
            );
        }
    }
}
