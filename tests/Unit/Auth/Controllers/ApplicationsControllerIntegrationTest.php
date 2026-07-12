<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApplicationsController;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

class TestableApplicationsController extends ApplicationsController
{
    protected function requireMinUserType(int $minType): bool
    {
        return false; // bypass for tests
    }

    protected function terminate(): void
    {
        // Do nothing in tests to avoid exit;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }
    
    public function renderLayout(string $activeTab, string $content): void
    {
        echo $content;
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class($name) {
            public $application = null;
            public $app = null;
            public function __construct($name) { 
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "";
                if ($layout === 'default' || $layout === 'list') {
                    $out = "dt-applications";
                } elseif ($layout === 'edit') {
                    if (is_array($this->application) && isset($this->application['name'])) {
                        $out = "Edit App: " . $this->application['name'];
                    } else {
                        $out = "Edit New App";
                    }
                } elseif ($layout === 'view') {
                    $out = "View App: " . ($this->app['name'] ?? '');
                } elseif ($layout === 'tokens') {
                    $out = "Tokens App: " . ($this->app['name'] ?? '');
                }
                
                if ($return) {
                    return $out;
                }
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

class ApplicationsControllerIntegrationTest extends TestCase
{
    private TestableApplicationsController $controller;
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
        $this->queryBuilderMock->method('from')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('limit')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();
        $this->queryBuilderMock->method('count')->willReturn(1);

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableApplicationsController(null);
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
        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('dt-applications', $echoed);
    }

    public function testDataReturnsJson()
    {
        $mockResult = new class {
            public $numRows = 1;
            public $fields = ['appid' => 1, 'name' => 'Test App', 'apikey' => 'abc', 'status' => 1, 'added' => 123456];
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
        $this->controller->data();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('aaData', $data);
        $this->assertCount(1, $data['aaData']);
        $this->assertStringContainsString('Test App', $data['aaData'][0][1]);
    }

    public function testViewExisting()
    {
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['appid' => 1, 'name' => 'Existing App'];
        
        $this->queryBuilderMock->method('first')->willReturn($mockResult);
        $this->queryBuilderMock->method('get')->willReturn([]);

        ob_start();
        $this->controller->view(1);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View App: Existing App', $echoed);
    }

    public function testViewNotFound()
    {
        $this->queryBuilderMock->method('first')->willReturn(false);

        ob_start();
        $this->controller->view(999);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('error=not_found', $echoed);
    }

    public function testEditNew()
    {
        ob_start();
        $this->controller->edit(0);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit New App', $echoed);
    }

    public function testEditExisting()
    {
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['appid' => 1, 'name' => 'Existing App Edit'];
        
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        ob_start();
        $this->controller->edit(1);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit App: Existing App Edit', $echoed);
    }

    public function testEditNotFound()
    {
        $this->queryBuilderMock->method('first')->willReturn(false);

        ob_start();
        $this->controller->edit(999);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('error=not_found', $echoed);
    }

    public function testSaveCreate()
    {
        $_POST['name'] = 'New App';
        $_POST['appid'] = '0';

        $this->queryBuilderMock->expects($this->once())->method('insert')->willReturn(true);

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=saved', $echoed);
    }

    public function testSaveUpdate()
    {
        $_POST['name'] = 'Updated App';
        $_POST['appid'] = '1';

        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=saved', $echoed);
    }

    public function testSaveMissingName()
    {
        $_POST['name'] = '';
        $_POST['appid'] = '1';

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('error=name_required', $echoed);
    }

    public function testDelete()
    {
        $this->queryBuilderMock->expects($this->exactly(2))->method('update')->willReturn(true);

        ob_start();
        $this->controller->delete(1);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=deleted', $echoed);
    }

    public function testTokens()
    {
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['appid' => 1, 'name' => 'App With Tokens'];
        
        $this->queryBuilderMock->method('first')->willReturn($mockResult);
        $this->queryBuilderMock->method('get')->willReturn([]);

        ob_start();
        $this->controller->tokens(1);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Tokens App: App With Tokens', $echoed);
    }

    public function testRotate()
    {
        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->rotate(1);
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=secret_rotated', $echoed);
    }
}
