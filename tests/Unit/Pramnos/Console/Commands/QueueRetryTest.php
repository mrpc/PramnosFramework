<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\QueueRetry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the queue:retry console command.
 *
 * queue:retry resets failed tasks back to pending via QueueManager::retryTask().
 * The database is unavailable in unit tests, so a test double overrides the two
 * data-access seams — retryTask() (records the ids it is asked to re-queue and
 * reports success for a configured allow-list) and getFailedTaskIds() (returns a
 * canned set of failed ids). This keeps the tests hermetic while exercising the
 * real argument/option handling and counting logic.
 *
 * Invariants covered:
 *  - Passing neither an id nor --all is an error (no silent no-op).
 *  - Passing both an id and --all is an error (ambiguous request).
 *  - A single id delegates exactly one retryTask() call and reports success.
 *  - A non-failed / missing id yields FAILURE and re-queues nothing.
 *  - --all iterates every failed id and reports the re-queued count.
 *  - --all with no failed tasks reports a friendly message and succeeds.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(QueueRetry::class)]
class QueueRetryTest extends TestCase
{
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Prevent PHP 8.4 "Undefined array key" warnings from Symfony's completion command.
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
     * Build a QueueRetry test double.
     *
     * @param int[] $failedIds     Ids returned by getFailedTaskIds()
     * @param int[] $retryableIds  Ids for which retryTask() reports success
     *                             (defaults to every failed id)
     */
    private function makeCommand(array $failedIds, ?array $retryableIds = null): QueueRetry
    {
        return new class($failedIds, $retryableIds ?? $failedIds) extends QueueRetry {
            /** @var int[] */
            private array $failedIds;
            /** @var int[] */
            private array $retryableIds;
            /** @var int[] Ids actually passed to retryTask() */
            public array $retried = [];

            public function __construct(array $failedIds, array $retryableIds)
            {
                parent::__construct();
                $this->failedIds    = $failedIds;
                $this->retryableIds = $retryableIds;
            }

            protected function getFailedTaskIds(): array
            {
                return $this->failedIds;
            }

            protected function retryTask(int $taskId): bool
            {
                $this->retried[] = $taskId;
                // Mirror QueueManager::retryTask(): only failed tasks succeed.
                return in_array($taskId, $this->retryableIds, true);
            }
        };
    }

    private function tester(Command $command): CommandTester
    {
        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);
        return new CommandTester($app->find('queue:retry'));
    }

    /**
     * Running with neither an id nor --all must fail loudly so the operator is
     * told how to target tasks, rather than silently doing nothing.
     */
    public function testFailsWhenNeitherIdNorAll(): void
    {
        // Arrange
        $command = $this->makeCommand([1, 2]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute([]);

        // Assert — failure, no retries attempted, guidance shown
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([], $command->retried);
        $this->assertStringContainsString('--all', $tester->getDisplay());
    }

    /**
     * An id and --all together is ambiguous and must be rejected before any
     * task is touched.
     */
    public function testFailsWhenBothIdAndAll(): void
    {
        // Arrange
        $command = $this->makeCommand([1, 2]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['id' => '1', '--all' => true]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([], $command->retried, 'No retry should happen on an ambiguous request');
        $this->assertStringContainsString('not both', $tester->getDisplay());
    }

    /**
     * A single valid id must delegate exactly one retryTask() call for that id
     * and report a single re-queue.
     */
    public function testSingleIdRetriesThatTask(): void
    {
        // Arrange — task 42 is retryable
        $command = $this->makeCommand([42], [42]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['id' => '42']);

        // Assert — one retry, for id 42, success reported
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([42], $command->retried);
        $this->assertStringContainsString('Re-queued 1 task', $tester->getDisplay());
        $this->assertStringContainsString('42', $tester->getDisplay());
    }

    /**
     * A task that is not in the failed state (retryTask() returns false) must
     * yield FAILURE with an explanatory message, so a typo'd or already-running
     * id is not reported as a success.
     */
    public function testSingleIdThatIsNotFailedReportsFailure(): void
    {
        // Arrange — id 99 exists in no retryable set
        $command = $this->makeCommand([], []);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['id' => '99']);

        // Assert — failure, retry was attempted once, message explains why
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([99], $command->retried);
        $this->assertStringContainsString('not re-queued', $tester->getDisplay());
    }

    /**
     * --all must attempt every failed id and report the number successfully
     * re-queued. Here all three ids are retryable.
     */
    public function testAllRetriesEveryFailedTask(): void
    {
        // Arrange
        $command = $this->makeCommand([7, 8, 9]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['--all' => true]);

        // Assert — every id attempted, count reported
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([7, 8, 9], $command->retried);
        $this->assertStringContainsString('Re-queued 3 failed task(s)', $tester->getDisplay());
    }

    /**
     * --all counts only the tasks that were actually re-queued: if some ids can
     * no longer be reset the reported count reflects only the successes.
     */
    public function testAllCountsOnlySuccessfulRetries(): void
    {
        // Arrange — three failed ids, but only two are still retryable
        $command = $this->makeCommand([7, 8, 9], [7, 9]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['--all' => true]);

        // Assert — all attempted, but only 2 counted
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([7, 8, 9], $command->retried);
        $this->assertStringContainsString('Re-queued 2 failed task(s)', $tester->getDisplay());
    }

    /**
     * --all with an empty failed set must report a friendly message and succeed
     * without attempting any retries.
     */
    public function testAllWithNoFailedTasks(): void
    {
        // Arrange
        $command = $this->makeCommand([]);
        $tester  = $this->tester($command);

        // Act
        $exit = $tester->execute(['--all' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([], $command->retried);
        $this->assertStringContainsString('No failed tasks', $tester->getDisplay());
    }
}
