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
     *
     * ## The owning user
     *
     * `usertokens.userid` is a foreign key to `users`, and a client-credentials
     * token has no end user: League leaves `userIdentifier` null, `(int) null` is
     * 0, and 0 is not a row in `users`. The insert failed and the grant answered
     * `server_error` — the ordinary, secret-authenticated `client_credentials`
     * grant did not work at all.
     *
     * The application's system account owns it instead. That is the same account
     * the JWT-assertion path has always created for itself; it now comes from one
     * place, so both paths behave the same and `introspect`, `revoke` and the audit
     * trail have a real subject to point at.
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        $clientId = $accessTokenEntity->getClient()->getIdentifier();
        $appId    = $this->resolveAppId($clientId);
        $scopes   = $this->scopeString($accessTokenEntity->getScopes());
        $expires = $accessTokenEntity->getExpiryDateTime()
            ? $accessTokenEntity->getExpiryDateTime()->getTimestamp()
            : 0;

        $userId = (int) $accessTokenEntity->getUserIdentifier();
        if ($userId <= 0) {
            $userId = $this->resolveSystemUserId((string) $clientId);
        }

        $db->queryBuilder()
            ->table('usertokens')
            ->insert([
                'userid'        => $userId,
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

    /**
     * The system account that owns a client-credentials token for this client.
     *
     * Created on first use and reused afterwards, so a client hammering the token
     * endpoint does not accumulate an account per request.
     *
     * Returns 0 when the client cannot be resolved — the insert then fails as it
     * did before, which is the honest outcome: a token for an application that
     * does not exist should not be stored under a user invented for it.
     */
    protected function resolveSystemUserId(string $clientIdentifier): int
    {
        if ($clientIdentifier === '') {
            return 0;
        }

        try {
            $application = new \Pramnos\Auth\Application(
                new \Pramnos\Application\Controller()
            );

            // loadByApiKey returns the hydrated model or false, so the result is
            // what to work with rather than the object it was called on.
            $loaded = $application->loadByApiKey($clientIdentifier);
            if ($loaded === false) {
                return 0;
            }

            return $loaded->systemUserId();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not resolve a system user for client ' . $clientIdentifier
                . ': ' . $exception->getMessage()
            );

            return 0;
        }
    }

    /** @param \League\OAuth2\Server\Entities\ScopeEntityInterface[] $scopes */
    private function scopeString(array $scopes): string
    {
        return implode(' ', array_map(fn($s) => $s->getIdentifier(), $scopes));
    }
}
