<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\PlainText;

/**
 * The `text/plain` half of an HTML email.
 *
 * It was `strip_tags($body)`, which produces a part that is technically present and practically
 * useless. Three specific failures, each asserted below:
 *
 * - every `href` disappeared, so «click here to confirm» arrived with nothing to click;
 * - adjacent table cells ran together, and HTML mail is nested tables, so the whole message
 *   arrived as one line;
 * - the `<style>` block's *contents* survived, so a reader in a text-only client was shown CSS.
 *
 * A multipart message whose alternative part does not match the HTML is also a documented spam
 * signal — so the part meant to help deliverability was hurting it.
 */
#[CoversClass(PlainText::class)]
class PlainTextTest extends TestCase
{
    /**
     * A link keeps its address.
     *
     * The failure that mattered most. `strip_tags` kept the anchor text and threw the `href`
     * away, which on a confirmation mail deletes the entire message.
     */
    public function testALinkKeepsItsAddress(): void
    {
        // Act
        $text = PlainText::fromHtml(
            '<p>Please <a href="https://example.com/confirm?t=abc">click here</a> to confirm.</p>'
        );

        // Assert
        $this->assertStringContainsString('click here <https://example.com/confirm?t=abc>', $text);
    }

    /**
     * A link whose text is already the address is not printed twice.
     *
     * Common in a footer, and `https://… <https://…>` reads as a mistake.
     */
    public function testALinkThatIsItsOwnLabelAppearsOnce(): void
    {
        // Act
        $text = PlainText::fromHtml('<a href="https://example.com/u?t=x">https://example.com/u?t=x</a>');

        // Assert
        $this->assertSame('https://example.com/u?t=x', $text);
    }

    /**
     * A `mailto:` shows the address, not the scheme.
     */
    public function testAMailtoShowsTheAddress(): void
    {
        // Act
        $both = PlainText::fromHtml('<a href="mailto:help@example.com">our support team</a>');
        $same = PlainText::fromHtml('<a href="mailto:help@example.com">help@example.com</a>');

        // Assert
        $this->assertSame('our support team <help@example.com>', $both);
        $this->assertSame('help@example.com', $same);
    }

    /**
     * An in-page anchor has nowhere to go in a mail, so only its text survives.
     */
    public function testAnInPageAnchorKeepsOnlyItsText(): void
    {
        // Act & Assert
        $this->assertSame('Back to top', PlainText::fromHtml('<a href="#top">Back to top</a>'));
    }

    /**
     * The stylesheet does not come along.
     *
     * `strip_tags` removes the tags and keeps what was between them, so the reader was shown
     * `.x{color:red}`. Same for `<script>` and for `<title>`, which repeated the subject line as
     * the first line of the body.
     */
    public function testTheStylesheetAndTitleAreNotInTheText(): void
    {
        // Act
        $text = PlainText::fromHtml(
            '<html><head><title>Confirm your address</title><style>.x{color:red}</style>'
            . '<script>alert(1)</script></head><body><p>Hello.</p></body></html>'
        );

        // Assert
        $this->assertSame('Hello.', $text);
    }

    /**
     * Adjacent cells do not run together.
     *
     * HTML mail is nested tables. `strip_tags` gave `OneTwoThree`, which is the whole message
     * as one word.
     */
    public function testAdjacentCellsDoNotRunTogether(): void
    {
        // Act
        $text = PlainText::fromHtml(
            '<table role="presentation"><tr><td>Header</td></tr>'
            . '<tr><td><h1>Heading</h1><p>Body.</p></td></tr></table>'
        );

        // Assert
        $this->assertStringContainsString("Header\n", $text);
        $this->assertStringNotContainsString('HeaderHeading', $text);
        $this->assertStringContainsString('Heading', $text);
        $this->assertStringContainsString('Body.', $text);
    }

    /**
     * A layout table's rows are sections; a data table's rows are a table.
     *
     * The distinction is already written into the markup — the framework's mail wrapper marks
     * its layout tables `role="presentation"` for screen readers — so it is read from there
     * rather than guessed. A data table joined with newlines is unreadable, and a layout table
     * joined with pipes is nonsense.
     */
    public function testLayoutAndDataTablesAreTreatedDifferently(): void
    {
        // Act
        $data = PlainText::fromHtml(
            '<table><tr><th>Device</th><th>Last seen</th></tr>'
            . '<tr><td>Chrome</td><td>28/08/2026</td></tr></table>'
        );
        $layout = PlainText::fromHtml(
            '<table role="presentation"><tr><td>First</td></tr><tr><td>Second</td></tr></table>'
        );

        // Assert
        $this->assertStringContainsString('Device | Last seen', $data);
        $this->assertStringContainsString('Chrome | 28/08/2026', $data);
        $this->assertStringNotContainsString('|', $layout);
        $this->assertStringContainsString("First\n\nSecond", $layout);
    }

    /**
     * A list reads as a list: consecutive lines, with markers.
     *
     * Blank lines between the items would not read as one, and ordered lists are numbered
     * because "1." carries information that "-" does not.
     */
    public function testListsReadAsLists(): void
    {
        // Act
        $unordered = PlainText::fromHtml('<ul><li>One</li><li>Two</li></ul>');
        $ordered   = PlainText::fromHtml('<ol><li>First</li><li>Second</li></ol>');

        // Assert
        $this->assertSame("- One\n- Two", $unordered);
        $this->assertSame("1. First\n2. Second", $ordered);
    }

