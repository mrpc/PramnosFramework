---
date: 2026-08-16
categories:
  - Changelog
  - Added
tags:
  - document
  - seo
---

# The HTML document could not say what page it was

`setCanonical()` and `addStructuredData()`, and a `Pramnos\Html\Seo` for pages built
without a `Document`. The HTML document type had no canonical property at all — only
AMP did.

<!-- more -->

## What was there

For a canonical, one route:

```php
$doc->addHeadContent('<link rel="canonical" href="' . $url . '">');
```

Which means every application escapes the URL itself, or does not. For structured data,
the same, with a trap: the method whose name is closest, `addInlineScript()`, hardcodes
a bare `<script>` with **no `type`** and appends it to the **foot**. Following it hands
your JSON-LD to the browser as JavaScript.

Both now have a method, and the same strings are available as
`Seo::canonicalLink($url)` and `Seo::jsonLd($data)` for a page assembled from a layout
template rather than through a `Document`. One implementation, two ways in — a second
copy of the encoding rules below is how the two drift.

## The flags are the feature

```php
JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
```

**`JSON_HEX_TAG` is the one that matters.** It is the only injection this format has: a
`</script>` inside any value ends the block early, and everything after it is parsed as
markup. Structured data is assembled from record titles and operator-written
descriptions — precisely where such a string arrives from.

The other two are about the block being *readable*: without them every URL becomes
`https:\/\/…` and non-Latin text becomes `\uXXXX`. Both are valid JSON and both make
the block unreadable in view-source, which is the only place anybody ever checks it.

And when the data cannot be encoded at all — a resource handle, invalid UTF-8 —
**nothing is emitted**. `json_encode()` returns `false`, which concatenated into a
script tag is an empty one. A page without structured data is a smaller problem than a
page with a broken script block in its head.

## Two decisions worth stating

**Blocks are not merged.** Each `addStructuredData()` call emits its own script. A
station page carries the station and its breadcrumb trail; merging two `@type`s into one
object produces something no validator accepts.

**AMP keeps exactly one canonical.** It computes one when none is set and has emitted it
since long before this, so the shared helper deliberately does not add a second. Two
`<link rel="canonical">` on one page is undefined behaviour to a crawler — worse than
having none, because it looks handled.

## What the framework will not do for you

Omit a key you have no value for rather than emitting `"genre": ""`. An empty string is
a claim that the field is blank, which is a different statement from not making the
claim, and consumers treat it as one. This cannot be automated: the framework cannot
tell a deliberate empty string from a lookup that failed, and guessing would be worse
than either.

## Added

- `Document::setCanonical()` and `Document::addStructuredData()`, rendered by the HTML
  document type and — structured data only — by AMP.
- `Pramnos\Html\Seo::jsonLd()` and `Seo::canonicalLink()`, for pages built without a
  `Document`.

## Documentation

- [Document & Output guide](../../Pramnos_Document_Output_Guide.md) — the *Canonical
  links* and *Schema.org structured data* sections rewritten against the new methods,
  with the encoding flags in a table and a note on why `addInlineScript()` is the wrong
  neighbour to reach for.
