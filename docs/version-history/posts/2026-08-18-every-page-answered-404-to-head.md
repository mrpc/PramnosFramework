---
date: 2026-08-18
categories: [Changelog]
---

# Every page answered 404 to HEAD

Reported from an application whose sitemap had just started working — 2,250 server-rendered
pages announced to crawlers, and then this:

```
GET  /station/athens  → 200
HEAD /station/athens  → 404
GET  /sitemap.xml     → 200
HEAD /sitemap.xml     → 404
```

Every page. Every application on this router.

<!-- more -->

## What was wrong

RFC 9110 §9.3.2 is not ambiguous: *"The HEAD method is identical to GET except that the server
MUST NOT send content in the response."* A resource that answers GET answers HEAD.

Routes are stored per method, so `$this->routes['HEAD']` held only the routes an application had
declared for HEAD explicitly — which is, in practice, none. `getMatchedRoute()` looked in that
table, found nothing, and returned null. The application then answered 404 for a page it serves
perfectly well.

It is not a curiosity about an unusual verb. HEAD is what link checkers, uptime monitors,
`curl -I`, several crawlers and every *"is this URL alive"* tool send first — so a site could be
entirely reachable and report as entirely broken, with nothing in its own logs looking wrong.

## The fix

`getMatchedRoute()` retries a HEAD request against the GET table when nothing in the HEAD table
matched. Tried **second**, so an application that declares a cheaper HEAD than its GET — an
existence check that skips the expensive query — keeps it.

The three lookups (exact, query-stripped, pattern) moved into a private `matchWithin()` so the
retry does not duplicate them, and `Route::matches()` gained an optional second parameter for
matching as a method other than the request's own. Both additive; no existing signature changed.

**Only HEAD falls back.** POST answered by a GET route would run a read handler for a write
request, and make a route look like it accepts submissions.

**The body is not the router's business.** PHP's SAPI drops the content of a HEAD response, and
an application writing its own output can read the method. What is fixed here is *which route
runs*.

## Fixed

- **`Router::getMatchedRoute()` answers a HEAD request from the GET table** when no HEAD route
  matches, with parameters filled exactly as GET would fill them.

## Added

- **`Route::matches($request, $asMethod = null)`** — match as a given method rather than the
  request's own. Optional and additive.

`tests/Unit/Routing/HeadIsAnsweredByGetTest.php` covers both directions: the fallback, the
parameters, an explicit HEAD route winning, a URI nobody serves still refusing, POST and DELETE
**not** borrowing the GET table, and GET itself unchanged.
