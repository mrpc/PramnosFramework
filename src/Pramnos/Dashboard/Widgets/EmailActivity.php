<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;
use Pramnos\Messaging\Mail;

/**
 * Email over the last week, from the send history (`#PREFIX#mails`, one row per message whatever
 * became of it): sent, failed, waiting in the outbox. The share opened appears beside them only
 * where tracked mail was sent in the week — an installation that tracks nothing shows no rate
 * rather than "0%". Opens the email screen.
 */
final class EmailActivity extends Widget
{
    /** The days it counts. */
    private const DAYS = 7;

    public function id(): string
    {
        return 'email';
    }

    public function title(): string
    {
        return 'Email (7 days)';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Email in the last 7 days: sent, failed, waiting to be sent, and opened where mail is tracked.';
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
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('mails')) {
            return '';
        }
        $from = time() - self::DAYS * 86400;

        // One pass over the week, by status — a range on the `date` index, however long the history.
        $counts = [];
        $result = $db->queryBuilder()->table('#PREFIX#mails')
            ->select(['status', $db->queryBuilder()->raw('COUNT(*) AS n')])
            ->where('date', '>=', $from)->groupBy('status')->get();
        while (($row = $result->fetch()) !== null) {
            $counts[(int) $row['status']] = (int) $row['n'];
        }
        $sent   = $counts[Mail::STATUS_SENT] ?? 0;
        $failed = $counts[Mail::STATUS_FAILED] ?? 0;
        $rate   = $sent + $failed > 0 ? $failed / ($sent + $failed) : 0.0;

        $tiles = [
            StatTile::render(Figures::words('Sent'), number_format($sent), link: $this->link()),
            StatTile::render(Figures::words('Failed'), number_format($failed), $sent + $failed > 0 ? number_format($rate * 100, 1) . '%' : '',
                state: $rate >= 0.05 ? StatTile::CRITICAL : ($failed > 0 ? StatTile::WARNING : null)),
            StatTile::render(Figures::words('Queued'), number_format($counts[Mail::STATUS_QUEUED] ?? 0)),
        ];

        $tracked = static fn () => $db->queryBuilder()->table('pramnos.emailtracking')->where('sent_at', '>=', $from);
        $total   = $db->schema()->hasTable('pramnos.emailtracking') ? $tracked()->count() : 0;
        if ($total > 0) {
            $opened  = $tracked()->where('opens', '>', 0)->count();
            $tiles[] = StatTile::render(Figures::words('Opened'), number_format($opened / $total * 100, 1) . '%', sprintf(Figures::words('of %s tracked'), number_format($total)));
        }

        return StatTile::grid($tiles);
    }
}
