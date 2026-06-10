<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2\Repositories;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Entities\AccessTokenEntity;
use Pramnos\Auth\OAuth2\Entities\RefreshTokenEntity;
use Pramnos\Auth\OAuth2\Repositories\RefreshTokenRepository;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

/**
 * Unit tests for RefreshTokenRepository.
 *
 * The repository relies on the Database singleton (via Factory::getDatabase())
 * for all persistence operations. Each test swaps the singleton with a mock
 * that records QueryBuilder interactions so no real database is needed.
 *
 * Tests cover:
 *  - getNewRefreshToken()  — factory method returns a valid entity.
 *  - persistNewRefreshToken() — correct INSERT is issued with linked access token data.
 *  - revokeRefreshToken()  — UPDATE sets status=0 for the correct token.
 *  - isRefreshTokenRevoked() — returns true when no row found (absent token).
 *  - isRefreshTokenRevoked() — returns true when status=0 (explicitly revoked).
 *  - isRefreshTokenRevoked() — returns false when status=1 (active token).
 */
#[CoversClass(RefreshTokenRepository::class)]
class RefreshTokenRepositoryTest extends TestCase
{
    private mixed $dbOriginal;

    /** @var array<int, array<string, mixed>> Rows captured by the QueryBuilder insert mock */
    private array $insertedRows = [];

    /** @var array<int, array<string, mixed>> Rows updated by the QueryBuilder update mock */
    private array $updatedRows = [];

    protected function setUp(): void
    {
        $this->insertedRows = [];
        $this->updatedRows  = [];

        // Preserve the real Database singleton and restore it in tearDown.
        $this->dbOriginal = Database::getInstance();
    }

