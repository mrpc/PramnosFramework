<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * OAuth2 Authorization Code Repository
 *
 * Persists authorization codes to `usertokens` (tokentype='auth_code').
 * The redirect URI is stored in the `notes` column for retrieval during
 * token exchange.
 *
 * @package PramnosFramework
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Return a new empty AuthCodeEntity (not yet persisted).
     */
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    /**
     * Persist a newly issued authorization code.
     *
     * Stores the code in `usertokens` with tokentype='auth_code'.
     * The redirect URI is kept in the `notes` column so it can be verified
     * during the subsequent token-exchange request.
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        $appId  = $this->resolveAppId($authCodeEntity->getClient()->getIdentifier());
        $scopes = implode(' ', array_map(fn($s) => $s->getIdentifier(), $authCodeEntity->getScopes()));
        $expires = $authCodeEntity->getExpiryDateTime()
            ? $authCodeEntity->getExpiryDateTime()->getTimestamp()
            : 0;

        $db->queryBuilder()
            ->table('usertokens')
            ->insert([
                'userid'        => (int) ($authCodeEntity->getUserIdentifier() ?? 0),
                'tokentype'     => 'auth_code',
                'token'         => $authCodeEntity->getIdentifier(),
                'created'       => $now,
                'status'        => 1,
                'applicationid' => $appId,
                'scope'         => $scopes,
                'expires'       => $expires,
                'notes'         => (string) $authCodeEntity->getRedirectUri(),
                'deviceinfo'    => '',
            ]);
    }

    /**
     * Revoke an authorization code by setting status=0.
     */
    public function revokeAuthCode(string $codeId): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('usertokens')
            ->where('token', $codeId)
            ->where('tokentype', 'auth_code')
            ->update(['status' => 0]);
    }

    /**
     * Return true when the authorization code does not exist or has been consumed/revoked.
     */
    public function isAuthCodeRevoked(string $codeId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens')
            ->select('status')
            ->where('token', $codeId)
            ->where('tokentype', 'auth_code')
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
}
