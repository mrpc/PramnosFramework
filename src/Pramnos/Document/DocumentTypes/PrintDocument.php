<?php

namespace Pramnos\Document\DocumentTypes;

/**
 * A page built to be printed — and, through the browser's own print dialog,
 * saved as a PDF.
 *
 * This replaces the `pdf` document type, which rendered through TCPDF. That
 * library is not a dependency of this framework and has not been for a long
 * time, so the type raised a fatal error on the first line that used it. What it
 * offered when it did work — an HTML subset, no CSS to speak of, a font matrix
 * to maintain — is worse than what every browser now does natively: real CSS,
 * real fonts, real page breaks, and "Save as PDF" in the print dialog.
 *
 * So this type produces a normal HTML document. It is an {@see Html} document in
 * every respect — theme, meta tags, `enqueueStyle()`, `enqueueScript()`,
 * `addCss()` — with three things added:
 *
 *   1. a small print stylesheet: an `@page` rule from the paper size and
 *      margins, screen styling that previews the sheet, and the usual print
 *      hygiene (no backgrounds, sensible page breaks, `.no-print` hidden);
 *   2. `window.print()` on load, so the dialog opens by itself;
 *   3. properties to control all of it.
 *
 * ```php
 * $document = \Pramnos\Document\Document::getInstance('print');
 * $document->title      = 'Invoice 2026-0042';
 * $document->paperSize  = 'A4';
 * $document->margin     = '15mm';
 * $document->addCss('/css/invoice.css');       // the real layout
 * $document->addPrintCss('.totals { break-inside: avoid; }');
 * $document->noPrint('.site-nav');
 * ```
 *
 * Turning the automatic dialog off is one property, for a page the user is
 * meant to read before printing:
 *
 * ```php
 * $document->autoPrint = false;
 * ```
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class PrintDocument extends Html
{
    /**
     * Whether the print dialog opens by itself once the page has loaded.
     *
     * @var bool
     */
    public $autoPrint = true;

    /**
     * Whether to close the window once printing finishes.
     *
     * Only works for a window the script itself opened, which is the case this
     * exists for: a "Print" button that opens the printable view in a new tab.
     * Browsers ignore it otherwise, which is why it is off by default.
     *
     * @var bool
     */
    public $closeAfterPrint = false;

    /**
     * Paper size for the `@page` rule — 'A4', 'Letter', 'A5', or any CSS page
     * size, including explicit dimensions such as '210mm 297mm'.
     *
     * @var string
     */
    public $paperSize = 'A4';

    /**
     * 'portrait' or 'landscape'. Anything else is passed through untouched, so
     * a caller can leave the orientation to the paper size.
     *
     * @var string
     */
    public $orientation = 'portrait';

    /**
     * Page margin for the `@page` rule — any CSS margin value.
     *
     * @var string
     */
    public $margin = '12mm';

    /**
     * Whether to emit the built-in print stylesheet at all.
     *
     * A document with its own complete print CSS sets this to false and gets a
     * plain HTML page with the print call attached.
     *
     * @var bool
     */
    public $baseStyles = true;

    /**
     * Extra CSS, appended after the built-in stylesheet, inside the same
     * `<style>` block. Added to by {@see addPrintCss()}.
     *
     * @var string
     */
    public $printStyles = '';

    /**
     * Selectors hidden when printing, on top of `.no-print`. Added to by
     * {@see noPrint()}.
     *
     * @var array<int, string>
     */
    public $hideOnPrint = [];

    /**
     * Add CSS to the document's print stylesheet.
     *
     * For the small, page-specific rules that are not worth a file. Anything
     * larger belongs in a stylesheet added with `addCss()` or
     * `enqueueStyle()` — both work here exactly as they do on an HTML document.
     *
     * @param  string $css
     * @return $this
     */
    public function addPrintCss($css)
    {
        $this->printStyles .= "\n" . $css;

        return $this;
    }

    /**
     * Hide an element when printing.
     *
     * `.no-print` is hidden already; this is for elements whose markup you do
     * not control — a theme's navigation, a cookie banner, a debug toolbar.
     *
     * @param  string $selector Any CSS selector
     * @return $this
     */
    public function noPrint($selector)
    {
        $selector = trim((string) $selector);

        if ($selector !== '' && !in_array($selector, $this->hideOnPrint, true)) {
            $this->hideOnPrint[] = $selector;
        }

        return $this;
    }

    /**
     * Render the page, with the print stylesheet and the print call attached.
     *
     * Both are inline rather than files: they depend on properties that are only
     * known at render time, and the parent's CSP post-processing gives every
     * inline `<style>` and `<script>` the request's nonce, so being inline costs
     * nothing in policy terms.
     *
     * @return string
     */
    public function render()
    {
        // A printable page has no progressive-enhancement styling to switch on, and
        // this type's contract is that it emits no <script> when it has nothing to
        // run. Inheriting the parent's no-js flip would break that for every print
        // view, to set a class no print stylesheet reads.
        $this->emitNoJsFlip = false;

        $this->header .= $this->buildStyleBlock();
        $this->foot   .= $this->buildPrintScript();

        return parent::render();
    }

    /**
     * The `<style>` block: the built-in sheet, then whatever was added.
     *
     * @return string
     */
    protected function buildStyleBlock()
    {
        $css = $this->baseStyles ? $this->baseStylesheet() : '';
        $css .= $this->printStyles;

        if (trim($css) === '') {
            return '';
        }

        return "\n        <style>" . $css . "\n        </style>";
    }

    /**
     * The built-in print stylesheet.
     *
     * Deliberately small. It sets the page geometry, previews the sheet on
     * screen so that what the author sees resembles what comes out, and applies
     * the handful of print rules that every printable page needs and that no
     * screen stylesheet provides: no backgrounds, no orphaned headings, no table
     * row split across a page, and nothing hidden that should not be.
     *
     * @return string
     */
    protected function baseStylesheet()
    {
        $size = trim($this->paperSize);
        if (in_array(strtolower(trim($this->orientation)), ['portrait', 'landscape'], true)) {
            $size .= ' ' . strtolower(trim($this->orientation));
        }

        $hidden = $this->hiddenSelectors();

        return "
@page { size: " . $size . "; margin: " . $this->margin . "; }

/* On screen: preview the sheet, so the author sees roughly what prints. */
@media screen {
    body {
        background: #f1f1f1;
        margin: 0;
        padding: 24px 0;
    }
    body > * {
        max-width: 210mm;
        margin-left: auto;
        margin-right: auto;
    }
}

@media print {
    /* Print the colours that were asked for, rather than the browser's
       ink-saving reinterpretation of them. */
    * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body {
        background: #fff;
        margin: 0;
        padding: 0;
        font-size: 11pt;
        line-height: 1.4;
    }
    " . $hidden . " { display: none !important; }

    /* A heading at the foot of a page, and a table row split in half, are the
       two things that make a printout look broken. */
    h1, h2, h3, h4, h5, h6 { break-after: avoid; page-break-after: avoid; }
    tr, img, figure, blockquote, pre { break-inside: avoid; page-break-inside: avoid; }
    table { border-collapse: collapse; width: 100%; }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }

    /* A link is useless on paper unless it says where it goes — but only for
       real destinations, not for in-page anchors or javascript: handlers. */
    a[href^=\"http\"]::after { content: \" (\" attr(href) \")\"; font-size: 85%; word-break: break-all; }

    .page-break { break-before: page; page-break-before: always; }
}
";
    }

    /**
     * The selector list hidden when printing.
     *
     * @return string
     */
    protected function hiddenSelectors()
    {
        $selectors = array_merge(['.no-print'], $this->hideOnPrint);

        return implode(', ', $selectors);
    }

    /**
     * The script that opens the print dialog.
     *
     * `afterprint` rather than a timer: the dialog is modal and the user may sit
     * in it for a minute, and closing the window out from under an open dialog
     * is how a print job gets cancelled by the code that asked for it.
     *
     * @return string
     */
    protected function buildPrintScript()
    {
        if (!$this->autoPrint && !$this->closeAfterPrint) {
            return '';
        }

        $script = "\n        <script>\n            (function () {\n";

        if ($this->closeAfterPrint) {
            $script .= "                window.addEventListener('afterprint', function () {\n"
                . "                    window.close();\n"
                . "                });\n";
        }

        if ($this->autoPrint) {
            // window.load rather than DOMContentLoaded: a dialog that opens
            // before the images and fonts have arrived prints the page without
            // them.
            $script .= "                window.addEventListener('load', function () {\n"
                . "                    window.setTimeout(function () { window.print(); }, 0);\n"
                . "                });\n";
        }

        $script .= "            })();\n        </script>";

        return $script;
    }
}
