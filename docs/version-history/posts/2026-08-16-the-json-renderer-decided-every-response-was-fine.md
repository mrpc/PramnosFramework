---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - document
  - api
  - middleware
---

# The JSON renderer decided every response was fine

`Json::render()` opened with `header('HTTP/1.1 200 OK')`. Every JSON error — 404, 403,
500 — was therefore served as `200 OK`, and a client checking `response.ok` saw every
failure as a success carrying strange data.

<!-- more -->

## Found by a warning, not by a report

The previous commit added an `http_response_code()` call to `showError()`. The suite
came back green with one warning:

```
http_response_code(): Calling http_response_code() after header('HTTP/...') has no effect
```

Which is PHP saying, politely, that something upstream had already written a status
line by hand. Three places had: `Json::render()`, `Rss::render()` and
`CorsMiddleware`'s preflight branch.

The CORS one is harmless in effect and wrong in form. The other two are not harmless.

## Two failures from one line

```php
header('HTTP/1.1 200 OK');
```

**It stamps 200 over a status the caller already set.** An API that decided this
request is a 404 renders its body through `Json::render()`, and the body-rendering step
overwrites the decision. For an SPA using `fetch`, `response.ok` is `true` for every
error the API returns.

**It pins the status.** Once a status line has been written by hand, PHP ignores every
subsequent `http_response_code()`. So a middleware that runs after rendering, or an
error handler further out, cannot correct it — and PHP reports that only as a warning,
which in production is a line in a log nobody reads.

`200` is PHP's default. The line was a no-op in the one case where it was correct.

## What the tests could and could not do

The behavioural test — set 404, render, assert still 404 — fails when the fix is
reverted. That one works.

The obvious companion — render, then set a code, then check it applied — **cannot be
written**. PHP's "a status line was sent by hand" flag is global to the process and
there is no way to clear it, so the first test to trip it decides the answer for every
test after it. Two such tests were written first, and **both passed with the defect
present**, in whatever order PHPUnit happened to run them.

So that half is structural instead: no document type may contain `header('HTTP/...')`.
It covers `Rss` — which the behavioural test never reached — and every renderer added
later, which is where a fix applied once goes missing.

That guard was then wrong in the way this ledger has been collecting all week. Its
first version pointed `dirname(__DIR__, 5)` at a directory outside the repository,
found **no files**, and passed. A guard that scans nothing reports success. It now
asserts it scanned something before asserting what it scanned is clean — the authority
for *"there is anything to check"* has to come from outside the loop doing the
checking.

## Fixed

- `Json::render()` and `Rss::render()` no longer write a status line, so the status
  the caller set survives being rendered.
- `CorsMiddleware` answers preflight with `http_response_code(204)` instead of
  `header('HTTP/1.1 204 No Content')` — the hardcoded `HTTP/1.1` is also wrong on an
  HTTP/2 connection.

## Documentation

- [Document & Output guide](../../Pramnos_Document_Output_Guide.md) — *The document
  does not decide the status code*: what a renderer owns, what it does not, and why
  `header('HTTP/...')` is never the right call.
