---
date: 2026-08-24
categories: [Changelog]
---

# A bypass that only stopped half of it

`PageCache::bypass()` meant "do not save this page". It did not mean "do not serve
one", and the missing half is the dangerous one.

<!-- more -->

## Fixed

- **`PageCache::bypass()` now stops a lookup as well as a store.** The runtime flag
  moved into `bypassCheck()`, which both halves already consult.
- **`whyBypassed()` reports it**, as `runtime:<reason>`. It previously returned
  `null` for a request the application had explicitly refused.

## Added

- **`skipWhileDebugging`** (default `true`) — a response is not stored while the
  debug toolbar is collecting.

## How the bypass failed

`store()` asked two questions:

```php
if ($this->bypassCheck($request) !== null || self::isBypassed()) {
```

`lookup()` asked one. `self::isBypassed()` appeared exactly once in the file.

Both halves read correctly in isolation, which is why this survived review and was
caught by an HTTP test in a consuming application instead. That application calls
`bypass()` whenever a session exists, so its signed-in visitors were served the
anonymous cached page — logged-out header on a logged-in page. Its front controller
now carries a hand-written `isBypassed()` check before `lookup()` with a comment
pointing at the framework; that check can go.

The fix is in `bypassCheck()` rather than at the top of `lookup()`, so a third call
site cannot forget it and so `whyBypassed()` gets it for free. A diagnostic that
answers "this request is cacheable" about a request the application has refused
sends whoever is debugging to the configuration, which is the one place the answer
is not.

## The toolbar was being cached with the page

`Application::render()` injects the debug toolbar into the string it returns. A
front controller then wraps that string in a `Response` and hands it to `store()` —
and nothing in between could notice. `privateMarkers` is empty by default. A
toolbar sets no cookie. So the guard that catches per-visitor responses did not
catch this one.

What would be stored is one developer's SQL with its bound values, their timings
and the files that ran, served to everyone who asks for that page next.

`APP_DEBUG` is meant to be off in production, which bounds it and does not close
it: a staging environment with real data and the page cache on is an ordinary thing
to have, and there the failure is silent. The guard uses the same condition
`injectInto()` uses to decide whether to inject at all, so "there is a toolbar in
this body" and "refuse to store this body" cannot drift apart.

There is an escape hatch, off by default, because the alternative to a switch is
somebody editing the guard out locally and pushing it:

```php
'skipWhileDebugging' => false,   // only where the stored pages reach nobody else
```

## While we were there

A hit returns a `Response` before the application runs, and `DebugBarMiddleware`
only decorates string responses — so **a cache hit carries no toolbar at all**.
That is correct, and an easy thing to misread as "debug is broken". Said out loud
in the guide, next to `X-Pramnos-Cache`, which is what actually tells you a hit
happened.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) — the store rules, a new
  section on the toolbar, and a rewritten "When a page is not being cached" that
  now opens with the one command that answers it most of the time:

  ```bash
  curl -D- -o /dev/null -s https://example.test/directory | grep -i set-cookie
  ```
