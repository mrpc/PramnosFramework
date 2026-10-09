<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Grants;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A short-lived access token exchanged for a long-lived one (RFC 8693 token exchange).
 *
 * The client presents an active access token it was issued, as `subject_token`, and receives
 * one for the same user and scopes lasting {@see LIFETIME}, with no refresh token. The token it
 * presented is revoked. `exchange_token` is accepted as the grant type beside the RFC's URN, for
 * clients written against the older name.
 */
class TokenExchangeGrant extends AbstractGrant
{
    use AuthenticatesClient;

    /** The exchanged token's lifetime: sixty days. */
    public const LIFETIME = 'P60D';

    private const ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'urn:ietf:params:oauth:grant-type:token-exchange';
    }

    protected function grantTypeNames(): array
    {
        return [$this->getIdentifier(), 'exchange_token'];
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        $client  = $this->validateClient($request);
        $subject = $this->getRequestParameter('subject_token', $request);
        if (!is_string($subject) || $subject === '') {
            throw OAuthServerException::invalidRequest('subject_token');
        }
        $type = $this->getRequestParameter('subject_token_type', $request, self::ACCESS_TOKEN_TYPE);
        if ($type !== self::ACCESS_TOKEN_TYPE) {
            throw new OAuthServerException('Only an access token can be exchanged.', 3, 'invalid_request', 400);
        }

        $row = $this->activeAccessToken($subject, (string) $client->getIdentifier());
        if ($row === null) {
            throw OAuthServerException::invalidGrant('The subject_token is not an active access token of this client.');
        }

        // Revoked before the new one is issued: a failure in between leaves the user signed out
        // of this client rather than holding two tokens.
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#usertokens')
            ->where('tokenid', (int) $row['tokenid'])->where('status', 1)->update(['status' => 0]);

        $accessToken = $this->issueAccessToken(
            new \DateInterval(self::LIFETIME),
            $client,
            (string) $row['userid'],
            $this->scopesFrom((string) ($row['scope'] ?? ''), $client)
        );
        $responseType->setAccessToken($accessToken);

        return $responseType;
    }

    /**
     * The active access-token row a presented token is, issued to this client; null otherwise.
     *
     * Looked up as presented, then by its `jti` — a token from the token endpoint is stored by it.
     *
     * @return array<string, mixed>|null
     */
    private function activeAccessToken(string $token, string $clientId): ?array
    {
        $candidates = [$token];
        $claims     = \Pramnos\Auth\JWT::decodeUnverified($token);
        if (is_object($claims) && is_string($claims->jti ?? null) && $claims->jti !== '') {
            $candidates[] = $claims->jti;
        }

        foreach ($candidates as $stored) {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('usertokens ut')
                ->join('applications a', 'ut.applicationid = a.appid')
                ->select('ut.tokenid, ut.userid, ut.scope, ut.expires')
                ->where('ut.token_lookup', \Pramnos\User\Token::lookup($stored))
                ->where('ut.tokentype', 'access_token')
                ->where('ut.status', 1)
                ->where('a.apikey', $clientId)
                ->first();
            if ($result && $result->numRows > 0
                && ((int) $result->fields['expires'] === 0 || (int) $result->fields['expires'] > time())
            ) {
                return (array) $result->fields;
            }
        }

        return null;
    }
}
