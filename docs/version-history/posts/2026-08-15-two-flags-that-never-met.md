---
date: 2026-08-15
categories:
  - Changelog
  - Fixed
tags:
  - application
  - middleware
  - deployment
  - seo
---

# Two flags that never met

`MigrationRunner` enables maintenance mode by raising `var/MAINTENANCE`.
`MaintenanceModeMiddleware` watched `maintenance.flag`. An application that registered
the middleware exactly as documented served every migration from the live site.

<!-- more -->

## The flag nobody was watching

Three things in this framework put the site into maintenance, and they did not agree on
the file:

| Raised by | Flag |
| --- | --- |
| `Application::startMaintenance()` | `var/MAINTENANCE` |
| `MigrationRunner`, for the duration of a batch | `var/MAINTENANCE` |
| `MaintenanceModeMiddleware` | **`maintenance.flag`** |

The middleware's own docblock said `touch /path/to/maintenance.flag`, and the guide's
table said the same, so nothing about reading either would have told you. The operator
adds the middleware globally, runs `php pramnos migrate`, and watches the runner
announce that maintenance mode is on — while every request continues to be served,
mid-migration, against a schema that is halfway between two shapes.

**The failure is silent in the worst direction.** It is not that maintenance mode does
nothing visible; it is that the evidence for it working is right there — the middleware
in the pipeline, the runner's message, the flag file on disk — and all of it is true.
Three correct facts about two different files.

With no constructor argument the middleware now watches both. Pass a path explicitly
and it watches that path alone: an application that named its own file has said which
file it means, and silently adding two more would let the framework decide a site is
down.

The tests assert **which paths are watched**, not that some flag stops a request. The
latter would have passed for the entire life of the defect — which is exactly the shape
this ledger keeps recording, and the reason to write the harder assertion.

## And the response itself had no status

`Application::showError()` is reached whenever `var/MAINTENANCE` exists, because the
**constructor** calls it — which includes applications that route with
`Router::dispatch()` and never touch `init()` or `exec()`. It emitted an HTML page and
nothing else: no status code, no content type.

Two consequences that look unrelated and are one bug:

- **A JSON client got `200 OK` with a page of HTML.** It failed on parsing, not on
  recognising that the site was down — so the SPA showed a generic error instead of a
  maintenance state it could have handled.
- **A crawler got the maintenance page as a `200`**, which makes it eligible to be
  indexed in place of the real page. For a site that renders on the server *because of*
  search engines, an hour of planned downtime could cost the result the page exists to
  earn.

Now:

| Situation | Status | Body |
| --- | --- | --- |
| Maintenance, browser | `503` + `Retry-After` | the HTML page, unchanged |
| Maintenance, `Accept: application/json` | `503` + `Retry-After` | `{"error":"maintenance","retry_after":300}` |
| Any other fatal — PHP version, addon, database | `500` | `"error":"unavailable"`, no retry |

The split matters: `showError()` is also the terminal *fault* path, and answering
`503 Retry-After` to a misconfiguration tells a crawler to come back to something that
is not coming back.

`Retry-After` reads `PRAMNOS_MAINTENANCE_RETRY_AFTER` — a constant rather than a
setting, deliberately. This runs while the site is down, and in the case that matters
most, the database being *why* it is down, asking the database how long to wait cannot
work.

Content negotiation is one header test: `Accept` naming `application/json`, or
`X-Requested-With: XMLHttpRequest`. Browsers send neither, so there is no list of API
paths to keep in step with the router — the thing that would rot.

The JSON body carries the same message the HTML page shows, under the same conditions
(the developer-supplied message always, the database dump only under `DEVELOPMENT`).
Carrying less would mean the format a client can actually parse is the one told least.

## What made it findable

Both halves were found while writing documentation, not while reading code: the flag
mismatch turned up because the guide's middleware table had to be checked against the
class, and the missing status turned up because a consumer asked whether their SPA had
a maintenance guard. It has one. It has had one all along, and its output was
unusable — which is a different problem from not having one, and produces the same
report.

## Fixed

- `MaintenanceModeMiddleware` watches `var/MAINTENANCE` as well as `maintenance.flag`
  when constructed with no argument; an explicit path is still exclusive.
- `Application::showError()` sends `503` (maintenance) or `500` (fault), a matching
  `Content-Type`, and `Retry-After` while stopped on purpose.
- The same call answers JSON to clients that asked for it, instead of HTML with a
  `200`.
- `PRAMNOS_MAINTENANCE_RETRY_AFTER` sets the retry window for both paths.
- `MaintenanceModeMiddleware` gained the docblocks the rest of the middleware has, and
  no longer calls `header()` after headers are sent.

## Documentation

- [Framework guide](../../Pramnos_Framework_Guide.md) — a *Maintenance mode* section
  under Built-in Middleware: which flag each mechanism raises, what each kind of client
  is told, and the corrected table entry.
