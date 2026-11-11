<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Whom an answer is for, and what follows from it — the mechanism only; what each audience may
 * hear is the application's to say:
 *
 * ```php
 * 'llm' => ['chat' => ['audiences' => [
 *     'child' => [
 *         'rules'     => 'You are speaking with a child. …',          // added to the prompt
 *         'topics'    => ['interests', 'talk'],                       // the memories that pass; all when not said
 *         'leave_out' => ['plan', 'pinned', 'follow_ups', 'diary', 'profile', 'calendar', 'organisation'],
 *         'without'   => ['adult'],                                   // tools marked with these audiences, not loaded
 *     ],
 * ]]],
 * ```
 *
 * - **Who is speaking** comes from the request (`speaker`, `{kind, name, age}`) — a declared
 *   audience by its kind — or from the application,
 *   `'chat' => ['audience' => fn (Scope $scope, ?array $speaker): string]`, which is where a
 *   profile says it. Anybody else is {@see ADULT}, with nothing left out.
 * - **Skills** say whom they are for (`audience: <name> | all`, and `age: 3-7`); the catalogue holds
 *   only those for this audience and age ({@see allows()}).
 * - **Tools** a capability marks (`'audience' => 'adult'`, {@see adults()}) are not loaded for an
 *   audience whose `without` names it ({@see forTools()}).
 * - **The context** leaves out the sources an audience's `leave_out` names, and memories of other
 *   topics than its `topics` ({@see leavesOut()}, {@see memories()}); its `rules` are said in the
 *   prompt ({@see rules()}).
 *
 * An application that declares no audiences keeps what a `child` speaker had before: the
 * {@see RULES} (or its `chat.child_rules`), {@see CHILD_TOPICS}, every source left out and an
 * adult's tools not loaded.
 */
final class Audience
{
    public const CHILD = 'child';

    public const ADULT = 'adult';

    public const EVERYONE = 'all';

    /** The context sources an audience may leave out. */
    public const SOURCES = ['plan', 'pinned', 'follow_ups', 'diary', 'profile', 'calendar', 'organisation'];

    /** A `child` speaker's memory topics, where the application declares no audiences. */
    public const CHILD_TOPICS = ['interests', 'talk'];

    /** A `child` speaker's rules, where the application declares no audiences nor `chat.child_rules`. */
    public const RULES = 'You are speaking with a child. Whatever you say keeps to what is safe to bring up with them: their name, '
        . 'age, brothers and sisters, pets, favourite things, good things that happened lately, and places. Never bring up fears '
        . '(unless they are overcome and the child is proud of it), illness, quarrels, the parents\' money or work, or anything '
        . 'marked as sensitive — even if you remember it. When the child asks about a grown-up\'s things (their diary, plans, '
        . 'reminders, money), never say they do not exist and never make anything up: decline kindly and send them to the grown-up '
        . '("that is something to ask your mum"). Never guess at how a grown-up feels or what they are going through; bring it back '
        . 'to the grown-up and to the child\'s own day. No moralising: be warm, short and playful.';

    /** @return array<string, array<string, mixed>> The application's audiences, by name */
    private static function declared(): array
    {
        $given = ((array) \Pramnos\Llm\Llm::config('chat', []))['audiences'] ?? null;

        return is_array($given) ? array_filter($given, 'is_array') : [];
    }

    /**
     * Whom an answer is for: the application's word, else the speaker's kind where it is an
     * audience — a declared one, or `child` where none is declared — else {@see ADULT}.
     *
     * @param array<string, mixed>|null $speaker
     */
    public static function of(?Scope $scope, ?array $speaker): string
    {
        $hook = ((array) \Pramnos\Llm\Llm::config('chat', []))['audience'] ?? null;
        if (is_callable($hook) && $scope !== null) {
            $said = trim((string) $hook($scope, $speaker));

            return preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $said) === 1 ? $said : self::ADULT;
        }
        // A profile speaking is its kind; a speaker the request names, theirs.
        $kind     = (string) ($speaker['kind'] ?? (Profiles::kindOf($scope) ?? ''));
        $declared = self::declared();

