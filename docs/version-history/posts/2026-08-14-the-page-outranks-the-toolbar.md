---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - debug
---

# The page outranks the toolbar

Injecting the debug toolbar can no longer cost you the response. Anything thrown
while rendering gives the body back exactly as it arrived, and a decorated body
shorter than the original is discarded. Both the middleware and the
output-buffering path enforce it.

<!-- more -->

## What happened

With the toolbar booted, a plain HTML response reached the browser as **200 with
`Content-Length: 0`**. PHP discards an output buffer when its callback throws, so
a 37KB page was produced by the application and then dropped on the way out.

Every signal pointed away from the truth:

- the response headers said the request had **succeeded** — `Server-Timing`,
  `X-Pramnos-Debug` with a query count and a request id, all present and correct;
- **nothing was logged**, because the injection runs at shutdown;
- the same request under the CLI SAPI rendered perfectly, because the provider's
  boot condition differs there — which sends you looking for an Apache or
  output-compression fault;
- the 200 made every uptime check pass.

An empty body on a 200 is the worst shape a failure can take: invisible to every
automatic check, and to a person it looks like a broken front-end build. One
development environment lost a day to it and turned `APP_DEBUG` off — which is a
real loss, because the toolbar is the thing that would have explained it.

## The fix

The rule is now explicit in both injection paths, and it is the general one rather
than a patch for the specific throw:

- **Anything thrown is caught**, and the un-decorated response is returned.
  Rendering reads collectors, the session, the container and an asset from disk —
  none of which have anything to do with the page that is ready to be sent.
- **A shorter result is discarded.** A decoration that shortened the body has
  failed, whatever it thinks. This is a guard against a future change rather than
  a known path, and it is one line.

The decision now lives in `DebugBarServiceProvider::decorate()` rather than inside
the `ob_start()` closure, because a closure that runs at shutdown cannot be
tested — and this is the code that decides whether a page is delivered at all. It
has tests now: a bar that throws while rendering returns the page byte-for-byte,
in both paths.

If the toolbar ever vanishes from a page that is otherwise fine, that is this
guard working. A missing toolbar is a bug report; a missing page is a phone call.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — "The page outranks the
  toolbar", under how the data gets there.
