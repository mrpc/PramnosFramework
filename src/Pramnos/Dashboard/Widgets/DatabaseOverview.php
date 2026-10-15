<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/** The database server: its size, its connections, and how much is answered from memory. */
final class DatabaseOverview extends Widget
{
    public function id(): string
    {
        return 'database';
    }

    public function title(): string
    {
        return 'Database';
    }

    public function link(): ?string
    {
        return adminUrl('Dashboard/database');
    }

    public function render(): string
    {
        $db    = Figures::database();
        $tiles = [
            StatTile::render('Size', Figures::bytes((float) ($db['db_size_bytes'] ?? 0)), (string) ($db['version'] ?? $db['type'] ?? ''), $this->link()),
            StatTile::render('Connections', (int) ($db['connections_active'] ?? 0), (int) ($db['connections_total'] ?? 0) . ' in all'),
        ];
        if (isset($db['cache_hit_ratio'])) {
            $ratio   = (float) $db['cache_hit_ratio'];
            $tiles[] = StatTile::render('Cache hits', number_format($ratio, 1) . '%', state: $ratio < 90 ? StatTile::WARNING : null);
        }

        return StatTile::grid($tiles);
    }
}
