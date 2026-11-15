<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Llm\Sandbox\Exec;
use Pramnos\Mcp\McpToolInterface;
use Pramnos\Queue\QueueManager;

/**
 * Python, run in an isolated sandbox ({@see \Pramnos\Llm\Sandbox\PyodideRunner}) — the harness
 * roadmap's `code_exec`, for analysis no provider's own code-execution tool covers, since the
 * assistant is multi-provider and a provider's own sandbox is not.
 *
 * **Never run here, by design.** This tool only writes a row to `pramnos.llm_code_exec_runs`,
 * queues a `code_exec` task, and polls the row until a worker finishes it or `$waitSeconds` runs
 * out — the request answering the question never itself runs a model-written script, which is the
 * whole point of keeping the sandbox's own runtime (Node.js, the cgroup tooling) off every other
 * server. Nothing answers unless an application has pointed a worker at `code_exec` explicitly
 * (see {@see \Pramnos\Llm\Sandbox\CodeExecTask}) — refused outright otherwise, past the wait.
 *
 * **A prototype.** See docs/Pramnos_LLM_Analyst_Guide.md, "code_exec (prototype)", for what this
 * still does not do before it is production-grade: network isolation, pre-bundled packages for an
 * offline worker, and a `systemd-run` fallback for a host it cannot reach.
 */
final class CodeExecTool implements McpToolInterface
{
    /** The queue task type. */
    public const TASK = 'code_exec';

    /** Seconds between looks at a run a worker may still be claiming. */
    public const LOOK_EVERY = 0.2;

    /** Seconds the answer waits for a worker, by default — separate from the sandbox's own `code_exec_seconds`. */
    public const DEFAULT_WAIT = 25;

    public function __construct(
        private readonly int $userId,
        private readonly int $waitSeconds = self::DEFAULT_WAIT,
        private readonly ?QueueManager $queue = null,
    ) {
    }

    public function name(): string
    {
        return self::TASK;
    }

    public function description(): string
    {
        return 'Python, run in an isolated sandbox — no network, bounded memory/CPU/time — for analysis plain tools cannot: '
            . 'comparing numbers across a file, pandas-style grouping or a pivot. csv: a CSV\'s text, when the question gave '
            . 'one, read from /tmp/input.csv by the code. print() whatever should come back — nothing else of the run is '
            . 'kept. Refused if no sandbox worker claims it in time.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['code'], 'properties' => [
            'code' => ['type' => 'string', 'description' => 'Python. print() what should come back.'],
            'csv'  => ['type' => 'string', 'description' => 'A CSV\'s text, read from /tmp/input.csv by the code.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        if ($this->userId <= 0) {
            return 'Error: a sandbox run is made for the person asking, and there is none.';
        }
        if (!Exec::available()) {
            return 'Error: there is no sandbox here.';
        }
        $code = (string) ($input['code'] ?? '');
        if (trim($code) === '') {
            return 'Error: nothing to run.';
        }

        $runId = Exec::create($this->userId, $code, isset($input['csv']) ? (string) $input['csv'] : null);

        try {
            $this->queue()->addTask(self::TASK, ['run_id' => $runId, 'user_id' => $this->userId], 1, 1);
        } catch (\Throwable $why) {
            Exec::forget($runId);

            return 'Error: could not reach the sandbox queue (' . $why->getMessage() . ').';
        }

        $deadline = microtime(true) + max(1, $this->waitSeconds);
        while (true) {
            $row = Exec::find($runId, $this->userId);
            if ($row === null) {
                return 'Error: the run is gone.';
            }
            if ((string) $row['status'] === 'done') {
                Exec::forget($runId);

                return (string) $row['result'];
            }
            if ((string) $row['status'] === 'error') {
                $why = (string) $row['error'];
                Exec::forget($runId);

                return 'Error: ' . $why;
            }
            if (microtime(true) >= $deadline) {
                Exec::forget($runId);

                return 'Error: no sandbox worker finished this in time.';
            }
            usleep((int) (self::LOOK_EVERY * 1_000_000));
        }
    }

    private function queue(): QueueManager
    {
        return $this->queue ?? new QueueManager(
            QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }
}
