<?php

declare(strict_types=1);

namespace Pramnos\Llm\Eval;

use Pramnos\Llm\Client;

/**
 * Questions with the tool calls a good answer makes, asked of a model and scored — so a change
 * to a prompt, a model or a tool is measured before it ships, not after.
 *
 * A fixture is a JSON file, one case each, or a list of cases:
 *
 * ```json
 * {
 *   "name": "orders this month",
 *   "user_query": "How many orders did we get this month?",
 *   "available_tools": ["count_orders", "list_users"],
 *   "tool_results": {"count_orders": "{\"count\": 42}"},
 *   "expected_tool_calls": ["count_orders"],
 *   "expected_arguments": {"count_orders": {"period": "this_month"}},
 *   "expected_final_state": {"answer_contains": ["42"]}
 * }
 * ```
 *
 * `available_tools` names tools of the set given to {@see run()} — the assistant's own, with their
 * real descriptions and schemas — or defines them in place (`{"name": {"description", "parameters"}}`).
 * Either way no tool runs: each answers its `tool_results` entry, or `ok`, so a case reads
 * nothing and changes nothing, and gives the same results every time.
 *
 * | Score, per case | |
 * |---|---|
 * | `tool_accuracy` | the expected tools called, over the larger of expected and called (by name): a call nobody expected costs as much as one missing |
 * | `argument_fidelity` | the expected arguments the best call of each tool had, over all of them; `"Ann"` matches `"ann "`, `"5"` matches `5` |
 * | `final_state` | the answer holds every `answer_contains` text, ignoring case: 1 or 0 |
 * | `rounds` | the rounds the answer took |
 *
 * The suite's {@see Report} is their mean, compared with a baseline by {@see Report::regressions()}.
 */
final class Suite
{
    /** @var list<array<string, mixed>> */
    private array $cases = [];

    /** @param list<array<string, mixed>> $cases */
    public function __construct(array $cases = [])
    {
        foreach ($cases as $case) {
            $this->add($case);
        }
    }

    /** Every `*.json` in a directory, in name order — but a baseline ({@see Report::toArray()}) kept there. */
    public static function fromDirectory(string $directory): self
    {
        $files = glob(rtrim($directory, '/') . '/*.json') ?: [];
        sort($files);
        $suite = new self();
        foreach ($files as $file) {
            $read = json_decode((string) file_get_contents($file), true);
            if (!is_array($read)) {
                throw new \InvalidArgumentException($file . ' is not JSON.');
            }
            // A baseline kept beside the cases is a saved Report, not a case.
            if (isset($read['summary'], $read['cases'])) {
                continue;
            }
            foreach (array_is_list($read) ? $read : [$read] as $at => $case) {
                $suite->add((array) $case + ['name' => basename($file, '.json') . (array_is_list($read) ? '#' . ($at + 1) : '')]);
            }
        }

        return $suite;
    }

    /**
     * One case more.
     *
     * @param array<string, mixed> $case
     */
    public function add(array $case): self
    {
        if (trim((string) ($case['user_query'] ?? '')) === '') {
            throw new \InvalidArgumentException('An eval case needs a user_query' . (isset($case['name']) ? ' (' . $case['name'] . ')' : '') . '.');
        }
        $this->cases[] = $case + ['name' => 'case ' . (count($this->cases) + 1)];

        return $this;
    }

    /** How many cases there are. */
    public function count(): int
    {
        return count($this->cases);
    }

    /**
     * Ask every case and score it.
     *
     * @param array<string, array<string, mixed>> $tools The tools a case names, as the client takes them
     * @param (callable(): Client)|null           $client A fresh client per case; null for the default connection
     */
    public function run(string $systemPrompt, array $tools = [], ?callable $client = null, int $rounds = 6): Report
    {
        $scores = [];
        foreach ($this->cases as $case) {
            $scores[] = $this->score($case, $systemPrompt, $tools, $client, $rounds);
        }

        return new Report($scores);
    }

