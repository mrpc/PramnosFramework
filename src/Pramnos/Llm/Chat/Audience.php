<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Whom an answer is for — a child, or an adult — and what follows from it, in code, not as a rule
 * in the prompt:
 *
 * - **skills** say whom they are for in their frontmatter (`audience: child | adult | all`, and
 *   `age: 3-7` for a child's); the catalogue the model sees holds only those for this audience
 *   and age ({@see allows()});
 * - **tools** for adults (`'audience' => 'adult'` in a tool's metadata — the plan, follow-ups, the
 *   work mode's) are not loaded at all for a child ({@see forTools()});
 * - **what is said to a child** keeps to an allowlist ({@see rules()});
 * - **what the prompt carries** for a child is the child's to hear: memories of {@see CHILD_TOPICS}
 *   only, and none of the grown-up's plan, pinned artifacts, follow-ups, diary, profile, calendar
 *   or organisation's memory — left out where the context is put together, in one place.
 *
 * Who is speaking comes from the request (`speaker`, `{kind: child|parent|…, name, age}`), or from
 * the application: `'llm' => ['chat' => ['audience' => fn (Scope $scope, ?array $speaker): string]]`
 * — which is where a profile, once there are profiles, says it.
 */
final class Audience
{
    public const CHILD = 'child';

    public const ADULT = 'adult';

    public const EVERYONE = 'all';

    /** What a skill may say it is for. */
    public const ALL = [self::CHILD, self::ADULT, self::EVERYONE];

    /**
     * The memories a child's answer may be told: who the account's people are, their relations,
     * their interests, how they like to talk — never the plans, the work, the moods, the values or
     * the goals of the grown-up whose account it is.
     */
    public const CHILD_TOPICS = ['identity', 'relationships', 'interests', 'talk'];

    /** What may be said to a child, unless the application words it otherwise (`chat.child_rules`). */
    public const RULES = 'You are speaking with a child. Whatever you say keeps to what is safe to bring up with them: their name, '
        . 'age, brothers and sisters, pets, favourite things, good things that happened lately, and places. Never bring up fears '
        . '(unless they are overcome and the child is proud of it), illness, quarrels, the parents\' money or work, or anything '
        . 'marked as sensitive — even if you remember it. No moralising: be warm, short and playful.';

    /**
     * Whom an answer is for: the application's word, else the speaker's kind — a child is a child,
     * anybody else an adult.
     *
     * @param array<string, mixed>|null $speaker
     */
    public static function of(?Scope $scope, ?array $speaker): string
    {
        $hook = ((array) \Pramnos\Llm\Llm::config('chat', []))['audience'] ?? null;
        if (is_callable($hook) && $scope !== null) {
            $said = (string) $hook($scope, $speaker);

            return $said === self::CHILD ? self::CHILD : self::ADULT;
        }

        return ($speaker['kind'] ?? null) === self::CHILD ? self::CHILD : self::ADULT;
    }

    /**
     * Whether a skill is for this audience — and, for a child with an age known, for that age.
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
        if ($audience !== self::CHILD || $age === null || preg_match('/^(\d+)\s*-\s*(\d+)$/', $range, $m) !== 1) {
            return true;
        }

        return $age >= (int) $m[1] && $age <= (int) $m[2];
    }

    /**
     * The tools for this audience: for a child, none of those marked for adults.
     *
     * @param array<string, array<string, mixed>> $tools
     * @return array<string, array<string, mixed>>
     */
    public static function forTools(array $tools, string $audience): array
    {
        return $audience === self::CHILD ? array_filter($tools, static fn (array $tool): bool => ($tool['audience'] ?? null) !== self::ADULT) : $tools;
    }

    /** The tools of a capability marked as an adult's. @param array<string, array<string, mixed>> $tools @return array<string, array<string, mixed>> */
    public static function adults(array $tools): array
    {
        return array_map(static fn (array $tool): array => ['audience' => self::ADULT] + $tool, $tools);
    }

    /**
     * The memories a child's answer may carry: those of {@see CHILD_TOPICS}; every one for an adult.
     *
     * @param list<array<string, mixed>> $memories
     * @return list<array<string, mixed>>
     */
    public static function memories(array $memories, string $audience): array
    {
        return $audience !== self::CHILD ? $memories
            : array_values(array_filter($memories, static fn (array $m): bool => in_array($m['topic'] ?? null, self::CHILD_TOPICS, true)));
    }

    /** What may be said to a child: the application's words, or {@see RULES}. */
    public static function rules(): string
    {
        $own = ((array) \Pramnos\Llm\Llm::config('chat', []))['child_rules'] ?? null;

        return is_string($own) && trim($own) !== '' ? trim($own) : self::RULES;
    }
}
