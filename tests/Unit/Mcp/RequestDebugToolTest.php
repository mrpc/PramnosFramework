<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\RequestLog;
use Pramnos\Logs\Logger;
use Pramnos\Mcp\Tools\RequestDebugTool;

/**
 * Reading back what a request did, from outside the browser.
 *
 * The debug toolbar answers this for a response somebody is looking at. A request that *died*
 * carried almost nothing back, so the lines on disk are the only record — and the id needed to
 * find them is something you only have after somebody has read an error page and copied it out,
 * which on a request that never rendered one nobody has.
 */
#[CoversClass(RequestDebugTool::class)]
#[CoversClass(RequestLog::class)]
class RequestDebugToolTest extends TestCase
{
    private string $logDir;

    private string $file;

    protected function setUp(): void
    {
        $this->logDir = Logger::logDirectory();

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        $this->file = $this->logDir . DIRECTORY_SEPARATOR . 'reqdebug-test.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * With no id, it lists the requests that went wrong — most recent first.
     *
     * The default is `error` on purpose: "what blew up" is the question, and a list of every
     * request the log knows about buries the two that matter under two hundred that do not.
     */
    public function testItListsTheRequestsThatWentWrong(): void
    {
        // Arrange
        $this->write([
            ['a1b2c3d4e5f60718', 'info',  '2026-08-29 10:00:00', 'started'],
            ['a1b2c3d4e5f60718', 'info',  '2026-08-29 10:00:01', 'finished'],
            ['00112233445566aa', 'error', '2026-08-29 10:05:00', 'column "userid" does not exist'],
            ['ffeeddccbbaa9988', 'error', '2026-08-29 10:09:00', 'later failure'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute([]);

        // Assert
        $ids = array_column($answer['requests'], 'request');
        $this->assertSame(['ffeeddccbbaa9988', '00112233445566aa'], $ids,
            'most recent first, and the healthy request is not in the list');
        $this->assertSame('column "userid" does not exist', $answer['requests'][1]['message']);
    }

    /**
     * `"level": ""` lists every request, including the ones that worked.
     */
    public function testAnEmptyLevelListsEverything(): void
    {
        // Arrange
        $this->write([
            ['a1b2c3d4e5f60718', 'info', '2026-08-29 10:00:00', 'started'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute(['level' => '']);

        // Assert
        $this->assertSame(['a1b2c3d4e5f60718'], array_column($answer['requests'], 'request'));
    }

    /**
     * A request's lines come back oldest first, with the levels counted.
     */
    public function testOneRequestsLinesComeBackInOrder(): void
    {
        // Arrange
        $this->write([
            ['00112233445566aa', 'info',  '2026-08-29 10:05:00', 'first'],
            ['00112233445566aa', 'error', '2026-08-29 10:05:01', 'second'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute(['request' => '00112233445566aa']);

        // Assert
        $this->assertSame('first', $answer['lines'][0]['message']);
        $this->assertSame(['info' => 1, 'error' => 1], $answer['counts']);
        $this->assertSame('2026-08-29 10:05:00', $answer['timespan']['from']);
    }

    /**
     * Another request's lines are not this request's lines.
     *
     * The property the whole design turns on: on a live server the toolbar is open for one
     * browser while everybody else logs into the same seconds, and a time-window implementation
     * would hand their lines over.
     */
    public function testItReturnsOnlyTheRequestAskedFor(): void
    {
        // Arrange
        $this->write([
            ['00112233445566aa', 'error', '2026-08-29 10:05:00', 'mine'],
            ['ffeeddccbbaa9988', 'error', '2026-08-29 10:05:00', 'somebody else'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute(['request' => '00112233445566aa']);

        // Assert
        $this->assertCount(1, $answer['lines']);
        $this->assertSame('mine', $answer['lines'][0]['message']);
    }

    /**
     * Something that is not an id is refused, and the refusal says what to do instead.
     */
    public function testSomethingThatIsNotAnIdIsRefused(): void
    {
        // Act
        $answer = (new RequestDebugTool())->execute(['request' => '../../etc/passwd']);

        // Assert
        $this->assertArrayHasKey('error', $answer);
        $this->assertStringContainsString('list', $answer['note']);
    }

    /**
     * A valid id with nothing behind it explains why, rather than looking like a bug.
     *
     * Lines are tagged only while the toolbar is active for that visitor — so "no lines" is
     * usually correct and is exactly what a developer will misread as the tool being broken.
     */
    public function testAnIdWithNoLinesExplainsItself(): void
    {
        // Act
        $answer = (new RequestDebugTool())->execute(['request' => 'aaaaaaaaaaaaaaaa']);

        // Assert
        $this->assertSame([], $answer['lines']);
        $this->assertStringContainsString('toolbar', $answer['note']);
    }

    /**
     * With no failing request, the note says how to see the rest.
     */
    public function testNoFailuresSaysHowToSeeTheRest(): void
    {
        // Act
        $answer = (new RequestDebugTool())->execute(['level' => 'critical']);

        // Assert
        $this->assertSame([], $answer['requests']);
        $this->assertStringContainsString('"level": ""', $answer['note']);
    }

    /**
     * The worst line explains the request, and the last one at that level wins.
     *
     * A request that failed twice is usually explained by the second failure; the first is what
     * led to it.
     */
    public function testTheLastWorstLineIsTheSummary(): void
    {
        // Arrange
        $this->write([
            ['00112233445566aa', 'error', '2026-08-29 10:05:00', 'first failure'],
            ['00112233445566aa', 'error', '2026-08-29 10:05:02', 'second failure'],
            ['00112233445566aa', 'info',  '2026-08-29 10:05:03', 'and then this'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute([]);

        // Assert
        $this->assertSame('second failure', $answer['requests'][0]['message']);
        $this->assertSame('error', $answer['requests'][0]['worst']);
        $this->assertSame(3, $answer['requests'][0]['lines']);
    }

    /**
     * A line without a well-formed id belongs to no request.
     *
     * Production logs have no request ids at all — the id is only written while the toolbar is
     * active — so most lines in a real file look like this, and treating them as one anonymous
     * "request" would produce a single entry with ten thousand lines.
     */
    public function testLinesWithNoIdBelongToNoRequest(): void
    {
        // Arrange
        file_put_contents($this->file, implode("\n", [
            json_encode(['timestamp' => '2026-08-29 10:00:00', 'level' => 'error', 'message' => 'no id']),
            json_encode(['request' => 'not-an-id', 'level' => 'error', 'message' => 'bad id']),
            'this line is not JSON at all',
        ]) . "\n");

        // Act
        $answer = (new RequestDebugTool())->execute(['level' => '']);

        // Assert
        $this->assertSame([], $answer['requests']);
    }

    /**
     * "Most recent" survives a month boundary.
     *
     * The log writes `d/m/Y H:i:s`. Compared as strings, `01/09/2026` sorts before
     * `29/08/2026` — so for the first days of every month the list is upside down and the
     * request somebody is asking about is at the bottom. Right again by the tenth, which is
     * why it is never reported.
     */
    public function testMostRecentSurvivesAMonthBoundary(): void
    {
        // Arrange
        $this->write([
            ['aaaaaaaaaaaaaaaa', 'error', '29/08/2099 23:59:00', 'august'],
            ['bbbbbbbbbbbbbbbb', 'error', '01/09/2099 00:01:00', 'september'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute([]);

        // Assert
        $this->assertSame('bbbbbbbbbbbbbbbb', $answer['requests'][0]['request'],
            'string comparison would have put august first');
    }

    /**
     * A request's span is its first and last line, not the order they were read in.
     */
    public function testTheSpanIsTheFirstAndLastLine(): void
    {
        // Arrange — written out of order, as two log files would interleave
        $this->write([
            ['aaaaaaaaaaaaaaaa', 'info',  '01/09/2099 10:00:05', 'later'],
            ['aaaaaaaaaaaaaaaa', 'error', '01/09/2099 10:00:01', 'earlier'],
        ]);

        // Act
        $answer = (new RequestDebugTool())->execute([]);

        // Assert
        $this->assertSame('01/09/2099 10:00:01', $answer['requests'][0]['started']);
        $this->assertSame('01/09/2099 10:00:05', $answer['requests'][0]['ended']);
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:string}> $lines
     */
    private function write(array $lines): void
    {
        $out = [];

        foreach ($lines as [$id, $level, $timestamp, $message]) {
            $out[] = json_encode([
                'timestamp' => $timestamp,
                'level'     => $level,
                'message'   => $message,
                'request'   => $id,
                'context'   => [],
            ]);
        }

        file_put_contents($this->file, implode("\n", $out) . "\n");
    }
}
