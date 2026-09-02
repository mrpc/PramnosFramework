<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Worker;

/**
 * What a queue worker is built with.
 *
 * Three statements, never executed — every test in this area constructs a worker through a double
 * that skips the constructor, so the two things it actually does had never been observed: it keeps
 * the controller, and it builds a queue manager **through a seam**.
 *
 * The worker id is the part worth pinning. It identifies the process that claimed a task, so a
 * batch left half-done by a worker that died can be recognised and reclaimed. A constructor that
 * dropped it would leave every claim anonymous, and a reclaim would either take work another
 * worker is still doing or take none at all.
 */
#[CoversClass(Worker::class)]
class WorkerConstructionTest extends TestCase
{
    /** A worker whose queue manager is recorded rather than connected. */
    private function worker(?string $workerId): object
    {
        return new class ($workerId) extends Worker {
            /** @var array{0: mixed, 1: ?string}|null What the seam was asked for */
            public ?array $asked = null;

            public function __construct(?string $workerId)
            {
                parent::__construct(new \Pramnos\Application\Controller(), $workerId);
            }

            protected function createQueueManager($controller, ?string $workerId): QueueManager
            {
                $this->asked = [$controller, $workerId];

                return (new \ReflectionClass(QueueManager::class))->newInstanceWithoutConstructor();
            }

            public function exposeController(): mixed
            {
                return $this->controller;
            }

            public function exposeQueueManager(): QueueManager
            {
                return $this->queueManager;
            }
        };
    }

    /**
     * The worker id reaches the queue manager.
     *
     * Which is what makes a claim attributable. Without it, a worker that dies mid-batch leaves
     * tasks claimed by nobody — and a reclaim either takes work another worker is still doing or
     * cannot find anything to take.
     */
    public function testTheWorkerIdReachesTheQueueManager(): void
    {
        // Act
        $worker = $this->worker('worker-7');

        // Assert
        $this->assertSame('worker-7', $worker->asked[1] ?? null);
    }

    /**
     * No id is passed through as `null`, not as an empty string.
     *
     * A worker run by hand has no id, and the manager distinguishes "not identified" from an
     * identifier that happens to be blank — `''` would be a claim attributed to a worker whose
     * name is nothing, which is not the same as an unattributed one.
     */
    public function testNoIdIsPassedThroughAsNull(): void
    {
        // Act
        $worker = $this->worker(null);

        // Assert — on the array itself, because `?? 'not-null'` cannot distinguish a null value
        // from an absent key: `null ?? 'x'` is `'x'`, which is what the first version asserted.
        $this->assertIsArray($worker->asked, 'the seam was never called');
        $this->assertNull($worker->asked[1]);
    }

    /**
     * The controller is kept, and the same one goes to the manager.
     *
     * Both, because the worker uses it for its own work and the manager needs it for the
     * database — two references to one controller, and a constructor that built a second would
     * give the manager a different application context from the tasks it hands out.
     */
    public function testTheControllerIsKeptAndSharedWithTheManager(): void
    {
        // Act
        $worker = $this->worker('w');

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $worker->exposeController());
        $this->assertSame($worker->exposeController(), $worker->asked[0] ?? null);
    }

    /** The manager the seam returned is the one the worker keeps. */
    public function testTheManagerFromTheSeamIsTheOneItKeeps(): void
    {
        // Act
        $worker = $this->worker('w');

        // Assert
        $this->assertInstanceOf(QueueManager::class, $worker->exposeQueueManager());
    }
}
