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
    public const CADENCES = ['daily', 'weekly', 'monthly'];

    /** Due routines run per pass. */
    public const BATCH = 20;

    public function __construct(private readonly Conversations $chat)
    {
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
        if (($why = $this->chat->assistant()->routineRefusal($scope, true)) !== null) {
            return $why;
        }

        $template = (string) ($wanted['template'] ?? '');
        $base     = self::templates()[$template] ?? null;
        $wanted  += $base ?? [];

        $title   = mb_substr(trim((string) ($wanted['title'] ?? '')), 0, 120);
        $prompt  = mb_substr(trim((string) ($wanted['prompt'] ?? '')), 0, Conversations::CHARS);
        $cadence = (string) ($wanted['cadence'] ?? '');

        if ($title === '' || $prompt === '') {
            return 'A scheduled task needs a title and what to ask.';
        }

        if (!in_array($cadence, self::CADENCES, true)) {
            return 'How often: daily, weekly or monthly.';
        }

        $schedule = self::schedule($cadence, (string) ($wanted['at'] ?? '08:00'), $wanted['day'] ?? null, (string) ($wanted['timezone'] ?? ''));

        if (is_string($schedule)) {
            return $schedule;
        }

        $stamp = CallLog::instant(time());
        $this->table()->insert($scope->columns() + [
            'title'       => $title,
            'prompt'      => $prompt,
            'template'    => $base === null ? null : $template,
            'cadence'     => $cadence,
            'at'          => $schedule['at'],
            'day'         => $schedule['day'],
            'timezone'    => $schedule['timezone'],
            'is_active'   => true,
            'next_run_at' => CallLog::instant(self::nextRun($cadence, $schedule['at'], $schedule['day'], $schedule['timezone'], time())),
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

        if ($found === null || ($active && !$found['active'] && $this->chat->assistant()->routineRefusal($scope, true) !== null)) {
            return false;
        }

        $this->scoped($scope, $routineId)->update([
            'is_active'   => $active,
            'next_run_at' => CallLog::instant(self::nextRun($found['cadence'], $found['at'], $found['day'], $found['timezone'], time())),
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
     * Run everything due, each as its owner.
     *
     * @return int How many ran
     */
    public function runDue(int $now): int
    {
        $result = $this->table()->where('is_active', true)->where('next_run_at', '<=', CallLog::instant($now))
            ->orderBy('next_run_at')->limit(self::BATCH)->get();
        $due = [];

        while (($row = $result->fetch()) !== null) {
            $due[] = self::shape($row);
        }

        foreach ($due as $routine) {
            $this->run($routine, $now);
        }

        return count($due);
    }

    /**
     * Run one now, whatever its schedule — and move its next run on either way.
     *
     * @param array<string, mixed> $routine As {@see find()} answers
     * @return array{ok: bool, says: string, conversation_id: int|null}
     */
    public function run(array $routine, int $now): array
    {
        $scope     = new Scope((int) $routine['userid'], $routine['organization_id'], (string) ($routine['space'] ?? Scope::CHAT));
        $assistant = $this->chat->assistant();
        $chat      = $this->chat->as($scope, true);
        $question  = 'Scheduled task "' . $routine['title'] . '", run on ' . gmdate('l j F Y', $now) . ": \n\n" . $routine['prompt'];

        $answer = ($why = $assistant->routineRefusal($scope)) !== null
            ? ['ok' => false, 'says' => $why, 'why' => 'limit', 'conversation_id' => null]
            : $assistant->acting($scope, static function () use ($chat, $routine, $question): array {
                $answer = $chat->ask($routine['conversation_id'], $question);

                // A conversation its owner deleted is started again, not an error for ever.
                return !$answer['ok'] && $answer['why'] === 'missing' ? $chat->ask(null, $question) : $answer;
            });

        $this->scoped($scope, (int) $routine['routine_id'])->update([
            'last_run_at'     => CallLog::instant($now),
            'last_error'      => $answer['ok'] ? null : mb_substr((string) $answer['says'], 0, 500),
            'conversation_id' => $answer['conversation_id'] ?? $routine['conversation_id'],
            'next_run_at'     => CallLog::instant(self::nextRun($routine['cadence'], $routine['at'], $routine['day'], $routine['timezone'], $now)),
            'updated_at'      => CallLog::instant(time()),
        ]);

        $conversationId = $answer['conversation_id'] ?? $routine['conversation_id'];
        $this->tell($scope->userId, (string) $routine['title'], (bool) $answer['ok'], (string) $answer['says'], $conversationId);

        return ['ok' => (bool) $answer['ok'], 'says' => (string) $answer['says'], 'conversation_id' => $conversationId];
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

    /** @return array{at: string, day: int|null, timezone: string}|string */
    private static function schedule(string $cadence, string $at, mixed $day, string $timezone): array|string
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($at), $time)) {
            return 'The time is HH:MM, for example 08:00.';
        }

        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
        $day      = $day === null || $day === '' ? null : (int) $day;

        if ($cadence === 'weekly' && ($day === null || $day < 1 || $day > 7)) {
            return 'Weekly needs a day, 1 (Monday) to 7 (Sunday).';
        }

        if ($cadence === 'monthly' && ($day === null || $day < 1 || $day > 28)) {
            return 'Monthly needs a day of the month, 1 to 28.';
        }

        return ['at' => sprintf('%02d:%02d', (int) $time[1], (int) $time[2]), 'day' => $cadence === 'daily' ? null : $day, 'timezone' => $timezone];
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
}