    /**
     * One case, asked and scored.
     *
     * @param array<string, mixed>                $case
     * @param array<string, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private function score(array $case, string $systemPrompt, array $tools, ?callable $client, int $rounds): array
    {
        $answer = ($client !== null ? $client() : new Client())->askWithTools(
            (string) ($case['system'] ?? $systemPrompt),
            [['role' => 'user', 'content' => (string) $case['user_query']]],
            self::offered($case, $tools),
            $rounds
        );

        $calls    = (array) ($answer['calls'] ?? []);
        $called   = array_values(array_unique(array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $calls)));
        $expected = array_values(array_unique(array_map('strval', (array) ($case['expected_tool_calls'] ?? []))));
        $widest   = max(count($expected), count($called));
        $text     = mb_strtolower((string) ($answer['reply'] ?? ''));
        $wanted   = (array) ($case['expected_final_state']['answer_contains'] ?? []);
        $missing  = array_values(array_filter($wanted, static fn ($w): bool => !str_contains($text, mb_strtolower(trim((string) $w)))));

        return [
            'name'              => (string) $case['name'],
            'ok'                => !empty($answer['ok']),
            'says'              => (string) ($answer['says'] ?? ''),
            'called'            => $called,
            'tool_accuracy'     => $widest === 0 ? 1.0 : (float) (count(array_intersect($expected, $called)) / $widest),
            'argument_fidelity' => self::fidelity((array) ($case['expected_arguments'] ?? []), $calls),
            'final_state'       => !empty($answer['ok']) && $missing === [] ? 1.0 : 0.0,
            'rounds'            => (int) ($answer['rounds'] ?? 0),
        ];
    }

    /**
     * The case's tools, each answering its canned result instead of running.
     *
     * @param array<string, mixed>                $case
     * @param array<string, array<string, mixed>> $tools
     * @return array<string, array<string, mixed>>
     */
    private static function offered(array $case, array $tools): array
    {
        $named   = (array) ($case['available_tools'] ?? []);
        $defined = array_is_list($named) ? array_intersect_key($tools, array_flip(array_map('strval', $named))) : $named;
        if (array_is_list($named) && count($defined) < count($named)) {
            throw new \InvalidArgumentException('Case ' . $case['name'] . ' names tools not given to the suite: '
                . implode(', ', array_diff($named, array_keys($defined))) . '.');
        }

        $results = (array) ($case['tool_results'] ?? []);
        $out     = [];
        foreach ($defined as $name => $tool) {
            $result     = $results[$name] ?? 'ok';
            $out[$name] = [
                'description' => (string) ($tool['description'] ?? ''),
                'parameters'  => (array) ($tool['parameters'] ?? []),
                'run'         => static fn (): string => is_string($result) ? $result : (string) json_encode($result),
            ];
        }

        return $out;
    }

    /**
     * The share of expected arguments the best call of each tool had.
     *
     * @param array<string, array<string, mixed>> $expected
     * @param list<array<string, mixed>>          $calls
     */
    private static function fidelity(array $expected, array $calls): float
    {
        $total = 0;
        $had   = 0;
        foreach ($expected as $tool => $arguments) {
            $arguments = (array) $arguments;
            $total    += count($arguments);
            $best      = 0;
            foreach ($calls as $call) {
                if (($call['name'] ?? '') !== $tool) {
                    continue;
                }
                $given = (array) ($call['arguments'] ?? []);
                $best  = max($best, count(array_filter(
                    array_keys($arguments),
                    static fn ($key): bool => array_key_exists($key, $given) && self::same($arguments[$key], $given[$key])
                )));
            }
            $had += $best;
        }

        return $total === 0 ? 1.0 : (float) ($had / $total);
    }

    /** Whether a given argument is the expected one: numbers by value, text ignoring case and the space around it, lists and objects whole. */
    private static function same(mixed $expected, mixed $given): bool
    {
        if (is_numeric($expected) && is_numeric($given)) {
            return (float) $expected === (float) $given;
        }
        if (is_scalar($expected) && is_scalar($given)) {
            return mb_strtolower(trim((string) $expected)) === mb_strtolower(trim((string) $given));
        }

        return $expected == $given;
    }
}
