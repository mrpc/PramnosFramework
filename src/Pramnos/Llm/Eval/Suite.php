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
 * | `skill_used` | for a case that names an `expected_skill`: 1 when the model called `use_skill` with it, else 0 |
 * | `assertions` | for a case with `assertions` (plain-language checks, as public skill evals write them): the share the model, asked as a judge, finds met — those not met listed in `unmet` |
 *
 * A file may also be in the public Agent Skills shape, `{"evals": [{"id", "prompt", "expected_output",
 * "assertions"}]}`: each becomes a case, `prompt` its `user_query`, `expected_output` shown to the judge.
 *
 * A case left without `available_tools` is offered none — but {@see withTools()} gives such cases
 * every tool of a set, which is how `llm:eval --skills` asks a skill's cases with the application's
 * own tools, grouped into skills. A tool that adds tools (`use_skill`) runs for real, so a skill
 * turned on hands over its instructions; the tools it adds answer their canned results.
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
            // The public Agent Skills shape: {"evals": [{"id", "prompt", "expected_output", "assertions"}]}.
            if (isset($read['evals']) && is_array($read['evals'])) {
                foreach (array_values($read['evals']) as $at => $one) {
                    $one = (array) $one;
                    $suite->add(array_filter([
                        'name'            => isset($one['id']) ? basename($file, '.json') . '#' . $one['id'] : basename($file, '.json') . '#' . ($at + 1),
                        'user_query'      => (string) ($one['prompt'] ?? $one['user_query'] ?? ''),
                        'expected_output' => $one['expected_output'] ?? null,
                        'assertions'      => isset($one['assertions']) ? array_values(array_map('strval', (array) $one['assertions'])) : null,
                    ], static fn ($v): bool => $v !== null) + $one);
                }
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

    /**
     * The same cases, those that name no `available_tools` given every one of these, and — when a
     * skill is named — those that name no `expected_skill` expected to turn it on.
     *
     * @param list<string> $tools
     */
    public function withTools(array $tools, ?string $skill = null): self
    {
        $suite = new self();
        foreach ($this->cases as $case) {
            if (!array_key_exists('available_tools', $case)) {
                $case['available_tools'] = $tools;
            }
            if ($skill !== null && !array_key_exists('expected_skill', $case)) {
                $case['expected_skill'] = $skill;
            }
            $suite->cases[] = $case;
        }

        return $suite;
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

        $scores = [
            'name'              => (string) $case['name'],
            'ok'                => !empty($answer['ok']),
            'says'              => (string) ($answer['says'] ?? ''),
            'called'            => $called,
            'tool_accuracy'     => $widest === 0 ? 1.0 : (float) (count(array_intersect($expected, $called)) / $widest),
            'argument_fidelity' => self::fidelity((array) ($case['expected_arguments'] ?? []), $calls),
            'final_state'       => !empty($answer['ok']) && $missing === [] ? 1.0 : 0.0,
            'rounds'            => (int) ($answer['rounds'] ?? 0),
            // Stopped at the limit of rounds rather than answering: the scores are of a cut answer.
            // — forced to answer in its last round, or refused still calling tools.
            'out_of_rounds'     => !empty($answer['out_of_rounds']) || (empty($answer['ok']) && $calls !== []),
        ];
        // Assertions in plain words, as public skill evals write them: graded by the model as a judge.
        $assertions = array_values(array_filter(array_map('strval', (array) ($case['assertions'] ?? [])), static fn (string $a): bool => trim($a) !== ''));
        if ($assertions !== []) {
            $met = self::judge($case, $assertions, (string) ($answer['reply'] ?? ''), $client);
            $scores['assertions'] = (float) (count(array_filter($met)) / count($assertions));
            $scores['unmet']      = array_values(array_intersect_key($assertions, array_filter($met, static fn (bool $m): bool => !$m)));
        }

        // Whether the model turned on the skill a question needs: a skill never turned on is text
        // nobody reads. Scored only for a case that names one.
        if (is_string($case['expected_skill'] ?? null) && $case['expected_skill'] !== '') {
            $used = array_filter($calls, static fn (array $c): bool => ($c['name'] ?? '') === 'use_skill'
                && (string) (((array) ($c['arguments'] ?? []))['skill'] ?? '') === $case['expected_skill']);
            $scores['skill_used'] = $used !== [] ? 1.0 : 0.0;
        }

        return $scores;
    }

    /**
     * Which assertions an answer meets, by asking the model as a judge — one call for all of a
     * case's assertions, each answered true or false. An answer the judge could not give meets none.
     *
     * @param array<string, mixed> $case
     * @param list<string>         $assertions
     * @return list<bool>
     */
    private static function judge(array $case, array $assertions, string $reply, ?callable $client): array
    {
        $numbered = implode("\n", array_map(static fn (int $i, string $a): string => ($i + 1) . '. ' . $a, array_keys($assertions), $assertions));
        $asked    = 'The question:' . "\n" . $case['user_query'] . "\n\n"
            . (isset($case['expected_output']) && is_string($case['expected_output']) && $case['expected_output'] !== '' ? 'What a good answer looks like:' . "\n" . $case['expected_output'] . "\n\n" : '')
            . 'The answer given:' . "\n" . ($reply !== '' ? $reply : '(no answer)') . "\n\n"
            . 'The assertions:' . "\n" . $numbered;
        $answer = ($client !== null ? $client() : new Client())->askForJson(
            'You grade an answer against assertions. For each numbered assertion, in order, say whether the answer given meets it: true or false. Be strict: an assertion the answer only hints at is not met.',
            [['role' => 'user', 'content' => $asked]],
            ['type' => 'object', 'properties' => ['met' => ['type' => 'array', 'items' => ['type' => 'boolean']]], 'required' => ['met']],
            'grade'
        );
        $met = is_array($answer['data']['met'] ?? null) ? array_values($answer['data']['met']) : [];

        return array_map(static fn (int $i): bool => ($met[$i] ?? false) === true, array_keys($assertions));
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

        return self::canned($defined, (array) ($case['tool_results'] ?? []));
    }

    /**
     * Tools that answer their canned result instead of running — but a tool that adds tools
     * (`use_skill`, through `expands`) runs as it is, unless the case gives its result, so a skill
     * turned on hands over its instructions; the tools it adds are canned in turn.
     *
     * @param array<string, array<string, mixed>> $tools
     * @param array<string, mixed>                $results
     * @return array<string, array<string, mixed>>
     */
    private static function canned(array $tools, array $results): array
    {
        $out = [];
        foreach ($tools as $name => $tool) {
            $expands    = $tool['expands'] ?? null;
            $real       = is_callable($expands) && !array_key_exists($name, $results) && is_callable($tool['run'] ?? null);
            $result     = $results[$name] ?? 'ok';
            $out[$name] = [
                'description' => (string) ($tool['description'] ?? ''),
                'parameters'  => (array) ($tool['parameters'] ?? []),
                'run'         => $real ? $tool['run'] : static fn (): string => is_string($result) ? $result : (string) json_encode($result),
            ];
            if (is_callable($expands)) {
                $out[$name]['expands'] = static fn (array $arguments): array => self::canned((array) $expands($arguments), $results);
            }
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
