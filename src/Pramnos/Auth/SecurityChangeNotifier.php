<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Tell the account when the things that protect it change.
 *
 * A password, an email address, a second factor, a passkey. The account is told *after*
 * the change, which is the only useful moment: before it, there is nothing to report; long
 * after, the trail is cold.
 *
 * ## The old address is the point
 *
 * When an email address changes, this notifies **both** the new address and the one that
 * was on the account. That is the whole reason the class exists rather than a line at each
 * call site: a stolen session's first two moves are to change the address and then the
 * password, and every notification after the first one goes to the attacker. Mailing the
 * previous address is the only signal the owner ever gets, and it is the one that lets
 * them act while it still matters.
 *
 * ## Opt-in, and why that is not timidity
 *
 * `auth.security.notify_security_changes`, off by default. Mail costs money and reputation:
 * an application whose users change a password as routine hygiene would be sending a great
 * deal of it, and one with a transactional-mail budget measured per message should decide
 * that itself. The framework's job is to make the right thing one line of configuration
 * away, not to spend somebody else's send quota on their behalf.
 *
 * Best-effort throughout. A notification is never worth failing the change it reports:
 * somebody who has just been told "your password could not be updated" because a mail
 * server was down will try again, and the second attempt is what actually goes wrong.
 */
class SecurityChangeNotifier
{
    /** A password was changed by its owner. */
    public const PASSWORD = 'password';

    /** The account's email address was changed. */
    public const EMAIL = 'email';

    /** A second factor was added. */
    public const FACTOR_ADDED = 'factor_added';

    /** A second factor was removed. */
    public const FACTOR_REMOVED = 'factor_removed';

    /** A passkey was registered. */
    public const PASSKEY_ADDED = 'passkey_added';

    /** A passkey was revoked. */
    public const PASSKEY_REMOVED = 'passkey_removed';

    /**
     * Report a change, to the account and — for an address change — to the old address.
     *
     * @param int         $userId   Whose account changed
     * @param string      $what     One of this class's constants
     * @param string      $detail   Optional specifics, e.g. the factor's label
     * @param string      $oldEmail The address that was on the account, when it changed
     * @return bool Whether anything was sent, for a caller that wants to log it
     */
    public static function notify(
        int $userId,
        string $what,
        string $detail = '',
        string $oldEmail = ''
    ): bool {
        if ($userId < 2 || !SecurityPolicy::notifiesSecurityChanges()) {
            return false;
        }

        try {
            $user = new \Pramnos\User\User($userId);
            $notification = new Notifications\SecurityChangeNotification($what, $detail);
            $notifier = new \Pramnos\Notification\Notifier();
            $sent = false;

            $current = (string) ($user->email ?? '');
            if ($current !== '' && filter_var($current, FILTER_VALIDATE_EMAIL) !== false) {
                $notifier->sendNow($user, $notification);
                $sent = true;
            }

            /**
             * And the address it used to have.
             *
             * Sent through a detached notifiable rather than by re-pointing the user
             * object: the user is a live model that other code holds, and mutating its
             * address to send one mail is the kind of change that leaks into a save().
             */
            $oldEmail = trim($oldEmail);
            if ($oldEmail !== ''
                && strcasecmp($oldEmail, $current) !== 0
                && filter_var($oldEmail, FILTER_VALIDATE_EMAIL) !== false
            ) {
                $notifier->sendNow(
                    new Notifications\PlainAddress($oldEmail),
                    new Notifications\SecurityChangeNotification($what, $detail, true)
                );
                $sent = true;
            }

            if ($sent) {
                ActivityLog::record($userId, 'security_change_notified', [
                    'what' => $what,
                ]);
            }

            return $sent;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'SecurityChangeNotifier failed for ' . $userId . ' (' . $what . '): '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }
    }
}
