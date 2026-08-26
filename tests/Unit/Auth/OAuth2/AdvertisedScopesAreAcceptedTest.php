<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Repositories\ScopeRepository;
use Pramnos\Auth\Scopes;

/**
 * A server must accept the scopes it advertises.
 *
 * The discovery document publishes `scopes_supported` from {@see Scopes}, and the
 * token endpoint validated requests against four identifiers hardcoded in
 * `ScopeRepository` — `read`, `write`, `admin`, `user` — which nothing ever
 * replaced, because nothing ever called `setScopes()`.
 *
 * So the server published twelve scopes and accepted one of them. A client that
 * followed the discovery document exactly got `invalid_scope` on its first
 * request, and the eleven it was refused included `openid`: OpenID Connect could
 * not be used at all against a server whose discovery document said it could.
 *
 * The failure is invisible from either side alone. Read the discovery document and
 * the scopes are there. Read the repository and its four are consistent. Only
 * comparing the two shows it, which is what this test does.
 */
class AdvertisedScopesAreAcceptedTest extends TestCase
{
    /**
     * Every scope in the registry is accepted by the token endpoint.
     */
    public function testEveryRegisteredScopeIsAccepted(): void
    {
        // Arrange
        $repository = new ScopeRepository();
        $advertised = array_keys(Scopes::getScopeDescriptions());
        $this->assertNotEmpty($advertised, 'the registry must publish scopes');

        // Act
        $rejected = [];
        foreach ($advertised as $scope) {
            if ($repository->getScopeEntityByIdentifier($scope) === null) {
                $rejected[] = $scope;
            }
        }

        // Assert
        $this->assertSame([], $rejected,
            'these scopes are advertised and refused: ' . implode(', ', $rejected));
    }

    /**
     * `openid` in particular, since OIDC is unusable without it.
     *
     * Called out on its own because it is the one whose absence breaks a whole
     * protocol rather than one permission.
     */
    public function testOpenidIsAccepted(): void
    {
        // Arrange
        $repository = new ScopeRepository();

        // Assert
        $this->assertNotNull(
            $repository->getScopeEntityByIdentifier('openid'),
            'openid must be accepted — OpenID Connect cannot work without it'
        );
    }

    /**
     * The four identifiers this class used to define are still accepted.
     *
     * An integration built against them predates the registry. A scope that
     * stops being accepted is an outage on somebody else's server.
     */
    public function testTheLegacyIdentifiersStillWork(): void
    {
        // Arrange
        $repository = new ScopeRepository();

        // Assert
        foreach (['read', 'write', 'admin', 'user'] as $scope) {
            $this->assertNotNull(
                $repository->getScopeEntityByIdentifier($scope),
                "the legacy scope $scope must keep working"
            );
        }
    }

    /**
     * An unknown scope is still refused.
     *
     * Accepting everything would be a way to make this test pass and a way to
     * hand out a token for a permission nobody defined.
     */
    public function testAnUnknownScopeIsRefused(): void
    {
        // Arrange
        $repository = new ScopeRepository();

        // Assert
        $this->assertNull($repository->getScopeEntityByIdentifier('no_such_scope_here'));
    }

    /**
     * An application can still replace the list outright.
     */
    public function testAnApplicationCanReplaceTheList(): void
    {
        // Arrange
        $repository = new ScopeRepository();

        // Act
        $repository->setScopes(['only_this' => 'The only scope']);

        // Assert
        $this->assertNotNull($repository->getScopeEntityByIdentifier('only_this'));
        $this->assertNull($repository->getScopeEntityByIdentifier('openid'));
    }

    /**
     * And add to it without losing what was there.
     *
     * `addScopes()` merged onto the property, which is now unresolved until first
     * read — so adding before any read had to resolve the registry first, or it
     * would have replaced the list rather than extending it.
     */
    public function testAnApplicationCanAddToTheList(): void
    {
        // Arrange
        $repository = new ScopeRepository();

        // Act
        $repository->addScopes(['extra' => 'An extra scope']);

        // Assert
        $this->assertNotNull($repository->getScopeEntityByIdentifier('extra'));
        $this->assertNotNull($repository->getScopeEntityByIdentifier('openid'),
            'adding a scope must not drop the registered ones');
        $this->assertNotNull($repository->getScopeEntityByIdentifier('read'),
            'nor the legacy ones');
    }
}
