<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * Questions asked again on a schedule — `ai_routines`.
 *
 * "Every morning give me the SEO report" is a routine: a question, a cadence, a time on the
 * person's clock. Each run is asked as its owner, into the routine's own conversation, with
 * `scheduled` set so the assistant leaves out what only a person asking may use — and the owner
 * is told, by the notification `'llm' => ['chat' => ['routine_notification' => …]]` names
 * ({@see RoutineReady} by default, constructed with title, ok, why and link).
 *
 * `llm:routines`, scheduled every minute by the framework, runs what is due — at most
 * {@see BATCH} a pass, so one slow provider cannot hold the rest. A run moves its next time on
 * whether it succeeded or not, so a failure is reported once rather than every minute.
 */
class Routines
{
    public const CADENCES = ['daily', 'weekly', 'monthly', 'once', 'monthly_weekday'];

    /** A question asked again, into its owner's conversation — what every routine was at first. */
    public const ASK = 'ask';

    /** A reminder in its owner's words: no model is asked. */
    public const REMINDER = 'reminder';

    /** What a task may be. */
    public const KINDS = [self::ASK, self::REMINDER];

    /** The queue task a claimed run is handed to — {@see TaskRunTask}. */
    public const TASK = 'ai_task_run';

    /** Due routines run per pass. */
    public const BATCH = 20;

    /** How long a claimed run holds its task, in seconds: a worker that died frees it after this. */
    public const LEASE = 900;

    private ?TaskPolicy $policy = null;

    public function __construct(private readonly Conversations $chat, private readonly ?\Pramnos\Queue\QueueManager $queue = null)
    {
    }

    /** The rules around tasks — {@see TaskPolicy}, or the application's. */
    public function policy(): TaskPolicy
    {
        return $this->policy ??= TaskPolicy::for($this->chat->assistant());
    }

