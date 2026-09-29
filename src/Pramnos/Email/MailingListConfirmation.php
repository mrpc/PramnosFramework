<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Notification\NotificationInterface;

/**
 * The mail that asks an address to confirm it wants a list — the second half of double opt-in.
 *
 * Transactional: it is the consequence of somebody asking, so it goes whatever the address has
 * opted out of, and it carries no unsubscribe link of its own — ignoring it is the way to say
 * no. The link leads to a page with a button, not to the confirmation itself, because mail
 * scanners follow every link in a message and would otherwise confirm on the reader's behalf.
 */
class MailingListConfirmation implements NotificationInterface
{
    private string $siteName;

    public function __construct(
        private string $link,
        private ?MailType $type = null,
        string $siteName = ''
    ) {
        $this->siteName = $siteName !== '' ? $siteName : (string) (\Pramnos\Application\Settings::getSetting('sitename') ?: (defined('URL') ? URL : 'this site'));
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function queueable(): bool
    {
        return true;
    }

    public function toMail(mixed $notifiable): array
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = $this->type !== null ? $this->type->label : t('our mailing list');
        $days  = intdiv(MailingList::CONFIRM_TTL, 86400);

        $body = '<p>' . t('Somebody — we hope you — asked for this address to receive %s from %s.', '<strong>' . $e($label) . '</strong>', $e($this->siteName)) . '</p>'
            . ($this->type !== null && $this->type->description !== '' ? '<p><em>' . $e($this->type->description) . '</em></p>' : '')
            . '<p>' . t('To confirm, open this link and press the button:') . '</p>'
            . '<p><a href="' . $e($this->link) . '">' . $e($this->link) . '</a></p>'
            . '<p>' . t('It works for %s days. If you did not ask, ignore this message and nothing will be sent to you.', $days) . '</p>';

        return [
            'subject' => t('Confirm your subscription to %s', $label),
            'body'    => $body,
        ];
    }

    public function link(): string
    {
        return $this->link;
    }
}
