<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Auth\Application as AuthApplication;
use Pramnos\Auth\OAuth2\Repositories\ClientRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Unit tests for ClientRepository.
 *
 * ClientRepository resolves OAuth2 client_id values by querying the
 * `applications` table via Pramnos\Auth\Application.  Since Application
 * is a Model that requires a real database, tests use a concrete subclass
 * that overrides the protected makeApplication() factory method to return a
 * mock — no real database connection is needed.
 *
 * Paths covered:
 *  - getClientEntity(): client not found (loadByApiKey returns false) → null
 *  - getClientEntity(): client found (loadByApiKey returns true) → ClientEntity
 *    with identifier, name, redirectUri, confidential flag populated.
 *  - validateClient(): delegates to Application::validateCredentials() → bool
 */
#[CoversClass(ClientRepository::class)]
class ClientRepositoryTest extends TestCase
{
    /** @var Controller&\PHPUnit\Framework\MockObject\MockObject */
    private Controller $controller;

    protected function setUp(): void
    {
        $this->controller = $this->createMock(Controller::class);
    }

    // ── getClientEntity() ─────────────────────────────────────────────────────

    /**
     * getClientEntity() must return null when the Application model cannot find
     * a matching client_id in the database.  This is the "unknown client" path
     * that league/oauth2-server uses to reject the request.
     */
    public function testGetClientEntityReturnsNullWhenClientNotFound(): void
    {
        // Arrange — loadByApiKey returns false (client not in DB)
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->method('loadByApiKey')->willReturn(false);

        $repo = $this->makeRepo($mockApp);

        // Act
        $entity = $repo->getClientEntity('unknown-client-id');

        // Assert — not found → null
        $this->assertNull($entity,
            'getClientEntity() must return null when the client_id is not found');
    }

    /**
     * getClientEntity() must return a fully-populated ClientEntity when the
     * client is found.  The entity's identifier, name, redirect URI, and
     * confidential flag are taken from the Application model.
     */
    public function testGetClientEntityReturnsPopulatedEntityWhenClientFound(): void
    {
        // Arrange — loadByApiKey returns true; Application reports client details
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->method('loadByApiKey')->willReturn($mockApp);  // truthy return
        $mockApp->method('getClientIdentifier')->willReturn('my-client-id');
        $mockApp->method('getClientName')->willReturn('My OAuth App');
        $mockApp->method('getRedirectUris')->willReturn(['https://example.com/callback']);
        $mockApp->method('isConfidential')->willReturn(true);

        $repo = $this->makeRepo($mockApp);

        // Act
        $entity = $repo->getClientEntity('my-client-id');

        // Assert — entity is returned and hydrated correctly
        $this->assertInstanceOf(ClientEntityInterface::class, $entity,
            'getClientEntity() must return a ClientEntityInterface on success');
        $this->assertSame('my-client-id', $entity->getIdentifier());
        $this->assertSame('My OAuth App', $entity->getName());
        $this->assertTrue($entity->isConfidential());
    }

    /**
     * getClientEntity() must correctly set a non-confidential flag on the entity.
     *
     * Public clients do not require a client secret during authorization.
     */
    public function testGetClientEntitySetsNonConfidentialFlag(): void
    {
        // Arrange — public (non-confidential) client
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->method('loadByApiKey')->willReturn($mockApp);
        $mockApp->method('getClientIdentifier')->willReturn('public-client');
        $mockApp->method('getClientName')->willReturn('Public App');
        $mockApp->method('getRedirectUris')->willReturn([]);
        $mockApp->method('isConfidential')->willReturn(false);

        $repo = $this->makeRepo($mockApp);

        // Act
        $entity = $repo->getClientEntity('public-client');

        // Assert — confidential flag is false
        $this->assertNotNull($entity);
        $this->assertFalse($entity->isConfidential(),
            'getClientEntity() must propagate isConfidential=false from the model');
    }

    // ── validateClient() ──────────────────────────────────────────────────────

    /**
     * validateClient() must return true when the Application model confirms
     * the client_id + client_secret combination is valid.
     */
    public function testValidateClientReturnsTrueWhenCredentialsAreValid(): void
    {
        // Arrange
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->method('validateCredentials')->willReturn(true);

        $repo = $this->makeRepo($mockApp);

        // Act
        $result = $repo->validateClient('valid-id', 'valid-secret', 'client_credentials');

        // Assert
        $this->assertTrue($result,
            'validateClient() must return true when credentials are valid');
    }

    /**
     * validateClient() must return false when the Application model rejects
     * the credentials (wrong secret, inactive app, etc.).
     */
    public function testValidateClientReturnsFalseWhenCredentialsAreInvalid(): void
    {
        // Arrange
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->method('validateCredentials')->willReturn(false);

        $repo = $this->makeRepo($mockApp);

        // Act
        $result = $repo->validateClient('bad-id', 'wrong-secret', 'authorization_code');

        // Assert
        $this->assertFalse($result,
            'validateClient() must return false when credentials are invalid');
    }

    /**
     * validateClient() must pass the clientIdentifier and clientSecret to
     * Application::validateCredentials() unchanged.
     */
    public function testValidateClientPassesCorrectArguments(): void
    {
        // Arrange
        $mockApp = $this->createMock(AuthApplication::class);
        $mockApp->expects($this->once())
            ->method('validateCredentials')
            ->with('client-abc', 's3cr3t')
            ->willReturn(true);

        $repo = $this->makeRepo($mockApp);

        // Act
        $repo->validateClient('client-abc', 's3cr3t', 'client_credentials');

        // Assert — verified by mock expectation above
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a testable ClientRepository subclass that returns a mock Application
     * from makeApplication(), bypassing the need for a real database connection.
     */
    private function makeRepo(AuthApplication $mockApp): ClientRepository
    {
        return new class($this->controller, $mockApp) extends ClientRepository {
            private AuthApplication $mockApplication;

            public function __construct(Controller $controller, AuthApplication $mockApplication)
            {
                parent::__construct($controller);
                $this->mockApplication = $mockApplication;
            }

            protected function makeApplication(): AuthApplication
            {
                return $this->mockApplication;
            }
        };
    }
}
