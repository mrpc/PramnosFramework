<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * `DaemonOrchestrator::queuePool()`: a worker pool whose size follows the backlog.
 *
 * `BurstPolicy` existed as arithmetic with nothing calling it, so every application wrote a
 * fixed number of workers into `buildDesiredProcesses()`. These tests drive the pool cycle by
 * cycle with the backlog, the load and the running count supplied, which is the only way to
 * see the sequence of sizes a pool goes through.
 */
#[CoversClass(DaemonOrchestrator::class)]
class QueuePoolTest extends TestCase
{
    /**
     * An orchestrator whose world — backlog, load, running workers — the test sets.
     */
    private function orchestrator(): PoolProbeOrchestrator
    {
        return new PoolProbeOrchestrator();
    }

    /**
     * The sizes a pool takes over $cycles cycles with a fixed backlog.
     *
     * @param array<string, mixed> $config
     * @return list<int>
     */
    private function sizes(PoolProbeOrchestrator $orch, array $config, ?int $backlog, int $cycles): array
    {
        $orch->backlog = $backlog;
        $sizes = [];
        for ($i = 0; $i < $cycles; $i++) {
            $sizes[] = count($orch->pool('passes', $config));
        }

        return $sizes;
    }

    /**
     * A deep backlog grows the pool one worker at a time, waiting out the cooldown between
     * steps, and stops at the ceiling.
     */
    public function testADeepBacklogGrowsThePoolOneStepAtATimeToTheCeiling(): void
    {
        // Arrange
        $orch = $this->orchestrator();

        // Act — cooldown 1: a change, a cycle held, a change…
        $sizes = $this->sizes($orch, ['floor' => 1, 'ceiling' => 3, 'grow_above' => 10, 'shrink_below' => 2, 'cooldown' => 1], 500, 7);

        // Assert — the floor filled from nothing, then a step after every cycle of cooldown
        $this->assertSame([1, 1, 2, 2, 3, 3, 3], $sizes);
    }

    /**
     * A drained queue shrinks the pool back to its floor, never below it.
     */
    public function testADrainedQueueShrinksThePoolToItsFloor(): void
    {
        // Arrange — the pool has grown to its ceiling
        $orch = $this->orchestrator();
        $config = ['floor' => 1, 'ceiling' => 3, 'grow_above' => 10, 'shrink_below' => 2, 'cooldown' => 0];
        $this->sizes($orch, $config, 500, 5);

        // Act
        $sizes = $this->sizes($orch, $config, 0, 4);

        // Assert
        $this->assertSame([2, 1, 1, 1], $sizes);
    }

    /**
     * A backlog that cannot be read holds the pool where it is.
     *
     * The count most likely failed because the database is in trouble: growing makes that
     * worse, and shrinking would drain the pool during a blip.
     */
    public function testAnUnreadableBacklogHoldsThePool(): void
    {
        // Arrange
        $orch = $this->orchestrator();
        $config = ['floor' => 1, 'ceiling' => 4, 'grow_above' => 10, 'shrink_below' => 2, 'cooldown' => 0];
        $this->sizes($orch, $config, 500, 3);

        // Act
        $sizes = $this->sizes($orch, $config, null, 3);

        // Assert
        $this->assertSame([3, 3, 3], $sizes);
    }

    /**
     * Past the load ceiling the pool does not grow, however deep the queue.
     */
    public function testHighLoadStopsGrowth(): void
    {
        // Arrange
        $orch = $this->orchestrator();
        $orch->load = 0.95;

        // Act
        $sizes = $this->sizes($orch, ['floor' => 1, 'ceiling' => 4, 'grow_above' => 10, 'load_ceiling' => 0.75, 'cooldown' => 0], 500, 3);

        // Assert — the floor is kept, nothing added
        $this->assertSame([1, 1, 1], $sizes);
    }

    /**
     * A restarted supervisor starts from the workers it finds alive, not from the floor.
     */
    public function testARestartKeepsThePoolItFound(): void
    {
        // Arrange — four workers of this pool alive in the state file
        $orch = $this->orchestrator();
        $orch->running = 4;

        // Act — a backlog between the thresholds: hold
        $sizes = $this->sizes($orch, ['floor' => 1, 'ceiling' => 8, 'grow_above' => 100, 'shrink_below' => 2], 50, 2);

        // Assert
        $this->assertSame([4, 4], $sizes);
        // Read once, at the first cycle, for the pool's slug.
        $this->assertSame('passes', $orch->lastSlug);
    }

    /**
     * Each worker is a `queue:process` daemon for the pool's types, with its own id and lock.
     */
    public function testTheWorkersAreQueueProcessesForThePoolsTypes(): void
    {
        // Arrange
        $orch = $this->orchestrator();
        $orch->running = 2;
        $orch->backlog = 50;

        // Act
        $processes = $orch->pool('Pass Units', [
            'types' => 'pass_unit, mail',
            'floor' => 2, 'ceiling' => 2,
            'args'  => ['--runtime', '3600'],
        ]);

        // Assert
        $this->assertCount(2, $processes);
        $this->assertSame('queue-pass-units-2', $processes[1]['workerId']);
        $this->assertSame($processes[1]['workerId'], $processes[1]['id']);
        $this->assertSame('Pass Units', $processes[1]['pool']);
        $this->assertStringEndsWith('/var/QUEUE_PASS_UNITS_2.lock', $processes[1]['lockFile']);
        $this->assertSame(
            ['queue:process', '--daemon', '--quiet', '--type', 'pass_unit,mail', '--runtime', '3600', '--worker-id', 'queue-pass-units-2'],
            $processes[1]['tokens']
        );
        // The backlog was counted for exactly these types.
        $this->assertSame(['pass_unit', 'mail'], $orch->lastTypes);
    }

