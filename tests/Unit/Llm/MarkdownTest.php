<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Chat\Markdown;

/**
 * A model's Markdown as safe HTML: escaped first, then only the shapes a model uses.
 *
 * A reply is text nobody here wrote, printed into the page; every test below that puts markup
 * in a reply checks it comes out as text.
 */
#[CoversClass(Markdown::class)]
class MarkdownTest extends TestCase
{
    /**
     * Markup in a reply is text, wherever it appears — a paragraph, a heading, a list, a table,
     * a link's label or address, code.
     */
    public function testMarkupInAReplyIsText(): void
    {
        // Act
        $html = Markdown::toHtml("<script>alert(1)</script>\n# <b>t</b>\n- <i>x</i>\n\n| <a> | b |\n|---|---|\n| <img src=x> | 2 |\n\n"
            . "[<b>label</b>](https://x.example/\"onmouseover=\"a) `<code>` [bad](javascript:alert(1))");

        // Assert
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('href="javascript', $html, 'only http and https become links');
        $this->assertStringNotContainsString('" onmouseover', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<code>&lt;code&gt;</code>', $html);
    }

    /**
     * The shapes a model uses: headings a level down, emphasis, links that open apart, lists,
     * tables and fenced code — and lines of a paragraph kept apart.
     */
    public function testTheShapesAModelUses(): void
    {
        // Act
        $html = Markdown::toHtml("## Results\n\n**Total**: 12, *up* from _ten_.\nSee [the report](https://x.example/r?a=1&b=2) or https://x.example/p.\n\n"
            . "1. one\n2. two\n\n- a\n* b\n\n| Week | Visits |\n|:---|---:|\n| 1 | **5** |\n\n```\nif (a < b) {}\n```");

        // Assert
        $this->assertStringContainsString('<h4>Results</h4>', $html);
        $this->assertStringContainsString('<strong>Total</strong>: 12, <em>up</em> from <em>ten</em>.<br>', $html);
        $this->assertStringContainsString('<a href="https://x.example/r?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">the report</a>', $html);
        $this->assertStringContainsString('<a href="https://x.example/p" target="_blank" rel="noopener noreferrer">https://x.example/p</a>.', $html);
        $this->assertStringContainsString('<ol><li>one</li><li>two</li></ol>', $html);
        $this->assertStringContainsString('<ul><li>a</li><li>b</li></ul>', $html);
        $this->assertMatchesRegularExpression('#<thead><tr><th[^>]*>Week</th><th[^>]*>Visits</th></tr></thead><tbody><tr><td[^>]*>1</td><td[^>]*><strong>5</strong></td></tr></tbody></table></div>#', $html);
        $this->assertStringContainsString("<pre><code>if (a &lt; b) {}</code></pre>", $html);
        $this->assertSame('', Markdown::toHtml(''));
    }

    /**
     * An unclosed fence runs to the end, and a snake_case word is not emphasis.
     */
    public function testEdges(): void
    {
        // Assert
        $this->assertSame('<pre><code>open</code></pre>', Markdown::toHtml("```\nopen"));
        $this->assertSame('<p>my_var_name and 2*3*4</p>', Markdown::toHtml('my_var_name and 2*3*4'));
    }

    /**
     * A table is readable in every theme: Bootstrap's and daisyUI's table class, cell spacing
     * and rules inline for a theme without one, and a scroll of its own when it is wider than
     * the answer — columns that ran together were the report.
     */
    public function testATableIsStyledWhereItIsDrawn(): void
    {
        // Act
        $html = Markdown::toHtml("| a | b |\n|---|---|\n| 1 | 2 |");

        // Assert
        $this->assertStringStartsWith('<div style="overflow-x:auto', $html, 'a wide table scrolls by itself');
        $this->assertStringContainsString('<table class="table table-sm"', $html);
        $this->assertStringContainsString('<th style="text-align:left;padding:', $html, 'a header cell has spacing of its own');
        $this->assertStringContainsString('<td style="padding:', $html, 'so does every other cell');
    }
}
