<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * Questions answered in the queue worker rather than in the request — `chat_asks`.
 *
 * An answer with tools can take a minute; a request that waits for it is one a proxy cuts off.
 * {@see start()} writes a row and queues a `chat_ask` task; the worker answers it as its author
 * ({@see run()}, through `Assistant::acting()`) and marks the row answered or failed, which the
 * screen polls with {@see find()}.
 *
 * A question still waiting can be stopped ({@see cancel()}): the row is marked `cancelled`, and
 * the worker — which asks before every round and every tool — stops there, keeping nothing.
 *
 * What the caller put in `$extra` travels with the task — where the answer should also go, say —
 * and {@see onAnswered()} is told every answer with it.
 */
class Asks
{
    /** The queue task type. */
    public const TASK = 'chat_ask';

    /** How often, at most, the answer's text so far is written for the screen: seconds. */
    public const PARTIAL_EVERY = 0.5;

    /** @var list<callable(array<string, mixed>, array<string, mixed>): void> */
    private static array $listeners = [];

    public function __construct(private readonly Conversations $chat, private readonly ?\Pramnos\Queue\QueueManager $queue = null)
    {
    }

    /**
     * Queue a question. Returns the ask's id, for the screen to follow.
     *
     * @param array{name?: string, text?: string}|null $attachment
     * @param array<string, mixed>                     $extra
     */
    public function start(Scope $scope, ?int $conversationId, string $question, ?array $attachment = null, array $extra = []): int
    {
        $stamp = CallLog::instant(time());
        $this->table()->insert($scope->columns() + [
            'conversation_id' => $conversationId,
            'status'          => 'waiting',
            'created_at'      => $stamp,
            'updated_at'      => $stamp,
        ]);
        $askId = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();

        // Once: an answer costs, and a failed one says why for the person to ask again.
        $this->queue()->addTask(self::TASK, [
            'ask_id'          => $askId,
            'organization_id' => $scope->organizationId,
            'userid'          => $scope->userId,
            'space'           => $scope->space,
            'conversation_id' => $conversationId,
            'question'        => $question,
            'attachment'      => $attachment,
            'extra'           => $extra,
        ], 5, 1);

        return $askId;
    }

