<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Chat\Attachments;

/**
 * Files sent with a question, refused before anything is asked when they cannot be used.
 *
 * Each refusal is a sentence the person reads, so it says which file and what would do.
 */
#[CoversClass(Attachments::class)]
class AttachmentsTest extends TestCase
{
    /**
     * Nothing usable sent is no attachment; empty files are passed over; files too large
     * together, a file nothing reads, and a picture the application does not keep are refused.
     */
    public function testWhatIsRefused(): void
    {
        // Arrange
        $keepNothing = static fn (string $path, string $filename): ?string => null;
        $half        = str_repeat('x', (int) (Attachments::BYTES / 2) + 1);

        // Act
        $none     = Attachments::build([['name' => 'empty.txt', 'bytes' => '']], $keepNothing);
        $tooLarge = Attachments::build([['name' => 'a.txt', 'bytes' => $half], ['name' => 'b.txt', 'bytes' => $half]], $keepNothing);
        $unread   = Attachments::build([['name' => 'report.pdf', 'bytes' => '%PDF-1.7']], $keepNothing);
        $picture  = Attachments::build([['name' => 'chart.png', 'bytes' => 'PNG']], $keepNothing);

        // Assert
        $this->assertNull($none, 'an empty file is no file');
        $this->assertSame('The files are too large together: at most 5 MB.', $tooLarge);
        $this->assertStringStartsWith('“report.pdf” could not be read.', (string) $unread);
        // Without a store, a picture cannot reach the model: refused rather than dropped silently.
        $this->assertStringStartsWith('“chart.png” could not be read.', (string) $picture);
    }
}
