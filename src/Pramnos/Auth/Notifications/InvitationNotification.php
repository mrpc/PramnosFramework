<?php

declare(strict_types=1);

namespace Pramnos\Auth\Notifications;

use Pramnos\Notification\NotificationInterface;

/**
 * The mail that carries an invitation's link.
 *
 * The recipient has no account yet, so the notifiable is anything with an `email`. Queued: the
 * link works whether the mail leaves now or in a minute, and an invitation is not somebody
 * waiting at a screen.
 */
class InvitationNotification implements NotificationInterface
{
    private string $siteName;

    public function __construct(
        private string $link,
        private int $ttl,
        private string $inviter = '',
        private string $note = '',
        string $siteName = ''
    ) {
        $this->siteName = $siteName !== '' ? $siteName : (defined('URL') ? (string) URL : 'this site');
    }

    public function via(mixed $notifiable): array
    {
        return array('mail');
    }

    public function queueable(): bool
    {
        return true;
    }

    public function toMail(mixed $notifiable): array
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hours = $this->hours();

        $body = '<p>' . ($this->inviter !== ''
                ? t('%s has invited you to create an account on <strong>%s</strong>.', $e($this->inviter), $e($this->siteName))
                : t('You have been invited to create an account on <strong>%s</strong>.', $e($this->siteName)))
            . '</p>'
            . ($this->note !== '' ? '<p><em>' . $e($this->note) . '</em></p>' : '')
            . '<p>' . t('Open this link to choose a username and a password:') . '</p>'
            . '<p><a href="' . $e($this->link) . '">' . $e($this->link) . '</a></p>'
            . '<p>' . t('It works once, for this address only, and expires in %s hours.', $hours) . '</p>'
            . '<p>' . t('If you were not expecting it, you can ignore this message.') . '</p>';

        return array(
            'subject' => t('You are invited to %s', $this->siteName),
            'body'    => $body,
        );
    }

    public function storedMailTemplate(): array
    {
        return array(
            'category' => 'auth.invitation',
            'vars'     => array(
                'link'     => $this->link,
                'hours'    => $this->hours(),
                'inviter'  => $this->inviter,
                'note'     => $this->note,
                'sitename' => $this->siteName,
            ),
        );
    }

    public function link(): string
    {
        return $this->link;
    }

    private function hours(): int
    {
        return (int) max(1, round($this->ttl / 3600));
    }
}
