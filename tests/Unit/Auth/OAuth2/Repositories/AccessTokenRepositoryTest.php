<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2\Repositories;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\OAuth2\Entities\AccessTokenEntity;
use Pramnos\Auth\OAuth2\Entities\ClientEntity;
use Pramnos\Auth\OAuth2\Entities\ScopeEntity;
use Pramnos\Auth\OAuth2\Repositories\AccessTokenRepository;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

/**
 * Unit tests for AccessTokenRepository.
 *
 * The repository persists OAuth2 access tokens to the `usertokens` table
 * via the Database singleton. Each test swaps the singleton with a mock
 * that records QueryBuilder interactions so no real database is needed.
 *
 * Tests cover:
 *  - getNewToken()           — factory returns hydrated entity (client/user/scopes).
 *  - persistNewAccessToken() — INSERT with resolved applicationid + scope string.
 *  - persistNewAccessToken() — applicationid=0 when client identifier unknown/empty.
 *  - revokeAccessToken()     — UPDATE sets status=0.
 *  - isAccessTokenRevoked()  — true when not found / status=0 / null result,
 *                              false when status=1.
 */
#[CoversClass(AccessTokenRepository::class)]
class AccessTokenRepositoryTest extends TestCase
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

        // Preserve the real Database singleton; restored in tearDown so
        // subsequent test classes get the original connection back.
        $this->dbOriginal = Database::getInstance();
    }

    protected function tearDown(): void
    {
        $db = &Database::getInstance();
        $db = $this->dbOriginal;
        $this->restoreAppInstances();
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

    /** Build a repository with a mocked Controller dependency. */
    private function buildRepo(): AccessTokenRepository
    {
        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        return new AccessTokenRepository($controller);
    }

    /** @var array<string, mixed>|null snapshot of appInstances, restored in tearDown */
    private ?array $savedAppInstances = null;

    /** Register a real Application stub with the given `oauth.*` config. */
    private function withOauthConfig(array $config): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['oauth' => $config];

        $ref                     = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedAppInstances = $ref->getValue() ?? [];
        $instances               = $this->savedAppInstances;
        $instances['default']    = $stub;
        $ref->setValue(null, $instances);
    }

    private function restoreAppInstances(): void
    {
        if ($this->savedAppInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedAppInstances);
            $this->savedAppInstances = null;
        }
    }

    // =========================================================================
    // getNewToken()
    // =========================================================================

    /**
     * getNewToken() must return an AccessTokenEntity hydrated with the given
     * client, user identifier and all scopes.
     *
     * The League server uses this factory before signing the JWT; missing
     * scopes here would silently issue an under-privileged token.
     */
    public function testGetNewTokenReturnsHydratedEntity(): void
    {
        // Arrange
        $repo   = $this->buildRepo();
        $client = $this->buildClient('my-client');
        $scopes = [$this->buildScope('openid'), $this->buildScope('profile')];

        // Act
        $token = $repo->getNewToken($client, $scopes, 42);

        // Assert — entity type and full hydration
        $this->assertInstanceOf(AccessTokenEntityInterface::class, $token);
        $this->assertInstanceOf(AccessTokenEntity::class, $token);
        $this->assertSame($client, $token->getClient(),
            'getNewToken() must attach the provided client entity');
        $this->assertSame(42, $token->getUserIdentifier(),
            'getNewToken() must store the user identifier');
        // Both scopes must be attached in order
        $attached = array_map(fn($s) => $s->getIdentifier(), $token->getScopes());
        $this->assertSame(['openid', 'profile'], $attached,
            'getNewToken() must attach every scope passed in');
    }

    /**
     * getNewToken() with an empty scope list and null user must still return
     * a valid entity (client-credentials style issuance).
     */
    public function testGetNewTokenWithNoScopesAndNullUser(): void
    {
        // Arrange
        $repo   = $this->buildRepo();
        $client = $this->buildClient();

        // Act
        $token = $repo->getNewToken($client, []);

        // Assert
        $this->assertInstanceOf(AccessTokenEntity::class, $token);
        $this->assertSame([], $token->getScopes(), 'No scopes must be attached');
        $this->assertNull($token->getUserIdentifier(),
            'User identifier must remain null when not provided');
    }

    /**
     * A scope this account's role may not grant throws `invalid_scope` by default —
     * `userId` 1 has no loadable `User` row, which `ScopePolicy::user()` reads as "nobody to
     * judge", refusing every scope that has a configured rule.
     */
    public function testGetNewTokenRefusesAScopeTheAccountMayNotGrant(): void
    {
        // Arrange
        $this->withOauthConfig(['scopes' => ['admin:write' => ['min_role' => 'admin']]]);
        $repo   = $this->buildRepo();
        $client = $this->buildClient();
        $scopes = [$this->buildScope('admin:write')];

        // Act / Assert
        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $this->expectExceptionMessage('The requested scope is invalid, unknown, or malformed');
        $repo->getNewToken($client, $scopes, 1);
    }

    /**
     * With `scope_refusal = drop`, a scope the account may not grant is dropped rather than
     * refusing the whole request — RFC 6749 §3.3 allows issuing fewer scopes than asked.
     */
    public function testGetNewTokenDropsARefusedScopeRatherThanRefusingWhenConfiguredTo(): void
    {
        // Arrange
        $this->withOauthConfig([
            'scope_refusal' => 'drop',
            'scopes'        => ['admin:write' => ['min_role' => 'admin']],
        ]);
        $repo   = $this->buildRepo();
        $client = $this->buildClient();
        $scopes = [$this->buildScope('admin:write'), $this->buildScope('profile:read')];

        // Act
        $token = $repo->getNewToken($client, $scopes, 1);

        // Assert — only the ungoverned scope survives
        $attached = array_map(fn ($s) => $s->getIdentifier(), $token->getScopes());
        $this->assertSame(['profile:read'], $attached);
    }

    /** A client-credentials request (no user identifier) is never judged by the scope policy. */
    public function testGetNewTokenWithNoUserIdentifierSkipsThePolicyEntirely(): void
    {
        // Arrange — a rule that would refuse everything, if it were even consulted
        $this->withOauthConfig(['scopes' => ['admin:write' => ['min_role' => 'admin']]]);
        $repo   = $this->buildRepo();
        $client = $this->buildClient();
        $scopes = [$this->buildScope('admin:write')];

        // Act
        $token = $repo->getNewToken($client, $scopes);

        // Assert — nothing was dropped or refused
        $attached = array_map(fn ($s) => $s->getIdentifier(), $token->getScopes());
        $this->assertSame(['admin:write'], $attached);
    }

    // =========================================================================
    // persistNewAccessToken()
    // =========================================================================

    /**
     * persistNewAccessToken() must INSERT one usertokens row with
     * tokentype='access_token', the resolved applicationid from the client's
     * apikey, the space-joined scope string and the expiry timestamp.
     */
    public function testPersistNewAccessTokenInsertsCorrectRow(): void
    {
        // Arrange — resolveAppId() lookup returns appid=9
        $qb = $this->buildQueryBuilderStub([['appid' => '9']]);
        $this->injectDb($this->buildDbMock($qb));

        $repo  = $this->buildRepo();
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-123');
        $token->setClient($this->buildClient('client-key-1'));
        $token->setUserIdentifier(7);
        $token->addScope($this->buildScope('openid'));
        $token->addScope($this->buildScope('email'));
        $expiry = new \DateTimeImmutable('+1 hour');
        $token->setExpiryDateTime($expiry);

        // Act
        $repo->persistNewAccessToken($token);

        // Assert — exactly one INSERT with the expected columns
        $this->assertCount(1, $this->insertedRows,
            'persistNewAccessToken() must issue exactly one INSERT');
        $row = $this->insertedRows[0];
        $this->assertSame('acc-123', $row['token']);
        $this->assertSame('access_token', $row['tokentype']);
        $this->assertSame(7, $row['userid']);
        $this->assertSame(9, $row['applicationid'],
            'applicationid must be resolved from the client apikey lookup');
        $this->assertSame('openid email', $row['scope'],
            'Scopes must be space-joined into one string');
        $this->assertSame($expiry->getTimestamp(), $row['expires'],
            'expires must be the expiry DateTime timestamp');
        $this->assertSame(1, $row['status'], 'New token must be active');
    }

    /**
     * persistNewAccessToken() must store applicationid=0 when the client's
     * apikey is not found in the applications table (orphan client).
     */
    public function testPersistNewAccessTokenWithUnknownClientStoresZeroAppId(): void
    {
        // Arrange — resolveAppId() lookup returns no rows
        $qb = $this->buildQueryBuilderStub([null]);
        $this->injectDb($this->buildDbMock($qb));

        $repo  = $this->buildRepo();
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-orphan');
        $token->setClient($this->buildClient('unknown-key'));
        $token->setUserIdentifier(1);
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));

        // Act
        $repo->persistNewAccessToken($token);

        // Assert
        $this->assertCount(1, $this->insertedRows);
        $this->assertSame(0, $this->insertedRows[0]['applicationid'],
            'applicationid must default to 0 when the apikey is unknown');
    }

    /**
     * persistNewAccessToken() must store applicationid=0 without performing a
     * DB lookup when the client identifier is empty (resolveAppId guard).
     */
    public function testPersistNewAccessTokenWithEmptyClientIdSkipsLookup(): void
    {
        // Arrange — no first() rows configured: a lookup would return not-found,
        // but the guard short-circuits before any query.
        $qb = $this->buildQueryBuilderStub([]);
        $this->injectDb($this->buildDbMock($qb));

        $repo  = $this->buildRepo();
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-noclient');
        $token->setClient($this->buildClient(''));
        $token->setUserIdentifier(5);
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));

        // Act
        $repo->persistNewAccessToken($token);

        // Assert
        $this->assertCount(1, $this->insertedRows);
        $this->assertSame(0, $this->insertedRows[0]['applicationid'],
            'Empty client identifier must short-circuit to applicationid=0');
    }

    /**
     * A client with its own access-token TTL has its expiry recomputed from that lifetime —
     * so the JWT's `exp`, the response's `expires_in` and this row all agree, rather than
     * trusting whatever League already set a moment earlier.
     */
    public function testPersistNewAccessTokenHonoursTheClientsOwnTtl(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['appid' => '9']]);
        $this->injectDb($this->buildDbMock($qb));

        $repo   = $this->buildRepo();
        $client = $this->buildClient('client-key-1');
        $client->setTokenLifetimes(120, null);
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-ttl');
        $token->setClient($client);
        $token->setUserIdentifier(7);
        // League's own, much longer, expiry -- must be overridden by the client's 120s.
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 day'));

        $before = time();

        // Act
        $repo->persistNewAccessToken($token);

        // Assert
        $row = $this->insertedRows[0];
        $this->assertGreaterThanOrEqual($before + 120, $row['expires']);
        $this->assertLessThan($before + 130, $row['expires'], 'the client TTL must win over the day-long expiry');
    }

    /** With no expiry set at all, the stored row's expires is 0, not a fatal. */
    public function testPersistNewAccessTokenWithNoExpiryStoresZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['appid' => '9']]);
        $this->injectDb($this->buildDbMock($qb));

        $repo  = $this->buildRepo();
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-noexpiry');
        $token->setClient($this->buildClient('client-key-1'));
        $token->setUserIdentifier(7);

        // Act
        $repo->persistNewAccessToken($token);

        // Assert
        $this->assertSame(0, $this->insertedRows[0]['expires']);
    }

    /**
     * A client-credentials token (no end user) is filed under the client's own system
     * account, not under userid 0 — which is not a row in `users` and used to fail the
     * insert outright.
     */
    public function testPersistNewAccessTokenWithNoUserFallsBackToTheSystemAccount(): void
    {
        // Arrange — the resolver seam, since resolveSystemUserId() itself needs a real
        // Auth\Application/DB round trip that this mock-only test file does not set up.
        $qb = $this->buildQueryBuilderStub([['appid' => '9']]);
        $this->injectDb($this->buildDbMock($qb));

        $repo = new class ($this->createMock(\Pramnos\Application\Controller::class)) extends AccessTokenRepository {
            public ?string $resolvedFor = null;

            protected function resolveSystemUserId(string $clientIdentifier): int
            {
                $this->resolvedFor = $clientIdentifier;

                return 314;
            }
        };
        $token = new AccessTokenEntity();
        $token->setIdentifier('acc-clientcreds');
        $token->setClient($this->buildClient('client-key-1'));
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
        // No setUserIdentifier() at all: League's own default for client_credentials.

        // Act
        $repo->persistNewAccessToken($token);

        // Assert
        $this->assertSame('client-key-1', $repo->resolvedFor);
        $this->assertSame(314, $this->insertedRows[0]['userid']);
    }

    // =========================================================================
    // resolveSystemUserId()
    // =========================================================================

    /** An empty client identifier short-circuits to 0 without touching the database. */
    public function testResolveSystemUserIdWithEmptyClientIdentifierIsZero(): void
    {
        // Arrange
        $repo   = $this->buildRepo();
        $method = new \ReflectionMethod(AccessTokenRepository::class, 'resolveSystemUserId');

        // Act / Assert
        $this->assertSame(0, $method->invoke($repo, ''));
    }

    /**
     * A client that cannot be loaded (an apikey nobody has, or a broken lookup) answers 0
     * rather than throwing — an insert under an invented account is worse than the failure
     * that already existed.
     */
    public function testResolveSystemUserIdAnswersZeroWhenTheClientCannotBeLoaded(): void
    {
        // Arrange — no real DB connection is configured for this mock-only test file, so the
        // real Auth\Application's loadByApiKey() throws, which the method's own catch reads
        // the same way it reads "not found".
        $repo   = $this->buildRepo();
        $method = new \ReflectionMethod(AccessTokenRepository::class, 'resolveSystemUserId');

        // Act / Assert — the point is that this does not throw past resolveSystemUserId()
        $this->assertSame(0, $method->invoke($repo, 'no-such-client'));
    }

    // =========================================================================
    // revokeAccessToken()
    // =========================================================================

    /**
     * revokeAccessToken() must issue an UPDATE setting status=0 for the row
     * matching the token identifier and tokentype='access_token'.
     */
    public function testRevokeAccessTokenSetsStatusToZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub();
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act
        $repo->revokeAccessToken('acc-revoke-me');

        // Assert
        $this->assertCount(1, $this->updatedRows,
            'revokeAccessToken() must issue exactly one UPDATE');
        $this->assertSame(0, $this->updatedRows[0]['status'],
            'UPDATE must set status=0 (revoked)');
    }

    // =========================================================================
    // isAccessTokenRevoked()
    // =========================================================================

    /**
     * isAccessTokenRevoked() must return true when no row exists for the
     * token — an unknown token must never be accepted.
     */
    public function testIsAccessTokenRevokedReturnsTrueWhenNotFound(): void
    {
        // Arrange — first() returns numRows=0
        $qb = $this->buildQueryBuilderStub([null]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertTrue($repo->isAccessTokenRevoked('unknown'),
            'Absent token must count as revoked');
    }

    /**
     * isAccessTokenRevoked() must return true when the row exists with status=0.
     */
    public function testIsAccessTokenRevokedReturnsTrueWhenStatusZero(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['status' => '0']]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertTrue($repo->isAccessTokenRevoked('revoked-token'),
            'status=0 must count as revoked');
    }

    /**
     * isAccessTokenRevoked() must return false when the row exists with status=1.
     */
    public function testIsAccessTokenRevokedReturnsFalseWhenStatusOne(): void
    {
        // Arrange
        $qb = $this->buildQueryBuilderStub([['status' => '1']]);
        $this->injectDb($this->buildDbMock($qb));
        $repo = $this->buildRepo();

        // Act + Assert
        $this->assertFalse($repo->isAccessTokenRevoked('active-token'),
            'status=1 means the token is still active');
    }

    /**
     * isAccessTokenRevoked() must return true when first() returns null
     * (defensive null-result handling).
     */
    public function testIsAccessTokenRevokedReturnsTrueWhenFirstReturnsNull(): void
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
        $this->assertTrue($repo->isAccessTokenRevoked('null-result'),
            'A null DB result must count as revoked');
    }
}
