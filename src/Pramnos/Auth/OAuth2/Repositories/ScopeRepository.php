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
 * Validates requested scopes against the framework's built-in scope list.
 * Applications that need custom scopes should extend this class or override
 * the $scopes property via setScopes().
 *
 * Built-in scopes (application-agnostic):
 * - read   — Read access to resources
 * - write  — Write access to resources
 * - admin  — Full administrative access
 * - user   — Access to the user's own profile
 *
 * @package PramnosFramework
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    /** @var array<string,string> scope identifier → human-readable description */
    private array $scopes = [
        'read'  => 'Read access',
        'write' => 'Write access',
        'admin' => 'Admin access',
        'user'  => 'User profile access',
    ];

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
        $this->scopes = array_merge($this->scopes, $scopes);
    }

    /**
     * Return the ScopeEntity for the given identifier, or null if unknown.
     *
     * league/oauth2-server calls this for each scope in the request to
     * decide whether it is recognized by the server.
     */
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (!array_key_exists($identifier, $this->scopes)) {
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
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null
    ): array {
        return $scopes;
    }
}
