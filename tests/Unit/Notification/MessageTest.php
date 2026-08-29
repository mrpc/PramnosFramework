<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\Message;

/**
 * One message, to whichever channels were asked for.
 *
 * The shape each channel reads is the whole contract, and each is a different medium: a mail
 * body is HTML, a push notification is two lines on a lock screen, a stored record is neither.
 * Handing the same string to all three is how a push arrives reading `<p>Your export is
 * ready.</p>`.
 */
#[CoversClass(Message::class)]
class MessageTest extends TestCase
{
    /**
     * By default it goes to the in-app record and nowhere else.
     *
     * The safe default: a record nobody reads costs nothing, and a mail nobody asked for costs
     * a complaint.
     */
    public function testItGoesToTheRecordUnlessToldOtherwise(): void
    {
        // Assert
        $this->assertSame(['database'], (new Message('Hello', 'Body'))->via(null));
    }

    /**
     * The channels are the ones named, in the order named.
     */
    public function testTheChannelsAreTheOnesNamed(): void
    {
        // Act
        $message = (new Message('Hello', 'Body'))->to('mail', 'push');

        // Assert
        $this->assertSame(['mail', 'push'], $message->via(null));
    }

    /**
     * An empty channel name is dropped rather than dispatched to.
     *
     * A form that posts an unticked box, a config with a trailing comma. `Notifier` would look
     * for a channel called `''` and the failure would be a log line nobody reads.
     */
    public function testEmptyChannelNamesAreDropped(): void
    {
        // Act
        $message = (new Message('Hello', 'Body'))->to('mail', '', '   ', 'database');

        // Assert
        $this->assertSame(['mail', 'database'], $message->via(null));
    }

    /**
     * The mail gets the subject and the body as written.
     */
    public function testTheMailIsTheSubjectAndTheBody(): void
    {
        // Act
        $mail = (new Message('Το εξαγόμενο είναι έτοιμο', '<p>Είναι στη σελίδα σας.</p>'))->toMail(null);

        // Assert
        $this->assertSame('Το εξαγόμενο είναι έτοιμο', $mail['subject']);
        $this->assertSame('<p>Είναι στη σελίδα σας.</p>', $mail['body']);
        $this->assertArrayNotHasKey('from', $mail, 'the installation default, not an empty sender');
    }

    /**
     * A sender is passed through when one is set.
     */
    public function testAnExplicitSenderIsPassedThrough(): void
    {
        // Act
        $mail = (new Message('Hi', 'Body'))->from('ops@example.com')->toMail(null);

        // Assert
        $this->assertSame('ops@example.com', $mail['from']);
    }

    /**
     * The push payload is text, not markup, and it is one line.
     *
     * A push body is rendered literally by the operating system. Given HTML, the person sees
     * the tags; given a paragraph with newlines, the notification is truncated at a point
     * nobody chose.
     */
    public function testThePushBodyIsPlainAndSingleLine(): void
    {
        // Act
        $push = (new Message(
            'Νέο μήνυμα',
            "<p>Πρώτη γραμμή.</p>\n<p>Δεύτερη <strong>γραμμή</strong>.</p>"
        ))->toPush(null);

        // Assert
        $this->assertSame('Νέο μήνυμα', $push['title']);
        $this->assertSame('Πρώτη γραμμή. Δεύτερη γραμμή.', $push['body']);
        $this->assertStringNotContainsString('<', $push['body']);
    }

    /**
     * Entities are decoded, because a push shows them as typed.
     *
     * The body reaching a push has usually been through `htmlspecialchars()` on its way to the
     * mail. Left encoded, an apostrophe arrives on somebody's phone as `&#039;`.
     */
    public function testEntitiesAreDecodedForThePush(): void
    {
        // Act
        $push = (new Message('Hi', 'It&#039;s ready &amp; waiting'))->toPush(null);

        // Assert
        $this->assertSame("It's ready & waiting", $push['body']);
    }

