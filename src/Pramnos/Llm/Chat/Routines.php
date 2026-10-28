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

    /** A report: asked headless with read-only tools, recorded, and sent to its readers. */
    public const REPORT = 'report';

    /** What a task may be. */
    public const KINDS = [self::ASK, self::REMINDER, self::REPORT];

    /** One person's task. */
    public const OWNER_USER = 'user';

    /** A report an organisation shares: its members may subscribe. */
    public const OWNER_ORGANIZATION = 'organization';

    /** A report for the whole installation. */
    public const OWNER_GLOBAL = 'global';

    /** Whose a task may be. */
    public const OWNERS = [self::OWNER_USER, self::OWNER_ORGANIZATION, self::OWNER_GLOBAL];

    /** The queue task a claimed run is handed to — {@see TaskRunTask}. */
    public const TASK = 'ai_task_run';

    /** Due routines run per pass. */
    public const BATCH = 20;

    /** How long a claimed run holds its task, in seconds: a worker that died frees it after this. */
    public const LEASE = 900;

    private ?TaskPolicy $policy = null;

    /** @var list<int> Tasks this instance's tools made with no conversation to name yet */
    private array $madeUnlinked = [];

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
            return 'A task is a question asked again (ask), a reminder (reminder) or a report (report).';
        }

        $owner = (string) ($wanted['owner'] ?? self::OWNER_USER);
        if (!in_array($owner, self::OWNERS, true) || ($owner !== self::OWNER_USER && $kind !== self::REPORT)) {
            return 'Only a report is shared: owner is user, or organization or global for a report.';
        }
        if (!$this->policy()->canManage($scope, $owner)) {
            return $owner === self::OWNER_GLOBAL ? 'You may not make a report for the whole installation.' : 'You may not make a report for the whole organisation.';
        }
        // Who may receive it: only a shared report has readers to choose.
        $audience = $owner === self::OWNER_USER ? null : $this->audienceFrom($wanted);
        if (is_string($audience)) {
            return $audience;
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
            return 'There is no such cadence. How often: daily, weekly, monthly, monthly_weekday (the 2nd Tuesday, the last Friday) or once.';
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
        // Written only when chosen, so an installation that has not run the migration adding the
        // column still schedules everything else.
        $this->table()->insert($scope->columns() + ($audience === null ? [] : ['audience' => json_encode($audience)]) + [
            'title'       => $title,
            'prompt'      => $prompt,
            'template'    => $base === null ? null : $template,
            'kind'        => $kind,
            'owner'       => $owner,
            'cadence'     => $cadence,
            'at'          => $schedule['at'],
            'day'         => $schedule['day'],
            'nth'         => $schedule['nth'],
            'run_at'      => $schedule['run_at'] === null ? null : CallLog::instant($schedule['run_at']),
            'timezone'    => $schedule['timezone'],
            'notify'      => ($wanted['notify'] ?? '') === 'relevant' ? 'relevant' : 'always',
            'resource_type' => isset($wanted['resource_type']) && $wanted['resource_type'] !== '' ? mb_substr((string) $wanted['resource_type'], 0, 32) : null,
            'resource_id' => isset($wanted['resource_id']) && is_numeric($wanted['resource_id']) ? (int) $wanted['resource_id'] : null,
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

    /** Delete one of this person's, with its readers and the record of its runs. Its conversation stays, like any other. */
    public function delete(Scope $scope, int $routineId): bool
    {
        if ($this->find($scope, $routineId) === null) {
            return false;
        }

        foreach ([$this->subscriptionsTable(), $this->runsTable()] as $table) {
            try {
                $table->where('routine_id', $routineId)->delete();
            } catch (\Throwable $e) {
                // No such table yet — `migrate` has not run since it was added: nothing to remove.
                \Pramnos\Logs\Logger::log('llm task delete: ' . $e->getMessage());
            }
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

            if (!$this->queued() && $task['space'] !== Scope::CHAT) {
                // The analyst's task with the analyst: its tools, its prompt, its refusals —
                // not the chat assistant this tick was built with.
                $this->forSpace($task['space'])->run($task, $now);
                continue;
            }
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

    /** @var array<string, self> The tasks of other spaces, with their own assistant */
    private array $spaces = [];

    /** These tasks for another space — `admin`, the analyst's — with that space's assistant. */
    protected function forSpace(string $space): self
    {
        return $this->spaces[$space] ??= new static(new Conversations(Chat::assistant($space)), $this->queue);
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

        if ($routine['kind'] === self::REPORT && !$policy->resourceExists($routine)) {
            // What it is about is gone: not a failure to count, but nothing to run on again.
            $this->pause($routine, 'What it is about no longer exists.');
            $this->tell($scope->userId, (string) $routine['title'], false, 'It was paused: what it is about no longer exists.', $routine['conversation_id']);

            return ['ok' => false, 'says' => 'Paused: what it is about no longer exists.', 'conversation_id' => $routine['conversation_id']];
        }

        if ($routine['kind'] === self::REMINDER) {
            $answer = ['ok' => true, 'says' => '', 'conversation_id' => $routine['conversation_id'], 'content' => (string) $routine['prompt']];
        } elseif ($routine['kind'] === self::REPORT) {
            $chat     = $this->chat->as($scope, true)->forTask((int) $routine['routine_id']);
            $day      = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone))->format('l j F Y');
            $about    = $policy->resourceLabel($routine);
            $question = 'Scheduled report "' . $routine['title'] . '"' . ($about !== '' ? ' about ' . $about : '') . ', run on ' . $day
                . ". It is read by others, not answered: write the report itself, complete on its own.\n\n" . $routine['prompt'];
            $answer   = ($why = $policy->refusal($scope, self::REPORT)) !== null
                ? ['ok' => false, 'says' => $why, 'why' => 'limit']
                : $this->chat->assistant()->acting($scope, static fn (): array => $chat->report($question, ['task' => $routine], $routine['notify'] === 'relevant'));
            $answer['conversation_id'] = $routine['conversation_id'];
        } else {
            $assistant = $this->chat->assistant();
            $chat      = $this->chat->as($scope, true)->forTask((int) $routine['routine_id']);
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

        $audience = $policy->audience($routine + ['subscribers' => $this->subscribers((int) $routine['routine_id'])]);
        if ($routine['kind'] === self::REPORT) {
            // A failure is its maker's to fix; a report with nothing new, when asked for only
            // what matters, tells nobody — it is still recorded.
            $audience = !$ok ? [$scope->userId] : ($routine['notify'] === 'relevant' && ($answer['relevant'] ?? null) === false ? [] : $audience);
        }

        $run = $this->record($routine, $answer, $conversationId, $started, $audience);

        if ($routine['kind'] === self::REPORT) {
            $title = (string) $routine['title'] . (($label = $policy->resourceLabel($routine)) !== '' ? ' — ' . $label : '');
            $why   = $ok ? '' : (string) $answer['says'] . ($pause ? ' It was paused after ' . $failures . ' failures in a row; resume it when this is fixed.' : '');
            // Who asked for it by email too: the report itself goes to them, not only its notice.
            $byEmail = $ok ? $this->emailSubscribers((int) $routine['routine_id']) : [];
            foreach ($audience as $userId) {
                $this->tell($userId, $title, $ok, $why, $conversationId, $ok ? $policy->link($routine, $run) : null,
                    (string) ($run['content'] ?? ''), in_array($userId, $byEmail, true));
            }
        } elseif ($routine['kind'] === self::REMINDER) {
            $this->remind($audience, (string) $routine['prompt'], $policy->link($routine, $run));
        } else {
            $why = $ok ? '' : (string) $answer['says'] . ($pause ? ' It was paused after ' . $failures . ' failures in a row; resume it when this is fixed.' : '');
            foreach ($audience as $userId) {
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
    private function record(array $routine, array $answer, ?int $conversationId, int $started, array $notified = []): array
    {
        $message = is_array($answer['message'] ?? null) ? $answer['message'] : [];
        $extra   = array_filter([
            'steps'    => $answer['steps'] ?? [],
            'relevant' => $answer['relevant'] ?? null,
            'notified' => $notified,
        ], static fn ($v): bool => $v !== null && $v !== []);
        $row     = [
            'routine_id'      => (int) $routine['routine_id'],
            'organization_id' => $routine['organization_id'],
            'kind'            => (string) $routine['kind'],
            'status'          => $answer['ok'] ? 'ok' : 'failed',
            'content'         => (string) ($answer['content'] ?? $message['content'] ?? '') ?: null,
            'cost'            => $answer['cost'] ?? $message['cost'] ?? null,
            'extra'           => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
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

    /**
     * What a leaving person is asked about, organisation by organisation: their tasks there,
     * which of them are shared (an organisation's or the installation's reports) and which their
     * own, and who may take them over ({@see TaskPolicy::successors()}). Shared ones are proposed
     * for a successor by default; one's own go with them only if they choose; with nobody to take
     * them, they can only be deleted.
     *
     * @return list<array{organization_id: int|null, shared: list<array<string, mixed>>, personal: list<array<string, mixed>>, successors: array<int, string>}>
     */
    public function handoverChoices(int $userId): array
    {
        $byOrganization = [];
        foreach ($this->forUser($userId)['tasks'] as $task) {
            $key = $task['organization_id'] === null ? 'none' : (string) $task['organization_id'];
            $byOrganization[$key] ??= ['organization_id' => $task['organization_id'], 'shared' => [], 'personal' => [], 'successors' => []];
            $byOrganization[$key][$task['owner'] === self::OWNER_USER ? 'personal' : 'shared'][] = $task;
        }
        foreach ($byOrganization as $key => $one) {
            $byOrganization[$key]['successors'] = $this->policy()->successors($one['organization_id'], $userId);
        }

        return array_values($byOrganization);
    }

    /**
     * Hand a leaving person's tasks to the successors they chose, per organisation —
     * `[orgId => ['to' => userid, 'personal' => bool]]`, `none` for outside any. Shared tasks go
     * to the successor; one's own only with `personal`. A successor the policy does not offer is
     * refused; what is not handed over is left for the erasure to delete.
     *
     * @param array<int|string, array{to?: mixed, personal?: mixed}> $choices
     * @return list<int> The tasks handed over
     */
    public function handover(int $userId, array $choices): array
    {
        $moved = [];
        foreach ($this->handoverChoices($userId) as $one) {
            $key    = $one['organization_id'] === null ? 'none' : (string) $one['organization_id'];
            $choice = $choices[$key] ?? [];
            $to     = (int) ($choice['to'] ?? 0);
            if ($to <= 0 || !isset($one['successors'][$to])) {
                continue;
            }
            $tasks = !empty($choice['personal']) ? [...$one['shared'], ...$one['personal']] : $one['shared'];
            if ($tasks === []) {
                continue;
            }
            $moved = [...$moved, ...$this->transfer($one['organization_id'], $userId, $to, array_column($tasks, 'routine_id'))['moved']];
        }

        return $moved;
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
            $runs[] = self::runShape($row);
        }

        return $runs;
    }

    /**
     * One run, with the task it belongs to — null when there is no such run. Whether the person
     * asking may read it is {@see TaskPolicy::canSee()}'s to say, over `task`.
     *
     * @return array<string, mixed>|null
     */
    public function runById(int $runId): ?array
    {
        $result = $this->runsTable()->where('run_id', $runId)->first();
        if (!$result || (int) $result->numRows === 0) {
            return null;
        }
        $run  = self::runShape($result->fields);
        $task = $this->byId((int) $result->fields['routine_id']);

        return $task === null ? null : $run + ['task' => $task];
    }

    /**
     * The conversation a reader opened from a run, kept so opening it again continues it — or,
     * given one, keep it.
     */
    public function opened(int $runId, int $userId, ?int $conversationId = null): ?int
    {
        $found = $this->runById($runId);
        if ($found === null) {
            return null;
        }
        $extra = $found['extra'];
        if ($conversationId === null) {
            return isset($extra['opened'][(string) $userId]) ? (int) $extra['opened'][(string) $userId] : null;
        }
        $extra['opened'][(string) $userId] = $conversationId;
        $this->runsTable()->where('run_id', $runId)->update(['extra' => json_encode($extra, JSON_UNESCAPED_UNICODE)]);

        return $conversationId;
    }

    /**
     * Open a report's run as a conversation of the reader's own, to ask about it — the same one
     * each time they open it again, while it lasts. Null when there is no such run they may read.
     *
     * The conversation is theirs, in their scope: what they ask next runs with their rights, not
     * the report's maker's.
     */
    public function continueRun(Scope $viewer, int $runId): ?int
    {
        $found = $this->runById($runId);
        if ($found === null || $found['status'] !== 'ok' || !$this->policy()->canSee($viewer->userId, $found['task'])) {
            return null;
        }

        $chat = $this->chat->as($viewer);
        $kept = $this->opened($runId, $viewer->userId);
        if ($kept !== null && $chat->find($kept) !== null) {
            return $kept;
        }

        $day  = $found['finished_at'] === null ? '' : ' (' . substr((string) $found['finished_at'], 0, 10) . ')';
        $made = $chat->seed($found['task']['title'] . $day, [
            ['role' => 'user', 'content' => 'Report "' . $found['task']['title'] . '"' . $day . ': ' . $found['task']['prompt']],
            ['role' => 'assistant', 'content' => (string) $found['content']],
        ]);

        return $made === null ? null : $this->opened($runId, $viewer->userId, $made);
    }

    /**
     * Subscribe a person to a shared report — or their own — so each run reaches them. Answers
     * null, or why not.
     */
    public function subscribe(int $userId, int $routineId, bool $email = false): ?string
    {
        $task = $this->byId($routineId);
        if ($task === null || $task['kind'] !== self::REPORT || !$this->policy()->canSee($userId, $task)) {
            return 'There is no such report you may read.';
        }
        if (in_array($userId, $this->subscribers($routineId), true)) {
            $this->subscriptionsTable()->where('routine_id', $routineId)->where('userid', $userId)->update(['email' => $email]);

            return null;
        }
        $this->subscriptionsTable()->insert(['routine_id' => $routineId, 'userid' => $userId, 'email' => $email, 'created_at' => CallLog::instant(time())]);

        return null;
    }

    /** Stop a person receiving a report. False when they were not subscribed. */
    public function unsubscribe(int $userId, int $routineId): bool
    {
        if (!in_array($userId, $this->subscribers($routineId), true)) {
            return false;
        }
        $this->subscriptionsTable()->where('routine_id', $routineId)->where('userid', $userId)->delete();

        return true;
    }

    /** @return list<int> Who subscribed to a report by email too */
    public function emailSubscribers(int $routineId): array
    {
        try {
            $result = $this->subscriptionsTable()->select(['userid', 'email'])->where('routine_id', $routineId)->get();
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        while (($row = $result->fetch()) !== null) {
            if (in_array($row['email'], [true, 1, '1', 't'], true)) {
                $ids[] = (int) $row['userid'];
            }
        }

        return $ids;
    }

    /** @return list<int> Who subscribed to a report — before {@see TaskPolicy::audience()} checks each */
    public function subscribers(int $routineId): array
    {
        try {
            $result = $this->subscriptionsTable()->select(['userid'])->where('routine_id', $routineId)->orderBy('userid')->get();
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        while (($row = $result->fetch()) !== null) {
            $ids[] = (int) $row['userid'];
        }

        return $ids;
    }

    /**
     * The shared reports a person may read here — their organisation's and the installation's —
     * each with `subscribed` and its latest run.
     *
     * @return list<array<string, mixed>>
     */
    public function reports(Scope $scope): array
    {
        $result = $this->table()->where('kind', self::REPORT)->where('space', $scope->space)
            ->whereIn('owner', [self::OWNER_ORGANIZATION, self::OWNER_GLOBAL])->orderBy('routine_id')->get();
        $found  = [];
        while (($row = $result->fetch()) !== null) {
            $found[] = self::shape($row);
        }

        $list = [];
        foreach ($found as $task) {
            $here = $task['owner'] === self::OWNER_GLOBAL || $task['organization_id'] === $scope->organizationId;
            if ($here && $this->policy()->canSee($scope->userId, $task)) {
                $list[] = $task + [
                    'subscribed' => in_array($scope->userId, $this->subscribers($task['routine_id']), true),
                    'latest_run' => $this->runs($task['routine_id'], 1)[0] ?? null,
                ];
            }
        }

        return $list;
    }

    /**
     * Everything kept about one person, for an export: the tasks they made, everywhere, and the
     * reports they read.
     *
     * @return array{tasks: list<array<string, mixed>>, subscriptions: list<int>}
     */
    public function forUser(int $userId): array
    {
        $result = $this->table()->where('userid', $userId)->orderBy('routine_id')->get();
        $tasks  = [];
        while (($row = $result->fetch()) !== null) {
            $tasks[] = self::shape($row);
        }
        $subscriptions = [];
        try {
            $subs = $this->subscriptionsTable()->select(['routine_id'])->where('userid', $userId)->orderBy('routine_id')->get();
            while (($row = $subs->fetch()) !== null) {
                $subscriptions[] = (int) $row['routine_id'];
            }
        } catch (\Throwable $e) {
            // No subscriptions table yet — `migrate` has not run: there is nothing to export.
            \Pramnos\Logs\Logger::log('llm task subscriptions not read: ' . $e->getMessage());
        }

        return ['tasks' => $tasks, 'subscriptions' => $subscriptions];
    }

    /**
     * A run as it is answered.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function runShape(array $row): array
    {
        return [
            'run_id'          => (int) $row['run_id'],
            'routine_id'      => (int) $row['routine_id'],
            'status'          => (string) $row['status'],
            'content'         => $row['content'] === null ? null : (string) $row['content'],
            'cost'            => $row['cost'] === null ? null : (float) $row['cost'],
            'error'           => $row['error'] === null ? null : (string) $row['error'],
            'extra'           => is_array($extra = json_decode((string) ($row['extra'] ?? ''), true)) ? $extra : [],
            'conversation_id' => $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
            'started_at'      => $row['started_at'] === null ? null : (string) $row['started_at'],
            'finished_at'     => $row['finished_at'] === null ? null : (string) $row['finished_at'],
        ];
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
            $at = is_int($at) ? $at : ($at === null ? null : self::utc((string) $at));

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
        return $this->toolsFor($scope, $timezone, null);
    }

    /**
     * The scheduling tools for a conversation: schedule, change, list and cancel this person's
     * tasks — questions asked again and reminders, recurring or once. A reminder links back to the
     * conversation it was asked in.
     *
     * Never in a scheduled run (a task that schedules tasks has no end), and a duplicate is refused
     * by the tool itself, with the id to change instead: a description asking the model not to
     * duplicate is a description it can ignore.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toolsFor(Scope $scope, string $timezone, ?int $conversationId): array
    {
        if ($this->policy()->refusal($scope, self::ASK) !== null && $this->policy()->refusal($scope, self::REMINDER) !== null) {
            return [];
        }

        $when = [
            'cadence'    => ['type' => 'string', 'enum' => self::CADENCES, 'description' => 'once for a single time; monthly_weekday for "the second Tuesday", "the last Friday".'],
            'at'         => ['type' => 'string', 'description' => 'Time of day, HH:MM, 24-hour. Morning means 08:00 unless they say.'],
            'day'        => ['type' => 'integer', 'description' => 'weekly and monthly_weekday: 1 Monday … 7 Sunday. monthly: 1 to 28.'],
            'nth'        => ['type' => 'integer', 'description' => 'monthly_weekday: 1–4, or 5 for the last.'],
            'run_at'     => ['type' => 'string', 'description' => 'once: YYYY-MM-DD HH:MM on the person\'s clock (' . $timezone . ').'],
            'in_minutes' => ['type' => 'integer', 'description' => 'once: minutes from now — "in an hour" is 60.'],
        ];

        return [
            'schedule_task' => [
                'description' => 'Schedule something for this person. kind "report": a report its readers receive, written without a '
                    . 'conversation and changing nothing — "every Monday send the team the week\'s figures". kind "ask": a question asked again automatically — "every morning give me '
                    . 'a report", "each Monday summarise last week" — answered into its own conversation, and they are notified. '
                    . 'kind "reminder": "remind me in an hour about X", "on Friday at 9 tell me to call Maria" — they are notified in '
                    . 'their own words, and nothing is asked. A task that already does the same is refused with its id: change that one '
                    . 'with update_scheduled_task instead. Confirm what you scheduled in one sentence, with when it runs next.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'kind'   => ['type' => 'string', 'enum' => self::KINDS, 'description' => 'Required. reminder: tell them something at a time, '
                        . 'in their words — "remind me", "tell me to", "don\'t let me forget"; nothing is asked of a model. ask: a question '
                        . 'answered for them each time. report: a report for its readers.'],
                    'title'  => ['type' => 'string', 'description' => 'A short name, as the person would say it.'],
                    'prompt' => ['type' => 'string', 'description' => 'ask: the question each time, complete on its own. reminder: what to tell them, in their words. report: what the report covers.'],
                    'owner'  => ['type' => 'string', 'enum' => self::OWNERS, 'description' => 'report only: organization shares it with their organisation, '
                        . 'global with the installation — only when they ask to share it. Otherwise user.'],
                    'notify' => ['type' => 'string', 'enum' => ['always', 'relevant'], 'description' => 'report only: relevant tells its readers only when there is something new.'],
                ] + $when, 'required' => ['kind', 'title', 'prompt', 'cadence']],
                'run' => function (array $a) use ($scope, $timezone, $conversationId): string {
                    // Required in the schema, and refused here too: a model that leaves it out must
                    // not turn "remind me in two minutes" into a question that costs a call.
                    if (!in_array($a['kind'] ?? null, self::KINDS, true)) {
                        return 'Error: say which kind — reminder (tell them something at a time, nothing asked), ask (a question '
                            . 'answered each time) or report. "Remind me…" is a reminder.';
                    }
                    $same = $this->duplicateOf($scope, $a);
                    if ($same !== null) {
                        return 'Error: task ' . $same['routine_id'] . ' ("' . $same['title'] . '") already does this, next on '
                            . ($same['next_run_local'] ?? 'no date') . '. Change it with update_scheduled_task, or say why a second is wanted.';
                    }
                    $made = $this->create($scope, $a + ['timezone' => $timezone, 'conversation_id' => $conversationId]);
                    if (is_array($made) && $conversationId === null) {
                        $this->madeUnlinked[] = (int) $made['routine_id'];
                    }

                    return is_string($made)
                        ? 'Error: ' . $made
                        : 'Scheduled as task ' . $made['routine_id'] . '. Next run: ' . $made['next_run_local'] . ' (' . $made['timezone'] . ').';
                },
            ],
            'update_scheduled_task' => [
                'description' => 'Change one of this person\'s scheduled tasks by id — its words, its time, how often, or pause and resume it.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'routine_id' => ['type' => 'integer'],
                    'title'      => ['type' => 'string'],
                    'prompt'     => ['type' => 'string'],
                    'active'     => ['type' => 'boolean', 'description' => 'false pauses it, true resumes it.'],
                ] + $when, 'required' => ['routine_id']],
                'run' => function (array $a) use ($scope, $timezone): string {
                    $changed = $this->update($scope, (int) ($a['routine_id'] ?? 0), $a + ['timezone' => $timezone]);

                    return is_string($changed)
                        ? 'Error: ' . $changed
                        : 'Task ' . $changed['routine_id'] . ' changed. ' . ($changed['active'] ? 'Next run: ' . $changed['next_run_local'] . '.' : 'It is paused.');
                },
            ],
            'list_scheduled_tasks' => [
                'description' => 'This person\'s scheduled tasks and reminders, with when each runs next, and any that stopped and why.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
                'run'         => fn (): string => (string) json_encode(array_map(
                    static fn (array $r): array => array_intersect_key($r, array_flip(['routine_id', 'kind', 'title', 'cadence', 'at', 'day', 'nth', 'timezone', 'active', 'next_run_local', 'last_error'])),
                    $this->mine($scope)
                ), JSON_UNESCAPED_UNICODE),
            ],
            'list_reports' => [
                'description' => 'The reports shared here that this person may receive, and whether they already do.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
                'run'         => fn (): string => (string) json_encode(array_map(
                    static fn (array $r): array => array_intersect_key($r, array_flip(['routine_id', 'title', 'owner', 'cadence', 'at', 'day', 'next_run_local', 'subscribed'])),
                    $this->reports($scope)
                ), JSON_UNESCAPED_UNICODE),
            ],
            'subscribe_report' => [
                'description' => 'Have this person receive a shared report by id, when they ask — by email too when they ask for that, which sends the report itself, not only its notice. List them first to find the id. Asked again, it changes whether email is on.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'routine_id' => ['type' => 'integer'],
                    'email'      => ['type' => 'boolean', 'description' => 'Also email the report to them. Default false.'],
                ], 'required' => ['routine_id']],
                'run' => fn (array $a): string => ($why = $this->subscribe($scope->userId, (int) ($a['routine_id'] ?? 0), !empty($a['email']))) === null
                    ? (!empty($a['email']) ? 'Subscribed, by email too.' : 'Subscribed.') : 'Error: ' . $why,
            ],
            'unsubscribe_report' => [
                'description' => 'Stop this person receiving a shared report by id.',
                'parameters'  => ['type' => 'object', 'properties' => ['routine_id' => ['type' => 'integer']], 'required' => ['routine_id']],
                'run' => fn (array $a): string => $this->unsubscribe($scope->userId, (int) ($a['routine_id'] ?? 0)) ? 'Unsubscribed.' : 'Error: they do not receive it.',
            ],
            'cancel_scheduled_task' => [
                'description' => 'Delete one of this person\'s scheduled tasks or reminders by id, when they ask. List them first to find the id.',
                'parameters'  => ['type' => 'object', 'properties' => ['routine_id' => ['type' => 'integer']], 'required' => ['routine_id']],
                'run' => fn (array $a): string => $this->delete($scope, (int) ($a['routine_id'] ?? 0)) ? 'Deleted.' : 'Error: there is no such task.',
            ],
        ];
    }

    /**
     * Name the conversation the tasks these tools just made belong to — for a first question,
     * whose conversation exists only once it is answered, so a reminder asked for there leads
     * back to it. Answers how many were linked.
     */
    public function linkMade(int $conversationId): int
    {
        $linked = 0;
        foreach ($this->madeUnlinked as $routineId) {
            $result = $this->table()->where('routine_id', $routineId)->whereNull('conversation_id')
                ->update(['conversation_id' => $conversationId, 'updated_at' => CallLog::instant(time())]);
            $linked += is_object($result) ? (int) $result->getAffectedRows() : 0;
        }
        $this->madeUnlinked = [];

        return $linked;
    }

    /**
     * An active task of this person's that already does what a request asks: the same kind, and
     * the same words or the same title, at the same time.
     *
     * @param array<string, mixed> $wanted
     * @return array<string, mixed>|null
     */
    private function duplicateOf(Scope $scope, array $wanted): ?array
    {
        $kind   = (string) ($wanted['kind'] ?? self::ASK);
        $title  = mb_strtolower(trim((string) ($wanted['title'] ?? '')));
        $prompt = mb_strtolower(trim((string) ($wanted['prompt'] ?? '')));
        $at     = (string) ($wanted['at'] ?? '');

        foreach ($this->mine($scope) as $task) {
            if (!$task['active'] || $task['kind'] !== $kind) {
                continue;
            }
            $sameWords = ($prompt !== '' && mb_strtolower($task['prompt']) === $prompt) || ($title !== '' && mb_strtolower($task['title']) === $title);
            $sameTime  = ($wanted['cadence'] ?? '') === 'once' || $at === '' || $task['at'] === sprintf('%05s', $at);
            if ($sameWords && $sameTime && ($wanted['cadence'] ?? $task['cadence']) === $task['cadence']) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Change one of this person's tasks: its words, schedule, or whether it is active. What is not
     * given stays; a new schedule is checked as a new task's is, and its next run worked out again.
     *
     * @param array<string, mixed> $changes title, prompt, cadence, at, day, nth, run_at, in_minutes, timezone, active
     * @return array<string, mixed>|string The task as changed, or why not
     */
    public function update(Scope $scope, int $routineId, array $changes): array|string
    {
        $found = $this->find($scope, $routineId);
        if ($found === null) {
            return 'There is no such task.';
        }

        $set = [];
        foreach (['title' => 120, 'prompt' => Conversations::CHARS] as $field => $max) {
            if (isset($changes[$field]) && trim((string) $changes[$field]) !== '') {
                $set[$field] = mb_substr(trim((string) $changes[$field]), 0, $max);
            }
        }

        $scheduling = array_intersect_key($changes, array_flip(['cadence', 'at', 'day', 'nth', 'run_at', 'in_minutes']));
        $task       = $found;
        if ($scheduling !== []) {
            $cadence = (string) ($changes['cadence'] ?? $found['cadence']);
            if (!in_array($cadence, self::CADENCES, true)) {
                return 'There is no such cadence. How often: daily, weekly, monthly, monthly_weekday (the 2nd Tuesday, the last Friday) or once.';
            }
            $zone     = (string) ($changes['timezone'] ?? $found['timezone']);
            $schedule = self::schedule($cadence, (string) ($changes['at'] ?? $found['at']), $changes['day'] ?? $found['day'], $zone,
                $changes + ['nth' => $found['nth']]);
            if (is_string($schedule)) {
                return $schedule;
            }
            $task = ['cadence' => $cadence] + $schedule + $found;
            $set += [
                'cadence'  => $cadence,
                'at'       => $schedule['at'],
                'day'      => $schedule['day'],
                'nth'      => $schedule['nth'],
                'run_at'   => $schedule['run_at'] === null ? null : CallLog::instant($schedule['run_at']),
                'timezone' => $schedule['timezone'],
            ];
        }

        if (array_key_exists('audience', $changes) || array_key_exists('audience_role', $changes) || array_key_exists('audience_users', $changes)) {
            if ($found['owner'] === self::OWNER_USER) {
                return 'Only a shared report has readers to choose.';
            }
            $audience = $this->audienceFrom($changes);
            if (is_string($audience)) {
                return $audience;
            }
            $set['audience'] = $audience === null ? null : json_encode($audience);
        }

        $active = array_key_exists('active', $changes) ? (bool) $changes['active'] : $found['active'];
        if ($active && !$found['active'] && $this->policy()->refusal($scope, $found['kind'], true) !== null) {
            return (string) $this->policy()->refusal($scope, $found['kind'], true);
        }
        $next = self::nextFor($task, time());
        if ($active && $next === null) {
            return 'That time has passed: give one still to come.';
        }

        $this->scoped($scope, $routineId)->update($set + [
            'is_active'   => $active,
            'failures'    => $active && !$found['active'] ? 0 : $found['failures'],
            'next_run_at' => $active && $next !== null ? CallLog::instant($next) : null,
            'updated_at'  => CallLog::instant(time()),
        ]);

        return $this->find($scope, $routineId) ?? [];
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
                // `2026-10-16 17:00`, or `2026-10-16T17:00` as a datetime-local field sends it.
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', trim((string) ($wanted['run_at'] ?? ''))), new \DateTimeZone($timezone));
                if ($parsed === false) {
                    return 'A one-off needs when: run_at as YYYY-MM-DD HH:MM on the person\'s clock, or in_minutes.';
                }
                $runAt = $parsed->getTimestamp();
            }
            // A MySQL TIMESTAMP ends in January 2038; a one-off further than ten years on is a typo
            // more often than a plan.
            if ($runAt > time() + 10 * 366 * 86400) {
                return 'That is too far ahead: give a time within ten years.';
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

    /**
     * Tell a person, by the configured notification — with a report's words, and by email when
     * their subscription asks for it. An application's own notification class, written for the
     * first four arguments, is given the other two and may ignore them.
     */
    private function tell(int $userId, string $title, bool $ok, string $says, ?int $conversationId, ?string $link = null, string $content = '', bool $email = false): void
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['routine_notification'] ?? null) && class_exists($chat['routine_notification'])
            ? $chat['routine_notification'] : RoutineReady::class;

        try {
            $this->deliver($userId, new $class($title, $ok, $ok ? '' : $says, self::linkable($link ?? $this->chat->assistant()->conversationUrl($conversationId)), $content, $email));
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
                $this->deliver((int) $userId, new $class($words, self::linkable($link)));
            } catch (\Throwable $why) {
                \Pramnos\Logs\Logger::log('llm reminder notification: ' . $why->getMessage());
            }
        }
    }

    /**
     * A notification's link that does not depend on the page it is opened from. Built in a cron
     * run with no request and no `APP_URL`, an address is relative (`admin/Ai/report/1`), and a
     * notification bell resolves it under the page it is on (`/admin/admin/Ai/…`): from the site's
     * root instead. Set `APP_URL` for an application in a folder — the `site_url` health check
     * says so.
     */
    private static function linkable(string $link): string
    {
        return $link === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $link) === 1 ? $link : '/' . $link;
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
        $next     = $f['next_run_at'] === null ? null : self::utc((string) $f['next_run_at']);

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
            'next_run_local'  => $next ? (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone($timezone))->format('d/m/Y H:i') : null,
            'last_run_at'     => $f['last_run_at'] === null ? null : (string) $f['last_run_at'],
            'last_error'      => $f['last_error'] === null ? null : (string) $f['last_error'],
            'conversation_id' => $f['conversation_id'] === null ? null : (int) $f['conversation_id'],
            'audience'        => self::audienceOf($f['audience'] ?? null),
        ];
    }

    /**
     * Who may receive a shared report, as given: `audience` as `{min_usertype, users}`, or the
     * form's `audience_role` (a role, `''` for none) and `audience_users` (usernames or emails,
     * separated by commas). Null when nothing is chosen — the policy's own rule applies — or what
     * is wrong with it.
     *
     * @param array<string, mixed> $wanted
     * @return array{min_usertype: int|null, users: list<int>}|string|null
     */
    private function audienceFrom(array $wanted): array|string|null
    {
        $given = is_array($wanted['audience'] ?? null) ? $wanted['audience'] : [];
        $min   = isset($given['min_usertype']) && is_numeric($given['min_usertype']) ? (int) $given['min_usertype'] : null;
        $role  = trim((string) ($wanted['audience_role'] ?? ''));
        if ($role !== '') {
            try {
                $min = \Pramnos\User\Roles::level($role);
            } catch (\InvalidArgumentException) {
                return self::words('There is no role called %s.', $role);
            }
        }
        if ($min !== null && ($min < 1 || $min > 99)) {
            return 'A usertype is between 1 and 99.';
        }

        $users = array_values(array_unique(array_map('intval', array_filter((array) ($given['users'] ?? []), 'is_numeric'))));
        $names = array_values(array_filter(array_map('trim', explode(',', (string) ($wanted['audience_users'] ?? ''))), static fn (string $n): bool => $n !== ''));
        if ($names !== []) {
            $found = $this->policy()->audienceUsers($names);
            $missing = array_diff($names, array_keys($found));
            if ($missing !== []) {
                return self::words('There is nobody called %s.', implode(', ', $missing));
            }
            $users = array_values(array_unique(array_merge($users, array_values($found))));
        }
        $users = array_slice(array_values(array_filter($users, static fn (int $id): bool => $id > 1)), 0, 200);

        return $min === null && $users === [] ? null : ['min_usertype' => $min, 'users' => $users];
    }

    /**
     * A message that carries a value, in the page's language: the value is a placeholder, so a
     * translation catches the whole sentence — a flash message translates only words it knows
     * whole.
     */
    private static function words(string $sentence, string $value): string
    {
        return function_exists('t') ? (string) t($sentence, $value) : sprintf($sentence, $value);
    }

    /** @return array{min_usertype: int|null, users: list<int>}|null The audience as stored */
    private static function audienceOf(mixed $stored): ?array
    {
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'min_usertype' => isset($decoded['min_usertype']) && is_numeric($decoded['min_usertype']) ? (int) $decoded['min_usertype'] : null,
            'users'        => array_values(array_map('intval', array_filter((array) ($decoded['users'] ?? []), 'is_numeric'))),
        ];
    }

    /**
     * A moment as the database answers it, read as UTC — which is how it was written
     * ({@see CallLog::instant()}). PostgreSQL answers with its offset, which wins; MySQL answers
     * the bare wall-clock, which PHP would otherwise read in its own zone, so that "09:00 in
     * Athens" was shown as 06:00.
     */
    public static function utc(string $moment): ?int
    {
        try {
            return (new \DateTimeImmutable($moment, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            // Not a moment at all: no time, as strtotime() answered.
            return null;
        }
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
