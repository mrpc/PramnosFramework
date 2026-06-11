<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\PermissionsController;
use Pramnos\User\User;

class TestablePermissionsController extends PermissionsController
{
    public array $redirectedTo = [];

    public function redirect($url = null, $quit = true, $code = '302')
    {
        if ($url === null) {
            $url = 'default_redirect';
        }
        $this->redirectedTo[] = $url;
        throw new \RuntimeException('redirect_quit');
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new class {
            public mixed $permissions;
            public mixed $permission;
            public mixed $total;
            public mixed $page;
            
            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        return $view;
    }
}

class PermissionsControllerTest extends TestCase
{
    private TestablePermissionsController $controller;

    protected function setUp(): void
    {
        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = null;

        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db->query("DROP TABLE IF EXISTS `#PREFIX#authserver_permissions`");
        $db->query("CREATE TABLE `#PREFIX#authserver_permissions` (
            `permissionid` int(11) NOT NULL AUTO_INCREMENT,
            `subject_type` varchar(50) NOT NULL,
            `subject_id` int(11) NOT NULL,
            `object_type` varchar(50) NOT NULL,
            `object_id` varchar(255) DEFAULT NULL,
            `action` varchar(50) NOT NULL,
            `grant_type` enum('allow','deny') NOT NULL DEFAULT 'allow',
            `priority` int(11) NOT NULL DEFAULT '100',
            `granted_by` int(11) DEFAULT NULL,
            `granted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`permissionid`)
        )");
        $db->query("INSERT INTO `#PREFIX#authserver_permissions` (`permissionid`, `subject_type`, `subject_id`, `object_type`, `action`) VALUES (1, 'user', 2, 'reports', 'view')");

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestablePermissionsController($app);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (isset($doc->themeObject) && $doc->themeObject instanceof \stdClass) {
            unset($doc->themeObject);
        }
        $_SESSION = [];
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function setMockUser(int $usertype): void
    {
        $_SESSION['logged'] = true;
        $_SESSION['login'] = true;
        $_SESSION['userid'] = 2;
        $_SESSION['uid'] = 2;
        $_SESSION['usertype'] = $usertype;
        $_SESSION['sessionid'] = 'dummy_session_id';

        $user = new User(0);
        $user->userid = 2;
        $user->usertype = $usertype;
        
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

    public function testRequireMinUserTypeRedirectsWhenBelowRequired(): void
    {
        $this->setMockUser(89); // Required is 90

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testRequireMinUserTypeRedirectsWhenNullUser(): void
    {
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testDisplayShowsPermissions(): void
    {
        $this->setMockUser(90);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Permissions', $doc->title);
    }

    public function testEditDisplaysFormForNewPermission(): void
    {
        $this->setMockUser(90);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->edit(0);
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('New Permission', $doc->title);
    }

    public function testEditDisplaysFormForExistingPermission(): void
    {
        $this->setMockUser(90);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->edit(1);
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Edit Permission', $doc->title);
    }

    public function testEditRedirectsWhenNotFound(): void
    {
        $this->setMockUser(90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->edit(999);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=not_found', $this->controller->redirectedTo[0]);
        }
    }

    public function testSaveCreatesNewPermission(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'permissionid' => 0,
            'subject_type' => 'role',
            'subject_id' => 1,
            'object_type' => 'users',
            'action' => 'edit'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->save();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=saved', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('authserver.permissions')->where('subject_type', 'role')->first();
            $this->assertNotNull($result);
            $this->assertEquals('users', $result->fields['object_type']);
        }
    }

    public function testSaveUpdatesExistingPermission(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'permissionid' => 1,
            'subject_type' => 'user',
            'subject_id' => 2,
            'object_type' => 'reports',
            'action' => 'delete', // changed
            'grant_type' => 'deny' // changed
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->save();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=saved', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('authserver.permissions')->where('permissionid', 1)->first();
            $this->assertEquals('delete', $result->fields['action']);
            $this->assertEquals('deny', $result->fields['grant_type']);
        }
    }

    public function testSaveRedirectsWhenMissingFields(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'subject_type' => ''
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->save();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=required_fields', $this->controller->redirectedTo[0]);
        }
    }

    public function testDeleteRemovesPermission(): void
    {
        $this->setMockUser(90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->delete(1);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=deleted', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('authserver.permissions')->where('permissionid', 1)->first();
            $this->assertTrue(!$result || $result->numRows === 0);
        }
    }

    public function testDeleteRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->delete(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_id', $this->controller->redirectedTo[0]);
        }
    }

    public function testAssignCreatesPermissionForUser(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'userid' => 3,
            'object_type' => 'settings',
            'action' => '*'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->assign();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=assigned', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('authserver.permissions')->where('subject_id', 3)->first();
            $this->assertNotNull($result);
            $this->assertEquals('settings', $result->fields['object_type']);
            $this->assertEquals('*', $result->fields['action']);
        }
    }

    public function testAssignRedirectsWhenMissingFields(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'userid' => 0
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->assign();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=required_fields', $this->controller->redirectedTo[0]);
        }
    }
}
