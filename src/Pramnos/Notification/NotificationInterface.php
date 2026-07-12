<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * Contract for notification objects.
 *
 * A notification declares which channels it should be dispatched to by
 * returning their names from via(). It optionally implements channel-specific
 * serialisation methods recognised by the built-in channels:
 *
 *   toMail($notifiable): array         — consumed by MailChannel
 *   toDatabase($notifiable): array     — consumed by DatabaseChannel
 *   toBroadcast($notifiable): array    — consumed by BroadcastChannel
 *   toLog($notifiable): mixed          — consumed by LogChannel
 *
 */
interface NotificationInterface
{
    /**
     * Return the channel names this notification should be dispatched to.
     *
     * Each element is either a short alias ('mail', 'database', 'broadcast',
     * 'log') or a fully-qualified class name that implements ChannelInterface.
     *
     * @param  mixed $notifiable The entity receiving the notification.
     * @return string[]
     */
    public function via(mixed $notifiable): array;
}
