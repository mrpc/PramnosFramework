<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Breadcrumb;
use Pramnos\Html\Date;
use Pramnos\Html\Html;

/**
 * Characterization tests for the Html subsystem: Breadcrumb, Html (base),
 * and Date utility.
 *
 * All tests are pure-logic (no DB, no network).
 */
#[CoversClass(Breadcrumb::class)]
#[CoversClass(Html::class)]
#[CoversClass(Date::class)]
class HtmlCharacterizationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Breadcrumb
    // -----------------------------------------------------------------------

    /**
     * A new Breadcrumb has an empty items list.
     */
    public function testBreadcrumbItemsEmptyByDefault(): void
    {
        // Arrange & Act
        $bc = new Breadcrumb();

        // Assert
        $this->assertSame([], $bc->items);
    }

    /**
     * addItem() stores an item indexed by label.
     */
    public function testAddItemStoresByLabel(): void
    {
        // Arrange
        $bc = new Breadcrumb();

        // Act
        $bc->addItem('Home', '/');

        // Assert
        $this->assertArrayHasKey('Home', $bc->items);
        $this->assertSame('/', $bc->items['Home']['url']);
    }

    /**
     * addItem() stores the title when provided.
     */
    public function testAddItemStoresTitle(): void
    {
        // Arrange
        $bc = new Breadcrumb();

        // Act
        $bc->addItem('About', '/about', 'About Us');

        // Assert
        $this->assertSame('About Us', $bc->items['About']['title']);
    }

    /**
     * render() produces a <nav> containing an <ol class="breadcrumb">.
     */
    public function testRenderContainsBootstrapBreadcrumbStructure(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Home', '/');

        // Act
        $html = $bc->render();

        // Assert
        $this->assertStringContainsString('<nav ', $html);
        $this->assertStringContainsString('class="breadcrumb"', $html);
        $this->assertStringContainsString('<ol', $html);
        $this->assertStringContainsString('</nav>', $html);
    }

    /**
     * render() embeds JSON-LD structured data for BreadcrumbList.
     */
    public function testRenderContainsJsonLdStructuredData(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Docs', '/docs');

        // Act
        $html = $bc->render();

        // Assert
        $this->assertStringContainsString('"@type": "BreadcrumbList"', $html);
        $this->assertStringContainsString('"@context": "https://schema.org"', $html);
    }

    /**
     * render() includes each item's label text in the output.
     */
    public function testRenderContainsItemLabels(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Features', '/features');
        $bc->addItem('Caching', '/features/cache');

        // Act
        $html = $bc->render();

        // Assert – both labels appear
        $this->assertStringContainsString('Features', $html);
        $this->assertStringContainsString('Caching', $html);
    }

    /**
     * render() wraps items with a URL in an <a> tag.
     */
    public function testRenderWrapsItemWithUrlInAnchorTag(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Home', '/');

        // Act
        $html = $bc->render();

        // Assert
        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('href="/"', $html);
    }

    /**
     * render() uses <span> instead of <a> for items with no URL.
     */
    public function testRenderUsesSpanForItemWithNoUrl(): void
    {
        // Arrange
        $bc = new Breadcrumb();
        $bc->addItem('Current Page'); // no URL

        // Act
        $html = $bc->render();

        // Assert – no <a> tag because URL is empty
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('<span', $html);
    }

    // -----------------------------------------------------------------------
    // Html (base class)
    // -----------------------------------------------------------------------

    /**
     * Html::getDate() returns a new Date instance.
     */
    public function testGetDateReturnsDateInstance(): void
    {
        // Arrange
        $html = new Html();

        // Act
        $date = $html->getDate();

        // Assert
        $this->assertInstanceOf(\Pramnos\Html\Date::class, $date);
    }

    /**
     * Html::render() returns the value assigned to $_content.
     */
    public function testRenderReturnsContentProperty(): void
    {
        // Arrange
        $html = new Html();
        $ref  = new \ReflectionProperty($html, '_content');
        $ref->setValue($html, '<div>hello</div>');

        // Act & Assert
        $this->assertSame('<div>hello</div>', $html->render());
    }

    /**
     * __toString() returns the same value as render().
     */
    public function testToStringDelegatesToRender(): void
    {
        // Arrange
        $html = new Html();
        $ref  = new \ReflectionProperty($html, '_content');
        $ref->setValue($html, 'test content');

        // Act
        $result = (string) $html;

        // Assert
        $this->assertSame('test content', $result);
    }

    // -----------------------------------------------------------------------
    // Date – static getHtmlDate()
    // -----------------------------------------------------------------------

    /**
     * Date::getHtmlDate() converts an HTML5 date string to a Unix timestamp.
     * The resulting timestamp points to midnight of the given date.
     */
    public function testGetHtmlDateConvertsDateStringToTimestamp(): void
    {
        // Arrange
        $dateStr = '2024-06-15';

        // Act
        $ts = Date::getHtmlDate($dateStr);

        // Assert – round-trip back to the same date
        $this->assertSame('2024-06-15', date('Y-m-d', $ts));
    }

    /**
     * Date::getHtmlDate() handles Unix epoch (1970-01-01) correctly.
     */
    public function testGetHtmlDateHandlesEpoch(): void
    {
        // Act
        $ts = Date::getHtmlDate('1970-01-01');

        // Assert
        $this->assertSame('1970-01-01', date('Y-m-d', $ts));
    }

    /**
     * Date constructor sets name and date properties.
     */
    public function testDateConstructorSetsNameAndDate(): void
    {
        // Arrange
        $ts = mktime(0, 0, 0, 3, 15, 2023);

        // Act
        $date = new Date('myfield', $ts);

        // Assert
        $this->assertSame('myfield', $date->name);
        $this->assertSame($ts, $date->date);
    }

    /**
     * Date constructor strips spaces from the field name.
     */
    public function testDateConstructorStripsSpacesFromName(): void
    {
        // Act
        $date = new Date('my field name');

        // Assert
        $this->assertSame('myfieldname', $date->name);
    }

    /**
     * Date __toString() returns a string (renders the widget).
     * Full rendering depends on Document::getInstance(); we only assert
     * the return type here to lock the interface.
     */
    public function testDateToStringReturnsString(): void
    {
        // Arrange
        $date = new Date('afield', time());

        // Act
        $result = (string) $date;

        // Assert
        $this->assertIsString($result);
    }
}
