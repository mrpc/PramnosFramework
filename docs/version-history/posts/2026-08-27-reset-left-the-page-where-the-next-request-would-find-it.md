---
title: reset() left the page where the next request would find it
date: 2026-08-27
categories:
  - Bugfix
  - Testing
---

# reset() left the page where the next request would find it

A login page 1.7 MB long, carrying a hundred copies of an inline script from a
screen it has nothing to do with. Every assertion against it passed.

<!-- more -->

## What was happening

`Document::reset()` did this:

```php
self::$instances = [];
self::$type      = 'html';
```

Which looks complete, and is not. **The page body does not live on the instance.**
Every concrete document type — `Html`, `Raw`, `Json`, `Amp`, `Png` — reads it from
a static buffer that `addContent()` appends to. Discarding the instances left that
buffer exactly where the next document would find it.

So each request added its page to the one before. Measured in a project's suite: a
login page grew by about 2.9 KB every time it was requested, and by the end of a
run it was 1.7 MB.

## Why it went unnoticed for so long

Because it makes tests pass.

`assertSee()` on a page that carries every page before it succeeds on content the
test never asked for — a test written to prove that `/login` shows a password field
passes if any earlier page had one. `assertDontSee()` fails on a page the test has
already left, which at least gets investigated. The passing half does not.

It had been worked around three times in one project without being recognised. A
security assertion — "the status page publishes no host measurements" — was scoped
to the page's own markup because the whole response contained the admin dashboard.
A "the revoked session is gone from the list" assertion was rewritten to query the
database instead. Each looked like a quirk of that particular page.

## The fix

```php
self::$buffer = '';
```

`reset()` is what `DocumentIsolation` and `TestClient` call between requests, so
one line covers both.

## The same shape one layer up

`View::display()` returned `$this->output`, and `getTpl()` appends to it.

The appending is deliberate — it is how a caller renders several templates into one
buffer with successive `getTpl()` calls. `display()` is not that caller: it renders
one template and returns the result. Returning the accumulated buffer gave it
everything that view had ever rendered, and a view is cached per controller, a
controller per application. Any process that serves more than one request gets the
previous pages in front of this one.

`display()` now starts from empty. `getTpl()` still appends.

## Notes

- Nothing to change in a project.
- In production this is mostly invisible — one request, one process — which is why
  it survived. It is real for anything that renders twice: a worker, a long-running
  server, a controller that displays two views.

## Documentation

- `Pramnos_Testing_Guide.md` — the "one client, many requests" material now names
  the static buffer as the deeper half of the accumulation.
