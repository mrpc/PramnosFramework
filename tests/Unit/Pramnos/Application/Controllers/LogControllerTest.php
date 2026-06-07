<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\LogController;
use Pramnos\Application\Application;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;

class TestableLogController extends LogController
{
    public $redirectUrl = null;
    
    protected function terminate(): void
    {
        // Don't exit during tests
    }

    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->redirectUrl = $url;
    }
}

#[CoversClass(LogController::class)]
class LogControllerTest extends TestCase
{
    private TestableLogController $controller;
    private string $logDir;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', sys_get_temp_dir());
        }
        if (!defined('ROOT')) {
            define('ROOT', sys_get_temp_dir());
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $this->logDir = LOG_PATH . DS . 'logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        // Create dummy log files
        file_put_contents($this->logDir . DS . 'php_error.log', "Error 1\nError 2\nError 3\n");
        file_put_contents($this->logDir . DS . 'pramnosframework.log', "INFO App started\nINFO Route /logs\n");
        
        // Also ensure archive dir exists to prevent errors during archiving
        $archiveDir = LOG_PATH . DS . 'archives';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0777, true);
        }

        // Mock Application
        $appMock = $this->createMock(Application::class);
        $appMock->method('getExtraPaths')->willReturn([]);

        \Pramnos\Framework\Factory::getDocument('html');
        // Reset document state
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new \stdClass();

        $this->controller = new TestableLogController($appMock);
        
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        // Clean up dummy log files
        if (is_dir($this->logDir)) {
            $files = glob($this->logDir . DS . '*.log');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
        
        // Clean up archives
        $archiveDir = LOG_PATH . DS . 'archives';
        if (is_dir($archiveDir)) {
            $files = glob($archiveDir . DS . '*.zip');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (isset($doc->themeObject) && $doc->themeObject instanceof \stdClass) {
            unset($doc->themeObject);
        }

        $_SERVER = [];
        $_POST = [];
        $_GET = [];
    }

    public function testDisplayReturnsHtml(): void
    {
        ob_start();
        $result = $this->controller->display();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Logs/stats', $output);
    }

    public function testRawReturnsLogContent(): void
    {
        $_GET['file'] = 'php_error.log';
        $_GET['maxLines'] = 10;
        
        ob_start();
        $result = $this->controller->raw();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Error 1', $output);
        $this->assertStringContainsString('Error 2', $output);
    }

    public function testStatsShowsStatistics(): void
    {
        ob_start();
        $result = $this->controller->stats();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Log File Statistics', $output);
        $this->assertStringContainsString('php_error.log', $output);
        $this->assertStringContainsString('pramnosframework.log', $output);
    }

    public function testSearchFindsMatches(): void
    {
        $_POST['query'] = 'Error 2';
        
        ob_start();
        $result = $this->controller->search();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Search Results for "Error 2"', $output);
        $this->assertStringContainsString('php_error.log', $output);
    }

    public function testArchiveCreatesArchive(): void
    {
        // Change filemtime of one of the log files so it can be archived
        touch($this->logDir . DS . 'php_error.log', time() - (40 * 86400)); // 40 days old

        $_POST['action'] = 'archive';
        $_POST['days'] = 30;

        ob_start();
        $result = $this->controller->archive();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Successfully archived', $output);
    }

    public function testRotateLogs(): void
    {
        $_POST['action'] = 'rotate';
        $_POST['max_size'] = 10;
        $_POST['max_backups'] = 5;
        $_POST['files'] = ['php_error.log'];
        
        ob_start();
        $result = $this->controller->rotate();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Rotate Log Files', $output);
    }

    public function testDashboard(): void
    {
        $_GET['timespan'] = '1h';
        
        ob_start();
        $result = $this->controller->dashboard();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Logs Dashboard', $output);
    }

    public function testExportForm(): void
    {
        ob_start();
        $result = $this->controller->export();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Export Log Files', $output);
        $this->assertStringContainsString('Export Format:', $output);
    }

    public function testExportJson(): void
    {
        $_GET['format'] = 'json';
        $_GET['file'] = 'php_error.log';
        
        ob_start();
        $result = $this->controller->export();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('logs', $output);
        $this->assertStringContainsString('Error 1', $output);
    }

    public function testExportCsv(): void
    {
        $_GET['format'] = 'csv';
        $_GET['file'] = 'php_error.log';
        
        ob_start();
        $result = $this->controller->export();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('Timestamp,Level,Message,Context', $output);
        $this->assertStringContainsString('Error 1', $output);
    }

    public function testClearFile(): void
    {
        $this->controller->clearFile('php_error.log');
        $this->assertNotNull($this->controller->redirectUrl);
    }

    public function testClearLogs(): void
    {
        $this->controller->clear();
        
        $this->assertNotNull($this->controller->redirectUrl);
        // They should be truncated to 0 bytes
        $this->assertEquals(0, filesize($this->logDir . DS . 'php_error.log'));
        $this->assertEquals(0, filesize($this->logDir . DS . 'pramnosframework.log'));
    }
}
