<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\DocumentTypes\Rss;
use Pramnos\Document\DocumentTypes\Rss\Item;

/**
 * Unit tests for Pramnos\Document\DocumentTypes\Rss.
 *
 * Tests cover the pure-logic methods (newItem, addItem, removeItem) and
 * the render() method for the XML structure that does not depend on global
 * Settings or the Language singleton.  When title/link/description are set
 * explicitly, render() uses those values and skips the Settings lookup.
 */
#[CoversClass(Rss::class)]
class RssTest extends TestCase
{
    protected function setUp(): void
    {
        // render() references sURL as a fallback when $this->link is empty.
        if (!defined('sURL')) {
            define('sURL', 'http://example.com/');
        }
    }

    // =========================================================================
    // newItem
    // =========================================================================

    /**
     * newItem() returns a fresh Rss\Item instance each time it is called.
     * Items are independent — modifying one does not affect another.
     */
    public function testNewItemReturnsRssItemInstance(): void
    {
        // Arrange
        $feed = new Rss();

        // Act
        $item = $feed->newItem();

        // Assert
        $this->assertInstanceOf(Item::class, $item);
    }

    /**
     * newItem() returns a different object on each call (not a singleton).
     */
    public function testNewItemReturnsDistinctObjects(): void
    {
        // Arrange
        $feed = new Rss();

        // Act
        $a = $feed->newItem();
        $b = $feed->newItem();

        // Assert – different instances
        $this->assertNotSame($a, $b);
    }

    // =========================================================================
    // addItem / removeItem
    // =========================================================================

    /**
     * addItem() appends an item to the internal collection and returns $this
     * for fluent chaining.
     */
    public function testAddItemReturnsSelf(): void
    {
        // Arrange
        $feed = new Rss();
        $item = new Item();
        $item->link = 'http://example.com/1';

        // Act
        $result = $feed->addItem($item);

        // Assert – fluent interface
        $this->assertSame($feed, $result);
    }

    /**
     * addItem() with the same link URL twice only stores the item once —
     * the collection is keyed by link to avoid duplicate entries.
     */
    public function testAddItemIgnoresDuplicateLink(): void
    {
        // Arrange
        $feed = new Rss();

        $item1 = new Item();
        $item1->link  = 'http://example.com/post/1';
        $item1->title = 'First';

        $item2 = new Item();
        $item2->link  = 'http://example.com/post/1';  // same URL
        $item2->title = 'Second';

        // Act
        $feed->addItem($item1);
        $feed->addItem($item2);

        // Assert – only the first item is kept (second is silently dropped)
        $xml = $feed->render();
        $this->assertSame(1, substr_count($xml, '<item>'));
        $this->assertStringContainsString('First', $xml);
        $this->assertStringNotContainsString('Second', $xml);
    }

    /**
     * removeItem() removes a previously added item and returns $this.
     * The item no longer appears in render() output.
     */
    public function testRemoveItemReturnsSelfAndRemovesItem(): void
    {
        // Arrange
        $feed = new Rss();
        $item = new Item();
        $item->link  = 'http://example.com/post/2';
        $item->title = 'To Remove';
        $feed->addItem($item);

        // Act
        $result = $feed->removeItem($item);

        // Assert – fluent interface
        $this->assertSame($feed, $result);

        // Assert – item no longer in rendered output
        $xml = $feed->render();
        $this->assertStringNotContainsString('To Remove', $xml);
    }

    /**
     * removeItem() on an item that was never added is a no-op and returns
     * $this without throwing.
     */
    public function testRemoveItemNotAddedIsNoOp(): void
    {
        // Arrange
        $feed = new Rss();
        $item = new Item();
        $item->link = 'http://example.com/ghost';

        // Act – should not throw
        $result = $feed->removeItem($item);

        // Assert
        $this->assertSame($feed, $result);
    }

    // =========================================================================
    // render
    // =========================================================================

    /**
     * render() produces an XML string beginning with the XML declaration and
     * containing the RSS 2.0 root element with the expected channel wrapper.
     */
    public function testRenderProducesRssXmlStructure(): void
    {
        // Arrange
        $feed              = new Rss();
        $feed->title       = 'My Feed';
        $feed->link        = 'http://example.com/';
        $feed->description = 'A test feed';
        $feed->webMaster   = 'admin@example.com';

        // Act
        $xml = $feed->render();

        // Assert – RSS scaffold
        $this->assertStringContainsString('<?xml version="1.0"?>', $xml);
        $this->assertStringContainsString('<rss version="2.0">', $xml);
        $this->assertStringContainsString('<channel>', $xml);
        $this->assertStringContainsString('</channel>', $xml);
        $this->assertStringContainsString('</rss>', $xml);
    }

    /**
     * render() includes the feed's title, link, and description when
     * explicitly set, without falling back to Settings::getSetting().
     */
    public function testRenderUsesExplicitFeedProperties(): void
    {
        // Arrange
        $feed              = new Rss();
        $feed->title       = 'Test Channel';
        $feed->link        = 'http://example.com/';
        $feed->description = 'Channel description';
        $feed->webMaster   = 'admin@example.com';

        // Act
        $xml = $feed->render();

        // Assert
        $this->assertStringContainsString('<![CDATA[Test Channel]]>', $xml);
        $this->assertStringContainsString('<![CDATA[http://example.com/]]>', $xml);
        $this->assertStringContainsString('<![CDATA[Channel description]]>', $xml);
    }

    /**
     * render() includes all added items in the output.
     * The feed with two items should contain two <item> blocks.
     */
    public function testRenderIncludesAllAddedItems(): void
    {
        // Arrange
        $feed              = new Rss();
        $feed->title       = 'News';
        $feed->link        = 'http://example.com/';
        $feed->description = 'News feed';
        $feed->webMaster   = 'admin@example.com';

        $item1 = new Item();
        $item1->link  = 'http://example.com/1';
        $item1->title = 'Article One';

        $item2 = new Item();
        $item2->link  = 'http://example.com/2';
        $item2->title = 'Article Two';

        $feed->addItem($item1);
        $feed->addItem($item2);

        // Act
        $xml = $feed->render();

        // Assert
        $this->assertSame(2, substr_count($xml, '<item>'));
        $this->assertStringContainsString('Article One', $xml);
        $this->assertStringContainsString('Article Two', $xml);
    }

    /**
     * render() output with all required fields can be parsed as valid XML
     * by SimpleXML.
     */
    public function testRenderProducesWellFormedXml(): void
    {
        // Arrange
        $feed              = new Rss();
        $feed->title       = 'Feed Title';
        $feed->link        = 'http://example.com/';
        $feed->description = 'Feed Description';
        $feed->webMaster   = 'admin@example.com';

        $item              = new Item();
        $item->link        = 'http://example.com/post/1';
        $item->title       = 'Post One';
        $item->description = 'Post body';
        $item->pubDate     = 'Mon, 01 Jan 2024 00:00:00 +0000';
        $feed->addItem($item);

        // Act
        $xml = $feed->render();

        // Assert – valid XML
        libxml_use_internal_errors(true);
        $result = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($result, 'render() output is not well-formed XML');
        $this->assertEmpty($errors, 'render() output has XML parse errors');
    }
}
