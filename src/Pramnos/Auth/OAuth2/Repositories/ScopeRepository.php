<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * OAuth2 Scope Repository
 *
 * Validates requested scopes against {@see \Pramnos\Auth\Scopes} — the framework's
 * scope registry, and the same list the discovery document publishes, the consent
 * screen renders and the permission checks read.
 *
 * It did not used to. This class carried four hardcoded scopes of its own — `read`,
 * `write`, `admin`, `user` — and nothing ever called `setScopes()` to replace them.
 * So a server published one list and enforced another: of the twelve scopes in
 * `scopes_supported`, eleven were rejected as `invalid_scope` by the token
 * endpoint that advertised them. `openid` was one of them, which means OpenID
 * Connect could not be used at all — a client following the discovery document to
 * the letter got a 400 on its first request.
 *
 * The four legacy identifiers are still accepted, so an integration built against
 * them keeps working. Applications that want their own list still call
 * `setScopes()` or `addScopes()`.
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * The four this class used to define on its own.
     *
     * Kept because an existing integration may be asking for them, and a scope
     * that stops being accepted is an outage on somebody else's server.
     *
     * @var array<string,string>
     */
    private const LEGACY_SCOPES = [
        'read'  => 'Read access',
        'write' => 'Write access',
        'admin' => 'Admin access',
        'user'  => 'User profile access',
    ];

    /** @var array<string,string>|null scope identifier → description, or null until read */
    private ?array $scopes = null;

    /**
     * The scopes this server accepts.
     *
     * Resolved on first use rather than in a property initialiser, because the
     * registry is a class this one must not force-load at construction time.
     *
     * @return array<string,string>
     */
    private function scopes(): array
    {
        if ($this->scopes !== null) {
            return $this->scopes;
        }

        try {
            $registered = \Pramnos\Auth\Scopes::getScopeDescriptions();
        } catch (\Throwable) {
            // A registry that cannot be read must not make every scope invalid;
            // fall back to what this class accepted before it consulted one.
            $registered = [];
        }

        $this->scopes = array_merge(self::LEGACY_SCOPES, $registered);

        return $this->scopes;
    }

    /**
     * Replace or extend the built-in scope list.
     *
     * @param array<string,string> $scopes  identifier → description map
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    /**
     * Add scopes to the existing list without replacing it.
     *
     * @param array<string,string> $scopes  identifier → description map
     */
    public function addScopes(array $scopes): void
    {
        $this->scopes = array_merge($this->scopes(), $scopes);
    }

    /**
     * Return the ScopeEntity for the given identifier, or null if unknown.
     *
     * league/oauth2-server calls this for each scope in the request to
     * decide whether it is recognized by the server.
     */
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        $scopes = $this->scopes();
        if (!array_key_exists($identifier, $scopes)) {
            return null;
        }

        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);

        return $scope;
    }

    /**
     * Finalize the scope list after client/user validation: the client's Allowed Scopes.
     *
     * A scope the client's registration does not include is refused with `invalid_scope`. A
     * client with no list is not restricted. See {@see \Pramnos\Auth\Application::scopesBeyond()}.
     *
     * Override to restrict further, per user or per grant; call the parent first.
     *
     * @param ScopeEntityInterface[] $scopes
     * @return ScopeEntityInterface[]
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        self::assertWithinClient($clientEntity, $scopes);

        return $scopes;
    }

    /**
     * Refuse scopes outside a client's Allowed Scopes — the check {@see finalizeScopes()} and
     * `AccessTokenRepository::getNewToken()` both make. League calls the first for the code and
     * client-credentials grants and not for a refresh; every grant issues its token through the
     * second.
     *
     * @param ScopeEntityInterface[] $scopes
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    public static function assertWithinClient(ClientEntityInterface $clientEntity, array $scopes): void
    {
        if (!$clientEntity instanceof \Pramnos\Auth\OAuth2\Entities\ClientEntity) {
            return;
        }

        $beyond = \Pramnos\Auth\Application::scopesBeyond(
            $clientEntity->getAllowedScopes(),
            array_map(static fn (ScopeEntityInterface $s): string => $s->getIdentifier(), $scopes)
        );
        if ($beyond !== []) {
            throw \League\OAuth2\Server\Exception\OAuthServerException::invalidScope(implode(' ', $beyond));
        }
    }
}
