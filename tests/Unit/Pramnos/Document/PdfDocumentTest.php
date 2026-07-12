<?php

declare(strict_types=1);

/**
 * Fake TCPDF defined in the production namespace.
 *
 * Pdf::render() instantiates `new TCPDF(...)` without a leading backslash, so
 * inside the Pramnos\Document\DocumentTypes namespace the reference resolves
 * to Pramnos\Document\DocumentTypes\TCPDF.  The real TCPDF package is not
 * installed in this project, so this stub records every call render() makes,
 * letting the test verify the full configuration sequence without emitting
 * an actual PDF to stdout.
 */

namespace Pramnos\Document\DocumentTypes {
    if (!class_exists(\Pramnos\Document\DocumentTypes\TCPDF::class, false)) {
        class TCPDF
        {
            /** @var array<int, array{0:string,1:array}> Recorded [method, args] calls */
            public static array $calls = [];

            /** @var array Constructor arguments for paper-size assertions */
            public static array $ctorArgs = [];

            public function __construct(...$args)
            {
                self::$ctorArgs = $args;
                self::$calls    = [];
            }

            public function __call(string $name, array $args): void
            {
                self::$calls[] = [$name, $args];
            }
        }
    }
}

namespace Pramnos\Tests\Unit\Document {

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\DocumentTypes\Pdf;
use Pramnos\Document\DocumentTypes\TCPDF;

/**
 * Unit tests for the Pdf document type.
 *
 * Uses the fake TCPDF above to exercise the entire render() pipeline:
 * paper-size selection, metadata (title/subject/keywords), language array,
 * font/margins configuration, script/object stripping from the HTML buffer,
 * and the final writeHTML/Output calls.
 */
#[CoversClass(Pdf::class)]
class PdfDocumentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // TCPDF configuration constants normally provided by the library.
        $defaults = [
            'PDF_PAGE_ORIENTATION' => 'P',
            'PDF_UNIT'             => 'mm',
            'PDF_FONT_SIZE_MAIN'   => 10,
            'PDF_FONT_SIZE_DATA'   => 8,
            'PDF_FONT_MONOSPACED'  => 'courier',
            'PDF_MARGIN_LEFT'      => 15,
            'PDF_MARGIN_RIGHT'     => 15,
            'PDF_MARGIN_FOOTER'    => 10,
            'PDF_MARGIN_BOTTOM'    => 25,
            'PDF_IMAGE_SCALE_RATIO' => 1.25,
        ];
        foreach ($defaults as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    protected function tearDown(): void
    {
        // The content buffer is static on Document — clear it so other
        // Document tests are not affected.
        $doc = new Pdf();
        $doc->setContent('');
    }

    /** Return the recorded calls matching the given TCPDF method name. */
    private function callsTo(string $method): array
    {
        return array_values(array_filter(
            TCPDF::$calls,
            fn(array $c) => $c[0] === $method
        ));
    }

    // =========================================================================
    // render() — default configuration
    // =========================================================================

    /**
     * render() with no title must default the PDF metadata to "Report" and
     * use A4 paper when printpaper is not set.
     */
    public function testRenderDefaultsToA4AndReportMetadata(): void
    {
        // Arrange
        $doc = new Pdf();
        $doc->title = '';
        $doc->setContent('<p>Hello PDF</p>');

        // Act
        $doc->render();

        // Assert — paper size defaults to A4 (3rd constructor argument)
        $this->assertSame('A4', TCPDF::$ctorArgs[2],
            'Default paper size must be A4 when printpaper is empty');

        // Metadata falls back to "Report"
        $titles = $this->callsTo('SetTitle');
        $this->assertCount(1, $titles);
        $this->assertSame('Report', $titles[0][1][0],
            'Empty title must fall back to "Report" metadata');
        $this->assertSame('Report', $this->callsTo('SetSubject')[0][1][0]);
        $this->assertSame('Report', $this->callsTo('SetKeywords')[0][1][0]);

        // Creator is always the framework
        $this->assertSame('PramnosFramework', $this->callsTo('SetCreator')[0][1][0]);
    }

    /**
     * render() with a custom title must propagate it to title, subject and
     * keywords metadata.
     */
    public function testRenderUsesDocumentTitleForMetadata(): void
    {
        // Arrange
        $doc = new Pdf();
        $doc->title = 'Monthly Invoice';
        $doc->setContent('<p>Invoice content</p>');

        // Act
        $doc->render();

        // Assert
        $this->assertSame('Monthly Invoice', $this->callsTo('SetTitle')[0][1][0]);
        $this->assertSame('Monthly Invoice', $this->callsTo('SetSubject')[0][1][0]);
        $this->assertSame('Monthly Invoice', $this->callsTo('SetKeywords')[0][1][0]);
    }

    /**
     * render() must honour a custom printpaper value instead of A4.
     */
    public function testRenderUsesCustomPrintPaper(): void
    {
        // Arrange
        $doc = new Pdf();
        $doc->printpaper = 'A5';
        $doc->setContent('<p>Small page</p>');

        // Act
        $doc->render();

        // Assert
        $this->assertSame('A5', TCPDF::$ctorArgs[2],
            'Custom printpaper must be forwarded to the TCPDF constructor');
    }

    /**
     * render() must strip <script> and <object> blocks from the HTML buffer
     * before handing it to writeHTML — PDFs cannot execute them and TCPDF
     * chokes on them.
     */
    public function testRenderStripsScriptAndObjectBlocks(): void
    {
        // Arrange
        $doc = new Pdf();
        $doc->setContent(
            '<p>Keep me</p>'
            . '<script type="text/javascript">alert(1);</script>'
            . '<object data="movie.swf" type="application/x-shockwave"></object>'
        );

        // Act
        $doc->render();

        // Assert — writeHTML received the sanitised buffer
        $writes = $this->callsTo('writeHTML');
        $this->assertCount(1, $writes, 'writeHTML must be called exactly once');
        $html = $writes[0][1][0];
        $this->assertStringContainsString('Keep me', $html);
        $this->assertStringNotContainsString('<script', $html,
            'Script blocks must be stripped before rendering');
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('<object', $html,
            'Object blocks must be stripped before rendering');
    }

    /**
     * render() must run the full TCPDF configuration sequence: fonts,
     * margins, auto page break, image scale, AddPage and final Output.
     */
    public function testRenderConfiguresPdfAndOutputs(): void
    {
        // Arrange
        $doc = new Pdf();
        $doc->setContent('<p>Full pipeline</p>');

        // Act
        $doc->render();

        // Assert — each configuration step happened at least once
        foreach (['setHeaderFont', 'setFooterFont', 'SetDefaultMonospacedFont',
                  'SetMargins', 'SetFooterMargin', 'SetHeaderMargin',
                  'setPrintHeader', 'SetAutoPageBreak', 'setImageScale',
                  'SetFont', 'AddPage', 'Output'] as $method) {
            $this->assertNotEmpty($this->callsTo($method),
                "render() must call TCPDF::{$method}()");
        }

        // Language array must be set (twice in the current implementation)
        $langCalls = $this->callsTo('setLanguageArray');
        $this->assertNotEmpty($langCalls);
        $l = $langCalls[0][1][0];
        $this->assertSame('UTF-8', $l['a_meta_charset']);
        $this->assertSame('ltr', $l['a_meta_dir']);
        $this->assertArrayHasKey('w_page', $l,
            'The translated "Page" string must be in the language array');

        // Output must be inline ('I') with the expected filename
        $out = $this->callsTo('Output')[0][1];
        $this->assertSame('export.pdf', $out[0]);
        $this->assertSame('I', $out[1]);
    }
}

}
