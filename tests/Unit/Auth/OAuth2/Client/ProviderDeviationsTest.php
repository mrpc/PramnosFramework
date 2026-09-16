<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\Provider;
use Pramnos\Auth\OAuth2\Client\TokenSet;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * The places a real provider is not quite OAuth2.
 *
 * Every option on {@see Provider} exists because some large platform does this rather than
 * the thing the specification says, and each one is tested here against the shape that
 * motivated it. An option nothing exercises is an option that is wrong — it will be
 * discovered wrong by whoever first configures the provider it was added for, in
 * production, against an endpoint they cannot debug.
 *
 * The provider names in these tests are the real ones, because the deviations are theirs.
 * Nothing here contacts them.
 */
#[CoversClass(Provider::class)]
#[CoversClass(OAuthClient::class)]
#[CoversClass(TokenSet::class)]
class ProviderDeviationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::resetFakes();
        parent::tearDown();
    }

    /**
     * Credentials in the body, under names the provider chose.
     *
     * TikTok calls `client_id` `client_key`, and wants both credentials as form fields —
     * a `Basic` header gets `invalid_client`, which reads as a wrong secret rather than as
     * a correct secret in the wrong place. That is a long afternoon.
     */
    public function testCredentialsCanGoInTheBodyUnderRenamedFields(): void
    {
        // Arrange — capture what was actually sent.
        $sent = null;
        Client::fake([
            'https://open.tiktokapis.com/*' => function (Client $request) use (&$sent): ClientResponse {
                $sent = $request;
                return ClientResponse::make(['access_token' => 'at-1'], 200);
            },
        ]);

        $provider = new Provider(
            name: 'tiktok',
            authorizeUrl: 'https://www.tiktok.com/v2/auth/authorize/',
            tokenUrl: 'https://open.tiktokapis.com/v2/oauth/token/',
            clientId: 'the-key',
            clientSecret: 'the-secret',
            redirectUri: 'https://app.test/callback',
            authMethod: Provider::AUTH_BODY,
            clientIdParam: 'client_key',
        );

        // Act
        (new OAuthClient($provider))->exchange('code-1');

        // Assert — the renamed field carries the id, and there is no Basic header.
        $body = $this->bodyOf($sent);
        $this->assertStringContainsString('client_key=the-key', $body);
        $this->assertStringContainsString('client_secret=the-secret', $body);
        $this->assertStringNotContainsString('client_id=', $body);
        $this->assertArrayNotHasKey('Authorization', $this->headersOf($sent));
    }

    /**
     * The conventional provider does get a Basic header.
     *
     * The positive control for the test above. A negative assertion on its own — "there
     * is no Authorization header" — passes just as well when the capture has broken, the
     * fake never fired, or the header is stored somewhere this test does not look. One of
     * the two is measuring the code; without this one, neither is.
     */
    public function testTheDefaultProviderSendsCredentialsAsABasicHeader(): void
    {
        // Arrange
        $sent = null;
        Client::fake([
            'https://acme.test/*' => function (Client $request) use (&$sent): ClientResponse {
                $sent = $request;
                return ClientResponse::make(['access_token' => 'at-1'], 200);
            },
        ]);

        $provider = new Provider(
            name: 'acme',
            authorizeUrl: 'https://acme.test/authorize',
            tokenUrl: 'https://acme.test/token',
            clientId: 'the-client',
            clientSecret: 'the-secret',
            redirectUri: 'https://app.test/callback',
        );

        // Act
        (new OAuthClient($provider))->exchange('code-1');

        // Assert — RFC 6749 §2.3.1's form, and the credentials are not also in the body.
        $headers = $this->headersOf($sent);
        $this->assertSame(
            'Basic ' . base64_encode('the-client:the-secret'),
            $headers['Authorization'] ?? ''
        );
        $this->assertStringNotContainsString('client_secret', $this->bodyOf($sent));
    }

    /**
     * The authorize URL uses the renamed field too.
     *
     * Asserted separately: the two code paths build their parameters independently, and a
     * provider that accepted `client_key` at the token endpoint and was sent `client_id`
     * at the authorize endpoint would fail at the first step, before any of the rest of
     * this had a chance to be right.
     */
    public function testTheAuthorizeUrlUsesTheRenamedClientIdField(): void
    {
        // Arrange
        $provider = new Provider(
            name: 'tiktok',
            authorizeUrl: 'https://www.tiktok.com/v2/auth/authorize/',
            tokenUrl: 'https://open.tiktokapis.com/v2/oauth/token/',
            clientId: 'the-key',
            clientSecret: 'the-secret',
            redirectUri: 'https://app.test/callback',
            clientIdParam: 'client_key',
        );

        // Act
        [$url] = (new OAuthClient($provider))->authorizationUrl();

        // Assert
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('the-key', $query['client_key']);
        $this->assertArrayNotHasKey('client_id', $query);
    }

    /**
     * A refresh that is a GET, to a different endpoint, with a different grant name.
     *
     * Instagram's long-lived token is refreshed with
     * `GET /refresh_access_token?grant_type=ig_refresh_token&access_token=…` — three
     * deviations at once, and none of them is expressible by changing a URL alone.
     */
    public function testARefreshCanBeAGetToItsOwnEndpointWithItsOwnGrantName(): void
    {
        // Arrange
        $seen = null;
        Client::fake([
            'https://graph.instagram.com/*' => function (Client $request) use (&$seen): ClientResponse {
                $seen = $request;
                return ClientResponse::make(['access_token' => 'at-2', 'expires_in' => 5184000], 200);
            },
        ]);

        $provider = new Provider(
            name: 'instagram',
            authorizeUrl: 'https://api.instagram.com/oauth/authorize',
            tokenUrl: 'https://api.instagram.com/oauth/access_token',
            clientId: 'id',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/callback',
            refreshUrl: 'https://graph.instagram.com/refresh_access_token',
            refreshMethod: 'GET',
            refreshGrantType: 'ig_refresh_token',
        );

        // Act
        $tokens = (new OAuthClient($provider))->refresh('rt-1');

        // Assert — the fields travelled as query parameters, because a GET has no body.
        $url = $this->urlOf($seen);
        $this->assertStringStartsWith('https://graph.instagram.com/refresh_access_token?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('ig_refresh_token', $query['grant_type']);
        $this->assertSame('rt-1', $query['refresh_token']);
        // Sixty days, which is the whole reason an idle connection needs a scheduled refresh.
        $this->assertEqualsWithDelta(time() + 5184000, $tokens->expiresAt, 5);
    }

    /**
     * A comma-separated scope list, for the providers that use one.
     */
    public function testScopesCanBeCommaSeparated(): void
    {
        // Arrange
        $provider = new Provider(
            name: 'meta',
            authorizeUrl: 'https://www.facebook.com/v21.0/dialog/oauth',
            tokenUrl: 'https://graph.facebook.com/v21.0/oauth/access_token',
            clientId: 'id',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/callback',
            scopes: ['pages_show_list', 'pages_read_engagement'],
            scopeSeparator: ',',
        );

        // Act
        [$url] = (new OAuthClient($provider))->authorizationUrl();

        // Assert
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('pages_show_list,pages_read_engagement', $query['scope']);
    }

    /**
     * A refresh response that omits the refresh token keeps the old one.
     *
     * **The single most expensive mistake available here.** Some providers rotate the
     * refresh token on every use, some return the same one, and some return none at all
     * and mean "keep using yours". Overwriting with the empty string in that last case
     * throws away the only thing that can renew the connection — and nothing fails until
     * the access token expires, hours or days later, in a scheduled job, far from the code
     * that lost it. The user is simply disconnected, for no reason they did anything to
     * cause.
     */
    public function testARefreshThatOmitsTheRefreshTokenKeepsTheExistingOne(): void
    {
        // Arrange — what we had, and what came back.
        $previous = new TokenSet(
            accessToken: 'at-old',
            refreshToken: 'rt-keep-me',
            expiresAt: time() + 60,
            scopes: ['read', 'write'],
        );
        $fresh = TokenSet::fromResponse(['access_token' => 'at-new', 'expires_in' => 3600]);

        // Act
        $merged = $fresh->mergedInto($previous);

        // Assert
        $this->assertSame('at-new', $merged->accessToken);
        $this->assertSame('rt-keep-me', $merged->refreshToken);
        // An omitted scope list is an omission, not a revocation.
        $this->assertSame(['read', 'write'], $merged->scopes);
    }

    /**
     * A rotated refresh token replaces the old one.
     *
     * The other half, and it has to be the other way round: keeping the old token after a
     * provider rotated it presents something already retired at the next refresh, which is
     * the same dead connection reached from the opposite direction.
     */
    public function testARotatedRefreshTokenReplacesTheOldOne(): void
    {
        // Arrange
        $previous = new TokenSet(accessToken: 'at-old', refreshToken: 'rt-old');
        $fresh    = TokenSet::fromResponse([
            'access_token'  => 'at-new',
            'refresh_token' => 'rt-new',
            'scope'         => 'read',
        ]);

        // Act
        $merged = $fresh->mergedInto($previous);

        // Assert
        $this->assertSame('rt-new', $merged->refreshToken);
        $this->assertSame(['read'], $merged->scopes);
    }

    /**
     * A scope list that arrives as an array, and one that arrives comma-separated.
     *
     * The specification says a space-delimited string. A minority send a JSON array and a
     * minority use commas, and a connection whose scopes parsed as one long string fails
     * every `hasScope()` check an application makes — which reads as the user having
     * declined a permission they granted.
     */
    public function testScopesParseWhicheverWayTheProviderSendsThem(): void
    {
        // Act & Assert — a list…
        $this->assertSame(
            ['read', 'write'],
            TokenSet::fromResponse(['access_token' => 'a', 'scope' => ['read', 'write']])->scopes
        );

        // …and commas.
        $this->assertSame(
            ['read', 'write'],
            TokenSet::fromResponse(['access_token' => 'a', 'scope' => 'read,write'])->scopes
        );

        // A token with no scope information at all is not a token with no scopes granted;
        // it is a provider that does not report them, and an empty list is the honest answer.
        $this->assertSame([], TokenSet::fromResponse(['access_token' => 'a'])->scopes);
    }

    /**
     * `expires_in` sent as a string still becomes an instant.
     *
     * Several providers quote it. `"3600" + time()` happens to work in PHP; the danger is
     * the cast being added later by someone who assumes it was always an int, so it is
     * pinned.
     */
    public function testAQuotedExpiresInIsStillUnderstood(): void
    {
        // Act
        $tokens = TokenSet::fromResponse(['access_token' => 'a', 'expires_in' => '3600']);

        // Assert
        $this->assertEqualsWithDelta(time() + 3600, $tokens->expiresAt, 5);
    }

    /**
     * A token the provider says does not expire has no expiry, rather than one in the past.
     *
     * `expires_at = time() + 0` would be "expired the moment it arrived", and the
     * scheduled refresh would then try to renew it on every run for ever.
     */
    public function testATokenWithNoStatedLifetimeHasNoExpiry(): void
    {
        // Act & Assert
        $this->assertNull(TokenSet::fromResponse(['access_token' => 'a'])->expiresAt);
    }

    /** The request body a fake captured. */
    private function bodyOf(?Client $request): string
    {
        $property = new \ReflectionProperty(Client::class, 'body');

        return (string) $property->getValue($request);
    }

    /** @return array<string,string> */
    private function headersOf(?Client $request): array
    {
        $property = new \ReflectionProperty(Client::class, 'headers');

        return (array) $property->getValue($request);
    }

    private function urlOf(?Client $request): string
    {
        $property = new \ReflectionProperty(Client::class, 'url');

        return (string) $property->getValue($request);
    }
}
