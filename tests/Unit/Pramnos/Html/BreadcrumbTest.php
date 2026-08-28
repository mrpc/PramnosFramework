<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Breadcrumb;

/**
 * Unit tests for Pramnos\Html\Breadcrumb.
 *
 * Breadcrumb renders:
 *  - A Bootstrap-compatible <nav><ol class="breadcrumb"> structure
 *  - Schema.org BreadcrumbList JSON-LD script block
 *  - <a> links for items with a URL; <span> for items without
 *  - Heading levels that decrement from (count+1) down to 2 for each item
 */
#[CoversClass(Breadcrumb::class)]
class BreadcrumbTest extends TestCase
{
    // =========================================================================
    // addItem
    // =========================================================================

    /**
     * addItem() stores items keyed by label so they can be rendered.
     * Adding the same label twice overwrites the first entry.
     */
    public function testAddItemStoresItemByLabel(): void
    {
        // Arrange
        $bc = new Breadcrumb();

        // Act
        $bc->addItem('Home', 'http://example.com/', 'Home Page');

        // Assert
        $this->assertArrayHasKey('Home', $bc->items);
        $this->assertSame('http://example.com/', $bc->items['Home']['url']);
        $this->assertSame('Home Page', $bc->items['Home']['title']);
    }

    /**
     * addItem() with no URL and no title stores empty strings for those fields.
     */
    public function testAddItemDefaultsUrlAndTitleToEmpty(): void
    {
        // Arrange
        $bc = new Breadcrumb();

        // Act
        $bc->addItem('Current Page');

        // Assert
        $this->assertSame('', $bc->items['Current Page']['url']);
        $this->assertSame('', $bc->items['Current Page']['title']);
    }

    // =========================================================================
    // render — structural
    // =========================================================================

