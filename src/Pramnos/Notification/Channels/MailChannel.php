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
 * ## Notifications that belong to a list
 *
 * A notification may declare `unsubscribeList(): string`. When it does, two things happen: the
 * address is checked against the unsubscribe records and skipped if it has opted out, and the
 * message goes out with a `List-Unsubscribe` header, its one-click companion and a visible
 * link in the footer.
 *
 * ```php
 * class WeeklyDigest implements NotificationInterface
 * {
 *     public function unsubscribeList(): string { return 'digest'; }
 *     public function toMail(mixed $notifiable): array { … }
 * }
 * ```
 *
 * A notification that declares nothing is transactional and gets none of it — no link, no
 * header, no suppression. That is the right default: a password reset must arrive even for
 * somebody who unsubscribed from everything, and an unsubscribe link on it teaches people the
 * link does nothing.
 *
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

        $list = $this->listFor($notification);

        // Suppression before composition: an address that asked us to stop is a message not
        // sent, and rendering the body first only wastes the work.
        if ($list !== '' && \Pramnos\Email\Unsubscribe::isOptedOut($address, $list)) {
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

        if ($list !== '') {
            $email->offerUnsubscribe($list, $address);
        }

        $this->applyOptions($email, $notification);

        $email->send();
    }

    /**
     * The optional declarations a notification may make about its mail.
     *
     * The same shape as `unsubscribeList()` above and for the same reason: a notification that
     * wants none of this declares nothing and gets the transactional defaults. Declared, they
     * are the capabilities `Email` already has — a wrapper, tracking, a Gmail action — reachable
     * from a notification without the caller having to abandon `notify()` and build an `Email`
     * by hand, which is what everybody did instead.
     *
     * `trackingRequested()` is a *request*: `Tracking` still refuses unless the installation has
     * it on and the message belongs to a list somebody agreed to receive.
     */
    protected function applyOptions(Email $email, NotificationInterface $notification): void
    {
        if (method_exists($notification, 'mailTemplate')) {
            // `null` is "the installation's default" and `''` is "no wrapper at all" — two
            // different answers, so the value is passed through rather than tested for empty.
            $template = $notification->mailTemplate();
            $email->setTemplate($template === null ? null : (string) $template);
        }

        if (method_exists($notification, 'trackingRequested') && $notification->trackingRequested()) {
            $email->enableTracking();
        }

        if (method_exists($notification, 'mailStructuredData')) {
            foreach ((array) $notification->mailStructuredData() as $block) {
                if (is_array($block)) {
                    $email->addStructuredData($block);
                }
            }
        }
    }

    /**
     * The list this notification belongs to, or an empty string for transactional mail.
     */
    protected function listFor(NotificationInterface $notification): string
    {
        if (!method_exists($notification, 'unsubscribeList')) {
            return '';
        }

        return trim((string) $notification->unsubscribeList());
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
