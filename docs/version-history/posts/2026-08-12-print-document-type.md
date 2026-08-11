---
date: 2026-08-12
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - document
  - print
  - pdf
---

# `print` replaces the `pdf` document type, which had not worked for years

`getDocument('pdf')` rendered through TCPDF. TCPDF is not a dependency of this
framework, so the type raised a fatal error on its first line — every caller of
it was already broken.

<!-- more -->

## What was there

`Pdf::render()` called `new TCPDF(...)` with no leading backslash, which inside
`Pramnos\Document\DocumentTypes` resolves to a class in that namespace. There is
no such class and no package that provides one. It also read
`$this->printpaper`, a property no class declares. The type could not have run.

What it offered when it did work, years ago, is worse than what a browser does
today: an HTML subset, almost no CSS, and a font matrix to maintain. Every
current browser prints real CSS with real fonts and real page breaks, and offers
**Save as PDF** in its own dialog.

## Added

**`Pramnos\Document\DocumentTypes\PrintDocument`**, as document type `print`. It
is an `Html` document — theme, meta tags, `addCss()`, `enqueueStyle()`,
`enqueueScript()` all work as they do on any HTML page — with three things
attached:

- an `@page` rule built from `paperSize`, `orientation` and `margin`;
- a small print stylesheet: no backgrounds suppressed by the browser's
  ink-saving, headings that do not sit alone at the foot of a page, table rows
  that do not split, `.no-print` hidden, `.page-break` honoured, and a screen
  preview so the author sees roughly what will come out;
- `window.print()` on `load` — after the images and web fonts have arrived,
  rather than before.

```php
$doc = \Pramnos\Framework\Factory::getDocument('print');
$doc->title      = 'Invoice 2026-0042';
$doc->paperSize  = 'A4';
$doc->margin     = '15mm';
$doc->addCss('/css/invoice.css');
$doc->addPrintCss('.totals { break-inside: avoid; }');
$doc->noPrint('.site-nav');
```

Every part is optional: `autoPrint = false` for a page the reader should check
first, `baseStyles = false` for a document with its own complete print CSS,
`closeAfterPrint = true` for a printable view opened in its own tab.

Being an HTML document is the point — the old type could not take a stylesheet
at all, so a printable page had to be built twice.

## Removed

`Pramnos\Document\DocumentTypes\Pdf`, and its tests, which passed only against a
stub of the library that was never installed.

`getDocument('pdf')` still answers, with the printable document, so existing
links produce a page a user can save as a PDF instead of a stack trace. Use
`'print'` in new code.

## Documentation

[Document output guide](../../Pramnos_Document_Output_Guide.md) — the *Printable
Document Type* section, with the full property list.
