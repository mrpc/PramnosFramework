<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification\Channels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;
use Pramnos\Notification\Channels\MailChannel;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotifiableTrait;
use Pramnos\Notification\NotificationInterface;

/**
 * Unit tests for MailChannel.
 *
 * The Email dependency is replaced with a spy/mock so no real SMTP
 * connection is attempted. Tests verify routing logic, field mapping,
 * and the skip conditions.
 */
#[CoversClass(MailChannel::class)]
class MailChannelTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Happy path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * MailChannel must call Email::send() exactly once when the notification
     * has a toMail() method and the notifiable has an email address.
     */
    public function testSendCallsEmailSend(): void
    {
        // Arrange
        $spy      = new SpyEmail();
        $channel  = new MailChannel($spy);
        $notif    = new SimpleMailNotification(['subject' => 'Hello', 'body' => '<p>Hi</p>']);
        $user     = new MailNotifiable('alice@example.com');

        // Act
        $channel->send($user, $notif);

        // Assert — Email::send() must have been called once
        $this->assertSame(1, $spy->sendCount, 'send() must call Email::send() exactly once');
    }

    /**
     * The subject and body from toMail() must be applied to the Email object
     * before send() is called.
     */
    public function testSendAppliesSubjectAndBodyFromNotification(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new MailChannel($spy);
        $notif   = new SimpleMailNotification(['subject' => 'Invoice Paid', 'body' => '<b>Done</b>']);
        $user    = new MailNotifiable('bob@example.com');

        // Act
        $channel->send($user, $notif);

        // Assert
        $this->assertSame('Invoice Paid', $spy->subject, 'Subject must match toMail() return');
        $this->assertSame('<b>Done</b>',  $spy->body,    'Body must match toMail() return');
        $this->assertSame('bob@example.com', $spy->to,   'Recipient must come from routeNotificationFor');
    }

    /**
     * When the notification returns a 'from' key, it must be applied as the
     * sender address on the Email object.
     */
    public function testSendAppliesFromWhenProvided(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new MailChannel($spy);
        $notif   = new SimpleMailNotification([
            'subject' => 'Hi',
            'body'    => 'body',
            'from'    => 'noreply@acme.com',
        ]);
        $user    = new MailNotifiable('carol@example.com');

        // Act
        $channel->send($user, $notif);

        // Assert
        $this->assertSame('noreply@acme.com', $spy->from,
            "'from' key in toMail() must be passed to Email::setFrom()");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Skip conditions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the notification does NOT implement toMail(), no email must be
     * sent — the channel must silently skip without an error.
     */
    public function testSendSkipsWhenNotificationHasNoToMailMethod(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new MailChannel($spy);
        $notif   = new NoMailNotification();
        $user    = new MailNotifiable('dave@example.com');

        // Act
        $channel->send($user, $notif);

        // Assert — Email::send() must NOT have been called
        $this->assertSame(0, $spy->sendCount,
            'MailChannel must skip silently when notification has no toMail()');
    }

    /**
     * When routeNotificationFor('mail') returns null (no email address), no
     * email must be sent.
     */
    public function testSendSkipsWhenAddressIsNull(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new MailChannel($spy);
        $notif   = new SimpleMailNotification(['subject' => 'Hi', 'body' => 'test']);
        $user    = new MailNotifiable(null);  // no email address

        // Act
        $channel->send($user, $notif);

        // Assert
        $this->assertSame(0, $spy->sendCount,
            'MailChannel must skip when notifiable has no email address');
    }

    /**
     * When the notifiable is a plain object (not NotifiableInterface) with a
     * public $email property, MailChannel must fall back to that property.
     */
    public function testSendFallsBackToEmailPropertyOnPlainObject(): void
    {
        // Arrange
        $spy        = new SpyEmail();
        $channel    = new MailChannel($spy);
        $notif      = new SimpleMailNotification(['subject' => 'Hi', 'body' => 'test']);
        $plainUser  = new \stdClass();
        $plainUser->email = 'eve@example.com';

        // Act
        $channel->send($plainUser, $notif);

        // Assert
        $this->assertSame(1, $spy->sendCount, 'Must fall back to $notifiable->email property');
        $this->assertSame('eve@example.com', $spy->to);
    }
    // ─────────────────────────────────────────────────────────────────────────
    // The optional mail declarations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A notification that declares nothing gets the transactional defaults.
     *
     * No wrapper choice, no tracking, no structured data. This is the important half of the
     * feature: a password reset must not acquire a tracking pixel because somebody added the
     * capability to the channel.
     */
    public function testANotificationThatDeclaresNothingGetsNothing(): void
    {
        // Arrange
        $spy = new SpyEmail();

        // Act
        (new MailChannel($spy))->send(
            new MailNotifiable('alice@example.com'),
            new SimpleMailNotification(['subject' => 'S', 'body' => 'B'])
        );

        // Assert
        $this->assertFalse($spy->templateSet);
        $this->assertSame(0, $spy->trackingCalls);
        $this->assertSame([], $spy->structured);
        $this->assertNull($spy->preheaderGiven);
    }

    /**
     * A declared preheader is handed over; an empty one is not.
     *
     * Handing over an empty string would replace the line `Email` derives from the body with
     * nothing at all — and the wrapper would go back to opening with whatever it happens to
     * open with, which is the state this whole feature exists to end.
     */
    public function testAnEmptyPreheaderIsNotHandedOver(): void
    {
        // Arrange
        $wanted   = new SpyEmail();
        $unwanted = new SpyEmail();

        // Act
        (new MailChannel($wanted))->send(
            new MailNotifiable('a@example.com'),
            new FullyDressedNotification(null, false, [], 'Your code is 481920')
        );
        (new MailChannel($unwanted))->send(
            new MailNotifiable('a@example.com'),
            new FullyDressedNotification(null, false, [], '   ')
        );

        // Assert
        $this->assertSame('Your code is 481920', $wanted->preheaderGiven);
        $this->assertNull($unwanted->preheaderGiven);
    }

    /**
     * A declared wrapper reaches the message.
     */
    public function testADeclaredWrapperReachesTheMessage(): void
    {
        // Arrange
        $spy = new SpyEmail();

        // Act
        (new MailChannel($spy))->send(
            new MailNotifiable('alice@example.com'),
            new FullyDressedNotification('receipt', false, [])
        );

        // Assert
        $this->assertTrue($spy->templateSet);
        $this->assertSame('receipt', $spy->templateGiven);
    }

    /**
     * `''` is passed through as `''` — "no wrapper", which is not "the default".
     *
     * Two answers that both look empty. Conflated, an installation that wraps everything can
     * never send one bare body — a machine-readable mail, or one whose body is already a whole
     * document.
     */
    public function testNoWrapperIsNotTheSameAsTheDefault(): void
    {
        // Arrange
        $spy = new SpyEmail();

        // Act
        (new MailChannel($spy))->send(
            new MailNotifiable('alice@example.com'),
            new FullyDressedNotification('', false, [])
        );

        // Assert
        $this->assertSame('', $spy->templateGiven, 'an empty wrapper name is a real answer');
    }

    /**
     * Tracking is asked for once when it is declared, and not at all when it is not.
     */
    public function testTrackingIsAskedForOnlyWhenDeclared(): void
    {
        // Arrange
        $wanted   = new SpyEmail();
        $unwanted = new SpyEmail();

        // Act
        (new MailChannel($wanted))->send(
            new MailNotifiable('a@example.com'),
            new FullyDressedNotification(null, true, [])
        );
        (new MailChannel($unwanted))->send(
            new MailNotifiable('a@example.com'),
            new FullyDressedNotification(null, false, [])
        );

        // Assert
        $this->assertSame(1, $wanted->trackingCalls);
        $this->assertSame(0, $unwanted->trackingCalls);
    }

    /**
     * Structured data blocks are handed over, and rubbish among them is dropped.
     *
     * The declaration is an application's array. A non-array entry reaching
     * `addStructuredData()` would be a type error at send time — on the password reset, in
     * production, at the moment somebody is locked out.
     */
    public function testStructuredDataIsHandedOverAndRubbishIsDropped(): void
    {
        // Arrange
        $spy = new SpyEmail();

        // Act
        (new MailChannel($spy))->send(
            new MailNotifiable('a@example.com'),
            new FullyDressedNotification(null, false, [
                ['@type' => 'EmailMessage'],
                'not an array',
                [],
                ['@type' => 'ConfirmAction'],
            ])
        );

        // Assert
        $this->assertSame(
            [['@type' => 'EmailMessage'], ['@type' => 'ConfirmAction']],
            $spy->structured
        );
    }

}