    /**
     * The worker's half: answer it as its author, and mark the row.
     *
     * @param array<string, mixed> $job As {@see start()} queued it
     * @return array{ok: bool, conversation_id?: int, why?: string, says?: string}
     */
    public function run(array $job): array
    {
        $org   = $job['organization_id'] ?? null;
        $scope = new Scope((int) ($job['userid'] ?? 0), $org === null ? null : (int) $org, (string) ($job['space'] ?? Scope::CHAT));
        $askId = (int) ($job['ask_id'] ?? 0);
        // Stopped by the person: before the worker reached it, nothing is asked at all.
        $stopped = fn (): bool => ($this->find($scope, $askId)['status'] ?? '') === 'cancelled';
        if ($stopped()) {
            return ['ok' => false, 'why' => 'cancelled', 'says' => \Pramnos\Llm\Client::STOPPED];
        }
        // The answer's text so far, for the screen following it — not more often than PARTIAL_EVERY.
        // Without the column (its migration not run yet) nothing is written, and the answer still comes whole.
        $streamed = false;
        $last     = 0.0;
        $partial  = function (string $text) use ($scope, $job, &$streamed, &$last): void {
            if (microtime(true) - $last >= self::PARTIAL_EVERY) {
                $scope->apply($this->table())->where('ask_id', (int) ($job['ask_id'] ?? 0))->update(['partial' => $text]);
                [$streamed, $last] = [true, microtime(true)];
            }
        };
        $chat  = \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('chat_asks', 'partial')
            ? $this->chat->as($scope)->streaming($partial) : $this->chat->as($scope);
        $chat  = $chat->stoppable($stopped);
        // An ask from the conversations: the people there are told the analyst is on it.
        if (isset($job['extra']['conversations'])) {
            try {
                \Pramnos\Conversations\Ask::thinking($job);
            } catch (\Throwable $why) {
                \Pramnos\Logs\Logger::log('llm ask thinking: ' . $why->getMessage());
            }
        }

        try {
            $answer = $this->chat->assistant()->acting($scope, static fn (): array => $chat->ask(
                !empty($job['conversation_id']) ? (int) $job['conversation_id'] : null,
                (string) ($job['question'] ?? ''),
                is_array($job['attachment'] ?? null) ? $job['attachment'] : null,
                is_array($job['extra'] ?? null) ? $job['extra'] : []
            ));
        } catch (\Throwable $exception) {
            $answer = ['ok' => false, 'why' => 'provider', 'says' => 'The AI stopped: ' . $exception->getMessage()];
        }

        $outcome = $answer['ok']
            ? ['status' => 'answered', 'conversation_id' => (int) $answer['conversation_id'], 'why' => null, 'says' => null]
            : ['status' => ($answer['why'] ?? '') === 'cancelled' ? 'cancelled' : 'failed', 'why' => mb_substr((string) ($answer['why'] ?? '') ?: 'provider', 0, 32),
                'says' => (string) ($answer['says'] ?? '') ?: 'The AI could not answer.'];

        $scope->apply($this->table())->where('ask_id', (int) ($job['ask_id'] ?? 0))
            ->update($outcome + ($streamed ? ['partial' => null] : []) + ['updated_at' => CallLog::instant(time())]);

        // An ask from the conversations is posted back from here, not by a listener a provider
        // registers: the worker answering it may be an application that boots no providers.
        $listeners = isset($job['extra']['conversations']) ? [[\Pramnos\Conversations\Ask::class, 'answered'], ...self::$listeners] : self::$listeners;
        foreach ($listeners as $listener) {
            try {
                $listener($job, $answer);
            } catch (\Throwable $why) {
                \Pramnos\Logs\Logger::log('llm ask listener: ' . $why->getMessage());
            }
        }

        return $answer['ok']
            ? ['ok' => true, 'conversation_id' => $outcome['conversation_id']]
            : ['ok' => false, 'why' => $outcome['why'], 'says' => $outcome['says']];
    }

    /**
     * One of this person's asks: its status, the answer's text so far while it is written, and the
     * conversation once answered.
     *
     * @return array{ask_id: int, status: string, conversation_id: int|null, why: string|null, says: string|null, partial: string|null}|null
     */
    public function find(Scope $scope, int $askId): ?array
    {
        $result = $scope->apply($this->table())->where('ask_id', $askId)->first();

        if (!$result || (int) $result->numRows === 0) {
            return null;
        }

        $f = $result->fields;

        return [
            'ask_id'          => (int) $f['ask_id'],
            'status'          => (string) $f['status'],
            'conversation_id' => $f['conversation_id'] === null ? null : (int) $f['conversation_id'],
            'why'             => $f['why'] === null ? null : (string) $f['why'],
            'says'            => $f['says'] === null ? null : (string) $f['says'],
            'partial'         => ($f['partial'] ?? null) === null ? null : (string) $f['partial'],
        ];
    }

    /**
     * Stop a question of this person's that is still being answered. The worker stops before its
     * next round or tool; the call already under way is paid for. False when there is no such
     * question, or it is no longer waiting.
     */
    public function cancel(Scope $scope, int $askId): bool
    {
        if (($this->find($scope, $askId)['status'] ?? '') !== 'waiting') {
            return false;
        }

        $scope->apply($this->table())->where('ask_id', $askId)->where('status', 'waiting')
            ->update(['status' => 'cancelled', 'updated_at' => CallLog::instant(time())]);

        return true;
    }

    /**
     * Be told each answered or failed ask, with the job it came from — its `extra` included.
     *
     * @param callable(array<string, mixed>, array<string, mixed>): void $listener
     */
    public static function onAnswered(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** Forget the listeners — for tests. */
    public static function reset(): void
    {
        self::$listeners = [];
    }

    /** The queue tasks go to. */
    protected function queue(): \Pramnos\Queue\QueueManager
    {
        return $this->queue ?? new \Pramnos\Queue\QueueManager(
            \Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_asks');
    }
}
