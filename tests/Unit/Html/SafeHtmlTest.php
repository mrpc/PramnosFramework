<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\SafeHtml;

/**
 * Markup from the message editor, reduced to what a message may carry.
 *
 * The body of a message to one account is typed in an editor and sent by mail, stored for the
 * inbox, and shown on screens. Whatever the editor — or anybody posting the form by hand — puts
 * in it must not be able to run anything in the reader's client or in the administration area.
 */
#[CoversClass(SafeHtml::class)]
class SafeHtmlTest extends TestCase
{
    /**
     * What the editor produces survives as it was.
     */
    public function testTheEditorsOwnMarkupIsKept(): void
    {
        // Arrange
        $html = '<h2>Hello</h2><p>A <strong>bold</strong> and <em>quiet</em> word, a <a href="https://example.com/x?a=1&amp;b=2">link</a>'
            . ' and a <a href="mailto:a@example.com">mail</a>.</p><ul><li>one</li></ul><ol><li>two</li></ol>'
            . '<p><img src="https://example.com/i.png" alt="An image"><br>Ελληνικά</p>';

        // Act
        $clean = SafeHtml::clean($html);

        // Assert
        $this->assertSame($html, $clean);
    }

    /** @return array<string, array{string, string}> */
    public static function hostile(): array
    {
        return [
            'a script and its content'   => ['<p>a</p><script>alert(1)</script>', '<p>a</p>'],
            'a style block'              => ['<style>p{color:red}</style><p>a</p>', '<p>a</p>'],
            'an event handler'           => ['<p onclick="x()">a</p>', '<p>a</p>'],
            'a style attribute'          => ['<p style="background:url(x)">a</p>', '<p>a</p>'],
            'a javascript: link'         => ['<a href="javascript:alert(1)">a</a>', '<a>a</a>'],
            'a data: link'               => ['<a href="data:text/html,x">a</a>', '<a>a</a>'],
            'a plain-HTTP image'         => ['<img src="http://example.com/i.png">', ''],
            'an iframe'                  => ['<iframe src="https://evil.example"></iframe><p>a</p>', '<p>a</p>'],
            'an unknown tag, unwrapped'  => ['<section><p>a</p></section>', '<p>a</p>'],
            'a comment'                  => ['<p>a<!-- hidden --></p>', '<p>a</p>'],
            'an svg with a handler'      => ['<svg onload="x()"><circle/></svg><p>a</p>', '<p>a</p>'],
        ];
    }

    /**
     * Anything that could run, or carry something that could, is removed.
     */
    #[DataProvider('hostile')]
    public function testWhatCouldRunIsRemoved(string $html, string $expected): void
    {
        // Act & Assert
        $this->assertSame($expected, SafeHtml::clean($html));
    }

    /**
     * Nothing in, nothing out; text with no markup is escaped text.
     */
    public function testEmptyAndTextInput(): void
    {
        // Act & Assert
        $this->assertSame('', SafeHtml::clean('   '));
        $this->assertSame('a &amp; b', SafeHtml::clean('a &amp; b'));
    }
}
