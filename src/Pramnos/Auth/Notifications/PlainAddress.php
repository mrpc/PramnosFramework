<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Notification\NotifiableTrait;

/**
 * A notifiable that is nothing but an address.
 *
 * For the one case where a notification has to reach somewhere that is not the account's
 * current address: {@see \Pramnos\Auth\SecurityChangeNotifier} telling the *previous*
 * address that the account's email was changed. That mail is the only signal the real
 * owner gets when a stolen session changes the address first, so it cannot be routed
 * through the user object — the user object now points at the attacker's mailbox.
 *
 * Deliberately not a `User` with its address overwritten. A user model is live: other code
 * holds it, and a mutation made to send one mail is exactly the kind that survives into a
 * `save()`.
 */
class PlainAddress implements NotifiableInterface
{
    use NotifiableTrait;

    /** Read by the mail channel's `$notifiable->email` fallback as well as the route. */
    public string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    /**
     * Mail only: there is no account behind this, so no database or broadcast target.
     */
    public function routeNotificationFor(string $channel): mixed
    {
        return $channel === 'mail' ? $this->email : null;
    }
}
