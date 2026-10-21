<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Drafts, never more: a segment is checked and counted and handed back as a link that opens the
 * builder with it filled in — nothing is saved; a rule is saved as a draft, which does nothing
 * until an administrator switches it on, and answers how many would have come in over the last
 * week. Behind the screens' abilities, for the administrator asking.
 */
final class AudienceDraftTool extends AudienceTool
{
    public function name(): string
    {
        return 'audience_draft';
    }

    public function description(): string
    {
        return 'Draft a segment or a rule for the administrator to review. kind=segment: give name, description, filter '
            . '({match: all|any, conditions: [{attribute, op, value}]}) — nothing is saved; answers the count and a link to the builder, filled in. '
            . 'kind=rule: give name and definition ({trigger, entry, start, steps, who, exit, goal, consent, hours, caps}) — saved as a '
            . 'draft that does nothing until switched on; answers its link and how many would have come in over 7 days. '
            . 'Use audience_segments and audience_rules first to see what exists.';
    }

    protected function arguments(): array
    {
        return [
            'kind'        => ['type' => 'string', 'enum' => ['segment', 'rule']],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'A segment\'s purpose.'],
            'filter'      => ['type' => 'object', 'description' => 'A segment\'s filter.'],
            'definition'  => ['type' => 'object', 'description' => 'A rule\'s definition.'],
        ];
    }

    protected function tables(): array
    {
        return ['segments'];
    }

    protected function ability(): string
    {
        return 'admin.segments';
    }

    protected function answer(array $input, Window $window): array
    {
        if ($this->adminId <= 0) {
            return ['available' => false, 'says' => 'Drafts are made for an administrator, in Ask.'];
        }
        $name = trim((string) ($input['name'] ?? ''));
        if (($input['kind'] ?? '') === 'rule') {
            if (!$this->allowed('admin.rules') || !\Pramnos\Audience\Installed::has('rules', 'rule_versions')) {
                return ['available' => false, 'says' => 'Rules are not open to the administrator asking.'];
            }
            $id = \Pramnos\Audience\Rules\Rules::create($name, (array) ($input['definition'] ?? []), $this->adminId);

            return ['saved' => 'draft', 'rule' => $id, 'link' => adminUrl('Rules/edit/') . $id,
                'wouldHaveComeIn7Days' => \Pramnos\Audience\Rules\Tester::simulate($id, 7)['entered'],
                'says' => 'Saved as a draft: it does nothing until an administrator reviews it and switches it on.'];
        }
        $filter = \Pramnos\Audience\Filter::fromArray((array) ($input['filter'] ?? []));
        $draft  = ['name' => $name, 'description' => trim((string) ($input['description'] ?? '')), 'filter' => $filter->toArray()];

        return ['saved' => false, 'count' => $filter->count(),
            'link' => adminUrl('Segments/edit') . '?draft=' . rawurlencode((string) json_encode($draft, JSON_UNESCAPED_UNICODE)),
            'says' => 'Not saved: the link opens the builder with it filled in, for the administrator to save.'];
    }
}
