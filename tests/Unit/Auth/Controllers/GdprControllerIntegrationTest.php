<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Gdpr;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\User\User;

class TestableGdprController extends Gdpr
{
    public $mockJsonBody = [];

    protected function readJsonBody(): array
    {
        return $this->mockJsonBody;
    }
}

class TestDatabase extends Database {
    public function __construct() {}
    public function __destruct() {}
}

class GdprControllerIntegrationTest extends TestCase
{
    private ?TestableGdprController $controller = null;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;
    private $originalUser;

    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;

        // Mock User
        $userMock = $this->createMock(User::class);
        $userMock->userid = 100;
        
        $appRef = \Pramnos\Application\Application::getInstance();
        if ($appRef) {
            $this->originalUser = $appRef->currentUser;
            $appRef->currentUser = $userMock;
        }

        // Setup CSRF token
        $session = \Pramnos\Http\Session::getInstance();
        $session->regenerateToken();

        // Simulate login
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 100;
        $_SESSION['user_id'] = 100;
        $_SESSION['is_admin'] = false;

        $property = new \ReflectionProperty(\Pramnos\User\User::class, '_usercache');
        $property->setValue(null, [100 => $userMock]);

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('orWhere')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('limit')->willReturnSelf();
        $this->queryBuilderMock->method('distinct')->willReturnSelf();
        $this->queryBuilderMock->method('groupBy')->willReturnSelf();
        
        $webhookResult = new class {
            public int $numRows = 0;
            public function fetch(): bool { return false; }
        };
        $this->queryBuilderMock->method('get')->willReturn($webhookResult);

        // Mock Database
        $this->dbMock = $this->createMock(TestDatabase::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);
        $this->dbMock->method('prepareQuery')->willReturn('mock_query');

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $_SERVER['HTTP_AUTHORIZATION'] = '';

        $this->controller = new TestableGdprController(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Restore original database
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;

        $this->controller = null;
        $this->dbMock = null;
    }

    public function testRequestInvalidType()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller->mockJsonBody = ['request_type' => 'invalid'];

