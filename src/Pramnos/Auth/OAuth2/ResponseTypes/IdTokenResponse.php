<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\ResponseTypes;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/**
 * The token response, with an OpenID Connect ID token when the `openid` scope was granted.
 *
 * Signed RS256 with the key that signs the access tokens, and headed with the `kid` the JWKS
 * publishes, so a relying party verifies both the same way. Claims:
 *
 * - `iss` — the discovery document's `issuer`, exactly;
 * - `sub`, `aud` (the client), `iat`, `exp` (the access token's expiry), and `auth_time` for a code;
 * - `nonce`, when the client sent one to `/oauth/authorize` — carried with the code;
 * - the claims the granted scopes give, as `/oauth/userinfo` gives them ({@see \Pramnos\Auth\OAuth2\UserClaims}).
 *
 * A token with no user (client credentials) gets no ID token: there is nobody to identify.
 */
class IdTokenResponse extends BearerTokenResponse
{
    /** The `kid` the JWKS publishes for the signing key. */
    public const KEY_ID = 'auth-key-1';

    /** @var array{nonce?: string, auth_time?: int} */
    private array $context = [];

    public function __construct(private string $signingKeyPath, private string $issuer)
    {
    }

    /**
     * What the authorization request said, for the code being redeemed.
     *
     * @param array{nonce?: string, auth_time?: int} $context
     */
    public function setAuthorizationContext(array $context): void
    {
        $this->context = $context;
    }

    /** @return array<string, string> */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $accessToken->getScopes());
        $userId = $accessToken->getUserIdentifier();

        if (!in_array('openid', $scopes, true) || $userId === null || $userId === '' || !is_readable($this->signingKeyPath)) {
            return [];
        }

        $now    = time();
        $claims = [
            'iss'       => $this->issuer,
            'sub'       => (string) $userId,
            'aud'       => (string) $accessToken->getClient()->getIdentifier(),
            'iat'       => $now,
            'exp'       => $accessToken->getExpiryDateTime()->getTimestamp(),
        ];
        // Known for a code; a refresh has no sign-in behind it to date, so it says nothing.
        if ((int) ($this->context['auth_time'] ?? 0) > 0) {
            $claims['auth_time'] = (int) $this->context['auth_time'];
        }
        if (($this->context['nonce'] ?? '') !== '') {
            $claims['nonce'] = (string) $this->context['nonce'];
        }
        $claims += \Pramnos\Auth\OAuth2\UserClaims::for((int) $userId, $scopes);

        return [
            'id_token' => \Pramnos\Auth\JWT::encode($claims, (string) file_get_contents($this->signingKeyPath), 'RS256', self::KEY_ID),
        ];
    }
}
