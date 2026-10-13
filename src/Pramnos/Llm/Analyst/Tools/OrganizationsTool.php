<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Auth\Role;

/**
 * Organisations: how many, active, of which type, created over a window, and the largest by
 * members. An organisation's name is the business's; its members are never named.
 */
final class OrganizationsTool extends AdminTool
{
    public function name(): string
    {
        return 'organizations';
    }

    public function description(): string
    {
        return 'Organisations: totals, active, by type, created per period, and the largest by '
            . 'active members (organisation name and member count — members are never named).';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has('organizations')) {
            return self::absent('organisations');
        }

        $orgs = fn () => $this->table('#PREFIX#organizations');

        return [
            'window'   => $window->describe(),
            'total'    => $orgs()->count(),
            'active'   => $orgs()->where('is_active', true)->count(),
            'by_type'  => $this->countBy($orgs(), 'org_type'),
            'created'  => $window->apply($orgs(), 'created_at', false)->count(),
            'per_period' => $this->series($orgs(), 'created_at', $window, false),
            'largest'  => $this->largest(),
        ];
    }

    /** @return list<array{organization_id: int, name: string, members: int}> */
    private function largest(): array
    {
        $table  = Role::membershipTable();
        $column = Role::organizationColumn();

        if (!$this->has($table)) {
            return [];
        }

        $members = $this->countBy($this->table($table)->where('is_active', true), $column);
        if ($members === []) {
            return [];
        }

        $names  = [];
        $result = $this->table('#PREFIX#organizations')->select(['organization_id', 'name'])
            ->whereIn('organization_id', array_map('intval', array_keys($members)))->get();
        while (($row = $result->fetch()) !== null) {
            $names[(int) $row['organization_id']] = (string) $row['name'];
        }

        $out = [];
        foreach ($members as $id => $count) {
            $out[] = ['organization_id' => (int) $id, 'name' => $names[(int) $id] ?? '', 'members' => $count];
        }

        return $out;
    }
}