        ob_start();
        $this->controller->request();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Invalid request_type', $echoed);
        $this->assertEquals(400, http_response_code());
    }

    public function testRequestValidType()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller->mockJsonBody = ['request_type' => 'export'];


        $this->dbMock->method('query')->willReturn(true);
        $this->dbMock->method('getInsertId')->willReturn(42);

        ob_start();
        $this->controller->request();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(42, $data['request_id']);
        $this->assertEquals('export', $data['request_type']);
        $this->assertEquals(100, $data['user_id']);
    }

    public function testStatusMissingId()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        ob_start();
        $this->controller->status();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Missing request_id', $echoed);
        $this->assertEquals(400, http_response_code());
    }

    public function testStatusFound()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['request_id'] = 42;

        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['request_id' => 42, 'status' => 'pending'];
        $this->dbMock->method('query')->willReturn($mockResult);

        ob_start();
        $this->controller->status();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertEquals(42, $data['request']['request_id']);
        $this->assertEquals('pending', $data['request']['status']);
    }

    public function testStatusNotFound()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['request_id'] = 42;

        $mockResult = new \stdClass();
        $mockResult->numRows = 0;
        $this->dbMock->method('query')->willReturn($mockResult);

        ob_start();
        $this->controller->status();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('GDPR request not found', $echoed);
        $this->assertEquals(404, http_response_code());
    }


    public function testListRequests()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['page'] = 1;
        $_GET['limit'] = 10;

        $mockResult1 = new class {
            public array $fields = ['request_id' => 42, 'status' => 'pending'];
            private int $callCount = 0;
            public function fetch(): bool { return $this->callCount++ === 0; }
        };

        $mockResult2 = new \stdClass();
        $mockResult2->fields = ['total' => 1];

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($mockResult1, $mockResult2);

        ob_start();
        $this->controller->listRequests();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertIsArray($data['requests']);
        $this->assertCount(1, $data['requests']);
        $this->assertEquals(42, $data['requests'][0]['request_id']);
        $this->assertEquals(1, $data['pagination']['total']);
    }

    public function testDeauthorizeAllValidReason()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller->mockJsonBody = ['reason' => 'user_revoked'];


        $mockResult = new class {
            public function getAffectedRows(): int { return 5; }
        };

        $this->dbMock->method('query')->willReturn($mockResult);

        ob_start();
        $this->controller->deauthorizeAll();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(5, $data['total_tokens_revoked']);
    }

    public function testDeauthorizeAllInvalidReason()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller->mockJsonBody = ['reason' => 'invalid_reason'];

        ob_start();
        $this->controller->deauthorizeAll();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Invalid reason', $echoed);
        $this->assertEquals(400, http_response_code());
    }

    public function testNotifyChange()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller->mockJsonBody = ['changes' => ['email', 'name']];

        $this->dbMock->method('query')->willReturn(true);

        ob_start();
        $this->controller->notifyChange();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertContains('email', $data['changes']);
    }

    // ── Unauthenticated paths (401) ───────────────────────────────────────────

    /**
     * Clear every session key resolveActor() reads so the controller sees an
     * anonymous visitor.
     */
    private function clearActorSession(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['is_admin']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * request() without any session identity must return 401 — anonymous
     * visitors cannot create GDPR requests.
     */
    public function testRequestUnauthenticatedReturns401(): void
    {
        // Arrange
        $this->clearActorSession();
        $this->controller->mockJsonBody = ['request_type' => 'export'];

        // Act
        ob_start();
        $this->controller->request();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    /**
     * status() with a request_id but no identity must return 401 — the id
     * check comes first, then the auth check.
     */
    public function testStatusUnauthenticatedReturns401(): void
    {
        // Arrange
        $this->clearActorSession();
        $_GET['request_id'] = 5;

        // Act
        ob_start();
        $this->controller->status();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    /**
     * listRequests() without identity must return 401 so request history is
     * never exposed to anonymous visitors.
     */
    public function testListRequestsUnauthenticatedReturns401(): void
    {
        // Arrange
        $this->clearActorSession();

        // Act
        ob_start();
        $this->controller->listRequests();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    /**
     * deauthorizeAll() without identity must return 401 — only the user (or
     * an admin) may revoke tokens.
     */
    public function testDeauthorizeAllUnauthenticatedReturns401(): void
    {
        // Arrange
        $this->clearActorSession();
        $this->controller->mockJsonBody = ['reason' => 'user_revoked'];

        // Act
        ob_start();
        $this->controller->deauthorizeAll();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    /**
     * notifyChange() without identity must return 401.
     */
    public function testNotifyChangeUnauthenticatedReturns401(): void
    {
        // Arrange
        $this->clearActorSession();
        $this->controller->mockJsonBody = ['changes' => ['email']];

        // Act
        ob_start();
        $this->controller->notifyChange();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    // ── Validation branches ───────────────────────────────────────────────────

    /**
     * notifyChange() with an empty changes list must return 400 — there is
     * nothing to notify endpoints about.
     */
    public function testNotifyChangeEmptyChangesReturns400(): void
    {
        // Arrange — authenticated (setUp session) but no changes
        $this->controller->mockJsonBody = ['changes' => []];

        // Act
        ob_start();
        $this->controller->notifyChange();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('No changes specified', $echoed);
        $this->assertEquals(400, http_response_code());
    }

    // ── Admin override paths ──────────────────────────────────────────────────

    /**
     * An admin actor providing user_id in the body must create the request
     * for THAT user (target override), not for themselves.
     */
    public function testRequestAdminOverridesTargetUser(): void
    {
        // Arrange — promote the session actor to admin
        $_SESSION['is_admin'] = true;
        $this->controller->mockJsonBody = ['request_type' => 'delete', 'user_id' => 555];
        $this->dbMock->method('query')->willReturn(true);
        $this->dbMock->method('getInsertId')->willReturn(77);

        // Act
        ob_start();
        $this->controller->request();
        $echoed = ob_get_clean();

        // Assert — the request targets user 555, requested by the admin (100)
        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(555, $data['user_id'],
            'Admin-provided user_id must become the request target');
        $this->assertEquals('delete', $data['request_type']);
    }

    /**
     * An admin listing requests with a user_id query filter must apply that
     * filter; without it, all requests are listed (1=1 branch).
     */
    public function testListRequestsAdminPaths(): void
    {
        // Arrange — admin actor, explicit user filter
        $_SESSION['is_admin'] = true;
        $_GET['user_id'] = 42;

        $listResult = new class {
            public array $fields = ['request_id' => 1, 'status' => 'pending'];
            private int $calls = 0;
            public function fetch(): bool { return $this->calls++ === 0; }
        };
        $countResult = new \stdClass();
        $countResult->fields = ['total' => 1];
        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($listResult, $countResult);

        // Act
        ob_start();
        $this->controller->listRequests();
        $echoed = ob_get_clean();

        // Assert
        $data = json_decode($echoed, true);
        $this->assertCount(1, $data['requests']);
        $this->assertEquals(1, $data['pagination']['total']);
    }

    /**
     * deauthorizeAll() as admin with a body user_id must revoke tokens for
     * the target user, not the admin.
     */
    public function testDeauthorizeAllAdminOverridesTarget(): void
    {
        // Arrange
        $_SESSION['is_admin'] = true;
        $this->controller->mockJsonBody = ['reason' => 'admin_revoked', 'user_id' => 321];
        $mockResult = new class {
            public function getAffectedRows(): int { return 3; }
        };
        $this->dbMock->method('query')->willReturn($mockResult);

        // Act
        ob_start();
        $this->controller->deauthorizeAll();
        $echoed = ob_get_clean();

        // Assert
        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(321, $data['user_id'],
            'Admin-provided user_id must be the deauthorization target');
        $this->assertEquals(3, $data['total_tokens_revoked']);
    }

    // ── Bearer-token actor resolution ─────────────────────────────────────────

    /**
     * resolveActor() must accept a Bearer access token: the usertokens lookup
     * row supplies the userid and is_admin flag, and the endpoint proceeds.
     */
    public function testBearerTokenActorIsResolved(): void
    {
        // Arrange — no session identity, only a Bearer header
        $this->clearActorSession();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-access-token';
        $this->controller->mockJsonBody = ['changes' => ['email']];

        $tokenRow = new \stdClass();
        $tokenRow->numRows = 1;
        $tokenRow->fields  = ['userid' => 88, 'is_admin' => 0];
        $this->dbMock->method('query')->willReturn($tokenRow);

        // Act — notifyChange is the cheapest endpoint after auth
        ob_start();
        $this->controller->notifyChange();
        $echoed = ob_get_clean();

        // Assert — authenticated as user 88 via the token
        $data = json_decode($echoed, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(88, $data['user_id'],
            'The Bearer token row must supply the actor user id');
    }

    /**
     * A Bearer token with no matching active usertokens row must resolve to
     * an anonymous actor → 401.
     */
    public function testBearerTokenNotFoundReturns401(): void
    {
        // Arrange
        $this->clearActorSession();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired-or-unknown';
        $this->controller->mockJsonBody = ['changes' => ['email']];

        $emptyRow = new \stdClass();
        $emptyRow->numRows = 0;
        $emptyRow->fields  = [];
        $this->dbMock->method('query')->willReturn($emptyRow);

        // Act
        ob_start();
        $this->controller->notifyChange();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Authentication required', $echoed);
        $this->assertEquals(401, http_response_code());
    }

    // ── Exception paths (500) ─────────────────────────────────────────────────

    /**
     * A database failure while inserting the GDPR request must surface as a
     * 500 JSON error, never as an uncaught exception.
     */
    public function testRequestDbFailureReturns500(): void
    {
        // Arrange — query() blows up on the INSERT
        $this->controller->mockJsonBody = ['request_type' => 'export'];
        $this->dbMock->method('query')
            ->willThrowException(new \Exception('insert blew up'));

        // Act
        ob_start();
        $this->controller->request();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Failed to create GDPR request', $echoed);
        $this->assertEquals(500, http_response_code());
    }

    /**
     * A database failure while revoking tokens must surface as 500.
     */
    public function testDeauthorizeAllDbFailureReturns500(): void
    {
        // Arrange
        $this->controller->mockJsonBody = ['reason' => 'user_revoked'];
        $this->dbMock->method('query')
            ->willThrowException(new \Exception('update blew up'));

        // Act
        ob_start();
        $this->controller->deauthorizeAll();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('Failed to deauthorize user', $echoed);
        $this->assertEquals(500, http_response_code());
    }
}
