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

        // An operator's template wins over the text compiled into the class, field by
        // field. {@see applyTemplate()}
        $data = $this->applyTemplate($notification, $data);

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

        // After applyOptions(), which sets the wrapper the *class* asked for: when an
        // operator's template names one, theirs is the later decision and wins.
        if (!empty($data['emailtemplate'])) {
            $email->setTemplate((string) $data['emailtemplate']);
        }

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
     * Let an operator's `mailtemplates` row replace what the class composed.
     *
     * ## Why this is here rather than in each notification
     *
     * The `mailtemplates` table, its model and a full administration screen — list, edit,
     * delete and **test send** — shipped long ago, and **nothing read a template when
     * sending**. An operator could write one, save it, send themselves a test of it, and
     * every real message still went out with the text compiled into the class. A screen
     * that implies a capability the system does not have is worse than no screen.
     *
     * This is the one place every notification's mail passes through, so wiring it here
     * makes each one overridable by declaring a key rather than by repeating a lookup.
     *
     * ## The rule
     *
     * A notification opts in by declaring `storedMailTemplate(): array`, read through
     * `method_exists()` like `unsubscribeList()` and `queueable()` beside it — so nothing
     * has to implement it and a notification that says nothing behaves exactly as before:
     *
     * ```php
     * public function storedMailTemplate(): array
     * {
     *     return [
     *         'category' => 'auth.twofactor_code',
     *         'vars'     => ['code' => $this->code, 'minutes' => $minutes],
     *     ];
     * }
     * ```
     *
     * **`storedMailTemplate()`, not `mailTemplate()`** — that name was taken, by the
     * optional declaration naming the *HTML wrapper*. Two different things, and reusing
     * the name meant `applyOptions()` casting this array to a string and setting the
     * wrapper to `Array`. Caught by the first test written against it.
     *
     * **Field by field, and empty means keep the default.** A row whose body is empty is
     * an operator who filled in the subject and nothing else, not an instruction to send an
     * empty email. So a non-empty template subject replaces the subject, a non-empty
     * template body replaces the body, and anything left blank keeps what the class wrote.
     *
     * The template's `emailtemplate` column — the HTML wrapper — is applied too, because
     * choosing the wrapper is most of why an operator opens this screen, and until now
     * that field was written to the database and read only by a test send.
     *
     * The language is the reader's: `Notifier` has already switched the catalogue to the
     * notifiable's own before any channel runs, so asking for the current one here asks for
     * theirs.
     *
     * A lookup that raises — no table on an installation that never migrated messaging —
     * leaves the composed message alone. A template is an override; failing to find one is
     * not a failure to send.
     *
     * @param  array<string, mixed> $data What `toMail()` composed
     * @return array<string, mixed>
     */
    protected function applyTemplate(NotificationInterface $notification, array $data): array
    {
        if (!method_exists($notification, 'storedMailTemplate')) {
            return $data;
        }

        $declared = (array) $notification->storedMailTemplate();
        $category = trim((string) ($declared['category'] ?? ''));

        if ($category === '') {
            return $data;
        }

        try {
            $template = $this->templateFor($category);
        } catch (\Throwable $exception) {
            // The guard is here rather than inside the seam, because the seam is
            // overridable: an application's own lookup must not be able to stop the mail
            // either. A template is an override; failing to find one is not a failure to
            // send.
            \Pramnos\Logs\Logger::log(
                'MailChannel could not look up the template "' . $category . '": '
                . $exception->getMessage(),
                'mail'
            );

            return $data;
        }

        if ($template === null) {
            return $data;
        }

        $rendered = \Pramnos\Messaging\MailTemplate::fill(
            $template,
            (array) ($declared['vars'] ?? [])
        );

        if ($rendered['subject'] !== '') {
            $data['subject'] = $rendered['subject'];
        }

        if ($rendered['body'] !== '') {
            $data['body'] = $rendered['body'];
        }

        $wrapper = trim((string) ($template['emailtemplate'] ?? ''));

        if ($wrapper !== '') {
            $data['emailtemplate'] = $wrapper;
        }

        return $data;
    }

    /**
     * The stored template for a category, or null when there is none.
     *
     * `['subject' => …, 'body' => …, 'emailtemplate' => …]`, unsubstituted.
     *
     * A thin, overridable seam — the same idiom as `MailTemplatesController::mailer()` —
     * so the substitution rules above can be tested without a database. The language is
     * the reader's: `Notifier` switches the catalogue to the notifiable's own before any
     * channel runs, so asking for the current one asks for theirs.
     *
     * It may raise — an installation that never migrated the messaging tables has no such
     * table — and {@see applyTemplate()} absorbs that, so a missing table still sends mail.
     */
    protected function templateFor(string $category): ?array
    {
        $language = (string) \Pramnos\Translator\Language::getInstance()->currentlang();

        return \Pramnos\Messaging\MailTemplate::lookup($category, $language);
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
