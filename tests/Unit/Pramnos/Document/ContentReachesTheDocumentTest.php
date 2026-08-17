<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Theme\Theme;

/**
 * How a page's content reaches the document — both of the ways it can be put there.
 *
 * Two mechanisms existed and they disagreed. `Document::render()` read the public
 * `$content` property; every concrete type read the static buffer. So on an HTML page
 * `$document->content = $html` produced a correct header, a correct footer, and nothing
 * between them — the theme visibly working, the page visibly empty, and no error anywhere
 * to look up.
 *
 * Reported by a project adopting themes for the first time, and it is not guessable: the
 * property is public, it is what the parent class reads, and it looks exactly like the
 * seam. The reconciliation is one-directional and cannot break a working page — the buffer
 * wins whenever it holds anything, so the only output that changes is output that was
 * blank.
 *
 * The second class in this pair, `ThemeLazyLoadTest`, covers the other half of the same
 * report: a theme object that renders the bare default because nothing ever read its file.
 */
class ContentReachesTheDocumentTest extends TestCase
{
    /**
     * Empties the process-wide content buffer.
     *
     * It is `static`, which is its own hazard: two documents in one process share it. Any
     * test that forgets this reads the previous test's page.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Document::_setContent('');
    }

    /**
     * And leaves it empty for whatever runs next.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Document::_setContent('');
    }

    /**
     * Calls the protected resolver on a given document.
     *
     * @param  Document $document The document to ask
     * @return string
     */
    private function resolve(Document $document): string
    {
        return (string) (new \ReflectionMethod(Document::class, 'bodyContent'))
            ->invoke($document);
    }

    /**
     * A document for one of the concrete types.
     *
     * @param  string $type `html`, `json`, `raw`, `png`, `amp`, `rss`
     * @return Document
     */
    private function document(string $type): Document
    {
        return Document::getInstance($type);
    }

    /**
     * The buffer is the mechanism, and it is what the framework's own API writes.
     *
     * `$document->setContent()` and `addContent()` are ordinary public methods — the ones
     * to reach for. The report described `_setContent()` as "a static method with no
     * mention in the guide", which is true of the static; the instance pair exists and was
     * equally undocumented.
     *
     * @return void
     */
    public function testTheInstanceApiWritesTheBufferThatRenderingReads(): void
    {
        // Arrange
        $document = $this->document('html');

        // Act
        $document->setContent('<p>from setContent</p>');
        $document->addContent('<p>and more</p>');

        // Assert
        $this->assertSame(
            '<p>from setContent</p><p>and more</p>',
            $this->resolve($document)
        );
        $this->assertSame($document->getContent(), $this->resolve($document));
    }

    /**
     * With an empty buffer, the `content` property is used instead of nothing.
     *
     * The fix. Before it, this returned `''` on every concrete type and the page was blank.
     *
     * @return void
     */
    public function testTheContentPropertyIsUsedWhenTheBufferIsEmpty(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->content = '<p>set on the property</p>';

        // Act
        $resolved = $this->resolve($document);

        // Assert
        $this->assertSame('<p>set on the property</p>', $resolved);
    }

    /**
     * The buffer wins when both are set, so no working page changes.
     *
     * The compatibility argument for the whole change, asserted rather than reasoned. Every
     * page that renders today renders from the buffer; if the property could override it,
     * this would be a behaviour change instead of a repair.
     *
     * @return void
     */
    public function testTheBufferWinsWhenBothAreSet(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->content = '<p>the property</p>';
        $document->setContent('<p>the buffer</p>');

        // Act & Assert
        $this->assertSame('<p>the buffer</p>', $this->resolve($document));
    }

    /**
     * Every concrete document type resolves content the same way.
     *
     * The report framed this as `Html` disagreeing with its parent. It is the other way
     * round: `Html`, `Amp`, `Json`, `Png` and `Raw` all read the buffer, and only
     * `Document::render()` — which in practice serves `Rss` — read the property. Fixing
     * `Html` alone would have left the identical trap in four more types.
     *
     * @return void
     */
    public function testEveryDocumentTypeResolvesContentIdentically(): void
    {
        foreach (['html', 'json', 'raw', 'png', 'amp', 'rss'] as $type) {
            // Arrange — the property only, which is the case that used to yield nothing
            Document::_setContent('');
            $document = $this->document($type);
            $document->content = "<p>{$type}</p>";

            // Act & Assert
            $this->assertSame(
                "<p>{$type}</p>",
                $this->resolve($document),
                "Document type '{$type}' must resolve content like every other."
            );
        }
    }

    /**
     * An HTML page rendered from the property is not empty between head and foot.
     *
     * The end-to-end form of the report: a correct header, a correct footer, and nothing in
     * between. Asserted through `render()` rather than the resolver, because the resolver
     * being right is worthless if the renderer does not call it.
     *
     * @return void
     */
    public function testAnHtmlPageRenderedFromThePropertyContainsItsBody(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->content = '<main>THE-PAGE-BODY</main>';
        $document->themeObject = null;

        // Act
        $rendered = $document->render();

        // Assert
        $this->assertStringContainsString('THE-PAGE-BODY', $rendered);
    }

    /**
     * Nothing set anywhere is still an empty body, not a warning or a null.
     *
     * `$content` starts as an empty string and the buffer starts empty; a document with no
     * content is a legitimate state — a redirect, a 204 — and must render as one.
     *
     * @return void
     */
    public function testNoContentAnywhereResolvesToAnEmptyString(): void
    {
        // Arrange
        $document = $this->document('html');

        // Act & Assert
        $this->assertSame('', $this->resolve($document));
    }
}
