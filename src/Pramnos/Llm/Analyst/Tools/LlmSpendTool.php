<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Llm\CallLog;

/**
 * Model calls and what they cost: per period, by provider, model and purpose, the failures,
 * and the organisations that spent most — by id and name, which are the business's.
 */
final class LlmSpendTool extends AdminTool
{
    public function name(): string
    {
        return 'llm_spend';
    }

    public function description(): string
    {
        return 'Language-model calls in a window: calls, tokens and cost, per period, by provider, '
            . 'model and purpose; scheduled tasks\' runs apart, by task id; failures by reason; and the '
            . 'organisations that spent most (id and name).';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has(CallLog::TABLE)) {
            return self::absent('log of model calls');
        }

        $calls  = fn () => $window->apply($this->table(CallLog::TABLE), 'created_at', false);
        $totals = self::one($calls()->select([$this->raw('COUNT(*) AS calls'), $this->raw('SUM(tokens) AS tokens'), $this->raw('SUM(cost) AS cost')])) ?? [];

        return [
            'window'         => $window->describe(),
            'calls'          => (int) ($totals['calls'] ?? 0),
            'tokens'         => (int) ($totals['tokens'] ?? 0),
            'cost'           => round((float) ($totals['cost'] ?? 0), 4),
            'failed'         => $calls()->whereNotNull('error')->where('error', '!=', '')->count(),
            'by_provider'    => $this->spend($calls(), 'provider'),
            'by_model'       => $this->spend($calls(), 'model'),
            'by_purpose'     => $this->spend($calls(), 'purpose'),
            // Scheduled tasks' runs apart from conversations, where the log says which task.
            'scheduled'      => $this->scheduled($calls),
            'failures'       => $this->countBy($calls()->whereNotNull('error')->where('error', '!=', ''), 'error', 10),
            'per_period'     => $this->series($this->table(CallLog::TABLE), 'created_at', $window, false),
            'organizations'  => $this->organizations($window),
        ];
    }

    /**
     * What scheduled tasks' runs cost — `calls`, `tokens`, `cost` and the tasks that cost most, by
     * routine_id — or null where the call log does not say which task a call was for.
     *
     * @return array<string, mixed>|null
     */
    private function scheduled(callable $calls): ?array
    {
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn(CallLog::TABLE, 'routine_id')) {
            return null;
        }
        $totals = self::one($calls()->whereNotNull('routine_id')->select([$this->raw('COUNT(*) AS calls'), $this->raw('SUM(tokens) AS tokens'), $this->raw('SUM(cost) AS cost')])) ?? [];

        return [
            'calls'    => (int) ($totals['calls'] ?? 0),
            'tokens'   => (int) ($totals['tokens'] ?? 0),
            'cost'     => round((float) ($totals['cost'] ?? 0), 4),
            'by_task'  => $this->spend($calls()->whereNotNull('routine_id'), 'routine_id'),
        ];
    }

    /** @return array<string, array{calls: int, tokens: int, cost: float}> */
    private function spend(\Pramnos\Database\QueryBuilder $query, string $column): array
    {
        $result = $query->select([$column, $this->raw('COUNT(*) AS calls'), $this->raw('SUM(tokens) AS tokens'), $this->raw('SUM(cost) AS cost')])
            ->groupBy($column)->orderBy('cost', 'desc')->limit(self::TOP)->get();
        $out = [];
        while (($row = $result->fetch()) !== null) {
            $out[(string) ($row[$column] ?? '')] = ['calls' => (int) $row['calls'], 'tokens' => (int) $row['tokens'], 'cost' => round((float) $row['cost'], 4)];
        }

        return $out;
    }

    /** @return list<array{organization_id: int, name: string, calls: int, tokens: int, cost: float}> */
    private function organizations(Window $window): array
    {
        $spend = $this->spend($window->apply($this->table(CallLog::TABLE), 'created_at', false)->whereNotNull('organization_id'), 'organization_id');
        if ($spend === []) {
            return [];
        }

        $names = [];
        if ($this->has('organizations')) {
            $result = $this->table('#PREFIX#organizations')->select(['organization_id', 'name'])
                ->whereIn('organization_id', array_map('intval', array_keys($spend)))->get();
            while (($row = $result->fetch()) !== null) {
                $names[(int) $row['organization_id']] = (string) $row['name'];
            }
        }

        $out = [];
        foreach ($spend as $id => $figures) {
            $out[] = ['organization_id' => (int) $id, 'name' => $names[(int) $id] ?? ''] + $figures;
        }

        return $out;
    }

    private function raw(string $sql): \Pramnos\Database\Expression
    {
        return $this->table(CallLog::TABLE)->raw($sql);
    }
}
