<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * "Your password was changed." — and what to do if it was not you.
 *
 * Sent by {@see \Pramnos\Auth\SecurityChangeNotifier} after a change to the things that
 * protect an account. Its whole job is to be readable in three seconds by somebody who did
 * not make the change, so it says what changed, when, and the one action that helps.
 *
 * Like the sign-in alert and unlike the sign-in link, **it carries no link**: it arrives
 * unbidden, and an unbidden authentication email with something to click is the shape of
 * the attack. It says to open the site directly.
 *
 * ## The copy sent to a former address is different
 *
 * When the address itself changed, the previous address gets a version that says so
 * explicitly — "this account no longer uses this address" — because the reader is
 * otherwise being told about an account they will now appear to have no connection to,
 * which reads as a mistake and gets deleted. It is the most important mail this class
 * sends: for a stolen session that changed the address first, it is the only one that
 * reaches the owner.
 */
class SecurityChangeNotification implements NotificationInterface
{
    private string $what;
    private string $detail;
    private bool $toFormerAddress;
    private int $when;
    private string $siteName;

    /**
     * @param string $what            One of SecurityChangeNotifier's constants
     * @param string $detail          Optional specifics, e.g. which factor
     * @param bool   $toFormerAddress Addressed to the account's previous address
     */
    public function __construct(string $what, string $detail = '', bool $toFormerAddress = false)
    {
        $this->what            = $what;
        $this->detail          = $detail;
        $this->toFormerAddress = $toFormerAddress;
        $this->when            = time();
        $this->siteName        = defined('URL') ? (string) URL : 'this site';
    }

    /**
     * @param mixed $notifiable
     * @return string[]
     */
    /**
     * Mail always; push as well, except on the copy sent to a former address.
     *
     * The change itself is what a push is for: a password, an email address or a second factor
     * has just been altered, and if it was not the owner who did it, the minutes between the
     * mail being sent and the mailbox being opened are the minutes that matter. Worse — when
     * the address is what changed, the mail goes to the new one, which an attacker who changed
     * it now controls. A push reaches a browser they never subscribed in.
     *
     * **Not on the former-address copy.** An address change sends two mails, to the old address
     * and the new one, and both are the same account — so pushing on both would deliver the
     * same warning to the same devices twice. The copy to the new address carries the push; the
     * one to the former address is mail alone, which is its whole purpose.
     *
     * Never the database channel, for the reason `NewSignInNotification` gives: an in-app
     * warning is seen by whoever is currently signed in, and in the case worth warning about
     * that is the wrong person.
     *
     * @param  mixed $notifiable
     * @return string[]
     */
    public function via(mixed $notifiable): array
    {
        $channels = array('mail');

        if (!$this->toFormerAddress
            && \Pramnos\Push\Subscriptions::exist(self::accountOf($notifiable))
        ) {
            $channels[] = 'push';
        }

        return $channels;
    }

    /**
     * Nobody is waiting for this one either, so it goes through the outbox.
     *
     * It reports a change that has **already happened** — the password is changed, the factor
     * is gone — and `SecurityChangeNotifier` is best-effort precisely because the notification
     * is never worth failing the change it describes. Holding the response open for SMTP is the
     * same trade in a different currency: somebody who has just changed their password waits
     * for the mail server instead of seeing the screen that says it worked.
     *
     * This matters most on the address-change path, which sends **two** mails — the new address
     * and the previous one. That was two SMTP round trips inside one form submission.
     */
    public function queueable(): bool
    {
        return true;
    }

    /**
     * The change, in the length a lock screen has.
     *
     * No link, deliberately — the mail carries those. A notification that appears unprompted
     * and offers a button to "secure your account" is the shape of the attack this warns about,
     * and teaching people to tap one is worse than the warning is good.
     *
     * The `tag` is per kind of change, so a password change and a factor being removed do not
     * collapse into one another — they are different facts, and somebody who did one but not
     * the other needs to see the one they did not do.
     *
     * @param  mixed $notifiable
     * @return array<string, mixed>
     */
    public function toPush(mixed $notifiable): array
    {
        return array(
            'title' => t('Security change on your account'),
            'body'  => trim($this->headline() . ' ' . t('If this was not you, act now.')),
            'tag'   => 'securitychange-' . $this->what,
        );
    }

    /**
     * Which account a notifiable is, in the order every channel resolves it.
     *
     * `routeNotificationFor('push')`, then `userid`, then `id` — the same order `PushChannel`
     * uses, so an object that works for one channel works for all of them.
     */
    private static function accountOf(mixed $notifiable): int
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $routed = $notifiable->routeNotificationFor('push');

            if (is_numeric($routed)) {
                return (int) $routed;
            }
        }

        foreach (array('userid', 'id') as $property) {
            if (is_object($notifiable) && isset($notifiable->$property)) {
                return (int) $notifiable->$property;
            }
        }

        return 0;
    }

    /**
     * @param mixed $notifiable
     * @return array{subject: string, body: string}
     */
    public function toMail(mixed $notifiable): array
    {
        $headline = $this->headline();
        $when     = date('j M Y, H:i T', $this->when);
        $detail   = $this->detail !== ''
            ? ' (' . htmlspecialchars($this->detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')'
            : '';

        $body = '<p>' . htmlspecialchars($headline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . $detail . '</p>'
            . '<p>' . t('Time: %s', htmlspecialchars($when, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</p>';

        if ($this->toFormerAddress) {
            $body .= '<p>' . t(
                'This account no longer uses this email address. You are being told because '
                . 'the change was made <strong>from</strong> this address, and if it was not '
                . 'you, it means somebody else has access to the account.'
            ) . '</p>';
        }

        $body .= '<p>' . t('If you made this change, there is nothing to do.') . '</p>'
            . '<p>' . t(
                '<strong>If you did not</strong>, somebody else is using your account. Open '
                . 'the site yourself — not a link in an email, including this one — and change '
                . 'your password. If you cannot sign in, contact us.'
            ) . '</p>';

        return array(
            'subject' => t('%s — %s', $headline, $this->siteName),
            'body'    => $body,
        );
    }

    /**
     * One line naming what happened, in the words the reader would use.
     *
     * Not the constant, and not the method name: "FACTOR_ADDED" tells a person nothing,
     * and "two-factor authentication was enabled" tells them what to check.
     */
    private function headline(): string
    {
        return match ($this->what) {
            \Pramnos\Auth\SecurityChangeNotifier::PASSWORD
                => t('Your password was changed'),
            \Pramnos\Auth\SecurityChangeNotifier::EMAIL
                => t('The email address on your account was changed'),
            \Pramnos\Auth\SecurityChangeNotifier::FACTOR_ADDED
                => t('A second sign-in step was added to your account'),
            \Pramnos\Auth\SecurityChangeNotifier::FACTOR_REMOVED
                => t('A second sign-in step was removed from your account'),
            \Pramnos\Auth\SecurityChangeNotifier::PASSKEY_ADDED
                => t('A passkey was added to your account'),
            \Pramnos\Auth\SecurityChangeNotifier::PASSKEY_REMOVED
                => t('A passkey was removed from your account'),
            default => t('Your account security settings were changed'),
        };
    }
}
