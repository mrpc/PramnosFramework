<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2\Repositories;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Entities\AuthCodeEntity;
use Pramnos\Auth\OAuth2\Entities\ClientEntity;
use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use Pramnos\Auth\OAuth2\Repositories\AuthCodeRepository;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Unit tests for AuthCodeRepository.
 *
 * The repository persists OAuth2 authorization codes to `usertokens`
 * (tokentype='auth_code') via the Database singleton. Each test swaps the
 * singleton with a mock recording QueryBuilder calls — no real DB needed.
 *
 * Tests cover:
 *  - getNewAuthCode()     — factory returns a fresh AuthCodeEntity.
 *  - persistNewAuthCode() — INSERT with redirect URI in `notes`, resolved appid,
 *                           scope string, expiry and null-user default.
 *  - revokeAuthCode()     — UPDATE sets status=0.
 *  - isAuthCodeRevoked()  — true when missing / status=0 / null result,
 *                           false when status=1.
 */
#[CoversClass(AuthCodeRepository::class)]
class AuthCodeRepositoryTest extends TestCase
{
    private mixed $dbOriginal;

    /** @var array<int, array<string, mixed>> Rows captured by the insert mock */
    private array $insertedRows = [];

    /** @var array<int, array<string, mixed>> Rows captured by the update mock */
    private array $updatedRows = [];

    protected function setUp(): void
    {
        $this->insertedRows = [];
        $this->updatedRows  = [];

        // Preserve the real Database singleton; restored in tearDown.
        $this->dbOriginal = Database::getInstance();
    }

    protected function tearDown(): void
    {
        $db = &Database::getInstance();
        $db = $this->dbOriginal;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a fluent QueryBuilder stub recording INSERT/UPDATE calls.
     * first() returns rows from $firstResults in sequence (null = not found).
     *
     * @param array<int, array<string, mixed>|null> $firstResults
     */
    private function buildQueryBuilderStub(array $firstResults = []): QueryBuilder
    {
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();

        $qb->method('insert')->willReturnCallback(function (array $values): void {
            $this->insertedRows[] = $values;
        });
        $qb->method('update')->willReturnCallback(function (array $values): void {
            $this->updatedRows[] = $values;
        });

        $call = 0;
        $qb->method('first')->willReturnCallback(
            function () use (&$call, $firstResults): ?\Pramnos\Database\Result {
                $fields = $firstResults[$call] ?? null;
                $call++;
                $res = $this->createMock(\Pramnos\Database\Result::class);
                if ($fields === null) {
                    $res->numRows = 0;
                    $res->fields  = [];
                } else {
                    $res->numRows = 1;
                    $res->fields  = $fields;
                }
                return $res;
            }
        );

        return $qb;
    }

    /** Build a Database mock that returns the given QueryBuilder stub. */
    private function buildDbMock(QueryBuilder $qb): Database
    {
        $db = $this->createMock(Database::class);
        $db->connected = true;
        $db->type      = 'mysql';
        $db->method('queryBuilder')->willReturn($qb);
        $db->method('prepareQuery')->willReturnArgument(0);
        return $db;
    }

    /** Register a Database mock as the process-wide singleton. */
    private function injectDb(Database $db): void
    {
        $singleton = &Database::getInstance();
        $singleton = $db;
    }

    /** Build a repository with a mocked Controller dependency. */
    private function buildRepo(): AuthCodeRepository
    {
        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        return new AuthCodeRepository($controller);
    }

    /** Build a ClientEntity with a known apikey identifier. */
    private function buildClient(string $identifier = 'client-key-1'): ClientEntity
    {
        $client = new ClientEntity();
        $client->setIdentifier($identifier);
        return $client;
    }

    /** Build a ScopeEntity with the given identifier. */
    private function buildScope(string $identifier): ScopeEntity
    {
        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);
        return $scope;
    }

    // =========================================================================
    // getNewAuthCode()
    // =========================================================================

    /**
     * getNewAuthCode() must return a fresh framework AuthCodeEntity — the
     * League server hydrates it afterwards and calls persistNewAuthCode().
     */
    public function testGetNewAuthCodeReturnsEntity(): void
    {
        // Arrange
        $repo = $this->buildRepo();

        // Act
        $code = $repo->getNewAuthCode();

        // Assert
        $this->assertInstanceOf(AuthCodeEntityInterface::class, $code);
        $this->assertInstanceOf(AuthCodeEntity::class, $code,
            'getNewAuthCode() must return the framework AuthCodeEntity');
    }

    // =========================================================================
    // persistNewAuthCode()
    // =========================================================================

