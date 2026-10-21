<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Audience\Experiments;

/**
 * The A/B tests: each with its status, variants and results — people, how many reached the goal,
 * the rate, how sure the difference from the first variant is, and how many more it needs.
 */
final class AudienceExperimentsTool extends AudienceTool
{
    public function name(): string
    {
        return 'audience_experiments';
    }

    public function description(): string
    {
        return 'A/B tests: status, variants and results per variant — people, reached the goal, rate, how sure the difference '
            . 'from the first variant is (confidence), and how many more people it needs. Give test for one.';
    }

    protected function arguments(): array
    {
        return ['test' => ['type' => 'integer', 'description' => 'One test by id.']];
    }

    protected function tables(): array
    {
        return ['experiments', 'experiment_assignments'];
    }

    protected function ability(): string
    {
        return 'admin.experiments';
    }

    protected function answer(array $input, Window $window): array
    {
        $describe = static fn (array $t): array => ['id' => $t['id'], 'slug' => $t['slug'], 'name' => $t['name'], 'status' => $t['status'],
            'winner' => $t['winner'], 'goal' => $t['goalEvent'] === null ? null : ['event' => $t['goalEvent'], 'days' => $t['goalDays']],
            'results' => Experiments::results($t['id'])];
        $id = self::id($input, 'test');
        if ($id > 0) {
            $test = Experiments::get($id);

            return $test === null ? ['found' => false] : $describe($test);
        }

        return ['tests' => array_map($describe, Experiments::all())];
    }
}
