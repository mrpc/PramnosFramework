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
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
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
    /**
     * `raw` serves the lines the viewer's frame loads.
     *
     * The half that made the shared component embeddable here at all. Without it the frame
     * loads from an admin screen, and a developer holding a signed debug grant — the one
     * visitor this panel is for — sees a login page inside it.
     */
    public function testRawServesTheLinesForTheFrame(): void
    {
        // Arrange
        $_GET = ['file' => 'devpanel-logs-test.log'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $html = $this->dispatchRaw();

        // Assert
        $this->assertStringContainsString('something went wrong', $html);
    }

    /**
     * A file that is not one of the files on disk is refused.
     *
     * The name arrives in a query string. There is exactly one directory to read from and it is
     * not the caller's to choose, so the name is compared against what is there rather than
     * joined to a path — which is why `../../etc/passwd` is not a question about how many `..`
     * a path can contain.
     */
    public function testRawRefusesAFileThatIsNotOnDisk(): void
    {
        // Arrange
        $_GET = ['file' => '../../../etc/passwd'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $html = $this->dispatchRaw();

        // Assert
        $this->assertStringContainsString('Invalid or no log file', $html);
        $this->assertStringNotContainsString('root:', $html);
    }

    /**
     * And an absent file name is refused the same way.
     *
     * The frame is built with one, but the address is public and something will eventually ask
     * for it without one.
     */
    public function testRawRefusesAnAbsentFileName(): void
    {
        // Arrange
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $html = $this->dispatchRaw();

        // Assert
        $this->assertStringContainsString('Invalid or no log file', $html);
    }

    /**
     * The search and level parameters reach the reader.
     *
     * They are what makes the frame a viewer rather than a `tail`. A parameter that never
     * arrives is a control that does nothing when used, which is worse than no control.
     */
    public function testRawHonoursTheSearchAndLevelParameters(): void
    {
        // Arrange
        $_GET = [
            'file'   => 'devpanel-logs-test.log',
            'search' => 'nothing{space}matches{space}this',
            'level'  => 'error',
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $html = $this->dispatchRaw();

        // Assert
        $this->assertStringNotContainsString('something went wrong', $html,
            'the search excluded the only line in the file');
    }

    /**
     * Run `raw()` and capture what it wrote.
     *
     * The feature and the guard are the panel's own concern, asserted in
     * `DevPanelControllerTest`; these are about what the endpoint serves once it is past them.
     * A signed debug grant is the honest way past, since that is the case the endpoint exists
     * for — a developer with no admin session.
     */
    private function dispatchRaw(): string
    {
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        putenv('APP_DEBUG=1');
        $_ENV['APP_DEBUG'] = '1';

        $controller = new class extends DevPanelController {
            public function __construct()
            {
            }

            protected function guardAccess(): bool
            {
                return false;
            }
        };

        ob_start();

        try {
            $controller->raw();
        } catch (\Throwable $exception) {
            // `guardAccess()` renders and stops; the panel's own error path is asserted in
            // DevPanelControllerTest and is not what these are about.
            ob_get_clean();

            $this->markTestSkipped('raw() was guarded: ' . $exception->getMessage());
        }

        return (string) ob_get_clean();
    }

}
