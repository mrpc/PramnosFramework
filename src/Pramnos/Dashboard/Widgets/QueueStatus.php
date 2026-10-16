<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * The background queue: what waits now, what failed and what finished in the last day. Opens the
 * queue's screen. Read through the analyst's `QueueTool`, so the card and the analyst agree.
 */
final class QueueStatus extends Widget
{
    public function id(): string
    {
        return 'queue';
    }

    public function title(): string
    {
        return 'Queue';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Background tasks waiting now, and those that failed or finished in the last 24 hours.';
    }

    /** Where the queue is installed, for whoever may open its screen. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\Application\FeatureRegistry::isEnabled('queue') && Figures::opens($user, Figures::ability($this->id(), 'admin.queue'));
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Queue');
    }

    public function render(): string
    {
        $queue = (new \Pramnos\Llm\Analyst\Tools\QueueTool())->execute(['days' => 1]);
        if (!is_array($queue) || ($queue['available'] ?? true) === false) {
            return '';
        }
        $failed = array_sum(array_column((array) ($queue['failed'] ?? []), 'count'));

        return StatTile::grid([
            StatTile::render(Figures::words('Waiting'), number_format((int) ($queue['by_status']['pending'] ?? 0)), link: $this->link()),
            StatTile::render(Figures::words('Failed'), number_format($failed), Figures::words('24 hours'), state: $failed > 0 ? StatTile::CRITICAL : null),
            StatTile::render(Figures::words('Done'), number_format((int) ($queue['completed'] ?? 0)), Figures::words('24 hours')),
        ]);
    }
}
