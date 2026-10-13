<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Notification\NotificationInterface;

/**
 * A scheduled question was answered — or could not be.
 *
 * In the person's notifications, and as a push where they have a browser subscribed. An
 * application replaces it with `'llm' => ['chat' => ['routine_notification' => …]]`, a class
 * constructed the same way: title, whether it worked, why not, and the link to the answer.
 */
class RoutineReady implements NotificationInterface
{
    public function __construct(
        private readonly string $title,
        private readonly bool $ok,
        private readonly string $why,
        private readonly string $link
    ) {
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
        return [
            'message' => $this->ok ? $this->title . ' is ready.' : $this->title . ' could not be made: ' . $this->why,
            'url'     => $this->link,
        ];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return [
            'title' => mb_substr($this->title, 0, 90),
            'body'  => $this->ok ? 'Your scheduled report is ready.' : mb_substr('It could not be made: ' . $this->why, 0, 180),
            'url'   => $this->link,
            // One per routine: this morning's replaces yesterday's.
            'tag'   => 'routine-' . substr(md5($this->title), 0, 8),
        ];
    }
}
