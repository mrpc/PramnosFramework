<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/** Who is signed in: now, and over the last day and week. Opens the sessions list. */
final class ActiveUsers extends Widget
{
    public function id(): string
    {
        return 'users.active';
    }

    public function title(): string
    {
        return 'Active users';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Accounts signed in now, and in the last hour, day, week and month.';
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Users/sessions');
    }

    /** The same figures for everybody who may see it: worked out once, kept a while. */
    public function shared(): bool
    {
        return true;
    }

    /** The counts. */
    public function data(): array
    {
        return Figures::activeUsers();
    }

    public function present(array $counts): string
    {
        return StatTile::grid([
            StatTile::render(Figures::words('Now'), (int) ($counts['now'] ?? 0), link: $this->link()),
            StatTile::render(Figures::words('Last 24 hours'), (int) ($counts['last_24h'] ?? 0)),
            StatTile::render(Figures::words('Last 7 days'), (int) ($counts['last_7d'] ?? 0)),
        ]);
    }
}
