---
date: 2026-08-18
categories: [Changelog]
---

# A placeholder that ate the query string

Reported from a project whose station pages answered **404 to every link shared on Facebook**.
Facebook appends `fbclid` to the URL it posts, and the page it pointed at stopped existing:

```
GET /station/athens            → 200
GET /station/athens?fbclid=x   → 404
GET /stations?playable=1       → 200      ← the static route was fine
```

The pattern is what made it survive: only routes with a **placeholder** were affected, and only
when a query string was present. Nothing in the application had changed.

<!-- more -->

## What was wrong

`Request::getRequestUri()` returns the request with its query string still attached, and
`Route::matches()` used to try the compiled pattern against that string **first**, stripping the
query and retrying only if nothing had matched.

For a static route the retry did the work: `/stations?playable=1` misses every pattern, falls
through, and matches on the second attempt.

For `station/{slug}` there was no second attempt, because the **first one succeeded on the wrong
string**. A placeholder compiles to `[^/]+` by default and a query string contains no `/`, so the
pattern matched `/station/athens?fbclid=x` happily and filled `parameters['slug']` with
`athens?fbclid=x`. The route matched; the controller then looked up a slug nobody has.

The retry block was therefore unreachable for exactly the routes that needed it.

## The fix

The strip moved from after the regex to **before the first comparison**, in
`Pramnos\Routing\Route::matches()`. A route that declares its own query string is left alone —
that guard was already there and is why this is a conditional strip rather than an unconditional
`parse_url()`.

`Router::getMatchedRoute()` gained the same lookup on the path alone, so a static route with a
query string hits the O(1) map instead of scanning the whole table before `matches()` sorted it
out. Correct either way; this only makes the fast path reachable.

## Fixed

- **`Route::matches()` no longer captures the query string into a route placeholder.** Any route
  ending in `{param}` was affected: tracking parameters (`fbclid`, `utm_*`), a `?page=2` on a
  parameterised listing, and a redirect carrying `?error=…` back to the page that produced it.
- **`Router::getMatchedRoute()` matches static routes with a query string on the fast path.**

`tests/Unit/Routing/RouteIgnoresQueryStringTest.php` covers both directions, including a
parameter whose *value* contains slashes (`?return=/station/other`) and the guard that keeps a
route registered with its own query string reachable.
