<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Metrics\Achievements;
use Pramnos\Metrics\GoalJudge;
use Pramnos\Metrics\Goals;
use Pramnos\Metrics\Metrics;

/**
 * The goals and how each stands, for the analyst and MCP ({@see Goals}), and what was reached
 * ({@see Achievements}) — only the goals on metrics the person may read, of organisations they
 * may. Its name is the application's (`'metrics' => ['goals' => ['tool' => 'glideday-goals']]`),
 * so assistants that knew an application's own tool keep it. Read only: `goals_change` adds and
 * changes them.
 */
final class GoalsTool implements McpToolInterface
{
    /**
     * @param int         $adminId Who is asking; 0 is nobody signed in
     * @param object|null $as      The person asking, where the caller has them already (the JSON API)
     */
    public function __construct(private readonly int $adminId = 0, private readonly ?object $as = null)
    {
    }

    public function name(): string
    {
        return (string) ((Metrics::config()['goals']['tool'] ?? null) ?: 'goals');
    }

    public function description(): string
    {
        return 'The business\'s goals and how each stands: state (met, on_track, behind, stalled, unknown), '
            . 'what it says, the figures behind it, and for a recurring goal its past periods and streak. '
            . 'action=metrics lists what a goal can be set on. Report the state as given: unknown is a real '
            . '"not yet" (too little history), stalled is not behind (it is going the other way). '
            . 'achievements lists what was reached (milestones, best weeks, goals met), dated when it happened, not when it was seen.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['list', 'metrics'], 'description' => 'list by default.'],
            'id'           => ['type' => 'integer', 'description' => 'One goal.'],
            'achievements' => ['type' => 'boolean', 'description' => 'With what was reached; true by default.'],
            'limit'        => ['type' => 'integer', 'description' => 'At most this many achievements; 20 by default.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $user = $this->user();
        if (($input['action'] ?? 'list') === 'metrics') {
            $out = [];
            foreach (Metrics::all($user, false) as $metric) {
                $out[] = ['metric' => $metric->name, 'label' => $metric->label(), 'kinds' => $metric->kind === \Pramnos\Metrics\Metric::LEVEL ? GoalJudge::KINDS_LEVEL : GoalJudge::KINDS_TOTAL,
                    'higher_is_better' => $metric->getDirection() !== \Pramnos\Metrics\Metric::LOWER_IS_BETTER, 'dimensions' => $metric->getDimensions()];
            }

            return ['metrics' => $out, 'repeats' => GoalJudge::RECURRING];
        }
        $goals = self::readable($user);
        if (isset($input['id'])) {
            $goals = array_values(array_filter($goals, static fn (array $g): bool => $g['id'] === (int) $input['id']));
        }
        $out = [];
        foreach ($goals as $goal) {
            try {
                $judged = Goals::judge($goal) ?? [];
            } catch (\Throwable $e) {
                $judged = ['state' => 'unknown', 'says' => $e->getMessage()];
            }
            $out[] = array_filter(['id' => $goal['id'], 'metric' => $goal['metric'], 'label' => Metrics::get($goal['metric'])->label(), 'filters' => $goal['filters'] ?: null,
                'tenant' => $goal['tenant'] ?: null, 'repeats' => in_array($goal['grain'], GoalJudge::RECURRING, true) && $goal['period'] === '' ? $goal['grain'] : null,
                'starts' => $goal['starts_at'] ? date('Y-m-d', $goal['starts_at']) : null, 'due' => $goal['due_at'] ? date('Y-m-d', $goal['due_at']) : null,
                'owner' => $goal['owner'], 'note' => $goal['note'] ?: null] + $judged, static fn ($v): bool => $v !== null);
        }
        $answer = ['goals' => $out, 'note' => 'Report each goal\'s state and says as given; do not work the state out again.'];
        if (($input['achievements'] ?? true) !== false) {
            $tenants = Metrics::tenantsOf($user);
            $reached = Achievements::list($tenants !== null && count($tenants) === 1 ? $tenants[0] : null, max(1, min(100, (int) ($input['limit'] ?? 20))));
            $answer['achievements'] = array_map(static fn (array $a): array => ['kind' => $a['kind'], 'metric' => $a['metric'], 'value' => $a['value'],
                'achieved_at' => $a['achieved_at'], 'says' => $a['says']], $reached);
            $answer['achievements_note'] = 'An achievement\'s date is the day it happened, not the day it was noticed.';
        }

        return $answer;
    }

    /**
     * The goals the person may read: on a metric they may, of an organisation they may.
     *
     * @return list<array<string, mixed>>
     */
    public static function readable(?object $user): array
    {
        $metrics = Metrics::all($user, false);
        $tenants = Metrics::tenantsOf($user);

        return array_values(array_filter(Goals::all(), static fn (array $g): bool => isset($metrics[$g['metric']])
            && ($tenants === null || $g['tenant'] === 0 || in_array($g['tenant'], $tenants, true))
            && Metrics::withinScope($g['metric'], (array) $g['filters'], $user)));
    }

    /** Who is asking, or null for nobody. */
    private function user(): ?object
    {
        return $this->as ?? ($this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null);
    }
}
