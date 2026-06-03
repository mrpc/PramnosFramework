<?php

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\LogController;
use Pramnos\Application\Application;
use Pramnos\Http\Request;
use Pramnos\Framework\Factory;
use Pramnos\Logs\LogManager;

class TestableLogController extends LogController
{
    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }

    protected function terminate(): void
    {
        throw new \RuntimeException('TERMINATE_CALLED');
    }
}

class LogControllerTest extends TestCase
{
    protected $controller;
    protected string $testLogDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testLogDir = LOG_PATH . DS . 'logs';
        if (!is_dir($this->testLogDir)) {
            mkdir($this->testLogDir, 0777, true);
        }
        
        // Reset superglobals
        $_GET = [];
        $_POST = [];
        
        // Create a dummy log file
        file_put_contents($this->testLogDir . DS . 'test_log.log', "[2026-06-02 12:00:00] test log line\n");
        file_put_contents($this->testLogDir . DS . 'pramnosframework.log', "[2026-06-02 12:00:00] framework line\n");
        
        // Use testable subclass to prevent redirect() from calling exit() and to preserve coverage attribution
        $this->controller = new TestableLogController(null);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
        if (file_exists($this->testLogDir . DS . 'test_log.log')) {
            unlink($this->testLogDir . DS . 'test_log.log');
        }
        if (file_exists($this->testLogDir . DS . 'pramnosframework.log')) {
            unlink($this->testLogDir . DS . 'pramnosframework.log');
        }
        
