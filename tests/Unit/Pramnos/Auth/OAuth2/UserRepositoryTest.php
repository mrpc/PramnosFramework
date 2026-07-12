<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Repositories\UserRepository;
use Pramnos\Auth\OAuth2\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Application\Settings;

/**
 * Unit tests for Pramnos\Auth\OAuth2\Repositories\UserRepository.
 *
 * UserRepository validates username/password credentials via User::validateUserCredentials
 * and returns a UserEntity on success.
 */
#[CoversClass(UserRepository::class)]
class UserRepositoryTest extends TestCase
{
    private ?Database $originalDb = null;

    protected function setUp(): void
    {
        Settings::clearSettings();
        $this->originalDb = Database::getInstance();
    }

    protected function tearDown(): void
    {
        Settings::clearSettings();
        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;
    }

    /**
     * Test getUserEntityByUserCredentials() returns null when user credentials are invalid.
     */
    public function testGetUserEntityWithInvalidCredentialsReturnsNull(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $db->method('prepareQuery')->willReturn('SQL QUERY');

        $resMock = $this->createMock(\Pramnos\Database\Result::class);
        $resMock->numRows = 0;

        $db->method('query')->willReturn($resMock);
        
        $dbRef = &Database::getInstance();
        $dbRef = $db;

        $client = $this->createMock(ClientEntityInterface::class);
        $repo = new UserRepository();

        // Act
        $entity = $repo->getUserEntityByUserCredentials('wronguser', 'wrongpass', 'password', $client);

        // Assert
        $this->assertNull($entity);
    }

    /**
     * Test getUserEntityByUserCredentials() returns UserEntity with correct ID when credentials match.
     */
    public function testGetUserEntityWithValidCredentialsReturnsUserEntity(): void
    {
        // Arrange
        $db = $this->createMock(Database::class);
        $db->method('prepareQuery')->willReturn('SQL QUERY');

        $resMock = $this->createMock(\Pramnos\Database\Result::class);
        $resMock->numRows = 1;

        $salt = 'salt123';
        Settings::setSetting('securitySalt', $salt, false);

        $pwd = 'correctpass' . md5($salt . '42');
        $hashedPassword = password_hash($pwd, PASSWORD_DEFAULT);

        $resMock->fields = [
            'userid'   => 42,
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => $hashedPassword,
            'active'   => 1,
            'validated'=> 1,
        ];

        $db->method('query')->willReturn($resMock);
        
        $dbRef = &Database::getInstance();
        $dbRef = $db;

        $client = $this->createMock(ClientEntityInterface::class);
        $repo = new UserRepository();

        // Act
        $entity = $repo->getUserEntityByUserCredentials('testuser', 'correctpass', 'password', $client);

        // Assert
        $this->assertNotNull($entity);
        $this->assertInstanceOf(UserEntityInterface::class, $entity);
        $this->assertInstanceOf(UserEntity::class, $entity);
        $this->assertSame(42, $entity->getIdentifier());
    }
}
