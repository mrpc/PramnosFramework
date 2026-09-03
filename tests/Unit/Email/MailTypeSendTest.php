<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;

/**
 * Declaring what kind of mail a message is, and what that then decides.
 *
 * Four things have to agree for a message on a list: the `List-Unsubscribe` header, its
 * one-click companion, a visible link in the body, and the check that skips an address which
 * has already left. They were decided at each call site — and the fourth was usually not
 * decided at all, so `offerUnsubscribe()` put a link in a message that was sent to somebody who
 * had used the previous one.
 */
#[CoversClass(Email::class)]
class MailTypeSendTest extends TestCase
{
    protected function setUp(): void
    {
        MailTypes::reset();
        MailTypes::register(new MailType('digest', 'Weekly digest', 'Every Monday.', 'digest'));
        MailTypes::register(new MailType('receipt', 'Receipts', 'What you paid for.'));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        MailTypes::reset();
        parent::tearDown();
    }

    /**
     * A message on a list gets the link and the headers from its type alone.
     */
    public function testATypeWithAListBringsItsUnsubscribeWiring(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->type('digest')->setTo('reader@example.com')->setBody('<p>Hello</p>');

        // Act
        $sent = $email->send();

        // Assert
        $this->assertTrue($sent);
        $this->assertSame('digest', $email->unsubscribeListValue());
        $this->assertStringContainsString('/unsubscribe?u=', $email->unsubscribeUrlValue());
    }

    /**
     * Transactional mail gets none of it.
     *
     * A password reset must arrive for somebody who unsubscribed from everything, mailbox
     * providers do not ask you to offer an opt-out on it, and offering one anyway teaches
     * people that the link does nothing.
     */
    public function testATransactionalTypeGetsNoUnsubscribeWiring(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->type('receipt')->setTo('reader@example.com')->setBody('<p>Thanks</p>');

        // Act
        $email->send();

        // Assert
        $this->assertSame('', $email->unsubscribeListValue());
        $this->assertSame('', $email->unsubscribeUrlValue());
    }

    /**
     * An address that has left the list is not sent to, and the send says so.
     *
     * The half that `offerUnsubscribe()` never did. A link in a message that goes out anyway is
     * the sender's promise broken on the next send — which is the thing a mailbox provider
     * counts, against the password resets too.
     */
    public function testAnAddressThatLeftTheListIsNotSentTo(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->optedOutOf = ['digest'];
        $email->type('digest')->setTo('gone@example.com')->setBody('<p>Hello</p>');

        // Act
        $sent = $email->send();

        // Assert
        $this->assertFalse($sent);
        $this->assertSame('', $email->delivered, 'nothing reached the mailer');
        $this->assertStringContainsString('unsubscribed', $email->getLastError());
    }

    /**
     * And the audit log still records it.
     *
     * «We did not send this, and this is why» is exactly what an audit log is for. Without the
     * row, «why did they not get it» has no answer anywhere.
     */
    public function testASuppressedMessageIsStillRecorded(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->optedOutOf = ['digest'];
        $email->type('digest')->setTo('gone@example.com')->setBody('<p>Hello</p>');

        // Act
        $email->send();

        // Assert
        $this->assertSame([false], $email->recorded);
    }

    /**
     * An opt-out never suppresses transactional mail.
     */
    public function testAnOptOutDoesNotSuppressTransactionalMail(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->optedOutOf = ['digest', 'all'];
        $email->type('receipt')->setTo('gone@example.com')->setBody('<p>Thanks</p>');

        // Act & Assert
        $this->assertTrue($email->send());
    }

    /**
     * `type()` and `setTo()` work in either order.
     *
     * The unsubscribe token has to name the recipient, and the recipient is usually set after
     * the message is described. Applying the type when it is *set* would have made the order a
     * silent requirement: the link would simply be missing.
     */
    public function testTheTypeMayBeDeclaredBeforeTheRecipient(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->setTo('reader@example.com')->setBody('<p>Hi</p>');
        $email->type('digest');

        // Act
        $email->send();

        // Assert
        $this->assertStringContainsString('/unsubscribe?u=', $email->unsubscribeUrlValue());
    }

    /**
     * A caller that built its own unsubscribe keeps it.
     *
     * An application may have a preferences URL of its own that it would rather send people to.
     * The type fills in what is missing; it does not overrule a decision already made.
     */
    public function testACallerThatBuiltItsOwnUnsubscribeKeepsIt(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->setTo('reader@example.com')->setBody('<p>Hi</p>');
        $email->offerUnsubscribe('special');
        $email->type('digest');

        // Act
        $email->send();

        // Assert
        $this->assertSame('special', $email->unsubscribeListValue());
    }