        // Restore default document to html so we don't break subsequent tests
        Factory::getDocument('html');
    }

    public function testDisplay()
    {
        $_GET['file'] = 'test_log.log';
        
        // Capture output
        ob_start();
        $output = $this->controller->display();
        $echoed = ob_get_clean();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('test_log.log', $output);
        $this->assertStringContainsString('Clear Logs', $output);
    }

    public function testStats()
    {
        ob_start();
        $output = $this->controller->stats();
        $echoed = ob_get_clean();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('test_log.log', $output);
        $this->assertStringContainsString('Log Statistics', $output);
    }

    public function testRaw()
    {
        $_GET['file'] = 'test_log.log';
        $_GET['maxLines'] = '10';
        $_GET['page'] = '1';
        
        $output = $this->controller->raw();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('test log line', $output);
    }
    
    public function testRawInvalidFile()
    {
        $_GET['file'] = 'invalid_file.log';
        
        $output = $this->controller->raw();
        $this->assertStringContainsString('Invalid or no log file specified', $output);
    }

    public function testSearch()
    {
        $_POST['query'] = 'test log line';
        $_POST['context'] = '2';
        $_POST['case_sensitive'] = '1';
        
        $output = $this->controller->search();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('test log line', $output);
        $this->assertStringContainsString('Search Results for', $output);
    }

    public function testClearFile()
    {
        ob_start();
        try {
            @$this->controller->clearFile('test_log.log');
        } catch (\Exception $e) {
            // Might throw redirect exception depending on framework
        }
        $echoed = ob_get_clean();
        
        // Verify the file was cleared (LogManager::clearLog empties it)
        $content = file_get_contents($this->testLogDir . DS . 'test_log.log');
        $this->assertEquals('', $content);
        $this->assertStringContainsString('REDIRECTED_TO:logs', $echoed);
    }

    public function testArchive()
    {
        $_POST['action'] = 'archive';
        $_POST['days'] = '30';
        
        // Ensure there's an old file to archive
        $oldFile = $this->testLogDir . DS . 'old_test.log';
        file_put_contents($oldFile, "old data");
        touch($oldFile, time() - (31 * 86400));
        
        // Re-instantiate to pick up the new file
        $this->controller = new TestableLogController(null);
        
        $output = $this->controller->archive();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('Successfully archived', $output);
        
        // Cleanup archive
        $archives = glob($this->testLogDir . DS . 'archive_*.zip');
        foreach ($archives as $archive) {
            unlink($archive);
        }
        if (file_exists($oldFile)) unlink($oldFile);
    }

    public function testRotate()
    {
        $_POST['action'] = 'rotate';
        $_POST['max_size'] = '0'; // force rotate
        $_POST['max_backups'] = '1';
        $_POST['files'] = ['test_log.log'];
        
        $output = $this->controller->rotate();
        
        $this->assertIsString($output);
        $this->assertStringContainsString('test_log.log', $output);
        $this->assertStringContainsString('Rotated successfully', $output);
        
        if (file_exists($this->testLogDir . DS . 'test_log.log.1')) {
            unlink($this->testLogDir . DS . 'test_log.log.1');
        }
    }

    public function testExportShowsForm(): void
    {
        // No format/file GET params, should show the form
        $output = $this->controller->export();

        $this->assertIsString($output);
        $this->assertStringContainsString('Export Log Files', $output);
    }

    public function testExportCsv(): void
    {
        $_GET['format'] = 'csv';
        $_GET['file'] = 'test_log.log';

        // We expect it to try to clear/manipulate output buffers, then print CSV header + content, then throw TERMINATE_CALLED
        ob_start();
        try {
            $this->controller->export();
            $this->fail('Should have terminated');
        } catch (\RuntimeException $e) {
            $this->assertEquals('TERMINATE_CALLED', $e->getMessage());
        }
        $echoed = ob_get_clean();

        // CSV header contains Timestamp,Level,Message,Context
        $this->assertStringContainsString('Timestamp', $echoed);
        $this->assertStringContainsString('Level', $echoed);
        $this->assertStringContainsString('test log line', $echoed);
    }

    public function testExportJson(): void
    {
        $_GET['format'] = 'json';
        $_GET['file'] = 'test_log.log';

        ob_start();
        try {
            $this->controller->export();
            $this->fail('Should have terminated');
        } catch (\RuntimeException $e) {
            $this->assertEquals('TERMINATE_CALLED', $e->getMessage());
        }
        $echoed = ob_get_clean();

        $this->assertStringContainsString('logs', $echoed);
        $this->assertStringContainsString('test log line', $echoed);
    }

    public function testExportRaw(): void
    {
        $_GET['format'] = 'raw';
        $_GET['file'] = 'test_log.log';

        ob_start();
        try {
            $this->controller->export();
            $this->fail('Should have terminated');
        } catch (\RuntimeException $e) {
            $this->assertEquals('TERMINATE_CALLED', $e->getMessage());
        }
        $echoed = ob_get_clean();

        $this->assertStringContainsString('test log line', $echoed);
    }

    public function testExportInvalidFile(): void
    {
        $_GET['format'] = 'csv';
        $_GET['file'] = 'invalid_file.log';

        // Should fall through to the form (invalid file not in whitelist)
        $output = $this->controller->export();
        $this->assertIsString($output);
        $this->assertStringContainsString('Export Log Files', $output);
    }

    public function testClearFileWithStatsReferer(): void
    {
        $_SERVER['HTTP_REFERER'] = 'http://localhost/logs/stats';

        ob_start();
        $this->controller->clearFile('test_log.log');
        $echoed = ob_get_clean();

        // Should redirect to logs/stats
        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('logs/stats', $echoed);

        unset($_SERVER['HTTP_REFERER']);

        // Recreate the file for other tests
        file_put_contents($this->testLogDir . DS . 'test_log.log', "");
    }

    public function testClearFileInvalid(): void
    {
        ob_start();
        $this->controller->clearFile('invalid_file.log');
        $echoed = ob_get_clean();

        // Invalid file → redirect to logs
        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testClearAction(): void
    {
        ob_start();
        $this->controller->clear();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testDashboard(): void
    {
        ob_start();
        $output = $this->controller->dashboard();
        $echoed = ob_get_clean();

        $combined = ($output ?? '') . $echoed;
        $this->assertIsString($combined);
        // dashboard renders an HTML page with chart/stats
        $this->assertStringContainsString('Logs Dashboard', $combined);
    }

    public function testFilter(): void
    {
        $_POST['file'] = 'test_log.log';
        $_POST['levels'] = ['error', 'warning'];
        $_POST['limit'] = '100';

        $output = $this->controller->filter();

        $this->assertIsString($output);
        $this->assertStringContainsString('Filter Log Files', $output);
    }

    public function testSearchEmpty(): void
    {
        // No query posted — should render form without results
        $_POST = [];

        $output = $this->controller->search();

        $this->assertIsString($output);
        $this->assertStringContainsString('Search Log Files', $output);
        $this->assertStringNotContainsString('Search Results for', $output);
    }

    public function testAutoPopulateWhitelistAddsNewFiles(): void
    {
        file_put_contents($this->testLogDir . DS . 'newfile.log', "new data\n");

        $controller = new TestableLogController(null);

        $ref      = new \ReflectionProperty(LogController::class, 'whitelist');
        $whitelist = $ref->getValue($controller);

        $this->assertContains('newfile.log', $whitelist);

        unlink($this->testLogDir . DS . 'newfile.log');
    }

}
