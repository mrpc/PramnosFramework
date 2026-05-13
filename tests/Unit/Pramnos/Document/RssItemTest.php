<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\DocumentTypes\Rss\Item;

/**
 * Unit tests for Pramnos\Document\DocumentTypes\Rss\Item.
 *
 * Item is a simple value object whose render() method produces an XML
 * <item> fragment for inclusion in an RSS feed.
 */
#[CoversClass(Item::class)]
class RssItemTest extends TestCase
{
    // =========================================================================
    // Default state
    // =========================================================================

    /**
     * A freshly created Item has all public properties set to their zero values
     * (empty strings, 0 for numeric fields) so render() never produces nulls.
     */
    public function testDefaultPropertiesAreEmpty(): void
    {
        // Arrange / Act
        $item = new Item();

        // Assert – all string fields default to ''
        $this->assertSame('', $item->title);
        $this->assertSame('', $item->link);
        $this->assertSame('', $item->description);
        $this->assertSame('', $item->guid);
        $this->assertSame('', $item->pubDate);
        $this->assertSame('', $item->author);
        // Enclosure length defaults to 0
        $this->assertSame(0, $item->enclosure_length);
        // Enclosure type has a sensible default
        $this->assertSame('audio/mpeg', $item->enclosure_type);
    }

    // =========================================================================
    // render()
    // =========================================================================

    /**
     * render() always returns an XML <item>…</item> block wrapped in CDATA
     * for title, description, and guid.
     */
    public function testRenderProducesXmlItemBlock(): void
    {
        // Arrange
        $item = new Item();
        $item->title       = 'Test Title';
        $item->description = 'Test Description';
        $item->link        = 'http://example.com/post/1';
        $item->pubDate     = 'Mon, 01 Jan 2024 00:00:00 +0000';

        // Act
        $xml = $item->render();

        // Assert – outer tag
        $this->assertStringContainsString('<item>', $xml);
        $this->assertStringContainsString('</item>', $xml);
    }

    /**
     * render() wraps title and description in CDATA sections so special
     * characters do not need escaping.
     */
    public function testRenderWrapsTitleAndDescriptionInCdata(): void
    {
        // Arrange
        $item = new Item();
        $item->title       = 'News & Updates';
        $item->description = 'Hello <world>';
        $item->link        = 'http://example.com/1';

        // Act
        $xml = $item->render();

        // Assert – CDATA wrapping present
        $this->assertStringContainsString('<title><![CDATA[News & Updates]]></title>', $xml);
        $this->assertStringContainsString('<description><![CDATA[Hello <world>]]></description>', $xml);
    }

    /**
     * render() uses the link value as the <guid> CDATA content, which is the
     * conventional RSS pattern (link = globally unique identifier).
     */
    public function testRenderUsesLinkAsGuid(): void
    {
        // Arrange
        $item = new Item();
        $item->title = 'Post';
        $item->link  = 'http://example.com/post/42';

        // Act
        $xml = $item->render();

        // Assert – link appears inside <guid>
        $this->assertStringContainsString(
            '<guid><![CDATA[http://example.com/post/42]]></guid>',
            $xml
        );
    }

    /**
     * render() includes the pubDate as a plain text element (not CDATA),
     * matching the RSS 2.0 specification.
     */
    public function testRenderIncludesPubDate(): void
    {
        // Arrange
        $item          = new Item();
        $item->pubDate = 'Mon, 01 Jan 2024 12:00:00 +0000';

        // Act
        $xml = $item->render();

        // Assert
        $this->assertStringContainsString(
            '<pubDate>Mon, 01 Jan 2024 12:00:00 +0000</pubDate>',
            $xml
        );
    }

    /**
     * render() produces well-formed XML — the output can be loaded by
     * SimpleXML without errors when wrapped in a root element.
     */
    public function testRenderProducesWellFormedXml(): void
    {
        // Arrange
        $item              = new Item();
        $item->title       = 'Valid Title';
        $item->description = 'Valid description';
        $item->link        = 'http://example.com/valid';
        $item->pubDate     = 'Mon, 01 Jan 2024 00:00:00 +0000';

        // Act
        $xml = $item->render();

        // Assert – wrap in a root element and parse
        $wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>' . $xml . '</root>';
        libxml_use_internal_errors(true);
        $result = simplexml_load_string($wrapped);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($result, 'render() output is not well-formed XML');
        $this->assertEmpty($errors, 'render() output has XML parse errors');
    }
}
