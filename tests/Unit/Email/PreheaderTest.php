<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;
use Pramnos\Email\EmailTheme;
use Pramnos\Email\PlainText;

/**
 * The line a mailbox list shows beside the subject, and the wrapper that hides it.
 *
 * The second most prominent piece of text in an inbox, and until now chosen by nobody: every
 * client prints the message's first readable text, which on a wrapped message is the wrapper's
 * own opening — a logo's alt, "view this in your browser", the first cell of a table.
 */
#[CoversClass(Email::class)]
class PreheaderTest extends TestCase
{
    /**
     * An explicit preheader is used as written.
     */
    public function testAnExplicitPreheaderIsUsed(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = '<p>Something else entirely.</p>';

        // Act
        $mail->preheader('  Your code is 481920  ');

        // Assert
        $this->assertSame('Your code is 481920', $mail->preheaderText());
    }

    /**
     * With none set, the body's own opening is used.
     *
     * "No preheader" is not a neutral state — it is the wrapper's first text. The body's
     * opening sentence is at worst a repetition of what the reader is about to see, which is
     * what most mail does deliberately.
     */
    public function testWithNoneSetTheBodysOpeningIsUsed(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = '<h1>Καλώς ήρθατε</h1><p>Ο λογαριασμός σας είναι έτοιμος.</p>';

        // Act
        $text = $mail->preheaderText();

        // Assert
        $this->assertStringStartsWith('Καλώς ήρθατε', $text);
        $this->assertStringContainsString('έτοιμος', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /**
     * It is one line, whatever the body's shape.
     *
     * A mailbox list renders it on a single line; newlines and runs of whitespace become gaps
     * that look like a formatting fault rather than a sentence.
     */
    public function testItIsAlwaysOneLine(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = "<p>First line.</p>\n\n<p>Second     line.</p>";

        // Act & Assert
        $this->assertSame('First line. Second line.', $mail->preheaderText());
    }

    /**
     * It is cut to what a list actually shows.
     */
    public function testItIsCutToWhatAListShows(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = '<p>' . str_repeat('word ', 100) . '</p>';

        // Act
        $text = $mail->preheaderText();

        // Assert
        $this->assertSame(Email::PREHEADER_LENGTH, mb_strlen($text));
    }

    /**
     * A multibyte body is cut on characters, not bytes.
     *
     * Cut mid-character, the last thing in the inbox line is a replacement glyph — on Greek
     * mail, every time.
     */
    public function testAGreekBodyIsCutOnCharacters(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = '<p>' . str_repeat('δοκιμή ', 40) . '</p>';

        // Act
        $text = $mail->preheaderText();

        // Assert
        $this->assertSame(Email::PREHEADER_LENGTH, mb_strlen($text));
        $this->assertSame($text, mb_convert_encoding($text, 'UTF-8', 'UTF-8'),
            'a cut that lands mid-character produces invalid UTF-8');
    }

    /**
     * There is always a language, even with nothing configured.
     *
     * An empty `lang` is the state that makes a screen reader fall back to its own setting
     * silently, so the last fallback has to be a real value rather than an empty string.
     */
    public function testThereIsAlwaysALanguageToDeclare(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();

        $mail = new class extends Email {
            public function probeLanguage(): string { return $this->messageLanguage(); }
        };

        try {
            // Assert
            $this->assertNotSame('', $mail->probeLanguage());
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * An empty body has no preheader rather than an empty one.
     */
    public function testAnEmptyBodyHasNoPreheader(): void
    {
        // Arrange
        $mail = new Email();
        $mail->body = '';

        // Assert
        $this->assertSame('', $mail->preheaderText());
    }

    /**
     * The bundled wrapper hides the preheader from the reader and shows it to the list.
     *
     * Three mechanisms, because no single one works everywhere: `display:none` is ignored by
     * some clients, `mso-hide:all` is the only one Outlook honours, and a 1px transparent
     * colour catches the rest.
     */
    public function testTheWrapperHidesThePreheader(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', [
            'preheader' => 'Your code is 481920',
            'subject'   => 'Your code',
        ]);

        // Assert
        $this->assertStringContainsString('Your code is 481920', $html);
        $this->assertStringContainsString('display:none', $html);
        $this->assertStringContainsString('mso-hide:all', $html);
    }

    /**
     * And the plain-text part does not open with it.
     *
     * A hidden preheader that reaches the text part is the message's first line twice — and in
     * a text-only client, the visible one.
     */
    public function testThePlainTextPartDoesNotOpenWithThePreheader(): void
    {
        // Arrange
        $html = EmailTheme::wrap('<p>The actual message.</p>', 'default', [
            'preheader' => 'HIDDEN PREHEADER',
            'subject'   => 'Subject',
        ]);

        // Act
        $text = PlainText::fromHtml($html);

        // Assert
        $this->assertStringNotContainsString('HIDDEN PREHEADER', $text);
        $this->assertStringContainsString('The actual message.', $text);
    }

    /**
     * A message with no preheader renders no hidden block at all.
     *
     * An empty hidden div is a stray zero-width run at the top of every message, which some
     * clients render as a blank first line.
     */
    public function testNoPreheaderRendersNoBlock(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', ['preheader' => '', 'subject' => 'S']);

        // Assert
        $this->assertStringNotContainsString('mso-hide:all', $html);
    }

    /**
     * The wrapper declares dark mode rather than leaving the client to invert it.
     *
     * Apple Mail and Outlook invert colours themselves otherwise, per element and unaware of
     * images — so a dark logo on the white card it was drawn for ends up black on near-black.
     */
    public function testTheWrapperDeclaresDarkMode(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', ['subject' => 'S']);

        // Assert
        $this->assertStringContainsString('name="color-scheme"', $html);
        $this->assertStringContainsString('name="supported-color-schemes"', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringContainsString('color-scheme:light dark', $html);
    }

    /**
     * Every colour the dark block overrides is also inline.
     *
     * Gmail strips `<style>` from a forwarded message and several clients drop it outright, so
     * the block is an improvement where it survives and never the thing keeping the message
     * readable.
     */
    public function testTheDarkBlockIsAnImprovementNotARequirement(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', ['subject' => 'S']);
        $withoutStyle = (string) preg_replace('~<style>.*?</style>~s', '', $html);

        // Assert
        foreach (['background-color:#f4f5f7', 'background-color:#ffffff', 'color:#1f2937'] as $inline) {
            $this->assertStringContainsString($inline, $withoutStyle,
                'the light palette has to survive the stylesheet being stripped');
        }
    }

    /**
     * The document declares the language it is written in.
     *
     * A screen reader with no `lang` announces the text in whatever it was last set to, which
     * on Greek mail read by an English-configured reader is unintelligible.
     */
    public function testTheDocumentDeclaresItsLanguage(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Γεια</p>', 'default', ['subject' => 'S', 'language' => 'el']);

        // Assert
        $this->assertStringContainsString('<html lang="el">', $html);
    }

    /**
     * With no language given it still declares one.
     *
     * An empty `lang` attribute is worse than a wrong one: it is the state that makes a reader
     * fall back to its own setting silently.
     */
    public function testThereIsAlwaysALanguage(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', ['subject' => 'S']);

        // Assert
        $this->assertStringContainsString('<html lang="en">', $html);
    }

    /**
     * Layout tables are marked as layout, and the body text is readable.
     *
     * `role="presentation"` is what stops a screen reader announcing "table, two columns" about
     * a message that has no table in it. 16px because 12px is below what most guidance calls
     * legible on a phone, and mail is read on phones.
     */
    public function testTheWrapperIsReadableByAScreenReader(): void
    {
        // Act
        $html = EmailTheme::wrap('<p>Body</p>', 'default', ['subject' => 'S', 'sitename' => 'Example']);

        // Assert
        $this->assertSame(
            substr_count($html, '<table'),
            substr_count($html, 'role="presentation"'),
            'every table in a layout is a layout table'
        );
        $this->assertStringContainsString('font-size:16px;line-height:1.6', $html);
        $this->assertStringNotContainsString('font-size:12px', $html);
    }
}
