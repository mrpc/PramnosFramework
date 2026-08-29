<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Logs\Logger;

/**
 * The log, as a page rather than as a controller.
 *
 * The administration area has a whole log screen — charts, a datatable, its own controller —
 * and it is the wrong place to read a log from while developing: behind an admin session,
 * styled like the application, and slow. What a developer wants is the last fifty lines and a
 * way to grep them, which is what this is.
 */
class DevPanelLogsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        if (!is_dir(Logger::logDirectory())) {
            mkdir(Logger::logDirectory(), 0777, true);
        }

        $this->file = Logger::logDirectory() . DIRECTORY_SEPARATOR . 'devpanel-logs-test.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * Newest first, because the line somebody is looking for was written a minute ago.
     *
     * And ordered by parsed time, not by string: the log writes `d/m/Y`, so on the first days
     * of a month a string sort puts the oldest lines at the top — which is the shape of "the
     * log viewer is broken" reports that come and go.
     */
    public function testNewestFirstAcrossAMonthBoundary(): void
    {
        // Arrange
        $this->write([
            ['29/08/2099 23:59:00', 'info', 'august'],
            ['01/09/2099 00:01:00', 'info', 'september'],
        ]);

        // Act
        $lines = $this->read();

        // Assert
        $this->assertSame('september', $lines[0]['message']);
        $this->assertSame('august', $lines[1]['message']);
    }

    /**
     * The level filter is a floor, not an equality.
     *
     * "Show me errors" means errors and worse — a critical is not something to hide from
     * somebody who asked for errors.
     */
    public function testTheLevelFilterIsAFloor(): void
    {
        // Arrange
        $this->write([
            ['29/08/2099 10:00:00', 'debug',    'noise'],
            ['29/08/2099 10:00:01', 'warning',  'a warning'],
            ['29/08/2099 10:00:02', 'error',    'an error'],
            ['29/08/2099 10:00:03', 'critical', 'a catastrophe'],
        ]);

        // Act
        $messages = array_column($this->read(level: 'error'), 'message');

        // Assert
        $this->assertContains('an error', $messages);
        $this->assertContains('a catastrophe', $messages);
        $this->assertNotContains('a warning', $messages);
        $this->assertNotContains('noise', $messages);
    }

    /**
     * The search is a substring, case-insensitively.
     */
    public function testTheSearchIsCaseInsensitive(): void
    {
        // Arrange
        $this->write([
            ['29/08/2099 10:00:00', 'error', 'Column "userid" does not exist'],
            ['29/08/2099 10:00:01', 'error', 'something else'],
        ]);

        // Act
        $messages = array_column($this->read(search: 'USERID'), 'message');

        // Assert
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('userid', $messages[0]);
    }

    /**
     * A file name from the query string selects a file; it never becomes a path.
     *
     * The name arrives from a URL. Joined to the log directory it is a traversal; compared
     * against the names actually on disk it is a filter, and there is nothing to get subtly
     * wrong about how many `..` a path can contain.
     */
    public function testAFileNameIsAFilterNotAPath(): void
    {
        // Arrange
        $this->write([['29/08/2099 10:00:00', 'error', 'mine']]);

        // Act
        $mine     = $this->read(file: 'devpanel-logs-test.log');
        $traverse = $this->read(file: '../../../etc/passwd');

        // Assert
        $this->assertNotSame([], $mine);
        $this->assertSame([], $traverse);
    }

    /**
     * The limit is clamped, so a URL cannot ask for the whole file.
     */
    public function testTheLimitIsRespected(): void
    {
        // Arrange
        $lines = [];

        for ($i = 0; $i < 40; $i++) {
            $lines[] = ['29/08/2099 10:00:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'info', 'line ' . $i];
        }

        $this->write($lines);

        // Act & Assert
        $this->assertCount(10, $this->read(limit: 10));
    }

    /**
     * A line that is not JSON is skipped rather than shown as one.
     *
     * Log files acquire them: a fatal written by PHP itself, a partial write, a rotated file
     * concatenated badly.
     */
    public function testALineThatIsNotJsonIsSkipped(): void
    {
        // Arrange
        file_put_contents($this->file, "PHP Fatal error: something\n"
            . json_encode(['timestamp' => '29/08/2099 10:00:00', 'level' => 'error', 'message' => 'real']) . "\n");

        // Act
        $messages = array_column($this->read(), 'message');

        // Assert
        $this->assertSame(['real'], $messages);
    }

    /**
     * A file it cannot open contributes nothing rather than stopping the read.
     */
    public function testAnUnreadableFileIsSkipped(): void
    {
        // Assert
        $this->assertSame([], $this->call('tailLines', '/no/such/file.log'));
    }

    /**
     * The page carries the filters, the lines, and where to widen them.
     *
     * An empty result with no explanation is the state a reader concludes the tool is broken
     * from, and it is the most common one — the filters are narrow by default on purpose.
     */
    public function testThePageExplainsAnEmptyResult(): void
    {
        // Arrange
        $request = $this->request(['q' => 'nothing-matches-this-' . bin2hex(random_bytes(4))]);

        // Act
        $html = $this->call('renderLogViewer', $request);

        // Assert
        $this->assertStringContainsString('Nothing matches', $html);
        $this->assertStringContainsString('log-filters', $html);
    }

    /**
     * A log message is escaped, because a log message is arbitrary text.
     *
     * A logged exception carries whatever the request contained, and this page is opened by a
     * developer holding a debug grant — the one visitor whose session is worth stealing.
     */
    public function testAMessageIsEscaped(): void
    {
        // Arrange
        $this->write([['29/08/2099 10:00:00', 'error', '<script>alert(1)</script>']]);

        // Act
        $html = $this->call('renderLogViewer', $this->request([]));

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
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

    /** @param list<array{0:string,1:string,2:string}> $lines */
    private function write(array $lines): void
    {
        $out = [];

        foreach ($lines as [$timestamp, $level, $message]) {
            $out[] = json_encode([
                'timestamp' => $timestamp,
                'level'     => $level,
                'message'   => $message,
            ]);
        }

        file_put_contents($this->file, implode("\n", $out) . "\n");
    }

    /** @return list<array<string, mixed>> */
    private function read(string $file = 'devpanel-logs-test.log', string $level = '', string $search = '', int $limit = 100): array
    {
        return $this->call('readLogLines', $file, $level, $search, $limit);
    }

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
