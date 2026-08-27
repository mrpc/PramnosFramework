<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * "Open this link to finish signing in."
 *
 * The mail behind {@see \Pramnos\Auth\NewDeviceAuthLink}. It carries a link, which the
 * sign-in *alert* deliberately does not — the reasoning for the difference is in that
 * service's docblock, and it comes down to this being the reply to something the person
 * did seconds ago rather than an unexpected arrival.
 *
 * What it therefore has to do is make that context obvious to the reader, so that the
 * same person is not trained to click a link in a mail they did *not* expect. So the mail
 * says which device asked, says the link is useless to anybody else, and says plainly
 * what to do if they were not signing in — which is nothing, plus change the password,
 * because somebody who reaches this point already had it.
 */
class NewDeviceAuthLinkNotification implements NotificationInterface
{
    private string $url;
    private int $ttl;
    private string $siteName;
    private string $device;

    /**
     * @param string $url      The absolute, single-use URL
     * @param int    $ttl      Lifetime in seconds, for the "expires in" line
     * @param string $device   A human description of the device, when known
     * @param string $siteName Shown in the subject; falls back to the URL
     */
    public function __construct(string $url, int $ttl = 900, string $device = '', string $siteName = '')
    {
        $this->url      = $url;
        $this->ttl      = $ttl > 0 ? $ttl : 900;
        $this->device   = $device !== ''
            ? $device
            : \Pramnos\Auth\SignInFingerprint::describe(\Pramnos\Auth\SignInFingerprint::current());
        $this->siteName = $siteName !== ''
            ? $siteName
            : (defined('URL') ? (string) URL : 'this site');
    }

    /**
     * Mail only. A database notification would put the link in the panel of the
     * half-finished session, which is the session this is meant to gate.
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
        $url     = htmlspecialchars($this->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $device  = htmlspecialchars($this->device, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $expiry = $minutes === 1
            ? t('It works once and expires in one minute.')
            : t('It works once and expires in %s minutes.', $minutes);

        $body = '<p>' . t(
                'Somebody just entered your password on <strong>%s</strong>, which this '
                . 'account has not been used from before.',
                $device
            ) . '</p>'
            . '<p>' . t('<strong>If that was you</strong>, open this link to finish signing in:')
            . '</p>'
            . '<p><a href="' . $url . '">' . $url . '</a></p>'
            . '<p>' . $expiry . '</p>'
            . '<p>' . t(
                '<strong>If it was not you</strong>, do nothing — without this link the '
                . 'sign-in cannot continue. But somebody has your password, so change it: '
                . 'open the site yourself rather than following a link in an email.'
            ) . '</p>';

        return array(
            'subject' => t('Finish signing in to %s — %s', $this->siteName, $device),
            'body'    => $body,
        );
    }

    /** The URL this notification carries, for a test that asserts what was sent. */
    public function url(): string
    {
        return $this->url;
    }
}
