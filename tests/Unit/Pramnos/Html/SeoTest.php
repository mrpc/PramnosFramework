<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Amp;
use Pramnos\Document\DocumentTypes\Html;
use Pramnos\Framework\Factory;
use Pramnos\Html\Seo;
use Pramnos\Http\Request;

/**
 * Canonical links and JSON-LD, from the framework rather than from each application.
 *
 * The HTML document type had **no way to emit a canonical at all** — only the AMP one
 * did, and it computed its own. The single route was `addHeadContent()` with a
 * hand-built `<link>`, which meant every application escaped the URL itself or forgot
 * to. Structured data had the same shape, and the method whose name is closest,
 * `addInlineScript()`, hardcodes a bare `<script>` with no `type` **into the foot** —
 * so following it would have handed JSON-LD to the browser as JavaScript.
 *
 * The encoding flags carry most of the weight here, and `JSON_HEX_TAG` carries most of
 * that: a `</script>` inside any value ends the block early and everything after it is
 * parsed as markup. Structured data is assembled from record titles and descriptions,
 * which is exactly where such a string arrives from.
 */
class SeoTest extends TestCase
{
    /**
     * Gives the language what the renderers ask for.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!class_exists('pramnos_request')) {
            class_alias(Request::class, 'pramnos_request');
        }
        Factory::getLanguage()->addlang(['LangShort' => 'en', 'CHARSET' => 'UTF-8']);
        Request::$originalRequestNoChange = '/a-page';
        Document::_setContent('');
    }

    /**
     * Clears the shared content buffer.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Document::_setContent('');
    }

    /**
     * A `</script>` inside a value cannot end the block.
     *
     * The one injection this format has, and the reason `JSON_HEX_TAG` is not
     * optional. Without it the block closes early and the rest of the value is
     * markup.
     *
     * @return void
     */
    public function testAClosingScriptTagInsideAValueIsNeutralised(): void
    {
        // Act
        $markup = Seo::jsonLd([
            '@type' => 'Article',
            'name'  => 'Trouble </script><img src=x onerror=alert(1)>',
        ]);

        // Assert — exactly one closing tag, and it is ours
        $this->assertSame(1, substr_count($markup, '</script>'));
        $this->assertStringNotContainsString('<img src=x', $markup);
    }

    /**
     * URLs and non-Latin text stay readable.
     *
     * Both are valid either way; escaped, the block is unreadable in view-source,
     * which is the only place anybody ever checks it.
     *
     * @return void
     */
    public function testUrlsAndUnicodeAreNotEscapedIntoIllegibility(): void
    {
        // Act
        $markup = Seo::jsonLd([
            'url'  => 'https://example.com/station/rock',
            'name' => 'Ραδιόφωνο',
        ]);

        // Assert
        $this->assertStringContainsString('https://example.com/station/rock', $markup);
        $this->assertStringNotContainsString('https:\/\/', $markup);
        $this->assertStringContainsString('Ραδιόφωνο', $markup);
    }

    /**
     * Nothing to say produces nothing.
     *
     * An empty `<script type="application/ld+json">{}</script>` in every page is the
     * kind of thing that survives for years because nobody notices it.
     *
     * @return void
     */
    public function testEmptyDataProducesNoMarkup(): void
    {
        // Act & Assert
        $this->assertSame('', Seo::jsonLd([]));
        $this->assertSame('', Seo::canonicalLink(''));
        $this->assertSame('', Seo::canonicalLink('   '));
    }

    /**
     * Data that cannot be encoded costs the block, not the page.
     *
     * A resource handle or invalid UTF-8 in one field would otherwise make
     * `json_encode()` return `false` and put the literal `false` — an empty string —
     * inside a script tag. A page without structured data is a smaller problem than a
     * page with a broken script block in its head.
     *
     * @return void
     */
    public function testUnencodableDataYieldsNothingRatherThanABrokenBlock(): void
    {
        // Arrange — a lone continuation byte is not valid UTF-8
        $data = ['name' => "bad \xB1 byte", 'handle' => fopen('php://memory', 'r')];

        // Act
        $markup = Seo::jsonLd($data);

        // Assert
        $this->assertSame('', $markup);
    }

    /**
     * A canonical URL is escaped for the attribute it lands in.
     *
     * @return void
     */
    public function testTheCanonicalUrlIsEscaped(): void
    {
        // Act
        $markup = Seo::canonicalLink('https://example.com/x" onload="alert(1)');

        // Assert
        $this->assertStringNotContainsString('onload="alert(1)"', $markup);
        $this->assertStringContainsString('&quot;', $markup);
    }

    /**
     * The HTML document emits both, which it previously could not do at all.
     *
     * @return void
     */
    public function testTheHtmlDocumentEmitsCanonicalAndStructuredData(): void
    {
        // Arrange
        $doc = new Html();
        $doc->setCanonical('https://example.com/station/rock');
        $doc->addStructuredData(['@type' => 'RadioStation', 'name' => 'Rock FM']);

        // Act
        $output = $doc->render();

        // Assert
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/station/rock">',
            $output
        );
        $this->assertStringContainsString('application/ld+json', $output);
        $this->assertStringContainsString('Rock FM', $output);
    }

    /**
     * Several blocks stay several blocks.
     *
     * A station page carries the station and its breadcrumb trail. Merging two
     * `@type`s into one object produces something no validator accepts, so each call
     * gets its own script.
     *
     * @return void
     */
    public function testEachStructuredDataCallGetsItsOwnBlock(): void
    {
        // Arrange
        $doc = new Html();
        $doc->addStructuredData(['@type' => 'RadioStation']);
        $doc->addStructuredData(['@type' => 'BreadcrumbList']);

        // Act
        $output = $doc->render();

        // Assert
        $this->assertSame(2, substr_count($output, 'application/ld+json'));
    }

    /**
     * A document with neither emits neither.
     *
     * @return void
     */
    public function testADocumentWithNoSeoDataAddsNothing(): void
    {
        // Act
        $output = (new Html())->render();

        // Assert
        $this->assertStringNotContainsString('rel="canonical"', $output);
        $this->assertStringNotContainsString('ld+json', $output);
    }

    /**
     * AMP takes the structured data and keeps its own single canonical.
     *
     * It computes a canonical when none was set, and has emitted one since long
     * before this. A second `<link rel="canonical">` on one page is undefined
     * behaviour to a crawler — worse than having none — so the shared helper
     * deliberately does not add one here.
     *
     * @return void
     */
    public function testAmpKeepsExactlyOneCanonical(): void
    {
        // Arrange
        $doc = new Amp();
        $doc->addStructuredData(['@type' => 'NewsArticle']);

        // Act
        $output = $doc->render();

        // Assert
        $this->assertSame(1, substr_count($output, 'rel="canonical"'));
        $this->assertStringContainsString('application/ld+json', $output);
    }
}
