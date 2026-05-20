<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\LogController;

/**
 * Minimal test double that bypasses LogController constructor side effects.
 */
class TestableLogController extends LogController
{
    public function __construct()
    {
    }

    public function setWhitelist(array $whitelist): void
    {
        $this->whitelist = $whitelist;
    }

    public function setBlacklist(array $blacklist): void
    {
        $this->blacklist = $blacklist;
    }

    public function setClearList(array $clearList): void
    {
        $this->clearList = $clearList;
    }

    /**
     * Expose auto-population behavior for characterization.
     */
    public function runAutoPopulateWhitelist(): void
    {
        $this->autoPopulateWhitelist();
    }

    /**
     * Expose date-aware log processing for characterization.
     */
    public function runProcessLogFileWithDateCheck(string $filename, string $ext, callable $callback): bool
    {
        return $this->processLogFileWithDateCheck($filename, $ext, $callback);
    }

    /**
     * Expose action buttons renderer for deterministic assertions.
     */
    public function runRenderActionButtons(): string
    {
        return $this->renderActionButtons();
    }
}

/**
 * Characterization tests for deterministic LogController helper behavior.
 *
 * Scope: whitelist auto-population/sorting/filtering, date-aware line processing,
 * and action-buttons rendering contracts.
 */
#[CoversClass(LogController::class)]
final class LogControllerCharacterizationTest extends TestCase
{
    private string $logsDir;

    private string $apiDir;

    /**
     * @var array<string>
     */
    private array $createdFiles = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
    }

    protected function setUp(): void
    {
        // Arrange
        $this->logsDir = LOG_PATH . DS . 'logs';
        $this->apiDir = ROOT . DS . 'www' . DS . 'api';

        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0777, true);
        }

        if (!is_dir($this->apiDir)) {
            mkdir($this->apiDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Arrange/Act cleanup
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * autoPopulateWhitelist() merges discovered files, removes blacklisted items,
     * and returns a sorted unique whitelist.
     */
    public function testAutoPopulateWhitelistAddsDiscoversAndSortsEntries(): void
    {
        // Arrange
        $controller = new TestableLogController();
        $controller->setWhitelist(['zeta.log', 'php_error.log']);
        $controller->setBlacklist(['ignore.log']);

        $alpha = $this->createFile($this->logsDir . DS . 'alpha.log', "line\n");
        $ignore = $this->createFile($this->logsDir . DS . 'ignore.log', "line\n");
        $special = $this->createFile($this->apiDir . DS . 'GitDeploy', "deploy\n");

        // Act
        $controller->runAutoPopulateWhitelist();
        $actual = $this->readProtectedArray($controller, 'whitelist');

        // Assert
        $this->assertContains('alpha.log', $actual);
        $this->assertContains('GitDeploy', $actual);
        $this->assertContains('php_error.log', $actual);
        $this->assertNotContains('ignore.log', $actual);
        $this->assertSame($actual, array_values(array_unique($actual)));

        $expectedSorted = $actual;
        sort($expectedSorted);
        // Proves the controller keeps whitelist ordering deterministic.
        $this->assertSame($expectedSorted, $actual);

        $this->assertFileExists($alpha);
        $this->assertFileExists($ignore);
        $this->assertFileExists($special);
    }

    /**
     * processLogFileWithDateCheck() returns false for missing files and does not invoke callbacks.
     */
    public function testProcessLogFileWithDateCheckReturnsFalseForMissingFile(): void
    {
        // Arrange
        $controller = new TestableLogController();
        $calls = 0;

        // Act
        $ok = $controller->runProcessLogFileWithDateCheck('missing_file_for_characterization', 'log', function () use (&$calls): void {
            $calls++;
        });

        // Assert
        $this->assertFalse($ok);
        $this->assertSame(0, $calls);
    }

    /**
     * processLogFileWithDateCheck() parses bracket timestamps and forwards them to callback.
     */
    public function testProcessLogFileWithDateCheckParsesBracketTimestamp(): void
    {
        // Arrange
        $controller = new TestableLogController();
        $this->createFile(
            $this->logsDir . DS . 'lc_bracket_timestamp.log',
            "[2026/05/03 12:13:14] entry\n"
        );

        $captured = [];

        // Act
        $ok = $controller->runProcessLogFileWithDateCheck('lc_bracket_timestamp', 'log', function (string $line, int $timestamp) use (&$captured): void {
            $captured[] = ['line' => trim($line), 'timestamp' => $timestamp];
        });

        // Assert
        $this->assertTrue($ok);
        $this->assertCount(1, $captured);
        $this->assertSame('[2026/05/03 12:13:14] entry', $captured[0]['line']);
        $this->assertSame(strtotime('2026/05/03 12:13:14'), $captured[0]['timestamp']);
    }

    /**
     * processLogFileWithDateCheck() falls back to current-time semantics when no parseable timestamp exists.
     */
    public function testProcessLogFileWithDateCheckFallsBackToCurrentTime(): void
    {
        // Arrange
        $controller = new TestableLogController();
        $this->createFile($this->logsDir . DS . 'lc_plain_line.log', "plain message\n");

        $capturedTimestamp = 0;
        $before = time();

        // Act
        $ok = $controller->runProcessLogFileWithDateCheck('lc_plain_line', 'log', function (string $line, int $timestamp) use (&$capturedTimestamp): void {
            $capturedTimestamp = $timestamp;
        });
        $after = time();

        // Assert
        $this->assertTrue($ok);
        $this->assertGreaterThanOrEqual($before, $capturedTimestamp);
        $this->assertLessThanOrEqual($after, $capturedTimestamp);
    }

    /**
     * renderActionButtons() includes action links and clearList summary text.
     */
    public function testRenderActionButtonsIncludesActionsAndClearListSummary(): void
    {
        // Arrange
        $controller = new TestableLogController();
        $controller->setClearList(['pramnosframework.log', 'php_error.log']);

        // Act
        $html = $controller->runRenderActionButtons();

        // Assert
        $this->assertStringContainsString('Log Statistics', $html);
        $this->assertStringContainsString('Search Across Logs', $html);
        $this->assertStringContainsString('Rotate Logs', $html);
        $this->assertStringContainsString('Archive Logs', $html);
        $this->assertStringContainsString('Clear Logs', $html);
        $this->assertStringContainsString('pramnosframework.log, php_error.log', $html);
    }

    private function createFile(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function readProtectedArray(object $object, string $property): array
    {
        $ref = new \ReflectionProperty($object, $property);

        /** @var array<int, string> $value */
        $value = $ref->getValue($object);
        return $value;
    }
}
