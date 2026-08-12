<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\RequestId;
use Pramnos\Debug\RequestLog;
use Pramnos\Logs\Logger;

/**
 * The thread from a response back to the lines the request wrote.
 *
 * The toolbar's data travels with the response it describes, and a request that
 * died has no response to carry anything — an error page cannot hold a `_debug`
 * key, and the header that still gets through has room for a count but never a
 * message. {@see RequestId} names the request, Logger writes the name on every
 * line, and {@see RequestLog} reads them back.
 *
 * What these tests pin is mostly *what must not happen*: no id in production, no
 * change to the log format there, and above all no lines returned for anything
 * other than the exact id asked for. On a live server the toolbar is open for one
 * browser while everybody else logs into the same seconds, and a time-window
 * implementation would hand their lines over.
 */
class RequestLogTest extends TestCase
{
    /**
     * Log directory for this test, under the temp dir LOG_PATH points at.
     */
    private string $logDir;

    protected function setUp(): void
    {
        RequestId::reset();
        $this->logDir = Logger::logDirectory();
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        RequestId::reset();
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . 'reqlog-test*.log') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ── RequestId ─────────────────────────────────────────────────────────────

    /**
     * No id is issued until something activates it.
     *
     * This is what keeps a production installation's logs byte-identical: the
     * Logger asks for `activeId()`, gets null, and adds nothing.
     */
    public function testNoIdIsIssuedUntilActivated(): void
    {
        // Arrange — the default state

        // Act & Assert
        $this->assertNull(RequestId::activeId());
        $this->assertFalse(RequestId::isActive());
    }

    /**
     * Once activated, the id is stable for the rest of the request.
     *
     * Two log lines from the same request must be findable together; an id that
     * changed per call would name each line uniquely and correlate nothing.
     */
    public function testTheIdIsStableWithinARequest(): void
    {
        // Arrange
        RequestId::activate();

        // Act
        $first  = RequestId::activeId();
        $second = RequestId::activeId();

        // Assert
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $first);
    }

    /**
     * reset() gives the next request its own name.
     *
     * A worker serving many requests in one PHP lifetime would otherwise file
     * every line it ever wrote under the first request it handled.
     */
    public function testResetIssuesANewIdForTheNextRequest(): void
    {
        // Arrange
        RequestId::activate();
        $first = RequestId::current();

        // Act
        RequestId::reset();
        RequestId::activate();

        // Assert
        $this->assertNotSame($first, RequestId::current());
    }

    // ── The log format ────────────────────────────────────────────────────────

    /**
     * With ids inactive, a simple log line keeps the plain format it always had.
     *
     * The change must be invisible where the toolbar is not running: log parsers,
     * shipped log pipelines and anybody's `tail -f` predate this feature.
     */
    public function testTheLogFormatIsUnchangedWhenIdsAreInactive(): void
    {
        // Arrange — ids not activated
        $file = 'reqlog-test-plain';

        // Act
        Logger::log('a plain message', $file);
        $written = (string) file_get_contents($this->logPath($file));

        // Assert — "[dd/mm/yyyy HH:ii:ss] message", not JSON
        $this->assertStringStartsWith('[', $written);
        $this->assertStringContainsString('a plain message', $written);
        $this->assertStringNotContainsString('"request"', $written);
    }

    /**
     * With ids active, every line carries the request's name.
     */
    public function testALineWrittenWhileActiveCarriesTheRequestId(): void
    {
        // Arrange
        RequestId::activate();
        $id   = RequestId::current();
        $file = 'reqlog-test-active';

        // Act
        Logger::log('something happened', $file);
        $written = (string) file_get_contents($this->logPath($file));

        // Assert
        $decoded = json_decode(trim($written), true);
        $this->assertIsArray($decoded);
        $this->assertSame($id, $decoded['request']);
        $this->assertSame('something happened', $decoded['message']);
    }

    // ── Reading back ──────────────────────────────────────────────────────────

    /**
     * The lines of one request come back, and nobody else's do.
     *
     * The second request in this test is what a live server always has: another
     * visitor, logging into the same file at the same moment. Its lines must not
     * appear, which is why lookup is by id and never by time.
     */
    public function testOnlyTheAskedForRequestsLinesComeBack(): void
    {
        // Arrange — two requests, interleaved in one file
        $file = 'reqlog-test-mixed';
        RequestId::activate();
        $mine = RequestId::current();
        Logger::log('mine: first', $file);

        RequestId::reset();
        RequestId::activate();
        $theirs = RequestId::current();
        Logger::log('theirs: private', $file);

        RequestId::reset();
        RequestId::activate();
        // Re-establishing the first id is not possible through the public API,
        // so the third line is written under a third id — it stands in for any
        // other traffic and must be excluded just the same.
        Logger::log('somebody else again', $file);

        // Act
        $lines = RequestLog::forRequest($mine);

        // Assert
        $this->assertCount(1, $lines);
        $this->assertSame('mine: first', $lines[0]['message']);
        $this->assertNotSame($mine, $theirs, 'the two requests really are different');
    }

    /**
     * A level and its context survive the round trip.
     *
     * The reason to fetch these at all is a request that failed, and "error"
     * versus "info" is the first thing read.
     */
    public function testLevelAndContextSurvive(): void
    {
        // Arrange
        RequestId::activate();
        $id = RequestId::current();

        // Act
        Logger::error('it broke', ['where' => 'here'], 'reqlog-test-levels');
        $lines = RequestLog::forRequest($id);

        // Assert
        $this->assertNotEmpty($lines);
        $this->assertSame('error', $lines[0]['level']);
        $this->assertSame('it broke', $lines[0]['message']);
        $this->assertSame('here', $lines[0]['context']['where']);
        $this->assertSame('reqlog-test-levels.log', $lines[0]['file']);
    }

    /**
     * Anything that is not an id this class issued is refused outright.
     *
     * The value decides which lines are handed back and reaches file handling,
     * so the shape is checked rather than sanitised: a pattern that only accepts
     * sixteen hex characters cannot carry a path, a glob or a regex.
     */
    public function testAMalformedIdIsRefusedRatherThanSearchedFor(): void
    {
        // Arrange
        $bad = ['', '../../etc/passwd', '*', 'ZZZZZZZZZZZZZZZZ', str_repeat('a', 17), 'a b'];

        // Act & Assert
        foreach ($bad as $id) {
            $this->assertFalse(RequestLog::isValidId($id), $id . ' must not be a valid id');
            $this->assertSame([], RequestLog::forRequest($id), $id . ' must return nothing');
        }
    }

    /**
     * An id nothing was logged under returns an empty list, not an error.
     *
     * "No lines" is a real answer — the request may simply not have logged —
     * and the panel says so rather than reporting a failure.
     */
    public function testAnUnknownIdReturnsNothing(): void
    {
        // Arrange
        $unused = str_repeat('ab', 8);

        // Act & Assert
        $this->assertSame([], RequestLog::forRequest($unused));
    }

    /**
     * The full path of a log file for this test.
     */
    private function logPath(string $file): string
    {
        return $this->logDir . DIRECTORY_SEPARATOR . $file . '.log';
    }
}
