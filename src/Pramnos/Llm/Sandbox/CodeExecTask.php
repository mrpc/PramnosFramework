<?php

declare(strict_types=1);

namespace Pramnos\Llm\Sandbox;

use Pramnos\Llm\Analyst\Tools\CodeExecTool;
use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * One `code_exec` call, run by a worker that has Node.js, the `pyodide` package and the cgroup
 * tooling {@see PyodideRunner} needs — never claimed by an ordinary worker, since this type is
 * deliberately not in {@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS}. An application points a
 * worker meant for this at it:
 *
 * ```php
 * 'queue' => ['handlers' => ['code_exec' => \Pramnos\Llm\Sandbox\CodeExecTask::class]]
 * ```
 *
 * A call the answer's own timeout gave up on first, or another worker already took, is nothing to
 * do — the same claim {@see Exec::claim()} makes atomic.
 */
class CodeExecTask extends AbstractTask
{
    public string $name = CodeExecTool::TASK;

    public function execute(QueueItem $queueItem): mixed
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);
        $runId   = (int) ($payload['run_id'] ?? 0);

        if ($runId <= 0 || !Exec::claim($runId, 'worker-' . getmypid())) {
            return true;
        }

        $config  = \Pramnos\Llm\Llm::config('analyst', []);
        $seconds = max(1, (int) (is_array($config) ? ($config['code_exec_seconds'] ?? PyodideRunner::DEFAULT_TIMEOUT_SECONDS) : PyodideRunner::DEFAULT_TIMEOUT_SECONDS));
        $memory  = max(64, (int) (is_array($config) ? ($config['code_exec_memory_mb'] ?? PyodideRunner::DEFAULT_MEMORY_MB) : PyodideRunner::DEFAULT_MEMORY_MB));

        $row = Exec::find($runId, (int) ($payload['user_id'] ?? 0));
        if ($row === null) {
            return true;
        }

        $answer = PyodideRunner::default()->run((string) $row['code'], $row['input'] !== null ? (string) $row['input'] : null, $seconds, $memory);

        if (!empty($answer['ok'])) {
            Exec::finish($runId, (string) ($answer['result'] ?? ''));
        } else {
            Exec::fail($runId, (string) ($answer['error'] ?? 'The sandbox did not say why.'));
        }

        return true;
    }

    public function getDescription(QueueItem $queueItem): string
    {
        return 'Run sandboxed code, call ' . (int) (((array) json_decode((string) $queueItem->payload, true))['run_id'] ?? 0);
    }
}
