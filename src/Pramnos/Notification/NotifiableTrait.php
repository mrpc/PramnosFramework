<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * Default implementation of NotifiableInterface.
 *
 * Add this trait to any model class (typically User) to get out-of-the-box
 * `notify()` and `routeNotificationFor()` behaviour:
 *
 * ```php
 * class User extends OrmModel implements NotifiableInterface
 * {
 *     use NotifiableTrait;
 * }
 *
 * $user->notify(new InvoicePaidNotification($invoice));
 * ```
 *
 * Override `routeNotificationFor()` to customise how the entity's delivery
 * address is resolved per channel.
 *
 * @package     PramnosFramework
 * @subpackage  Notification
 */
trait NotifiableTrait
{
    /**
     * Dispatch a notification to this entity immediately.
     *
     * Delegates to Notifier::sendNow() so all channels listed in
     * $notification->via($this) are called in sequence.
     */
    public function notify(NotificationInterface $notification): void
    {
        (new Notifier())->sendNow($this, $notification);
    }

    /**
     * Return the delivery address for a given channel.
     *
     * Default routing:
     *   - 'mail'     → $this->email (string|null)
     *   - 'database' → $this->userid ?? $this->id (int|null)
     *   - all others → null (channel decides how to handle)
     *
     * Override this method to customise routing, e.g. for per-user
     * notification preferences or alternative email addresses.
     */
    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail'     => $this->email ?? null,
            'database' => $this->userid ?? $this->id ?? null,
            default    => null,
        };
    }
}