    /**
     * render() with no items returns the nav/ol wrapper and an empty
     * JSON-LD script block with no <li> elements.
     */
    public function testRenderEmptyBreadcrumb(): void
    {
        // Arrange
        $bc = new Breadcrumb();

        // Act
        $html = $bc->render();

        // Assert – wrapper present
        $this->assertStringContainsString('<nav aria-label="breadcrumb"', $html);
        $this->assertStringContainsString('<ol class="breadcrumb">', $html);
        $this->assertStringContainsString('</ol></nav>', $html);
        // No list items
        $this->assertStringNotContainsString('<li', $html);
        // JSON-LD block present but empty items
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    /**
     * render() with a single item having a URL produces an <a> tag (not a
     * <span>), includes the correct href, and the JSON-LD block contains the
     * item's position and name.
     */
    public function testRenderSingleItemWithUrl(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Home', 'http://example.com/');

        // Act
        $html = $bc->render();

        // Assert – <a> link rendered
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('href="http://example.com/"', $html);
        $this->assertStringNotContainsString('<span title=', $html);

        // JSON-LD – item present
        $this->assertStringContainsString('"position":1', $html);
        $this->assertStringContainsString('"name":"Home"', $html);
    }

    /**
     * render() with an item that has no URL uses a <span> instead of <a>,
     * since it is the "current page" and should not be a link.
     */
    public function testRenderItemWithoutUrlUsesSpan(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Current Page');  // no URL

        // Act
        $html = $bc->render();

        // Assert – <span> rendered, no <a> link
        $this->assertStringContainsString('<span title=', $html);
        $this->assertStringNotContainsString('<a title=', $html);
    }

    /**
     * render() with multiple items: the last item gets aria-current="page"
     * and class "active"; earlier items do not.
     */
    public function testRenderMultipleItemsLastIsActive(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Home',     'http://example.com/');
        $bc->addItem('Products', 'http://example.com/products/');
        $bc->addItem('Widget');  // current page, no URL

        // Act
        $html = $bc->render();

        // Assert – the last item has aria-current and active class
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('breadcrumb-item active', $html);
        // Count occurrences: only 1 item should be active
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    /**
     * render() emits a schema.org BreadcrumbList JSON-LD block with correct
     * position numbers for each item added.
     */
    public function testRenderJsonLdContainsAllItems(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Home',     'http://example.com/');
        $bc->addItem('Category', 'http://example.com/cat/');

        // Act
        $html = $bc->render();

        // Assert — decoded rather than matched, because the exact spacing of the JSON is
        // json_encode's business and not part of the contract. It was matched literally,
        // which is why replacing hand-built concatenation with an encoder broke this test
        // without anything about the output being wrong.
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $decoded = json_decode((string) ($m[1] ?? ''), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertSame([1, 2], array_column($decoded['itemListElement'], 'position'));
        $this->assertSame(
            ['Home', 'Category'],
            array_column($decoded['itemListElement'], 'name')
        );
    }

    /**
     * render() uses the item label as the title when no explicit title is set,
     * so the <a title="..."> attribute equals the label.
     */
    public function testRenderUsesLabelAsTitleWhenTitleEmpty(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('About Us', 'http://example.com/about/');  // no explicit title

        // Act
        $html = $bc->render();

        // Assert – title attribute equals the label
        $this->assertStringContainsString('title="About Us"', $html);
    }

    /**
     * render() uses the explicit title when provided, rather than the label.
     */
    public function testRenderUsesExplicitTitleWhenProvided(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('About', 'http://example.com/about/', 'About Our Company');

        // Act
        $html = $bc->render();

        // Assert – explicit title wins
        $this->assertStringContainsString('title="About Our Company"', $html);
    }

    /**
     * The structured data is valid JSON even when a label has an apostrophe.
     *
     * It was not. The JSON-LD was concatenated by hand and escaped with `addslashes()`, which
     * also escapes the *single* quote — and `\'` is not a valid JSON escape sequence. So one
     * apostrophe made the whole `BreadcrumbList` unreadable, not just the entry it appeared
     * in: `json_decode()` stops at the first one.
     *
     * Breadcrumb labels are user data — a person's name, a place, a category title — so this
     * is the common case rather than the awkward one. And nothing sees it: the visible list
     * renders identically, the page is 200, and an HTML snapshot comparing the same broken
     * text agrees with itself byte for byte. The only reader that notices is a search engine,
     * which cannot tell you.
     */
    public function testTheStructuredDataIsValidJsonForAwkwardLabels(): void
    {
        // Arrange
        $breadcrumb = new Breadcrumb();
        $breadcrumb->addItem("D'Angelo N.", '/pro/1');
        $breadcrumb->addItem('A "quoted" label', '/q');
        $breadcrumb->addItem('back\\slash', '');

        // Act
        preg_match(
            '~<script type="application/ld\+json">(.*)</script>~s',
            $breadcrumb->render(),
            $matches
        );
        $decoded = json_decode((string) ($matches[1] ?? ''), true);

        // Assert
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsArray($decoded);
        $this->assertSame(
            ["D'Angelo N.", 'A "quoted" label', 'back\\slash'],
            array_column($decoded['itemListElement'], 'name'),
            'the labels must survive the encoding unchanged'
        );
    }

    /**
     * A label containing `</script>` does not end the structured-data element.
     *
     * The same defect one step further: hand-built JSON inside a `<script>` block is escaped
     * by the JSON rules, and HTML has its own. `JSON_HEX_TAG` is what keeps a label from
     * closing the tag and turning the rest of the page into markup somebody else chose.
     *
     * The *visible* label is deliberately not escaped — callers pass markup on purpose, which
     * is why the structured-data name is `strip_tags()`d. This is about the one place where
     * that contract does not apply.
     */
    public function testALabelCannotCloseTheStructuredDataElement(): void
    {
        // Arrange
        $breadcrumb = new Breadcrumb();
        $breadcrumb->addItem('x</script><img src=y>', '');

        // Act
        preg_match(
            '~<script type="application/ld\+json">(.*?)</script>~s',
            $breadcrumb->render(),
            $matches
        );

        // Assert
        $this->assertStringNotContainsString('</script>', (string) ($matches[1] ?? '</script>'),
            'the JSON must not be able to end its own element');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * A quote in a title does not end the attribute it is in.
     *
     * The title defaults to the label, and labels are built from names, places and category
     * titles somebody typed. Unescaped, one double quote ends `title="` and everything after
     * it is markup the visitor chose — in the one part of this class that has no
     * markup-carrying contract to protect.
     */
    public function testAQuoteInATitleCannotEscapeTheAttribute(): void
    {
        // Arrange
        $breadcrumb = new Breadcrumb();
        $breadcrumb->addItem('Label', '/x', 'a " onmouseover=alert(1) x="');

        // Act
        $html = $breadcrumb->render();

        // Assert — the payload may appear as inert text; what must not appear is the quote
        // that would end the attribute and make it markup again.
        $this->assertStringNotContainsString('" onmouseover', $html);
        $this->assertStringContainsString('&quot; onmouseover', $html);
    }
}
