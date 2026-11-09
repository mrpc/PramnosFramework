<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;

/**
 * The goal card on the dashboard: today's progress (or this month's) towards a metric's target —
 * the card's own, relative to last year, or the goal kept for it — as a bar or hour by hour. Or,
 * with `goal`, any goal kept ({@see \Pramnos\Metrics\Goals}) of any kind: its state, its sentence,
 * and for a recurring one its past periods and streak. Offered to whoever may read a metric, and
 * showing only a goal they may.
 */
class DashboardGoalWidget extends GoalWidget
{
    public function id(): string
    {
        return 'metric.goal';
    }

    /** Any board, not only a report's. */
    public function boards(): ?array
    {
        return null;
    }

    public function description(): string
    {
        return Figures::words('How near today\'s target is, against the curve of the same day last year');
    }

    /** The parent's settings, and a goal kept to show instead. */
    public function options(): array
    {
        $goals = [];
        foreach (\Pramnos\Llm\Analyst\Tools\GoalsTool::readable(\Pramnos\Metrics\ReportBoard::context()['user']) as $goal) {
            $goals[(string) $goal['id']] = Figures::words(\Pramnos\Metrics\Metrics::get($goal['metric'])->label()) . ($goal['note'] !== '' ? ' — ' . $goal['note'] : '');
        }

        return ['goal' => ['label' => 'A goal kept', 'values' => ['' => Figures::words('None: the metric and target below')] + $goals]] + parent::options();
    }

    /** A goal kept, judged; or the metric and target, as on a report. */
    public function render(): string
    {
        $id = (int) $this->setting('goal');
        if ($id <= 0) {
            return parent::render();
        }
        $goal = array_values(array_filter(\Pramnos\Llm\Analyst\Tools\GoalsTool::readable($this->report()['user']), static fn (array $g): bool => $g['id'] === $id))[0] ?? null;
        if ($goal === null) {
            return parent::render();   // gone since: the metric and target, or the prompt to choose
        }
        try {
            $judged = \Pramnos\Metrics\Goals::judge($goal) ?? [];
        } catch (\Throwable $e) {
            return self::note($e->getMessage());
        }
        $title = $this->heading() !== '' ? $this->heading() : Figures::words(\Pramnos\Metrics\Metrics::get($goal['metric'])->label());
        $state = ['met' => 'Met', 'on_track' => 'On track', 'behind' => 'Behind', 'stalled' => 'Stalled'][$judged['state'] ?? ''] ?? 'Not known yet';
        $dots  = '';
        foreach ($judged['history'] ?? [] as $past) {
            $dots .= '<span title="' . htmlspecialchars((string) $past['from'], ENT_QUOTES, 'UTF-8') . '" style="opacity:' . ($past['met'] === true ? '1' : ($past['met'] === false ? '.35' : '.15')) . '">●</span>';
        }

        return \Pramnos\Html\StatTile::render($title, Figures::words($state), (string) ($judged['says'] ?? ''))
            . ($dots === '' ? '' : '<div aria-hidden="true" style="margin-top:.35rem;letter-spacing:.1em">' . $dots
                . (($judged['streak'] ?? 0) > 1 ? ' <span class="pf-muted">' . htmlspecialchars(sprintf(Figures::words('%d in a row'), $judged['streak']), ENT_QUOTES, 'UTF-8') . '</span>' : '') . '</div>');
    }

    /** Whoever may read a metric at all. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\Metrics\Metrics::all($user, false) !== [];
    }
}
