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
        $this->assertStringContainsString('"@type": "BreadcrumbList"', $html);
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
        $this->assertStringContainsString('"position": 1', $html);
        $this->assertStringContainsString('"name": "Home"', $html);
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

        // Assert – two positions in the JSON-LD
        $this->assertStringContainsString('"position": 1', $html);
        $this->assertStringContainsString('"position": 2', $html);
        $this->assertStringContainsString('"name": "Home"', $html);
        $this->assertStringContainsString('"name": "Category"', $html);
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
}
