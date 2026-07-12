<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Entities\ClientEntity;
use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use Pramnos\Auth\OAuth2\Entities\UserEntity;

/**
 * Unit tests for the OAuth2 Entity value objects.
 *
 * These classes are thin data carriers hydrated by their corresponding
 * Repository classes during the League oauth2-server grant flow.  They
 * contain no business logic — tests simply verify that setters persist
 * values and getters return them accurately.
 *
 * Tests verify:
 *   - ClientEntity: setName/getName, setRedirectUri/getRedirectUri (string
 *     and array variants), setConfidential/isConfidential, setIdentifier/
 *     getIdentifier (from EntityTrait).
 *   - UserEntity: setIdentifier/getIdentifier; null default.
 *   - ScopeEntity: setIdentifier/getIdentifier, jsonSerialize() returns the
 *     identifier (used by lcobucci/jwt when embedding scopes in the JWT payload).
 */
#[CoversClass(ClientEntity::class)]
#[CoversClass(UserEntity::class)]
#[CoversClass(ScopeEntity::class)]
class EntitiesTest extends TestCase
{
    // =========================================================================
    // ClientEntity
    // =========================================================================

    /**
     * setName() / getName() stores and returns the client application name.
     */
    public function testClientEntityNameRoundtrip(): void
    {
        // Arrange
        $entity = new ClientEntity();

        // Act
        $entity->setName('MyApp');

        // Assert
        $this->assertSame('MyApp', $entity->getName());
    }

    /**
     * getName() returns an empty string before setName() is called.
     */
    public function testClientEntityNameDefaultsToEmptyString(): void
    {
        // Arrange / Act
        $entity = new ClientEntity();

        // Assert
        $this->assertSame('', $entity->getName());
    }

    /**
     * setRedirectUri() / getRedirectUri() with a string value.
     */
    public function testClientEntityRedirectUriStringRoundtrip(): void
    {
        // Arrange
        $entity = new ClientEntity();

        // Act
        $entity->setRedirectUri('https://example.com/callback');

        // Assert
        $this->assertSame('https://example.com/callback', $entity->getRedirectUri());
    }

    /**
     * setRedirectUri() / getRedirectUri() with an array of URIs.
     */
    public function testClientEntityRedirectUriArrayRoundtrip(): void
    {
        // Arrange
        $entity = new ClientEntity();
        $uris   = ['https://app.example.com/cb', 'https://app.example.com/cb2'];

        // Act
        $entity->setRedirectUri($uris);

        // Assert
        $this->assertSame($uris, $entity->getRedirectUri());
    }

    /**
     * isConfidential() defaults to true (server-side clients are confidential).
     */
    public function testClientEntityIsConfidentialDefaultsToTrue(): void
    {
        // Arrange / Act
        $entity = new ClientEntity();

        // Assert
        $this->assertTrue($entity->isConfidential());
    }

    /**
     * setConfidential(false) / isConfidential() correctly represents public clients.
     */
    public function testClientEntitySetConfidentialFalse(): void
    {
        // Arrange
        $entity = new ClientEntity();

        // Act
        $entity->setConfidential(false);

        // Assert
        $this->assertFalse($entity->isConfidential());
    }

    /**
     * setIdentifier() / getIdentifier() — provided by League's EntityTrait.
     * Tests that the trait integration works correctly.
     */
    public function testClientEntityIdentifierRoundtrip(): void
    {
        // Arrange
        $entity = new ClientEntity();

        // Act
        $entity->setIdentifier('client_001');

        // Assert
        $this->assertSame('client_001', $entity->getIdentifier());
    }

    // =========================================================================
    // UserEntity
    // =========================================================================

    /**
     * getIdentifier() returns null before setIdentifier() is called.
     */
    public function testUserEntityIdentifierDefaultsToNull(): void
    {
        // Arrange / Act
        $entity = new UserEntity();

        // Assert
        $this->assertNull($entity->getIdentifier());
    }

    /**
     * setIdentifier() / getIdentifier() persists the user identifier.
     */
    public function testUserEntityIdentifierRoundtrip(): void
    {
        // Arrange
        $entity = new UserEntity();

        // Act
        $entity->setIdentifier(42);

        // Assert
        $this->assertSame(42, $entity->getIdentifier());
    }

    /**
     * setIdentifier() accepts a string user ID (common in UUID-keyed systems).
     */
    public function testUserEntityIdentifierAcceptsString(): void
    {
        // Arrange
        $entity = new UserEntity();

        // Act
        $entity->setIdentifier('user-uuid-1234');

        // Assert
        $this->assertSame('user-uuid-1234', $entity->getIdentifier());
    }

    // =========================================================================
    // ScopeEntity
    // =========================================================================

    /**
     * setIdentifier() / getIdentifier() — provided by League's EntityTrait.
     */
    public function testScopeEntityIdentifierRoundtrip(): void
    {
        // Arrange
        $entity = new ScopeEntity();

        // Act
        $entity->setIdentifier('read');

        // Assert
        $this->assertSame('read', $entity->getIdentifier());
    }

    /**
     * jsonSerialize() returns the scope identifier string, which is what
     * lcobucci/jwt serializes into the JWT token's `scp` claim.
     */
    public function testScopeEntityJsonSerializeReturnsIdentifier(): void
    {
        // Arrange
        $entity = new ScopeEntity();
        $entity->setIdentifier('admin');

        // Act
        $serialized = $entity->jsonSerialize();

        // Assert — the JWT scope representation is just the string identifier
        $this->assertSame('admin', $serialized);
    }

    /**
     * A ScopeEntity can be JSON-encoded directly; the result is a JSON string
     * (not an object), matching the OAuth2 scope representation in JWT tokens.
     */
    public function testScopeEntityEncodesAsStringInJson(): void
    {
        // Arrange
        $entity = new ScopeEntity();
        $entity->setIdentifier('write');

        // Act
        $json = json_encode($entity);

        // Assert — JSON string, not object
        $this->assertSame('"write"', $json);
    }
}
