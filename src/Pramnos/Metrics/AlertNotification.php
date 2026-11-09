<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Notification\NotificationInterface;

/** A metric's alert that fired ({@see Alerts}): in the bell, and as a push where the person has one. */
final class AlertNotification implements NotificationInterface
{
    public function __construct(public readonly int $ruleId, public readonly string $message, public readonly string $url = '')
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
        return ['message' => $this->message, 'url' => $this->url, 'group' => 'metrics', 'about' => ['alert:' . $this->ruleId]];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('Alert'), 'body' => $this->message, 'url' => $this->url, 'tag' => 'alert-' . $this->ruleId];
    }
}
