<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Document\DocumentTypes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Html;
use Pramnos\Document\DocumentTypes\PrintDocument;

/**
 * The printable document type — the replacement for the TCPDF-backed `pdf`.
 *
 * What is worth testing here is not that HTML comes out; the parent already
 * guarantees that. It is that the page carries the three things that make it
 * printable — a page geometry taken from the properties, the print rules a
 * screen stylesheet never provides, and a call to `window.print()` — and that
 * each of them can be turned off by the document that does not want it.
 */
#[CoversClass(PrintDocument::class)]
class PrintDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://example.com/');
        }
        Document::_setContent('');
    }

    protected function tearDown(): void
    {
        Document::_setContent('');
        parent::tearDown();
    }

    /**
     * It is an HTML document, so everything an HTML document can do still works.
     *
     * The whole point of building this on `Html` rather than on `Document` is
     * that a printable page needs stylesheets, meta tags and a theme exactly as
     * much as a screen page does — the old PDF type had none of that.
     */
    public function testItIsAnHtmlDocument(): void
    {
        // Arrange & Act
        $document = new PrintDocument();

        // Assert
        $this->assertInstanceOf(Html::class, $document);
    }

    /**
     * The page geometry comes from the properties.
     *
     * `@page` is the only way to state paper size and margins to a browser's
     * print engine; a printable page without it prints at whatever the last
     * dialog was set to.
     */
    public function testThePageRuleComesFromTheProperties(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->paperSize   = 'Letter';
        $document->orientation = 'landscape';
        $document->margin      = '5mm';

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString(
            '@page { size: Letter landscape; margin: 5mm; }',
            $output
        );
    }

    /**
     * An orientation that is not portrait or landscape is left out.
     *
     * `size: 210mm 297mm` is a valid page size that already states its
     * orientation; appending a third word to it would make the rule invalid and
     * the browser would drop the whole thing.
     */
    public function testAnExplicitPageSizeIsNotGivenAnOrientation(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->paperSize   = '210mm 297mm';
        $document->orientation = '';

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString('@page { size: 210mm 297mm; margin:', $output);
    }

    /**
     * The print dialog opens by itself, after the page has finished loading.
     *
     * On `load` rather than `DOMContentLoaded`: a dialog that opens before the
     * images and web fonts have arrived prints the page without them.
     */
    public function testThePrintDialogOpensOnLoad(): void
    {
        // Arrange
        $document = new PrintDocument();

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString('window.print()', $output);
        $this->assertStringContainsString("addEventListener('load'", $output);
    }

    /**
     * A document that asks not to print automatically does not.
     *
     * A printable page the user is meant to read first — a report with a date
     * range to check — must not ambush them with a dialog.
     */
    public function testAutoPrintCanBeTurnedOff(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->autoPrint = false;

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringNotContainsString('window.print()', $output);
        $this->assertStringContainsString('@page', $output, 'The styles are still there');
    }

    /**
     * closeAfterPrint waits for `afterprint`, not for a timer.
     *
     * The dialog is modal and the user may sit in it for a minute; closing the
     * window on a timeout cancels the print job the code asked for.
     */
    public function testClosingAfterPrintWaitsForTheEvent(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->closeAfterPrint = true;

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString("addEventListener('afterprint'", $output);
        $this->assertStringContainsString('window.close()', $output);
    }

    /**
     * With nothing to say, no script tag is emitted at all.
     *
     * An empty `<script>` block in every printable page is the kind of thing
     * that survives for years because nobody notices it.
     */
    public function testNoScriptIsEmittedWhenThereIsNothingToRun(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->autoPrint       = false;
        $document->closeAfterPrint = false;

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringNotContainsString('<script>', $output);
    }

    /**
     * `.no-print` is hidden, and so is anything else the document names.
     *
     * The markup a page most wants to drop from a printout — a theme's
     * navigation, a cookie banner — is usually markup it does not control, so
     * hiding it has to be possible from the outside.
     */
    public function testElementsCanBeHiddenFromThePrintout(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->noPrint('.site-nav');
        $document->noPrint('#cookie-banner');
        $document->noPrint('.site-nav');   // twice
        $document->noPrint('  ');          // nothing

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString(
            '.no-print, .site-nav, #cookie-banner { display: none !important; }',
            $output,
            'Each selector appears once, in the order it was added'
        );
    }

    /**
     * Extra CSS is appended after the built-in sheet, so it can override it.
     */
    public function testExtraPrintCssIsAppendedAfterTheBuiltInSheet(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->addPrintCss('.totals { break-inside: avoid; }');

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString('.totals { break-inside: avoid; }', $output);
        $this->assertGreaterThan(
            strpos($output, '@page'),
            strpos($output, '.totals'),
            'Later rules win in CSS, so the document\'s own must come last'
        );
    }

    /**
     * A document with its own complete print CSS can drop the built-in sheet.
     */
    public function testTheBuiltInSheetCanBeDropped(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->baseStyles = false;
        $document->addPrintCss('@page { size: A3; }');

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString('@page { size: A3; }', $output);
        $this->assertStringNotContainsString('-webkit-print-color-adjust', $output);
    }

    /**
     * With no styles at all, no style tag is emitted.
     */
    public function testNoStyleTagIsEmittedWhenThereAreNoStyles(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->baseStyles = false;
        $document->autoPrint  = false;

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringNotContainsString('<style>', $output);
    }

    /**
     * The document's content is rendered, with the print rules around it.
     *
     * The end-to-end check: what goes in comes out, inside a page that will
     * print the way the properties asked for.
     */
    public function testTheContentIsRenderedInsideThePrintablePage(): void
    {
        // Arrange
        $document = new PrintDocument();
        $document->title = 'Invoice 2026-0042';
        Document::_setContent('<h1>Invoice 2026-0042</h1>');

        // Act
        $output = $document->render();

        // Assert
        $this->assertStringContainsString('<h1>Invoice 2026-0042</h1>', $output);
        $this->assertStringContainsString('<title>Invoice 2026-0042</title>', $output);
        $this->assertStringContainsString('break-after: avoid', $output);
    }

    /**
     * The fluent setters return the document.
     */
    public function testTheSettersAreFluent(): void
    {
        // Arrange
        $document = new PrintDocument();

        // Act & Assert
        $this->assertSame($document, $document->addPrintCss('.x {}'));
        $this->assertSame($document, $document->noPrint('.y'));
    }
}
