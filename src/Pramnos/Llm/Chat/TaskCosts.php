<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;
use Pramnos\Translator\Language;

/**
 * What the scheduled tasks cost, read from what was already recorded.
 *
 * Each run's `ai_task_runs.cost` is the sum priced for that run's model calls — the same pricing
 * as the call log — once, however many people receive the report. The calls themselves sit in the
 * call log under the task maker's userid with nothing naming the task, so the run is where a
 * task's cost is read.
 *
 * Shown in two places with nothing to configure: as a card on `/admin/Ai` (the last
 * {@see DAYS} days per task, and their share of everything the call log priced), and on the open
 * task's card on the Scheduled page ({@see forTask()}). A reminder asks no model and costs
 * nothing.
 */
final class TaskCosts implements \Pramnos\Llm\Admin\Card
{
    /** Days the admin card covers, as the call log's "30 days". */
    public const DAYS = 30;

    /**
     * Per task: runs, cost, and successful runs without a price — since `$since` (a Unix time),
     * or ever.
     *
     * @return array<int, array{runs: int, cost: float, unpriced: int}>
     */
    public static function byTask(?int $since = null): array
    {
        $qb    = \Pramnos\Framework\Factory::getDatabase()->queryBuilder();
        $query = $qb->table('#PREFIX#ai_task_runs')->select([
            'routine_id',
            $qb->raw('count(*) as runs'),
            $qb->raw('sum(cost) as cost'),
            $qb->raw("sum(case when cost is null and status = 'ok' then 1 else 0 end) as unpriced"),
        ])->groupBy('routine_id');
        if ($since !== null) {
            $query->where('started_at', '>=', CallLog::instant($since));
        }
        $out = [];
        try {
            $result = $query->get();
        } catch (\Throwable $e) {
            // No ai_task_runs yet (the migration has not run): nothing ran.
            \Pramnos\Logs\Logger::log('task costs: ' . $e->getMessage(), 'llm');
            return [];
        }
        while (($row = $result->fetch()) !== null) {
            $out[(int) $row['routine_id']] = ['runs' => (int) $row['runs'], 'cost' => (float) $row['cost'], 'unpriced' => (int) $row['unpriced']];
        }

        return $out;
    }

    /**
     * The tasks' titles, by routine_id; a deleted task is absent.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function titles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $titles = [];
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#ai_routines')
            ->select(['routine_id', 'title'])->whereIn('routine_id', $ids)->get();
        while (($row = $result->fetch()) !== null) {
            $titles[(int) $row['routine_id']] = (string) $row['title'];
        }

        return $titles;
    }

    /** USD, as the AI settings screen shows it: four places below a dollar, a dash for no price. */
    public static function usd(?float $cost): string
    {
        return $cost === null ? '—' : '$' . number_format($cost, $cost < 1 ? 4 : 2);
    }

    /**
     * The open task's costs on the Scheduled page: its runs and total, and each listed run's cost.
     *
     * @param array<string, mixed>       $task As Routines::find() answers
     * @param list<array<string, mixed>> $runs Its runs, as Routines::runs() answers
     */
    public static function forTask(array $task, array $runs): string
    {
        $t = static fn (string $s): string => self::e(Language::getInstance()->_($s));
        $c = self::byTask()[(int) $task['routine_id']] ?? ['runs' => 0, 'cost' => 0.0, 'unpriced' => 0];

        $html = '<div data-pf-task-costs><h3>' . $t('What it cost') . '</h3>'
            . '<p data-pf-task-cost="' . (int) $task['routine_id'] . '">' . $t('Times run') . ': ' . $c['runs']
            . ' · ' . $t('Total') . ': ' . self::e(self::usd($c['cost']))
            . ($c['unpriced'] > 0 ? ' + ' . $c['unpriced'] . ' ' . $t('not priced') : '') . '</p>';
        if ($runs !== []) {
            $html .= '<table class="table table-sm"><thead><tr><th>' . $t('Run') . '</th><th>' . $t('Status')
                . '</th><th>' . $t('Cost') . '</th></tr></thead><tbody>';
            foreach ($runs as $run) {
                $at = Routines::utc((string) ($run['finished_at'] ?? $run['started_at'] ?? ''));
                $html .= '<tr data-pf-run-cost="' . (int) $run['run_id'] . '"><td>' . self::e($at === null ? '' : date('Y-m-d H:i', $at))
                    . '</td><td>' . $t(($run['status'] ?? '') === 'ok' ? 'Done' : 'Failed')
                    . '</td><td>' . self::e(self::usd($run['cost'] === null ? null : (float) $run['cost'])) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html . '<p><small>' . $t('The cost of each run as its model calls were priced, charged to the task\'s maker. A reminder asks no model and costs nothing.')
            . '</small></p></div>';
    }

    public function key(): string
    {
        return 'pf-task-costs';
    }

    public function title(): string
    {
        return Language::getInstance()->_('Scheduled tasks: what they cost');
    }

    /** The last {@see DAYS} days per task, and their share of everything the call log priced. */
    public function render(): string
    {
        $t      = static fn (string $s): string => self::e(Language::getInstance()->_($s));
        $costs  = self::byTask(time() - self::DAYS * 86400);
        $all    = (float) ((new CallLog())->summary(24 * self::DAYS)['cost'] ?? 0);
        $titles = self::titles(array_keys($costs));

        $html = '<table class="table table-sm" data-pf-task-costs><thead><tr><th>' . $t('Task') . '</th><th>'
            . $t('Times run') . '</th><th>' . $t('Cost') . '</th></tr></thead><tbody>';
        $sum = 0.0;
        foreach ($costs as $id => $c) {
            $sum += $c['cost'];
            // A deleted task takes its title with it; its runs still cost what they cost.
            $html .= '<tr><td>' . self::e($titles[$id] ?? '#' . $id) . '</td><td>' . $c['runs'] . '</td><td>' . self::e(self::usd($c['cost'])) . '</td></tr>';
        }

        return $html . '</tbody><tfoot><tr><th>' . $t('Total') . '</th><th></th><th>' . self::e(self::usd($sum)) . '</th></tr></tfoot></table>'
            . '<p><small>' . self::e(sprintf(
                Language::getInstance()->_('Last %d days: %s of the %s every model call cost. The rest is Ask conversations and the site\'s own AI.'),
                self::DAYS,
                self::usd($sum),
                self::usd($all)
            )) . '</small></p>';
    }

    /** Escape for HTML. */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
