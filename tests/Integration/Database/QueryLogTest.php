<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The three development-only query logs — 39 statements that only run under `DEVELOPMENT`.
 *
 * Which is why they were uncovered, and also why they are worth covering: the machine that hits
 * this code is a developer's, running a whole suite in one process, and every bound in here was
 * added after that machine fell over. A 3,123-test suite died at the 2,394th with exit 255 and no
 * message, which is what PHP's memory limit looks like from the outside — the query log held the
 * full SQL of every statement for the life of the process, the duplicate detector held every
 * distinct statement as an **array key**, and the log was written in the destructor, so the run
 * that died wrote nothing at all. The log was empty on exactly the run somebody needed it for.
 *
 * So there are two kinds of assertion here. The feature works — a statement reaches the log, a
 * repeat reaches the duplicate log, the summary says how many queries and which URL. And the bounds
 * hold, because a diagnostic that costs more than the bug it finds gets switched off, and then it
 * is not a diagnostic at all.
 *
 * The duplicate detector is the interesting one. It exists to find *one request asking the same
 * thing twice* — the N+1 that no single query looks slow enough to explain — and its whole memory
 * is a set of fingerprints. Two decisions come out of that: the fingerprint is a hash rather than
 * the statement, because the map only ever answers "have I seen this", and forgetting the oldest
 * half is the right thing to lose, since a duplicate that far apart is two requests rather than
 * one request asking twice.
 *
 * Both backends: {@see QueryLogPostgreSQLTest} re-runs it. The logging sits in `runQuery()` after
 * the backend branch, so the two lanes reach it by different routes — and the PostgreSQL one
 * rewrites the statement before executing it, which is the text that gets logged and fingerprinted.
 */
#[CoversClass(Database::class)]
class QueryLogTest extends BaseTestCase
{
    private $db;

    /** Private log state, restored afterwards — the connection is shared with the whole suite. */
    private array $saved = [];

    private string $logDir = '';

    /** Files this test created under the log directory. */
    private array $made = [];

