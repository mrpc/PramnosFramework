---
date: 2026-08-15
categories:
  - Changelog
  - Fixed
  - Documentation
tags:
  - document
  - security
  - seo
---

# One quote in a station name

Every document type built the `<head>` by concatenation, so a `"` in a title ended the
attribute and everything after it was markup. And the guide section describing that head
documented three methods that do not exist.

<!-- more -->

## The head was never escaped

```php
'<meta name="description" content="' . $this->description . '" />'
```

That line, and fifteen like it, in `DocumentTypes/Html.php` and again in `Amp.php`. Title,
description, all six `og_*` slots, both meta-tag loops — **names as well as values** — and
the AMP `canonical`. All interpolated raw.

It went unnoticed for a reason worth naming: for most of this framework's life the values
were developer-written constants. A page whose title is `'Dashboard'` is safe no matter how
it is concatenated. The moment those values start coming from a database — a record's name,
operator-written copy, anything a user submitted — the same line is an injection point, and
it is in the part of the page nobody reads.

Fixed with one shared helper on `Document` rather than sixteen inline calls, so the two
renderers cannot drift:

```php
protected function escapeHeadValue($value)
```

Three decisions inside it, each of which would be a bug the other way:

- **`ENT_QUOTES`** — these are attribute values, and single quotes matter as much as double.
- **`ENT_SUBSTITUTE`** — without it, `htmlspecialchars()` returns `''` for the *whole* value
  when the input is not valid UTF-8. One bad byte in one row would silently erase the entire
  page title. A visible replacement character is a much cheaper failure than a blank
  `<title>` nobody can trace.
- **`double_encode: false`** — an application that already escapes its own metadata is doing
  the right thing, and turning its `&amp;` into `&amp;amp;` would punish it for that.

What is **not** escaped, deliberately: `headContent`, `addHeadTagContent()`, `extraHtmlTag`,
`extraBodyTag` and `header`. Those exist to carry markup. Escaping them would break every
application using them as documented, and the breakage would present as `<link>` tags showing
up as visible text — obvious, but only after a deploy.

The tests are split the same three ways: values that must be escaped, values that must not,
and already-escaped input that must survive untouched. Six of the ten fail without the fix;
the other four are the guards that would catch over-applying it.

## And the guide described an API that does not exist

The **Meta Tags and SEO** section told you to call `addMetaName()`, `addMeta()` and
`addScriptDeclaration()`. None of the three exists. None ever did. Code copied out of that
section failed with `Call to undefined method`.

The real API is one method with a flag:

```php
$doc->addMetaTag('robots', 'index, follow', true);   // <meta name="…">
$doc->addMetaTag('og:article:author', 'Author');     // <meta property="…">
```

The structured-data example was worse than wrong. It pointed at `addScriptDeclaration()` with
an `application/ld+json` argument, implying the framework knows about JSON-LD. It does not —
and the method that *sounds* closest, `addInlineScript()`, hardcodes a bare `<script>` with
no `type` and appends it to the **foot**. Following the shape of the old example with the
nearest real method would have handed your JSON-LD to the browser as JavaScript.

The section now shows `addHeadContent()` with the encoding flags spelled out and the reason
for each — `JSON_HEX_TAG` in particular, because a `</script>` inside any value is the one
injection JSON-LD has.

## The two are the same failure

A section documenting methods that were never implemented is the same defect as a head that
was never escaped: both are things nobody exercised, in an area that only became load-bearing
when pages started being assembled from data. The escaping bug needed code; this one needed
somebody to run the example.

## Fixed

- `Document\DocumentTypes\Html` and `Amp` escape every value they render into the `<head>`:
  title, description, all `og_*`, meta-tag names and values, AMP `canonical`, `lang` and
  `charset`.
- A `null`, array or object in one of those slots now renders as empty instead of raising a
  deprecation or a `TypeError` mid-`<head>`.

## Documentation

- [Document & Output guide](../../Pramnos_Document_Output_Guide.md) — the **Meta Tags and
  SEO** section rewritten against the real API, plus a new subsection stating exactly which
  values the document escapes for you and which remain yours to escape.
