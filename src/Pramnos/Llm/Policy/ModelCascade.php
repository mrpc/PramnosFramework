<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Client;
use Pramnos\Llm\Connection;

/**
 * The fast tier decides which tier a question needs: one short call, answered as
 * `{"tier": "fast"|"frontier"}` ({@see Client::askForJson()}), on the question and the last few
 * messages before it.
 *
 * ```php
 * 'llm' => [
 *     'features' => ['chat' => 'Ask AI', 'chat_frontier' => 'Ask AI, for the hard questions'],
 *     'tiers'    => ['fast' => 'chat', 'frontier' => 'chat_frontier'],
 *     'cascade'  => \Pramnos\Llm\Policy\ModelCascade::class,
 * ],
 * ```
 *
 * A decision that cannot be had — the fast tier down, an answer in no tier — is null: the
 * question goes to the assistant's own connection, as with no cascade at all. Override
 * {@see guidance()} to say what needs the frontier in this application.
 */
class ModelCascade implements Cascade
{
    /** How many messages before the question the decision reads. */
    public const HISTORY = 4;

    public function __construct(private readonly ?Client $client = null)
    {
    }

    /**
     * @param array<string, mixed>                       $context
     * @param list<array{role: string, content: string}> $history
     */
    public function tier(array $context, string $question, array $history): ?string
    {
        $tiers = (array) \Pramnos\Llm\Llm::config('tiers', []);
        if (!isset($tiers['fast'], $tiers['frontier'])) {
            return null;
        }

        $client = $this->client ?? new Client(new Connection(null, (string) $tiers['fast']));
        $recent = array_map(
            static fn (array $m): string => $m['role'] . ': ' . mb_substr((string) $m['content'], 0, 1000),
            array_slice($history, -self::HISTORY)
        );
        $answer = $client->about(['purpose' => 'cascade'] + array_intersect_key($context, ['userid' => true, 'organization_id' => true]))->askForJson(
            'You decide which model answers a question. ' . $this->guidance() . ' Answer with the tier only.',
            [['role' => 'user', 'content' => ($recent === [] ? '' : "Before it:\n" . implode("\n", $recent) . "\n\n") . 'The question: ' . mb_substr($question, 0, 4000)]],
            ['type' => 'object', 'properties' => ['tier' => ['type' => 'string', 'enum' => ['fast', 'frontier']]], 'required' => ['tier']],
            'choose_tier'
        );

        return ($answer['ok'] ?? false) ? (string) $answer['data']['tier'] : null;
    }

    /** What each tier is for, as the deciding model is told. */
    protected function guidance(): string
    {
        return 'fast: routine questions, looking things up, figures, short or simple tasks. '
            . 'frontier: long reasoning or analysis over many steps, delicate personal or emotional conversation, '
            . 'and writing where the wording matters. When unsure, fast.';
    }
}
