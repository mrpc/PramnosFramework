<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * The API over the last day: requests, the share that failed, and how long they took. Opens the
 * token-action list, where those numbers are read from.
 */
final class ApiPerformance extends Widget
{
    public function id(): string
    {
        return 'api.performance';
    }

    public function title(): string
    {
        return 'API (24 hours)';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Calls to the API in the last 24 hours: how many, how fast, how many failed.';
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'TokenActions');
    }

    /** The same figures for everybody who may see it: worked out once, kept a while. */
    public function shared(): bool
    {
        return true;
    }

    /** The last day's calls. */
    public function data(): array
    {
        return Figures::api();
    }

    public function present(array $api): string
    {
        $error = (float) ($api['error_rate'] ?? 0) * 100;

        return StatTile::grid([
            StatTile::render(Figures::words('Requests'), number_format((int) ($api['total_requests'] ?? 0)), link: $this->link()),
            StatTile::render(Figures::words('Errors'), number_format($error, 1) . '%', state: $error >= 5 ? StatTile::CRITICAL : ($error >= 1 ? StatTile::WARNING : null)),
            StatTile::render(Figures::words('Average'), number_format((float) ($api['avg_execution_time'] ?? 0)) . ' ms', 'p95 ' . number_format((float) ($api['p95_execution_time'] ?? 0)) . ' ms'),
        ]);
    }
}
