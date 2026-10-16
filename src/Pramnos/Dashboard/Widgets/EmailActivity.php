<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * Email over the last week, from the send history (`#PREFIX#mails`, one row per message whatever
 * became of it): sent, failed, waiting in the outbox. The share opened appears beside them only
 * where tracked mail was sent in the week — an installation that tracks nothing shows no rate
 * rather than "0%". Opens the email screen.
 */
final class EmailActivity extends Widget
{
    use \Pramnos\Dashboard\HasPeriod;

    /** The period, as each person sets it — the last week unless set otherwise. */
    public function options(): array
    {
        return $this->periodOptions();
    }

    public function id(): string
    {
        return 'email';
    }

    public function title(): string
    {
        return 'Email';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Email in the period chosen: sent, failed, waiting to be sent, and opened where mail is tracked.';
    }

    /** Whoever may open the email screen. */
    public function allowed(?object $user): bool
    {
        return Figures::opens($user, Figures::ability($this->id(), 'admin.emails'));
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Emails');
    }

    public function render(): string
    {
        $history = new \Pramnos\Email\SendHistory();
        if (!$history->available()) {
            return '';
        }
        [$from, $to] = $this->periodRange();
        $figures = $history->figures($from, $to);
        $sent    = $figures['sent'];
        $failed  = $figures['failed'];
        $rate    = (float) ($figures['failure_rate'] ?? 0);
        $db      = \Pramnos\Framework\Factory::getDatabase();

        $tiles = [
            StatTile::render(Figures::words('Sent'), number_format($sent), $this->periodLabel(), $this->link()),
            // The rate, and where most failures were: the module to look at first.
            StatTile::render(Figures::words('Failed'), number_format($failed), implode(' · ', array_filter([
                $figures['failure_rate'] !== null ? number_format($rate * 100, 1) . '%' : '',
                (string) array_key_first($figures['failed_by_module']),
            ])),
                state: $rate >= 0.05 ? StatTile::CRITICAL : ($failed > 0 ? StatTile::WARNING : null)),
            StatTile::render(Figures::words('Queued'), number_format($figures['queued'])),
        ];

        $tracked = static fn () => $db->queryBuilder()->table('pramnos.emailtracking')->where('sent_at', '>=', $from)->where('sent_at', '<=', $to);
        $total   = $db->schema()->hasTable('pramnos.emailtracking') ? $tracked()->count() : 0;
        if ($total > 0) {
            $opened  = $tracked()->where('opens', '>', 0)->count();
            $tiles[] = StatTile::render(Figures::words('Opened'), number_format($opened / $total * 100, 1) . '%', sprintf(Figures::words('of %s tracked'), number_format($total)));
        }

        return StatTile::grid($tiles);
    }
}
