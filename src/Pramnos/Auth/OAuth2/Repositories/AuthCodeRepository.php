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
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    /** @var array{nonce?: string, auth_time?: int} */
    private array $context = [];

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * What the authorization request said, stored with the next code for its ID token.
     *
     * @param array{nonce?: string, auth_time?: int} $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * The context a code was issued with, by the code's id; empty when it has none.
     *
     * @return array{nonce?: string, auth_time?: int}
     */
    public static function contextOf(string $codeId): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!self::storesContext()) {
            return [];
        }

        $row = $db->queryBuilder()->table('#PREFIX#usertokens')
            ->select(['oidc_context'])
            ->where('token_lookup', \Pramnos\User\Token::lookup($codeId))
            ->where('tokentype', 'auth_code')
            ->first();
        $context = $row && $row->numRows > 0 ? json_decode((string) $row->fields['oidc_context'], true) : null;

        return is_array($context) ? $context : [];
    }

    /** Whether `usertokens` has the column — an installation that has not migrated issues codes without it. */
    private static function storesContext(): bool
    {
        try {
            $schema = \Pramnos\Framework\Factory::getDatabase()->schema();

            return $schema !== null && $schema->hasColumn('#PREFIX#usertokens', 'oidc_context');
        } catch (\Throwable) {
            return false;
        }
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
            ->table('#PREFIX#usertokens')
            ->insert([
                'userid'        => (int) ($authCodeEntity->getUserIdentifier() ?? 0),
                'tokentype'     => 'auth_code',
                ...\Pramnos\User\Token::storageFor((string) $authCodeEntity->getIdentifier()),
                'created'       => $now,
                'status'        => 1,
                'applicationid' => $appId,
                'scope'         => $scopes,
                'expires'       => $expires,
                'notes'         => (string) $authCodeEntity->getRedirectUri(),
                'deviceinfo'    => '',
            ] + ($this->context !== [] && self::storesContext()
                ? ['oidc_context' => json_encode($this->context)]
                : []));
    }

    /**
     * Revoke an authorization code by setting status=0.
     */
    public function revokeAuthCode($codeId): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $codeId))
            ->where('tokentype', 'auth_code')
            ->update(['status' => 0]);
    }

    /**
     * Return true when the authorization code does not exist or has been consumed/revoked.
     */
    public function isAuthCodeRevoked($codeId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->select('status')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $codeId))
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
            ->table('#PREFIX#applications')
            ->select('appid')
            ->where('apikey', (string)$clientIdentifier)
            ->first();
        return ($result && $result->numRows > 0) ? (int)$result->fields['appid'] : 0;
    }
}
