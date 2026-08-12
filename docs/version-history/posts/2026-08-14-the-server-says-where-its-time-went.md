---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Changed
tags:
  - debugbar
  - debugging
  - performance
---

# The server now says where its own time went

The Time tab learned to subtract client from server and SQL from both. What it
still could not show was what the server's share was *made of*: the API path had
no timers at all, and the phase before any application code runs — connecting to
the database, booting providers, starting the session — had never been on the
timeline at all.

<!-- more -->

## Added

**A real `bootstrap` segment**, around the whole of `Application::init()`, with
`db-connect`, `providers` and `session` inside it. These are the classic
invisible cost: they happen before a single line of application code, and no
amount of profiling the controller finds them.

Timing them needed a new way to record. The collector that would have measured
them is registered *by* one of those phases, so it does not exist while they run.
`TimeCollector::addSegment($name, $start, $end)` takes absolute times, so each
phase is measured as it happens and handed over at the end keeping its own place
— the existing `addCompletedSegment()` back-calculates from "now minus the
duration", which is right for one piece of work reported as it finishes and
stacks three of them at the same instant when they are reported together.

**`middleware` and `action` on the API path.** This is why a SPA's Time tab
showed a single segment: everything a SPA does happens here and none of it was
measured. `middleware` stops when the pipeline reaches the core and `action`
starts there, so work in between is charged to the action rather than the
pipeline — and `middleware` is stopped again after the pipeline returns, because
an OPTIONS preflight or a refused authentication never reaches the callback, and
a timer left open would read as an action that took the whole request.

**The request id, copyable from the requests list.** On hover, beside the path —
the value to paste into a bug report or a log search, and what
`/devpanel/logs?request=<id>` takes. Not a column: sixteen characters of noise
that somebody reads once in their life should not permanently own a sixth of a
narrow table.

## Changed

**`boot` is now `debugbar`.** It measures the toolbar's own provider registering
its collectors — useful when the toolbar itself is suspected of costing
something, and misleading under a name that reads as application startup. That
name is now taken by something that is.

**`Server-Timing` carries the phases**, so the browser's own network panel draws
them with no toolbar involved, and the database's share travels as a duration
rather than only a count: `db;dur=24.5;desc="3 queries"`.

Only the framework's own phases are published. An application can name a timer
anything at all — including something it would rather not have in a log file —
and this header is written to every access log between here and the client.
