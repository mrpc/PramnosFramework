<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Logs\Logger;

/**
 * The panel serves the administration area's log viewer, not a second one.
 *
 * It used to serve its own: a table with three filters, written beside a `LogController` that
 * already had pagination, reverse order, follow, statistics, cross-file search, export, rotate
 * and archive. Reported as «γιατί τα logs στο devpanel δεν είναι τα ίδια με τον κανονικό
 * controller; νόμιζα ότι μπορούμε να έχουμε το ίδιο».
 *
 * The reason was one hard-coded URL — `LogViewer` built its own `raw` address from
 * `adminUrl('logs')`, so the component could only be embedded in one place. That is a parameter
 * now, and what these assert is that the panel uses the shared component and points it at its
 * own guarded endpoint rather than at an admin screen.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelLogsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        if (!is_dir(Logger::logDirectory())) {
            mkdir(Logger::logDirectory(), 0777, true);
        }

        $this->file = Logger::logDirectory() . DIRECTORY_SEPARATOR . 'devpanel-logs-test.log';
        file_put_contents($this->file, json_encode([
            'timestamp' => '29/08/2099 10:00:00',
            'level'     => 'error',
            'message'   => 'something went wrong',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * The page is the shared viewer, recognisable by the component's own markup.
     *
     * `pf-logviewer` is the wrapper `LogViewerView` emits and nothing else does. A page without
     * it is a second implementation, which is the thing this change removed.
     */
    public function testThePageIsTheSharedViewer(): void
    {
        // Act
        $html = $this->call('renderLogViewer', $this->request([]));

        // Assert
        $this->assertStringContainsString('pf-logviewer', $html);
        $this->assertStringContainsString('id="logFrame"', $html);
        $this->assertStringContainsString('id="maxLines"', $html, 'pagination it never had');
        $this->assertStringContainsString('id="logLevel"', $html);
    }

    /**
     * And it points at this panel, not at the administration screen.
     *
     * The whole reason the second viewer existed. A frame loaded from `/admin/Logs/raw` renders
     * a login page inside the panel for the one visitor this panel is for — a developer holding
     * a signed debug grant and no admin session.
     */
    public function testTheFrameLoadsFromThisPanelAndNotFromTheAdminScreen(): void
    {
        // Act
        $html = $this->call('renderLogViewer', $this->request([]));

        // Assert
        $this->assertStringContainsString('/devpanel/raw/file/', $html);
        $this->assertStringNotContainsString('/admin/logs/raw', strtolower($html));
    }

    /**
     * `raw` is an action, or the frame is a 404.
     *
     * The recurring failure in this codebase: a component that renders correctly and points at
     * an address nothing dispatches. `Controller::exec()` dispatches what `addAuthAction()` was
     * given and nothing else.
     */
    public function testRawIsDispatchable(): void
    {
        // Arrange
        $controller = (new \ReflectionClass(DevPanelController::class))->newInstanceWithoutConstructor();

        // Act
        $source = (string) file_get_contents(
            (new \ReflectionClass(DevPanelController::class))->getFileName()
        );

        // Assert
        $this->assertTrue(method_exists($controller, 'raw'));
        $this->assertStringContainsString("'raw',", $source,
            'raw must be registered as an auth action or nothing dispatches it');
    }

    /**
     * The failed requests stay, because the administration screen has nothing like them.
     *
     * They exist only while the debug toolbar is tagging a visitor's requests, so they are this
     * panel's own and the one part worth keeping above the shared viewer.
     */
    public function testTheFailedRequestsBlockIsStillThisPanelsOwn(): void
    {
        // Act
        $html = $this->call('renderLogViewer', $this->request([]));

        // Assert — either the block, or nothing at all when no request has been tagged
        $this->assertMatchesRegularExpression(
            '~(Requests that failed|<h2>Logs</h2>)~',
            $html
        );
    }

    /**
     * The screens this panel does not reproduce are linked rather than rewritten.
     *
     * Statistics, cross-file search, filter and export are `LogController`'s. Writing a second
     * copy of each is exactly what produced the viewer this replaced, so the answer is a link
     * and an honest note that it needs an admin session.
     */
    public function testTheOtherLogScreensAreLinkedNotRewritten(): void
    {
        // Act
        $html = $this->call('renderLogViewer', $this->request([]));

        // Assert
        foreach (['Statistics', 'Search every file', 'Filter', 'Export'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertStringContainsString('admin session', $html);
    }

    /**
     * A file name from the query string picks from the files on disk; it is never a path.
     *
     * There is exactly one directory to read from and it is not the caller's to choose. A name
     * that is not one of them falls back rather than being joined to anything.
     */
    public function testAFileNameIsAChoiceFromDiskAndNotAPath(): void
    {
        // Act
        $html = $this->call('renderLogViewer', $this->request(['file' => '../../etc/passwd']));

        // Assert
        $this->assertStringNotContainsString('passwd', $html);
        $this->assertStringContainsString('/devpanel/raw/file/', $html);
    }

    /**
     * The log tab is in the tab strip, or nobody finds it.
     */
    public function testTheTabIsInTheStrip(): void
    {
        // Act
        $tabs = (new \ReflectionMethod(DevPanelController::class, 'tabs'))->invoke(null);

        // Assert
        $this->assertArrayHasKey('logs', $tabs);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, string> $query */
    private function request(array $query): \Pramnos\Http\Request
    {
        $_GET = $query + ['file' => 'devpanel-logs-test.log'];
        \Pramnos\Http\Request::resetInstance();

        return new \Pramnos\Http\Request();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $controller = (new \ReflectionClass(DevPanelController::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(DevPanelController::class, $method))->invoke($controller, ...$args);
    }
}
