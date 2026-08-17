---
date: 2026-08-17
categories: [Changelog]
---

# A correct header, a correct footer, and nothing between them

Two reports from a project adopting `app/themes/` for the first time. The guide was good on
what a theme *is* — `theme.html.php`, `[MODULE]`, the partials, the override hierarchy — and
silent on **how a page's content reaches the document**. Neither of these is guessable, and
both produce a page that looks like the theme is working and the content is missing.

<!-- more -->

## Fixed

### The two renderers read the content from two different places

```php
// Document::render()
$content .= $this->content;          // the public property

// DocumentTypes\Html::render() — the one actually serving a page
$content .= self::_getContent();     // a static buffer
```

Setting `$document->content` is the obvious move: the property is public, it is what the
parent class reads, and it looks exactly like the seam. On an HTML page it produced a
correct header, a correct footer, and nothing between them — with no error anywhere.

The report framed this as `Html` disagreeing with its parent. Reading the other five types
first turned it round: `Html`, `Amp`, `Json`, `Png` and `Raw` **all** read the buffer, and
only `Document::render()` read the property — which in practice serves `Rss`. Fixing `Html`
alone, as suggested, would have left the identical trap in four more types.

So the reconciliation is one shared resolver, `Document::bodyContent()`, used by all six:
the buffer when it holds anything, the property otherwise. That direction is what makes it a
repair rather than a behaviour change — every page that renders today renders from the
buffer, so the only output that changes is output that was blank.

Worth stating since the report named `_setContent()` as "a static method with no mention in
the guide": the instance API exists too. `$document->setContent()`, `addContent()` and
`getContent()` are ordinary public methods, and they were equally undocumented. They are
what the framework itself uses, and what the guide now points at.

### A theme object that had not read its theme

```php
public function loadtheme($theme = 'default', $path = '', $application = null)
{
    $themeobject = Theme::getTheme($theme, $path, false, $application);
    //                                            ^ $load
}
```

With `$load = false`, `Theme::loadtheme()` never ran, `$body` stayed empty, and
`gethead()` / `getfoot()` split an empty string. `Html::render()` calls `loadTheme()`
itself, which is exactly why this is invisible from inside the framework and obvious from
outside it: the framework's own path works, and an application that assigns `themeObject`
and renders through any other route gets an object that reports no error and produces the
bare default.

The report asked for a line in the guide saying the body is loaded lazily. Making that
sentence **true** was cheaper than writing it: `gethead()`, `getfoot()` and `getheader()`
now read the theme file when nothing has read it yet.

Two boundaries on that, both of which an existing test or a plausible caller cares about:

- the condition is *nothing read yet* — both `contents` and `body` — not merely an empty
  `contents`. A caller that assigns `body` itself has supplied the very thing the load would
  produce, and reading over it would discard a deliberate value. An existing test in this
  suite does exactly that, and failed immediately against the narrower condition.
- an explicit `loadtheme()` still re-reads. The file it picks depends on the content type,
  so setting a content type and reloading is how a theme switches templates; memoising
  would silently ignore that.

## Documentation

The Theme Guide gains **How a page's content reaches `[MODULE]`** and **When the theme body
is loaded** — the `setContent()` / `addContent()` API, the fact that the buffer is `static`
and therefore shared by every document in the process (which matters to a long-running
worker and to a forgetful test), the property that now works and why it used to be a trap,
and the two boundaries on the lazy load.

Three `use_cases:` entries were added with the symptom in them, not the mechanism —
*"Diagnosing a theme that renders a header and footer with an empty page"* — because that is
what somebody hitting this actually types.