    /**
     * persistNewAuthCode() must INSERT one usertokens row with
     * tokentype='auth_code', the redirect URI stored in `notes` (needed for
     * the later token-exchange verification), the resolved applicationid,
     * the scope string and the expiry timestamp.
     */
    public function testPersistNewAuthCodeInsertsCorrectRow(): void
    {
        // Arrange — resolveAppId() lookup returns appid=4
        $qb = $this->buildQueryBuilderStub([['appid' => '4']]);
        $this->injectDb($this->buildDbMock($qb));

        $repo = $this->buildRepo();
        $code = new AuthCodeEntity();
        $code->setIdentifier('code-abc');
        $code->setClient($this->buildClient('client-key-1'));
        $code->setUserIdentifier(11);
        $code->setRedirectUri('https://app.example.com/callback');
        $code->addScope($this->buildScope('openid'));
        $expiry = new \DateTimeImmutable('+10 minutes');
        $code->setExpiryDateTime($expiry);

        // Act
        $repo->persistNewAuthCode($code);

        // Assert
        $this->assertCount(1, $this->insertedRows,
            'persistNewAuthCode() must issue exactly one INSERT');
        $row = $this->insertedRows[0];
        $this->assertSame('code-abc', $row['token']);
        $this->assertSame('auth_code', $row['tokentype']);
        $this->assertSame(11, $row['userid']);
        $this->assertSame(4, $row['applicationid'],
            'applicationid must be resolved from the client apikey');
        $this->assertSame('openid', $row['scope']);
        $this->assertSame('https://app.example.com/callback', $row['notes'],
            'Redirect URI must be stored in the notes column for token exchange');
        $this->assertSame($expiry->getTimestamp(), $row['expires']);
        $this->assertSame(1, $row['status'], 'New code must be active');
    }

    /**
     * persistNewAuthCode() must default userid to 0 when the entity carries
     * no user identifier (the `?? 0` null-coalescing branch).
     */
    public function testPersistNewAuthCodeDefaultsUserIdToZero(): void
    {
        // Arrange — appid lookup not found → 0
        $qb = $this->buildQueryBuilderStub([null]);
        $this->injectDb($this->buildDbMock($qb));

        $repo = $this->buildRepo();
        $code = new AuthCodeEntity();
        $code->setIdentifier('code-nouser');
        $code->setClient($this->buildClient('unknown-key'));
        $code->setRedirectUri('https://x.example.com/cb');
        $code->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));

        // Act
        $repo->persistNewAuthCode($code);

        // Assert
        $this->assertCount(1, $this->insertedRows);
        $this->assertSame(0, $this->insertedRows[0]['userid'],
            'userid must default to 0 when no user identifier is set');
        $this->assertSame(0, $this->insertedRows[0]['applicationid'],
            'applicationid must default to 0 when apikey is unknown');
    }

    /**
     * persistNewAuthCode() with an empty client identifier must skip the
     * applications lookup (resolveAppId guard) and store applicationid=0.
     */
    public function testPersistNewAuthCodeWithEmptyClientIdSkipsLookup(): void
    {
        // Arrange — no lookup rows configured at all
        $qb = $this->buildQueryBuilderStub([]);
        $this->injectDb($this->buildDbMock($qb));

        $repo = $this->buildRepo();
        $code = new AuthCodeEntity();
        $code->setIdentifier('code-noclient');
        $code->setClient($this->buildClient(''));
        $code->setRedirectUri('https://y.example.com/cb');
        $code->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));

        // Act
        $repo->persistNewAuthCode($code);

        // Assert
        $this->assertCount(1, $this->insertedRows);
        $this->assertSame(0, $this->insertedRows[0]['applicationid'],
            'Empty client identifier must short-circuit to applicationid=0');
    }

    // =========================================================================
    // revokeAuthCode()
    // =========================================================================

    /**
     * revokeAuthCode() must issue an UPDATE setting status=0 — a consumed
     * code must never be exchangeable a second time (replay protection).
     */
    public function testRevokeAuthCodeSetsStatusToZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub();
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act
        $repo->revokeAuthCode('code-used');

        // Assert
        $this->assertCount(1, $this->updatedRows,
            'revokeAuthCode() must issue exactly one UPDATE');
        $this->assertSame(0, $this->updatedRows[0]['status'],
            'UPDATE must set status=0 (consumed/revoked)');
    }

    // =========================================================================
    // isAuthCodeRevoked()
    // =========================================================================

    /**
     * isAuthCodeRevoked() must return true for an unknown code — an absent
     * row means the code was never issued or already purged.
     */
    public function testIsAuthCodeRevokedReturnsTrueWhenNotFound(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([null]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertTrue($repo->isAuthCodeRevoked('unknown-code'),
            'Absent code must count as revoked');
    }

    /**
     * isAuthCodeRevoked() must return true when the row exists with status=0
     * (the code was already exchanged once — replay attempt).
     */
    public function testIsAuthCodeRevokedReturnsTrueWhenStatusZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['status' => '0']]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertTrue($repo->isAuthCodeRevoked('consumed-code'),
            'status=0 must count as revoked');
    }

    /**
     * isAuthCodeRevoked() must return false when the row exists with status=1
     * (the code is fresh and may be exchanged for tokens).
     */
    public function testIsAuthCodeRevokedReturnsFalseWhenStatusOne(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['status' => '1']]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertFalse($repo->isAuthCodeRevoked('fresh-code'),
            'status=1 means the code is still valid');
    }

    /**
     * isAuthCodeRevoked() must return true when first() returns null
     * (defensive null-result handling).
     */
    public function testIsAuthCodeRevokedReturnsTrueWhenFirstReturnsNull(): void
    {
        // Arrange — first() returns null directly
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qb->method('table')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('first')->willReturn(null);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertTrue($repo->isAuthCodeRevoked('null-result-code'),
            'A null DB result must count as revoked');
    }
}
