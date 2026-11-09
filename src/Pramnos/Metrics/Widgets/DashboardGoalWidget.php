<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;

/**
 * The goal card on the dashboard: today's progress (or this month's) towards a metric's target —
 * the card's own, relative to last year, or the goal kept for it — as a bar or hour by hour.
 * Offered to whoever may read a metric, and showing only one they may.
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

    /** Whoever may read a metric at all. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\Metrics\Metrics::all($user, false) !== [];
    }
}
