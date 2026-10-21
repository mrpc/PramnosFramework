<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * The schedule: how many tasks are late, how many last ran into an error, how many run now.
 * Opens the scheduler screen, which shows which ones.
 */
final class ScheduleStatus extends Widget
{
    public function id(): string
    {
        return 'scheduler';
    }

    public function title(): string
    {
        return 'Scheduler';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Scheduled tasks that are late, whose last run failed, and those running now.';
    }

    /** For whoever may open the scheduler screen, where there is a history to read. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\Scheduling\RunLog::available() && Figures::opens($user, Figures::ability($this->id(), 'admin.scheduler'));
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Scheduler');
    }

    /** The same figures for everybody who may see it. */
    public function shared(): bool
    {
        return true;
    }

    /** The three counts. */
    public function data(): array
    {
        \Pramnos\Scheduling\Scheduler::loadDefinitions();

        return \Pramnos\Scheduling\ScheduleStatus::counts(
            \Pramnos\Scheduling\ScheduleStatus::of(\Pramnos\Scheduling\Scheduler::all(), new \DateTimeImmutable())
        );
    }

    public function present(array $counts): string
    {
        $late   = (int) ($counts['late'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);

        return StatTile::grid([
            StatTile::render(Figures::words('Late'), (string) $late, link: $this->link(), state: $late > 0 ? StatTile::CRITICAL : null),
            StatTile::render(Figures::words('Last run failed'), (string) $failed, state: $failed > 0 ? StatTile::CRITICAL : null),
            StatTile::render(Figures::words('Running now'), (string) (int) ($counts['running'] ?? 0)),
        ]);
    }
}
