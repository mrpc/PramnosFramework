<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\Tools\CodeExecTool;
use Pramnos\Llm\Sandbox\CodeExecTask;
use Pramnos\Llm\Sandbox\Exec;
use Pramnos\Llm\Sandbox\PyodideRunner;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Worker;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * `code_exec` (a prototype — see docs/Pramnos_LLM_Analyst_Guide.md) against the real
 * `pramnos.llm_code_exec_runs` on MySQL and PostgreSQL: a run's row through its whole lifecycle,
 * `CodeExecTool` dispatching and polling without ever running the code itself, refusing past its
 * wait with no worker, and never auto-claimed by an ordinary worker. The sandbox itself
 * ({@see PyodideRunner}, Node.js + Pyodide) is exercised for real only where it is installed —
 * skipped rather than faked, since what is worth proving here is that it actually runs code.
 */
#[CoversClass(Exec::class)]
#[CoversClass(CodeExecTool::class)]
#[CoversClass(PyodideRunner::class)]
#[CoversClass(Analyst::class)]
class CodeExecTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * Created pending, for the person it was made for and nobody else; claimed once, not twice;
     * finished or failed is read back exactly; forgotten is gone.
     */
    #[DataProvider('databases')]
    public function testExecRowLifecycle(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act
        $runId = Exec::create(7, 'print(1)', 'a,b\n1,2');
        $theirs = Exec::find($runId, 8);
        $mine   = Exec::find($runId, 7);
        $firstClaim  = Exec::claim($runId, 'worker-1');
        $secondClaim = Exec::claim($runId, 'worker-2');
        Exec::finish($runId, 'done text');
        $finished = Exec::find($runId, 7);
        Exec::forget($runId);
        $gone = Exec::find($runId, 7);

        $failedId = Exec::create(7, 'raise ValueError()', null);
        Exec::claim($failedId);
        Exec::fail($failedId, 'ValueError');
        $failed = Exec::find($failedId, 7);

        // Assert
        $this->assertNull($theirs, 'not made for them');
        $this->assertSame('pending', $mine['status']);
        $this->assertSame('a,b\n1,2', str_replace("\r", '', (string) $mine['input']));
        $this->assertTrue($firstClaim);
        $this->assertFalse($secondClaim, 'already running: not claimed twice');
        $this->assertSame('done', $finished['status']);
        $this->assertSame('done text', $finished['result']);
        $this->assertNull($gone);
        $this->assertSame('error', $failed['status']);
        $this->assertSame('ValueError', $failed['error']);
    }

    /**
     * A worker that answers at once: the tool's own call blocks only as long as it takes, reads
     * the result back, and the row is gone once read — never run by the tool itself.
     */
    #[DataProvider('databases')]
    public function testAWorkerThatAnswersAtOnce(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $queue = new FakeCodeExecQueue(answer: ['ok' => true, 'result' => '4']);
        $tool  = new CodeExecTool(7, 5, $queue);

        // Act
        $said = $tool->execute(['code' => 'print(2+2)']);

        // Assert
        $this->assertSame('4', $said);
        $this->assertSame([CodeExecTool::TASK], array_column($queue->tasks, 0));
        $this->assertSame(7, $queue->tasks[0][1]['user_id']);
        $this->assertSame([], $this->rows(Exec::TABLE), 'state only while the call is answered');
    }

    /** A worker that fails the run: the tool reads the error back, not the stdout it never had. */
    #[DataProvider('databases')]
    public function testAWorkerThatFails(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tool = new CodeExecTool(7, 5, new FakeCodeExecQueue(answer: ['ok' => false, 'error' => 'Stopped: took too long.']));

        // Act
        $said = $tool->execute(['code' => 'while True: pass']);

        // Assert
        $this->assertSame('Error: Stopped: took too long.', $said);
        $this->assertSame([], $this->rows(Exec::TABLE));
    }

    /**
     * No worker at all: the tool waits its own `waitSeconds`, then refuses honestly rather than
     * running the code itself — the whole point of keeping this off the answering process.
     */
    #[DataProvider('databases')]
    public function testNoWorkerIsHonestPastTheWait(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tool = new CodeExecTool(7, 1, new FakeCodeExecQueue(answer: null));

        // Act
        $started = microtime(true);
        $said    = $tool->execute(['code' => 'print(1)']);
        $took    = microtime(true) - $started;

        // Assert
        $this->assertSame('Error: no sandbox worker finished this in time.', $said);
        $this->assertGreaterThanOrEqual(1, $took, 'actually waited, not returned at once');
        $this->assertSame([], $this->rows(Exec::TABLE), 'cleaned up rather than left behind');
    }

    /** Nothing to run, and nobody to run it for, are refused before a row is even written. */
    #[DataProvider('databases')]
    public function testRefusedBeforeARowIsWritten(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $queue = new FakeCodeExecQueue(answer: ['ok' => true, 'result' => 'unused']);

        // Act / Assert
        $this->assertStringStartsWith('Error: nothing to run', (new CodeExecTool(7, 5, $queue))->execute(['code' => '   ']));
        $this->assertStringStartsWith('Error: a sandbox run is made for', (new CodeExecTool(0, 5, $queue))->execute(['code' => 'print(1)']));
        $this->assertSame([], $queue->tasks, 'refused before anything was queued');
    }

    /**
     * Off the `analyst` tools until the application turns `code_exec` on — the table existing is
     * not enough by itself, the way `export` already needs `$adminId > 0`.
     */
    #[DataProvider('databases')]
    public function testOffUntilTurnedOn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act
        $before = array_keys(Analyst::tools(7));
        $this->configure(['analyst' => ['code_exec' => true]]);
        $after = array_keys(Analyst::tools(7));
        $noAdmin = array_keys(Analyst::tools(0));

        // Assert
        $this->assertNotContains(CodeExecTool::TASK, $before);
        $this->assertContains(CodeExecTool::TASK, $after);
        $this->assertNotContains(CodeExecTool::TASK, $noAdmin, 'a sandbox run is made for somebody');
    }

    /**
     * The one property this whole design leans on: an ordinary queue worker, told nothing about
     * `code_exec`, never claims it — {@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS} does not
     * name it, unlike `ParallelTools`'s own task, which is safe for any worker to run.
     */
    public function testNeverClaimedByAnOrdinaryWorker(): void
    {
        $this->assertArrayNotHasKey(CodeExecTool::TASK, Worker::FRAMEWORK_HANDLERS);
    }

    /**
     * The sandbox itself, end to end, where this host actually has it (`npm install` run in
     * `resources/js/sandbox-runner/`) — skipped, not faked, everywhere else. `CodeExecTask`'s own
     * body is exactly this sequence, with its payload unwrapped from a `QueueItem` first.
     */
    public function testTheSandboxItself(): void
    {
        $runner = PyodideRunner::default();
        if (!$runner->available()) {
            $this->markTestSkipped('No Node.js / pyodide here — run `npm install` in resources/js/sandbox-runner/ to exercise this.');
        }

        // Arrange / Act
        $plain  = $runner->run('print(1 + 1)', null, 15, PyodideRunner::DEFAULT_MEMORY_MB);
        $pandas = $runner->run(
            "import pandas as pd\nprint(pd.read_csv('/tmp/input.csv')['total'].sum())",
            "region,total\nAthens,10\nPatras,5\n",
            20,
            PyodideRunner::DEFAULT_MEMORY_MB
        );
        $stopped = $runner->run('while True: pass', null, 2, PyodideRunner::DEFAULT_MEMORY_MB);

        // Assert
        $this->assertSame(['ok' => true, 'result' => "2\n"], $plain);
        $this->assertSame(['ok' => true, 'result' => "15\n"], $pandas);
        $this->assertSame(['ok' => false, 'error' => 'Stopped: took longer than 2 seconds.'], $stopped);
    }
}

/** Answers every call at once with a canned worker result, or never answers at all. */
final class FakeCodeExecQueue extends QueueManager
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $tasks = [];

    /** @param array{ok: bool, result?: string, error?: string}|null $answer null: no worker ever claims it */
    public function __construct(private readonly ?array $answer)
    {
    }

    public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
    {
        $this->tasks[] = [$taskType, $data];
        if ($this->answer !== null) {
            $runId = (int) $data['run_id'];
            Exec::claim($runId);
            if (!empty($this->answer['ok'])) {
                Exec::finish($runId, (string) ($this->answer['result'] ?? ''));
            } else {
                Exec::fail($runId, (string) ($this->answer['error'] ?? ''));
            }
        }

        return count($this->tasks);
    }
}