    protected function tearDown(): void
    {
        $db = &Database::getInstance();
        $db = $this->dbOriginal;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a fluent QueryBuilder stub that records INSERT and UPDATE calls
     * and returns a configurable result from first().
     *
     * @param array<string, mixed>|null $firstResult Fields to return from first(),
     *                                                or null to simulate "not found".
     */
    private function buildQueryBuilderStub(?array $firstResult = null): QueryBuilder
    {
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        // All fluent methods return $this so the chain can continue.
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();

        // Capture the values array passed to insert()
        $qb->method('insert')->willReturnCallback(function (array $values): void {
            $this->insertedRows[] = $values;
        });

        // Capture the values array passed to update()
        $qb->method('update')->willReturnCallback(function (array $values): void {
            $this->updatedRows[] = $values;
        });

        // first() returns a Result mock whose numRows reflects the test scenario
        $qb->method('first')->willReturnCallback(function () use ($firstResult): ?\Pramnos\Database\Result {
            if ($firstResult === null) {
                // Simulate "row not found": numRows = 0
                $res = $this->createMock(\Pramnos\Database\Result::class);
                $res->numRows = 0;
                $res->fields  = [];
                return $res;
            }
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 1;
            $res->fields  = $firstResult;
            return $res;
        });

        return $qb;
    }

    /**
     * Build a Database mock that returns the given QueryBuilder stub from
     * queryBuilder() and also stubs prepareQuery / query to minimise noise.
     */
    private function buildDbMock(QueryBuilder $qb): Database&\PHPUnit\Framework\MockObject\MockObject
    {
        $db = $this->createMock(Database::class);
        $db->connected = true;
        $db->type      = 'mysql';
        $db->method('queryBuilder')->willReturn($qb);
        $db->method('prepareQuery')->willReturnArgument(0);
        return $db;
    }

    /**
     * Register a Database mock as the process-wide singleton so the
     * repository picks it up through Factory::getDatabase().
     */
    private function injectDb(Database $db): void
    {
        $singleton = &Database::getInstance();
        $singleton = $db;
    }

    /**
     * Build a minimal AccessTokenEntity with a known identifier.
     */
    private function buildAccessToken(string $identifier = 'acc-tok-001'): AccessTokenEntity
    {
        $token = new AccessTokenEntity();
        $token->setIdentifier($identifier);
        return $token;
    }

    /**
     * Build a RefreshTokenEntity linked to the given access token.
     */
    private function buildRefreshToken(
        string $identifier            = 'ref-tok-001',
        ?AccessTokenEntity $access    = null
    ): RefreshTokenEntity {
        $access ??= $this->buildAccessToken();

        $token = new RefreshTokenEntity();
        $token->setIdentifier($identifier);
        $token->setAccessToken($access);
        $token->setExpiryDateTime(
            new \DateTimeImmutable('+1 month')
        );
        return $token;
    }

    // =========================================================================
    // getNewRefreshToken()
    // =========================================================================

    /**
     * getNewRefreshToken() must return a non-null RefreshTokenEntityInterface.
     *
     * The League OAuth2 server calls this factory method before hydrating
     * the token. A null return skips refresh-token issuance entirely.
     */
    public function testGetNewRefreshTokenReturnsEntity(): void
    {
        // Arrange
        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $token = $repo->getNewRefreshToken();

        // Assert — must return a valid interface implementation
        $this->assertInstanceOf(RefreshTokenEntityInterface::class, $token,
            'getNewRefreshToken() must return a RefreshTokenEntityInterface');
        $this->assertInstanceOf(RefreshTokenEntity::class, $token,
            'getNewRefreshToken() must return the framework RefreshTokenEntity class');
        $this->assertNotNull($token, 'getNewRefreshToken() must not return null');
    }

    // =========================================================================
    // persistNewRefreshToken()
    // =========================================================================

    /**
     * persistNewRefreshToken() must INSERT one row into usertokens with the
     * refresh token's identifier, tokentype='refresh_token', and the parent
     * access token's userid and applicationid copied from the DB look-up.
     *
     * This verifies the full persistence path including:
     *  1. Look up access token by identifier → resolve tokenid.
     *  2. Load the access token row to copy userid / applicationid.
     *  3. INSERT the refresh token row with status=1.
     */
    public function testPersistNewRefreshTokenInsertsCorrectRow(): void
    {
        // Arrange — two consecutive first() calls return different results:
        //   call 1: resolveAccessTokenId()  → tokenid = 42
        //   call 2: loadAccessTokenRow(42)  → userid=7, applicationid=3
        $callCount = 0;
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('insert')->willReturnCallback(function (array $values): void {
            $this->insertedRows[] = $values;
        });
        $qb->method('first')->willReturnCallback(function () use (&$callCount): \Pramnos\Database\Result {
            $callCount++;
            $res           = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows  = 1;
            if ($callCount === 1) {
                // resolveAccessTokenId() → tokenid 42
                $res->fields = ['tokenid' => '42'];
            } else {
                // loadAccessTokenRow(42) → userid 7, applicationid 3
                $res->fields = ['userid' => '7', 'applicationid' => '3'];
            }
            return $res;
        });

        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller  = $this->createMock(\Pramnos\Application\Controller::class);
        $repo        = new RefreshTokenRepository($controller);
        $refreshToken = $this->buildRefreshToken('ref-tok-xyz');

        // Act
        $repo->persistNewRefreshToken($refreshToken);

        // Assert — exactly one INSERT was issued
        $this->assertCount(1, $this->insertedRows,
            'persistNewRefreshToken() must issue exactly one INSERT');

        $row = $this->insertedRows[0];

        // The token identifier must be stored
        $this->assertSame('ref-tok-xyz', $row['token'],
            'INSERT must store the refresh token identifier in the token column');

        // The tokentype must be refresh_token
        $this->assertSame('refresh_token', $row['tokentype'],
            'INSERT must set tokentype to "refresh_token"');

        // userid and applicationid must be copied from the access token row
        $this->assertSame(7, $row['userid'],
            'INSERT must copy userid from the parent access token row');
        $this->assertSame(3, $row['applicationid'],
            'INSERT must copy applicationid from the parent access token row');

        // New token must be active (status=1)
        $this->assertSame(1, $row['status'],
            'Newly persisted refresh token must have status=1 (active)');
    }

    /**
     * persistNewRefreshToken() must handle the edge case where the parent
     * access token cannot be found in the database (tokenid=0 / empty row).
     *
     * userid and applicationid must default to 0 so the INSERT does not fail
     * with a type error.
     */
    public function testPersistNewRefreshTokenHandlesMissingAccessToken(): void
    {
        // Arrange — first() always returns numRows=0 (access token not found)
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('insert')->willReturnCallback(function (array $values): void {
            $this->insertedRows[] = $values;
        });
        $qb->method('first')->willReturnCallback(function (): \Pramnos\Database\Result {
            $res          = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            $res->fields  = [];
            return $res;
        });

        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller  = $this->createMock(\Pramnos\Application\Controller::class);
        $repo        = new RefreshTokenRepository($controller);
        $refreshToken = $this->buildRefreshToken('ref-tok-orphan');

        // Act — must not throw even when parent access token is missing
        $repo->persistNewRefreshToken($refreshToken);

        // Assert — INSERT still happened with default zeros
        $this->assertCount(1, $this->insertedRows);
        $this->assertSame(0, $this->insertedRows[0]['userid'],
            'userid must default to 0 when access token is not found');
        $this->assertSame(0, $this->insertedRows[0]['applicationid'],
            'applicationid must default to 0 when access token is not found');
    }

    // =========================================================================
    // revokeRefreshToken()
    // =========================================================================

    /**
     * revokeRefreshToken() must UPDATE the usertokens row for the given token
     * identifier, setting status=0.
     *
     * After revocation the token must no longer be accepted by the OAuth2 server.
     */
    public function testRevokeRefreshTokenSetsStatusToZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub();
        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $repo->revokeRefreshToken('ref-tok-revoke-me');

        // Assert — exactly one UPDATE was issued
        $this->assertCount(1, $this->updatedRows,
            'revokeRefreshToken() must issue exactly one UPDATE');
        $this->assertSame(0, $this->updatedRows[0]['status'],
            'UPDATE must set status=0 to mark the token as revoked');
    }