    /**
     * A line break in the mail body becomes a space, not a run-together word.
     */
    public function testALineBreakBecomesASpace(): void
    {
        // Act
        $push = (new Message('Hi', 'first<br>second'))->toPush(null);

        // Assert
        $this->assertSame('first second', $push['body']);
    }

    /**
     * The link is where a push opens and what the stored record carries.
     */
    public function testTheLinkReachesBothChannelsThatCanUseIt(): void
    {
        // Act
        $message = (new Message('Hi', 'Body'))->link('https://example.com/account');

        // Assert
        $this->assertSame('https://example.com/account', $message->toPush(null)['url']);
        $this->assertSame('https://example.com/account', $message->toDatabase(null)['url']);
        $this->assertArrayNotHasKey('url', $message->toMail(null), 'the mail body carries its own links');
    }

    /**
     * Push options are merged in, and do not overwrite what the message already said.
     *
     * A tag, an icon, action buttons — everything the channel supports that a plain message has
     * no field for. But a `title` passed here must not silently replace the subject: two
     * sources for one field is how a notification ends up saying something nobody wrote.
     */
    public function testPushOptionsAreAddedWithoutOverwriting(): void
    {
        // Act
        $push = (new Message('Real title', 'Body'))
            ->pushOptions(['tag' => 'export', 'title' => 'Sneaky', 'icon' => '/icon.png'])
            ->toPush(null);

        // Assert
        $this->assertSame('Real title', $push['title']);
        $this->assertSame('export', $push['tag']);
        $this->assertSame('/icon.png', $push['icon']);
    }

    /**
     * A message with no list is transactional, and says so by declaring nothing.
     */
    public function testAMessageWithNoListIsTransactional(): void
    {
        // Assert
        $message = new Message('Hi', 'Body');
        $this->assertSame('', $message->unsubscribeList());
        $this->assertFalse($message->trackingRequested());
        $this->assertSame([], $message->mailStructuredData());
        $this->assertNull($message->mailTemplate());
    }

    /**
     * The template distinguishes "no wrapper" from "the default".
     *
     * Two different answers that both look empty: `''` means send the body bare, `null` means
     * whatever this installation wraps everything in. Conflated, an application that wraps
     * every message cannot send one machine-readable mail.
     */
    public function testNoWrapperIsNotTheSameAsTheDefault(): void
    {
        // Assert
        $this->assertSame('', (new Message('Hi', 'B'))->template('')->mailTemplate());
        $this->assertNull((new Message('Hi', 'B'))->template(null)->mailTemplate());
        $this->assertSame('receipt', (new Message('Hi', 'B'))->template('receipt')->mailTemplate());
    }

    /**
     * Naming a list is what turns the unsubscribe machinery on.
     */
    public function testNamingAListMakesItNonTransactional(): void
    {
        // Assert
        $this->assertSame('digest', (new Message('Hi', 'B'))->list('  digest  ')->unsubscribeList());
    }

    /**
     * An empty action block is not recorded.
     *
     * `Actions::rsvp([])` returns nothing when it had nothing to describe, and an empty
     * `ld+json` in the head is a claim that the message has no actions rather than the absence
     * of a claim.
     */
    public function testAnEmptyActionIsNotRecorded(): void
    {
        // Act
        $message = (new Message('Hi', 'B'))
            ->action([])
            ->action(['@type' => 'EmailMessage']);

        // Assert
        $this->assertSame([['@type' => 'EmailMessage']], $message->mailStructuredData());
    }

    /**
     * Every setter returns the message, because this is written as one expression.
     */
    public function testTheSettersChain(): void
    {
        // Act
        $message = (new Message('Hi', 'B'))
            ->to('mail')
            ->link('https://example.com')
            ->list('digest')
            ->template('receipt')
            ->track()
            ->from('ops@example.com')
            ->action(['@type' => 'EmailMessage'])
            ->pushOptions(['tag' => 'x']);

        // Assert
        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue($message->trackingRequested());
        $this->assertSame(['mail'], $message->via(null));
    }
}
