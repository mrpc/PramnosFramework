<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * Contract for notification delivery channels.
 *
 * Each channel is responsible for delivering one notification to one notifiable
 * entity. Channels should be idempotent and must not throw for missing
 * optional data — e.g. MailChannel silently skips when the notifiable has no
 * email address.
 *
 */
interface ChannelInterface
{
    /**
     * Deliver the notification to a single notifiable entity.
     *
     * @param mixed                 $notifiable   The entity to notify.
     * @param NotificationInterface $notification The notification to send.
     */
    public function send(mixed $notifiable, NotificationInterface $notification): void;
}
