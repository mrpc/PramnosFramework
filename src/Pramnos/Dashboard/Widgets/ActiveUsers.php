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

    public function link(): ?string
    {
        return adminUrl('Users/sessions');
    }

    public function render(): string
    {
        $counts = Figures::activeUsers();

        return StatTile::grid([
            StatTile::render('Now', (int) ($counts['now'] ?? 0), link: $this->link()),
            StatTile::render('Last 24 hours', (int) ($counts['last_24h'] ?? 0)),
            StatTile::render('Last 7 days', (int) ($counts['last_7d'] ?? 0)),
        ]);
    }
}