        return $kind !== '' && ($declared !== [] ? isset($declared[$kind]) : $kind === self::CHILD) ? $kind : self::ADULT;
    }

    /**
     * What an audience hears and has: its rules, the memory topics that pass (null for every one),
     * the context sources left out and the tools' audiences not loaded.
     *
     * @return array{rules: string, topics: ?list<string>, leave_out: list<string>, without: list<string>}
     */
    public static function policy(string $audience): array
    {
        $declared = self::declared();
        if (isset($declared[$audience])) {
            $one = $declared[$audience];

            return [
                'rules'     => trim((string) ($one['rules'] ?? '')),
                'topics'    => is_array($one['topics'] ?? null) ? array_values(array_map('strval', $one['topics'])) : null,
                'leave_out' => array_values(array_intersect(array_map('strval', (array) ($one['leave_out'] ?? [])), self::SOURCES)),
                'without'   => array_values(array_map('strval', (array) ($one['without'] ?? []))),
            ];
        }
        if ($declared === [] && $audience === self::CHILD) {
            $own = ((array) \Pramnos\Llm\Llm::config('chat', []))['child_rules'] ?? null;

            return ['rules' => is_string($own) && trim($own) !== '' ? trim($own) : self::RULES, 'topics' => self::CHILD_TOPICS,
                'leave_out' => self::SOURCES, 'without' => [self::ADULT]];
        }

        return ['rules' => '', 'topics' => null, 'leave_out' => [], 'without' => []];
    }

    /**
     * Whether a skill is for this audience — `all`, or its name — and, with an age known and a
     * range given, for that age.
     *
     * @param array<string, mixed> $skill As {@see Skills::load()} answers
     */
    public static function allows(array $skill, string $audience, ?int $age = null): bool
    {
        $for = (string) ($skill['audience'] ?? self::EVERYONE);
        if ($for !== self::EVERYONE && $for !== $audience) {
            return false;
        }
        $range = (string) ($skill['age'] ?? '');
        if ($age === null || preg_match('/^(\d+)\s*-\s*(\d+)$/', $range, $m) !== 1) {
            return true;
        }

        return $age >= (int) $m[1] && $age <= (int) $m[2];
    }

    /**
     * The tools for this audience: none marked with an audience its `without` names.
     *
     * @param array<string, array<string, mixed>> $tools
     * @return array<string, array<string, mixed>>
     */
    public static function forTools(array $tools, string $audience): array
    {
        $without = self::policy($audience)['without'];

        return $without === [] ? $tools : array_filter($tools, static fn (array $tool): bool => !in_array($tool['audience'] ?? null, $without, true));
    }

    /** The tools of a capability marked as an adult's. @param array<string, array<string, mixed>> $tools @return array<string, array<string, mixed>> */
    public static function adults(array $tools): array
    {
        return array_map(static fn (array $tool): array => ['audience' => self::ADULT] + $tool, $tools);
    }

    /** Whether an audience's context leaves a source out ({@see SOURCES}). */
    public static function leavesOut(string $audience, string $source): bool
    {
        return in_array($source, self::policy($audience)['leave_out'], true);
    }

    /**
     * The memories an answer for this audience may carry: those of its topics, every one where it
     * names none.
     *
     * @param list<array<string, mixed>> $memories
     * @return list<array<string, mixed>>
     */
    public static function memories(array $memories, string $audience): array
    {
        $topics = self::policy($audience)['topics'];

        return $topics === null ? $memories
            : array_values(array_filter($memories, static fn (array $m): bool => in_array($m['topic'] ?? null, $topics, true)));
    }

    /** What an audience is told in the prompt; '' for nothing. */
    public static function rules(string $audience = self::CHILD): string
    {
        return self::policy($audience)['rules'];
    }
}
