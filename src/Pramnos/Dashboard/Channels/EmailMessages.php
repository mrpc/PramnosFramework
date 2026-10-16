<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Channels;

use Pramnos\Dashboard\MessageChannel;

/** Email, from the send history: the `messages` card's first row, where the history is kept. */
final class EmailMessages implements MessageChannel
{
    public function id(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function link(): ?string
    {
        return \Pramnos\Dashboard\Widgets\Figures::link('email', 'Emails');
    }

    /** @return array{total: int, failed: int, queued: int, delivered: int} */
    public function figures(int $from, int $to): array
    {
        $figures = (new \Pramnos\Email\SendHistory())->figures($from, $to);

        return ['total' => $figures['total'], 'failed' => $figures['failed'], 'queued' => $figures['queued'], 'delivered' => $figures['sent']];
    }
}