    /**
     * Ready-made routines an application offers, by key: `title`, `prompt`, `cadence`, `at`, `day`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat['routine_templates'] ?? null) ? $chat['routine_templates'] : [];
    }

    /**
     * This person's routines, each with the last answer it produced.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Scope $scope): array
    {
        $result = $scope->apply($this->table())->orderBy('routine_id')->get();
        $list   = [];

        while (($row = $result->fetch()) !== null) {
            $list[] = self::shape($row);
        }

        foreach ($list as $i => $routine) {
            $list[$i]['latest'] = $this->latest($routine['conversation_id']);
        }

        return $list;
    }

    /**
     * Schedule one. Returns the routine, or a sentence saying why not.
     *
     * @param array<string, mixed> $wanted title, prompt, cadence, at, day, timezone — or template
     * @return array<string, mixed>|string
     */
    public function create(Scope $scope, array $wanted): array|string
    {
        $kind = (string) ($wanted['kind'] ?? self::ASK);
        if (!in_array($kind, self::KINDS, true)) {
            return 'A task is a question asked again (ask) or a reminder (reminder).';
        }

        if (($why = $this->policy()->refusal($scope, $kind, true)) !== null) {
            return $why;
        }

        $template = (string) ($wanted['template'] ?? '');
        $base     = self::templates()[$template] ?? null;
        $wanted  += $base ?? [];

        $title   = mb_substr(trim((string) ($wanted['title'] ?? '')), 0, 120);
        $prompt  = mb_substr(trim((string) ($wanted['prompt'] ?? '')), 0, Conversations::CHARS);
        $cadence = (string) ($wanted['cadence'] ?? '');

        if ($title === '' || $prompt === '') {
            return $kind === self::REMINDER ? 'A reminder needs a title and what to say.' : 'A scheduled task needs a title and what to ask.';
        }

        if (!in_array($cadence, self::CADENCES, true)) {
            return 'How often: once, daily, weekly, monthly, or monthly_weekday (the 2nd Tuesday, the last Friday).';
        }

        $schedule = self::schedule($cadence, (string) ($wanted['at'] ?? '08:00'), $wanted['day'] ?? null, (string) ($wanted['timezone'] ?? ''), $wanted);

        if (is_string($schedule)) {
            return $schedule;
        }

        $next = self::nextFor($schedule + ['cadence' => $cadence], time());
        if ($next === null) {
            return 'That time has passed: give one still to come.';
        }

        $stamp = CallLog::instant(time());
        $this->table()->insert($scope->columns() + [
            'title'       => $title,
            'prompt'      => $prompt,
            'template'    => $base === null ? null : $template,
            'kind'        => $kind,
            'owner'       => 'user',
            'cadence'     => $cadence,
            'at'          => $schedule['at'],
            'day'         => $schedule['day'],
            'nth'         => $schedule['nth'],
            'run_at'      => $schedule['run_at'] === null ? null : CallLog::instant($schedule['run_at']),
            'timezone'    => $schedule['timezone'],
            'notify'      => 'always',
            'conversation_id' => isset($wanted['conversation_id']) ? (int) $wanted['conversation_id'] : null,
            'is_active'   => true,
            'next_run_at' => CallLog::instant($next),
            'created_at'  => $stamp,
            'updated_at'  => $stamp,
        ]);

        return $this->find($scope, (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId()) ?? [];
    }

    /**
     * Pause or resume one of this person's — resumed from now, not for every day it was paused.
     *
     * Resuming one adds an active routine, so it is asked as adding one; a refusal leaves it
     * paused and answers false.
     */
    public function setActive(Scope $scope, int $routineId, bool $active): bool
    {
        $found = $this->find($scope, $routineId);

        if ($found === null || ($active && !$found['active'] && $this->policy()->refusal($scope, $found['kind'], true) !== null)) {
            return false;
        }

        $next = self::nextFor($found, time());
        if ($active && $next === null) {
            // A one-off whose moment has passed has nothing to resume to.
            return false;
        }

        $this->scoped($scope, $routineId)->update([
            'is_active'   => $active,
            // Resumed, it starts counting failures again.
            'failures'    => 0,
            'next_run_at' => $next === null ? null : CallLog::instant($next),
            'updated_at'  => CallLog::instant(time()),
        ]);

        return true;
    }

    /** Delete one of this person's. Its conversation stays, like any other. */
    public function delete(Scope $scope, int $routineId): bool
    {
        if ($this->find($scope, $routineId) === null) {
            return false;
        }

        $this->scoped($scope, $routineId)->delete();

        return true;
    }

    /** @return array<string, mixed>|null */
    public function find(Scope $scope, int $routineId): ?array
    {
        $result = $this->scoped($scope, $routineId)->first();

        return $result && (int) $result->numRows > 0 ? self::shape($result->fields) : null;
    }

    /**
     * Run everything due, each as its owner — or, with `'task_queue' => true`, hand each to the
     * queue and let a worker run it.
     *
     * Each is claimed first: its next run is moved on in the same statement that checks it is
     * still the one read, so a second tick, or a second host, finds nothing to claim and nothing
     * runs twice.
     *
     * @return int How many were claimed
     */
    public function runDue(int $now): int
    {
        $result = $this->table()->where('is_active', true)->where('next_run_at', '<=', CallLog::instant($now))
            ->where(static function ($q) use ($now): void {
                $q->whereNull('locked_until')->orWhere('locked_until', '<', CallLog::instant($now));
            })
            ->orderBy('next_run_at')->limit(self::BATCH)->get();
        $due = [];

        while (($row = $result->fetch()) !== null) {
            $due[] = $row;
        }

        $claimed = 0;
        foreach ($due as $row) {
            $task = self::shape($row);
            if (!$this->claim($task, (string) $row['next_run_at'], $now)) {
                continue;
            }
            $claimed++;

            if ($this->queued()) {
                $this->queue()->addTask(self::TASK, [
                    'routine_id' => $task['routine_id'],
                    'space'      => $task['space'],
                    'due_at'     => $task['next_run_at'],
                ], 5, 1);
            } else {
                $this->run($task, $now);
            }
        }

        return $claimed;
    }

    /**
     * Take a due task for one run: move its next run on and hold it, only if it is still due as it
     * was read. False when another tick or host took it first.
     *
     * @param array<string, mixed> $task
     */
    private function claim(array $task, string $readDue, int $now): bool
    {
        $next = $task['cadence'] === 'once' ? null : self::nextFor($task, $now);

        $result = $this->table()->where('routine_id', $task['routine_id'])->where('next_run_at', $readDue)
            ->update([
                'next_run_at'  => $next === null ? null : CallLog::instant($next),
                'locked_until' => CallLog::instant($now + self::LEASE),
                'updated_at'   => CallLog::instant(time()),
            ]);

        return is_object($result) && (int) $result->getAffectedRows() === 1;
    }

    /** Whether runs go to the queue: `'llm' => ['chat' => ['task_queue' => true]]`. */
    private function queued(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['task_queue']);
    }

