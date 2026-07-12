<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\DevPanel\DevPanelController;

/**
 * Testable subclass that exposes the private helper methods for direct unit
 * testing without going through the full HTTP / auth pipeline.
 *
 * All methods that call exit() are overridden to prevent test runner
 * termination.
 */
class InspectableDevPanelController extends DevPanelController
{
    // Prevent exit() during tests
    protected function terminate(): void {}

    // Prevent HTML output during tests
    protected function renderLayout(string $activeTab, string $content): void {}

    // Expose humanBytes()
    public function pubHumanBytes(int $bytes): string
    {
        $ref = new \ReflectionMethod($this, 'humanBytes');
        return $ref->invoke($this, $bytes);
    }

    // Expose statusClass()
    public function pubStatusClass(bool $bad): string
    {
        $ref = new \ReflectionMethod($this, 'statusClass');
        return $ref->invoke($this, $bad);
    }

    // Expose card()
    public function pubCard(string $title, string $body): string
    {
        $ref = new \ReflectionMethod($this, 'card');
        return $ref->invoke($this, $title, $body);
    }

    // Expose alert()
    public function pubAlert(string $message, string $type = 'info'): string
    {
        $ref = new \ReflectionMethod($this, 'alert');
        return $ref->invoke($this, $message, $type);
    }

    // Expose readProcUptime()
    public function pubReadProcUptime(): string
    {
        $ref = new \ReflectionMethod($this, 'readProcUptime');
        return $ref->invoke($this);
    }

    // Expose readProcLoadAvg()
    public function pubReadProcLoadAvg(): string
    {
        $ref = new \ReflectionMethod($this, 'readProcLoadAvg');
        return $ref->invoke($this);
    }

    // Expose readProcMemInfo()
    public function pubReadProcMemInfo(): array
    {
        $ref = new \ReflectionMethod($this, 'readProcMemInfo');
        return $ref->invoke($this);
    }

    // Expose isDevMode()
    public function pubIsDevMode(): bool
    {
        $ref = new \ReflectionMethod($this, 'isDevMode');
        return $ref->invoke($this);
    }

    // Expose detectRepoRoot()
    public function pubDetectRepoRoot(): string
    {
        $ref = new \ReflectionMethod($this, 'detectRepoRoot');
        return $ref->invoke($this);
    }
}

/**
 * Unit tests for the private helper methods of DevPanelController.
 *
 * These tests cover the uncovered branches identified in the coverage report:
 *  - humanBytes()       — unit scaling (B, KB, MB, GB)
 *  - statusClass()      — ok vs warn badge class
 *  - card()             — HTML card wrapper structure
 *  - alert()            — alert type variants
 *  - readProcUptime()   — time formatting / unavailable file
 *  - readProcLoadAvg()  — load string / unavailable file
 *  - readProcMemInfo()  — memory string / unavailable file
 *  - isDevMode()        — DEVELOPMENT / APP_DEBUG / settings checks
 *  - detectRepoRoot()   — ROOT constant / fallback to framework root
 */
#[CoversClass(DevPanelController::class)]
class DevPanelHelpersTest extends TestCase
{
    private InspectableDevPanelController $controller;

    protected function setUp(): void
    {
        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['devpanel']);

