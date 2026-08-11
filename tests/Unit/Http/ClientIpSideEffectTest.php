<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;
use Pramnos\Http\Session;

/**
 * The two regressions that only a real application surfaced.
 *
 * WHAT: asking "who is the client" must be a lookup, not an event; and the CSRF
 *       fingerprint must hash exactly what it hashed before.
 * WHY:  both were found by running a production application's suite against
 *       this framework, and neither could have been found here — the framework's
 *       own tests never had a second application to construct, and never set
 *       `REMOTE_ADDR` to an empty string. Three of that application's login
 *       tests failed on "security token invalid or expired".
 *
 * They are kept as unit tests so the next change to `clientIp()` has to answer
 * for them without waiting for a downstream suite to notice.
 */
class ClientIpSideEffectTest extends TestCase
{
    /** @var array<string, mixed> The server superglobal as the test found it */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * Resolving the client IP does not construct an application.
     *
     * `Application::getInstance()` is a factory: with no instance for the key it
     * reads `app.php`, defines constants and runs the whole constructor, which
     * sets up a database, a language and a session. `clientIp()` is called from
     * CSRF verification and from rate-limit middleware — the two places least
     * able to absorb a side effect of that size, because both run before the
     * request has decided anything.
     *
     * The resolver therefore asks `currentInstance()`, which returns null rather
     * than building one.
     */
    public function testResolvingTheClientIpCreatesNoApplication(): void
    {
        // Arrange — count the applications that exist right now
        $instances = new \ReflectionProperty(
            \Pramnos\Application\Application::class,
            'appInstances'
        );
        $before = count((array) $instances->getValue());

        // Act
        Request::clientIp();

        // Assert
        $this->assertSame(
            $before,
            count((array) $instances->getValue()),
            'a configuration lookup must not boot an application'
        );
    }

    /**
     * An empty `REMOTE_ADDR` is not the same as an absent one.
     *
     * The fingerprint was `$_SERVER['REMOTE_ADDR'] ?? 'none'`, and `??` does not
     * fire for an empty string — so an empty `REMOTE_ADDR` hashed as `''`. A
     * rewrite that substituted `'none'` for both cases changed the fingerprint,
     * which invalidated every CSRF token in flight: the token is issued by one
     * request and verified by the next, so any change to the hashed value logs
     * users out of their forms.
     *
     * This asserts the two cases stay distinguishable.
     */
    public function testAnEmptyRemoteAddrFingerprintsDifferentlyFromAnAbsentOne(): void
    {
        // Arrange
        $session = new Session();
        $_SERVER['HTTP_USER_AGENT'] = 'fingerprint-probe/1.0';

        // Act
        $_SERVER['REMOTE_ADDR'] = '';
        $empty = $session->getFingerprint(true);

        unset($_SERVER['REMOTE_ADDR']);
        $absent = $session->getFingerprint(true);

        // Assert
        $this->assertNotSame(
            $empty,
            $absent,
            "an empty REMOTE_ADDR hashed as '' and an absent one as 'none'; "
            . 'collapsing them invalidates tokens issued before the change'
        );
    }

    /**
     * The fingerprint is stable across calls with the same environment.
     *
     * The property the whole CSRF scheme rests on: the value hashed when the
     * form is rendered must be the value hashed when it comes back.
     */
    public function testTheFingerprintIsStableForTheSameRequestEnvironment(): void
    {
        // Arrange
        $session = new Session();
        $_SERVER['HTTP_USER_AGENT'] = 'fingerprint-probe/1.0';
        $_SERVER['REMOTE_ADDR']     = '203.0.113.5';

        // Act & Assert
        $this->assertSame($session->getFingerprint(true), $session->getFingerprint(true));
    }
}
