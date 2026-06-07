<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\ServicesController;
use Pramnos\User\User;

class TestableServicesController extends ServicesController
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
            public mixed $services;
            public mixed $service;
            public mixed $lines;
            
            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        return $view;
    }
}

class ServicesControllerTest extends TestCase
{
    private TestableServicesController $controller;
    private string $stateFile;
    private string $logFile;
    private string $lockFile;

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

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableServicesController($app);

        // Reset globals
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];

        // Setup mock daemon state file
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        if (!is_dir($base . '/var')) {
            mkdir($base . '/var', 0777, true);
        }
        if (!is_dir($base . '/var/logs')) {
            mkdir($base . '/var/logs', 0777, true);
        }

        $this->stateFile = $base . '/var/daemon_orchestrator_state.json';
        $this->lockFile = $base . '/var/test_daemon.lock';
        $this->logFile = $base . '/var/logs/test-worker.log';

        $state = [
            [
                'id' => 'test-worker-id',
                'daemon' => 'test',
                'workerId' => 'worker',
                'pid' => 99999999, // Unlikely to be running
                'lockFile' => $this->lockFile,
            ]
        ];
        file_put_contents($this->stateFile, json_encode($state));
        file_put_contents($this->logFile, "Line 1\nLine 2\nLine 3\n");
        file_put_contents($this->lockFile, "99999999");
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

        // Clean up mock files
        if (file_exists($this->stateFile)) @unlink($this->stateFile);
        if (file_exists($this->lockFile)) @unlink($this->lockFile);
        if (file_exists($this->lockFile . '.stop')) @unlink($this->lockFile . '.stop');
        if (file_exists($this->logFile)) @unlink($this->logFile);
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
        $this->setMockUser(79); // Required is 80

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

    public function testDisplayShowsServices(): void
    {
        $this->setMockUser(80);
        
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
        $this->assertSame('Services', $doc->title);
    }

    public function testStopServiceCreatesStopFile(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->stop('test-worker-id');
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=stopped', $this->controller->redirectedTo[0]);
            $this->assertFileExists($this->lockFile . '.stop');
        }
    }

    public function testStopServiceRedirectsWhenNotFound(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->stop('non-existent');
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=not_found', $this->controller->redirectedTo[0]);
        }
    }

    public function testStartServiceRemovesStopFile(): void
    {
        $this->setMockUser(80);
        file_put_contents($this->lockFile . '.stop', '1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->start('test-worker-id');
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=started', $this->controller->redirectedTo[0]);
            $this->assertFileDoesNotExist($this->lockFile . '.stop');
        }
    }

    public function testRestartServiceRemovesStopFile(): void
    {
        $this->setMockUser(80);
        file_put_contents($this->lockFile . '.stop', '1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->restart('test-worker-id');
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=restarted', $this->controller->redirectedTo[0]);
            $this->assertFileDoesNotExist($this->lockFile . '.stop');
        }
    }

    public function testLogsShowsTail(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->logs('test-worker-id');
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertStringContainsString('Service Logs — test-worker-id', $doc->title);
    }

    public function testLogsRedirectsWhenNotFound(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->logs('non-existent');
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=not_found', $this->controller->redirectedTo[0]);
        }
    }

    public function testStatusReturnsJson(): void
    {
        $this->setMockUser(80);

        ob_start();
        $this->controller->status();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('total', $json);
        $this->assertArrayHasKey('running', $json);
        $this->assertArrayHasKey('stopped', $json);
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayHasKey('services', $json);
        $this->assertEquals(1, $json['total']);
    }
}
