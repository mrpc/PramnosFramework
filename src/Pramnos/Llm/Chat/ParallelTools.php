<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;
use Pramnos\Llm\Tools;

/**
 * The tool calls of one round, run at once: by the answer itself and by the queue workers of the
 * `llm_tool` pool, whichever claims each call first — {@see \Pramnos\Llm\Client::inParallel()}.
 *
 * Every call becomes a row of `pramnos.llm_tool_runs` and a queued `llm_tool` task. The answer
 * then works through the rows itself, claiming each one still pending, while a worker that takes
 * a task claims its row and runs it there. So the answer never waits for a worker that is not
 * there — with none running, the calls run one after another as they did — and a slow call (a
 * long query, a fetch from another service) runs beside the others instead of before them.
 *
 * A worker has no closures: it builds the tools again from the descriptor — the assistant of the
 * space, the person, the conversation, `extra` — as the answer built them, and runs the one named,
 * as the answer's author. Only tools marked `'parallel' => true` go this way, which says they
 * read and change nothing, and depend on nothing but their arguments and that person.
 *
 * Results come back in the order the model asked for them; the round's rows are deleted once read.
 */
class ParallelTools
{
    /** The queue task type. */
    public const TASK = 'llm_tool';

    /** The table. */
    public const TABLE = 'pramnos.llm_tool_runs';

    /** Seconds between looks at calls another process is running. */
    public const LOOK_EVERY = 0.05;

    /** Said for a call no process finished within the timeout. */
    public const TOO_LONG = 'Error: the tool did not finish in time.';

    /**
     * @param array<string, mixed> $descriptor How a worker builds the tools again: `space`, `userid`,
     *                                         `organization_id`, `conversation_id`, `scheduled`, `extra`
     * @param int                  $timeout    Seconds the answer waits for a call another process took
     */
    public function __construct(
        private readonly array $descriptor,
        private readonly int $timeout = 60,
        private readonly ?\Pramnos\Queue\QueueManager $queue = null,
    ) {
    }

    /**
     * The parallel runner for a connection's answer, when the application turned it on
     * (`'llm' => ['parallel_tools' => true]`, or `['timeout' => seconds]`) and the table is there.
     *
     * @param array<string, mixed> $descriptor
     */
    public static function forAnswer(array $descriptor): ?self
    {
        $config = \Pramnos\Llm\Llm::config('parallel_tools', false);

        if ($config === false || $config === null || json_encode($descriptor) === false
            || !\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable(self::TABLE)) {
            return null;
        }

        return new static($descriptor, is_array($config) ? max(1, (int) ($config['timeout'] ?? 60)) : 60);
    }

    /**
     * Run these calls at once; their results in the same order.
     *
     * @param list<array{name: string, arguments: array<string, mixed>}> $calls
     * @param callable(string, array<string, mixed>): string             $runHere How the answer runs one itself
     * @return list<string>
     */
    public function __invoke(array $calls, callable $runHere): array
    {
        $batch = bin2hex(random_bytes(16));
        $me    = 'answer-' . bin2hex(random_bytes(6));
        $stamp = CallLog::instant(time());
        $ids   = [];

        try {
            foreach ($calls as $position => $call) {
                $this->table()->insert([
                    'batch'      => $batch,
                    'position'   => $position,
                    'tool'       => mb_substr($call['name'], 0, 64),
                    'arguments'  => (string) json_encode($call['arguments'], JSON_UNESCAPED_UNICODE),
                    'status'     => 'pending',
                    'created_at' => $stamp,
                ]);
                $ids[$position] = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
            }
            foreach ($ids as $runId) {
                $this->queue()->addTask(self::TASK, ['run_id' => $runId, 'descriptor' => $this->descriptor], 1, 1);
            }
        } catch (\Throwable $why) {
            // Nowhere to share the work: the answer runs every call itself, as without this.
            \Pramnos\Logs\Logger::log('llm parallel tools: ' . $why->getMessage());
            $this->forget($batch);

            return array_map(static fn (array $call): string => $runHere($call['name'], $call['arguments']), $calls);
        }

        $results = [];
        foreach ($ids as $position => $runId) {
            if (self::claim($runId, $me)) {
                $results[$position] = $runHere($calls[$position]['name'], $calls[$position]['arguments']);
                $this->table()->where('run_id', $runId)->update(['status' => 'done']);
            }
        }

        // What the workers took: read back as each finishes, up to the timeout.
        $deadline = microtime(true) + $this->timeout;
        while (count($results) < count($calls)) {
            $done = $this->table()->where('batch', $batch)->where('status', 'done')->where('owner', '!=', $me)->get();
            while ($done && $done->fetch()) {
                $results[(int) $done->fields['position']] = (string) $done->fields['result'];
            }
            if (count($results) >= count($calls)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                foreach (array_keys($calls) as $position) {
                    $results[$position] ??= self::TOO_LONG;
                }

                break;
            }
            usleep((int) (self::LOOK_EVERY * 1_000_000));
        }

        $this->forget($batch);
        ksort($results);

        return array_values($results);
    }

