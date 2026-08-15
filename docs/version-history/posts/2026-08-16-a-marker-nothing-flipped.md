---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
  - Documentation
tags:
  - document
  - upgrade
---

# A marker nothing flipped

A consumer migrating off the legacy document type found that `$modernizr` — default
`true`, injected on every page — has no counterpart in the modern one. Checking it
turned up something they had not reported: the modern document still emits the marker
that script existed to change.

<!-- more -->

## The report

Legacy `pramnos_document_html` carried `public $modernizr = true;` and put

```html
<script async src="<?= sURL ?>media/js/modernizr.min.js"></script>
```

into the `<head>` of every page. `Pramnos\Document\DocumentTypes\Html` has no such
property and never emits the tag. Zero occurrences of `modernizr` anywhere in `src/`.
Same for `$reset` and its `reset.css`.

**Neither is being restored**, and the reason is not indifference: the framework does
not ship either file. Reinstating a default-on injection of `media/js/modernizr.min.js`
would give every upgraded application a `404` in its `<head>` to replace a feature most
of them were not using. The Upgrade guide now says so, with the one-liner to add it
back.

## The part that was not in the report

`Html::render()` still emitted `<head class="no-js" …>`.

`no-js` exists for exactly one purpose: a script replaces it with `js` so stylesheets
can tell whether JavaScript ran. Remove the script and the marker becomes a permanent
claim that JavaScript is off — so `.no-js .thing { display: none }`, which is the
standard progressive-enhancement pattern, hides that thing **forever**, in a browser
with JavaScript working perfectly.

Removing a feature and leaving its footprint is worse than removing it cleanly, because
the footprint reads as deliberate. Anybody auditing the markup sees `no-js` and concludes
the mechanism is present.

And it was on the wrong element besides. **`<head class="no-js">` cannot be matched by
any stylesheet** — the head is not rendered, so `head.no-js` selects nothing. Modernizr
puts its classes on `<html>`, which is what makes the pattern work at all. So the marker
had never done anything, even while the script was being injected.

## Fixed

```html
<html class="no-js" lang="en">
<head>
<script>document.documentElement.className=document.documentElement.className.replace(/\bno-js\b/,'js');</script>
```

Two lines, no external file, no dependency. `.no-js` and `.js` now behave the way every
guide on progressive enhancement says they do. An application whose stylesheets were
written against the legacy behaviour starts working rather than stopping.

The test asserts **both halves** — the class on an element CSS can reach, and something
that flips it — because either alone is worse than neither. A marker nothing changes is
a lie about the browser; a flip script with nothing to flip is dead code.

## The general shape

This is the third variant this week of *the evidence says the feature is there*: a guide
naming methods that never existed, a usage example describing a workaround that could
not work, and now a class attribute implying a mechanism that had been deleted. All
three were found by running something rather than reading it.

## Fixed

- `no-js` moved from `<head>` (where no stylesheet can match it) to `<html>`, with an
  inline script that turns it into `js`.

## Documentation

- [Upgrade guide](../../Pramnos_Upgrade_Guide.md) — *Two features the legacy document
  had, and the modern one does not*: why `$modernizr` and `$reset` are not coming back,
  how to add either yourself, and what changed about `no-js`.
