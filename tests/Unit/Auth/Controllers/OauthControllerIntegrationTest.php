<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

class TestableOauth extends Oauth
{
    public $loggedInUser = null;

    protected function terminate(): void
    {
        // bypass exit; for tests
    }

    protected function getLoggedInUser(): ?\Pramnos\User\User
    {
        return $this->loggedInUser;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }
    
    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class($name) {
            public $apps = [];
            public function __construct($name) {
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "oauth-view";
                if ($return) return $out;
                echo $out;
                return true;
            }
            public function assign(string $key, mixed $val): void
            {
                $this->$key = $val;
            }
        };
        return $view;
    }
}

class OauthControllerIntegrationTest extends TestCase
{
    private TestableOauth $controller;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;

    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableOauth(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Restore original database
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;
    }

    public function testDisplay()
    {
        $mockResult = new class {
            public $numRows = 1;
            public $fields = ['appid' => 1, 'name' => 'App 1', 'description' => '', 'apikey' => 'key', 'status' => 1, 'created' => '2023-01-01'];
            private $fetched = false;
            public function fetch() {
                if (!$this->fetched) {
                    $this->fetched = true;
                    return true;
                }
                return false;
            }
        };
        
        $this->queryBuilderMock->method('get')->willReturn($mockResult);

        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('oauth-view', $echoed);
    }

    public function testAuthorizeNoLogin()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'redirect_uris' => 'http://localhost/callback'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        ob_start();
        $this->controller->authorize();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('login?return_url=', $echoed);
    }

    public function testAuthorizeWithLogin()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'redirect_uris' => 'http://localhost/callback', 'scope' => 'profile'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        $user = $this->createMock(\Pramnos\User\User::class);
        $user->userid = 10;
        $user->username = 'testuser';
        $this->controller->loggedInUser = $user;

        ob_start();
        $this->controller->authorize();
        $echoed = ob_get_clean();

        // should display the consent form or auto-approve if already authorized
        $this->assertIsString($echoed);
    }

    public function testAuthorizePostConsent()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['decision'] = 'approve';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'redirect_uris' => 'http://localhost/callback', 'scope' => 'profile'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        $user = $this->createMock(\Pramnos\User\User::class);
        $user->userid = 10;
        $user->username = 'testuser';
        $this->controller->loggedInUser = $user;

        ob_start();
        $this->controller->authorize();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
    }

    public function testRevokeMethodNotAllowed()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $response = $this->controller->revoke();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('method_not_allowed', $response->getBody());
        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testRevokeMissingToken()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        
        $response = $this->controller->revoke();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('Missing token parameter', $response->getBody());
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testRevokeSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['token'] = 'valid-token';

        $this->queryBuilderMock->expects($this->once())->method('update')->with(['status' => 0]);

        $response = $this->controller->revoke();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('{"success":true}', $response->getBody());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testIntrospectMethodNotAllowed()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('method_not_allowed', $response->getBody());
        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testIntrospectMissingToken()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'client_id' => '123',
            'client_secret' => 'secret'
        ];
        
        $this->queryBuilderMock->method('count')->willReturn(1);

        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('Missing token parameter', $response->getBody());
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testIntrospectSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'client_id' => '123',
            'client_secret' => 'secret',
            'token' => 'valid-token'
        ];
        
        // Mock validateCredentials count()
        $this->queryBuilderMock->method('count')->willReturn(1);
        
        // Mock token fetch
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['token' => 'valid-token', 'client_id' => '123', 'status' => 1, 'expires' => time() + 3600, 'scope' => 'read', 'userid' => 1, 'username' => 'testuser'];
        
        // We override first() to return the token data
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('active', $response->getBody());
    }

    public function testIntrospectInvalidClient()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['token'] = 'valid-token';
        // missing client_id and client_secret
        
        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('invalid_client', $response->getBody());
        $this->assertEquals(401, $response->getStatusCode());
    }
}
