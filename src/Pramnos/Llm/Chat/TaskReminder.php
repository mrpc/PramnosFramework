<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Notification\NotificationInterface;

/**
 * A reminder somebody asked the assistant for — "remind me in an hour about the invoice" — in
 * their own words, leading back to the conversation they asked in.
 *
 * In the person's notifications, and as a push where they have a browser subscribed. An
 * application replaces it with `'llm' => ['chat' => ['reminder_notification' => …]]`, a class
 * constructed the same way: the words and the link.
 */
class TaskReminder implements NotificationInterface
{
    public function __construct(private readonly string $words, private readonly string $link)
    {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        $channels = ['database'];

        if (\Pramnos\Push\Subscriptions::exist((int) ($notifiable->userid ?? 0))) {
            $channels[] = 'push';
        }

        return $channels;
    }

    /** @return array{message: string, url: string} */
    public function toDatabase(mixed $notifiable): array
    {
        return ['message' => $this->words, 'url' => $this->link];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return [
            'title' => mb_substr($this->words, 0, 90),
            'body'  => mb_strlen($this->words) > 90 ? mb_substr($this->words, 0, 240) : '',
            'url'   => $this->link,
            'tag'   => 'reminder-' . md5($this->words . $this->link),
        ];
    }
}
