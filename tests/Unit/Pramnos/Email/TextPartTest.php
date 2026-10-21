<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

/**
 * The text part a message carries: the application's own, or the HTML converted.
 *
 * A designed template converted to text is the whole layout — logo, menu, social bar — around
 * the message. An application that writes the text part itself (the message, a short footer)
 * sends that instead; the unsubscribe link the message offers is added where the text does not
 * carry it, because a reader of the text part has to be able to leave the list too.
 */
#[CoversClass(Email::class)]
final class TextPartTest extends TestCase
{
    /**
     * Without one, the text part is the HTML converted, as it always was.
     */
    public function testWithoutATextBodyTheHtmlIsConverted(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $text = $email->textPart('<p>Hello <a href="https://x.test/a">there</a></p>');

        // Assert
        $this->assertSame('Hello there <https://x.test/a>', $text);
    }

    /**
     * With one, it is the text part — and the unsubscribe link is added when the text does not
     * carry it, once; a blank text body is none.
     */
    public function testTheApplicationsTextIsSentWithTheUnsubscribeLink(): void
    {
        // Arrange
        $email = new Email();
        $email->setTo('reader@example.test');
        $email->offerUnsubscribe('newsletter');
        $url = (new \ReflectionProperty(Email::class, 'unsubscribe'))->getValue($email);

        // Act
        $own      = $email->setTextBody("Hello Anna,\n\nYour order shipped.\n\n-- \nExample Ltd")->textPart('<p>ignored</p>');
        $carrying = $email->setTextBody("Hi.\nLeave: " . $url)->textPart('');
        $blank    = $email->setTextBody('  ')->textPart('<p>Converted</p>');

        // Assert
        $this->assertStringStartsWith("Hello Anna,\n\nYour order shipped.\n\n-- \nExample Ltd\n\nUnsubscribe: ", $own);
        $this->assertStringEndsWith((string) $url, $own);
        $this->assertSame(1, substr_count($carrying, (string) $url), 'the link was added to a text that already had it');
        $this->assertSame('Converted', $blank, 'a blank text body replaced the conversion');
    }

    /**
     * A queued message keeps its text part for the worker that sends it.
     */
    public function testTheTextPartTravelsWithAQueuedMessage(): void
    {
        // Arrange
        $email = (new Email())->setTextBody('Plain words');

        // Act
        $state  = $email->outboxState();
        $worker = (new Email())->restoreOutboxState($state);

        // Assert
        $this->assertSame('Plain words', $worker->textPart('<p>x</p>'));
    }

    /**
     * Kept inside the stored body — not a column: read back whole, signature line and `</` too,
     * and invisible to the text converter and to the HTML that is sent again.
     */
    public function testTheTextPartIsKeptInsideTheStoredBody(): void
    {
        // Arrange
        $text = "Hello.\n-- \nExample Ltd </b> & co";

        // Act
        $stored = \Pramnos\Email\TextPart::embed('<p>Designed</p>', $text);

        // Assert
        $this->assertSame($text, \Pramnos\Email\TextPart::of($stored));
        $this->assertSame('<p>Designed</p>', \Pramnos\Email\TextPart::strip($stored), 'the HTML sent again would carry the text part');
        $this->assertSame('Designed', \Pramnos\Email\PlainText::fromHtml($stored), 'the converter read the kept text as page text');
        $this->assertSame('<p>Designed</p>', \Pramnos\Email\TextPart::embed('<p>Designed</p>', null));
        $this->assertNull(\Pramnos\Email\TextPart::of('<p>Designed</p>'));
    }

    /**
     * A sent message's stored body carries its text part, and its page shows that text — not the
     * HTML converted.
     */
    public function testTheMessagesPageShowsTheTextThatWent(): void
    {
        // Arrange — the body kept in its row, as the `content` store keeps it
        $email = new class () extends Email {
            /** The stored body, as send() keeps it. */
            public function stored(): array
            {
                return $this->keptBody(time(), 'reader@example.test');
            }
        };
        $email->setBody('<table><tr><td><img src="logo.png"></td></tr></table><p>Designed</p>');
        $email->setTextBody('Just the message.');
        (new \ReflectionProperty(\Pramnos\Email\BodyStore::class, 'resolved'))->setValue(null, \Pramnos\Email\BodyStore::CONTENT);

        try {
            // Act
            $kept   = $email->stored();
            $report = new \Pramnos\Email\MessageReport(['content' => $kept['content'], 'path' => '']);

            // Assert
            $this->assertSame('Just the message.', $report->plainText());
            $this->assertSame('Designed', \Pramnos\Email\PlainText::fromHtml($kept['content']));
        } finally {
            \Pramnos\Email\BodyStore::forget();
        }
    }
}