    /** The queue runs are handed to. */
    protected function queue(): \Pramnos\Queue\QueueManager
    {
        return $this->queue ?? new \Pramnos\Queue\QueueManager(
            \Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }

    /**
     * Run one now, whatever its schedule, as its owner — and record it, tell its audience, count
     * its failures, and move its next run on.
     *
     * A task whose maker may no longer run it ({@see TaskPolicy::stillAllowed()}) is paused and its
     * maker told, rather than run on data they may no longer see. A task that fails
     * {@see TaskPolicy::maxFailures()} times in a row is paused, and its owner told.
     *
     * @param array<string, mixed> $routine As {@see find()} answers
     * @return array{ok: bool, says: string, conversation_id: int|null}
     */
    public function run(array $routine, int $now): array
    {
        $policy    = $this->policy();
        $scope     = $policy->scope($routine);
        $timezone  = $policy->timezone($routine);
        $started   = time();

        if (!$policy->stillAllowed($routine)) {
            $this->pause($routine, 'Its maker may no longer run it.');
            $this->tell($scope->userId, (string) $routine['title'], false, 'It was paused: you may no longer run it here.', $routine['conversation_id']);

            return ['ok' => false, 'says' => 'Paused: its maker may no longer run it.', 'conversation_id' => $routine['conversation_id']];
        }

        if ($routine['kind'] === self::REMINDER) {
            $answer = ['ok' => true, 'says' => '', 'conversation_id' => $routine['conversation_id'], 'content' => (string) $routine['prompt']];
        } else {
            $assistant = $this->chat->assistant();
            $chat      = $this->chat->as($scope, true);
            // The day as the owner reads it, not as UTC has it.
            $day       = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone))->format('l j F Y');
            $question  = 'Scheduled task "' . $routine['title'] . '", run on ' . $day . ": \n\n" . $routine['prompt'];

            $answer = ($why = $policy->refusal($scope, (string) $routine['kind'])) !== null
                ? ['ok' => false, 'says' => $why, 'why' => 'limit', 'conversation_id' => null]
                : $assistant->acting($scope, static function () use ($chat, $routine, $question): array {
                    $answer = $chat->ask($routine['conversation_id'], $question);

                    // A conversation its owner deleted is started again, not an error for ever.
                    return !$answer['ok'] && ($answer['why'] ?? '') === 'missing' ? $chat->ask(null, $question) : $answer;
                });
        }

        $ok             = (bool) $answer['ok'];
        $conversationId = $answer['conversation_id'] ?? $routine['conversation_id'];
        $failures       = $ok ? 0 : (int) $routine['failures'] + 1;
        $once           = $routine['cadence'] === 'once';
        $pause          = !$ok && $failures >= $policy->maxFailures();
        $next           = $once ? null : self::nextFor($routine, $now);

