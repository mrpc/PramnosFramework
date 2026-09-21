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

    /**
     * `tokenParameters()` is the field `getTokenField()` renders, without the markup.
     *
     * WHAT: the array's one key is the field's name and its value is the fingerprint —
     *       the same two strings the hidden input carries.
     *
     * WHY:  both were unreachable. The name is the private `$_token` and the value is
     *       `getFingerprint()`, which is also not public, so a test that wanted to POST a
     *       form had to render the input and parse it with a regular expression. That was
     *       in every application testing a form, and it is the reason those tests — the
     *       highest-value ones in an application with accounts — get skipped.
     *
     *       Asserted against `getTokenField()` rather than against a literal, because the
     *       contract is *these two agree*: a test naming the field itself would pass while
     *       the two drifted, which is the only way this can go wrong.
     */
    public function testTokenParametersMatchTheRenderedField(): void
    {
        // Arrange
        $session = \Pramnos\Http\Session::getInstance();

        // Act
        $parameters = $session->tokenParameters();
        $rendered   = $session->getTokenField();

        // Assert — one entry, and it is the one in the markup
        $this->assertCount(1, $parameters);

        $name  = array_key_first($parameters);
        $value = $parameters[$name];

        $this->assertStringContainsString('name="' . $name . '"', $rendered);
        $this->assertStringContainsString('value="' . $value . '"', $rendered);
    }

    /**
     * And the value it returns is one `checkTokenValue()` accepts.
     *
     * The other half, and the half a test actually depends on: a field that matches the
     * markup but not the check would produce a form post refused for CSRF, which reads
     * as the controller rejecting the test.
     */
    public function testTheTokenItReturnsIsAcceptedByTheCheck(): void
    {
        // Arrange
        $session = \Pramnos\Http\Session::getInstance();

        // Act
        $parameters = $session->tokenParameters();

        // Assert — `array_values()[0]` rather than `reset()`, which takes its argument
        // by reference and emits a notice for anything that is not a variable.
        $this->assertTrue($session->checkTokenValue(array_values($parameters)[0]));
    }

    /**
     * The IP-pinned variant pins to the IP.
     *
     * `getTokenField(true)` exists, so its unrendered twin has to take the same argument
     * — and a caller passing it and getting the unpinned token would have a form that is
     * refused in exactly the configuration that asked for more security.
     */
    public function testTheIpPinnedVariantDiffersFromThePlainOne(): void
    {
        // Arrange
        $session = \Pramnos\Http\Session::getInstance();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        // Act
        $plain  = array_values($session->tokenParameters())[0];
        $pinned = array_values($session->tokenParameters(true))[0];

        // Assert
        $this->assertNotSame($plain, $pinned, 'the IP is not reaching the fingerprint');
        $this->assertTrue($session->checkTokenValue($pinned, true));
    }
}
