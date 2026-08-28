<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\Collectors\AuthCollector;

/**
 * Who the request was, and what convinced the server of it.
 *
 * "It worked and then it stopped" is almost always one of three things: the
 * credential expired, the client sent a different one than it believes it sent,
 * or it sent none and the server fell back to a session cookie that exists only
 * on the developer's own machine. Each of those is a different afternoon, and
 * none of them was visible before.
 *
 * The rule these tests exist to hold is the last one: **the credential itself
 * never appears in the payload**. That payload is attached to responses and
 * lands in a browser's network log, so a live token in it would hand out the
 * very thing the panel exists to explain.
 */
#[CoversClass(AuthCollector::class)]
class AuthCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        // Request-scoped identity is process-wide in a test run, and a sealed
        // caller from one test must not answer for the next.
        \Pramnos\Http\RequestIdentity::reset();
        $_SESSION = [];
        foreach (['HTTP_ACCESSTOKEN', 'HTTP_AUTHORIZATION', 'HTTP_APIKEY', 'HTTP_USERAUTH'] as $header) {
            unset($_SERVER[$header]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * With nothing presented, the answer is "nobody, by nothing" — stated rather
     * than left blank, because a blank panel reads as a panel that failed.
     */
    public function testAnAnonymousRequestSaysSo(): void
    {
        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertFalse($data['user']['authenticated']);
        $this->assertSame('none', $data['credential']);
        $this->assertNull($data['token']);
    }

    /**
     * A signed-in user is named, with the id and type that decide what they can
     * do.
     */
    public function testASignedInUserIsNamed(): void
    {
        // Arrange — sealed, which is how an authenticated API request declares
        // itself. Putting a user in $_SESSION was the old way and is precisely
        // what stopped meaning "authenticated": a website cookie must not
        // identify an API call.
        \Pramnos\Http\RequestIdentity::seal(
            (object) ['userid' => 42, 'username' => 'alice', 'usertype' => 90],
            'accessToken'
        );

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertTrue($data['user']['authenticated']);
        $this->assertSame(42, $data['user']['userid']);
        $this->assertSame('alice', $data['user']['username']);
        $this->assertSame(90, $data['user']['usertype']);
    }

    /**
     * The two ways of sending an access token are told apart.
     *
     * "The token" means the `accessToken` header to one developer and
     * `Authorization: Bearer` to another, and a client sending the one the
     * server is not reading looks exactly like a client sending nothing.
     */
    public function testTheTwoWaysOfSendingATokenAreDistinguished(): void
    {
        // Arrange — the framework's own header
        $_SERVER['HTTP_ACCESSTOKEN'] = $this->jwt(['sub' => 'u1', 'exp' => time() + 600]);

        // Act
        $viaHeader = (new AuthCollector())->collect();

        // Arrange — the RFC one
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt(['sub' => 'u1', 'exp' => time() + 600]);

        // Act
        $viaBearer = (new AuthCollector())->collect();

        // Assert
        $this->assertSame('accessToken', $viaHeader['credential']);
        $this->assertSame('accessToken header', $viaHeader['source']);
        $this->assertSame('accessToken', $viaBearer['credential']);
        $this->assertSame('Authorization: Bearer', $viaBearer['source']);
    }

    /**
     * A token is described by its claims and never by its value.
     *
     * This is the test that matters: everything else here is a convenience, and
     * this one is the reason the collector can exist at all.
     */
    public function testTheTokenItselfNeverAppearsInThePayload(): void
    {
        // Arrange
        $token = $this->jwt(['sub' => 'u1', 'exp' => time() + 600, 'secret_claim' => 'do-not-publish']);
        $_SERVER['HTTP_ACCESSTOKEN'] = $token;

        // Act
        $data = (new AuthCollector())->collect();
        $encoded = json_encode($data);

        // Assert — not the token, and not its signature
        $this->assertStringNotContainsString($token, (string) $encoded);
        $this->assertStringNotContainsString(explode('.', $token)[2], (string) $encoded);
        // And not a claim the framework does not vouch for: an application may
        // put anything in a token, including data it would not want in a log.
        $this->assertStringNotContainsString('do-not-publish', (string) $encoded);
        // What it does carry is the identity and the expiry
        $this->assertSame('jwt', $data['token']['format']);
        $this->assertSame('u1', $data['token']['claims']['sub']);
    }

    /**
     * The expiry travels as the token's own absolute timestamp.
     *
     * Not as "seconds remaining": the response may sit in a browser for a while
     * before anybody opens the panel, and a countdown that started when the
     * request was made would be reassuring and wrong.
     */
    public function testTheExpiryTravelsAsAnAbsoluteTimestamp(): void
    {
        // Arrange
        $expiry = time() + 900;
        $_SERVER['HTTP_ACCESSTOKEN'] = $this->jwt(['sub' => 'u1', 'iat' => time(), 'exp' => $expiry]);

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame($expiry, $data['token']['expires_at']);
        $this->assertIsInt($data['token']['issued_at']);
    }

    /**
     * A token that has already expired is still described.
     *
     * It is the one worth looking at: the signature is not verified here on
     * purpose, because this reports what the client sent rather than performing
     * a second authentication. Whether it was *accepted* shows up as the status
     * of the request beside it.
     */
    public function testAnExpiredTokenIsStillDescribed(): void
    {
        // Arrange
        $expired = time() - 60;
        $_SERVER['HTTP_ACCESSTOKEN'] = $this->jwt(['sub' => 'u1', 'exp' => $expired]);

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame($expired, $data['token']['expires_at']);
    }

    /**
     * An opaque credential — a random string looked up in a table — is a
     * perfectly good token with nothing inside it to read, and saying so beats
     * an empty claims table.
     */
    public function testAnOpaqueTokenIsReportedAsSuch(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCESSTOKEN'] = 'not-a-jwt-at-all';

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame('opaque', $data['token']['format']);
    }

    /**
     * An API key is named as one, and carries nothing else — the key is a
     * credential in itself, and there is nothing about it to describe that is
     * not also a way to use it.
     */
    public function testAnApiKeyIsNamedButNotShown(): void
    {
        // Arrange
        $_SERVER['HTTP_APIKEY'] = 'super-secret-key';

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame('apiKey', $data['credential']);
        $this->assertNull($data['token']);
        $this->assertStringNotContainsString('super-secret-key', (string) json_encode($data));
    }

    /**
     * The deprecated `userAuth` header is named as deprecated where a reader
     * will see it — finding an application still sending a password hash is the
     * whole reason to report it.
     */
    public function testTheLegacyHeaderIsFlaggedAsDeprecated(): void
    {
        // Arrange
        $_SERVER['HTTP_USERAUTH'] = 'a-password-hash';

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame('userAuth', $data['credential']);
        $this->assertStringContainsString('deprecated', $data['source']);
        $this->assertStringNotContainsString('a-password-hash', (string) json_encode($data));
    }

    /**
     * The credential reported is the one that will be used, not the first one
     * present — the collector checks in the middleware's own order.
     *
     * An API call carrying both a key and a token authenticates by the token,
     * and a panel that said "apiKey" would send somebody to look at the wrong
     * thing.
     */
    public function testTheCredentialReportedIsTheOneThatWins(): void
    {
        // Arrange — both presented
        $_SERVER['HTTP_APIKEY']      = 'a-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = $this->jwt(['sub' => 'u1', 'exp' => time() + 60]);

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertSame('accessToken', $data['credential']);
    }

    /**
     * A JWT with the given claims. Unsigned in any meaningful sense — nothing
     * here verifies it, which is the point.
     *
     * @param array<string, mixed> $claims
     */
    private function jwt(array $claims): string
    {
        $encode = static fn(array $part): string => rtrim(
            strtr(base64_encode((string) json_encode($part)), '+/', '-_'),
            '='
        );

        return $encode(['alg' => 'HS256', 'typ' => 'JWT'])
            . '.' . $encode($claims)
            . '.' . 'signature-that-nothing-here-checks';
    }

    /**
     * An anonymous request with nothing pending carries no second-factor block.
     *
     * The reads behind it are queries. A page load by somebody who is not signed in and is
     * not half-way through signing in has no second factor to describe, and should not pay
     * to find that out.
     */
    public function testAnAnonymousRequestCarriesNoSecondFactorBlock(): void
    {
        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertNull($data['twofactor']);
    }

    /**
     * A reading that fails says so, instead of reporting "no second factor".
     *
     * There is no database in a unit test, so this is the failure path — and the failure
     * path is the one worth pinning here. "Nothing enrolled" and "could not tell" are
     * different answers, and a panel that conflates them would have a developer chasing an
     * enrolment that was never read.
     *
     * The second assertion holds on both paths: no code, ever. The panel's whole payload is
     * attached to responses and ends up in bug reports, and a live six-digit code in a bug
     * report is a live six-digit code. The state this describes — whether a code exists, how
     * long the resend has — is asserted for real against a database in the application's own
     * suite.
     */
    public function testAFailedReadingIsReportedRatherThanReadAsNoFactor(): void
    {
        // Arrange — the session a half-finished login leaves behind
        $_SESSION['loginflow_pending_userid']     = 4242;
        $_SESSION['loginflow_pending_identifier'] = 'alice';
        $_SESSION['loginflow_pending_time']       = time() - 12;

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertIsArray($data['twofactor']);
        $this->assertArrayHasKey('error', $data['twofactor'],
            'with no database the reading cannot be done, and saying so is the answer');

        $encoded = json_encode($data['twofactor']);
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', (string) $encoded,
            'no six-digit code may reach the payload');
        $this->assertStringNotContainsStringIgnoringCase('secret', (string) $encoded);
    }

    /**
     * Codes are absent unless the installation asked for them.
     *
     * The default, and the one that matters: this payload rides on responses, sits in a
     * network log and gets pasted into bug reports. An installation that has not set
     * `debug.reveal_factor_codes` must never find a live code in there — including one that
     * arrived because somebody enabled debugging to investigate something else.
     */
    public function testCodesAreNotRevealedByDefault(): void
    {
        // Arrange — a pending step-up, and no application asking for codes
        $_SESSION['loginflow_pending_userid'] = 4242;

        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertArrayNotHasKey('revealed', (array) $data['twofactor']);
    }
}
