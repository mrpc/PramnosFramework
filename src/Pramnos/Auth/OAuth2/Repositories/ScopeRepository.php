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
     * Finalize the scope list after client/user validation.
     *
     * Override in application code to restrict scopes per client or user.
     * The default implementation returns the requested scopes unchanged.
     *
     * @param ScopeEntityInterface[] $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        return $scopes;
    }
}
