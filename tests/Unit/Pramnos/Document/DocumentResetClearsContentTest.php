<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * `Document::reset()` must actually reset the document.
 *
 * The page body does not live on the instance. Every concrete document type reads
 * it from a **static** buffer, which `addContent()` appends to — so discarding the
 * instances left the previous request's page exactly where the next document would
 * find it.
 *
 * The effect compounds: measured in a project's suite, a login page grew by about
 * 2.9 KB per request and reached **1.7 MB**, carrying a hundred copies of an
 * unrelated screen's inline script. Nothing failed. `assertSee()` passed on content
 * from a page the test had never asked for, and `assertDontSee()` failed on a page
 * the test had already left — the first being the half that does damage, because a
 * passing test is not investigated.
 *
 * `reset()` is what the isolation extension and `TestClient` call between requests.
 * It is the one place this can be fixed for all of them.
 */
class DocumentResetClearsContentTest extends TestCase
{
    protected function setUp(): void
    {
        Document::reset();
    }

    protected function tearDown(): void
    {
        Document::reset();
    }

    /**
     * Content added before a reset is not in the document after it.
     */
    public function testResetDiscardsTheContentBuffer(): void
    {
        // Arrange
        Document::_addContent('CONTENT-FROM-AN-EARLIER-REQUEST');
        $this->assertStringContainsString(
            'CONTENT-FROM-AN-EARLIER-REQUEST',
            Document::_getContent(),
            'precondition: the content is in the buffer'
        );

        // Act
        Document::reset();

        // Assert
        $this->assertSame('', Document::_getContent());
    }

    /**
     * And a document built after the reset renders without it.
     *
     * The assertion above reads the buffer directly; this one goes the way a page
     * does, which is where the bug was visible.
     */
    public function testADocumentBuiltAfterAResetRendersWithoutTheOldContent(): void
    {
        // Arrange
        $first = Document::getInstance('raw');
        $first->addContent('PAGE-ONE');
        $this->assertStringContainsString('PAGE-ONE', (string) $first->render(), 'precondition');

        // Act
        Document::reset();
        $second = Document::getInstance('raw');
        $second->addContent('PAGE-TWO');
        $rendered = (string) $second->render();

        // Assert
        $this->assertStringContainsString('PAGE-TWO', $rendered);
        $this->assertStringNotContainsString('PAGE-ONE', $rendered,
            'a document built after a reset must not carry the previous page');
    }

    /**
     * Two additions in one request still accumulate, which is what `addContent()`
     * is for.
     *
     * The fix is about what survives a reset, not about making the buffer
     * write-once — a page assembled from several calls is ordinary.
     */
    public function testContentStillAccumulatesWithinOneRequest(): void
    {
        // Act
        $document = Document::getInstance('raw');
        $document->addContent('FIRST-');
        $document->addContent('SECOND');

        // Assert
        $this->assertStringContainsString('FIRST-SECOND', (string) $document->render());
    }
}