    /**
     * A pool for every type passes no --type, and counts the whole queue.
     */
    public function testAPoolWithoutTypesTakesEveryType(): void
    {
        // Arrange
        $orch = $this->orchestrator();
        $orch->running = 1;
        $orch->backlog = 0;

        // Act
        $processes = $orch->pool('all', ['floor' => 1, 'ceiling' => 1]);

        // Assert
        $this->assertNotContains('--type', $processes[0]['tokens']);
        $this->assertSame([], $orch->lastTypes);
    }

    /**
     * The decision is kept in words, for the dashboard: what it saw and why it chose.
     */
    public function testTheDecisionIsExplained(): void
    {
        // Arrange
        $orch = $this->orchestrator();
        $orch->running = 1;
        $orch->backlog = 500;

        // Act
        $orch->pool('passes', ['floor' => 1, 'ceiling' => 4, 'grow_above' => 10]);

        // Assert
        $decision = $orch->poolDecisions()['passes'];
        $this->assertStringContainsString('running=1 target=2 backlog=500', $decision);
    }

    /**
     * The real load reader answers a non-negative number per core on a machine that has one.
     */
    public function testTheLoadReaderAnswersPerCore(): void
    {
        // Arrange
        $orch = new RealSeamsOrchestrator(sys_get_temp_dir() . '/nonexistent-state.json');

        // Act
        $load = $orch->load();

        // Assert — Linux in the container always has a load average
        $this->assertIsFloat($load);
        $this->assertGreaterThanOrEqual(0.0, $load);
    }

    /**
     * The running count reads the state file: live entries of this pool only.
     */
    public function testTheRunningCountReadsTheStateFile(): void
    {
        // Arrange — two live workers of `passes` (this process's pid), one dead, one of another pool
        $file = tempnam(sys_get_temp_dir(), 'poolstate');
        file_put_contents($file, json_encode([
            ['id' => 'queue-passes-1', 'pid' => getmypid()],
            ['id' => 'queue-passes-2', 'pid' => getmypid()],
            ['id' => 'queue-passes-3', 'pid' => 999999999],
            ['id' => 'queue-mail-1', 'pid' => getmypid()],
        ]));
        $orch = new RealSeamsOrchestrator($file);

        // Act
        $running = $orch->running('passes');
        @unlink($file);

        // Assert
        $this->assertSame(2, $running);
    }

    /**
     * A backlog that cannot be counted is null, not zero.
     *
     * Zero would read as "drained" and shrink the pool during the database trouble that made
     * the count fail.
     */
    public function testAFailingBacklogCountIsNull(): void
    {
        // Arrange — the application's database refuses every query
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('queryBuilder')->willThrowException(new \RuntimeException('gone'));
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $previous  = $singleton;
        $singleton = $db;
        $orch = new RealSeamsOrchestrator(sys_get_temp_dir() . '/nonexistent-state.json');

        // Act
        try {
            $backlog = $orch->backlog(['pass_unit']);
        } finally {
            $singleton = $previous;
        }

        // Assert
        $this->assertNull($backlog);
    }
}

/**
 * An orchestrator whose backlog, load and running count are set by the test.
 */
class PoolProbeOrchestrator extends DaemonOrchestrator
{
    /** @var int|null What poolBacklog() answers */
    public ?int $backlog = 0;

    /** @var float|null What loadPerCore() answers */
    public ?float $load = 0.1;

    /** @var int What runningInPool() answers */
    public int $running = 0;

    /** @var list<string> The types the last backlog count was asked for */
    public array $lastTypes = [];

    /** @var string The slug the last running count was asked for */
    public string $lastSlug = '';

    /** Builds no console command: nothing here is run. */
    public function __construct()
    {
    }

    /**
     * Exposes queuePool().
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public function pool(string $name, array $config): array
    {
        return $this->queuePool($name, $config);
    }

    /** @param list<string> $types */
    protected function poolBacklog(array $types): ?int
    {
        $this->lastTypes = $types;

        return $this->backlog;
    }

    /** The load the test set. */
    protected function loadPerCore(): ?float
    {
        return $this->load;
    }

    /** The running count the test set. */
    protected function runningInPool(string $slug): int
    {
        $this->lastSlug = $slug;

        return $this->running;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildDesiredProcesses(): array
    {
        return [];
    }

    /** Not run as a job. */
    protected function getJobName(): string
    {
        return 'pool_probe';
    }

    /** Not shown anywhere. */
    protected function getDashboardTitle(): string
    {
        return 'test';
    }

    /** Not run. */
    protected function getEntryPoint(): string
    {
        return '/dev/null';
    }
}

/**
 * An orchestrator with the real seams, exposed, and a state file the test chooses.
 */
class RealSeamsOrchestrator extends DaemonOrchestrator
{
    /** @param string $stateFile Where loadState() reads */
    public function __construct(private string $stateFile)
    {
    }

    /** The real load reader. */
    public function load(): ?float
    {
        return $this->loadPerCore();
    }

    /** The real running count. */
    public function running(string $slug): int
    {
        return $this->runningInPool($slug);
    }

    /**
     * The real backlog count.
     *
     * @param list<string> $types
     */
    public function backlog(array $types): ?int
    {
        return $this->poolBacklog($types);
    }

    /** The test's state file. */
    protected function getStateFile(): string
    {
        return $this->stateFile;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildDesiredProcesses(): array
    {
        return [];
    }

    /** Not a job. */
    protected function getJobName(): string
    {
        return 'real_seams';
    }

    /** Not shown. */
    protected function getDashboardTitle(): string
    {
        return 'real seams';
    }

    /** Not run. */
    protected function getEntryPoint(): string
    {
        return '/dev/null';
    }
}
