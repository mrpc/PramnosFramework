<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Email\MailMessage;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\DocumentText;

/**
 * A document's words and pictures, and an email's — read with the standard library, so an
 * attached press release can be worked from.
 */
#[CoversClass(DocumentText::class)]
#[CoversClass(MailMessage::class)]
#[CoversClass(Scope::class)]
class DocumentTextTest extends LlmTestCase
{
    /**
     * A zip archive of the given parts, as bytes.
     *
     * @param array<string, string> $parts
     */
    private static function zip(array $parts): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip  = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    /** A Word document with two paragraphs and a picture. */
    private static function docx(): string
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        return self::zip([
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . $w . '"><w:body>'
                . '<w:p><w:r><w:t>Press release</w:t></w:r></w:p><w:p><w:r><w:t>Γεια σου</w:t></w:r></w:p></w:body></w:document>',
            'word/media/image1.png' => 'PNGBYTES',
            'word/media/notes.txt'  => 'not a picture',
        ]);
    }

    /**
     * Word, OpenDocument, text and Markdown become their words; anything else is not read.
     */
    public function testDocumentsBecomeTheirWords(): void
    {
        // Arrange
        $text = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
        $odt  = self::zip(['content.xml' => '<?xml version="1.0"?><o xmlns:text="' . $text . '"><text:h>Title</text:h>'
            . '<text:p>Body</text:p><text:span>skipped</text:span></o>', 'Pictures/a.jpg' => 'JPG']);

        // Act / Assert
        $this->assertSame("Press release\nΓεια σου", DocumentText::from('release.docx', self::docx()));
        $this->assertSame("Title\nBody", DocumentText::from('a.odt', $odt));
        $this->assertSame("one\n\ntwo", DocumentText::from('n.md', "one\r\n\r\n\r\n\r\ntwo"));
        $this->assertSame('ελληνικά', DocumentText::from('latin.txt', mb_convert_encoding('ελληνικά', 'ISO-8859-7', 'UTF-8')), 'a Greek legacy encoding is read');
        $this->assertNull(DocumentText::from('a.pdf', '%PDF'));
        $this->assertNull(DocumentText::from('a.txt', ''));
        $this->assertNull(DocumentText::from('broken.docx', 'not a zip'));
        $this->assertNull(DocumentText::from('empty.docx', self::zip(['word/other.xml' => 'x'])));
    }

    /**
     * A document's pictures, a picture on its own, and none for anything else.
     */
    public function testPictures(): void
    {
        // Act / Assert
        $this->assertSame([['name' => 'image1.png', 'bytes' => 'PNGBYTES']], DocumentText::pictures('release.docx', self::docx()));
        $this->assertSame([['name' => 'photo.jpg', 'bytes' => 'JPG']], DocumentText::pictures('/up/photo.jpg', 'JPG'));
        $this->assertSame([], DocumentText::pictures('notes.txt', 'x'));
        $this->assertSame([], DocumentText::pictures('broken.docx', 'not a zip'));
    }

    /**
     * Pictures are kept by the store given and listed after the words; one that cannot be kept
     * is left out, and the list is found again in the text.
     */
    public function testKeptPicturesAreListed(): void
    {
        // Arrange — a store that keeps the first and refuses the next
        $docx  = self::zip(['word/document.xml' => '<w:document xmlns:w="x"/>', 'word/media/a.png' => 'A', 'word/media/b.png' => 'B', 'word/media/c.png' => 'C']);
        $calls = 0;
        $store = static function (string $path, string $name) use (&$calls): ?string {
            $calls++;

            return match ($name) {
                'a.png' => is_file($path) ? 'https://cdn.example/a.png' : null,
                'b.png' => null,
                default => throw new \RuntimeException('full'),
            };
        };

        // Act
        $listed = DocumentText::keepPictures('x.docx', $docx, $store);

        // Assert
        $this->assertSame("\n\n" . DocumentText::PICTURES . "\n- https://cdn.example/a.png", $listed);
        $this->assertSame(3, $calls);
        $this->assertSame(['https://cdn.example/a.png'], DocumentText::picturesIn('words' . $listed));
        $this->assertSame([], DocumentText::picturesIn('no pictures'));
        $this->assertSame('', DocumentText::keepPictures('x.txt', 'words', $store));
    }

    /**
     * A saved email: its subject, sender and words, the documents it carries read in, its
     * pictures taken — and one that is not an email is not read as one.
     */
    public function testAnEmailIsRead(): void
    {
        // Arrange
        $mail = "From: =?UTF-8?B?" . base64_encode('Γραφείο Τύπου') . "?= <press@example.com>\r\n"
            . "To: News <news@example.com>\r\nCc: desk@example.com\r\nSubject: Fwd: Launch\r\nDate: Mon, 12 Oct 2026 10:00:00 +0300\r\n"
            . "Message-ID: <abc@example.com>\r\nReferences: <one@x> <two@x>\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "Hello=20world\r\n\r\n---------- Forwarded message ---------\r\nFrom: Original Sender <orig@example.com>\r\nSubject: Launch day\r\n\r\nThe news.\r\n"
            . "--B\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document; name=\"release.docx\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(self::docx())) . "\r\n"
            . "--B\r\nContent-Type: image/jpeg\r\nContent-Disposition: attachment; filename*=UTF-8''%CF%86%CF%89%CF%84%CE%BF\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode('JPEGBYTES') . "\r\n--B--\r\n";

        // Act
        $parsed = MailMessage::parse($mail);
        $text   = DocumentText::from('message.eml', $mail);
        $pics   = DocumentText::pictures('message.eml', $mail);

        // Assert
        $this->assertSame('Γραφείο Τύπου <press@example.com>', $parsed['from']);
        $this->assertSame(['news@example.com', 'desk@example.com'], $parsed['to']);
        $this->assertSame(['abc@example.com', ['one@x', 'two@x']], [$parsed['message_id'], $parsed['references']]);
        $this->assertSame(['from' => 'Original Sender <orig@example.com>', 'subject' => 'Launch day'], $parsed['original']);
        $this->assertStringStartsWith("Subject: Launch day\nFrom: Original Sender <orig@example.com>\nForwarded by: Γραφείο Τύπου", (string) $text);
        $this->assertStringContainsString("--- release.docx ---\nPress release", (string) $text);
        $this->assertSame(['image1.png', 'φωτο.jpeg'], array_column($pics, 'name'));
        $this->assertNull(DocumentText::from('x.eml', 'just some text'));
        $this->assertSame([], DocumentText::pictures('x.eml', 'just some text'));
    }

    /**
     * The email helpers: subjects without their forward prefixes, the language, an HTML-only
     * body read as words, and an attached forwarded email taken as the original.
     */
    public function testTheEmailHelpers(): void
    {
        // Arrange
        $html = "From: a@x\nSubject: Fw: ΠΡΘ: Note\nContent-Type: multipart/mixed; boundary=X\n\n"
            . "--X\nContent-Type: text/html; charset=iso-8859-7\n\n<p>" . mb_convert_encoding('Καλημέρα', 'ISO-8859-7', 'UTF-8') . "</p><script>x</script><br>end\n"
            . "--X\nContent-Type: message/rfc822\n\nFrom: first@x\nSubject: Note\nReply-To: r@x\nMessage-ID: <m@x>\n\nInner words\n--X--\n";

        // Act
        $parsed = MailMessage::parse($html);

        // Assert
        $this->assertSame('Note', MailMessage::cleanSubject('Fwd: FW: ΠΡΘ: Note'));
        $this->assertSame(['el', 'en'], [MailMessage::language('Καλημέρα σας'), MailMessage::language('Good morning')]);
        $this->assertSame('Inner words', $parsed['text'], 'plain text, the forwarded email\'s included, wins over HTML');
        $onlyHtml = MailMessage::parse("From: a@x\nSubject: s\nContent-Type: text/html; charset=iso-8859-7\n\n<p>"
            . mb_convert_encoding('Καλημέρα', 'ISO-8859-7', 'UTF-8') . "</p><script>x</script><br>end");
        $this->assertSame("Καλημέρα\n\nend", $onlyHtml['text'], 'HTML alone is read as its words, in its charset');
        $this->assertSame(['from' => 'first@x', 'subject' => 'Note', 'reply_to' => 'r@x', 'message_id' => 'm@x', 'references' => []], $parsed['original']);
        $this->assertTrue(MailMessage::looksLikeOne("Subject: x\nFrom: y\n\nbody"));
        $this->assertFalse(MailMessage::looksLikeOne("Hello there"));
    }

    /**
     * The scope of a request comes from the router, and none without a person.
     */
    public function testTheScopeOfARequest(): void
    {
        // Arrange
        \Pramnos\Llm\Llm::use('router', new class extends \Pramnos\Llm\Policy\DefaultRouter {
            public function context(array $context): array
            {
                return ['userid' => 7, 'organization_id' => '3'];
            }
        });

        // Act / Assert
        $scope = Scope::current();
        $this->assertSame([7, 3], [$scope->userId, $scope->organizationId]);
        $this->assertSame(['organization_id' => 3, 'userid' => 7, 'space' => 'chat'], $scope->columns());
        \Pramnos\Llm\Llm::use('router', new class extends \Pramnos\Llm\Policy\DefaultRouter {
            public function context(array $context): array
            {
                return ['userid' => null];
            }
        });
        $this->assertNull(Scope::current());
    }
}
