<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Metrics\Goals;
use Pramnos\Metrics\Metrics;

/**
 * Add a goal, or change one, for the analyst — a write, offered where the application lets the
 * assistant act ({@see \Pramnos\Llm\Analyst\AnalystAssistant::writes()}), and only on a metric
 * the person may read. A change keeps what it does not name. The owner is "me", an id, or a
 * name.
 */
final class GoalsChangeTool implements McpToolInterface
{
    /** @param int $adminId Who is asking */
    public function __construct(private readonly int $adminId = 0)
    {
    }

    public function name(): string
    {
        return 'goals_change';
    }

    public function description(): string
    {
        return 'Add a goal, or change one, only when the administrator asked for it or agreed. '
            . 'kind: count or growth on a total; reach, sustain, pace or growth on a level (goals action=metrics says which). '
            . 'target: the number, or the % for growth. repeats: day, week, month or quarter; or due (YYYY-MM-DD) and starts for a one-off. '
            . 'An update keeps what it does not name.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id'        => ['type' => 'integer', 'description' => 'The goal to change; none to add one.'],
            'metric'    => ['type' => 'string'],
            'kind'      => ['type' => 'string', 'enum' => ['count', 'growth', 'reach', 'sustain', 'pace']],
            'target'    => ['type' => 'number'],
            'repeats'   => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'quarter']],
            'starts'    => ['type' => 'string', 'description' => 'YYYY-MM-DD; today by default.'],
            'due'       => ['type' => 'string', 'description' => 'YYYY-MM-DD, for a one-off goal.'],
            'reference' => ['type' => 'string', 'enum' => Goals::REFERENCES],
            'filters'   => ['type' => 'object', 'description' => 'Dimension => value.'],
            'owner'     => ['type' => 'string', 'description' => '"me", a user id, or a name.'],
            'note'      => ['type' => 'string'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $user = $this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null;
        $goal = array_intersect_key($input, array_flip(['metric', 'kind', 'target', 'repeats', 'starts', 'due', 'reference', 'filters', 'note']));
        if (isset($input['owner'])) {
            $owner = $this->owner((string) $input['owner']);
            if (is_string($owner)) {
                return 'Error: ' . $owner;
            }
            $goal['owner'] = $owner;
        }
        try {
            if (isset($input['id'])) {
                $readable = array_column(GoalsTool::readable($user), null, 'id');
                if (!isset($readable[(int) $input['id']])) {
                    return 'Error: there is no goal ' . (int) $input['id'] . ' you may change.';
                }
                Goals::update((int) $input['id'], $goal);

                return ['changed' => (int) $input['id'], 'now' => Goals::judge((int) $input['id'])];
            }
            if (!isset(Metrics::all($user, false)[(string) ($goal['metric'] ?? '')])) {
                return 'Error: there is no metric "' . ($goal['metric'] ?? '') . '" you may set a goal on; goals action=metrics lists them.';
            }
            $id = Goals::create($goal + ['owner' => $this->adminId, 'by' => $this->adminId]);

            return ['added' => $id, 'now' => Goals::judge($id)];
        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /** The owner meant: "me", an id, or one name matching; else why not. */
    private function owner(string $who): int|string
    {
        $who = trim($who);
        if ($who === '' || in_array(mb_strtolower($who), ['me', 'εγώ', 'εμένα'], true)) {
            return $this->adminId;
        }
        if (ctype_digit($who)) {
            return (int) $who;
        }
        $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')->select(['userid', 'username'])
            ->where('usertype', '>=', \Pramnos\Auth\AdminAccess::defaultUsertype())->where('active', 1)
            ->whereRaw('LOWER(username) LIKE ?', ['%' . mb_strtolower($who) . '%'])->limit(5)->getAllOrFail();

        return count($rows) === 1 ? (int) $rows[0]['userid']
            : ($rows === [] ? 'nobody named "' . $who . '" may look after a goal.' : 'more than one is named "' . $who . '": ' . implode(', ', array_column($rows, 'username')) . '.');
    }
}
