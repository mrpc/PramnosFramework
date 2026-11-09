<?php

declare(strict_types=1);

namespace Pramnos\Todos;

use Pramnos\Notification\NotificationInterface;

/**
 * A to-do told to a person ({@see Todos}): given to them, its reminder, or due today — in the
 * bell, and as a push where they have one, an item's push replacing its last. About `todo:ID`, so
 * closing or deleting the item settles it, and in the `todos` group, so seeing the list settles them all.
 */
final class TodoNotification implements NotificationInterface
{
    public function __construct(public readonly int $todoId, public readonly string $message, public readonly string $url = '')
    {
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        $push = \Pramnos\Notification\Channels\PushChannel::sends() && \Pramnos\Push\Subscriptions::exist((int) ($notifiable->userid ?? 0));

        return $push ? ['database', 'push'] : ['database'];
    }

    /** @return array{message: string, url: string, group: string, about: list<string>} */
    public function toDatabase(mixed $notifiable): array
    {
        return ['message' => $this->message, 'url' => $this->url, 'group' => Todos::GROUP, 'about' => ['todo:' . $this->todoId]];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('To-do'), 'body' => $this->message, 'url' => $this->url, 'tag' => 'todo-' . $this->todoId];
    }
}