        $this->table()->where('routine_id', (int) $routine['routine_id'])->update([
            'last_run_at'     => CallLog::instant($now),
            'last_error'      => $ok ? null : mb_substr((string) $answer['says'], 0, 500),
            'conversation_id' => $conversationId,
            'failures'        => $failures,
            'locked_until'    => null,
            // A one-off is done once it ran; a task that keeps failing waits for its owner.
            'is_active'       => !$once && !$pause && $routine['active'],
            'next_run_at'     => $next === null ? null : CallLog::instant($next),
            'updated_at'      => CallLog::instant(time()),
        ]);

        $run = $this->record($routine, $answer, $conversationId, $started);

        if ($routine['kind'] === self::REMINDER) {
            $this->remind($policy->audience($routine), (string) $routine['prompt'], $policy->link($routine, $run));
        } else {
            $why = $ok ? '' : (string) $answer['says'] . ($pause ? ' It was paused after ' . $failures . ' failures in a row; resume it when this is fixed.' : '');
            foreach ($policy->audience($routine) as $userId) {
                $this->tell($userId, (string) $routine['title'], $ok, $why, $conversationId);
            }
        }

        try {
            $policy->onRan($routine, $run);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('llm task onRan: ' . $e->getMessage());
        }

        return ['ok' => $ok, 'says' => (string) $answer['says'], 'conversation_id' => $conversationId];
    }

    /**
     * Keep a run in `ai_task_runs`, and answer it as {@see runs()} does.
     *
     * @param array<string, mixed> $routine
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function record(array $routine, array $answer, ?int $conversationId, int $started): array
    {
        $message = is_array($answer['message'] ?? null) ? $answer['message'] : [];
        $row     = [
            'routine_id'      => (int) $routine['routine_id'],
            'organization_id' => $routine['organization_id'],
            'kind'            => (string) $routine['kind'],
            'status'          => $answer['ok'] ? 'ok' : 'failed',
            'content'         => (string) ($answer['content'] ?? $message['content'] ?? '') ?: null,
            'cost'            => $message['cost'] ?? null,
            'error'           => $answer['ok'] ? null : mb_substr((string) $answer['says'], 0, 500),
            'conversation_id' => $conversationId,
            'due_at'          => $routine['next_run_at'],
            'started_at'      => CallLog::instant($started),
            'finished_at'     => CallLog::instant(time()),
        ];

        try {
            $this->runsTable()->insert($row);
            $row['run_id'] = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        } catch (\Throwable $e) {
            // No runs table yet — `migrate` has not run since it was added. The run still counts.
            \Pramnos\Logs\Logger::log('llm task run not recorded: ' . $e->getMessage());
            $row['run_id'] = null;
        }

        return $row;
    }

    /** Pause a task, saying why. */
    private function pause(array $routine, string $why): void
    {
        $this->table()->where('routine_id', (int) $routine['routine_id'])->update([
            'is_active'    => false,
            'locked_until' => null,
            'last_error'   => $why,
            'updated_at'   => CallLog::instant(time()),
        ]);
    }

    /**
     * What one person has in one organisation — or outside any, with null — across spaces: the
     * tasks they made and the shared reports they read. What a page asks about before a member
     * leaves: "these go to whom?".
     *
     * @return array{tasks: list<array<string, mixed>>, subscriptions: list<int>}
     */
    public function ownedBy(?int $organizationId, int $userId): array
    {
        $query = $this->table()->where('userid', $userId)->orderBy('routine_id');
        $organizationId === null ? $query->whereNull('organization_id') : $query->where('organization_id', $organizationId);
        $result = $query->get();
        $tasks  = [];
        while (($row = $result->fetch()) !== null) {
            $tasks[] = self::shape($row);
        }

        return ['tasks' => $tasks, 'subscriptions' => $this->subscriptionsIn($organizationId, $userId)];
    }

    /**
     * Hand one person's tasks in an organisation to another — when they leave, and whoever is
     * asked chose a successor.
     *
     * Each moves with its schedule and history, but not its conversation: the new owner does not
     * inherit the old one's, and the next run starts one of theirs. Each is checked again as the
     * new owner's ({@see TaskPolicy::stillAllowed()} and {@see TaskPolicy::refusal()}); one they
     * may not run stays theirs but paused, saying why. The leaver's subscriptions are removed, not
     * passed on. The new owner is told what they received.
     *
     * @param list<int>|null $only The tasks to hand over; all of them when null
     * @return array{moved: list<int>, paused: array<int, string>}
     */
    public function transfer(?int $organizationId, int $fromUserId, int $toUserId, ?array $only = null): array
    {
        $policy = $this->policy();
        $moved  = [];
        $paused = [];

        foreach ($this->ownedBy($organizationId, $fromUserId)['tasks'] as $task) {
            if ($only !== null && !in_array($task['routine_id'], array_map('intval', $only), true)) {
                continue;
            }
            $this->table()->where('routine_id', $task['routine_id'])->update([
                'userid'          => $toUserId,
                'conversation_id' => null,
                'updated_at'      => CallLog::instant(time()),
            ]);
            $moved[] = $task['routine_id'];

            $theirs = ['userid' => $toUserId, 'conversation_id' => null] + $task;
            $why    = !$policy->stillAllowed($theirs)
                ? 'Its new owner may not run it here.'
                : $policy->refusal($policy->scope($theirs), (string) $task['kind']);
            if ($why !== null && $task['active']) {
                $this->pause($theirs, $why);
                $paused[$task['routine_id']] = $why;
            }
        }

        foreach ($this->subscriptionsIn($organizationId, $fromUserId) as $routineId) {
            $this->subscriptionsTable()->where('routine_id', $routineId)->where('userid', $fromUserId)->delete();
        }

        if ($moved !== []) {
            $words = count($moved) === 1 ? 'A scheduled task was passed to you.' : count($moved) . ' scheduled tasks were passed to you.';
            $this->remind([$toUserId], $words . ($paused === [] ? '' : ' ' . count($paused) . ' of them are paused: open them to see why.'), $this->chat->assistant()->conversationUrl(null));
        }

        return ['moved' => $moved, 'paused' => $paused];
    }

    /** @return list<int> The shared tasks of an organisation a person reads */
    private function subscriptionsIn(?int $organizationId, int $userId): array
    {
        try {
            $result = $this->subscriptionsTable()->where('userid', $userId)->get();
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        while (($row = $result->fetch()) !== null) {
            $task = $this->byId((int) $row['routine_id']);
            if ($task !== null && $task['organization_id'] === $organizationId) {
                $ids[] = (int) $row['routine_id'];
            }
        }

        return $ids;
    }

    private function subscriptionsTable(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#ai_task_subscriptions');
    }

    /**
     * A task by id, whoever owns it — for the worker that runs it, never for a person's request.
     *
     * @return array<string, mixed>|null
     */
    public function byId(int $routineId): ?array
    {
        $result = $this->table()->where('routine_id', $routineId)->first();

        return $result && (int) $result->numRows > 0 ? self::shape($result->fields) : null;
    }

    /**
     * A task's runs, the newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(int $routineId, int $limit = 20): array
    {
        $result = $this->runsTable()->where('routine_id', $routineId)->orderBy('run_id', 'desc')->limit(max(1, $limit))->get();
        $runs   = [];
        while (($row = $result->fetch()) !== null) {
            $runs[] = [
                'run_id'          => (int) $row['run_id'],
                'status'          => (string) $row['status'],
                'content'         => $row['content'] === null ? null : (string) $row['content'],
                'cost'            => $row['cost'] === null ? null : (float) $row['cost'],
                'error'           => $row['error'] === null ? null : (string) $row['error'],
                'conversation_id' => $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
                'started_at'      => $row['started_at'] === null ? null : (string) $row['started_at'],
                'finished_at'     => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            ];
        }

        return $runs;
    }

    /**
     * When a schedule next fires after a moment, on its own clock — "every morning at eight" is
     * eight on the person's clock, across a change to summer time too. A monthly day is at most
     * the 28th, so every month has it.
     */
    public static function nextRun(string $cadence, string $at, ?int $day, string $timezone, int $after): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $at) + [1 => 0]);
        $candidate = (new \DateTimeImmutable('@' . $after))->setTimezone(new \DateTimeZone($timezone))->setTime($hour, $minute);

        for ($i = 0; $i < 400; $i++) {
            $fits = match ($cadence) {
                'weekly'  => (int) $candidate->format('N') === (int) $day,
                'monthly' => (int) $candidate->format('j') === (int) $day,
                default   => true,
            };

            if ($fits && $candidate->getTimestamp() > $after) {
                return $candidate->getTimestamp();
            }

            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute);
        }

        // @codeCoverageIgnoreStart
        // Unreachable with a valid schedule, which create() insists on.
        return $after + 86400;
        // @codeCoverageIgnoreEnd
    }

    /**
     * When a task next fires after a moment, whatever its cadence — null for a one-off whose
     * moment has passed. `monthly_weekday` is the `nth` (1–4, or 5 for the last) `day` (1 Monday …
     * 7 Sunday) of the month: the second Tuesday, the last Friday.
     *
     * @param array<string, mixed> $task cadence, at, day, nth, run_at, timezone
     */
    public static function nextFor(array $task, int $after): ?int
    {
        $cadence  = (string) $task['cadence'];
        $timezone = in_array((string) ($task['timezone'] ?? ''), \DateTimeZone::listIdentifiers(), true) ? (string) $task['timezone'] : 'UTC';

        if ($cadence === 'once') {
            $at = $task['run_at'] ?? null;
            $at = is_int($at) ? $at : ($at === null ? null : strtotime((string) $at));

            return $at !== null && $at !== false && $at > $after ? $at : null;
        }

        if ($cadence !== 'monthly_weekday') {
            return self::nextRun($cadence, (string) $task['at'], $task['day'] === null ? null : (int) $task['day'], $timezone, $after);
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) $task['at']) + [1 => 0]);
        $zone  = new \DateTimeZone($timezone);
        $month = (new \DateTimeImmutable('@' . $after))->setTimezone($zone)->modify('first day of this month')->setTime($hour, $minute);
        $names = [1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $order = [1 => 'first', 'second', 'third', 'fourth', 5 => 'last'];

        for ($i = 0; $i < 24; $i++) {
            $candidate = $month->modify($order[(int) $task['nth']] . ' ' . $names[(int) $task['day']] . ' of this month')->setTime($hour, $minute);
            if ($candidate->getTimestamp() > $after) {
                return $candidate->getTimestamp();
            }
            $month = $month->modify('first day of next month');
        }

        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * The tools that let somebody say "every morning, give me…" and have it scheduled.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(Scope $scope, string $timezone): array
    {
        if ($this->chat->assistant()->routineRefusal($scope) !== null) {
            return [];
        }

        return [
            'schedule_task' => [
                'description' => 'Schedule a question to be asked again automatically — "every morning give me a report", '
                    . '"each Monday summarise last week". Each run is answered into its own conversation and the person is '
                    . 'notified. Confirm what you scheduled in one sentence, with when it runs next.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'title'   => ['type' => 'string', 'description' => 'A short name, as the person would say it.'],
                    'prompt'  => ['type' => 'string', 'description' => 'The question to ask each time, complete on its own.'],
                    'cadence' => ['type' => 'string', 'enum' => self::CADENCES],
                    'at'      => ['type' => 'string', 'description' => 'Time of day, HH:MM, 24-hour. Morning means 08:00 unless they say.'],
                    'day'     => ['type' => 'integer', 'description' => 'Weekly: 1 Monday … 7 Sunday. Monthly: 1 to 28.'],
                ], 'required' => ['title', 'prompt', 'cadence', 'at']],
                'run' => function (array $a) use ($scope, $timezone): string {
                    $made = $this->create($scope, $a + ['timezone' => $timezone]);

                    return is_string($made)
                        ? 'Error: ' . $made
                        : 'Scheduled as task ' . $made['routine_id'] . '. Next run: ' . $made['next_run_local'] . ' (' . $made['timezone'] . ').';
                },
            ],
            'list_scheduled_tasks' => [
                'description' => 'This person\'s scheduled tasks, with when each runs next.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
                'run'         => fn (): string => (string) json_encode(array_map(
                    static fn (array $r): array => array_intersect_key($r, array_flip(['routine_id', 'title', 'cadence', 'at', 'day', 'timezone', 'active', 'next_run_local'])),
                    $this->mine($scope)
                ), JSON_UNESCAPED_UNICODE),
            ],
            'cancel_scheduled_task' => [
                'description' => 'Delete one of this person\'s scheduled tasks by id, when they ask.',
                'parameters'  => ['type' => 'object', 'properties' => ['routine_id' => ['type' => 'integer']], 'required' => ['routine_id']],
                'run' => fn (array $a): string => $this->delete($scope, (int) ($a['routine_id'] ?? 0)) ? 'Deleted.' : 'Error: there is no such task.',
            ],
        ];
    }

    /** @return array{content: string, at: string}|null The last answer a routine produced */
    private function latest(?int $conversationId): ?array
    {
        if ($conversationId === null) {
            return null;
        }

        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_messages')
            ->select(['content', 'created_at'])->where('conversation_id', $conversationId)
            ->where('role', 'assistant')->orderBy('message_id', 'desc')->first();

        return $result && (int) $result->numRows > 0
            ? ['content' => (string) $result->fields['content'], 'at' => (string) $result->fields['created_at']]
            : null;
    }

    /**
     * A schedule as it is kept, or a sentence saying what is wrong with it.
     *
     * A one-off is `run_at`, a date and time on the asker's clock (`2026-10-16 17:00`), or
     * `in_minutes` from now.
     *
     * @param array<string, mixed> $wanted The whole request, for run_at, in_minutes and nth
     * @return array{at: string, day: int|null, nth: int|null, run_at: int|null, timezone: string}|string
     */
    private static function schedule(string $cadence, string $at, mixed $day, string $timezone, array $wanted = []): array|string
    {
        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';

        if ($cadence === 'once') {
            if (isset($wanted['in_minutes']) && is_numeric($wanted['in_minutes']) && (int) $wanted['in_minutes'] > 0) {
                $runAt = time() + 60 * (int) $wanted['in_minutes'];
            } else {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim((string) ($wanted['run_at'] ?? '')), new \DateTimeZone($timezone));
                if ($parsed === false) {
                    return 'A one-off needs when: run_at as YYYY-MM-DD HH:MM on the person\'s clock, or in_minutes.';
                }
                $runAt = $parsed->getTimestamp();
            }
            $local = (new \DateTimeImmutable('@' . $runAt))->setTimezone(new \DateTimeZone($timezone));

            return ['at' => $local->format('H:i'), 'day' => null, 'nth' => null, 'run_at' => $runAt, 'timezone' => $timezone];
        }

        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($at), $time)) {
            return 'The time is HH:MM, for example 08:00.';
        }

        $day      = $day === null || $day === '' ? null : (int) $day;

        if ($cadence === 'monthly_weekday') {
            $nth = isset($wanted['nth']) && is_numeric($wanted['nth']) ? (int) $wanted['nth'] : 0;
            if ($nth < 1 || $nth > 5 || $day === null || $day < 1 || $day > 7) {
                return 'Monthly on a weekday needs nth (1–4, or 5 for the last) and day (1 Monday … 7 Sunday).';
            }

            return ['at' => sprintf('%02d:%02d', (int) $time[1], (int) $time[2]), 'day' => $day, 'nth' => $nth, 'run_at' => null, 'timezone' => $timezone];
        }

        if ($cadence === 'weekly' && ($day === null || $day < 1 || $day > 7)) {
            return 'Weekly needs a day, 1 (Monday) to 7 (Sunday).';
        }

        if ($cadence === 'monthly' && ($day === null || $day < 1 || $day > 28)) {
            return 'Monthly needs a day of the month, 1 to 28.';
        }

        return ['at' => sprintf('%02d:%02d', (int) $time[1], (int) $time[2]), 'day' => $cadence === 'daily' ? null : $day, 'nth' => null, 'run_at' => null, 'timezone' => $timezone];
    }

    /** Tell the owner, by the configured notification. */
    private function tell(int $userId, string $title, bool $ok, string $says, ?int $conversationId): void
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['routine_notification'] ?? null) && class_exists($chat['routine_notification'])
            ? $chat['routine_notification'] : RoutineReady::class;

        try {
            $this->deliver($userId, new $class($title, $ok, $ok ? '' : $says, $this->chat->assistant()->conversationUrl($conversationId)));
        } catch (\Throwable $why) {
            // A dead push endpoint must not undo a report that was written.
            \Pramnos\Logs\Logger::log('llm routine notification: ' . $why->getMessage());
        }
    }

    /** A reminder, in its owner's words, to each of its audience. */
    private function remind(array $audience, string $words, string $link): void
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['reminder_notification'] ?? null) && class_exists($chat['reminder_notification'])
            ? $chat['reminder_notification'] : TaskReminder::class;

        foreach ($audience as $userId) {
            try {
                $this->deliver((int) $userId, new $class($words, $link));
            } catch (\Throwable $why) {
                \Pramnos\Logs\Logger::log('llm reminder notification: ' . $why->getMessage());
            }
        }
    }

    /** Send a notification to a person, if they still exist. A seam for tests. */
    protected function deliver(int $userId, object $notification): void
    {
        $user = new \Pramnos\User\User($userId);

        if ((int) ($user->userid ?? 0) === $userId) {
            $user->notify($notification);
        }
    }

    private function scoped(Scope $scope, int $routineId): \Pramnos\Database\QueryBuilder
    {
        return $scope->apply($this->table())->where('routine_id', $routineId);
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private static function shape(array $f): array
    {
        $timezone = (string) $f['timezone'];
        $next     = $f['next_run_at'] === null ? null : strtotime((string) $f['next_run_at']);

        return [
            'routine_id'      => (int) $f['routine_id'],
            'organization_id' => $f['organization_id'] === null ? null : (int) $f['organization_id'],
            'userid'          => (int) $f['userid'],
            'space'           => (string) ($f['space'] ?? Scope::CHAT),
            'title'           => (string) $f['title'],
            'prompt'          => (string) $f['prompt'],
            'template'        => $f['template'] === null ? null : (string) $f['template'],
            'kind'            => (string) ($f['kind'] ?? self::ASK),
            'owner'           => (string) ($f['owner'] ?? 'user'),
            'resource_type'   => ($f['resource_type'] ?? null) === null ? null : (string) $f['resource_type'],
            'resource_id'     => ($f['resource_id'] ?? null) === null ? null : (int) $f['resource_id'],
            'notify'          => (string) ($f['notify'] ?? 'always'),
            'failures'        => (int) ($f['failures'] ?? 0),
            'nth'             => ($f['nth'] ?? null) === null ? null : (int) $f['nth'],
            'run_at'          => ($f['run_at'] ?? null) === null ? null : (string) $f['run_at'],
            'cadence'         => (string) $f['cadence'],
            'at'              => (string) $f['at'],
            'day'             => $f['day'] === null ? null : (int) $f['day'],
            'timezone'        => $timezone,
            'active'          => in_array($f['is_active'], [true, 't', 1, '1'], true),
            'next_run_at'     => $f['next_run_at'] === null ? null : (string) $f['next_run_at'],
            'next_run_local'  => $next ? (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone($timezone))->format('D j M Y, H:i') : null,
            'last_run_at'     => $f['last_run_at'] === null ? null : (string) $f['last_run_at'],
            'last_error'      => $f['last_error'] === null ? null : (string) $f['last_error'],
            'conversation_id' => $f['conversation_id'] === null ? null : (int) $f['conversation_id'],
        ];
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#ai_routines');
    }

    private function runsTable(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#ai_task_runs');
    }
}
