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
    public function via(mixed $notifiable): array
    {
        return array('mail');
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
