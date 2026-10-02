<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * The supervisor's cycle, obeying what an operator decided on the services screen.
 *
 * A stop written only as a sentinel file was undone on the next cycle: the worker exited and
 * the supervisor, which still had it on its list, started it again. These tests feed a cycle
 * the controls the screen writes and read the list the supervisor would act on.
 */
#[CoversClass(DaemonOrchestrator::class)]
class OrchestratorControlsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/orch_controls_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * A cycle's process ids.
     *
     * @return list<string>
     */
    private function ids(ControlsProbeOrchestrator $orch): array
    {
        return array_map(static fn (array $p): string => (string) $p['id'], $orch->collect());
    }

    /**
     * With no controls the list is the code's pools plus the framework's scheduler.
     */
    public function testWithoutControlsTheCodeDecides(): void
    {
        // Arrange
        $orch = new ControlsProbeOrchestrator($this->dir);

        // Act & Assert
        $this->assertSame(['queue-passes-1', 'queue-passes-2', 'schedule'], $this->ids($orch));
    }

    /**
     * A stopped service is left off the list, so the supervisor stops it and does not start
     * it again — the scheduler included.
     */
    public function testAStoppedServiceIsLeftOffTheList(): void
    {
        // Arrange
        $orch = new ControlsProbeOrchestrator($this->dir);
        $orch->controls['stopped'] = ['schedule' => ['id' => 'schedule'], 'queue-passes-2' => ['id' => 'queue-passes-2']];

        // Act & Assert
        $this->assertSame(['queue-passes-1'], $this->ids($orch));
    }

    /**
     * The screen's limits apply over the code's: a ceiling lowered to one shrinks the pool.
     */
    public function testTheScreenAdjustsACodePool(): void
    {
        // Arrange — the code says 2..4; the screen says at most 1
        $orch = new ControlsProbeOrchestrator($this->dir);
        $orch->controls['pools'] = ['passes' => self::row('passes', ['ceiling' => 1, 'floor' => 1])];

        // Act & Assert — one step down from the two it found running
        $this->assertSame(['queue-passes-1', 'schedule'], $this->ids($orch));
    }

    /**
     * A pool stopped on the screen runs no workers, and says who stopped it.
     */
    public function testAStoppedPoolRunsNoWorkers(): void
    {
        // Arrange
        $orch = new ControlsProbeOrchestrator($this->dir);
        $orch->controls['pools'] = ['passes' => self::row('passes', ['enabled' => false, 'updated_by' => 'alice'])];

        // Act
        $ids = $this->ids($orch);

        // Assert
        $this->assertSame(['schedule'], $ids);
        $this->assertSame('stopped by alice', $orch->poolDecisions()['passes']);
    }

    /**
     * A pool that exists only on the screen is supervised beside the code's.
     */
    public function testAScreenPoolIsAdded(): void
    {
        // Arrange
        $orch = new ControlsProbeOrchestrator($this->dir);
        $orch->controls['pools'] = ['mail' => self::row('mail', ['types' => 'mail', 'floor' => 1, 'ceiling' => 1])];

        // Act
        $processes = $orch->collect();

        // Assert
        $mail = array_values(array_filter($processes, static fn (array $p): bool => ($p['pool'] ?? '') === 'mail'));
        $this->assertCount(1, $mail);
        $this->assertContains('mail', $mail[0]['tokens']);
    }

    /**
     * Each cycle writes what it saw of every pool, for the screen: source, size, decision.
     */
    public function testTheCycleWritesThePoolsFile(): void
    {
        // Arrange
        $orch = new ControlsProbeOrchestrator($this->dir);
        $orch->controls['pools'] = [
            'mail'   => self::row('mail', ['floor' => 1, 'ceiling' => 1]),
            'passes' => self::row('passes', ['enabled' => false]),
        ];

        // Act
        $orch->collect();
        $file = json_decode((string) file_get_contents($this->dir . '/daemon_orchestrator_pools.json'), true);

        // Assert
        $pools = array_column($file['pools'], null, 'name');
        $this->assertGreaterThan(time() - 10, $file['at']);
        $this->assertSame('code', $pools['passes']['source']);
        $this->assertFalse($pools['passes']['enabled']);
        $this->assertSame('screen', $pools['mail']['source']);
        $this->assertSame(1, $pools['mail']['size']);
        $this->assertSame(40, $pools['mail']['backlog']);
        $this->assertStringContainsString('running=', $pools['mail']['decision']);
    }

    /**
     * A pool row as DaemonControls::pools() returns it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function row(string $name, array $values): array
    {
        return $values + [
            'name' => $name, 'types' => null, 'floor' => null, 'ceiling' => null, 'grow_above' => null,
            'shrink_below' => null, 'load_percent' => null, 'cooldown' => null, 'enabled' => true,
            'updated_at' => time(), 'updated_by' => null,
        ];
    }
}

/**
 * An orchestrator with one code pool, a fixed world, and controls the test sets.
 */
class ControlsProbeOrchestrator extends DaemonOrchestrator
{
    /** @var array{pools: array<string, mixed>, stopped: array<string, mixed>} */
    public array $controls = ['pools' => [], 'stopped' => []];

    /** @param string $dir Where the state and pools files go */
    public function __construct(private string $dir)
    {
    }

    /**
     * One cycle's desired list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        return $this->collectDesiredProcesses();
    }

    /** The test's controls. */
    protected function loadControls(): array
    {
        return $this->controls;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildDesiredProcesses(): array
    {
        return $this->queuePool('passes', ['types' => ['pass_unit'], 'floor' => 2, 'ceiling' => 4, 'grow_above' => 100, 'shrink_below' => 10]);
    }

    /** @param list<string> $types */
    protected function poolBacklog(array $types): ?int
    {
        return 40;
    }

    /** Idle. */
    protected function loadPerCore(): ?float
    {
        return 0.1;
    }

    /** Two of every pool alive at start. */
    protected function runningInPool(string $slug): int
    {
        return 2;
    }

    /** The test's directory. */
    protected function getStateFile(): string
    {
        return $this->dir . '/daemon_orchestrator_state.json';
    }

    /** Not a job. */
    protected function getJobName(): string
    {
        return 'controls_probe';
    }

    /** Not shown. */
    protected function getDashboardTitle(): string
    {
        return 'probe';
    }

    /** Not run. */
    protected function getEntryPoint(): string
    {
        return '/dev/null';
    }
}
