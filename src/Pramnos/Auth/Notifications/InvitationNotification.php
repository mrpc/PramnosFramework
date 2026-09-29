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

    /** The line a mail client shows beside the subject; empty means derived from the body. */
    private string $preheader = '';

    public function __construct(
        private string $link,
        private int $ttl,
        private string $inviter = '',
        private string $note = '',
        string $siteName = ''
    ) {
        $this->siteName = $siteName !== '' ? $siteName : (defined('URL') ? (string) URL : 'this site');
    }

    /**
     * The same invitation with a preheader of its own.
     *
     * Without one the line is derived from the body — the first sentence, which for an
     * invitation is "X has invited you…" and says nothing the subject did not.
     */
    public function withPreheader(string $preheader): static
    {
        $copy = clone $this;
        $copy->preheader = trim($preheader);

        return $copy;
    }

    /** Read by the mail channel; empty leaves the body-derived line. */
    public function mailPreheader(): string
    {
        return $this->preheader;
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
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>' . ($this->inviter !== ''
                ? t('%s has invited you to create an account on <strong>%s</strong>.', $e($this->inviter), $e($this->siteName))
                : t('You have been invited to create an account on <strong>%s</strong>.', $e($this->siteName)))
            . '</p>'
            . ($this->note !== '' ? '<p><em>' . $e($this->note) . '</em></p>' : '')
            . '<p>' . t('Open this link to choose a username and a password:') . '</p>'
            . '<p><a href="' . $e($this->link) . '">' . $e($this->link) . '</a></p>'
            . '<p>' . ($this->wholeDays() !== null
                ? t('It works once, for this address only, and expires in %s days.', $this->wholeDays())
                : t('It works once, for this address only, and expires in %s hours.', $this->hours())) . '</p>'
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
                // A 21-day link reads "504 hours" through {hours}. {days} is the whole number
                // of days, or — when the lifetime is not one — the hours as a fraction of a day,
                // so a template that says "days" is never wrong, only occasionally precise.
                'days'     => $this->wholeDays() ?? rtrim(rtrim(number_format($this->ttl / 86400, 1, '.', ''), '0'), '.'),
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

    /** The lifetime in days when it is a whole number of them, otherwise null. */
    private function wholeDays(): ?int
    {
        return $this->ttl >= 86400 && $this->ttl % 86400 === 0 ? intdiv($this->ttl, 86400) : null;
    }
}
