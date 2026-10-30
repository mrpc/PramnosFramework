<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Llm\Chat\Artifacts;

/**
 * A task handed to a model of its own: its own instructions, its own tools, its own context —
 * and back, always, a {@see SubagentResult}.
 *
 * ```php
 * $research = new Subagent(
 *     'You compare suppliers. Read their price lists and say which is cheapest for each item.',
 *     tools: $supplierTools,
 *     rounds: 8,
 *     client: (new Client(new Connection(null, 'research')))->withTurnBudget(maxCost: 0.10),
 *     artifacts: $artifacts,
 * );
 *
 * $result = $research->run('Prices for 200 m of cable and 40 sockets');
 * $tools  = $assistantTools + ['research_suppliers' => $research->asTool('Compare the suppliers for a list of items')];
 * ```
 *
 * **Its context is its own.** It starts from its instructions and the task, nothing of the
 * conversation that asked; what it reads stays with it. The parent reads only
 * {@see SubagentResult::forParent()}: the summary and the titles of what it kept.
 *
 * **It never throws.** An error anywhere — its client, its tools, keeping an artifact — comes back
 * as `failed` with a summary that says what it had done. A parent does not need a `try`.
 *
 * **Its artifacts are drafts until it finishes.** With `$artifacts`, it gets `save_artifact`,
 * checked as it is called and kept only when the task ends in `success`. A task that ran out
 * of rounds or budget, or failed, hands them back as drafts, for somebody to confirm.
 *
 * Give it a {@see Client} of its own, not the parent's: the parent's is in the middle of an
 * answer while this one runs. The rounds and the turn budget are the subagent's.
 */
final class Subagent
{
    /**
     * @param array<string, array<string, mixed>> $tools As {@see Client::askWithTools()} takes them
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $tools = [],
        private readonly int $rounds = 6,
        private readonly ?Client $client = null,
        private readonly ?Artifacts $artifacts = null
    ) {
    }

    /** Do the task; whatever happens, say how it went. */
    public function run(string $task): SubagentResult
    {
        $drafts = [];
        $answer = [];

        try {
            $answer = ($this->client ?? new Client())->askWithTools(
                $this->instructions,
                [['role' => 'user', 'content' => $task]],
                $this->tools + $this->draftTool($drafts),
                $this->rounds
            );
            [$status, $reason] = self::outcome($answer);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::logError('Subagent failed: ' . $why->getMessage(), $why);
            [$status, $reason] = [SubagentResult::FAILED, 'tool_error'];
            $answer += ['says' => 'It stopped on an error: ' . $why->getMessage()];
        }

        $committed = [];
        if ($status === SubagentResult::SUCCESS) {
            foreach ($drafts as $at => $draft) {
                try {
                    $made = $this->artifacts?->create($draft);
                } catch (\Throwable $why) {
                    \Pramnos\Logs\Logger::logError('Subagent could not keep an artifact: ' . $why->getMessage(), $why);
                    $made = null;
                }
                if (is_array($made)) {
                    $committed[] = $made;
                    unset($drafts[$at]);
                }
            }
        }

        $usage = (array) ($answer['usage'] ?? []);

        return new SubagentResult(
            $status,
            $reason,
            self::summary($status, $answer),
            $committed,
            array_values($drafts),
            (int) ($usage['prompt_tokens'] ?? 0) + Pricing::outputTokens($usage),
            (int) ($answer['rounds'] ?? 0)
        );
    }

    /**
     * The subagent as one tool of a parent's: the parent says the task, and reads back the
     * summary and what was kept. It writes, or reads somebody else's words, when one of its tools
     * does — so the parent's gate and held writes treat it as they would that tool.
     *
     * @return array<string, mixed>
     */
    public function asTool(string $description): array
    {
        $flags = [];
        foreach (['writes', 'untrusted', 'gated'] as $flag) {
            if (array_filter($this->tools, static fn (array $tool): bool => !empty($tool[$flag])) !== []) {
                $flags[$flag] = true;
            }
        }

        return [
            'description' => $description,
            'parameters'  => Tools::schema([
                'properties' => ['task' => ['type' => 'string', 'description' => 'The task, whole: it sees nothing else of this conversation.']],
                'required'   => ['task'],
            ]),
            'run'         => fn (array $arguments): string => $this->run((string) ($arguments['task'] ?? ''))->forParent(),
        ] + $flags;
    }

    /**
     * `save_artifact`, as {@see Artifacts::tools()} has it, keeping into `$drafts` instead.
     *
     * @param list<array<string, mixed>> $drafts
     * @return array<string, array<string, mixed>>
     */
    private function draftTool(array &$drafts): array
    {
        $save = $this->artifacts?->tools()['save_artifact'] ?? null;
        if ($save === null) {
            return [];
        }

        return ['save_artifact' => [
            'description' => $save['description'] . ' It is saved when you finish the task; one you do not finish leaves it a draft.',
            'parameters'  => $save['parameters'],
            'run'         => static function (array $arguments) use (&$drafts): string {
                if (($wrong = Artifacts::check($arguments)) !== null) {
                    return 'Error: ' . $wrong;
                }
                $drafts[] = $arguments;

                return 'Drafted "' . $arguments['title'] . '". It is saved when you finish.';
            },
        ]];
    }

    /**
     * Status and reason from the client's answer.
     *
     * @param array<string, mixed> $answer
     * @return array{0: string, 1: string}
     */
    private static function outcome(array $answer): array
    {
        return match (true) {
            !empty($answer['cancelled'])     => [SubagentResult::FAILED, 'cancelled'],
            empty($answer['ok'])             => [SubagentResult::FAILED, 'provider_error'],
            !empty($answer['over_budget'])   => [SubagentResult::PARTIAL, 'budget_exceeded'],
            !empty($answer['out_of_rounds']) => [SubagentResult::PARTIAL, 'max_rounds_reached'],
            default                          => [SubagentResult::SUCCESS, 'completed'],
        };
    }

    /**
     * Never empty: the answer when there is one, else why not and what it had called.
     *
     * @param array<string, mixed> $answer
     */
    private static function summary(string $status, array $answer): string
    {
        $said = trim((string) ($answer['final'] ?? $answer['reply'] ?? ''));
        if ($status !== SubagentResult::FAILED && $said !== '') {
            return $said;
        }

        $called = array_unique(array_map(static fn (array $call): string => (string) ($call['name'] ?? ''), (array) ($answer['calls'] ?? [])));

        return trim(trim((string) ($answer['says'] ?? '')) ?: 'It gave no answer.')
            . ($called === [] ? ' It had called no tool.' : ' It had called: ' . implode(', ', $called) . '.');
    }
}
