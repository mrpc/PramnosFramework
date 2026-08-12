---
date: 2026-08-12
categories:
  - Changelog
  - Changed
tags:
  - debugbar
  - debugging
  - csp
---

# The server-rendered toolbar now uses the one renderer too

`DebugBarAsset` became the single toolbar source, and the SPA panel started
drawing every collector. The other half stayed where it was: `DebugBar::render()`
still built its own HTML, CSS and inline JavaScript, so a server-rendered page
had neither the new tabs nor its own request in the list it was showing.

<!-- more -->

## What changed

`DebugBar::render()` emits two things and no markup of its own:

```html
<div id="pramnos-debug-data" hidden>{"time":61.2,"queries":{…},"request_method":"GET",…}</div>
<script nonce="…">/* DebugBarAsset::source() */</script>
```

The island is `ApiDebugPayload::build()` — the same payload an API response
carries — plus `request_method`, `request_path` and `status_code`, so the page's
own request can sit in the requests list beside the calls that follow it, marked
`(page)`. The renderer boots from it and then wraps `fetch` and
`XMLHttpRequest`, as it always did for a page that has one.

A `<div hidden>` rather than a `<script type="application/json">`: a data island
inside a script element is a grey area under a strict CSP, and this has to work
on every install. The JSON is hex-escaped (`JSON_HEX_TAG` and friends), so there
is nothing in a query or a log message that a parser could read as the end of the
element.

### Added

- Server-rendered pages get every tab the payload carries — Views, Models,
  Migrations, Exceptions and the rest — and a **requests** list that includes the
  page itself. Selecting a request switches every other tab to it.
- The bar is branded with the application's name (`title` setting, or the `TITLE`
  constant), the way a scaffolded SPA panel already was.

### Fixed

- **The tabs no longer follow the newest request on a server-rendered page.**
  Reported from a real application: `/users` is a datatable, which fetches its
  rows the moment it renders, and the toolbar moved onto that JSON call — so a
  page that had just rendered a template showed `Views 0`. The page's own request
  is now selected until the reader picks another, and a request they picked is
  not replaced by the ones that follow. A SPA, which has no page request, still
  follows the newest call.
- **Logs and Exceptions aggregate across requests until one is picked**, with a
  column naming the request each line came from. Both are streams: an entry
  happens at a moment, and which request produced it is a detail of it — an error
  logged by a background call was invisible while another request was in view. No
  other tab aggregates; Route and Session describe one request, and a combined
  SQL table would lose which call ran what.
- **An XHR never read the debug headers.** The `fetch` path fell back to
  `X-Pramnos-Debug` when a response body could not carry a payload; the
  `XMLHttpRequest` path did not — and every datatable is XHR. A call that
  returned an error page, a 204 or an HTML fragment reported `—` for server time
  and query count, while the identical call through `fetch` reported both. Both
  now go through one `headerPayload()`, with `Server-Timing` as a second
  fallback.
- **`X-Pramnos-Debug` never counted exceptions.** `summary()` looked for
  `exceptions` / `errors` keys that `ExceptionsCollector` has never emitted (it
  reports `count` and `items`), so the one thing a dead request could still have
  said about itself was always absent. The test that covered this passed because
  it built a fake collector with an invented shape; it now uses the real one.
- **Picking a request that carried nothing emptied the bar.** No payload meant no
  tabs, one line of grey text, and nothing to do — at the exact moment somebody
  clicked a red row *because* it had gone wrong. The stream tabs now stay
  reachable there, and the panel says which of the possible reasons applies.
- **An exception is now visible without being looked for**: the tab turns red,
  carries a `⚠`, and counts what any request raised — including a request whose
  response could only carry a count with no messages, where the row says so and
  points at the error log.

- `X-Pramnos-Debug` is written as JSON by `ApiDebugPayload::summary()`, and the
  renderer read it as `k=v;k=v`. Every bodiless response — a 204 from a save, a
  redirect — lost its server time and query count. JSON is now tried first, with
  the old reading kept as a fallback for a gateway that rewrites the value.
- The `<style>` the renderer injects carries the script tag's CSP nonce, read
  from `document.currentScript`. Without it a strict `style-src` left the toolbar
  as an unreadable column of text on exactly the installs that configure one.

### Removed

Roughly 500 lines: `css()`, `js()`, `ajaxJs()`, `renderPanel()`,
`formatTabLabel()`, `renderInfoStrip()` and the nine `render*()` methods. Nothing
called them once the island existed, and bypassed dead code is how two renderers
happen a second time.

## Tests

`tests/js/debugbar-ajax.test.js` and `debugbar-hide.test.js` used to extract the
deleted `ajaxJs()` and `js()` by reflection. They now run `DebugBarAsset::source()`
in a VM against a DOM stub, covering what is only true of this delivery: booting
from the island, wrapping the transports, returning the application's own response
untouched with its body unread, and the nonce reaching the stylesheet.

`DebugBarTest`'s assertions on generated HTML became assertions on the island's
JSON. That is the honest boundary now — PHP collects, the island carries, and the
JavaScript tests drive the drawing for real.

One behaviour changed with it: a bar with only a `MemoryCollector` used to render
nothing, because `memory` had no tab of its own. Which collectors deserve a tab is
the renderer's decision now, so the data always travels.