    // =========================================================================
    // isRefreshTokenRevoked()
    // =========================================================================

    /**
     * isRefreshTokenRevoked() must return true when no row exists for the
     * given token identifier (i.e. the token was never issued or was hard-deleted).
     *
     * The OAuth2 server treats an absent token as revoked.
     */
    public function testIsRefreshTokenRevokedReturnsTrueWhenNotFound(): void
    {
        // Arrange — first() returns numRows=0 (not found)
        $qb = $this->buildQueryBuilderStub(null);
        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $revoked = $repo->isRefreshTokenRevoked('unknown-token');

        // Assert — absent token counts as revoked
        $this->assertTrue($revoked,
            'isRefreshTokenRevoked() must return true when the token row is not found');
    }

    /**
     * isRefreshTokenRevoked() must return true when the row exists but its
     * status is 0 (explicitly revoked).
     */
    public function testIsRefreshTokenRevokedReturnsTrueWhenStatusIsZero(): void
    {
        // Arrange — first() returns status=0
        $qb = $this->buildQueryBuilderStub(['status' => '0']);
        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $revoked = $repo->isRefreshTokenRevoked('ref-tok-revoked');

        // Assert
        $this->assertTrue($revoked,
            'isRefreshTokenRevoked() must return true when status=0');
    }

    /**
     * isRefreshTokenRevoked() must return false when the row exists and its
     * status is 1 (the token is active and can be exchanged for a new access token).
     */
    public function testIsRefreshTokenRevokedReturnsFalseWhenStatusIsOne(): void
    {
        // Arrange — first() returns status=1
        $qb = $this->buildQueryBuilderStub(['status' => '1']);
        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $revoked = $repo->isRefreshTokenRevoked('ref-tok-active');

        // Assert — active token must not be considered revoked
        $this->assertFalse($revoked,
            'isRefreshTokenRevoked() must return false when status=1');
    }

    /**
     * isRefreshTokenRevoked() must return true when first() returns null
     * (defensive: some DB adapters may return null instead of a Result
     * with numRows=0).
     */
    public function testIsRefreshTokenRevokedReturnsTrueWhenFirstReturnsNull(): void
    {
        // Arrange — first() returns null
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('first')->willReturn(null);

        $db = $this->buildDbMock($qb);
        $this->injectDb($db);

        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $repo       = new RefreshTokenRepository($controller);

        // Act
        $revoked = $repo->isRefreshTokenRevoked('null-result-token');

        // Assert
        $this->assertTrue($revoked,
            'isRefreshTokenRevoked() must return true when the DB result is null');
    }
}
