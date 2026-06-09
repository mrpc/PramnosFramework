<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SettingsController;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Application\Settings;

class TestableSettingsController extends SettingsController
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
            public $settings = [];
            public $key = '';
            public $value = '';
            public $isNew = false;
            
            public function __construct($name) { 
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "";
                if ($layout === 'default') {
                    $out = "Settings System Display";
                } elseif ($layout === 'list') {
                    $out = "Settings List Display";
                } elseif ($layout === 'edit') {
                    if ($this->isNew) {
                        $out = "Edit New Setting";
                    } else {
                        $out = "Edit Setting: " . $this->key . " = " . $this->value;
                    }
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

class SettingsControllerIntegrationTest extends TestCase
{
    private TestableSettingsController $controller;
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

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);
        $this->dbMock->method('prepareQuery')->willReturn('MOCKED_QUERY');
        
        $mockDbResult = new \stdClass();
        $mockDbResult->numRows = 0;
        $mockDbResult->fields = [];
        $this->dbMock->method('query')->willReturn($mockDbResult);

        // Inject Database via reference
        $dbRef = $this->dbMock;
        
        // Inject Database into Settings
        Settings::setDatabase($this->dbMock, false);

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableSettingsController(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Reset DB singleton to null so subsequent tests get a fresh real connection
        // (a cloned DB object does not reliably preserve the mysqli connection resource).
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        Settings::clearSettings();
    }

    public function testDisplay()
    {
        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('Settings System Display', $echoed);
    }

    public function testSaveSystem()
    {
        $_POST['sitename'] = 'My Test Site';
        $_POST['debug'] = 'yes';
        
        ob_start();
        $this->controller->saveSystem();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('My Test Site', Settings::getSetting('sitename'));
        $this->assertEquals('yes', Settings::getSetting('debug'));
    }

    public function testList()
    {
        $this->queryBuilderMock->method('getAll')->willReturn([
            ['setting' => 'test_key', 'value' => 'test_val']
        ]);

        ob_start();
        $this->controller->list();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Settings List Display', $echoed);
    }

    public function testEditNew()
    {
        $_GET['_option'] = ''; // new setting

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit New Setting', $echoed);
    }

    public function testEditExisting()
    {
        $_GET['_option'] = 'existing_key';
        Settings::setSetting('existing_key', 'existing_val', false);

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit Setting: existing_key = existing_val', $echoed);
    }

    public function testEditReadonly()
    {
        $_GET['_option'] = 'hostname'; // protected

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('This setting is read-only and cannot be modified.', $_SESSION['settings_error']);
    }

    public function testSave()
    {
        $_POST['key'] = 'new_key';
        $_POST['value'] = 'new_val';

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('new_val', Settings::getSetting('new_key'));
    }

    public function testSaveEmptyKey()
    {
        $_POST['key'] = '';

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('Setting key must not be empty.', $_SESSION['settings_error']);
    }

    public function testSaveReadonly()
    {
        $_POST['key'] = 'hostname'; // protected

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('This setting is read-only and cannot be modified.', $_SESSION['settings_error']);
    }

    public function testDelete()
    {
        $_GET['_option'] = 'some_key';

        ob_start();
        $this->controller->delete();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testDeleteReadonly()
    {
        $_GET['_option'] = 'hostname'; // protected

        ob_start();
        $this->controller->delete();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        // It shouldn't attempt to delete.
    }
}
