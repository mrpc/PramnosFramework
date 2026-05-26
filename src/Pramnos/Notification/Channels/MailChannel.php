<?php

declare(strict_types=1);

namespace Pramnos\Notification\Channels;

use Pramnos\Email\Email;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotificationInterface;

/**
 * Notification channel that delivers via email.
 *
 * The notification must implement `toMail(mixed $notifiable): array` returning:
 *
 *   [
 *     'subject' => 'Invoice paid',
 *     'body'    => '<p>Your invoice...</p>',   // HTML or plain text
 *     'from'    => 'billing@example.com',       // optional
 *     'name'    => 'Billing Team',              // optional sender display name
 *   ]
 *
 * The recipient address is resolved via:
 *   1. $notifiable->routeNotificationFor('mail') — when NotifiableInterface
 *   2. $notifiable->email — direct property fallback
 *
 * The channel silently skips if the notification has no toMail() method or
 * the notifiable has no resolvable email address.
 *
 * @package     PramnosFramework
 * @subpackage  Notification\Channels
 */
class MailChannel implements ChannelInterface
{
    private ?Email $emailSender;

    /**
     * @param Email|null $emailSender  Inject a custom Email instance (for testing).
     */
    public function __construct(?Email $emailSender = null)
    {
        $this->emailSender = $emailSender;
    }

    /**
     * Send the notification as an email.
     *
     * Skips silently when:
     * - The notification has no toMail() method.
     * - The notifiable has no resolvable email address.
     */
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toMail')) {
            return;
        }

        $address = $this->resolveAddress($notifiable);
        if ($address === null || $address === '') {
            return;
        }

        $data = $notification->toMail($notifiable);

        $email = $this->createEmailSender();
        $email->setTo($address);
        $email->setSubject($data['subject'] ?? '');
        $email->setBody($data['body'] ?? '');

        if (!empty($data['from'])) {
            $email->setFrom($data['from']);
        }

        $email->send();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function createEmailSender(): Email
    {
        return $this->emailSender ?? new Email();
    }

    private function resolveAddress(mixed $notifiable): ?string
    {
        if ($notifiable instanceof NotifiableInterface) {
            $address = $notifiable->routeNotificationFor('mail');
            return is_string($address) ? $address : null;
        }

        return isset($notifiable->email) ? (string) $notifiable->email : null;
    }
}
