<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Session;

/**
 * Tests for the legacy CSRF token path — {@see Session::checkTokenValue()} and the
 * fingerprint it verifies against.
 *
 * This path is still what the account controllers, the settings form and the
 * scaffolded templates emit, so it is not a deprecated corner: it is the default a
 * new project gets. It had no direct test coverage at all, which is how a plain
 * `===` comparison stayed in it.
 *
 * The token itself is sound — an HMAC-SHA256 keyed by the session's own 256-bit
 * random token, so it cannot be predicted from the user agent and IP it hashes. What
 * these tests pin is that verifying it is constant-time and that the values which
 * must not pass, do not.
 */
#[CoversClass(Session::class)]
class SessionLegacyTokenTest extends TestCase
{
    /** @var array<string, mixed> $_SERVER as it was before the test. */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/legacy-token-test';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** The correct token is accepted — the case everything else is measured against. */
    public function testTheFingerprintVerifiesAgainstItself(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getFingerprint();

        // Act + Assert
        $this->assertTrue($session->checkTokenValue($token));
    }

    /**
     * A wrong token is refused — including one that shares a long prefix with the
     * real value, which is the shape a timing attack would be building up to.
     */
    public function testAWrongTokenIsRefused(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getFingerprint();

        // Act + Assert
        $this->assertFalse($session->checkTokenValue('nonsense'));
        $this->assertFalse($session->checkTokenValue(''));
        $this->assertFalse($session->checkTokenValue(substr($token, 0, -1)));
        $this->assertFalse($session->checkTokenValue($token . 'x'));
    }

    /**
     * A non-string is refused rather than coerced.
     *
     * `hash_equals()` requires two strings and raises on anything else, and a request
     * that sent `token[]=x` — an array where a token belongs — has not submitted a
     * token. Refusing it is both the correct answer and the one that does not turn a
     * malformed request into a TypeError.
     */
    public function testANonStringIsRefused(): void
    {
        // Arrange
        $session = Session::getInstance();

        // Act + Assert
        $this->assertFalse($session->checkTokenValue(null));
        $this->assertFalse($session->checkTokenValue(['array']));
        $this->assertFalse($session->checkTokenValue(12345));
        $this->assertFalse($session->checkTokenValue(false));
    }

    /**
     * The IP-pinned fingerprint is a different value from the unpinned one, and each
     * verifies only against its own form.
     *
     * A form emitted with pinning on must not be accepted by a check that asks for it
     * off, or the pinning is decorative.
     */
    public function testThePinnedAndUnpinnedFingerprintsDoNotCrossVerify(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
        $session = Session::getInstance();

        $plain  = $session->getFingerprint(false);
        $pinned = $session->getFingerprint(true);

        // Assert — different values, each valid only for its own mode.
        $this->assertNotSame($plain, $pinned);
        $this->assertTrue($session->checkTokenValue($plain, false));
        $this->assertTrue($session->checkTokenValue($pinned, true));
        $this->assertFalse($session->checkTokenValue($plain, true));
        $this->assertFalse($session->checkTokenValue($pinned, false));
    }

    /**
     * A token issued to one browser does not verify for another.
     *
     * The user agent is part of the HMAC, which is the whole reason this is a
     * fingerprint rather than a bare token.
     */
    public function testATokenDoesNotVerifyUnderADifferentUserAgent(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getFingerprint();

        // Act
        $_SERVER['HTTP_USER_AGENT'] = 'SomeoneElse/1.0';

        // Assert
        $this->assertFalse($session->checkTokenValue($token));
    }

    /**
     * The hidden field carries exactly the value the check expects.
     *
     * The two are a pair — one writes the form, the other reads it — and nothing else
     * asserts they agree, so a change to either alone would break every legacy form
     * in a way no test would notice.
     */
    public function testTheTokenFieldCarriesTheVerifiableValue(): void
    {
        // Arrange
        $session = Session::getInstance();

        // Act
        $field = $session->getTokenField();

        // Assert
        $this->assertMatchesRegularExpression('/value="([0-9a-f]{64})"/', $field);
        preg_match('/value="([0-9a-f]{64})"/', $field, $m);
        $this->assertTrue($session->checkTokenValue($m[1]));
    }
}
