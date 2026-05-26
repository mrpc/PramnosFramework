<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * Marks an entity (typically a User) as capable of receiving notifications.
 *
 * Implement this interface on any model that should support the
 * `$user->notify(new InvoicePaidNotification(...))` call pattern.
 *
 * The default implementation is provided by NotifiableTrait.
 *
 * @package     PramnosFramework
 * @subpackage  Notification
 */
interface NotifiableInterface
{
    /**
     * Dispatch a notification to this entity immediately.
     *
     * @param NotificationInterface $notification
     */
    public function notify(NotificationInterface $notification): void;

    /**
     * Return the delivery address for a given channel.
     *
     * Implementations may customise routing per channel. The default in
     * NotifiableTrait returns `$this->email` for 'mail', null otherwise.
     *
     * @param  string $channel  Channel alias ('mail', 'database', …).
     * @return mixed            Address string, integer ID, null to skip.
     */
    public function routeNotificationFor(string $channel): mixed;
}