    /**
     * The declared type is what the audit log records the message as.
     *
     * `module` was whatever the sender happened to write, so the log could not answer "how many
     * digests went out" — the same kind of mail was three different strings.
     */
    public function testTheTypeIsWhatTheAuditLogRecords(): void
    {
        // Arrange
        $email = $this->mailer();

        // Act
        $email->type('digest');

        // Assert
        $this->assertSame('digest', $email->module);
        $this->assertSame('digest', $email->mailType());
    }

    /**
     * An unknown type is recorded and changes nothing else.
     *
     * The thing that would throw is a send, so a typo must not stop a password reset.
     */
    public function testAnUnknownTypeSendsAsTransactional(): void
    {
        // Arrange
        $email = $this->mailer();
        $email->type('typo')->setTo('reader@example.com')->setBody('<p>Hi</p>');

        // Act
        $sent = $email->send();

        // Assert
        $this->assertTrue($sent);
        $this->assertSame('', $email->unsubscribeListValue());
        $this->assertSame('typo', $email->module);
    }

    /** An Email whose mailer, log and opt-out store are all seams. */
    /**
     * An address that left the list is not queued either — suppression is at compose time.
     *
     * `queue()` and `send()` share `compose()`, so the decision is taken in the request that
     * wrote the message and with the unsubscribe records that request could see. Deferring it to
     * the worker would take the decision an hour later against a different set of records, and
     * the caller would have been told the message was accepted.
     *
     * The recorded status is the assertion that matters: a *queued* row for a suppressed address
     * is a message the worker would faithfully deliver to somebody who asked us to stop.
     */
    public function testAnAddressThatLeftTheListIsNotQueued(): void
    {
        // Arrange
        $email = $this->spooler();
        $email->optedOutOf = ['digest'];
        $email->type('digest');
        $email->setTo('gone@example.com');
        $email->setSubject('Weekly digest');
        $email->setBody('<p>News</p>');

        // Act
        $queued = $email->queue();

        // Assert
        $this->assertFalse($queued, 'a suppressed address must not be queued');
        $this->assertSame(
            [\Pramnos\Messaging\Mail::STATUS_FAILED],
            $email->statuses,
            'the refusal must be recorded as refused, not as queued'
        );
    }

    /**
     * And an address still on the list is queued rather than sent.
     *
     * The pair is the point: `queue()` has to make the same suppression decision `send()` makes
     * and then take the other branch. Asserting only the refusal above would pass on a `queue()`
     * that refused everything.
     */
    public function testAnAddressStillOnTheListIsQueuedAndNotSent(): void
    {
        // Arrange
        $email = $this->spooler();
        $email->type('digest');
        $email->setTo('here@example.com');
        $email->setSubject('Weekly digest');
        $email->setBody('<p>News</p>');

        // Act
        $queued = $email->queue();

        // Assert
        $this->assertTrue($queued);
        $this->assertSame([\Pramnos\Messaging\Mail::STATUS_QUEUED], $email->statuses);
        $this->assertSame('', $email->delivered, 'the outbox path must open no SMTP connection');
    }

    /**
     * An Email whose outbox is a list in memory, so a unit test needs no database.
     *
     * `writeMailRow()` is overridden rather than `recordMail()`, and that is not arbitrary: the
     * outbox calls the first directly so that an application overriding the second — as the
     * double below does — cannot silently record a queued message as sent.
     */
    private function spooler(): object
    {
        return new class () extends Email {
            /** @var list<string> */
            public array $optedOutOf = [];

            public string $delivered = '';

            /** @var list<int> */
            public array $statuses = [];

            protected function sendWithSymfonyMailer()
            {
                $this->delivered = (string) ($this->renderedBody ?? $this->body);

                return true;
            }

            protected function writeMailRow(int $status): bool
            {
                $this->statuses[] = $status;

                return true;
            }

            protected function optedOut(string $address, string $list): bool
            {
                return in_array($list, $this->optedOutOf, true)
                    || in_array('all', $this->optedOutOf, true);
            }
        };
    }

    private function mailer(): object
    {
        return new class () extends Email {
            /** @var list<string> */
            public array $optedOutOf = [];

            public string $delivered = '';

            /** @var list<bool> */
            public array $recorded = [];

            protected function sendWithSymfonyMailer()
            {
                $this->delivered = (string) ($this->renderedBody ?? $this->body);

                return true;
            }

            protected function recordMail(bool $success): void
            {
                $this->recorded[] = $success;
            }

            protected function optedOut(string $address, string $list): bool
            {
                return in_array($list, $this->optedOutOf, true)
                    || in_array('all', $this->optedOutOf, true);
            }

            public function unsubscribeListValue(): string
            {
                return (string) $this->unsubscribeList;
            }

            public function unsubscribeUrlValue(): string
            {
                return (string) $this->unsubscribe;
            }
        };
    }
}
