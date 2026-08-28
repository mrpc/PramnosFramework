<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\LogAnalytics;
use Pramnos\Logs\LogManager;
use Pramnos\Mcp\Tools\LogAnalyticsTool;
use Pramnos\Mcp\Tools\LogErrorsTool;

/**
 * The log dashboard's figures, asked for by something that is not a screen.
 *
 * A hundred lines of aggregation used to live inside `LogController::dashboard()`, which meant
 * the answer to «what is going wrong on this installation» was reachable only by a human with a
 * browser and an administrator's session. This is the extracted service and the two MCP tools
 * that read it.
 *
 * The failure this file is really guarding against is the two callers drifting: a screen and a
 * tool computing the same figures separately both look right on their own, and the day they
 * disagree there is no way to tell which one is lying. So the contract is asserted here, once,
 * against log files written for the purpose.
 */
#[CoversClass(LogAnalytics::class)]
#[CoversClass(LogAnalyticsTool::class)]
#[CoversClass(LogErrorsTool::class)]
class LogAnalyticsTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        $this->written = [];

        parent::tearDown();
    }

    /**
     * A log file of JSON entries, which is the format the parser actually reads.
     *
     * @param list<array{0: string, 1: string, 2: int}> $entries level, message, seconds ago
     */
    private function log(string $name, array $entries): string
    {
        $lines = [];

        foreach ($entries as [$level, $message, $ago]) {
            $lines[] = json_encode([
                'timestamp' => date('c', time() - $ago),
                'level'     => $level,
                'message'   => $message,
            ]);
        }

        $path = LogManager::getLogFilePath($name, 'log');
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->written[] = $path;

        return $name . '.log';
    }

    /**
     * Every timespan the screen's selector offers is one the service knows.
     *
     * `6h` was on that selector and missing from the first version of this table, and an unknown
     * timespan falls back to a day — so the option would have kept working while quietly
     * answering a different question. Numbers under the wrong heading are worse than an error.
     */
    public function testEveryTimespanTheScreenOffersExists(): void
    {
        // Assert
        foreach (['1h', '6h', '24h', '7d', '30d'] as $timespan) {
            $this->assertArrayHasKey($timespan, LogAnalytics::TIMESPANS, $timespan . ' is offered');
        }
    }

    /**
     * An unknown timespan is a day, and says it is a day.
     *
     * The value arrives from a query string, so "anything" is the real input domain. Reporting
     * the timespan back is what stops the caller believing it got the hour it asked for.
     */
    public function testAnUnknownTimespanIsADayAndSaysSo(): void
    {
        // Act
        $summary = LogAnalytics::summary('whenever', ['nothing-here.log']);

        // Assert
        $this->assertSame('24h', $summary['timespan']);
        $this->assertSame(86400, $summary['to'] - $summary['from']);
    }

    /**
     * The shape is the same when there is nothing to read.
     *
     * Which is the state of a fresh checkout, and a missing key there is a fatal error on the one
     * screen somebody opened to find out why something *else* was broken.
     */
    public function testTheShapeSurvivesHavingNothingToRead(): void
    {
        // Act
        $summary = LogAnalytics::summary('1h', ['a-file-that-does-not-exist.log']);

        // Assert
        foreach ([
            'timespan', 'from', 'to', 'group',
            'trends', 'levels', 'topErrors', 'files', 'truncated',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary, $key . ' is part of the contract');
        }

        $this->assertSame([], $summary['topErrors']);
        $this->assertSame([], $summary['files']);
        $this->assertFalse($summary['truncated']);
    }

    /**
     * Levels are counted, errors are ranked, and the trend is labelled for the axis.
     *
     * One file, known contents: three errors of one kind, one of another, two harmless lines.
     * The ranking is the assertion that matters — the top row of that table is what somebody
     * actually acts on, and a stable sort by count is the only thing that makes it the right row.
     */
    public function testItCountsLevelsAndRanksErrors(): void
    {
        // Arrange
        $file = $this->log('test_analytics_' . bin2hex(random_bytes(3)), [
            ['error', 'Database went away', 120],
            ['error', 'Database went away', 90],
            ['error', 'Database went away', 60],
            ['error', 'Something else broke', 30],
            ['info', 'Signed in', 20],
            ['info', 'Signed out', 10],
        ]);

        // Act
        $summary = LogAnalytics::summary('1h', [$file]);

        // Assert
        $this->assertSame(4, $summary['levels']['error']);
        $this->assertSame(2, $summary['levels']['info']);

        $this->assertCount(2, $summary['topErrors']);
        $this->assertSame('Database went away', $summary['topErrors'][0]['message'],
            'the most frequent error is the first row');
        $this->assertSame(3, $summary['topErrors'][0]['count']);

        // The trend is keyed by a formatted label, because the chart draws these as its axis
        $this->assertNotSame([], $summary['trends']);
        $this->assertSame(6, array_sum($summary['trends']));

        foreach (array_keys($summary['trends']) as $label) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $label);
        }

        // And the file's own rate, which is what makes one file worth opening over another
        $this->assertSame(6, $summary['files'][$file]['total_entries']);
        $this->assertEqualsWithDelta(66.67, $summary['files'][$file]['error_rate'], 0.5);
    }

    /**
     * The same error in two files is one row carrying the total.
     *
     * Three files each reporting a failure four times is not three problems, and a table that
     * says so sends somebody looking for the difference between them.
     */
    public function testTheSameErrorInTwoFilesIsOneRow(): void
    {
        // Arrange
        $suffix = bin2hex(random_bytes(3));
        $first  = $this->log('test_merge_a_' . $suffix, [['error', 'Shared failure', 60]]);
        $second = $this->log('test_merge_b_' . $suffix, [
            ['error', 'Shared failure', 50],
            ['error', 'Shared failure', 40],
        ]);

        // Act
        $summary = LogAnalytics::summary('1h', [$first, $second]);

        // Assert
        $this->assertCount(1, $summary['topErrors']);
        $this->assertSame(3, $summary['topErrors'][0]['count']);
        $this->assertSame(3, $summary['levels']['error']);
    }

    /**
     * The files that carry no structured entries are skipped rather than miscounted.
     *
     * `GitDeploy` is shell output. Counting levels in it produces a number that looks like a
     * measurement and is not one.
     */
    public function testTheUnstructuredFilesAreSkipped(): void
    {
        // Assert
        $this->assertContains('GitDeploy', LogAnalytics::SKIP);
        $this->assertSame([], LogAnalytics::summary('1h', ['GitDeploy.log'])['files']);
    }

    // ── entries() ────────────────────────────────────────────────────────────

    /**
     * Reading entries defaults to the levels somebody means by "the error log".
     *
     * Not everything: an installation writes far more `info` than anything else, and a reader
     * that returns the newest hundred lines regardless of level returns sign-ins to somebody
     * asking what broke.
     */
    public function testEntriesDefaultToTheSeriousLevels(): void
    {
        // Arrange
        $file = $this->log('test_entries_' . bin2hex(random_bytes(3)), [
            ['info', 'Nothing to see', 60],
            ['error', 'This is the one', 30],
        ]);

        // Act
        $entries = LogAnalytics::entries([], [$file], '1h');

        // Assert
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('This is the one', $entries[0]['message']);
        $this->assertSame($file, $entries[0]['file'], 'which file it came from is half the answer');
    }

    /**
     * Newest first, across files, and bounded by the limit.
     *
     * The order is the service's promise rather than a side effect of directory order: a caller
     * asking for ten of two hundred wants the ten that just happened.
     */
    public function testEntriesComeBackNewestFirstAndBounded(): void
    {
        // Arrange
        $suffix = bin2hex(random_bytes(3));
        $old    = $this->log('test_order_a_' . $suffix, [['error', 'Older', 300]]);
        $recent = $this->log('test_order_b_' . $suffix, [
            ['error', 'Newer', 60],
            ['error', 'Newest', 10],
        ]);

        // Act
        $entries = LogAnalytics::entries(['error'], [$old, $recent], '1h', 2);

        // Assert
        $this->assertCount(2, $entries);
        $this->assertStringContainsString('Newest', $entries[0]['message']);
        $this->assertStringContainsString('Newer', $entries[1]['message']);
    }

    /**
     * And it can be narrowed by a search string.
     */
    public function testEntriesCanBeSearched(): void
    {
        // Arrange
        $file = $this->log('test_query_' . bin2hex(random_bytes(3)), [
            ['error', 'Connection refused', 60],
            ['error', 'Permission denied', 30],
        ]);

        // Act
        $entries = LogAnalytics::entries(['error'], [$file], '1h', 100, 'refused');

        // Assert
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('refused', $entries[0]['message']);
    }

    /**
     * `files()` answers with the directory rather than failing.
     *
     * A caller with no controller has no whitelist to consult, and an exception here would take
     * out an MCP tool for the same reason a screen would have shown an empty list.
     */
    public function testFilesListsWhatIsThere(): void
    {
        // Arrange
        $file = $this->log('test_listing_' . bin2hex(random_bytes(3)), [['info', 'Hello', 10]]);

        // Act
        $files = LogAnalytics::files();

        // Assert
        $this->assertContains($file, $files);
    }

    // ── The MCP tools ────────────────────────────────────────────────────────

    /**
     * The two tools say which question each answers, and share the service's timespans.
     *
     * That description text is the entire interface a caller sees before choosing one, so two
     * tools that read alike get picked at random. And a hand-written second list of timespans is
     * the drift this refactor exists to prevent — in the schema it would be a validation error on
     * a value the service accepts.
     */
    public function testTheToolsDescribeThemselvesAndShareTheTimespans(): void
    {
        // Arrange
        $analytics = new LogAnalyticsTool();
        $errors    = new LogErrorsTool();

        // Assert
        $this->assertSame('log-analytics', $analytics->name());
        $this->assertSame('log-errors', $errors->name());
        $this->assertStringContainsString('trend', strtolower($analytics->description()));
        $this->assertStringContainsString('log-analytics', $errors->description(),
            'the reader points at the summary, so a caller starts with counts');

        foreach ([$analytics, $errors] as $tool) {
            $this->assertSame(
                array_keys(LogAnalytics::TIMESPANS),
                $tool->inputSchema()['properties']['timespan']['enum'],
                $tool->name() . ' must not keep a second list of timespans'
            );
        }
    }

    /**
     * `log-analytics` returns the summary, and explains an empty one.
     *
     * "No log files were readable" and "nothing has gone wrong" are the same empty answer, and
     * only one of them means the caller should stop looking.
     */
    public function testTheSummaryToolExplainsAnEmptyAnswer(): void
    {
        // Act
        $answer = (new LogAnalyticsTool())->execute(['files' => ['nothing-here.log']]);

        // Assert
        $this->assertSame('24h', $answer['timespan']);
        $this->assertSame([], $answer['files']);
        $this->assertStringContainsString('the named files exist', $answer['note']);
    }

    /**
     * And it passes the timespan and the file filter through.
     */
    public function testTheSummaryToolPassesItsArgumentsOn(): void
    {
        // Arrange
        $file = $this->log('test_tool_' . bin2hex(random_bytes(3)), [['error', 'Boom', 30]]);

        // Act
        $answer = (new LogAnalyticsTool())->execute(['timespan' => '1h', 'files' => [$file]]);

        // Assert
        $this->assertSame('1h', $answer['timespan']);
        $this->assertSame(1, $answer['levels']['error']);
        $this->assertArrayNotHasKey('note', $answer);
    }

    /**
     * `log-errors` is bounded, and says whether it stopped early.
     *
     * An MCP response is a message, not a log file. And an answer that hit its own limit reads as
     * «that is all there is», which is the wrong conclusion when somebody is deciding whether a
     * problem is over.
     */
    public function testTheReaderIsBoundedAndSaysWhetherItIsComplete(): void
    {
        // Arrange — three errors, asked for two
        $file = $this->log('test_bound_' . bin2hex(random_bytes(3)), [
            ['error', 'One', 60],
            ['error', 'Two', 50],
            ['error', 'Three', 40],
        ]);

        // Act
        $capped = (new LogErrorsTool())->execute([
            'files' => [$file], 'timespan' => '1h', 'limit' => 2,
        ]);
        $whole = (new LogErrorsTool())->execute([
            'files' => [$file], 'timespan' => '1h', 'limit' => 50,
        ]);

        // Assert
        $this->assertCount(2, $capped['entries']);
        $this->assertFalse($capped['complete'], 'stopping at the limit is not the whole picture');
        $this->assertTrue($whole['complete']);
        $this->assertSame(3, $whole['count']);
    }

    /**
     * A limit past the ceiling is clamped rather than honoured.
     *
     * The caller is a language model, and «give me everything» is a thing it will ask for.
     */
    public function testTheReaderClampsAnAbsurdLimit(): void
    {
        // Act
        $answer = (new LogErrorsTool())->execute(['limit' => 100000, 'timespan' => '1h']);

        // Assert
        $this->assertLessThanOrEqual(200, count($answer['entries']));
        $this->assertSame(['emergency', 'alert', 'critical', 'error'], $answer['levels'],
            'and it reports the levels it actually used, not the ones it was not given');
    }
}
