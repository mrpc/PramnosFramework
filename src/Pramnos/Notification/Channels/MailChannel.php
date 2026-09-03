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
 * ## Notifications nobody is waiting for
 *
 * A notification may also declare `queueable(): bool`. When it returns true the message is
 * composed in this request and handed to the outbox instead of an SMTP connection, and
 * `mail:flush` delivers it. Use it for anything the recipient did not ask for and is not
 * waiting on — a security alert, an audit notice.
 *
 * Do **not** use it for a second-factor code or a sign-in link: somebody is looking at the
 * screen waiting for those, and a spool would trade a latency nobody measures for a wait
 * everybody feels. Declaring nothing keeps the message synchronous.
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

        /*
         * Queued only when the notification asks, and the default is the safe half.
         *
         * The three notifications a person is *waiting* for — a second-factor code, a
         * new-device link, an operator pressing Send with somebody on the phone — must go out
         * in this request. Queuing those would make the product worse in exchange for a
         * latency nobody is measuring, so opting in is per notification rather than a setting,
         * and a notification that says nothing keeps today's behaviour exactly.
         */
        if ($this->queues($notification)) {
            $email->queue();

            return;
        }

        $email->send();
    }

    /**
     * Whether this notification is content to be delivered by the outbox worker.
     *
     * Read through `method_exists()` like every other optional declaration a notification may
     * make, so nothing has to implement it.
     */
    protected function queues(NotificationInterface $notification): bool
    {
        return method_exists($notification, 'queueable') && (bool) $notification->queueable();
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

        if (method_exists($notification, 'mailPreheader')) {
            $preheader = trim((string) $notification->mailPreheader());

            if ($preheader !== '') {
                // Only when there is one: an empty call would replace the body-derived line
                // with nothing, and the wrapper would go back to opening with whatever it
                // happens to open with.
                $email->preheader($preheader);
            }
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
