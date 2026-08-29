<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * One message, written by a person, to whichever channels were asked for.
 *
 * Every other notification in an application is a **class per event** — `InvoicePaid`,
 * `NewSignIn` — and that is the right shape when the event is known in advance. This is for the
 * case that is not: an operator writing a sentence to one account, a broadcast composed in an
 * administration screen, a test send. There is no event to name, so there is no class to write.
 *
 * ```php
 * $user->notify(
 *     (new Message('Your export is ready', '<p>It is on your downloads page.</p>'))
 *         ->to('mail', 'database', 'push')
 *         ->link(sURL . 'account/downloads')
 * );
 * ```
 *
 * Every channel gets a shape it can use: the mail channel the subject and body, the database
 * channel the same as a stored record, the push channel a title and a short body. A message that
 * names a channel it has nothing for is not an error — the channel skips it.
 *
 * ### The mail options
 *
 * The chained setters below are the same capabilities `Email` has, declared where a notification
 * can reach them: a wrapper, an unsubscribe list, open/click tracking, a Gmail action. They are
 * read by {@see \Pramnos\Notification\Channels\MailChannel} through optional methods, so a
 * notification that wants none of them declares nothing and gets the transactional defaults.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Message implements NotificationInterface
{
    /** @var list<string> */
    protected array $channels = ['database'];

    protected string $url = '';

    protected string $list = '';

    protected ?string $template = null;

    protected bool $tracking = false;

    protected string $preheaderText = '';

    protected string $from = '';

    /** @var list<array<string, mixed>> */
    protected array $structuredData = [];

    /** @var array<string, mixed> */
    protected array $push = [];

    /**
     * @param string $subject What the message is about — the mail subject, the push title
     * @param string $body    HTML for mail; a text-only channel gets the text of it
     */
    public function __construct(protected string $subject, protected string $body)
    {
    }

    /**
     * Which channels this one goes to.
     *
     * @return $this
     */
    public function to(string ...$channels): static
    {
        $this->channels = array_values(array_filter(array_map('trim', $channels)));

        return $this;
    }

    /**
     * Where a click goes — the push notification's target, and the stored record's link.
     *
     * @return $this
     */
    public function link(string $url): static
    {
        $this->url = trim($url);

        return $this;
    }

    /**
     * The list this belongs to, which makes it non-transactional.
     *
     * Two things follow, both in `MailChannel`: an address that has opted out of this list is
     * skipped, and the message goes out with a working unsubscribe. A message that names no
     * list gets neither — which is right for a password reset and wrong for a newsletter.
     *
     * @return $this
     */
    public function list(string $list): static
    {
        $this->list = trim($list);

        return $this;
    }

    /**
     * The wrapper to render the mail in — `''` for none, `null` for the installation's default.
     *
     * @return $this
     */
    public function template(?string $template): static
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Ask for open and click tracking.
     *
     * A request, not a switch: `Tracking` still refuses unless the installation has it on and
     * the message belongs to a list somebody agreed to receive. See the email guide.
     *
     * @return $this
     */
    public function track(bool $tracking = true): static
    {
        $this->tracking = $tracking;

        return $this;
    }

    /**
     * The line a mailbox list shows beside the subject.
     *
     * Left unset, `Email` derives it from the body — which is better than the wrapper's own
     * opening, and worse than a sentence somebody wrote for the inbox.
     *
     * @return $this
     */
    public function preheader(string $text): static
    {
        $this->preheaderText = trim($text);

        return $this;
    }

    /**
     * A sender other than the installation's default.
     *
     * @return $this
     */
    public function from(string $address): static
    {
        $this->from = trim($address);

        return $this;
    }

    /**
     * A schema.org block for the message head — a Gmail action, a brand mark.
     *
     * @param  array<string, mixed> $data From {@see \Pramnos\Email\Actions}
     * @return $this
     */
    public function action(array $data): static
    {
        if ($data !== []) {
            $this->structuredData[] = $data;
        }

        return $this;
    }

    /**
     * Anything else the push payload should carry — an icon, a tag, action buttons.
     *
     * @param  array<string, mixed> $options
     * @return $this
     */
    public function pushOptions(array $options): static
    {
        $this->push = $options;

        return $this;
    }

    // -------------------------------------------------------------------------
    // What each channel reads
    // -------------------------------------------------------------------------

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toMail(mixed $notifiable): array
    {
        return array_filter([
            'subject' => $this->subject,
            'body'    => $this->body,
            'from'    => $this->from,
        ], static fn ($value): bool => $value !== '');
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return array_filter([
            'subject' => $this->subject,
            'message' => $this->body,
            'url'     => $this->url,
        ], static fn ($value): bool => $value !== '');
    }

    /**
     * The push payload.
     *
     * The body is stripped of markup and shortened, because a push notification is two lines on
     * a lock screen — HTML would be shown as HTML, and a paragraph would be truncated by the
     * operating system at a point nobody chose.
     *
     * @return array<string, mixed>
     */
    public function toPush(mixed $notifiable): array
    {
        $text = trim(html_entity_decode(strip_tags(
            preg_replace('~<br\s*/?>|</p>~i', ' ', $this->body) ?? $this->body
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return array_filter([
            'title' => $this->subject,
            'body'  => preg_replace('~\s+~u', ' ', $text) ?? $text,
            'url'   => $this->url,
        ], static fn ($value): bool => $value !== '' && $value !== null) + $this->push;
    }

    // -------------------------------------------------------------------------
    // The optional declarations MailChannel looks for
    // -------------------------------------------------------------------------

    public function unsubscribeList(): string
    {
        return $this->list;
    }

    public function mailTemplate(): ?string
    {
        return $this->template;
    }

    public function mailPreheader(): string
    {
        return $this->preheaderText;
    }

    public function trackingRequested(): bool
    {
        return $this->tracking;
    }

    /** @return list<array<string, mixed>> */
    public function mailStructuredData(): array
    {
        return $this->structuredData;
    }
}
