---
date: 2026-08-10
categories:
  - Changelog
  - Features
tags:
  - spa
  - debug
  - testing
  - scaffolding
---

# A debug bar a SPA can actually use — and a guide for testing the front end

The HTML toolbar is injected before `</body>`. A JSON response has none, and a
SPA's page is a static shell that never reaches that middleware — so
single-page applications had no debug information at all, for exactly the
requests that do the work. Now the data travels with each response, and a panel
shows it.

<!-- more -->

## Debug data rides along with the response

In development the API attaches a `_debug` key to every JSON response:

```json
{
  "application": "myapp",
  "status": "ok",
  "_debug": { "time": 154.61, "memory": {…}, "queries": { "count": 3, "queries": […] } }
}
```

No storage, no extra endpoint, nothing to correlate — the data describes the
response it is attached to, including the ones that failed. A `Server-Timing`
header goes out too, which browsers render in the network panel with no
front-end code at all and which also works for responses with no body.

**Never in production.** `ApiDebugPayload::isEnabled()` asks the toolbar whether
it has collectors, and collectors are registered only by
`DebugBarServiceProvider`, which only boots in debug mode. That keeps one
definition of "development" rather than two that can drift apart. Verified both
ways against a running project: with `development => true` the key is there, with
`false` it is gone entirely.

Two things the payload will not do: **fail the request** — a collector that
throws is reported as an error entry inside the payload, because instrumentation
is never a good reason for an API call to fail — and **outweigh the response**:
the query list is capped at 100 with the real count kept, so an N+1 is still
obvious without shipping a megabyte of SQL.

## The panel

Every SPA project gets `lib/debug.js`, and the API client feeds it each
response. It is inert unless debug data arrives, so it ships in every project
rather than being a development-only file the client would have to import
conditionally: no data, no DOM, no panel. When there is data it shows the last
20 calls — method, path, status, duration, query count — each expandable.

## Front-end testing guide

`docs/FRONTEND_TESTING.md` is now generated into every SPA project, describing
**that** project: its runner (Vitest or `node --test`), its directories, its
commands, with examples using its own API prefix.

It covers what is worth asserting in the API client (the URL, the `apiKey`
header, `credentials: 'same-origin'`, an `ApiError` whose status survives a
non-JSON error body), what a screen test should assert (visible behaviour, and
the two unhappy paths that matter: a failed request must become visible text, and
paging must go to the server), what **not** to test, and the traps — a leaked
`fetch` stub, awaiting what the component awaits, a token left in
`localStorage`, importing an entry point for its side effects.

`CLAUDE.md` and the `README` both point at it, so it is found rather than
discovered.

## Tests

`ApiDebugPayloadTest` — disabled with no collectors (the production path),
enabled once one is registered, the payload carrying timings and collector data,
a broken collector reported rather than thrown, the query cap keeping the true
count and stating what it dropped, and the `Server-Timing` value.
`InitSpaScaffoldingTest` — the panel is scaffolded and wired into the client, and
the guide is generated per stack (no Vitest API in a project without Vitest) and
not at all for an MVC project.
