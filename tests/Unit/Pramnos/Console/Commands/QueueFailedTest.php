<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\QueueFailed;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the queue:failed console command.
 *
 * queue:failed lists tasks in the terminal 'failed' state. The database layer
 * is unavailable in unit tests, so a test double overrides the single data
 * access seam (getFailedTasks) to return deterministic canned rows. This keeps
 * the tests hermetic while still exercising the real rendering, --json and
 * --limit behaviour of the command.
 *
 * Invariants covered:
 *  - An empty failed set prints a friendly message (not a table) and succeeds.
 *  - Failed tasks are rendered in a table containing their id/type/error.
 *  - --json emits valid JSON with a count and the task rows.
 *  - --limit is forwarded to the data-access layer.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(QueueFailed::class)]
class QueueFailedTest extends TestCase
{
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Build a QueueFailed test double whose data-access seam returns the
     * supplied rows and records the limit it was called with.
     *
     * @param array<int, array<string, mixed>> $tasks
     */
    private function makeCommand(array $tasks): QueueFailed
    {
        return new class($tasks) extends QueueFailed {
            /** @var array<int, array<string, mixed>> */
            private array $stubTasks;
            /** @var int|null Limit observed by getFailedTasks() */
            public ?int $seenLimit = null;

            public function __construct(array $tasks)
            {
                parent::__construct();
                $this->stubTasks = $tasks;
            }

            // Override the sole DB-touching seam so no live database is needed.
            protected function getFailedTasks(int $limit = 0): array
            {
                $this->seenLimit = $limit;
                return $this->stubTasks;
            }
        };
    }

    private function tester(array $tasks): CommandTester
    {
        return $this->testerFor($this->makeCommand($tasks));
    }

    private function testerFor(Command $command): CommandTester
    {
        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        return new CommandTester($app->find('queue:failed'));
    }

    /**
     * Two sample failed rows used across several tests.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleTasks(): array
    {
        return [
            [
                'id' => 42, 'type' => 'send_email', 'status' => 'failed',
                'attempts' => 3, 'maxattempts' => 3,
                'error' => 'SMTP connection refused',
                'createdat' => '2026-07-13 10:00:00',
                'updatedat' => '2026-07-13 10:05:00',
                'completedat' => '2026-07-13 10:05:00',
            ],
            [
                'id' => 43, 'type' => 'process_import', 'status' => 'failed',
                'attempts' => 5, 'maxattempts' => 5,
                'error' => "Malformed CSV\non line 12",
                'createdat' => '2026-07-13 11:00:00',
                'updatedat' => '2026-07-13 11:02:00',
                'completedat' => '2026-07-13 11:02:00',
            ],
        ];
    }

    /**
     * With no failed tasks the command must report an informational message and
     * succeed, rather than rendering an empty table.
     */
    public function testEmptyResultPrintsMessageAndSucceeds(): void
    {
        // Arrange
        $tester = $this->tester([]);

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('No failed tasks', $tester->getDisplay());
    }

    /**
     * Failed tasks must be rendered in a table that includes each task's id,
     * type and (truncated) error preview — the operator's primary triage view.
     */
    public function testRendersFailedTasksInTable(): void
    {
        // Arrange
        $tester = $this->tester($this->sampleTasks());

        // Act
        $exit = $tester->execute([]);
        $out  = $tester->getDisplay();

        // Assert — success and both task ids present
        $this->assertSame(Command::SUCCESS, $exit, $out);
        $this->assertStringContainsString('42', $out);
        $this->assertStringContainsString('send_email', $out);
        $this->assertStringContainsString('43', $out);
        $this->assertStringContainsString('process_import', $out);

        // Assert — attempts rendered as attempts/maxattempts
        $this->assertStringContainsString('3/3', $out);

        // Assert — a summary count line is printed
        $this->assertStringContainsString('2 failed task(s)', $out);
    }

    /**
     * --json must emit valid JSON carrying the count and the task rows, so the
     * command can be consumed by monitoring scripts.
     */
    public function testJsonOutputIsValidAndContainsTasks(): void
    {
        // Arrange
        $tester = $this->tester($this->sampleTasks());

        // Act
        $exit = $tester->execute(['--json' => true]);
        $out  = $tester->getDisplay();

        // Assert — exit code and JSON validity
        $this->assertSame(Command::SUCCESS, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded, 'Output must be valid JSON');

        // Assert — shape: count + task rows preserved
        $this->assertSame(2, $decoded['count']);
        $this->assertCount(2, $decoded['tasks']);
        $this->assertSame(42, $decoded['tasks'][0]['id']);
        $this->assertSame('send_email', $decoded['tasks'][0]['type']);
    }

    /**
     * --limit must be forwarded verbatim to the data-access layer so the DB
     * query can bound the result set instead of fetching every failed row.
     */
    public function testLimitOptionIsForwardedToDataAccess(): void
    {
        // Arrange
        $command = $this->makeCommand($this->sampleTasks());
        $tester  = $this->testerFor($command);

        // Act
        $tester->execute(['--limit' => 25]);

        // Assert — the seam observed the requested limit
        $this->assertSame(25, $command->seenLimit, '--limit must reach getFailedTasks()');
    }
}
