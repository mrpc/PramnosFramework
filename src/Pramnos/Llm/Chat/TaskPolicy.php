<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Who may have a scheduled AI task, as whom it runs, on whose clock, and what happens around a run.
 *
 * ```php
 * // app/app.php
 * 'llm' => ['chat' => ['task_policy' => \App\Llm\TaskPolicy::class]],
 *
 * final class TaskPolicy extends \Pramnos\Llm\Chat\TaskPolicy
 * {
 *     public function stillAllowed(array $task): bool
 *     {
 *         return Tenant::isMember((int) $task['userid'], (int) $task['organization_id']);
 *     }
 * }
 * ```
 *
 * Every method has a default, so an application overrides only what it has. The defaults keep
 * what the assistant already decides — {@see Assistant::routineRefusal()} and
 * {@see Assistant::timezone()} — so an application that put its rules there needs no change.
 *
 * Constructed with the assistant the tasks run under.
 */
class TaskPolicy
{
    /** Failed runs in a row after which a task pauses, and its owner is told. */
    public const MAX_FAILURES = 3;

    public function __construct(protected readonly Assistant $assistant)
    {
    }

    /** The policy `'llm' => ['chat' => ['task_policy' => …]]` names, this one otherwise. */
    public static function for(Assistant $assistant): self
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['task_policy'] ?? null) && is_subclass_of($chat['task_policy'], self::class)
            ? $chat['task_policy'] : self::class;

        return new $class($assistant);
    }

    /**
     * Why a task of this kind may not be added (`$adding`) or run in this scope, or null.
     *
     * The assistant's {@see Assistant::routineRefusal()}, as before there were kinds — so an
     * assistant that schedules nothing gets no reminders either. A reminder asks no model, so it is
     * asked as a run, never as adding: it does not count against a limit on active tasks. An
     * application that offers reminders where it offers no scheduled questions returns null for
     * them here.
     */
    public function refusal(Scope $scope, string $kind, bool $adding = false): ?string
    {
        return $this->assistant->routineRefusal($scope, $kind === Routines::REMINDER ? false : $adding);
    }

    /**
     * As whom a run asks: its maker, in the organisation and space it was made in. A report an
     * organisation shares runs with these rights, so whoever receives it must be allowed to see
     * what they allow — {@see stillAllowed()} and, for readers, the application's subscription rule.
     *
     * @param array<string, mixed> $task As {@see Routines::find()} answers
     */
    public function scope(array $task): Scope
    {
        return new Scope((int) $task['userid'], $task['organization_id'], (string) ($task['space'] ?? Scope::CHAT));
    }

    /**
     * The clock a task keeps — "every morning at eight" is eight there. The zone it was made in;
     * an application whose task is about a site returns that site's.
     *
     * @param array<string, mixed> $task
     */
    public function timezone(array $task): string
    {
        $zone = (string) ($task['timezone'] ?? '');

        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /**
     * Whether a task may still run: its maker still belongs where it was made, and may still ask
     * there. False pauses it and tells its maker, rather than running on data they may no longer
     * see. True by default — an erased account takes its tasks with it — and an application with
     * organisations checks membership here: a member removed from an organisation otherwise keeps
     * getting runs over its data.
     *
     * @param array<string, mixed> $task
     */
    public function stillAllowed(array $task): bool
    {
        return true;
    }

    /**
     * Who is told about a run. The maker, by default.
     *
     * @param array<string, mixed> $task
     * @return list<int>
     */
    public function audience(array $task): array
    {
        return [(int) $task['userid']];
    }

    /**
     * Where a run's notification leads: the conversation it went into, or the one it was set in.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $run  As {@see Routines::runs()} answers
     */
    public function link(array $task, array $run): string
    {
        return $this->assistant->conversationUrl($run['conversation_id'] ?? $task['conversation_id'] ?? null);
    }

    /**
     * After every run, recorded and told: for a channel of the application's own — a Discord or
     * Slack message — or charging. Nothing by default. What it throws is logged, not the run's.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $run
     */
    public function onRan(array $task, array $run): void
    {
    }

    /** Failed runs in a row after which a task pauses. */
    public function maxFailures(): int
    {
        return self::MAX_FAILURES;
    }
}
