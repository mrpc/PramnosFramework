---
date: 2026-08-12
categories:
  - Changelog
  - Changed
tags:
  - debugbar
  - spa
  - debugging
---

# One toolbar, delivered two ways — and the SPA panel gains every tab

The toolbar was drawn twice: ~970 lines of PHP for server-rendered pages, and a
separate scaffolded module for SPA projects, both turning the same collector data
into the same tables. They drifted, and then the `✕` that hid nothing had to be
fixed in both.

<!-- more -->

## One source

`Pramnos\Debug\DebugBarAsset` now owns the renderer, in two shapes because the
two contexts *load* code differently — not because the code differs:

- `source()` — an IIFE publishing `window.__pramnosDebugBar`, for inlining into a
  page, where an ESM `export` would be a syntax error that takes the whole script
  with it.
- `spaModule($appName)` — the same source with an `export function record()`
  appended that **forwards** to that instance rather than reimplementing it.

`init` and `project:resync --debug-panel` both write `lib/debug.js` from it, so
`scaffolding/templates/spa-debug-panel.js.stub` is gone: it was the second copy.

## The SPA panel draws every collector

The payload was never the limitation. `ApiDebugPayload::build()` has always
attached **every** registered collector — session, logs, views, models,
migrations, exceptions, route — and the SPA panel drew requests and statements.
It now draws all of them, because it is the same renderer that draws them on a
server-rendered page.

The model is a list of **entries**, one per request with the payload it produced.
Selecting a request in the `requests` tab switches every other tab to it. On a
server-rendered page entry #0 is the page itself; in a SPA the first entry arrives
with the first API call. A collector the payload does not carry gets no tab,
rather than an empty one that reads as "nothing happened", and a collector that
*threw* says so — the payload carries `{error: …}` in its place.

## Deliberate details

**The data island is a `<div hidden>`, not a `<script type="application/json">`.**
A data block inside a script element is treated differently by different browsers
under a strict Content-Security-Policy, and this has to work on every install.

**No transport wrapping in a SPA.** `boot()` wraps `fetch`/`XMLHttpRequest` only
when the data island is present. A SPA's API client calls `record()` itself, and
wrapping fetch as well would record every one of those calls twice.

## Still to come

This landed the SPA half: the module is generated from the single source and the
tabs are there. `DebugBar::render()` still builds its own HTML — swapping it for
the data island plus this script is the next step, and the point at which the
~970 lines of PHP renderers are deleted rather than merely bypassed.

## Tests

`DebugBarAssetTest` — the classic shape carries no ESM syntax, the module's export
forwards rather than duplicates, the generated header names the application and
says not to edit it, and an application name with markup in it is escaped rather
than allowed to corrupt the bar. `tests/js/spa-debug-panel.test.js` is rewritten
against the shipped module: production silence, every collector becoming a tab,
each tab drawing its own data, a failed collector reported, newest-first request
order, a 204 still recorded, hide/restore, and storage that throws costing the
memory rather than the button.
