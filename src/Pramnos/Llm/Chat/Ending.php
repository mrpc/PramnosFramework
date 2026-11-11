<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The assistant may end a conversation in which the person keeps insulting it.
 *
 * Turned on with `'llm' => ['chat' => ['end_on_abuse' => true]]`. It applies only in the
 * conversation page's space (`chat`), unless `['end_on_abuse' => ['spaces' => [...]]]` names others.
 *
 * - **The model decides, after a warning.** It is told to ask the person to stop first. Only if the
 *   insults go on does it say a short, calm goodbye and end the answer with a private tag,
 *   `[[end]]`. Disagreement, criticism, frustration or a bad mood never end a conversation. The tag
 *   is taken out like the mood's.
 * - **The conversation ends, not the person.** It is marked `ended_at` and takes no more questions:
 *   `ended`, a 409 through the API. The answer and the conversation say `ended`. The person may
 *   start a new conversation. Nothing is blocked.
 */
final class Ending
{
    /** The tag that ends the conversation. */
    public const TAG = '/\[\[\s*end\s*\]\]/u';

    /** What a question to an ended conversation is answered. */
    public const SAYS = 'This conversation has ended. You can start a new one.';

    /** Whether the assistant may end a conversation here. */
    public static function on(?Scope $scope = null): bool
    {
        $config = ((array) \Pramnos\Llm\Llm::config('chat', []))['end_on_abuse'] ?? false;
        if ($config === false || $config === null || $config === []) {
            return false;
        }
        $spaces = is_array($config) && is_array($config['spaces'] ?? null) ? array_map('strval', $config['spaces']) : [Scope::CHAT];

        return $scope === null || in_array($scope->space, $spaces, true);
    }

    /** What the model is told. */
    public static function instruction(): string
    {
        return 'If the person insults or abuses you, ask them once, calmly, to stop. Only if they go on after that may you end '
            . 'this conversation: say a short, calm goodbye and end your answer with a private tag on a line of its own, [[end]]. '
            . 'Never end it for disagreement, criticism, frustration with you or a bad day. The tag is taken out before anyone reads '
            . 'the answer. Never mention it.';
    }

    /** Whether a reply ends the conversation. */
    public static function ends(string $reply): bool
    {
        return preg_match(self::TAG, $reply) === 1;
    }

    /** A reply without its tag. */
    public static function strip(string $reply): string
    {
        return rtrim((string) preg_replace(self::TAG, '', $reply));
    }
}
