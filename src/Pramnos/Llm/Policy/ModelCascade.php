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

    /**
     * Automatic among the models a person may choose: the cheapest of them reads the question and
     * names one of `$choices` — listed cheapest first, so the dearest it can name is the dearest
     * this person's role allows. Null when it cannot decide; the caller then keeps its own
     * connection.
     *
     * @param array<string, mixed>                       $context
     * @param list<array{role: string, content: string}> $history
     * @param list<array{key: string, name: string, provider: string, model: string, reasoning: bool}> $choices Cheapest first
     */
    public function choose(array $context, string $question, array $history, array $choices): ?string
    {
        if (count($choices) < 2) {
            return null;
        }

        $client = $this->client ?? new Client(\Pramnos\Llm\Chat\ModelChoices::connection($choices[0]));
        $recent = array_map(
            static fn (array $m): string => $m['role'] . ': ' . mb_substr((string) $m['content'], 0, 1000),
            array_slice($history, -self::HISTORY)
        );
        $list = implode("\n", array_map(
            static fn (array $c): string => '- ' . $c['key'] . ': ' . $c['name'] . ' (' . $c['model'] . ($c['reasoning'] ? ', reasons first' : '') . ')',
            $choices
        ));
        $answer = $client->about(['purpose' => 'cascade'] + array_intersect_key($context, ['userid' => true, 'organization_id' => true]))->askForJson(
            'You decide which model answers a question. The models, cheapest and fastest first, dearest and strongest last:' . "\n" . $list
            . "\n" . 'Name the cheapest that will answer it well. ' . $this->guidance() . ' The first models are "fast", the last "frontier". Answer with the key only.',
            [['role' => 'user', 'content' => ($recent === [] ? '' : "Before it:\n" . implode("\n", $recent) . "\n\n") . 'The question: ' . mb_substr($question, 0, 4000)]],
            ['type' => 'object', 'properties' => ['model' => ['type' => 'string', 'enum' => array_column($choices, 'key')]], 'required' => ['model']],
            'choose_model'
        );
        $key = ($answer['ok'] ?? false) ? (string) ($answer['data']['model'] ?? '') : '';

        return in_array($key, array_column($choices, 'key'), true) ? $key : null;
    }

    /** What each tier is for, as the deciding model is told. */
    protected function guidance(): string
    {
        return 'fast: routine questions, looking things up, figures, short or simple tasks. '
            . 'frontier: long reasoning or analysis over many steps, delicate personal or emotional conversation, '
            . 'and writing where the wording matters. When unsure, fast.';
    }
}
