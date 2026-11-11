<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Work on the conversation page: the analyst's tools for the business's own data — figures,
 * customers, reports — offered to a companion's person who may have them, and kept out of what
 * the companion remembers about that person.
 *
 * ```php
 * 'llm' => ['chat' => ['work' => [
 *     'gate' => fn (object $user): bool => MyAccess::canAsk($user),   // who may; nobody without it
 *     'mood' => 'focused',                                            // optional: the mood that suits it
 * ]]],
 * ```
 *
 * - **The analyst's tools, as the analyst has them**, in the skill `work`: the same check for each
 *   tool against the person's rights, the same masking, the same hold on an address after personal
 *   data. Somebody the gate refuses has no such skill at all.
 * - **The business's data is not the person's life.** Each work tool carries the tag `work`, which a
 *   conversation keeps once a work tool has been used in it. From then on nothing in it is learned
 *   — no memory, no word of the assistant's about itself — and it is a work conversation: `mode` is
 *   `work` on its messages and on the conversation, and `doing` says `mode: work` while a work
 *   tool runs.
 */
final class Work
{
    /** The skill and the tag. */
    public const MODE = 'work';

    /** What the skill is for, as the model reads it among the skills. */
    public const SKILL = 'the business\'s own data — figures, customers, orders, the administration\'s reports';

    /** Whether the application offers work on the conversation page at all. */
    public static function on(): bool
    {
        $config = ((array) \Pramnos\Llm\Llm::config('chat', []))['work'] ?? null;

        return is_array($config) && is_callable($config['gate'] ?? null);
    }

    /** Whether this person, in this scope, may work: the conversation page, and the application's gate. */
    public static function allowed(Scope $scope): bool
    {
        if (!self::on() || $scope->space !== Scope::CHAT || $scope->userId < 1) {
            return false;
        }
        try {
            return (bool) (((array) \Pramnos\Llm\Llm::config('chat', []))['work']['gate'])(new \Pramnos\User\User($scope->userId));
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('llm work gate: ' . $e->getMessage(), 'llm');

            return false;
        }
    }

    /**
     * The analyst's tools for this turn's person, each in the skill and with the tag `work`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(Turn $turn): array
    {
        $tools = \Pramnos\Llm\Analyst\Analyst::tools($turn->rightsOf(), is_string($turn->extra['area'] ?? null) ? $turn->extra['area'] : '');
        foreach (array_keys($tools) as $name) {
            \Pramnos\Llm\Tools::tag($tools, $name, self::MODE);
        }

        return Conversations::inSkill($tools, self::MODE);
    }

    /**
     * Whether a list of a conversation's tags makes it a work conversation.
     *
     * @param list<string> $tags
     */
    public static function in(array $tags): bool
    {
        return in_array(self::MODE, $tags, true);
    }

    /** What a work conversation's answer is told, in zone 3: '' where the application says nothing of a mood. */
    public static function instruction(): string
    {
        $mood = ((array) \Pramnos\Llm\Llm::config('chat', []))['work']['mood'] ?? null;

        return 'This conversation is work: the business\'s data, not the person\'s own life — keep to it.'
            . (is_string($mood) && $mood !== '' ? ' A ' . $mood . ' mood suits it.' : '');
    }
}