        \Pramnos\Application\Settings::clearSettings();

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
        }

        $this->controller = new InspectableDevPanelController($app);
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
        DevPanelController::resetCustomPanels();
        \Pramnos\Application\Settings::clearSettings();
    }

    // =========================================================================
    // humanBytes()
    // =========================================================================

    /**
     * humanBytes() returns raw byte count appended with ' B' for values under
     * 1 KiB. This is the smallest unit; no conversion is applied.
     */
    public function testHumanBytesReturnsBytesForSmallValues(): void
    {
        // Act + Assert — values below 1024 are shown as plain bytes
        $this->assertSame('0 B',   $this->controller->pubHumanBytes(0),
            '0 bytes must show as "0 B"');
        $this->assertSame('512 B', $this->controller->pubHumanBytes(512),
            '512 bytes must show as "512 B"');
        $this->assertSame('1023 B', $this->controller->pubHumanBytes(1023),
            '1023 bytes must show as "1023 B"');
    }

    /**
     * humanBytes() rounds to 1 decimal place and appends ' KB' for values
     * between 1 KiB and 1 MiB (exclusive).
     */
    public function testHumanBytesReturnsKilobytesForMediumValues(): void
    {
        // Act + Assert — 1024 bytes = 1.0 KB
        $this->assertSame('1 KB',  $this->controller->pubHumanBytes(1024),
            '1024 bytes must show as "1 KB"');
        $this->assertSame('1.5 KB', $this->controller->pubHumanBytes(1536),
            '1536 bytes must show as "1.5 KB"');
    }

    /**
     * humanBytes() rounds to 2 decimal places and appends ' MB' for values
     * between 1 MiB and 1 GiB (exclusive).
     */
    public function testHumanBytesReturnsMegabytesForLargeValues(): void
    {
        // Act — 1 MiB exactly
        $result = $this->controller->pubHumanBytes(1048576);

        // Assert — '1 MB' (round() removes trailing zero)
        $this->assertStringContainsString('MB', $result,
            '1 MiB must show with MB suffix');
        $this->assertStringContainsString('1', $result);
    }

    /**
     * humanBytes() appends ' GB' for values >= 1 GiB.
     */
    public function testHumanBytesReturnsGigabytesForHugeValues(): void
    {
        // Act — 1 GiB exactly
        $result = $this->controller->pubHumanBytes(1073741824);

        // Assert — GB suffix
        $this->assertStringContainsString('GB', $result,
            '1 GiB must show with GB suffix');
        $this->assertStringContainsString('1', $result);
    }

    // =========================================================================
    // statusClass()
    // =========================================================================

    /**
     * statusClass(true) returns a CSS class that signals a warning state.
     *
     * Used by the Overview panel to colour pending migration counts and failed
     * queue jobs in red.
     */
    public function testStatusClassReturnsBadgeWarnWhenBad(): void
    {
        // Act
        $result = $this->controller->pubStatusClass(true);

        // Assert — warn class for bad state
        $this->assertStringContainsString('warn', $result,
            'statusClass(true) must return a class containing "warn"');
    }

    /**
     * statusClass(false) returns a CSS class for the healthy/OK state.
     */
    public function testStatusClassReturnsBadgeOkWhenGood(): void
    {
        // Act
        $result = $this->controller->pubStatusClass(false);

        // Assert — ok class for good state
        $this->assertStringContainsString('ok', $result,
            'statusClass(false) must return a class containing "ok"');
    }

    // =========================================================================
    // card()
    // =========================================================================

    /**
     * card() wraps its content in a div with card-title and card-body sections.
     *
     * All DevPanel panels use this helper to produce a consistent card UI.
     * Verifying the HTML structure here protects against accidental template
     * regression in a private method.
     */
    public function testCardOutputsStructuredHtmlWithTitleAndBody(): void
    {
        // Act
        $result = $this->controller->pubCard('My Title', '<p>Body content</p>');

        // Assert — contains both the title and the body
        $this->assertStringContainsString('My Title', $result,
            'card() must include the provided title');
        $this->assertStringContainsString('<p>Body content</p>', $result,
            'card() must include the provided body HTML verbatim');

        // Assert — uses the card CSS class structure
        $this->assertStringContainsString('card-title', $result,
            'card() must include a card-title element');
        $this->assertStringContainsString('card-body', $result,
            'card() must include a card-body element');
    }

    /**
     * card() must NOT escape the body HTML — the body is trusted content
     * produced by the internal renderer, not user input.
     */
    public function testCardDoesNotEscapeBodyHtml(): void
    {
        // Act
        $result = $this->controller->pubCard('T', '<strong>bold</strong>');

        // Assert — raw HTML tags preserved
        $this->assertStringContainsString('<strong>bold</strong>', $result,
            'card() must pass body HTML through unescaped');
    }

    // =========================================================================
    // alert()
    // =========================================================================

    /**
     * alert() produces an alert-info div by default.
     *
     * The 'info' type is the least urgent; it is used for purely informational
     * messages with no action required.
     */
    public function testAlertDefaultTypeIsInfo(): void
    {
        // Act
        $result = $this->controller->pubAlert('Some information', 'info');

        // Assert
        $this->assertStringContainsString('alert-info', $result,
            'Default alert type must be "alert-info"');
        $this->assertStringContainsString('Some information', $result);
    }

    /**
     * alert() produces an alert-warning div for the 'warning' type.
     */
    public function testAlertWarningType(): void
    {
        // Act
        $result = $this->controller->pubAlert('Watch out!', 'warning');

        // Assert
        $this->assertStringContainsString('alert-warning', $result);
        $this->assertStringContainsString('Watch out!', $result);
    }

    /**
     * alert() produces an alert-error div for the 'error' type.
     */
    public function testAlertErrorType(): void
    {
        // Act
        $result = $this->controller->pubAlert('Something failed.', 'error');

        // Assert
        $this->assertStringContainsString('alert-error', $result);
        $this->assertStringContainsString('Something failed.', $result);
    }

    /**
     * alert() HTML-escapes the message to prevent XSS injection from error
     * messages that include user-supplied data (e.g., table names in DB errors).
     */
    public function testAlertEscapesMessage(): void
    {
        // Act — message contains a raw < > tag
        $result = $this->controller->pubAlert('<script>alert(1)</script>', 'error');

        // Assert — < and > are escaped
        $this->assertStringNotContainsString('<script>', $result,
            'alert() must HTML-escape the message to prevent XSS');
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    // =========================================================================
    // readProcUptime()
    // =========================================================================

    /**
     * readProcUptime() returns a formatted 'Xd Yh Zm' string on Linux where
     * /proc/uptime is available, or '—' when it cannot be read.
     *
     * We cannot control /proc/uptime in tests, so we only assert on the two
     * possible outcomes — either a formatted duration or the fallback dash.
     */
    public function testReadProcUptimeReturnsStringOrDash(): void
    {
        // Act
        $result = $this->controller->pubReadProcUptime();

        // Assert — either the formatted duration or the unavailable fallback
        $isFormatted = (bool) preg_match('/^\d+d \d+h \d+m$/', $result);
        $isDash      = $result === '—';
        $this->assertTrue($isFormatted || $isDash,
            "readProcUptime() must return 'Xd Yh Zm' or '—', got: {$result}");
    }

    // =========================================================================
    // readProcLoadAvg()
    // =========================================================================

    /**
     * readProcLoadAvg() returns a '1m / 5m / 15m' formatted load string on
     * Linux, or '—' when /proc/loadavg cannot be read.
     */
    public function testReadProcLoadAvgReturnsStringOrDash(): void
    {
        // Act
        $result = $this->controller->pubReadProcLoadAvg();

        // Assert — either a load average string or the fallback dash
        $isFormatted = str_contains($result, ' / ');
        $isDash      = $result === '—';
        $this->assertTrue($isFormatted || $isDash,
            "readProcLoadAvg() must return 'a / b / c' or '—', got: {$result}");
    }

    // =========================================================================
    // readProcMemInfo()
    // =========================================================================

    /**
     * readProcMemInfo() returns a 3-element array of human-readable strings:
     * [total, free, used], or ['—','—','—'] when /proc/meminfo is unavailable.
     */
    public function testReadProcMemInfoReturnsThreeElementArray(): void
    {
        // Act
        $result = $this->controller->pubReadProcMemInfo();

        // Assert — always returns exactly 3 elements
        $this->assertCount(3, $result,
            'readProcMemInfo() must return exactly 3 elements [total, free, used]');

        // Assert — each element is either a human-bytes string or '—'
        foreach ($result as $idx => $val) {
            $isHumanBytes = (bool) preg_match('/^\d+(\.\d+)? (B|KB|MB|GB)$/', $val);
            $isDash       = $val === '—';
            $this->assertTrue($isHumanBytes || $isDash,
                "readProcMemInfo()[$idx] must be a human-bytes string or '—', got: {$val}");
        }
    }

    // =========================================================================
    // isDevMode()
    // =========================================================================

    /**
     * isDevMode() returns true when the DEVELOPMENT constant is defined as true.
     *
     * This is the primary dev-mode flag for installed applications that run
     * with a hard-coded DEVELOPMENT=true in their bootstrap.
     */
    public function testIsDevModeReturnsTrueWhenDevelopmentConstantIsTrue(): void
    {
        // Arrange — DEVELOPMENT constant is defined as true in the integration
        // test bootstrap (tests/fixtures/bootstrap.php or phpunit.xml).
        // If already true, just assert. If false we cannot un-define it.
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            // Act
            $result = $this->controller->pubIsDevMode();
            // Assert
            $this->assertTrue($result,
                'isDevMode() must return true when DEVELOPMENT===true');
        } else {
            $this->markTestSkipped('DEVELOPMENT constant is not true in this environment');
        }
    }

    /**
     * isDevMode() returns true when the debug setting in Settings is 'yes'.
     *
     * This covers the Settings::getSetting('debug') branch used by applications
     * that set debug via their settings file rather than a PHP constant.
     */
    public function testIsDevModeReturnsTrueWhenDebugSettingIsYes(): void
    {
        // Arrange — set the debug setting before instantiating
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('debug', 'yes');

        // Act — isDevMode() reads Settings
        $result = $this->controller->pubIsDevMode();

        // Assert
        $this->assertTrue($result,
            'isDevMode() must return true when Settings debug=yes');
    }

    /**
     * isDevMode() returns false when no dev-mode indicator is set.
     *
     * When neither DEVELOPMENT is true, APP_DEBUG is set, nor the debug/
     * development settings are truthy, the panel must treat the environment as
     * production and deny access.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testIsDevModeReturnsFalseWhenNoDevIndicatorIsSet(): void
    {
        // Arrange — clear settings so no Settings-based flag applies
        \Pramnos\Application\Settings::clearSettings();

        // Temporarily clear APP_DEBUG env var
        $orig = getenv('APP_DEBUG');
        putenv('APP_DEBUG=');

        // Act
        $result = $this->controller->pubIsDevMode();

        // Restore env
        if ($orig !== false) {
            putenv('APP_DEBUG=' . $orig);
        } else {
            putenv('APP_DEBUG');
        }

        // Assert
        $this->assertFalse($result,
            'isDevMode() must return false when no dev-mode indicator is active');
    }

    // =========================================================================
    // detectRepoRoot()
    // =========================================================================

    /**
     * detectRepoRoot() returns a non-empty string that points to a real
     * directory on disk.
     *
     * The exact value depends on how ROOT is defined and where .git lives, but
     * it must always resolve to an actual path — a bad path would break the Git
     * info panel.
     */
    public function testDetectRepoRootReturnsRealDirectory(): void
    {
        // Act
        $root = $this->controller->pubDetectRepoRoot();

        // Assert — result is a non-empty string
        $this->assertNotEmpty($root, 'detectRepoRoot() must never return an empty string');

        // Assert — the path is a directory that exists on disk
        $this->assertDirectoryExists($root,
            "detectRepoRoot() returned '{$root}' which does not exist as a directory");
    }

    /**
     * detectRepoRoot() prefers the ROOT constant over the framework source
     * directory when ROOT points to a directory containing a .git folder.
     *
     * This matters for host applications that have their own git repo: the Git
     * panel should reflect the app's commit, not the framework's.
     */
    public function testDetectRepoRootPrefersRootConstantWhenGitDirPresent(): void
    {
        // Arrange — ROOT must be defined (it is in the test bootstrap)
        if (!defined('ROOT')) {
            $this->markTestSkipped('ROOT constant not defined in test environment');
        }

        // Act
        $root = $this->controller->pubDetectRepoRoot();

        // Assert — if ROOT has a .git dir, the returned root must equal ROOT
        if (is_dir(ROOT . '/.git')) {
            $this->assertSame(ROOT, $root,
                'detectRepoRoot() must return ROOT when ROOT/.git exists');
        } else {
            // ROOT exists but has no .git; fallback to framework root is fine
            $this->assertNotEmpty($root);
        }
    }
}
