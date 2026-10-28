<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Notification\NotificationInterface;

/**
 * Somebody shared an Ask conversation or artifact with this person: to the bell, and as a push
 * where they have a browser subscribed, with the link that opens it.
 *
 * `'llm' => ['chat' => ['share_notification' => …]]` names a class of the application's own,
 * constructed with the same two arguments.
 */
class SharedWithYou implements NotificationInterface
{
    public function __construct(private readonly string $words, private readonly string $link)
    {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return RoutineReady::channels($notifiable);
    }

    /** @return array{message: string, url: string} */
    public function toDatabase(mixed $notifiable): array
    {
        return ['message' => $this->words, 'url' => $this->link];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => mb_substr($this->words, 0, 90), 'body' => '', 'url' => $this->link, 'tag' => 'shared-' . md5($this->link)];
    }
}