    /**
     * Hidden text stays hidden.
     *
     * A preheader is text meant only for the inbox preview and invisible in the HTML part.
     * Repeating it as the first line of the text part is a difference between the two halves,
     * which is the thing this class exists to avoid.
     */
    public function testAPreheaderIsNotRepeatedInTheText(): void
    {
        // Act
        $text = PlainText::fromHtml(
            '<div style="display:none;max-height:0">Preheader text</div><p>The message.</p>'
        );

        // Assert
        $this->assertSame('The message.', $text);
    }

    /**
     * An image contributes its `alt` text, or nothing.
     *
     * A decorative image has no `alt`, and a line reading `[]` is worse than no line.
     */
    public function testAnImageContributesItsAltTextOrNothing(): void
    {
        // Act
        $described  = PlainText::fromHtml('<p><img src="a.png" alt="The logo"> Hello.</p>');
        $decorative = PlainText::fromHtml('<p><img src="a.png" alt=""> Hello.</p>');

        // Assert
        $this->assertStringContainsString('[The logo] Hello.', $described);
        $this->assertSame('Hello.', $decorative);
    }

    /**
     * Entities and non-breaking spaces become the characters they mean.
     */
    public function testEntitiesAreDecoded(): void
    {
        // Act
        $text = PlainText::fromHtml('<p>Tom &amp; Jerry&nbsp;&mdash; &quot;quoted&quot;</p>');

        // Assert
        $this->assertSame('Tom & Jerry — "quoted"', $text);
    }

    /**
     * Greek text survives.
     *
     * libxml assumes ISO-8859-1 unless told otherwise, and the documented way to tell it —
     * `mb_convert_encoding($html, 'HTML-ENTITIES', …)` — is deprecated as of PHP 8.2. Getting
     * this wrong turns every message on a Greek-language site into mojibake.
     */
    public function testGreekTextSurvives(): void
    {
        // Act
        $text = PlainText::fromHtml('<p>Καλώς ορίσατε στον λογαριασμό σας.</p>');

        // Assert
        $this->assertSame('Καλώς ορίσατε στον λογαριασμό σας.', $text);
    }

    /**
     * Lines are wrapped, and a URL is never broken.
     *
     * A wrapped URL is an unusable URL: the client links the first half and leaves the rest as
     * text. A slightly ragged paragraph is the smaller problem.
     */
    public function testLinesWrapButUrlsDoNot(): void
    {
        // Arrange
        $url  = 'https://example.com/confirm?token=' . str_repeat('a', 90);
        $long = str_repeat('word ', 40);

        // Act
        $text = PlainText::fromHtml('<p>' . $long . '</p><p><a href="' . $url . '">x</a></p>');

        // Assert
        foreach (explode("\n", $text) as $line) {
            if (!str_contains($line, 'example.com')) {
                $this->assertLessThanOrEqual(
                    PlainText::WIDTH,
                    mb_strlen($line),
                    'prose is wrapped: ' . $line
                );
            }
        }

        $this->assertStringContainsString('<' . $url . '>', $text, 'the URL is intact');
    }

    /**
     * An empty body is an empty string, not a blank line or a warning.
     */
    public function testAnEmptyBodyIsEmpty(): void
    {
        // Assert
        $this->assertSame('', PlainText::fromHtml(''));
        $this->assertSame('', PlainText::fromHtml('   '));
    }

    /**
     * A `<br>` is a line break, and paragraphs are separated by a blank line.
     */
    public function testBreaksAndParagraphs(): void
    {
        // Act
        $text = PlainText::fromHtml('<p>One<br>Two</p><p>Three</p>');

        // Assert
        $this->assertSame("One\nTwo\n\nThree", $text);
    }

    /**
     * Whitespace in the source is not whitespace in the message.
     *
     * Mail templates are indented PHP, so the markup is full of newlines and tabs that mean
     * nothing. Left alone they arrive as ragged gaps in the middle of sentences.
     */
    public function testSourceIndentationIsNotOutput(): void
    {
        // Act
        $text = PlainText::fromHtml("<p>\n    Hello\n        there.\n</p>");

        // Assert
        $this->assertSame('Hello there.', $text);
    }

    /**
     * An `<hr>` becomes a visible rule, because it means something.
     *
     * It is usually what separates the message from the footer, and without it the unsubscribe
     * line reads as another paragraph of the message.
     */
    public function testAHorizontalRuleIsVisible(): void
    {
        // Act
        $text = PlainText::fromHtml('<p>Message.</p><hr><p>Footer.</p>');

        // Assert
        $this->assertStringContainsString('----', $text);
        $this->assertStringContainsString("Message.", $text);
        $this->assertStringContainsString("Footer.", $text);
    }

    /**
     * A link with no text is its address.
     *
     * An image wrapped in a link, which is how a logo is usually built, has no anchor text at
     * all — so the address is the only thing there is to show.
     */
    public function testALinkWithNoTextIsItsAddress(): void
    {
        // Act
        $text = PlainText::fromHtml('<a href="https://example.com/x"><img src="a.png" alt=""></a>');

        // Assert
        $this->assertSame('https://example.com/x', $text);
    }

    /**
     * An empty cell contributes nothing, not a separator.
     *
     * Spacer cells are how HTML mail makes margins, and a table of them would otherwise produce
     * a column of ` | ` with nothing between.
     */
    public function testAnEmptyCellIsNotASeparator(): void
    {
        // Act
        $text = PlainText::fromHtml(
            '<table><tr><td>Real</td><td>  </td><td>Also real</td></tr></table>'
        );

        // Assert
        $this->assertSame('Real | Also real', $text);
        $this->assertStringNotContainsString('|  |', $text);
    }
}
