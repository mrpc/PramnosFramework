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
        // The driver reads the account through the query builder: the same answer, from first().
        $qb = $this->createMock(\Pramnos\Database\QueryBuilder::class);
        foreach (['table', 'select', 'where', 'orWhere'] as $chain) {
            $qb->method($chain)->willReturnSelf();
        }
        $qb->method('first')->willReturn($resMock);
        $db->method('queryBuilder')->willReturn($qb);
        
        $dbRef = &Database::getInstance();
        $dbRef = $db;

        $client = $this->createMock(ClientEntityInterface::class);
        $repo = $this->repository();

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
        // The driver reads the account through the query builder: the same answer, from first().
        $qb = $this->createMock(\Pramnos\Database\QueryBuilder::class);
        foreach (['table', 'select', 'where', 'orWhere'] as $chain) {
            $qb->method($chain)->willReturnSelf();
        }
        $qb->method('first')->willReturn($resMock);
        $db->method('queryBuilder')->willReturn($qb);
        
        $dbRef = &Database::getInstance();
        $dbRef = $db;

        $client = $this->createMock(ClientEntityInterface::class);
        $repo = $this->repository();

        // Act
        $entity = $repo->getUserEntityByUserCredentials('testuser', 'correctpass', 'password', $client);

        // Assert
        $this->assertNotNull($entity);
        $this->assertInstanceOf(UserEntityInterface::class, $entity);
        $this->assertInstanceOf(UserEntity::class, $entity);
        $this->assertSame(42, $entity->getIdentifier());
    }

    /**
     * A locked account is refused before the password is even checked, with a Retry-After
     * telling the client when to try again — the lockout is the login form's, and the token
     * endpoint must not be a way round it.
     */
    public function testALockedAccountIsRefusedBeforeThePasswordIsChecked(): void
    {
        // Arrange
        $lockout = $this->createMock(\Pramnos\Auth\Loginlockout::class);
        $lockout->method('getLockoutStatus')->willReturn(['locked' => true, 'remaining' => 42]);
        $lockout->expects($this->never())->method('recordFailedAttempt');
        $repo = new class ($lockout) extends UserRepository {
            public function __construct(private \Pramnos\Auth\Loginlockout $store)
            {
            }

            protected function lockout(): \Pramnos\Auth\Loginlockout
            {
                return $this->store;
            }
        };
        $client = $this->createMock(ClientEntityInterface::class);

        // Act / Assert
        try {
            $repo->getUserEntityByUserCredentials('someone', 'whatever', 'password', $client);
            $this->fail('a locked account must refuse before answering');
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            $this->assertSame('invalid_grant', $exception->getErrorType());
            $this->assertArrayHasKey('Retry-After', $exception->getHttpHeaders());
            $this->assertSame('42', $exception->getHttpHeaders()['Retry-After']);
        }
    }

    /** The real seam: without a test replacing it, `lockout()` builds an actual `Loginlockout`. */
    public function testLockoutBuildsARealLockoutStore(): void
    {
        // Arrange
        $repo   = new UserRepository();
        $method = new \ReflectionMethod(UserRepository::class, 'lockout');

        // Act / Assert
        $this->assertInstanceOf(\Pramnos\Auth\Loginlockout::class, $method->invoke($repo));
    }

    /**
     * A repository whose lockout store never locks, so these tests reach the credential check.
     */
    private function repository(): UserRepository
    {
        $lockout = $this->createMock(\Pramnos\Auth\Loginlockout::class);
        $lockout->method('getLockoutStatus')->willReturn(['locked' => false, 'remaining' => 0]);

        return new class ($lockout) extends UserRepository {
            /** @param \Pramnos\Auth\Loginlockout $store The stand-in store */
            public function __construct(private \Pramnos\Auth\Loginlockout $store)
            {
            }

            /** The stand-in store. */
            protected function lockout(): \Pramnos\Auth\Loginlockout
            {
                return $this->store;
            }
        };
    }
}