    /**
     * The worker's half: claim the call, build the tools again as the answer did, run the one
     * named as the answer's author, and write its result. A call the answer, or another worker,
     * already took is left alone.
     *
     * @param array<string, mixed> $job As {@see __invoke()} queued it
     */
    public static function runQueued(array $job): bool
    {
        $runId = (int) ($job['run_id'] ?? 0);

        if ($runId <= 0 || !self::claim($runId, 'worker-' . getmypid())) {
            return false;
        }

        $row = self::rows()->where('run_id', $runId)->first();
        if (!$row || (int) $row->numRows === 0) {
            return false;
        }

        $d         = (array) ($job['descriptor'] ?? []);
        $org       = $d['organization_id'] ?? null;
        $space     = (string) ($d['space'] ?? Scope::CHAT);
        $scope     = new Scope((int) ($d['userid'] ?? 0), $org === null ? null : (int) $org, $space);
        $scheduled = (bool) ($d['scheduled'] ?? false);
        $chat      = (new Conversations(Chat::assistant($space)))->as($scope, $scheduled);
        $name      = (string) $row->fields['tool'];
        $arguments = json_decode((string) $row->fields['arguments'], true);
        $arguments = is_array($arguments) ? $arguments : [];

        try {
            $result = $chat->assistant()->acting($scope, static function () use ($chat, $scope, $d, $scheduled, $name, $arguments): string {
                $conversationId = isset($d['conversation_id']) ? (int) $d['conversation_id'] : null;
                $tools = $chat->toolsFor(new Turn($scope, $conversationId, $scheduled, [], [], (array) ($d['extra'] ?? []), $chat));
                $tool  = $tools[$name] ?? null;

                // Only what the answer would have sent here: a tool not marked parallel stays with it.
                if ($tool === null || empty($tool['parallel']) || !is_callable($tool['run'] ?? null)) {
                    return 'Error: there is no tool called ' . $name . '.';
                }

                return Tools::text(($tool['run'])($arguments));
            });
        } catch (\Throwable $why) {
            $result = 'Error: ' . $why->getMessage();
        }

        self::rows()->where('run_id', $runId)->update(['status' => 'done', 'result' => (string) $result]);

        return true;
    }

    /**
     * Take a pending call for `$owner`. Two processes may race for it: the update only takes a
     * row still pending, and the owner read back says who won — which works the same on every
     * database, without counting affected rows.
     */
    private static function claim(int $runId, string $owner): bool
    {
        self::rows()->where('run_id', $runId)->where('status', 'pending')->update(['status' => 'running', 'owner' => $owner]);
        $row = self::rows()->select(['owner', 'status'])->where('run_id', $runId)->first();

        // Running, and ours: a call this same process already finished is not taken again.
        return $row && (int) $row->numRows > 0 && (string) $row->fields['status'] === 'running' && (string) $row->fields['owner'] === $owner;
    }

    /** Delete a round's rows once read: a row is kept only while its round is answered. */
    private function forget(string $batch): void
    {
        try {
            $this->table()->where('batch', $batch)->delete();
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm parallel tools: ' . $why->getMessage());
        }
    }

    /** The queue the calls go to. */
    protected function queue(): \Pramnos\Queue\QueueManager
    {
        return $this->queue ?? new \Pramnos\Queue\QueueManager(
            \Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return self::rows();
    }

    private static function rows(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(self::TABLE);
    }
}
