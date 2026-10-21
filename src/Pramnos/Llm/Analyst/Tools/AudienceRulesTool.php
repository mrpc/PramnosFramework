<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Audience\Rules\Rules;

/**
 * The rules: each with its status, trigger in words, how many came in over seven days, how many
 * are on their way and how many reached the goal; or one rule with its definition, how many went
 * through each step and what came of it. Counts only — never a person.
 */
final class AudienceRulesTool extends AudienceTool
{
    public function name(): string
    {
        return 'audience_rules';
    }

    public function description(): string
    {
        return 'Automation rules (when → who → what → until): status, trigger, entered over 7 days, under way, goal reached. '
            . 'Give rule for one with its definition and how many went through each step. Counts only.';
    }

    protected function arguments(): array
    {
        return ['rule' => ['type' => 'integer', 'description' => 'One rule by id, with its steps and counts.']];
    }

    protected function tables(): array
    {
        return ['rules', 'rule_runs', 'rule_log'];
    }

    protected function ability(): string
    {
        return 'admin.rules';
    }

    protected function answer(array $input, Window $window): array
    {
        $describe = static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'version' => $r['version'],
            'when' => \Pramnos\Html\RuleCanvas::trigger($r['definition']['trigger'])] + Rules::figures($r['id']);
        $id = self::id($input, 'rule');
        if ($id > 0) {
            $rule = Rules::get($id);

            return $rule === null ? ['found' => false] : $describe($rule) + ['definition' => $rule['definition'],
                'steps' => \Pramnos\Html\RuleCanvas::counts($id)];
        }

        return ['rules' => array_map($describe, Rules::all())];
    }
}
