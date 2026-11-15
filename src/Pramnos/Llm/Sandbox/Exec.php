<?php

declare(strict_types=1);

namespace Pramnos\Llm\Sandbox;

use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;

/**
 * One sandboxed `code_exec` call's row in `pramnos.llm_code_exec_runs`, from the moment
 * {@see Tools\CodeExecTool} asks for it until a worker of the `code_exec` pool finishes it.
 *
 * Deliberately **not** `Pramnos\Llm\Chat\ParallelTools`'s own table: that one is claimed and run by
 * *any* queue worker — correct for a call that only reads and depends on nothing but its
 * arguments. A `code_exec` call needs a worker with Node.js, the `pyodide` package and the cgroup
 * tooling {@see PyodideRunner} shells out to, which most workers do not have — so its task type is
 * never in {@see \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS}, and claims nothing unless the
 * application registers it explicitly, on the worker(s) that can run it:
 * `'queue' => ['handlers' => ['code_exec' => \Pramnos\Llm\Sandbox\CodeExecTask::class]]]`.
 */
final class Exec
{
    public const TABLE = 'pramnos.llm_code_exec_runs';

    /** Whether this installation can run one: its table exists. */
    public static function available(): bool
    {
        try {
            return Factory::getDatabase()->schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** A new row, pending a worker. */
    public static function create(int $userId, string $code, ?string $input): int
    {
        self::rows()->insert([
            'user_id'    => $userId,
            'code'       => $code,
            'input'      => $input,
            'status'     => 'pending',
            'created_at' => \Pramnos\Database\Instant::utc(time()),
        ]);

        return (int) Factory::getDatabase()->getInsertId();
    }

    /**
     * One row, for the person it was made for — null for somebody else's, or one gone already.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $runId, int $userId): ?array
    {
        $row = self::rows()->where('run_id', $runId)->where('user_id', $userId)->first();

        return $row && (int) $row->numRows > 0 ? (array) $row->fields : null;
    }

    /**
     * Take a pending call for `$owner`. Two workers may race for the same one: the update only
     * takes a row still pending, and the owner read back says who won — the same shape as
     * {@see \Pramnos\Llm\Chat\ParallelTools::claim()}, one row at a time rather than a batch. A
     * row already running — somebody else's claim, or this same one asked for twice — is not
     * `status = 'running'` alone: without `owner` in the check, a second claim of a row this
     * process itself just took would read back `true` as well.
     */
    public static function claim(int $runId, string $owner = 'worker'): bool
    {
        self::rows()->where('run_id', $runId)->where('status', 'pending')->update(['status' => 'running', 'owner' => $owner]);
        $row = self::rows()->select(['status', 'owner'])->where('run_id', $runId)->first();

        return $row && (int) $row->numRows > 0 && (string) $row->fields['status'] === 'running' && (string) $row->fields['owner'] === $owner;
    }

    /** The run finished: its stdout kept, nothing to say went wrong. */
    public static function finish(int $runId, string $result): void
    {
        self::rows()->where('run_id', $runId)->update([
            'status' => 'done', 'result' => $result, 'finished_at' => \Pramnos\Database\Instant::utc(time()),
        ]);
    }

    /** The run failed: why, for the model to read back rather than the stdout it never had. */
    public static function fail(int $runId, string $why): void
    {
        self::rows()->where('run_id', $runId)->update([
            'status' => 'error', 'error' => $why, 'finished_at' => \Pramnos\Database\Instant::utc(time()),
        ]);
    }

    /** Forget a finished row: kept only while its call is answered, like `llm_tool_runs`. */
    public static function forget(int $runId): void
    {
        try {
            self::rows()->where('run_id', $runId)->delete();
        } catch (\Throwable) {
            // Already gone, or the table is not there: nothing left to clean up either way.
        }
    }

    private static function rows(): QueryBuilder
    {
        return Factory::getDatabase()->queryBuilder()->table(self::TABLE);
    }
}
