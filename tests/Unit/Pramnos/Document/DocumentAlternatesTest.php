<?php

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Application\Settings;

/**
 * Unit tests for Document::addAlternate()/addAlternatesFromRoute() and the
 * hreflang markup they produce — the first piece of hreflang support
 * (docs/Hreflang-And-Sitemap-Design.md), structural case only: what the
 * router already knows from a localized route group.
 */
#[CoversClass(Document::class)]
class DocumentAlternatesTest extends TestCase
{
    protected $document;

    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://example.com/');
        }

        $this->document = new class extends Document {
            public function render() { return ''; }
        };
        $this->document->type = 'html';
    }

    protected function tearDown(): void
    {
        // Leave the in-memory setting as other tests expect it.
        Settings::setSetting('default_language', false, false);
    }

    /**
     * No alternates added → alternateHeadMarkup() returns the empty string,
     * the same "absent is not empty" contract as metaTag() — a page that
     * never calls addAlternate() gets no hreflang block at all, not an
     * x-default pointing at nothing.
     */
    public function testEmptyWithNoAlternatesAdded(): void
    {
        // Act
        $markup = $this->renderAlternates();

        // Assert
        $this->assertSame('', $markup);
    }

    /**
     * Each added locale gets its own <link rel="alternate" hreflang="…">,
     * and an x-default is added pointing at the default_language entry.
     */
    public function testEmitsOneLinkPerAlternatePlusXDefault(): void
    {
        // Arrange
        Settings::setSetting('default_language', 'en', false);
        $this->document->addAlternate('el', 'http://example.com/syxnes-erwthseis');
        $this->document->addAlternate('en', 'http://example.com/faqs');

        // Act
        $markup = $this->renderAlternates();

        // Assert — one tag per locale plus x-default
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="el" href="http://example.com/syxnes-erwthseis" />',
            $markup
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="http://example.com/faqs" />',
            $markup
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="x-default" href="http://example.com/faqs" />',
            $markup
        );
    }

    /**
     * When the default_language setting's locale was never added, x-default
     * falls back to the first alternate rather than emitting nothing — a
     * missing default_language configuration must not break the markup.
     */
    public function testXDefaultFallsBackToFirstAlternateWhenDefaultLanguageIsMissing(): void
    {
        // Arrange — default_language left unset
        $this->document->addAlternate('el', 'http://example.com/syxnes-erwthseis');
        $this->document->addAlternate('en', 'http://example.com/faqs');

        // Act
        $markup = $this->renderAlternates();

        // Assert
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="x-default" href="http://example.com/syxnes-erwthseis" />',
            $markup
        );
    }

    /**
     * A locale or URL containing a double quote is escaped the same way
     * every other head value is (escapeHeadValue()) — confirms the new
     * method actually routes through the shared escaper rather than
     * concatenating a caller's value raw into the head.
     */
    public function testLocaleAndUrlAreEscaped(): void
    {
        // Arrange
        $this->document->addAlternate('en" onmouseover="alert(1)', 'http://example.com/"><script>');

        // Act
        $markup = $this->renderAlternates();

        // Assert — the raw payload never appears unescaped
        $this->assertStringNotContainsString('<script>', $markup);
        $this->assertStringNotContainsString('onmouseover="alert(1)', $markup);
    }

    /**
     * addAlternatesFromRoute() does nothing for a null route — the shape an
     * unmatched request's getMatchedRoute() hands back.
     */
    public function testAddAlternatesFromRouteDoesNothingForANullRoute(): void
    {
        // Arrange
        $router = new \Pramnos\Routing\Router(new \Pramnos\Application\Application('myApp'));

        // Act
        $this->document->addAlternatesFromRoute($router, null);

        // Assert
        $this->assertSame('', $this->renderAlternates());
    }

    /**
     * addAlternatesFromRoute() does nothing for a route that was registered
     * the ordinary way (no locale group) — a page the router cannot derive
     * alternates for stays silent rather than guessing.
     */
    public function testAddAlternatesFromRouteDoesNothingForAnUnlocalizedRoute(): void
    {
        // Arrange
        $router = new \Pramnos\Routing\Router(new \Pramnos\Application\Application('myApp'));
        $router->get('/about', fn() => 'about');
        $route = $router->getMatchedRoute(\Pramnos\Http\Request::create('/about', 'GET'));

        // Act
        $this->document->addAlternatesFromRoute($router, $route);

        // Assert
        $this->assertSame('', $this->renderAlternates());
    }

    /**
     * addAlternatesFromRoute() adds one alternate per locale sibling of a
     * matched locale-grouped route, including the current locale itself —
     * the "free hreflang" case design doc §3 describes.
     */
    public function testAddAlternatesFromRouteAddsEverySibling(): void
    {
        // Arrange
        $router = new \Pramnos\Routing\Router(new \Pramnos\Application\Application('myApp'));
        $router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], fn() => 'faq-page');
        $route = $router->getMatchedRoute(\Pramnos\Http\Request::create('/faqs', 'GET'));

        // Act
        $this->document->addAlternatesFromRoute($router, $route);
        $markup = $this->renderAlternates();

        // Assert — absolute URLs for both siblings, including the one that was matched.
        // sURL is a global constant other tests in this suite may have already defined
        // to something other than this file's own default, so built from it rather than
        // hardcoded.
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="el" href="' . sURL . 'syxnes-erwthseis" />',
            $markup
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="' . sURL . 'faqs" />',
            $markup
        );
    }

    /**
     * Invokes the protected alternateHeadMarkup() method, the same pattern
     * used elsewhere in this suite (ContentReachesTheDocumentTest) for
     * protected head-rendering methods.
     */
    private function renderAlternates(): string
    {
        return (string) (new \ReflectionMethod(Document::class, 'alternateHeadMarkup'))
            ->invoke($this->document);
    }
}