    private const LOG_PROPERTIES = [
        '_queryLogHandler',
        '_duplicateQueryLogHandler',
        '_slowQueryLogHandler',
        '_duplicateQueries',
        '_duplicateQueriesCounter',
        '_querieslog',
        '_slowquerieslog',
        '_numSlowqueries',
        '_customLogSlowQueries',
    ];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        /*
         * `startLogs()` writes under `LOG_PATH/logs`, which in the test environment is the system
         * temp directory and may not have the subdirectory yet. Without it `fopen()` answers false
         * and every assertion here would be about a handler that was never opened — passing, for
         * the wrong reason.
         */
        $this->logDir = LOG_PATH . DS . 'logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        foreach (self::LOG_PROPERTIES as $name) {
            $this->saved[$name] = $this->read($name);
        }
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        // Close anything still open before putting the old values back, or the handles leak for
        // the rest of the run.
        foreach (['_queryLogHandler', '_duplicateQueryLogHandler', '_slowQueryLogHandler'] as $name) {
            $handle = $this->read($name);
            if (is_resource($handle) && $handle !== ($this->saved[$name] ?? null)) {
                fclose($handle);
            }
        }

        foreach ($this->saved as $name => $value) {
            $this->write($name, $value);
        }
        $this->saved = [];

        foreach ($this->made as $file) {
            @unlink($file);
        }
        $this->made = [];

        parent::tearDown();
    }

    private function read(string $property): mixed
    {
        return (new \ReflectionProperty(Database::class, $property))->getValue($this->db);
    }

    private function write(string $property, mixed $value): void
    {
        (new \ReflectionProperty(Database::class, $property))->setValue($this->db, $value);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(Database::class, $method))->invoke($this->db, ...$args);
    }

    /** A scratch file under the log directory, removed in tearDown. */
    private function scratch(string $name): string
    {
        $path = $this->logDir . DS . $name . '-' . bin2hex(random_bytes(4)) . '.log';
        $this->made[] = $path;
        $this->made[] = $path . '.old';

        return $path;
    }

    /** Point the two logs at files this test owns, and start from a clean counter. */
    private function openScratchLogs(): array
    {
        $queries    = $this->scratch('queries');
        $duplicates = $this->scratch('duplicates');

        $this->write('_queryLogHandler', fopen($queries, 'a+'));
        $this->write('_duplicateQueryLogHandler', fopen($duplicates, 'a+'));
        $this->write('_duplicateQueries', []);
        $this->write('_duplicateQueriesCounter', 0);
        $this->write('_querieslog', '');

        return [$queries, $duplicates];
    }

    /** A statement that is valid on both backends and touches nothing. */
    private function probe(string $tag): string
    {
        return "SELECT 1 AS probe_" . $tag;
    }

    // ── Opening the logs ──────────────────────────────────────────────────────

    /**
     * `startLogs()` opens both logs and starts the duplicate map empty.
     *
     * Called from `connect()` under `DEVELOPMENT`, which is the only reason this whole family
     * exists. The map has to start empty on every connection: carried over, the first statement of
     * a new connection would be reported as a duplicate of one from the last.
     */
    public function testStartingTheLogsOpensBothAndClearsTheMap(): void
    {
        // Arrange
        $this->write('_duplicateQueries', ['stale' => true]);

        // Act
        $this->call('startLogs');

        // Assert
        $this->assertIsResource($this->read('_queryLogHandler'), 'the query log was not opened');
        $this->assertIsResource($this->read('_duplicateQueryLogHandler'));
        $this->assertSame([], $this->read('_duplicateQueries'), 'a stale fingerprint survived');

        $this->assertFileExists($this->logDir . DS . 'databaseQueries.log');
        $this->assertFileExists($this->logDir . DS . 'duplicateQueries.log');
    }

    // ── What reaches the query log ────────────────────────────────────────────

    /**
     * A statement and its timing reach the query log.
     *
     * The log is what a developer reads to answer "what did that page actually do", so the
     * statement itself has to be in it — a count would say a page was slow without saying why.
     */
    public function testAStatementAndItsTimingReachTheLog(): void
    {
        // Arrange
        [$queries] = $this->openScratchLogs();
        $sql = $this->probe('reaches_log');

        // Act
        $this->db->query($sql);
        $this->db->stopLogs();

        // Assert
        $written = (string) file_get_contents($queries);
        $this->assertStringContainsString('probe_reaches_log', $written);
        $this->assertStringContainsString('Time: ', $written, 'the timing is not in the log');
    }

    /**
     * The summary says how many queries and which URL, and it is written at the top of the batch.
     *
     * A log of statements with no request boundary is unreadable once two pages have been loaded —
     * the URL is what makes an entry attributable, and the count is the number worth seeing first.
     */
    public function testTheSummaryNamesTheRequestAndTheCount(): void
    {
        // Arrange
        [$queries] = $this->openScratchLogs();
        $this->db->query($this->probe('summary'));

        // Act
        $this->db->stopLogs();

        // Assert
        $written = (string) file_get_contents($queries);
        $this->assertStringContainsString(' queries :: ', $written);
        $this->assertStringContainsString(date('d/m/Y'), $written);
        $this->assertMatchesRegularExpression('/={10,}/', $written, 'no separator between requests');
    }

    // ── The duplicate detector ────────────────────────────────────────────────

    /**
     * The same statement twice is written to the duplicate log.
     *
     * What this exists to find is the N+1 that no single query is slow enough to explain: one
     * request asking the same question in a loop, where every individual answer looks fine.
     */
    public function testTheSameStatementTwiceIsReportedAsADuplicate(): void
    {
        // Arrange
        [, $duplicates] = $this->openScratchLogs();
        $sql = $this->probe('twice');

        // Act
        $this->db->query($sql);
        $this->db->query($sql);
        $this->db->stopLogs();

        // Assert
        $written = (string) file_get_contents($duplicates);
        $this->assertStringContainsString('probe_twice', $written, 'the repeat was not reported');
    }

    /**
     * A statement seen once is not a duplicate.
     *
     * The other direction, and the one that decides whether the log is worth opening: a detector
     * that reports every statement reports nothing.
     */
    public function testAStatementSeenOnceIsNotADuplicate(): void
    {
        // Arrange
        [, $duplicates] = $this->openScratchLogs();

        // Act
        $this->db->query($this->probe('first'));
        $this->db->query($this->probe('second'));
        $this->db->stopLogs();

        // Assert
        $this->assertSame('', (string) file_get_contents($duplicates), 'distinct statements reported');
    }

    /**
     * The request header is written once, however many duplicates follow it.
     *
     * `duplicateQueryHeader()` guards on the counter being zero, and the guard is the whole point:
     * a header before every entry gives a file that is more banner than content, and the entries
     * are what somebody came to read. One header per request is also what makes the file
     * attributable — the banner carries the URL.
     */
    public function testTheHeaderIsWrittenOncePerRequest(): void
    {
        // Arrange
        [, $duplicates] = $this->openScratchLogs();
        $sql = $this->probe('many');

        // Act — four repeats, so three duplicate entries
        for ($i = 0; $i < 4; $i++) {
            $this->db->query($sql);
        }
        $this->db->stopLogs();

        // Assert — counted by the date-and-URL line, which is one per header. The banner rule is
        // drawn twice per header, above the line and below it, so counting `=` runs counts rules.
        $written = (string) file_get_contents($duplicates);
        $this->assertSame(
            1,
            substr_count($written, date('d/m/Y') . ' :: '),
            'a header was written for every duplicate rather than once for the request'
        );
        $this->assertSame(
            3,
            substr_count($written, 'probe_many'),
            'the repeats after the first were not all recorded'
        );
    }

    /**
     * The detector's memory is fingerprints, and it forgets the oldest half when it is full.
     *
     * Keyed by the whole SQL string, a suite issuing fifty thousand distinct statements held fifty
     * thousand of them as array keys — which is the memory this feature was costing. Forgetting the
     * oldest half rather than clearing entirely is deliberate: what is lost is a statement seen
     * once at the start of a very long process and again at the end, and a duplicate that far apart
     * is two requests, not one request asking twice.
     */
    public function testTheFingerprintMapForgetsItsOldestHalfWhenFull(): void
    {
        // Arrange
        $this->write('_duplicateQueries', []);

        // Act — one past the bound
        for ($i = 0; $i <= 5000; $i++) {
            $this->call('rememberFingerprint', md5('statement ' . $i));
        }

        // Assert
        $map = (array) $this->read('_duplicateQueries');
        $this->assertLessThanOrEqual(5000, count($map), 'the map grew without limit');
        $this->assertGreaterThan(2000, count($map), 'it cleared instead of halving');

        // The newest is kept and the oldest is gone — a bound that dropped the recent end would
        // lose exactly the duplicates a developer is looking at.
        $this->assertArrayHasKey(md5('statement 5000'), $map, 'the newest fingerprint was dropped');
        $this->assertArrayNotHasKey(md5('statement 0'), $map, 'the oldest survived');
    }

    // ── The bound that made the log survive a crash ───────────────────────────

    /**
     * The accumulated log is written out once it is big enough, not held to the destructor.
     *
     * It used to be written in the destructor and nowhere else, so a process killed by the memory
     * limit this log was helping to reach wrote nothing at all. Both directions are asserted,
     * because a flush on every statement would turn a diagnostic into a write per query.
     */
    public function testTheLogIsFlushedOnceItIsBigEnough(): void
    {
        // Arrange
        [$queries] = $this->openScratchLogs();

        // Act — comfortably under the threshold
        $this->write('_querieslog', str_repeat('x', 1024));
        $this->call('flushQueryLog');

        // Assert
        $this->assertSame(0, (int) filesize($queries), 'it wrote on every statement');
        $this->assertSame(1024, strlen((string) $this->read('_querieslog')));

        // Act — past it
        $this->write('_querieslog', str_repeat('y', 300000));
        $this->call('flushQueryLog');

        // Assert
        clearstatcache(true, $queries);
        $this->assertGreaterThan(
            200000,
            (int) filesize($queries),
            'a process that died here would have written nothing'
        );
        $this->assertSame('', $this->read('_querieslog'), 'the buffer was not emptied after writing');
    }

    // ── Rotation ──────────────────────────────────────────────────────────────

    /**
     * A log past the size limit is moved aside and a fresh one started.
     *
     * The previous file is kept as `.old` rather than truncated, because the statements that
     * explain a problem are usually the ones just before somebody noticed it.
     */
    public function testALargeLogIsMovedAsideAndReplaced(): void
    {
        // Arrange
        $file = $this->scratch('rotate');
        file_put_contents($file, str_repeat('z', 600 * 1024));

        // Act
        $this->call('rotateLog', $file);

        // Assert
        $this->assertFileExists($file . '.old');
        $this->assertGreaterThan(500000, (int) filesize($file . '.old'), 'the old log was truncated');
        clearstatcache(true, $file);
        $this->assertSame(0, (int) filesize($file), 'the live log was not started fresh');
        $this->assertTrue(is_writable($file), 'the fresh log cannot be written to');
    }

    /**
     * A log under the limit is left exactly as it is.
     *
     * Rotating on every connection would throw away the log of the request before this one, which
     * on a developer's machine is the request they are debugging.
     */
    public function testASmallLogIsLeftAlone(): void
    {
        // Arrange
        $file = $this->scratch('keep');
        file_put_contents($file, 'a few statements');

        // Act
        $this->call('rotateLog', $file);

        // Assert
        $this->assertSame('a few statements', (string) file_get_contents($file));
        $this->assertFileDoesNotExist($file . '.old');
    }

    /**
     * A second rotation replaces the previous `.old` rather than accumulating files.
     *
     * Two generations is the bound: this is a log that exists to be looked at now, and a directory
     * filling with `.old.old` is how a diagnostic becomes a disk-space incident.
     */
    public function testASecondRotationReplacesThePreviousOldFile(): void
    {
        // Arrange
        $file = $this->scratch('twice');
        file_put_contents($file, str_repeat('1', 600 * 1024));
        $this->call('rotateLog', $file);

        file_put_contents($file, str_repeat('2', 600 * 1024));

        // Act
        $this->call('rotateLog', $file);

        // Assert
        $this->assertFileDoesNotExist($file . '.old.old', 'the generations accumulate');
        $old = (string) file_get_contents($file . '.old');
        $this->assertStringStartsWith('2', $old, 'the older generation was kept over the newer');
    }

    /**
     * Rotating a log that is not there creates it.
     *
     * The first connection on a fresh installation, and the branch that makes everything after it
     * work: without the file, `fopen(…, 'a+')` in `startLogs()` is the next thing to fail.
     */
    public function testRotatingAMissingLogCreatesIt(): void
    {
        // Arrange
        $file = $this->scratch('fresh');
        $this->assertFileDoesNotExist($file);

        // Act
        $this->call('rotateLog', $file);

        // Assert
        $this->assertFileExists($file);
        $this->assertTrue(is_writable($file));
    }

    // ── Closing ───────────────────────────────────────────────────────────────

    /**
     * `stopLogs()` closes the handles, and calling it again does nothing.
     *
     * The destructor calls it too, so a request that stops its logs explicitly reaches this twice —
     * and `fwrite()` on a closed handle is a warning plus a second summary in a file whose first
     * summary was correct.
     */
    public function testStoppingTwiceIsSafe(): void
    {
        // Arrange
        [$queries] = $this->openScratchLogs();
        $this->db->query($this->probe('closing'));

        // Act
        $this->db->stopLogs();
        $afterFirst = (string) file_get_contents($queries);
        $this->db->stopLogs();

        // Assert
        $this->assertFalse(is_resource($this->read('_queryLogHandler')), 'the handle stayed open');
        $this->assertSame(
            $afterFirst,
            (string) file_get_contents($queries),
            'a second summary was appended over a correct one'
        );
    }

    /**
     * With no logs open, stopping is a no-op rather than a warning.
     *
     * Which is every production request: `startLogs()` only runs under `DEVELOPMENT`, and the
     * destructor calls `stopLogs()` regardless.
     */
    public function testStoppingWithNoLogsOpenDoesNothing(): void
    {
        // Arrange
        $this->write('_queryLogHandler', null);
        $this->write('_duplicateQueryLogHandler', null);
        $this->write('_slowQueryLogHandler', null);

        // Act & Assert — the assertion is that nothing is raised
        $this->db->stopLogs();
        $this->assertNull($this->read('_queryLogHandler'));
    }

    /**
     * A slow statement is recorded with its time and the threshold it crossed.
     *
     * The threshold is in the entry on purpose: «0.9s» means nothing without knowing what counts as
     * slow here, and the number is configurable per installation.
     */
    public function testASlowStatementIsRecordedWithTheThresholdItCrossed(): void
    {
        // Arrange — a threshold anything will cross, so the branch runs on a fast machine too
        $slow = $this->scratch('slow');
        $this->write('_slowQueryLogHandler', fopen($slow, 'a+'));
        $this->write('_customLogSlowQueries', true);
        $this->write('_slowquerieslog', '');
        $this->write('_numSlowqueries', 0);
        $originalThreshold = $this->db->longQueryTime;
        $this->db->longQueryTime = 0;

        try {
            // Act
            $this->db->query($this->probe('slow'));
            $this->db->stopLogs();

            // Assert
            $written = (string) file_get_contents($slow);
            $this->assertStringContainsString('probe_slow', $written, 'the slow statement is not named');
            $this->assertStringContainsString('Time: ', $written);
            $this->assertStringContainsString(' > 0', $written, 'the threshold it crossed is missing');
            $this->assertGreaterThan(0, (int) $this->read('_numSlowqueries'));
        } finally {
            $this->db->longQueryTime = $originalThreshold;
        }
    }

    /**
     * Nothing is written when no statement was slow.
     *
     * `stopLogs()` guards the write on the counter, so a request with a slow-query log open and
     * nothing slow in it leaves a banner-only entry out of the file. A log of empty requests is a
     * log nobody scrolls through.
     */
    public function testNothingIsWrittenWhenNoStatementWasSlow(): void
    {
        // Arrange
        $slow = $this->scratch('noslow');
        $this->write('_slowQueryLogHandler', fopen($slow, 'a+'));
        $this->write('_customLogSlowQueries', true);
        $this->write('_slowquerieslog', '');
        $this->write('_numSlowqueries', 0);

        // Act
        $this->db->stopLogs();

        // Assert
        $this->assertSame('', (string) file_get_contents($slow), 'an empty request left a banner');
    }
}
