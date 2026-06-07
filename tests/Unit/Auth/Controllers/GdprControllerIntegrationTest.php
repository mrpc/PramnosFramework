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
}
