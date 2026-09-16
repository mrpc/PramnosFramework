<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\OAuthClientException;
use Pramnos\Auth\OAuth2\Client\Provider;
use Pramnos\Auth\OAuth2\Client\TokenSet;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * The authorization-code conversation, driven against fake providers.
 *
 * This is the half where a mistake is a security bug rather than a bug, so the assertions
 * are weighted accordingly: `state` and PKCE get more attention than the happy path, and
 * the per-provider deviations get tests because they are the reason this class has options
 * at all — an option nothing exercises is an option that is wrong.
 *
 * Every test runs through `Client::fake()`. Nothing here touches a network or a database.
 */
#[CoversClass(OAuthClient::class)]
#[CoversClass(Provider::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(OAuthClientException::class)]
class OAuthClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::resetFakes();
        parent::tearDown();
    }

    /** A conventional provider — credentials in a Basic header, spaces between scopes. */
    private function provider(array $overrides = []): Provider
    {
        return new Provider(
            name: $overrides['name'] ?? 'acme',
            authorizeUrl: $overrides['authorizeUrl'] ?? 'https://acme.test/authorize',
            tokenUrl: $overrides['tokenUrl'] ?? 'https://acme.test/token',
            clientId: $overrides['clientId'] ?? 'the-client',
            clientSecret: $overrides['clientSecret'] ?? 'the-secret',
            redirectUri: $overrides['redirectUri'] ?? 'https://app.test/callback',
            scopes: $overrides['scopes'] ?? ['read', 'write'],
            authorizeParams: $overrides['authorizeParams'] ?? [],
            tokenParams: $overrides['tokenParams'] ?? [],
            authMethod: $overrides['authMethod'] ?? Provider::AUTH_BASIC,
            clientIdParam: $overrides['clientIdParam'] ?? 'client_id',
            clientSecretParam: $overrides['clientSecretParam'] ?? 'client_secret',
            scopeSeparator: $overrides['scopeSeparator'] ?? ' ',
            refreshUrl: $overrides['refreshUrl'] ?? '',
            refreshMethod: $overrides['refreshMethod'] ?? 'POST',
            refreshGrantType: $overrides['refreshGrantType'] ?? 'refresh_token',
            usePkce: $overrides['usePkce'] ?? false,
        );
    }

    /**
     * The authorize URL carries everything the provider needs and a fresh `state`.
     */
    public function testTheAuthorizationUrlCarriesTheRequiredParameters(): void
    {
        // Act
        [$url, $state, $verifier] = (new OAuthClient($this->provider()))->authorizationUrl();

        // Assert
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('https://acme.test/authorize', explode('?', $url)[0]);
        $this->assertSame('the-client', $query['client_id']);
        $this->assertSame('https://app.test/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('read write', $query['scope']);
        $this->assertSame($state, $query['state']);
        // No PKCE unless the provider asked for it.
        $this->assertSame('', $verifier);
        $this->assertArrayNotHasKey('code_challenge', $query);
    }

    /**
     * Two flows never share a `state`.
     *
     * The whole value of the parameter is that an attacker cannot predict or reuse it. A
     * constant, or one derived from the user, would satisfy every `===` in the codebase and
     * defend against nothing.
     */
    public function testEveryFlowGetsItsOwnState(): void
    {
        // Arrange
        $client = new OAuthClient($this->provider());

        // Act
        [, $first]  = $client->authorizationUrl();
        [, $second] = $client->authorizationUrl();

        // Assert — and long enough that guessing is not a strategy.
        $this->assertNotSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    /**
     * `verifyState()` rejects a mismatch, and rejects an empty expectation outright.
     *
     * The empty case is the one worth pinning. A flow whose stored state has been lost —
     * an expired session, a different browser, a callback that arrived twice — must not
     * compare `'' === ''` and pass. That is a login CSRF with the check switched off.
     */
    public function testStateVerificationRejectsAMismatchAndAnEmptyExpectation(): void
    {
        // Arrange
        $client = new OAuthClient($this->provider());

        // Act & Assert
        $this->assertTrue($client->verifyState('abc', 'abc'));
        $this->assertFalse($client->verifyState('abc', 'abd'));
        $this->assertFalse($client->verifyState('', ''));
        $this->assertFalse($client->verifyState('', 'abc'));
    }

    /**
     * PKCE sends an S256 challenge, and the verifier stays behind.
     *
     * `plain` would put the verifier in the URL, which is the interception PKCE exists to
     * prevent — so the method is asserted, not just the presence of a challenge. The
     * challenge is recomputed here from the returned verifier: that relationship is the
     * whole mechanism, and a test that only checked both fields were non-empty would pass
     * on a challenge that verifies nothing.
     */
    public function testPkceSendsAnS256ChallengeDerivedFromTheVerifier(): void
    {
        // Act
        [$url, , $verifier] = (new OAuthClient($this->provider(['usePkce' => true])))->authorizationUrl();

        // Assert
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
            'the challenge must be the SHA-256 of the verifier, or it proves nothing'
        );
        // RFC 7636 §4.1: 43 to 128 characters, unreserved alphabet only.
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
        // And the verifier itself never travels.
        $this->assertStringNotContainsString($verifier, $url);
    }

    /**
     * A successful exchange returns the tokens, with `expires_in` resolved to an instant.
     */
    public function testAnAuthorizationCodeIsExchangedForTokens(): void
    {
        // Arrange
        Client::fake([
            'https://acme.test/token' => ClientResponse::make([
                'access_token'  => 'at-1',
                'refresh_token' => 'rt-1',
                'expires_in'    => 3600,
                'scope'         => 'read write',
            ], 200),
        ]);

        // Act
        $tokens = (new OAuthClient($this->provider()))->exchange('the-code');

        // Assert
        $this->assertSame('at-1', $tokens->accessToken);
        $this->assertSame('rt-1', $tokens->refreshToken);
        $this->assertSame(['read', 'write'], $tokens->scopes);
        // A duration became an instant, once, here — so no caller needs to know when the
        // response arrived. Tolerance because the clock ticks between the two calls.
        $this->assertEqualsWithDelta(time() + 3600, $tokens->expiresAt, 5);
    }

    /**
     * The PKCE verifier is sent with the code, and only then.
     *
     * The proof half of PKCE. The challenge in the authorize URL is worthless unless the
     * verifier reaches the token endpoint — a client that generated both and sent neither
     * would pass every test about the authorize URL and be exactly as interceptable as one
     * with no PKCE at all.
     */
    public function testThePkceVerifierIsSentWithTheCode(): void
    {
        // Arrange
        $sent = null;
        Client::fake([
            'https://acme.test/token' => function (Client $request) use (&$sent): ClientResponse {
                $sent = $request;
                return ClientResponse::make(['access_token' => 'at-1'], 200);
            },
        ]);

        $client = new OAuthClient($this->provider(['usePkce' => true]));
        [, , $verifier] = $client->authorizationUrl();

        // Act
        $client->exchange('the-code', $verifier);

        // Assert
        $body = (string) (new \ReflectionProperty(Client::class, 'body'))->getValue($sent);
        $this->assertStringContainsString('code_verifier=' . $verifier, $body);
    }

    /**
     * Without PKCE there is no `code_verifier` field at all.
     *
     * An empty one is not the same as an absent one: some providers reject a request
     * carrying `code_verifier=` against an authorization that had no challenge.
     */
    public function testNoVerifierFieldIsSentWhenPkceIsOff(): void
    {
        // Arrange
        $sent = null;
        Client::fake([
            'https://acme.test/token' => function (Client $request) use (&$sent): ClientResponse {
                $sent = $request;
                return ClientResponse::make(['access_token' => 'at-1'], 200);
            },
        ]);

        // Act
        (new OAuthClient($this->provider()))->exchange('the-code');

        // Assert
        $body = (string) (new \ReflectionProperty(Client::class, 'body'))->getValue($sent);
        $this->assertStringNotContainsString('code_verifier', $body);
    }

    /**
     * A provider that refuses raises with its own error code intact.
     *
     * The code rather than the message is what a caller branches on, and `invalid_grant`
     * is the one that means "stop trying".
     */
    public function testAProviderErrorBecomesATerminalException(): void
    {
        // Arrange
        Client::fake([
            'https://acme.test/token' => ClientResponse::make([
                'error'             => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        // Act & Assert
        try {
            (new OAuthClient($this->provider()))->refresh('rt-dead');
            $this->fail('a refused refresh must not return a TokenSet');
        } catch (OAuthClientException $exception) {
            $this->assertSame('invalid_grant', $exception->error);
            $this->assertTrue($exception->isTerminal());
            // The provider's own words reach the operator unaltered.
            $this->assertStringContainsString('Token has been expired or revoked.', $exception->getMessage());
        }
    }

    /**
     * A transient failure is not terminal, so the next scheduled run tries again.
     *
     * The distinction that decides whether a connection is killed. Treating a 503 as
     * terminal makes every user re-authorise over somebody else's bad afternoon.
     */
    public function testAServerErrorIsNotTerminal(): void
    {
        // Arrange
        Client::fake([
            'https://acme.test/token' => ClientResponse::make(['error' => 'temporarily_unavailable'], 503),
        ]);

        // Act & Assert
        try {
            (new OAuthClient($this->provider()))->refresh('rt-1');
            $this->fail('a 503 must still raise');
        } catch (OAuthClientException $exception) {
            $this->assertFalse($exception->isTerminal());
        }
    }

    /**
     * A response that is not a JSON object says so, rather than "no access token".
     *
     * The usual cause is a proxy error page or a captive portal. "The provider returned no
     * access token" sends whoever reads it to check scopes and credentials, which is the
     * wrong half of the system entirely.
     */
    public function testANonJsonResponseIsReportedAsOne(): void
    {
        // Arrange
        Client::fake([
            'https://acme.test/token' => ClientResponse::make('<html>502 Bad Gateway</html>', 502),
        ]);

        // Act & Assert
        try {
            (new OAuthClient($this->provider()))->exchange('code');
            $this->fail('an HTML error page must not parse as a token response');
        } catch (OAuthClientException $exception) {
            $this->assertSame('invalid_response', $exception->error);
            $this->assertStringContainsString('not a JSON object', $exception->getMessage());
            $this->assertSame(502, $exception->status);
        }
    }

    /**
     * A provider on a private address is called, not refused.
     *
     * `Security\OutboundUrl` guards URLs a *visitor* supplied, and it rejects anything
     * resolving inside this network. A token endpoint is configuration, and an identity
     * provider on a private address is an ordinary arrangement — Keycloak on an internal
     * host, an enterprise directory behind a private endpoint. An earlier version of this
     * class applied the guard here and made every one of those impossible, with an error
     * that read as a network fault rather than as a policy.
     *
     * Asserted rather than left implicit because the guard is the obvious thing for the
     * next reader to add.
     */
    public function testAProviderOnAPrivateAddressIsNotRefused(): void
    {
        // Arrange — the shape of an on-premise identity provider.
        Client::fake([
            'https://keycloak.internal/token' => ClientResponse::make(['access_token' => 'at-1'], 200),
        ]);

        // Act
        $tokens = (new OAuthClient($this->provider(['tokenUrl' => 'https://keycloak.internal/token'])))
            ->exchange('code');

        // Assert
        $this->assertSame('at-1', $tokens->accessToken);
    }
}
