<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Export\Xlsx;

/**
 * The spreadsheet writer: a workbook of the parts a spreadsheet needs, each sheet named as Excel
 * allows, numbers written as numbers (so they add up) and the rest as text through a shared
 * strings table, the first row bold, columns past Z lettered AA…, characters XML refuses left
 * out, and a CSV — with its byte-order mark — read in as a sheet.
 */
#[CoversClass(Xlsx::class)]
final class XlsxTest extends TestCase
{
    /** The workbook's parts, read back. @return array<string, string> */
    private static function parts(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive();
        $zip->open($file);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name       = (string) $zip->getNameIndex($i);
            $out[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($file);

        return $out;
    }

    /** Two sheets of numbers and text: what a spreadsheet opens, and the cells as written. */
    public function testAWorkbookOfNumbersAndText(): void
    {
        // Arrange
        $wide = array_fill(0, 28, 'x');

        // Act
        $parts = self::parts(Xlsx::write([
            'Sales: 2026/09' => [['Region', 'Orders', 'Share'], ['North', 12, 0.5], ['Νότος', '7', "a\x01 & <b>"], ['-3.25', null, '']],
            ''               => [$wide],
        ]));

        // Assert — [Content_Types].xml is the ZIP's FIRST entry: the OPC spec requires it,
        // and Excel is known to reject an otherwise well-formed .xlsx that puts it later
        // (reported as "we found a problem with some content" on an export this produced
        // with sheet1.xml first).
        $this->assertSame(
            ['[Content_Types].xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/sharedStrings.xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml'],
            array_keys($parts)
        );
        $this->assertStringContainsString('<sheet name="Sales  2026 09" sheetId="1"', $parts['xl/workbook.xml'], 'a name Excel allows');
        $this->assertStringContainsString('<sheet name="Sheet2" sheetId="2"', $parts['xl/workbook.xml'], 'no name: one made');
        $sheet  = $parts['xl/worksheets/sheet1.xml'];
        $shared = $parts['xl/sharedStrings.xml'];
        // Text cells reference the shared strings table by index (first-seen order:
        // "Region"=0, "Orders"=1, "Share"=2, "North"=3, "Νότος"=4, the escaped one=5),
        // rather than carrying their own text inline — what Excel's own writer does,
        // and what large, schema-valid inline-string exports still tripped its repair
        // prompt on.
        $this->assertStringContainsString('<c r="A1" s="1" t="s"><v>0</v></c>', $sheet, 'the first row bold, referencing shared string 0');
        $this->assertStringContainsString(
            '<si><t xml:space="preserve">Region</t></si><si><t xml:space="preserve">Orders</t></si><si><t xml:space="preserve">Share</t></si>',
            $shared,
            'shared strings written in first-seen order'
        );
        $this->assertStringContainsString('<c r="B2"><v>12</v></c><c r="C2"><v>0.5</v></c>', $sheet, 'numbers as numbers');
        $this->assertStringContainsString('<c r="B3"><v>7</v></c>', $sheet, 'a string that reads as a number');
        $this->assertStringContainsString('<si><t xml:space="preserve">a &amp; &lt;b&gt;</t></si>', $shared, 'escaped, and a control character left out');
        $this->assertStringContainsString('<c r="A4"><v>-3.25</v></c></row>', $sheet, 'empty cells left out');
        $this->assertStringContainsString('<c r="AB1"', $parts['xl/worksheets/sheet2.xml'], 'the 28th column');
        // Assert — the table's own counts: 34 text cells reference it in total (6 in
        // sheet1 — 3 header cells, "North", "Νότος", the escaped one — plus 28 "x"
        // cells in sheet2, all sharing the SAME index), but only 7 distinct strings exist.
        $this->assertStringContainsString('count="34" uniqueCount="7"', $shared, 'every reference counted, duplicates not');
    }

    /**
     * `\x7F`-`\x9F` (DEL and the C1 controls) are valid XML 1.0 characters — the regex
     * excluding `\x01` and friends never touched them — but Excel's own stricter
     * validation of a cell's text does not accept them, reporting "Repaired Records:
     * String properties from …/sheet1.xml" on a real 502K-row export that was otherwise
     * verified byte-for-byte clean. Left out now, same as the characters XML itself
     * already refused.
     */
    public function testExcelOnlyControlCharactersAreLeftOutToo(): void
    {
        // Act — U+0001 (XML-illegal, already handled) and U+007F/U+0085/U+009F (XML-legal
        // UTF-8 code points, Excel is not) — \u{} escapes, not raw bytes: these are each
        // one Unicode code point, not a Latin-1 byte that would not be valid UTF-8 alone
        $parts = self::parts(Xlsx::write(['Sheet1' => [["a\x01b\u{7F}c\u{85}d\u{9F}e"]]]));

        // Assert
        $this->assertStringContainsString('<si><t xml:space="preserve">abcde</t></si>', $parts['xl/sharedStrings.xml']);
    }

    /**
     * `<cellStyles>` with a named "Normal" default is optional in the OOXML schema, but
     * Excel expects it in practice — its absence is a documented, common cause of the
     * same "we found a problem with some content" repair prompt a bad ZIP entry order
     * also produces, reported on a file whose entry order was already correct.
     */
    public function testStylesDeclareTheNormalDefaultCellStyle(): void
    {
        // Act
        $parts = self::parts(Xlsx::write(['Sheet1' => [['A']]]));

        // Assert
        $this->assertStringContainsString(
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>',
            $parts['xl/styles.xml']
        );
    }

    /**
     * `xl/sharedStrings.xml` is a real part, declared in `[Content_Types].xml` and
     * related to from `xl/_rels/workbook.xml.rels` — without both, a reader has no way
     * to resolve a text cell's `t="s"` index back into actual text.
     */
    public function testSharedStringsIsDeclaredAndRelated(): void
    {
        // Act
        $parts = self::parts(Xlsx::write(['Sheet1' => [['A']]]));

        // Assert
        $this->assertStringContainsString(
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>',
            $parts['[Content_Types].xml']
        );
        $this->assertStringContainsString(
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"',
            $parts['xl/_rels/workbook.xml.rels']
        );
    }

    /** A CSV export, byte-order mark and quoted commas, as one sheet. */
    public function testACsvAsASheet(): void
    {
        // Act
        $parts = self::parts(Xlsx::fromCsv("\xEF\xBB\xBFsection,row,2026-09\nSales,\"North, east\",6\n\n", 'Report'));

        // Assert
        $this->assertStringContainsString('<sheet name="Report"', $parts['xl/workbook.xml']);
        // "section" is the very first shared string (index 0): no byte-order mark made it
        // into the cell's text, which a BOM-prefixed "section" would have kept out of it.
        $this->assertStringContainsString('<si><t xml:space="preserve">section</t></si>', $parts['xl/sharedStrings.xml'], 'no byte-order mark in the first cell');
        $this->assertStringContainsString('<c r="A1" s="1" t="s"><v>0</v></c>', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<si><t xml:space="preserve">North, east</t></si>', $parts['xl/sharedStrings.xml']);
        $this->assertStringContainsString('<c r="C2"><v>6</v></c>', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringNotContainsString('<row r="3">', $parts['xl/worksheets/sheet1.xml'], 'a blank line is no row');
    }

    /** No sheets: one empty sheet, still a workbook. */
    public function testNoSheetsIsOneEmptySheet(): void
    {
        // Act
        $parts = self::parts(Xlsx::write([]));

        // Assert
        $this->assertStringContainsString('<sheet name="Sheet1"', $parts['xl/workbook.xml']);
        $this->assertStringContainsString('<sheetData></sheetData>', $parts['xl/worksheets/sheet1.xml']);
        // Assert — an empty sheet still declares a dimension and a view: the one
        // remaining structural gap (against a file Excel itself writes) found after a
        // ZIP entry order fix (FW-537) and a cellStyles fix (FW-539) each resolved a
        // "we found a problem with some content" prompt, but a third export still hit it.
        $this->assertStringContainsString('<dimension ref="A1"/>', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<sheetViews><sheetView workbookViewId="0"/></sheetViews>', $parts['xl/worksheets/sheet1.xml']);
    }

    /** A sheet's dimension covers every row and the widest row's columns, not just the first row's. */
    public function testDimensionCoversTheWidestRowAndEveryRow(): void
    {
        // Act — row 2 is wider than row 1 ("widest row" is not "first row")
        $parts = self::parts(Xlsx::write([
            'Sheet1' => [['A'], ['B', 'C', 'D']],
        ]));

        // Assert — 3 columns (A..C) wide, 2 rows tall
        $this->assertStringContainsString('<dimension ref="A1:C2"/>', $parts['xl/worksheets/sheet1.xml']);
    }

    /**
     * Past the unique-string ceiling, text goes back to inline instead of a shared strings
     * table — Excel has an unofficial, reported limit somewhere past 1,000,000 shared
     * strings; past it, a cell's own index stops resolving and Excel reports the file as
     * damaged, confirmed in production on a 2.36-million-unique-string export. The second
     * (optional) `write()` argument lets this test cross that line with three rows instead
     * of actually building a million-plus-row workbook.
     */
    public function testPastTheSharedStringsCeilingFallsBackToInlineStrings(): void
    {
        // Act — 3 unique strings, ceiling lowered to 2: the 3rd pushes it over
        $parts = self::parts(Xlsx::write(['Sheet1' => [['Alpha'], ['Beta'], ['Gamma']]], maxSharedStrings: 2));

        // Assert — no shared strings table at all: not written, not declared, not related to
        $this->assertArrayNotHasKey('xl/sharedStrings.xml', $parts);
        $this->assertStringNotContainsString('sharedStrings', $parts['[Content_Types].xml']);
        $this->assertStringNotContainsString('sharedStrings', $parts['xl/_rels/workbook.xml.rels']);
        // Assert — every text cell carries its own text inline instead
        $this->assertStringContainsString(
            '<c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">Alpha</t></is></c>',
            $parts['xl/worksheets/sheet1.xml']
        );
        $this->assertStringContainsString('<t xml:space="preserve">Gamma</t>', $parts['xl/worksheets/sheet1.xml']);
    }

    /** At or under the ceiling, nothing changes — the shared strings table stays the default. */
    public function testAtTheSharedStringsCeilingStillUsesSharedStrings(): void
    {
        // Act — exactly 2 unique strings against a ceiling of 2: not OVER it
        $parts = self::parts(Xlsx::write(['Sheet1' => [['Alpha'], ['Beta']]], maxSharedStrings: 2));

        // Assert
        $this->assertArrayHasKey('xl/sharedStrings.xml', $parts);
        $this->assertStringContainsString('t="s"', $parts['xl/worksheets/sheet1.xml']);
    }

    /** `fromCsv()` passes its own override through to `write()` the same way. */
    public function testFromCsvAlsoAcceptsTheSharedStringsCeilingOverride(): void
    {
        // Act
        $parts = self::parts(Xlsx::fromCsv("Alpha\nBeta\nGamma\n", 'Report', maxSharedStrings: 2));

        // Assert
        $this->assertArrayNotHasKey('xl/sharedStrings.xml', $parts);
        $this->assertStringContainsString('t="inlineStr"', $parts['xl/worksheets/sheet1.xml']);
    }
}
