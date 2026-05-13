<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * OAuth2 Access Token Repository
 *
 * Persists access tokens to the `usertokens` table (tokentype='access_token').
 * Revocation sets status=0; isAccessTokenRevoked() checks existence and status.
 *
 * @package PramnosFramework
 */
class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Create a new in-memory AccessTokenEntity for the given client/scopes.
     * The token is not persisted until persistNewAccessToken() is called.
     *
     * @param ClientEntityInterface          $clientEntity
     * @param \League\OAuth2\Server\Entities\ScopeEntityInterface[] $scopes
     * @param mixed                          $userIdentifier
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        return $token;
    }

    /**
     * Persist a newly issued access token to the `usertokens` table.
     *
     * Resolves the applicationid from the client entity's identifier so that
     * the token is linked to the correct OAuth2 application record.
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        $appId  = $this->resolveAppId($accessTokenEntity->getClient()->getIdentifier());
        $scopes = $this->scopeString($accessTokenEntity->getScopes());
        $expires = $accessTokenEntity->getExpiryDateTime()
            ? $accessTokenEntity->getExpiryDateTime()->getTimestamp()
            : 0;

        $db->queryBuilder()
            ->table('usertokens')
            ->insert([
                'userid'        => (int) $accessTokenEntity->getUserIdentifier(),
                'tokentype'     => 'access_token',
                'token'         => $accessTokenEntity->getIdentifier(),
                'created'       => $now,
                'status'        => 1,
                'applicationid' => $appId,
                'scope'         => $scopes,
                'expires'       => $expires,
                'deviceinfo'    => '',
            ]);
    }

    /**
     * Revoke an access token by setting status=0.
     */
    public function revokeAccessToken($tokenId): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('usertokens')
            ->where('token', $tokenId)
            ->where('tokentype', 'access_token')
            ->update(['status' => 0]);
    }

    /**
     * Return true when the access token does not exist or has been revoked.
     */
    public function isAccessTokenRevoked($tokenId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens')
            ->select('status')
            ->where('token', $tokenId)
            ->where('tokentype', 'access_token')
            ->first();

        if (!$result || $result->numRows == 0) {
            return true;
        }
        return (int)$result->fields['status'] !== 1;
    }

    private function resolveAppId(mixed $clientIdentifier): int
    {
        if (empty($clientIdentifier)) {
            return 0;
        }
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('applications')
            ->select('appid')
            ->where('apikey', (string)$clientIdentifier)
            ->first();
        return ($result && $result->numRows > 0) ? (int)$result->fields['appid'] : 0;
    }

    /** @param \League\OAuth2\Server\Entities\ScopeEntityInterface[] $scopes */
    private function scopeString(array $scopes): string
    {
        return implode(' ', array_map(fn($s) => $s->getIdentifier(), $scopes));
    }
}
