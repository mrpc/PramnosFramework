<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Logs\LogViewerView;

/**
 * Unit tests for the LogViewerView HTML renderer.
 *
 * LogViewerView produces the full HTML interface for the log viewer dashboard.
 * It is a pure rendering class: the constructor only stores the controller
 * reference; all public/protected methods build and return HTML strings.
 *
 * Tests exercise:
 *  - render() returns a non-empty string containing structural HTML
 *  - The file whitelist is reflected in <option> elements
 *  - The current file is marked 'selected'
 *  - render() with an empty whitelist still produces valid markup
 *  - HTML-special characters in filenames are escaped in the output
 */
#[CoversClass(LogViewerView::class)]
class LogViewerViewTest extends TestCase
{
    private LogViewerView $view;

    protected function setUp(): void
    {
        // The controller is stored but never called by the rendering methods;
        // a mock satisfies the type constraint.
        $controller = $this->createMock(\Pramnos\Application\Controller::class);
        $this->view = new LogViewerView($controller);
    }

    // ── render() basic output ─────────────────────────────────────────────────

    /**
     * render() must return a non-empty string when given a file and a whitelist.
     *
     * This is the primary smoke test: if render() returns something, all the
     * output-buffering machinery (ob_start / ob_get_clean) is working.
     */
    public function testRenderReturnsNonEmptyString(): void
    {
        // Arrange
        $whitelist   = ['app.log', 'error.log'];
        $currentFile = 'app.log';

        // Act
        $html = $this->view->render($currentFile, $whitelist);

        // Assert
        $this->assertIsString($html, 'render() must return a string');
        $this->assertNotEmpty($html, 'render() must return a non-empty string');
    }

    /**
     * render() must include a <select> element for the file list so the user
     * can switch between available log files.
     */
    public function testRenderContainsFileSelectElement(): void
    {
        // Arrange
        $whitelist   = ['app.log', 'error.log'];
        $currentFile = 'app.log';

        // Act
        $html = $this->view->render($currentFile, $whitelist);

        // Assert — select element with id="file" must be present
        $this->assertStringContainsString('<select', $html,
            'render() must produce a <select> element for the file whitelist');
    }

    // ── Whitelist rendering ───────────────────────────────────────────────────

    /**
     * All files from the whitelist must appear as <option> elements in the
     * rendered HTML so the user can navigate to any available log file.
     */
    public function testRenderIncludesAllWhitelistFilesAsOptions(): void
    {
        // Arrange
        $whitelist = ['app.log', 'error.log', 'debug.log'];

        // Act
        $html = $this->view->render($whitelist[0], $whitelist);

        // Assert — each file appears in an <option> value attribute
        foreach ($whitelist as $file) {
            $this->assertStringContainsString(
                'value="' . htmlspecialchars($file) . '"',
                $html,
                "render() must include an <option> for '$file'"
            );
        }
    }

    /**
     * The currently active file must have the 'selected' attribute on its
     * <option> element so the select box shows the correct file on load.
     */
    public function testRenderMarksCurrentFileAsSelected(): void
    {
        // Arrange
        $whitelist   = ['app.log', 'error.log', 'debug.log'];
        $currentFile = 'error.log';

        // Act
        $html = $this->view->render($currentFile, $whitelist);

        // Assert — 'selected' must appear near the current file's option
        // We check that the option for the current file contains 'selected'
        $this->assertStringContainsString('selected', $html,
            "render() must mark the current file's <option> as 'selected'");
    }

    // ── Empty whitelist ───────────────────────────────────────────────────────

    /**
     * render() must not throw or crash when the whitelist is empty.
     *
     * Empty whitelist is a valid edge case (e.g. log directory doesn't exist yet).
     * The HTML must still be structurally valid — a <select> with no options.
     */
    public function testRenderWithEmptyWhitelistProducesValidMarkup(): void
    {
        // Arrange
        $whitelist   = [];
        $currentFile = '';

        // Act — must not throw
        $html = $this->view->render($currentFile, $whitelist);

        // Assert — returns HTML string without crashing
        $this->assertIsString($html, 'render() must return a string even with an empty whitelist');
        $this->assertStringContainsString('<select', $html,
            'render() must still produce the select element with an empty whitelist');
    }

    // ── HTML escaping ─────────────────────────────────────────────────────────

    /**
     * Filenames containing HTML-special characters must be escaped in the
     * rendered output to prevent XSS via maliciously named log files.
     */
    public function testRenderEscapesSpecialCharactersInFilenames(): void
    {
        // Arrange — a filename with characters that need HTML escaping
        $maliciousFile = 'app<script>alert(1)</script>.log';
        $whitelist     = [$maliciousFile];

        // Act
        $html = $this->view->render($maliciousFile, $whitelist);

        // Assert — raw unescaped script tag must NOT appear in the output
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html,
            'render() must HTML-escape special characters in filenames to prevent XSS');

        // Assert — the escaped version IS present
        $this->assertStringContainsString(htmlspecialchars($maliciousFile), $html,
            'render() must include the HTML-escaped filename in the output');
    }

    // ── Structural landmarks ──────────────────────────────────────────────────

    /**
     * The rendered output must contain the log iframe element that loads log
     * content. This is the primary display area of the log viewer.
     */
    public function testRenderContainsLogIframe(): void
    {
        // Arrange
        $whitelist = ['app.log'];

        // Act
        $html = $this->view->render('app.log', $whitelist);

        // Assert — log frame iframe must be present
        $this->assertStringContainsString('id="logFrame"', $html,
            'render() must include the log-content iframe element');
    }

    /**
     * The rendered output must include pagination controls so users can
     * navigate through large log files.
     */
    public function testRenderContainsPaginationControls(): void
    {
        // Arrange
        $whitelist = ['app.log'];

        // Act
        $html = $this->view->render('app.log', $whitelist);

        // Assert — page input and navigation buttons must be present
        $this->assertStringContainsString('id="page"', $html,
            'render() must include the page-number input element');
        $this->assertStringContainsString('id="firstPage"', $html,
            'render() must include the first-page navigation button');
        $this->assertStringContainsString('id="lastPage"', $html,
            'render() must include the last-page navigation button');
    }

    /**
     * The rendered output must include the log-level filter select so users
     * can filter entries by severity.
     */
    public function testRenderContainsLogLevelFilter(): void
    {
        // Arrange
        $whitelist = ['app.log'];

        // Act
        $html = $this->view->render('app.log', $whitelist);

        // Assert — log level select element must be present
        $this->assertStringContainsString('id="logLevel"', $html,
            'render() must include the log-level filter select element');
    }

    /**
     * The rendered output must contain JavaScript that powers the log viewer
     * client-side behaviour (pagination, search, auto-update).
     */
    public function testRenderContainsJavaScript(): void
    {
        // Arrange
        $whitelist = ['app.log'];

        // Act
        $html = $this->view->render('app.log', $whitelist);

        // Assert — <script> block must be present
        $this->assertStringContainsString('<script', $html,
            'render() must include a <script> block for client-side behaviour');
    }
}
