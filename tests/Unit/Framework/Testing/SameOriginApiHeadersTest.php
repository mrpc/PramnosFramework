<?php

declare(strict_types=1);

namespace Tests\Unit\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The header a page's own JavaScript needs to call this application's API.
 *
 * `sameOriginApiHeaders()` is a helper the framework ships for tests in *applications*, which is
 * why its four statements had never run here: nothing in the framework's own suite had called it,
 * so a helper offered to every project was untested.
 *
 * What it encodes is the rule `ApiAuthMiddleware` enforces: a page presents no API key — it
 * presents its session **plus** a CSRF token, and the token is what distinguishes a call the page
 * made from a call somebody else's page made with the visitor's cookie.
 */
#[CoversClass(BaseTestCase::class)]
class SameOriginApiHeadersTest extends BaseTestCase
{
    /**
     * The header is `X-CSRF-Token`, spelled as a `$_SERVER` key.
     *
     * `HTTP_X_CSRF_TOKEN` rather than `X-CSRF-Token`, because a test dispatcher hands the request
     * a `$_SERVER` array rather than a header list — and a helper returning the wire name would
     * produce a request the middleware never sees the token on.
     */
    public function testTheHeaderIsSpelledAsAServerKey(): void
    {
        // Act
        $headers = $this->sameOriginApiHeaders();

        // Assert
        $this->assertArrayHasKey('HTTP_X_CSRF_TOKEN', $headers);
        $this->assertArrayNotHasKey('X-CSRF-Token', $headers, 'the wire name would never be read');
    }

    /**
     * The token is the session's own, not a fresh one.
     *
     * The middleware compares what arrives against the session's token, so a helper that minted a
     * new one would produce a request that fails the check it exists to pass.
     */
    public function testTheTokenIsTheSessionsOwn(): void
    {
        // Act
        $headers = $this->sameOriginApiHeaders();

        // Assert
        $this->assertSame(
            \Pramnos\Http\Session::getInstance()->getCsrfToken(),
            $headers['HTTP_X_CSRF_TOKEN'],
            'the helper minted a token the session does not know'
        );
    }

    /**
     * The token is not empty.
     *
     * An empty value would be sent, accepted as "present", and then fail the comparison — a test
     * failing on authentication for a reason that has nothing to do with what it was testing.
     */
    public function testTheTokenIsNotEmpty(): void
    {
        // Act
        $headers = $this->sameOriginApiHeaders();

        // Assert
        $this->assertNotSame('', $headers['HTTP_X_CSRF_TOKEN']);
    }

    /** And it carries nothing else — a page presents no API key. */
    public function testItCarriesNothingElse(): void
    {
        // Act
        $headers = $this->sameOriginApiHeaders();

        // Assert
        $this->assertSame(['HTTP_X_CSRF_TOKEN'], array_keys($headers));
    }
}
