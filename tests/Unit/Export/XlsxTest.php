<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Export\Xlsx;

/**
 * The spreadsheet writer: a workbook of the parts a spreadsheet needs, each sheet named as Excel
 * allows, numbers written as numbers (so they add up) and the rest as text, the first row bold,
 * columns past Z lettered AA…, characters XML refuses left out, and a CSV — with its byte-order
 * mark — read in as a sheet.
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

        // Assert
        $this->assertSame(['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml'], array_keys($parts));
        $this->assertStringContainsString('<sheet name="Sales  2026 09" sheetId="1"', $parts['xl/workbook.xml'], 'a name Excel allows');
        $this->assertStringContainsString('<sheet name="Sheet2" sheetId="2"', $parts['xl/workbook.xml'], 'no name: one made');
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">Region</t></is></c>', $sheet, 'the first row bold');
        $this->assertStringContainsString('<c r="B2"><v>12</v></c><c r="C2"><v>0.5</v></c>', $sheet, 'numbers as numbers');
        $this->assertStringContainsString('<c r="B3"><v>7</v></c>', $sheet, 'a string that reads as a number');
        $this->assertStringContainsString('<t xml:space="preserve">a &amp; &lt;b&gt;</t>', $sheet, 'escaped, and a control character left out');
        $this->assertStringContainsString('<c r="A4"><v>-3.25</v></c></row>', $sheet, 'empty cells left out');
        $this->assertStringContainsString('<c r="AB1"', $parts['xl/worksheets/sheet2.xml'], 'the 28th column');
    }

    /** A CSV export, byte-order mark and quoted commas, as one sheet. */
    public function testACsvAsASheet(): void
    {
        // Act
        $parts = self::parts(Xlsx::fromCsv("\xEF\xBB\xBFsection,row,2026-09\nSales,\"North, east\",6\n\n", 'Report'));

        // Assert
        $this->assertStringContainsString('<sheet name="Report"', $parts['xl/workbook.xml']);
        $this->assertStringContainsString('<t xml:space="preserve">section</t>', $parts['xl/worksheets/sheet1.xml'], 'no byte-order mark in the first cell');
        $this->assertStringContainsString('<t xml:space="preserve">North, east</t></is></c><c r="C2"><v>6</v></c>', $parts['xl/worksheets/sheet1.xml']);
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
    }
}
