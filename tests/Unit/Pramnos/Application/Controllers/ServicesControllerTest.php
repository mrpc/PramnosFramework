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

    /**
     * stop() must redirect with error=no_lock_file when the service entry has
     * no lockFile configured. This guards against a missing file_put_contents
     * call on an empty path and covers lines 83-84 in stop().
     */
    public function testStopServiceRedirectsWithNoLockFileError(): void
    {
        // Arrange — overwrite state file with a service that has no lockFile
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $state = [
            ['id' => 'no-lock-service', 'daemon' => 'test', 'workerId' => 'w', 'pid' => 0]
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        // Act
        try {
            $this->controller->stop('no-lock-service');
        } finally {
            // Assert — must redirect with the no_lock_file error
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=no_lock_file', $this->controller->redirectedTo[0],
                'stop() must redirect with error=no_lock_file when the service has no lockFile');
        }
    }

    /**
     * start() calls clearStopFile() which must return early when the requested
     * service does not exist. The start redirect still fires because start() does
     * not verify that the service was found. Covers clearStopFile() line 306-307.
     */
    public function testStartWithNonExistentServiceStillRedirects(): void
    {
        // Arrange
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        // Act — 'nonexistent' is not in the state file; clearStopFile returns early
        try {
            $this->controller->start('nonexistent');
        } finally {
            // Assert — start() still redirects with message=started after clearStopFile no-ops
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=started', $this->controller->redirectedTo[0]);
        }
    }

    /**
     * findService() must return null immediately when called with an empty string.
     * An empty service ID means no service was specified; the early guard prevents
     * a full loadServiceList() scan for an inherently invalid key. Covers lines 287-288.
     */
    public function testFindServiceReturnsNullForEmptyId(): void
    {
        // Arrange
        $this->setMockUser(80);
        $method = new \ReflectionMethod(\Pramnos\Application\Controllers\ServicesController::class, 'findService');

        // Act — invoke private findService() with an empty string
        $result = $method->invoke($this->controller, '');

        // Assert
        $this->assertNull($result,
            'findService() must return null immediately for an empty service ID');
    }

    /**
     * When the running process is the test process itself (pid = getmypid()),
     * isProcessRunning() must return true and enrichServiceEntry() must set
     * status to "running". Covers the running-status branch (line 257) and
     * the uptime calculation (line 266) in enrichServiceEntry().
     */
    public function testEnrichServiceEntryStatusRunningWhenPidIsAlive(): void
    {
        // Arrange — create a lock file that exists and point the service to the
        // current test process PID so isProcessRunning() returns true
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $liveLockFile = $base . '/var/test_live.lock';
        file_put_contents($liveLockFile, (string) getmypid());

        $state = [
            [
                'id'       => 'live-service',
                'daemon'   => 'test',
                'workerId' => 'live',
                'pid'      => getmypid(),
                'lockFile' => $liveLockFile,
            ]
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $this->setMockUser(80);

        // Act — status() triggers loadServiceList() → enrichServiceEntry()
        ob_start();
        $this->controller->status();
        $output = ob_get_clean();
        @unlink($liveLockFile);

        // Assert — the service must be reported as 'running'
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertSame(1, $json['running'],
            'A service with a living PID and an existing lock file must have status=running');
    }

    /**
     * A service whose lockFile is absent AND has no stop sentinel must be
     * reported as "stopped" (not "error"). This is the clean-shutdown state
     * where the orchestrator did not create a lock file yet.
     * Covers the `!$hasLock && !$hasStop` branch at line 258-259.
     */
    public function testEnrichServiceEntryStatusStoppedWhenNoLockAndNoStop(): void
    {
        // Arrange — service with empty lockFile (→ hasLock = false, hasStop = false)
        $state = [
            [
                'id'       => 'no-lock-service',
                'daemon'   => 'test',
                'workerId' => 'w',
                'pid'      => 0,
                'lockFile' => '',
            ]
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $this->setMockUser(80);

        // Act
        ob_start();
        $this->controller->status();
        $output = ob_get_clean();

        // Assert — service must be counted as stopped, not error
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertSame(1, $json['stopped'],
            'A service with no lockFile and no stop sentinel must have status=stopped');
        $this->assertSame(0, $json['error']);
    }

    /**
     * readLogTail() must return an empty array when the log file does not exist.
     * The logs() action must still render without crashing. Covers lines 329-330.
     */
    public function testLogsActionShowsEmptyLinesWhenLogFileMissing(): void
    {
        // Arrange — delete the log file so readLogTail() cannot find it
        @unlink($this->logFile);
        $this->setMockUser(80);

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act
        ob_start();
        try {
            $result = $this->controller->logs('test-worker-id');
        } finally {
            ob_get_clean();
        }

        // Assert — must return the view string without crashing
        $this->assertNotNull($result, 'logs() must return a view even when the log file is missing');
    }

    /**
     * isProcessRunning() must return false when called with a PID of zero,
     * without invoking posix_kill() or any filesystem check. The early guard
     * at line 357-358 handles this case.
     */
    public function testIsProcessRunningReturnsFalseForZeroPid(): void
    {
        // Arrange
        $method = new \ReflectionMethod(
            \Pramnos\Application\Controllers\ServicesController::class,
            'isProcessRunning'
        );

        // Act — pid = 0 must return false immediately
        $result = $method->invoke($this->controller, 0);

        // Assert
        $this->assertFalse($result,
            'isProcessRunning(0) must return false — pid 0 is never a valid worker process');
    }

    /**
     * loadServiceList() must return an empty array when the state file contains
     * invalid JSON. A corrupt or truncated state file must not crash the dashboard.
     * Covers line 216 (empty/false json) and line 221 (non-array json_decode result).
     */
    public function testLoadServiceListReturnsEmptyForInvalidJson(): void
    {
        // Arrange — write syntactically invalid JSON to the state file
        file_put_contents($this->stateFile, '{ INVALID_JSON_DATA :::');

        $this->setMockUser(80);

        // Act
        ob_start();
        $this->controller->status();
        $output = ob_get_clean();

        // Assert — must return a valid response with 0 services
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertSame(0, $json['total'],
            'status() must report 0 services when the state file contains invalid JSON');
    }

    /**
     * loadServiceList() returns [] immediately when the state file does not exist
     * (line 211). This covers the first early-return guard in the method — a
     * missing file is not an error; the orchestrator simply hasn't started yet.
     */
    public function testStatusReturnsEmptyWhenStateFileMissing(): void
    {
        // Arrange — remove the state file created in setUp
        @unlink($this->stateFile);
        $this->setMockUser(80);

        // Act
        ob_start();
        $this->controller->status();
        $output = ob_get_clean();

        // Assert — no state file → zero services, no crash
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertSame(0, $json['total'],
            'status() must report 0 services when the state file does not exist');
    }
}
