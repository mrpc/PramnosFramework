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
 * as text, through a shared strings table (`xl/sharedStrings.xml`) rather than inline — Excel's
 * own writer does the same, and a real multi-thousand-row export with inline strings (schema-valid,
 * every outside tool happy with it) still made Excel itself report "we found a problem with some
 * content" on nothing a small file ever reproduced. The first row is bold.
 *
 * Past {@see MAX_SHARED_STRINGS} unique strings, text goes back to inline instead: Excel has an
 * unofficial, reported ceiling somewhere past 1,000,000 shared strings — though diffing a real
 * export against Excel's OWN resave of the same data (after a repair prompt at that scale) later
 * showed Excel's own writer uses a shared strings table past that count without trouble, so this
 * remains a hedge rather than a confirmed fix. At that scale reuse is usually low anyway (a
 * handful of unique values rarely climb into the millions), so the table buys little and inlining
 * costs little extra either way.
 *
 * Also writes `xl/theme/theme1.xml` and `docProps/core.xml`/`docProps/app.xml` — all three
 * optional per the OOXML schema, and nothing outside real Excel minded their absence, but they
 * were the remaining difference found by diffing a real export against Excel's own resave of it.
 */
final class Xlsx
{
    /** A sheet's name: what Excel allows, up to 31 characters. */
    private const NAME = '/[\[\]:*?\/\\\\]/';

    /**
     * Comfortably under the unofficial ceiling a Microsoft Q&A thread reports
     * (learn.microsoft.com/en-us/answers/questions/661619) — past it, a shared string's own
     * index stops resolving and Excel reports the file as damaged. Not a number Microsoft
     * documents as a real limit (only row/column counts are), so the margin is deliberate.
     */
    private const MAX_SHARED_STRINGS = 900000;

