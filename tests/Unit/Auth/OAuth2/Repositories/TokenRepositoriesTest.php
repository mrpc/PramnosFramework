<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2\Repositories;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Entities\AccessTokenEntity;
use Pramnos\Auth\OAuth2\Entities\AuthCodeEntity;
use Pramnos\Auth\OAuth2\Entities\ClientEntity;
use Pramnos\Auth\OAuth2\Entities\RefreshTokenEntity;
use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use Pramnos\Auth\OAuth2\Repositories\AccessTokenRepository;
use Pramnos\Auth\OAuth2\Repositories\AuthCodeRepository;
use Pramnos\Auth\OAuth2\Repositories\RefreshTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

/**
 * Unit tests for the factory methods of the OAuth2 token repositories.
 *
 * Only the "get new entity" factory methods are tested here because they are
 * pure in-memory operations that require no database access.  The persist,
 * revoke, and isRevoked methods all touch the DB and are covered separately
 * by integration tests.
 *
 * Repositories under test:
 *  - AccessTokenRepository::getNewToken()
 *  - RefreshTokenRepository::getNewRefreshToken()
 *  - AuthCodeRepository::getNewAuthCode()
 */
#[CoversClass(AccessTokenRepository::class)]
#[CoversClass(RefreshTokenRepository::class)]
#[CoversClass(AuthCodeRepository::class)]
class TokenRepositoriesTest extends TestCase
{
    private \Pramnos\Application\Controller $controller;

    protected function setUp(): void
    {
        // The controller is stored in each repository but not called by the
        // factory methods under test; a mock is sufficient.
        $this->controller = $this->createMock(\Pramnos\Application\Controller::class);
    }

    // ── AccessTokenRepository::getNewToken() ─────────────────────────────────

    /**
     * getNewToken() must return an AccessTokenEntityInterface hydrated with the
     * provided client, user identifier, and scopes.
     *
     * The returned entity is not yet persisted; it is handed off to the league
     * grant flow which later calls persistNewAccessToken().
     */
    public function testGetNewTokenReturnsHydratedAccessTokenEntity(): void
    {
        // Arrange
        $repo = new AccessTokenRepository($this->controller);

        $client = new ClientEntity();
        $client->setIdentifier('client-abc');

        $scope = new ScopeEntity();
        $scope->setIdentifier('read');

        // Act
        $token = $repo->getNewToken($client, [$scope], 42);

        // Assert — correct type
        $this->assertInstanceOf(AccessTokenEntityInterface::class, $token,
            'getNewToken() must return an AccessTokenEntityInterface');
        $this->assertInstanceOf(AccessTokenEntity::class, $token,
            'getNewToken() must return the framework AccessTokenEntity concrete class');

        // Assert — client was attached
        $this->assertSame($client, $token->getClient(),
            'getNewToken() must attach the provided client to the token');

        // Assert — user identifier was set
        $this->assertSame(42, $token->getUserIdentifier(),
            'getNewToken() must set the user identifier on the token');

        // Assert — scopes were added
        $scopes = $token->getScopes();
        $this->assertCount(1, $scopes, 'Token must carry the one scope that was passed');
        $this->assertSame('read', $scopes[0]->getIdentifier(),
            'Scope identifier must match what was provided');
    }

    /**
     * getNewToken() must accept a null user identifier (used in client_credentials
     * grant where there is no resource owner).
     */
    public function testGetNewTokenAcceptsNullUserIdentifier(): void
    {
        // Arrange
        $repo   = new AccessTokenRepository($this->controller);
        $client = new ClientEntity();
        $client->setIdentifier('machine-client');

        // Act
        $token = $repo->getNewToken($client, [], null);

        // Assert — no exception; user identifier is null
        $this->assertNull($token->getUserIdentifier(),
            'getNewToken() must allow a null user identifier for machine-to-machine grants');
    }

    /**
     * getNewToken() must accept multiple scopes and add all of them to the token.
     */
    public function testGetNewTokenAddsMultipleScopes(): void
    {
        // Arrange
        $repo = new AccessTokenRepository($this->controller);

        $client = new ClientEntity();
        $client->setIdentifier('multi-scope-client');

        $scopeRead  = new ScopeEntity();
        $scopeRead->setIdentifier('read');
        $scopeWrite = new ScopeEntity();
        $scopeWrite->setIdentifier('write');

        // Act
        $token = $repo->getNewToken($client, [$scopeRead, $scopeWrite], 1);

        // Assert — both scopes present
        $this->assertCount(2, $token->getScopes(),
            'getNewToken() must add all provided scopes to the token');
    }

    // ── RefreshTokenRepository::getNewRefreshToken() ──────────────────────────

    /**
     * getNewRefreshToken() must return a RefreshTokenEntityInterface (specifically
     * the framework's RefreshTokenEntity).
     *
     * No DB access, no arguments — this is a pure factory method.
     */
    public function testGetNewRefreshTokenReturnsRefreshTokenEntity(): void
    {
        // Arrange
        $repo = new RefreshTokenRepository($this->controller);

        // Act
        $token = $repo->getNewRefreshToken();

        // Assert — correct type
        $this->assertInstanceOf(RefreshTokenEntityInterface::class, $token,
            'getNewRefreshToken() must return a RefreshTokenEntityInterface');
        $this->assertInstanceOf(RefreshTokenEntity::class, $token,
            'getNewRefreshToken() must return the framework RefreshTokenEntity');
    }

    /**
     * getNewRefreshToken() must return a fresh instance on each call (not cached).
     */
    public function testGetNewRefreshTokenReturnsFreshInstanceEachCall(): void
    {
        // Arrange
        $repo = new RefreshTokenRepository($this->controller);

        // Act
        $t1 = $repo->getNewRefreshToken();
        $t2 = $repo->getNewRefreshToken();

        // Assert — distinct objects
        $this->assertNotSame($t1, $t2,
            'getNewRefreshToken() must return a new instance each time (not a singleton)');
    }

    // ── AuthCodeRepository::getNewAuthCode() ──────────────────────────────────

    /**
     * getNewAuthCode() must return an AuthCodeEntityInterface (specifically the
     * framework's AuthCodeEntity).
     *
     * No DB access, no arguments — this is a pure factory method.
     */
    public function testGetNewAuthCodeReturnsAuthCodeEntity(): void
    {
        // Arrange
        $repo = new AuthCodeRepository($this->controller);

        // Act
        $code = $repo->getNewAuthCode();

        // Assert — correct type
        $this->assertInstanceOf(AuthCodeEntityInterface::class, $code,
            'getNewAuthCode() must return an AuthCodeEntityInterface');
        $this->assertInstanceOf(AuthCodeEntity::class, $code,
            'getNewAuthCode() must return the framework AuthCodeEntity');
    }

    /**
     * getNewAuthCode() must return a fresh instance on each call.
     */
    public function testGetNewAuthCodeReturnsFreshInstanceEachCall(): void
    {
        // Arrange
        $repo = new AuthCodeRepository($this->controller);

        // Act
        $c1 = $repo->getNewAuthCode();
        $c2 = $repo->getNewAuthCode();

        // Assert — distinct objects
        $this->assertNotSame($c1, $c2,
            'getNewAuthCode() must return a new instance each time (not a singleton)');
    }
}
