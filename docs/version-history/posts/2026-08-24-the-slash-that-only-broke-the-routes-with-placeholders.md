---
date: 2026-08-24
categories: [Changelog]
---

# The slash that only broke the routes with placeholders

A consuming application filed one line: *`fe089ef` still has `'/' . $uri` without
`ltrim`.* It was right, the line was `Routing\Route::matches()`, and the cause
turned out to be one level above it.

<!-- more -->

## What broke

`matches()` hands the URI to Symfony's compiled pattern with a slash prefixed:

```php
preg_match($this->getCompiledRoute()->getRegex(), '/' . $uri, $this->parameters);
```

Give it a URI that already starts with a slash and that is `//stations/7`. The
compiled pattern is anchored, so it misses — **every route with a placeholder**,
while every static route keeps working, because those are answered by a `==`
comparison a few lines above and never reach the pattern.

That asymmetry is why it lasted. The routes anybody writes first, and tests
first, are the ones that were fine.

## Where the slash came from

`Request` trims the URI it reads from the environment — `trim($_SERVER['REQUEST_URI'], '/')`,
and the subdirectory branch trims too. `Request::create()`, the factory a test or
a console caller uses, did not:

```php
self::$requestUri = $uri;          // before
self::$requestUri = trim($uri, '/');  // now
```

So `getRequestUri()` answered `stations/7` for a real request and `/stations/7`
for a created one. Two ways of building a Request disagreeing about what the
request was for — and **every** consumer of that value inherited it, not only
routing. All 114 call sites in this repository pass a leading slash, because
that is how a URL is written; none of them wanted it preserved.

## Both halves fixed

The factory now produces the constructor's shape, and the match is defensive
like its two siblings — `Routing\OpenApiGenerator` and `Router::add()` have
written `'/' . ltrim($uri, '/')` all along. Fixing only the symptom would have
left the next caller of `getRequestUri()` to find the same discrepancy again.

The test file keeps them apart on purpose: one test pins the factory's output
shape, a second pins placeholder matching. Either fix alone turns the second
green, so without the first the factory could silently regress.

Thirteen tests, covering the reported case, both placeholder shapes through the
router, the static route that was never affected, and two URIs that must still
*not* match — a fix that made the pattern looser would pass everything else here.

906 routing, HTTP and middleware tests pass unchanged.
