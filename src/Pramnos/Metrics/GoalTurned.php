<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Notification\NotificationInterface;

/**
 * A goal that turned from good to bad ({@see Goals::check()}): in the bell, and as a push where
 * the person has one — a goal's push replacing its last. No mail.
 */
final class GoalTurned implements NotificationInterface
{
    /** The event a rule, a to-do source or an application listens to: `goal.turned`. */
    public const EVENT = 'goal.turned';

    public function __construct(public readonly int $goalId, public readonly string $message, public readonly string $url = '')
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
        return ['message' => $this->message, 'url' => $this->url, 'group' => 'metrics', 'about' => ['goal:' . $this->goalId]];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('Goal'), 'body' => $this->message, 'url' => $this->url, 'tag' => 'goal-' . $this->goalId];
    }
}
