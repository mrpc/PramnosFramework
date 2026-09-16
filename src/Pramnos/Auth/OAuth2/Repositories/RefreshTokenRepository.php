<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * OAuth2 Refresh Token Repository
 *
 * Persists refresh tokens to `usertokens` (tokentype='refresh_token').
 * The refresh token is linked to its parent access token via the
 * `usertokens.parentToken` column so that revocation can cascade.
 *
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Return a new empty RefreshTokenEntity (not yet persisted).
     */
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    /**
     * Persist a newly issued refresh token.
     *
     * Looks up the parent access token to copy the userid and applicationid,
     * then stores the refresh token in `usertokens`.
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        $parentAccessTokenId = $this->resolveAccessTokenId($refreshTokenEntity->getAccessToken()->getIdentifier());
        $parentRow           = $this->loadAccessTokenRow($parentAccessTokenId);

        $expires = $refreshTokenEntity->getExpiryDateTime()
            ? $refreshTokenEntity->getExpiryDateTime()->getTimestamp()
            : 0;

        $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->insert([
                'userid'        => (int) ($parentRow['userid'] ?? 0),
                'tokentype'     => 'refresh_token',
                ...\Pramnos\User\Token::storageFor((string) $refreshTokenEntity->getIdentifier()),
                'created'       => $now,
                'status'        => 1,
                'applicationid' => (int) ($parentRow['applicationid'] ?? 0),
                /*
                 * **The scope the refresh token may mint access tokens for.**
                 *
                 * Omitted entirely before this, and `usertokens.scope` is `TEXT NOT NULL`
                 * with no default — a TEXT column cannot have one in MySQL — so under
                 * strict mode the insert was refused with `Field 'scope' doesn't have a
                 * default value` and **no grant could issue a refresh token at all**.
                 * The failure lands at the very end of a successful exchange: the code was
                 * valid, the client authenticated, the access token was signed and
                 * persisted, and then the request died.
                 *
                 * It is the parent access token's scope rather than a fresh lookup:
                 * RFC 6749 §6 says a refreshed token may not exceed the scope of the
                 * original grant, so this column *is* that ceiling.
                 */
                'scope'         => (string) ($parentRow['scope'] ?? ''),
                'parentToken'   => $parentAccessTokenId,
                'expires'       => $expires,
                'deviceinfo'    => '',
            ]);
    }

    /**
     * Revoke a refresh token by setting status=0.
     */
    public function revokeRefreshToken($tokenId): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $tokenId))
            ->where('tokentype', 'refresh_token')
            ->update(['status' => 0]);
    }

    /**
     * Return true when the refresh token does not exist or has been revoked.
     */
    public function isRefreshTokenRevoked($tokenId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->select('status')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $tokenId))
            ->where('tokentype', 'refresh_token')
            ->first();

        if (!$result || $result->numRows == 0) {
            return true;
        }
        return (int)$result->fields['status'] !== 1;
    }

    private function resolveAccessTokenId(string $identifier): int
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->select('tokenid')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $identifier))
            ->where('tokentype', 'access_token')
            ->first();
        return ($result && $result->numRows > 0) ? (int)$result->fields['tokenid'] : 0;
    }

    private function loadAccessTokenRow(int $tokenId): array
    {
        if ($tokenId === 0) {
            return [];
        }
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            // `scope` travels with the parent because the refresh token's insert needs it:
            // `usertokens.scope` is `TEXT NOT NULL`, and MySQL cannot give a TEXT column a
            // default, so an insert that omits it is refused outright under strict mode.
            ->select('userid, applicationid, scope')
            ->where('tokenid', $tokenId)
            ->first();
        return ($result && $result->numRows > 0) ? $result->fields : [];
    }
}
