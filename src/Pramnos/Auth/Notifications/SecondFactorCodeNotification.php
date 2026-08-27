<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * "Your code is 123456."
 *
 * The mail behind {@see \Pramnos\Auth\EmailSecondFactor}. Sent only in answer to
 * something the person just did — they submitted a password a moment ago and are looking
 * at a form waiting for this. That is what separates it from
 * {@see NewSignInNotification}, which arrives unbidden.
 *
 * ## A code, and no link
 *
 * The same rule the sign-in alert follows, for the same reason: a link in an
 * authentication email is the shape of the attack, and one that signs somebody in is the
 * most valuable link an attacker can get forwarded. A code has to be typed into a page
 * the person already has open, which means it cannot be used by whoever else reads the
 * mailbox unless they also have the password. So the mail carries six digits and
 * nothing to click.
 *
 * ## What it says beyond the code
 *
 * How long the code lasts, because a person who reads the mail twenty minutes later
 * needs to know why it stopped working rather than concluding the site is broken. And a
 * line telling them to ignore it if they were not signing in — which is the only signal
 * available that somebody else has their password, and costs nothing to include.
 *
 * Mail only. A database notification would put the code in the panel of whoever is
 * holding the half-finished session, which is exactly the person the factor exists to
 * stop.
 */
class SecondFactorCodeNotification implements NotificationInterface
{
    /** The code, in the clear — this object exists to deliver it. */
    private string $code;

    /** How long it lives, in seconds. */
    private int $ttl;

    /** Shown in the subject. */
    private string $siteName;

    /**
     * @param string $code     The six-digit code, as generated
     * @param int    $ttl      Lifetime in seconds, for the "expires in" line
     * @param string $siteName Shown in the subject; falls back to the URL
     */
    public function __construct(string $code, int $ttl = 600, string $siteName = '')
    {
        $this->code     = $code;
        $this->ttl      = $ttl > 0 ? $ttl : 600;
        $this->siteName = $siteName !== ''
            ? $siteName
            : (defined('URL') ? (string) URL : 'this site');
    }

    /**
     * Mail only — see the class docblock.
     *
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
        $minutes = (int) max(1, round($this->ttl / 60));
        $code    = htmlspecialchars($this->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>Your sign-in code is:</p>'
            . '<p style="font-size:28px;font-weight:bold;letter-spacing:4px;">'
            . $code . '</p>'
            . '<p>It expires in ' . $minutes . ' '
            . ($minutes === 1 ? 'minute' : 'minutes') . ', and can be used once.</p>'
            . '<p>If you were not signing in, somebody else has your password. '
            . 'Ignore this code and change it — open the site yourself rather than '
            . 'following a link in an email.</p>';

        return array(
            // The code is in the subject as well: it is what the person is looking for,
            // and on a phone the subject line is often all they have to read.
            'subject' => $code . ' is your sign-in code for ' . $this->siteName,
            'body'    => $body,
        );
    }

    /**
     * The code this notification carries.
     *
     * For a test that needs to assert what was sent without parsing a mail body.
     */
    public function code(): string
    {
        return $this->code;
    }
}
