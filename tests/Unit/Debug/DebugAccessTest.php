<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugAccess;

/**
 * The signed grant that opens the toolbar on a server where it is off.
 *
 * This is the one part of the toolbar with a security argument to make, because
 * what it unlocks — the query log, the session keys and the logs of a live
 * installation — is exactly what an attacker would ask for. So the tests here
 * are mostly about refusal: an unsigned token, a token signed with another key,
 * an expired one, a malformed one, and the case that matters most — an
 * installation with no application key at all, where the correct behaviour is to
 * grant nothing rather than to fall back to a guessable secret.
 */
#[CoversClass(DebugAccess::class)]
class DebugAccessTest extends TestCase
{
    /** @var string|null The APP_KEY as the environment had it */
    private ?string $originalKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalKey = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        putenv('APP_KEY=test-key-for-debug-access');
        $_ENV['APP_KEY'] = 'test-key-for-debug-access';

        $_GET    = [];
        $_COOKIE = [];
        DebugAccess::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        $_GET    = [];
        $_COOKIE = [];
        DebugAccess::reset();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Issuing and verifying
    // -------------------------------------------------------------------------

    /**
     * A freshly issued token verifies.
     */
    public function testAnIssuedTokenVerifies(): void
    {
        // Act
        $token = DebugAccess::issue(3600);

        // Assert
        $this->assertTrue(DebugAccess::verify($token));
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $token);
    }

    /**
     * The token states its own expiry, and honours it.
     *
     * Self-contained expiry is what makes this safe to hand out: there is no
     * list of live tokens to maintain and no way to forget to revoke one.
     */
    public function testAnExpiredTokenIsRefused(): void
    {
        // Arrange — sign a timestamp that has already passed, the way a token
        // issued an hour ago would look now.
        $expiry = time() - 10;
        $token  = $expiry . '.' . hash_hmac('sha256', 'debug:' . $expiry, 'test-key-for-debug-access');

        // Act & Assert
        $this->assertFalse(DebugAccess::verify($token));
    }

    /**
     * Extending a token by editing its expiry does not work.
     *
     * The expiry is what is signed, so moving it invalidates the signature —
     * the property that stops a token from being renewed by its holder.
     */
    public function testTheExpiryCannotBeExtended(): void
    {
        // Arrange
        $token = DebugAccess::issue(60);
        [$expiry, $signature] = explode('.', $token, 2);

        // Act — same signature, a later expiry
        $forged = ((int) $expiry + 86400) . '.' . $signature;

        // Assert
        $this->assertFalse(DebugAccess::verify($forged));
    }

    /**
     * A token signed with a different key is refused.
     *
     * This is what makes rotating APP_KEY revoke every outstanding grant.
     */
    public function testATokenSignedWithAnotherKeyIsRefused(): void
    {
        // Arrange
        $expiry = time() + 3600;
        $token  = $expiry . '.' . hash_hmac('sha256', 'debug:' . $expiry, 'some-other-key');

        // Act & Assert
        $this->assertFalse(DebugAccess::verify($token));
    }

    /**
     * Malformed tokens are refused rather than raising.
     *
     * These arrive from the query string, so every shape of nonsense is an input
     * this has to survive — including the ones designed to make it throw.
     */
    public function testMalformedTokensAreRefused(): void
    {
        foreach (['', '.', 'nodot', '.sig', '123.', 'abc.def', '99999999999999999999.x'] as $token) {
            // Act & Assert
            $this->assertFalse(DebugAccess::verify($token), 'token: ' . var_export($token, true));
        }
    }

    /**
     * With no application key, nothing verifies and nothing can be issued.
     *
     * The important one. A fallback secret here would mean any installation that
     * had not run `key:generate` could have its live query log opened by anyone
     * who read this class — so the failure is total and loud.
     */
    public function testWithoutAnApplicationKeyNothingIsGranted(): void
    {
        // Arrange — a token made while a key existed
        $token = DebugAccess::issue(3600);
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);

        // Act & Assert
        $this->assertFalse(DebugAccess::verify($token), 'The old token stops working');

        $this->expectException(\RuntimeException::class);
        DebugAccess::issue(3600);
    }

    /**
     * A grant cannot be issued for longer than the ceiling.
     *
     * A debug token that lasts a month is a backdoor with a friendly name.
     */
    public function testTheLifetimeIsCapped(): void
    {
        // Act
        $token  = DebugAccess::issue(86400 * 30);
        $expiry = (int) explode('.', $token, 2)[0];

        // Assert
        $this->assertLessThanOrEqual(time() + DebugAccess::MAX_TTL, $expiry);
        $this->assertGreaterThan(time(), $expiry);
    }

    /**
     * A silly-short lifetime is raised to something usable.
     *
     * A token that expires before the page it was pasted into finishes loading
     * is indistinguishable from a broken one.
     */
    public function testAVeryShortLifetimeIsRaisedToAMinute(): void
    {
        // Act
        $token  = DebugAccess::issue(1);
        $expiry = (int) explode('.', $token, 2)[0];

        // Assert
        $this->assertGreaterThanOrEqual(time() + 59, $expiry);
    }

    // -------------------------------------------------------------------------
    // Granting
    // -------------------------------------------------------------------------

    /**
     * Nothing presented, nothing granted.
     *
     * The state of every request from every other visitor to the live server.
     */
    public function testNothingIsGrantedByDefault(): void
    {
        // Act & Assert
        $this->assertFalse(DebugAccess::isGranted());
    }

    /**
     * Redeeming a valid token grants, and the grant is visible immediately.
     *
     * "Immediately" matters: the provider that consults this runs during the
     * same request that redeemed the token, so a grant that only took effect on
     * the next one would make the link appear not to work.
     */
    public function testRedeemingAValidTokenGrantsAtOnce(): void
    {
        // Arrange
        $_GET[DebugAccess::PARAM] = DebugAccess::issue(3600);

        // Act & Assert
        $this->assertTrue(DebugAccess::isGranted());
        $this->assertSame($_GET[DebugAccess::PARAM], $_COOKIE[DebugAccess::COOKIE]);
    }

    /**
     * A cookie from an earlier request is enough on its own.
     *
     * This is the ordinary case: one link is opened, and every later page and
     * every XHR the pages make carries the grant with no query string at all.
     */
    public function testAValidCookieIsEnough(): void
    {
        // Arrange
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);

        // Act & Assert
        $this->assertTrue(DebugAccess::isGranted());
    }

    /**
     * An invalid or expired cookie is cleared, not merely ignored.
     *
     * Otherwise the browser keeps sending a dead token on every request for as
     * long as the cookie's own lifetime lasts.
     */
    public function testAnInvalidCookieIsCleared(): void
    {
        // Arrange
        $_COOKIE[DebugAccess::COOKIE] = 'forged.token';

        // Act
        $granted = DebugAccess::isGranted();

        // Assert
        $this->assertFalse($granted);
        $this->assertArrayNotHasKey(DebugAccess::COOKIE, $_COOKIE);
    }

    /**
     * `?_debug=off` ends the grant even while the cookie is still valid.
     *
     * The way out. Without it the only way to stop being in debug mode is to
     * wait for the token to expire or to clear cookies by hand.
     */
    public function testRevokingEndsAValidGrant(): void
    {
        // Arrange
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);
        $_GET[DebugAccess::PARAM]     = DebugAccess::REVOKE;

        // Act
        $granted = DebugAccess::isGranted();

        // Assert
        $this->assertFalse($granted);
        $this->assertArrayNotHasKey(DebugAccess::COOKIE, $_COOKIE);
    }

    /**
     * An invalid token in the query string does not disturb a valid cookie.
     *
     * A stale link in somebody's bookmarks should not log them out of a session
     * they legitimately hold.
     */
    public function testAnInvalidTokenDoesNotRevokeAValidCookie(): void
    {
        // Arrange
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);
        $_GET[DebugAccess::PARAM]     = 'garbage';

        // Act & Assert
        $this->assertTrue(DebugAccess::isGranted());
    }

    /**
     * The decision is made once per request.
     *
     * It can set a cookie, so re-deciding partway through a request could send a
     * second, contradictory Set-Cookie header.
     */
    public function testTheDecisionIsMadeOnce(): void
    {
        // Arrange
        $this->assertFalse(DebugAccess::isGranted());

        // Act — a token arriving after the decision was made
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);

        // Assert
        $this->assertFalse(DebugAccess::isGranted(), 'The answer was remembered');
        DebugAccess::reset();
        $this->assertTrue(DebugAccess::isGranted(), 'reset() makes the next call decide again');
    }

    /**
     * The expiry of the current grant can be read back.
     *
     * So that the toolbar, or a devpanel page, can say how long is left rather
     * than leaving the operator guessing.
     */
    public function testTheGrantReportsWhenItEnds(): void
    {
        // Arrange
        $_COOKIE[DebugAccess::COOKIE] = DebugAccess::issue(3600);

        // Act
        $expiresAt = DebugAccess::expiresAt();

        // Assert
        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(time() + 3600, $expiresAt, 5);
    }

    /**
     * With no grant there is no expiry to report.
     */
    public function testNoGrantHasNoExpiry(): void
    {
        // Act & Assert
        $this->assertNull(DebugAccess::expiresAt());
    }

    /**
     * The cookie is HttpOnly, Lax, rooted at /, and dies with its token.
     *
     * These four are the difference between a debug grant and a security
     * incident, and `setcookie()` cannot be observed under CLI — so what the
     * header *would* say is asserted directly.
     */
    public function testTheCookieIsWrittenDefensively(): void
    {
        // Arrange
        $method  = new \ReflectionMethod(DebugAccess::class, 'cookieOptions');
        $expires = time() + 3600;

        // Act
        $options = $method->invoke(null, $expires);

        // Assert
        $this->assertTrue($options['httponly'], 'No script needs to read it');
        $this->assertSame('Lax', $options['samesite'], 'Not sent with a cross-site POST');
        $this->assertSame('/', $options['path']);
        $this->assertSame($expires, $options['expires']);
    }

    /**
     * Over HTTPS the cookie is marked Secure — including behind a proxy.
     *
     * A live installation usually terminates TLS at a load balancer, so
     * `$_SERVER['HTTPS']` is empty on the application server and the forwarded
     * header is the only evidence. Missing it would send a live server's debug
     * token over plaintext on the next http:// link somebody clicked.
     */
    public function testTheCookieIsSecureOverHttpsAndBehindAProxy(): void
    {
        // Arrange
        $method = new \ReflectionMethod(DebugAccess::class, 'cookieOptions');
        $server = $_SERVER;

        try {
            // Act & Assert — plain HTTP
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $this->assertFalse($method->invoke(null, time())['secure']);

            // Act & Assert — HTTPS terminated here
            $_SERVER['HTTPS'] = 'on';
            $this->assertTrue($method->invoke(null, time())['secure']);

            // Act & Assert — the "off" spelling some SAPIs use
            $_SERVER['HTTPS'] = 'off';
            $this->assertFalse($method->invoke(null, time())['secure']);

            // Act & Assert — HTTPS terminated at a load balancer
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $this->assertTrue($method->invoke(null, time())['secure']);
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * A dedicated secret overrides the application key.
     *
     * For an installation that wants to hand out debug access without sharing
     * the key that everything else is signed and encrypted with.
     */
    public function testADedicatedSecretOverridesTheApplicationKey(): void
    {
        // Arrange — restore whatever was there, rather than leaving a value the
        // rest of the suite never asked for in the shared static store.
        $original = \Pramnos\Application\Settings::getSetting('debug_token_secret');
        \Pramnos\Application\Settings::setSetting('debug_token_secret', 'a-separate-secret', false);

        try {
            $token = DebugAccess::issue(3600);

            // Act & Assert — it verifies while the setting stands
            $this->assertTrue(DebugAccess::verify($token));

            // and stops when the setting changes, proving the setting was the key
            \Pramnos\Application\Settings::setSetting('debug_token_secret', 'changed', false);
            $this->assertFalse(DebugAccess::verify($token));
        } finally {
            \Pramnos\Application\Settings::setSetting('debug_token_secret', $original, false);
        }
    }
}
