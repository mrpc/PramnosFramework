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
     * Whether a person may make a task with this owner: their own (`user`) always; one their
     * organisation shares (`organization`) at the administration level, inside an organisation;
     * one for the whole installation (`global`) only as one of its administrators.
     */
    public function canManage(Scope $scope, string $owner): bool
    {
        if ($owner === Routines::OWNER_USER) {
            return true;
        }
        $type = $this->usertype($scope->userId);

        return $owner === Routines::OWNER_ORGANIZATION
            ? $scope->organizationId !== null && $type >= 80
            : $owner === Routines::OWNER_GLOBAL && $type >= 90;
    }

    /**
     * Whether a person may read a shared task's runs, and so subscribe to it.
     *
     * A report runs with its maker's rights ({@see scope()}), so whoever reads it sees what they
     * see. By default, then, strictly: a member of its organisation ({@see member()}) of the
     * maker's standing or more — unless the maker chose its readers (`audience`): everyone from a
     * usertype up, and people by name, still inside the organisation. One person's task is theirs
     * alone. An application with roles says
     * here who may — the readers of the site the report is about, say.
     *
     * @param array<string, mixed> $task
     */
    public function canSee(int $userId, array $task): bool
    {
        if ((int) $task['userid'] === $userId) {
            return true;
        }
        // A report carries what was read with its maker's rights: never to somebody the space's
        // screen is closed to, whatever its audience says.
        if ($this->usertype($userId) < $this->readerFloor($task)) {
            return false;
        }
        if (($task['owner'] ?? Routines::OWNER_USER) === Routines::OWNER_USER) {
            return false;
        }
        if (($task['owner'] ?? '') === Routines::OWNER_ORGANIZATION && !$this->member($userId, $task['organization_id'])) {
            return false;
        }

        // Its maker chose who: everyone from a usertype up, and named people.
        $audience = $task['audience'] ?? null;
        if (is_array($audience)) {
            return in_array($userId, $audience['users'] ?? [], true)
                || (($audience['min_usertype'] ?? null) !== null && $this->usertype($userId) >= (int) $audience['min_usertype']);
        }

        return $this->usertype($userId) >= $this->usertype((int) $task['userid']);
    }

    /**
     * The lowest usertype that may read a task someone else made: the analyst's floor in the
     * administrators' space — `'llm' => ['analyst' => ['floor' => …]]`, or the administration's
     * default — and nobody's in Ask AI. An application whose screens are gated otherwise answers
     * here.
     *
     * @param array<string, mixed> $task
     */
    public function readerFloor(array $task): int
    {
        return ($task['space'] ?? Scope::CHAT) === Scope::ADMIN && !self::reportsBelowFloor() ? self::analystFloor() : 0;
    }

    /**
     * Whether the application lets analyst reports reach people below the analyst's floor —
     * `'llm' => ['analyst' => ['reports_below_floor' => true]]`, off by default. They read them
     * read only: they cannot open Ask to ask about them.
     */
    public static function reportsBelowFloor(): bool
    {
        $analyst = \Pramnos\Llm\Llm::config('analyst', []);

        return is_array($analyst) && !empty($analyst['reports_below_floor']);
    }

    /** The analyst's floor: `analyst.floor` where the application sets it, else the administration's default. */
    public static function analystFloor(): int
    {
        $analyst = \Pramnos\Llm\Llm::config('analyst', []);
        $floor   = is_array($analyst) ? (int) ($analyst['floor'] ?? 0) : 0;

        return $floor > 0 ? $floor : \Pramnos\Auth\AdminAccess::defaultUsertype();
    }

    /**
     * The people a report's maker names as its readers, by username or email: `name => userid`
     * for each one found. An application whose people are found otherwise answers here.
     *
     * @param list<string> $names
     * @return array<string, int>
     */
    public function audienceUsers(array $names): array
    {
        $found  = [];
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['userid', 'username', 'email'])
            ->where(static fn ($q) => $q->whereIn('username', $names)->whereIn('email', $names, 'or'))
            ->get();
        while (($row = $result->fetch()) !== null) {
            foreach ([(string) $row['username'], (string) $row['email']] as $name) {
                if (in_array($name, $names, true)) {
                    $found[$name] = (int) $row['userid'];
                }
            }
        }

        return $found;
    }

    /**
     * How a report's named readers are shown back to its maker: `userid => username`.
     *
     * @param list<int> $userIds
     * @return array<int, string>
     */
    public function audienceNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $names  = [];
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['userid', 'username'])->whereIn('userid', $userIds)->get();
        while (($row = $result->fetch()) !== null) {
            $names[(int) $row['userid']] = (string) $row['username'];
        }

        return $names;
    }

    /**
     * Whether a person belongs to an organisation. True by default — the framework does not know
     * an application's memberships — so an application with organisations answers here.
     */
    public function member(int $userId, ?int $organizationId): bool
    {
        return true;
    }

    /**
     * Who is told about a run: its maker, and for a shared report each subscriber who may still
     * read it — checked at every run, so a reader who lost the right stops receiving it.
     *
     * @param array<string, mixed> $task
     * @return list<int>
     */
    public function audience(array $task): array
    {
        $audience = [(int) $task['userid']];
        foreach ($task['subscribers'] ?? [] as $userId) {
            if (!in_array((int) $userId, $audience, true) && $this->canSee((int) $userId, $task)) {
                $audience[] = (int) $userId;
            }
        }

        return $audience;
    }

    /**
     * What a task is about, as people read it — "GeekDom" rather than "property 42" — for a
     * notification's title and a screen. '' when it is about nothing in particular.
     *
     * @param array<string, mixed> $task
     */
    public function resourceLabel(array $task): string
    {
        return '';
    }

    /**
     * Whether what a task is about still exists. False pauses it and tells its maker, rather
     * than failing at every run; true by default.
     *
     * @param array<string, mixed> $task
     */
    public function resourceExists(array $task): bool
    {
        return true;
    }

    /**
     * Where a run's notification leads: a report's run, opened as a conversation of the reader's
     * own; otherwise the conversation it went into, or the one it was set in.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $run  As {@see Routines::runs()} answers
     */
    public function link(array $task, array $run): string
    {
        if (($task['kind'] ?? '') === Routines::REPORT && !empty($run['run_id'])) {
            // The analyst's reports open in the administration area, as the analyst's conversations do.
            return ($task['space'] ?? Scope::CHAT) === Scope::ADMIN
                ? \Pramnos\Llm\Analyst\AnalystAssistant::areaUrl('Ai/report/' . (int) $run['run_id'])
                : \Pramnos\Http\SiteUrl::to('chat/report/' . (int) $run['run_id']);
        }

        return $this->assistant->conversationUrl($run['conversation_id'] ?? $task['conversation_id'] ?? null);
    }

    /**
     * Who may take over a leaving person's tasks in an organisation, as `userid => name`: its other
     * active members, by default, from the framework's memberships. Nobody outside an
     * organisation, and nobody when there are no memberships to read — then the tasks can only be
     * deleted. An application with its own memberships answers here.
     *
     * @return array<int, string>
     */
    public function successors(?int $organizationId, int $leavingUserId): array
    {
        if ($organizationId === null) {
            return [];
        }
        try {
            $members = (new \Pramnos\Auth\OrganizationAdmin($leavingUserId))->members($organizationId);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Task successors not read: ' . $e->getMessage());

            return [];
        }
        $who = [];
        foreach ($members as $member) {
            if ((int) $member['userid'] !== $leavingUserId) {
                $who[(int) $member['userid']] = $member['name'] !== '' ? $member['name'] : $member['username'];
            }
        }

        return $who;
    }

    /** A person's usertype, 0 for nobody. */
    protected function usertype(int $userId): int
    {
        if ($userId <= 1) {
            return 0;
        }
        $user = new \Pramnos\User\User($userId);

        return (int) ($user->userid ?? 0) === $userId ? (int) ($user->usertype ?? 0) : 0;
    }

    /**
     * After every run, recorded and told: for a channel of the application's own — a message in
     * another chat — or charging. Nothing by default. What it throws is logged, not the run's.
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
