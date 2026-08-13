---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - debug
  - spa
---

# An Errors tab, for what the browser threw

The toolbar's **Exceptions** tab is the server's. Its blind spot is everything
after the response arrives: a screen that throws while rendering the data it just
fetched, a promise nobody caught, an `ApiError` a screen turned into a friendly
message. All of those left the panel looking perfectly healthy next to a page
that was visibly broken.

<!-- more -->

## What arrives by itself

`window.onerror` and `unhandledrejection`, in both deliveries — a server-rendered
page and a SPA, from the same source. Both listeners are **passive**: they never
call `preventDefault()`, so the console still shows the error and any other
handler still runs. A debug panel that swallowed errors would be worse than one
that missed them.

A cross-origin script reports a message and no `Error` object at all; that is
kept too, because a 404 on a bundle is a real finding.

## What you hand over

The interesting failures are the ones somebody caught — and a caught error
reaches no global handler:

```js
import { reportError } from './lib/debug.js';

try {
    await api.post('/things', body);
} catch (error) {
    showMessage(error.message);
    reportError(error, { kind: 'ApiError' });
    throw error;
}
```

A scaffolded project now does this in three places without being asked:

- `lib/api.js` reports every `ApiError` **with the request that produced it**;
- and every **network failure** — which has no response, no status and no
  `_debug`, so nothing else in the panel recorded it at all;
- `App.svelte` wraps each screen in a `<svelte:boundary>` whose `onerror` hands
  the failure over while the shell — header, navigation, a way out — stays on
  screen. Before this, a screen that threw took the whole application down.

On a server-rendered page the same entry point is `window.__pramnosDebugBar.reportError(error)`.

## The tab

It appears only once something has been thrown, red and with a `⚠`, next to
Exceptions. Each row carries the kind, the message, a folded stack (masked — this
panel gets screenshotted into bug reports) and **the request it happened after**.
That last column is a heuristic and says so: the code that threw was reacting to
the call that had just come back, which is right nearly every time, and an
explicit request from the caller always wins.

Identical failures collapse into one row with a `×4`. A render loop throws the
same error thousands of times, and fifty copies of it would push every other
finding off the panel.

Errors are collected before the bar exists — in a SPA the first one is often the
reason no request was ever made — but a **production page that throws still gets
no toolbar**: the bar is built only by a response that carried debug data.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — the Errors tab, what
  arrives by itself, and the three call sites a scaffolded project already has.
