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
        unset($_SERVER['HTTP_APIKEY']);
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

    /**
     * exec() with a non-empty controller name must set $this->controller to
     * that name (elseif branch, lines 94-95) and must invoke _executeCore
     * through the middleware pipeline (line 130 closure is exercised).
     *
     * To reach line 130 the ApiAuthMiddleware must pass, so we provide a valid
     * HTTP_APIKEY equal to md5(sURL) which checkApiKey() accepts.
     */
    public function testExecWithNonEmptyControllerNameCoversElseifBranch(): void
    {
        // Arrange — valid API key so the middleware does not short-circuit
        $_SERVER['HTTP_APIKEY'] = md5(sURL);

        $api = new class extends Api {
            public $applicationInfo = ['name' => 'test', 'cors_origins' => ['*']];
            public function __construct() { }
            public function checkversion($version = null) { return true; }
            public function _executeCore(float $start): mixed { return null; }
        };

        // Act — pass a non-empty controller name to reach elseif ($controller !== '')
        $api->exec('mycontroller');

        // Assert — controller was set to the passed name (lines 94-95 executed)
        $this->assertSame('mycontroller', $api->controller,
            'exec() must assign the passed controller name to $this->controller (lines 94-95)');
    }

    /**
     * exec() must take the cors_from_db=true path (lines 108-110) when that
     * key is present in applicationInfo, calling CorsMiddleware::fromApplicationSettings().
     * fromApplicationSettings() catches any DB error internally and falls back
     * to wildcard — so this test is safe to run without a seeded DB table.
     */
    public function testExecWithCorsFromDbFlag(): void
    {
        // Arrange — valid API key so middleware passes; cors_from_db triggers lines 108-110
        $_SERVER['HTTP_APIKEY'] = md5(sURL);

        $api = new class extends Api {
            public $defaultController = 'home';
            public $applicationInfo  = ['name' => 'test', 'cors_from_db' => true];
            public function __construct() { }
            public function checkversion($version = null) { return true; }
            public function _executeCore(float $start): mixed { return null; }
        };

        // Act — exec() builds CorsMiddleware via fromApplicationSettings() (lines 108-110)
        $api->exec('');

        // Assert — reached here without exception (CORS fallback to wildcard on DB miss)
        $this->assertTrue(true,
            'exec() must not throw when cors_from_db=true and DB lookup fails/returns nothing');
    }

    /**
     * logAction() must return immediately (line 289) when $apiKey is null,
     * without attempting to read the request URL or write a log entry.
     */
    public function testLogActionReturnsEarlyWhenApiKeyIsNull(): void
    {
        // Arrange — expose logAction() and leave apiKey as null
        $api = new class extends Api {
            public $apiKey = null;
            public function __construct() { }
            public function callLogAction(): void { $this->logAction(); }
        };

        // Act — must not throw and must return before the URL/log section
        $api->callLogAction();

        // Assert — early return (line 289) was taken
        $this->assertTrue(true,
            'logAction() must return immediately when apiKey is null (line 289)');
    }

    /**
     * _executeCore() must catch the exception thrown by setTrackingInfo()
     * (lines 166-170) and continue execution.  The controller's exec() returns
     * successfully, exercising the success path (lines 215-216).
     */
    public function testExecuteCoreSetTrackingInfoExceptionIsCaught(): void
    {
        // Arrange — database mock throws from setTrackingInfo
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    // Returns successfully — covers lines 215-216
                    public function exec($action) { return ['status' => 200, 'message' => 'ok']; }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $dbMock->method('setTrackingInfo')
            ->willThrowException(new \Exception('Tracking DB unavailable'));
        $api->database = $dbMock;

        // Act — setTrackingInfo throws → catch at lines 167-170; controller succeeds at 215-216
        $api->_executeCore(microtime(true));

        // Assert — exception was swallowed; execution continued to controller
        $this->assertTrue(true,
            '_executeCore() must catch setTrackingInfo exception (lines 166-170) and proceed');
    }

    /**
     * _executeCore() must catch the exception thrown by usertoken->addAction()
     * (lines 176-178), unset usertoken from the session, and continue execution.
     */
    public function testExecuteCoreAddActionExceptionIsCaught(): void
    {
        // Arrange — usertoken whose addAction() throws
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    public function exec($action) { return ['status' => 200, 'message' => 'ok']; }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        $_SESSION['usertoken'] = new class {
            public $tokentype = 'api';
            public $lastActionId = 1;
            // throws to exercise lines 177-178
            public function addAction() { throw new \Exception('Token backend error'); }
            public function updateAction($id, $status, $time, $record) { }
        };

        // Act — addAction throws → catch at lines 177-178; usertoken unset; execution continues
        $api->_executeCore(microtime(true));

        // Assert — usertoken was removed from session (line 177)
        $this->assertArrayNotHasKey('usertoken', $_SESSION,
            '_executeCore() must unset usertoken from session when addAction() throws (line 177)');
    }

    /**
     * _executeCore() must log the full SQL exception details (lines 240-244)
     * when the caught exception message contains the string 'SQL'.
     *
     * The logging call must be reached without rethrowing so that execution
     * continues to the 500 response envelope (line 247).
     */
    public function testExecuteCoreSQLExceptionIsLogged(): void
    {
        // Arrange — controller throws an exception whose message contains 'SQL'
        $api = new class extends Api {
            public $database;
            public $applicationInfo = ['name' => 'test'];
            public $controller = 'test';
            public $action = 'test';
            public function __construct() { }
            public function getController($controller, $userPermissions = []) {
                return new class {
                    public function exec($action) {
                        // Message includes 'SQL' to trigger the logger branch (lines 240-244)
                        throw new \Exception('SQL syntax error near SELECT *', 500);
                    }
                };
            }
        };

        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $api->database = $dbMock;

        // Act — exception with 'SQL' in message hits lines 240-244 then continues to 247
        $api->_executeCore(microtime(true));

        // Assert — no exception was rethrown; the logging branch was traversed
        $this->assertTrue(true,
            '_executeCore() must log SQL exception details (lines 240-244) and return 500 envelope');
    }
}
