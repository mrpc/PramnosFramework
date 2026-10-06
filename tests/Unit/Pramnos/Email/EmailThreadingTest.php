<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A transport that keeps each message as it would have gone on the wire.
 */
final class ThreadCapturingTransport extends AbstractTransport
{
    /** @var list<string> */
    public array $sent = [];

    protected function doSend(SentMessage $message): void
    {
        $this->sent[] = $message->toString();
    }

    public function __toString(): string
    {
        return 'capture://';
    }
}

/**
 * A reply lands in the thread of the message it answers.
 *
 * Mail clients thread on `In-Reply-To` and `References`, which hold message ids. Symfony requires
 * those as identification headers, and `addHeader()` used to add every header as text — so the
 * send failed deep in the transport, and a reply could only go out unthreaded. And a reply needs
 * the original's Message-ID, which nothing recorded unless the application chose it.
 */
#[CoversClass(Email::class)]
class EmailThreadingTest extends TestCase
{
    /** A message ready to send through a capturing transport. */
    private function message(ThreadCapturingTransport $transport): Email
    {
        return (new Email())->withTransport($transport)->setBody('<p>Your article is published.</p>')
            ->setTo('sender@agency.example')->setFrom('press@customer.example')->setSubject('Re: Our release');
    }

    /**
     * The reply carries its own Message-ID, the parent in In-Reply-To, and the chain in References.
     */
    public function testAReplyIsThreadedUnderTheMessageItAnswers(): void
    {
        // Arrange
        $transport = new ThreadCapturingTransport();

        // Act — brackets on some ids and not others, and the parent repeated in its references
        $sent = $this->message($transport)
            ->withMessageId('reply-1@customer.example')
            ->inReplyTo('<release-2@agency.example>', ['release-1@agency.example', '<release-2@agency.example>'])
            ->send();

        // Assert
        $this->assertTrue($sent);
        $raw = $transport->sent[0];
        $this->assertStringContainsString("Message-ID: <reply-1@customer.example>", $raw);
        $this->assertStringContainsString("In-Reply-To: <release-2@agency.example>", $raw);
        // The parent's references, then the parent, once.
        $this->assertStringContainsString("References: <release-1@agency.example> <release-2@agency.example>\r\n", $raw);
    }

    /**
     * Without withMessageId(), the transport still gives the message an id of its own.
     */
    public function testAMessageWithoutAChosenIdStillHasOne(): void
    {
        // Arrange
        $transport = new ThreadCapturingTransport();

        // Act
        $this->message($transport)->send();

        // Assert
        $this->assertMatchesRegularExpression('/^Message-ID: <[^>]+@[^>]+>/m', $transport->sent[0]);
    }

    /**
     * addHeader() with a message-id header, in any letter case, is written as one.
     *
     * This is the call that used to throw a LogicException from inside Symfony.
     */
    public function testAddHeaderWritesMessageIdHeadersAsIds(): void
    {
        // Arrange
        $transport = new ThreadCapturingTransport();

        // Act
        $sent = $this->message($transport)->addHeader('in-reply-to', 'original@agency.example')->send();

        // Assert
        $this->assertTrue($sent);
        $this->assertMatchesRegularExpression('/^In-Reply-To: <original@agency\.example>/mi', $transport->sent[0]);
    }

    /**
     * A message id that is not local@domain is refused at the call, with the header named.
     */
    public function testAnInvalidMessageIdIsRefusedAtTheCall(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('In-Reply-To needs message ids');

        // Act
        (new Email())->inReplyTo('not an id');
    }

    /**
     * queue() sends a threaded message at once: the outbox keeps no headers.
     *
     * Queued, it would have gone out later without In-Reply-To, outside its thread, with
     * nothing to say why.
     */
    public function testQueueingAThreadedMessageSendsItNow(): void
    {
        // Arrange
        $transport = new ThreadCapturingTransport();

        // Act
        $queued = $this->message($transport)->inReplyTo('original@agency.example')->queue();

        // Assert
        $this->assertTrue($queued);
        $this->assertCount(1, $transport->sent, 'the threaded message was not sent at once');
        $this->assertStringContainsString('In-Reply-To: <original@agency.example>', $transport->sent[0]);
    }
}
