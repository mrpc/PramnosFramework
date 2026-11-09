<?php

declare(strict_types=1);

namespace Pramnos\Export;

/**
 * A spreadsheet, written with `ext-zip` and a little XML — no spreadsheet library.
 *
 * ```php
 * $bytes = Xlsx::write(['Sales' => [['Region', 'Orders'], ['North', 12], ['South', 7.5]]]);
 * $bytes = Xlsx::fromCsv($csv, 'Report');   // what a CSV export already writes, as a sheet
 * ```
 *
 * Each sheet is rows of cells. A number — an int, a float, or a string that reads as one in the
 * plain form `-12.5` — is written as a number, so it adds up in the spreadsheet; everything else
 * as text. The first row is bold. Text is written inline, not shared: small files, one pass.
 */
final class Xlsx
{
    /** A sheet's name: what Excel allows, up to 31 characters. */
    private const NAME = '/[\[\]:*?\/\\\\]/';

    /**
     * The workbook's bytes.
     *
     * @param array<string, list<list<scalar|null>>> $sheets Name => rows
     */
    public static function write(array $sheets): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Writing a spreadsheet needs the zip extension (ext-zip).');
        }
        if ($sheets === []) {
            $sheets = ['Sheet1' => []];
        }
        $file = tempnam(sys_get_temp_dir(), 'pf-xlsx');
        $zip  = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $names = [];
        $i     = 0;
        foreach ($sheets as $name => $rows) {
            $i++;
            $names[$i] = mb_substr(trim((string) preg_replace(self::NAME, ' ', (string) $name)) ?: 'Sheet' . $i, 0, 31);
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', self::sheet($rows));
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn (int $n): string => '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names)))
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(static fn (int $n, string $label): string => '<sheet name="' . self::e($label) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>', array_keys($names), $names))
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn (int $n): string => '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>', array_keys($names)))
            . '<Relationship Id="rId' . (count($names) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Two styles: plain, and bold for the first row.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** A CSV — as an export already writes it, a byte-order mark or none — as a one-sheet workbook. */
    public static function fromCsv(string $csv, string $sheet = 'Sheet1'): string
    {
        $csv    = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($stream);

        return self::write([$sheet => $rows]);
    }

    /** One sheet's XML. @param list<list<scalar|null>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '';
        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values((array) $row) as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref   = self::column($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                $xml  .= is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value) === 1)
                    ? '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>'
                    : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::e((string) $value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>';
    }

    /** A column's letters: 0 is A, 26 is AA. */
    private static function column(int $index): string
    {
        $letters = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26) . $letters;
        }

        return $letters;
    }

    /** Text as XML holds it, the characters XML refuses left out. */
    private static function e(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