    /**
     * The workbook's bytes.
     *
     * @param array<string, list<list<scalar|null>>> $sheets Name => rows
     * @param int $maxSharedStrings {@see MAX_SHARED_STRINGS} — overridable for a caller that
     *            knows its own data (or a test, to exercise the inline fallback without
     *            actually building a million-plus-row workbook to cross the real default)
     */
    public static function write(array $sheets, int $maxSharedStrings = self::MAX_SHARED_STRINGS): string
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
        // Sheet names first, with no write yet — [Content_Types].xml needs every sheet's
        // number before it names them, and the OPC spec requires it to be the ZIP's FIRST
        // entry (readers that enforce this, Excel among them, reject the file otherwise,
        // even though every XML part inside is well-formed on its own).
        $names = [];
        $i     = 0;
        foreach ($sheets as $name => $rows) {
            $i++;
            $names[$i] = mb_substr(trim((string) preg_replace(self::NAME, ' ', (string) $name)) ?: 'Sheet' . $i, 0, 31);
        }
        // Collected across every sheet, in one pass — a shared strings table is a single
        // workbook-level part, so every sheet's text has to be gathered before any of
        // them is written. $strings keeps text => index in first-seen order; the same
        // order <si> entries are written in below.
        $strings    = [];
        $totalRefs  = 0;
        $sheetsXml  = [];
        foreach ($sheets as $rows) {
            $sheetsXml[] = self::sheet($rows, $strings, $totalRefs, useShared: true);
        }
        // Past the ceiling, rebuild with inline strings instead — a second pass over the
        // same rows, paid only in the rare case this limit is actually hit. Every other
        // export (the overwhelming majority) never pays it: one pass, exactly as before.
        $useShared = count($strings) <= $maxSharedStrings;
        if (!$useShared) {
            $strings   = [];
            $totalRefs = 0;
            $sheetsXml = [];
            foreach ($sheets as $rows) {
                $sheetsXml[] = self::sheet($rows, $strings, $totalRefs, useShared: false);
            }
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . ($useShared ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' : '')
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . implode('', array_map(static fn (int $n): string => '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names)))
            . '</Types>');
        $i = 0;
        foreach ($sheetsXml as $xml) {
            $i++;
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $xml);
        }
        if ($useShared) {
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $totalRefs . '" uniqueCount="' . count($strings) . '">'
                . implode('', array_map(static fn (string $s): string => '<si><t xml:space="preserve">' . self::e($s) . '</t></si>', array_keys($strings)))
                . '</sst>');
        }
        // docProps are OPC-level parts, related from the package's own root _rels/.rels —
        // not from xl/_rels/workbook.xml.rels, which only relates parts xl/workbook.xml
        // itself uses.
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(static fn (int $n, string $label): string => '<sheet name="' . self::e($label) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>', array_keys($names), $names))
            . '</sheets></workbook>');
        // rIds: every sheet first, then styles, then (only if used) sharedStrings, then
        // theme last — order does not matter to a reader, only that each Id is unique and
        // every Target above actually exists as a part.
        $themeRid = count($names) + ($useShared ? 3 : 2);
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn (int $n): string => '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>', array_keys($names)))
            . '<Relationship Id="rId' . (count($names) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . ($useShared ? '<Relationship Id="rId' . (count($names) + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' : '')
            . '<Relationship Id="rId' . $themeRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
            . '</Relationships>');
        // Two styles: plain, and bold for the first row.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            // Optional in the OOXML schema, but Excel expects it in practice: without a
            // named "Normal" default style pointing at cellStyleXfs[0], it reports the
            // same "we found a problem with some content" prompt FW-537 already fixed
            // the ZIP entry order for — a second, unrelated cause of that same symptom.
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        // The standard Office default theme — color/font/format scheme, all schema-required
        // children present. A styleSheet with no theme-indexed color ("theme5", say) does
        // not NEED this per the OOXML schema, and no outside tool minded its absence — but
        // it is the other real difference against a file Excel itself writes (alongside
        // docProps below), found by diffing one after Excel repaired and resaved it.
        $zip->addFromString('xl/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">'
            . '<a:themeElements>'
            . '<a:clrScheme name="Office">'
            . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
            . '<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="44546A"/></a:dk2>'
            . '<a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="4472C4"/></a:accent1>'
            . '<a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3>'
            . '<a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5>'
            . '<a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink>'
            . '<a:folHlink><a:srgbClr val="954F72"/></a:folHlink>'
            . '</a:clrScheme>'
            . '<a:fontScheme name="Office">'
            . '<a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme>'
            . '<a:fmtScheme name="Office">'
            . '<a:fillStyleLst>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '</a:fillStyleLst>'
            . '<a:lnStyleLst>'
            . '<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '</a:lnStyleLst>'
            . '<a:effectStyleLst>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle>'
            . '</a:effectStyleLst>'
            . '<a:bgFillStyleLst>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '</a:bgFillStyleLst>'
            . '</a:fmtScheme>'
            . '</a:themeElements>'
            . '</a:theme>');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Microsoft Excel</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant">'
            . '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>' . count($names) . '</vt:i4></vt:variant>'
            . '</vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($names) . '" baseType="lpstr">'
            . implode('', array_map(static fn (string $label): string => '<vt:lpstr>' . self::e($label) . '</vt:lpstr>', $names))
            . '</vt:vector></TitlesOfParts>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>1.0</AppVersion>'
            . '</Properties>');
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** A CSV — as an export already writes it, a byte-order mark or none — as a one-sheet workbook. */
    public static function fromCsv(string $csv, string $sheet = 'Sheet1', int $maxSharedStrings = self::MAX_SHARED_STRINGS): string
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

        return self::write([$sheet => $rows], $maxSharedStrings);
    }

    /**
     * One sheet's XML. With `$useShared`, a text cell references `$strings` (text => index,
     * shared across every sheet in the workbook) instead of carrying its own text, and
     * `$totalRefs` counts every such reference — both feed `xl/sharedStrings.xml`'s
     * `count`/`uniqueCount`. Without it, every text cell carries its own text inline
     * (`t="inlineStr"`) and `$strings`/`$totalRefs` are not touched — the fallback past
     * {@see MAX_SHARED_STRINGS} unique strings.
     *
     * @param list<list<scalar|null>> $rows
     * @param array<string, int> $strings
     */
    private static function sheet(array $rows, array &$strings, int &$totalRefs, bool $useShared): string
    {
        $xml     = '';
        $maxCols = 0;
        foreach (array_values($rows) as $r => $row) {
            $row     = array_values((array) $row);
            $maxCols = max($maxCols, count($row));
            $xml    .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref   = self::column($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value) === 1)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                    continue;
                }
                $text = (string) $value;
                if ($useShared) {
                    $totalRefs++;
                    $strings[$text] ??= count($strings);
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="s"><v>' . $strings[$text] . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::e($text) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        // All optional in the OOXML schema, and no parser outside real Excel seems to
        // mind their absence — but a 2867x62 export with everything else verified clean
        // (entry order, cellStyles, well-formed XML throughout) still triggered Excel's
        // repair prompt without them, and they are structural differences left against a
        // file Excel itself writes. Cheap to add, so added rather than chased further on
        // guesswork. sheetFormatPr and pageMargins were found the same way, later, on a
        // different, much larger export that still failed with dimension/sheetViews
        // already in place: diffing it against Excel's own resave of it.
        $dimension = $rows === []
            ? 'A1'
            : 'A1:' . self::column(max($maxCols - 1, 0)) . count($rows);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<sheetData>' . $xml . '</sheetData>'
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '</worksheet>';
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

    /**
     * Text as XML holds it, the characters XML refuses left out — and also `\x7F`-`\x9F`
     * (DEL and the C1 controls), which XML 1.0 itself allows but Excel's own stricter
     * validation of a cell's text does not: a real 502K-row export, otherwise verified
     * clean down to well-formed XML and a byte-for-byte diff against Excel's own
     * "repaired" copy, still had Excel report "Repaired Records: String properties from
     * …/sheet1.xml" — this exact, documented class of Excel-only character restriction.
     */
    private static function e(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{7E}\x{A0}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
