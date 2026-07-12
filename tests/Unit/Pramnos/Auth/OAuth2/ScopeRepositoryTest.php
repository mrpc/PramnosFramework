<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Repositories\ScopeRepository;
use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Unit tests for Pramnos\Auth\OAuth2\Repositories\ScopeRepository.
 *
 * ScopeRepository validates requested OAuth2 scopes against the server's
 * built-in scope list.  It exposes methods to replace or extend that list
 * and implements league/oauth2-server's ScopeRepositoryInterface.
 *
 * Tests verify:
 *   - Default scopes: read, write, admin, user — all resolve to ScopeEntity.
 *   - getScopeEntityByIdentifier(): returns null for unknown identifiers.
 *   - setScopes(): fully replaces the scope list; old scopes no longer resolve.
 *   - addScopes(): merges new scopes with existing ones.
 *   - finalizeScopes(): returns the scope array unchanged (no-op default).
 */
#[CoversClass(ScopeRepository::class)]
class ScopeRepositoryTest extends TestCase
{
    // =========================================================================
    // Default scope list
    // =========================================================================

    /**
     * The four built-in scopes (read, write, admin, user) all resolve to a
     * ScopeEntity with the correct identifier.
     */
    public function testBuiltInScopesResolveToScopeEntity(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act / Assert — each built-in scope resolves
        foreach (['read', 'write', 'admin', 'user'] as $id) {
            $entity = $repo->getScopeEntityByIdentifier($id);
            $this->assertInstanceOf(ScopeEntityInterface::class, $entity,
                "Built-in scope '{$id}' should resolve to a ScopeEntityInterface");
            $this->assertSame($id, $entity->getIdentifier(),
                "Resolved scope identifier should match the requested '{$id}'");
        }
    }

    /**
     * getScopeEntityByIdentifier() returns null for an identifier that is not
     * in the scope list, causing the server to reject the request.
     */
    public function testGetScopeEntityReturnsNullForUnknownScope(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act
        $result = $repo->getScopeEntityByIdentifier('superpower');

        // Assert
        $this->assertNull($result);
    }

    /**
     * The returned entity is a ScopeEntity (not another implementation of the
     * interface), so its jsonSerialize() behaviour is predictable.
     */
    public function testGetScopeEntityReturnsScopeEntityInstance(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act
        $entity = $repo->getScopeEntityByIdentifier('read');

        // Assert — concrete class matches expected entity type
        $this->assertInstanceOf(ScopeEntity::class, $entity);
    }

    // =========================================================================
    // setScopes()
    // =========================================================================

    /**
     * setScopes() fully replaces the scope list.  Old scopes no longer resolve
     * after the replacement.
     */
    public function testSetScopesReplacesExistingList(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act — replace with a single custom scope
        $repo->setScopes(['custom' => 'Custom scope']);

        // Assert — custom scope resolves
        $entity = $repo->getScopeEntityByIdentifier('custom');
        $this->assertNotNull($entity);
        $this->assertSame('custom', $entity->getIdentifier());

        // Assert — built-in scopes no longer resolve
        $this->assertNull($repo->getScopeEntityByIdentifier('read'));
        $this->assertNull($repo->getScopeEntityByIdentifier('write'));
    }

    /**
     * setScopes() with an empty array means no scopes are recognized.
     */
    public function testSetScopesWithEmptyArrayClearsAllScopes(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act
        $repo->setScopes([]);

        // Assert — formerly valid scopes now return null
        $this->assertNull($repo->getScopeEntityByIdentifier('read'));
        $this->assertNull($repo->getScopeEntityByIdentifier('admin'));
    }

    // =========================================================================
    // addScopes()
    // =========================================================================

    /**
     * addScopes() merges new scopes with the existing list; built-ins remain
     * resolvable after the merge.
     */
    public function testAddScopesMergesWithExistingScopes(): void
    {
        // Arrange
        $repo = new ScopeRepository();

        // Act
        $repo->addScopes(['profile' => 'User profile', 'billing' => 'Billing access']);

        // Assert — new scopes resolve
        $this->assertNotNull($repo->getScopeEntityByIdentifier('profile'));
        $this->assertNotNull($repo->getScopeEntityByIdentifier('billing'));

        // Assert — built-in scopes still resolve
        $this->assertNotNull($repo->getScopeEntityByIdentifier('read'));
        $this->assertNotNull($repo->getScopeEntityByIdentifier('admin'));
    }

    /**
     * addScopes() with a key that already exists overwrites the existing entry
     * (array_merge behaviour — last value wins on duplicate keys).
     */
    public function testAddScopesOverwritesDuplicateKeys(): void
    {
        // Arrange
        $repo = new ScopeRepository();
        $original = $repo->getScopeEntityByIdentifier('read'); // known good

        // Act — overwrite 'read' with a different description (same identifier)
        $repo->addScopes(['read' => 'Enhanced read access']);

        // Assert — 'read' still resolves (same key, just updated description)
        $entity = $repo->getScopeEntityByIdentifier('read');
        $this->assertNotNull($entity);
        $this->assertSame('read', $entity->getIdentifier());
    }

    // =========================================================================
    // finalizeScopes()
    // =========================================================================

    /**
     * finalizeScopes() returns the scope array unchanged — the default
     * implementation is a no-op pass-through for application code to override.
     */
    public function testFinalizeScopesReturnsInputUnchanged(): void
    {
        // Arrange
        $repo   = new ScopeRepository();
        $client = $this->createMock(ClientEntityInterface::class);

        $scopeA = new ScopeEntity();
        $scopeA->setIdentifier('read');
        $scopeB = new ScopeEntity();
        $scopeB->setIdentifier('write');

        $inputScopes = [$scopeA, $scopeB];

        // Act
        $result = $repo->finalizeScopes($inputScopes, 'authorization_code', $client, 'user_123');

        // Assert — same array returned
        $this->assertSame($inputScopes, $result);
    }

    /**
     * finalizeScopes() with an empty scope array returns an empty array.
     */
    public function testFinalizeScopesWithEmptyInputReturnsEmpty(): void
    {
        // Arrange
        $repo   = new ScopeRepository();
        $client = $this->createMock(ClientEntityInterface::class);

        // Act
        $result = $repo->finalizeScopes([], 'client_credentials', $client);

        // Assert
        $this->assertSame([], $result);
    }

    /**
     * finalizeScopes() passes through when userIdentifier is null (client
     * credentials grant has no user).
     */
    public function testFinalizeScopesWithNullUserIdentifier(): void
    {
        // Arrange
        $repo   = new ScopeRepository();
        $client = $this->createMock(ClientEntityInterface::class);

        $scope = new ScopeEntity();
        $scope->setIdentifier('admin');

        // Act
        $result = $repo->finalizeScopes([$scope], 'client_credentials', $client, null);

        // Assert
        $this->assertSame([$scope], $result);
    }
}
