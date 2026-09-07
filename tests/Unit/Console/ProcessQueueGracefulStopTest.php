<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\ProcessQueue;
use Pramnos\Queue\Worker;

/**
 * A worker asked to stop finishes the task in hand and claims no more.
 *
 * The daemon loop has always checked `shouldStop()` on each pass, and the comment above
 * it said "the current batch finishes before the process exits". With the default
 * `--batch=20`, that is up to twenty more tasks **claimed after the signal**.
 *
 * Which matters because of what happens next: systemd's `TimeoutStopSec` is 90 seconds by
 * default, so a batch that outlasts it is `SIGKILL`ed mid-task — and the row it was
 * holding is left `processing` behind a lock nobody holds. That is the leak
 * `queue:reclaim` exists to clean up, produced here, by the code whose job is to stop
 * cleanly.
 *
 * The check goes **before** each claim rather than after each task, so the task in hand
 * always completes and nothing new is taken.
 */
#[CoversClass(ProcessQueue::class)]
class ProcessQueueGracefulStopTest extends TestCase
{
    /**
     * A batch runner that counts claims and can be asked to stop after N of them.
     *
     * @param int $stopAfter Raise the stop flag once this many tasks have been claimed
     */
    private function command(int $stopAfter): object
    {
        return new class ($stopAfter) extends ProcessQueue {
            public int $claims = 0;

            private bool $stopping = false;

            public function __construct(private readonly int $stopAfter)
            {
                parent::__construct();
            }

            protected function shouldStop(): bool
            {
                return $this->stopping;
            }

            public function runBatch(Worker $worker, int $limit): int
            {
                return $this->processBatch($worker, new BufferedOutput(), $limit, null, null, false);
            }

            /** Called by the fake worker on every claim. */
            public function claimed(): void
            {
                $this->claims++;

                if ($this->claims >= $this->stopAfter) {
                    // Exactly what the signal handler does: raise a flag, tear nothing down.
                    $this->stopping = true;
                }
            }
        };
    }

    /**
     * A worker whose queue never runs dry, so only the stop condition can end the batch.
     */
    private function worker(object $command): Worker
    {
        return new class ($command) extends Worker {
            public function __construct(private readonly object $command)
            {
                // No parent::__construct() — it builds a QueueManager against a database.
            }

            public function processNextTask(
                string|array|null $taskTypes = null,
                ?int $startFromTimestamp = null,
                bool $reverseOrder = false
            ): array|false {
                $this->command->claimed();

                return ['id' => $this->command->claims, 'type' => 'probe', 'status' => 'completed'];
            }
        };
    }

    /**
     * A stop raised mid-batch ends it there, rather than at the batch boundary.
     *
     * The assertion that could not be made before: with a limit of 20 and a stop after the
     * third task, exactly three are claimed. Without the check inside `processBatch()` it
     * is twenty — and the seventeen extra are what a `SIGKILL` during
     * `TimeoutStopSec` turns into abandoned rows.
     */
    public function testAStopMidBatchClaimsNothingMore(): void
    {
        // Arrange
        $command = $this->command(3);

        // Act
        $processed = $command->runBatch($this->worker($command), 20);

        // Assert
        $this->assertSame(3, $command->claims, 'tasks were claimed after the stop was requested');
        $this->assertSame(3, $processed, 'the batch reported work it did not do');
    }

    /**
     * The task in hand still finishes — the check is before the claim, not after it.
     *
     * The half that must not be lost in fixing the other: a stop that abandons the task
     * being processed is the very thing being prevented, and "stop faster" is not an
     * improvement if it drops work.
     */
    public function testTheTaskInHandIsNotAbandoned(): void
    {
        // Arrange — stop is raised while the first task is being claimed
        $command = $this->command(1);

        // Act
        $processed = $command->runBatch($this->worker($command), 20);

        // Assert
        $this->assertSame(1, $command->claims);
        $this->assertSame(1, $processed, 'the task in hand was not counted as done');
    }

    /**
     * With no stop requested, the batch runs to its limit.
     *
     * The control. A check that answered "stop" too eagerly would pass both tests above
     * and turn every worker into one that processes a single task per poll.
     */
    public function testWithoutAStopTheBatchRunsToItsLimit(): void
    {
        // Arrange — a stop threshold beyond the batch
        $command = $this->command(1000);

        // Act
        $processed = $command->runBatch($this->worker($command), 20);

        // Assert
        $this->assertSame(20, $processed);
        $this->assertSame(20, $command->claims);
    }

    /**
     * A stop before the first claim takes nothing at all.
     *
     * The shape of a worker respawned by a supervisor into a `.stop` sentinel that is still
     * there: it must claim nothing rather than one task per restart, for ever.
     */
    public function testAStopAlreadyRaisedClaimsNothing(): void
    {
        // Arrange
        $command = $this->command(0);
        $command->claimed();
        $command->claims = 0;

        // Act
        $processed = $command->runBatch($this->worker($command), 20);

        // Assert
        $this->assertSame(0, $processed);
        $this->assertSame(0, $command->claims, 'a worker told to stop claimed a task anyway');
    }
}
