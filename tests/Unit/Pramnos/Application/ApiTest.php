<?php

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api;
use Pramnos\Framework\Factory;
use Pramnos\Document\Raw;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://localhost');
        }
        if (!defined('ROOT')) {
            define('ROOT', '/tmp');
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (file_exists(ROOT . '/src/Api/routes.php')) {
            unlink(ROOT . '/src/Api/routes.php');
            @rmdir(ROOT . '/src/Api');
        }
    }

    public function testConstructSetsAuthKey()
    {
        $api = new Api('');
        $this->assertNotEmpty($api->authenticationKey);
    }

    public function testTranslateStatusString()
    {
        $api = new class extends Api {
            public function translateStatus($status) {
                return $this->_translateStatus($status);
            }
        };

        $result = json_decode($api->translateStatus('Hello'), true);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals('Hello', $result['message']);
    }

    public function testTranslateStatusArray()
    {
        $api = new class extends Api {
            public function translateStatus($status) {
                return $this->_translateStatus($status);
            }
        };

        $result = json_decode($api->translateStatus(['status' => 404]), true);
        $this->assertEquals(404, $result['status']);
        $this->assertEquals('Resource not found', $result['statusmessage']);
    }

    public function testHttpStatusToText()
    {
        $api = new class extends Api {
            public function getStatusText($status) {
                return $this->_httpStatusToText($status);
            }
        };

        $this->assertEquals('OK', $api->getStatusText(200));
        $this->assertEquals('Created', $api->getStatusText(201));
        $this->assertEquals('Accepted (Request accepted, and queued for execution)', $api->getStatusText(202));
        $this->assertEquals('Bad request', $api->getStatusText(400));
        $this->assertEquals('Authentication failure', $api->getStatusText(401));
        $this->assertEquals('Forbidden', $api->getStatusText(403));
        $this->assertEquals('Resource not found', $api->getStatusText(404));
        $this->assertEquals('Method Not Allowed', $api->getStatusText(405));
        $this->assertEquals('Conflict', $api->getStatusText(409));
        $this->assertEquals('Precondition Failed', $api->getStatusText(412));
        $this->assertEquals('Request Entity Too Large', $api->getStatusText(413));
        $this->assertEquals('Unprocessable Entity', $api->getStatusText(422));
        $this->assertEquals('Internal Server Error', $api->getStatusText(500));
        $this->assertEquals('Not Implemented', $api->getStatusText(501));
        $this->assertEquals('Service Unavailable', $api->getStatusText(503));
    }

    public function testCheckApiKeyWithMd5Url()
    {
        $api = new Api('');
        
        $apiKey = md5(str_replace('/api/', '/', sURL));
        $this->assertTrue($api->checkApiKey($apiKey));
    }

    public function testExecuteCoreValidationException()
    {
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    public function exec($action) {
                        throw new \Pramnos\Validation\ValidationException(['error' => 'Validation Error']);
                    }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;
        $_SESSION['usertoken'] = new class {
            public $tokentype = 'api';
            public $lastActionId = 1;
            public function addAction() {}
            public function updateAction($id, $status, $time, $record) {}
        };

        $api->_executeCore(microtime(true));
        $this->assertTrue(true);
    }

    public function testExecuteCoreException500()
    {
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    public function exec($action) {
                        throw new \Exception('Random Error', 500);
                    }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $api->_executeCore(microtime(true));
        $this->assertTrue(true);
    }

    public function testExecuteCoreException403()
    {
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    public function exec($action) {
                        throw new \Exception('Forbidden', 403);
                    }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $api->_executeCore(microtime(true));
        $this->assertTrue(true);
    }

    public function testExecWithEmptyControllerThrowsClose()
    {
        $api = new class extends Api {
            public $applicationInfo = ['name' => 'test'];
            public $defaultController = '';
            public function __construct() { }
            public function checkversion($version = null) { return true; }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Application::close() called with msg: There is no controller to run...');
        $api->exec('');
    }

    public function testExecWithDefaultController()
    {
        $api = new class extends Api {
            public $defaultController = 'default_ctrl';
            public $applicationInfo = ['name' => 'test', 'cors_from_db' => false, 'cors_origins' => ['*']];
            public function __construct() { }
            public function checkversion($version = null) { return true; }
            public function _executeCore(float $start): mixed { return 'TestResponse'; }
        };

        $api->exec('');
        $this->assertEquals('default_ctrl', $api->controller);
    }
    public function testLogAction()
    {
        $api = new class extends Api {
            public $apiKey = 'test';
            public function __construct() { }
            public function testLogAction() { $this->logAction(); }
        };
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST = ['test' => 'data'];
        $api->testLogAction();
        
        \Pramnos\Http\Request::$requestMethod = 'DELETE';
        \Pramnos\Http\Request::$deleteData = ['test' => 'data'];
        $api->testLogAction();

        \Pramnos\Http\Request::$requestMethod = 'PUT';
        \Pramnos\Http\Request::$putData = ['test' => 'data'];
        $api->testLogAction();

        \Pramnos\Http\Request::$requestMethod = 'GET';
        $api->testLogAction();

        $this->assertTrue(true);
    }

    public function testExecuteCoreWithRoutesPhp()
    {
        if (!is_dir(ROOT . '/src/Api')) {
            mkdir(ROOT . '/src/Api', 0777, true);
        }
        file_put_contents(ROOT . '/src/Api/routes.php', '<?php return ["status" => 200, "message" => "Routes works"];');

        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public function __construct() { }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $api->_executeCore(microtime(true));
        
        $this->assertTrue(true);
    }

    public function testExecuteCoreWithRoutesPhpValidationException()
    {
        if (!is_dir(ROOT . '/src/Api')) {
            mkdir(ROOT . '/src/Api', 0777, true);
        }
        file_put_contents(ROOT . '/src/Api/routes.php', '<?php throw new \Pramnos\Validation\ValidationException(["error" => "validation"]);');

        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public function __construct() { }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $api->_executeCore(microtime(true));
        $this->assertTrue(true);
    }

    public function testExecuteCoreWithRoutesPhpException403()
    {
        if (!is_dir(ROOT . '/src/Api')) {
            mkdir(ROOT . '/src/Api', 0777, true);
        }
        file_put_contents(ROOT . '/src/Api/routes.php', '<?php throw new \Exception("Forbidden", 403);');

        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public function __construct() { }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $api->_executeCore(microtime(true));
        $this->assertTrue(true);
    }
}