// =============================================================================
// Stubs and spies
// =============================================================================

/**
 * Email spy — records calls to setSubject(), setBody(), setTo(), setFrom(),
 * and send() without actually sending anything.
 *
 * Does NOT redeclare Email's untyped properties; reads them via parent's $subject,
 * $body, $to, $from. Only $sendCount is a new typed property.
 */
class SpyEmail extends Email
{
    public int $sendCount = 0;

    public int $trackingCalls = 0;

    public ?string $preheaderGiven = null;

    public bool $templateSet = false;

    public mixed $templateGiven = false;

    /** @var list<array<string, mixed>> */
    public array $structured = [];

    public function setSubject($subject)    { $this->subject = $subject; return $this; }
    public function setBody($body)          { $this->body    = $body;    return $this; }
    public function setTo($to)              { $this->to      = $to;      return $this; }
    public function setFrom($from)          { $this->from    = $from;    return $this; }
    public function send()                  { $this->sendCount++;        return true;  }

    public function preheader($text)
    {
        $this->preheaderGiven = (string) $text;

        return $this;
    }

    public function setTemplate(?string $template)
    {
        $this->templateSet   = true;
        $this->templateGiven = $template;

        return $this;
    }

    public function enableTracking($trackingId = null)
    {
        $this->trackingCalls++;

        return $this;
    }

    public function addStructuredData(array $data)
    {
        if ($data !== []) {
            $this->structured[] = $data;
        }

        return $this;
    }
}

/** A notification that declares every optional mail option. */
class FullyDressedNotification implements NotificationInterface
{
    public function __construct(
        private ?string $template,
        private bool $tracking,
        private array $blocks,
        private string $preheader = ''
    ) {}

    public function via(mixed $notifiable): array    { return ['mail']; }
    public function toMail(mixed $notifiable): array { return ['subject' => 'S', 'body' => 'B']; }

    public function mailTemplate(): ?string          { return $this->template; }
    public function trackingRequested(): bool        { return $this->tracking; }
    public function mailStructuredData(): array      { return $this->blocks; }
    public function mailPreheader(): string          { return $this->preheader; }
}

/** Notification that has a toMail() method. */
class SimpleMailNotification implements NotificationInterface
{
    public function __construct(private array $data) {}

    public function via(mixed $notifiable): array       { return ['mail']; }
    public function toMail(mixed $notifiable): array    { return $this->data; }
}

/** Notification that does NOT have a toMail() method. */
class NoMailNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['mail']; }
}

/** Notifiable with a configurable email address. */
class MailNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public function __construct(private ?string $email) {}

    public function routeNotificationFor(string $channel): mixed
    {
        return $channel === 'mail' ? $this->email : null;
    }
}
