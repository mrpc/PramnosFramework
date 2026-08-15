---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - model
  - api
---

# A failed list query killed the request

`Model::_getList()` caught a query failure and then called `showError()`, which exits.
The two lines under it — record the error, return an empty list — are what the API's
own error envelope was written against, and they were unreachable on the one path that
needs them.

<!-- more -->

## The dead lines

```php
} catch (\Throwable $ex) {
    Logger::logError(...);
    if ($displayerroroutput == true) {
        $this->controller->application->showError($ex->getMessage());  // ← exits
    }
    $this->sqlError = $ex->getMessage();   // never reached
    return array();                        // never reached
}
```

`$displayerroroutput` defaults to `true`, so those last two lines only ran for a caller
that had explicitly asked for silence. Everyone else got the process torn down.

For a page that is defensible — without the list there is nothing useful to render. For
an API it is not, and the framework had already written the alternative:
`ApiListResponse::error()` builds `{"error": …, "data": [], "pagination": null}`, reading
exactly the `sqlError` that the exit prevented from being set.

So the branch is now taken on what the client asked for. A request whose `Accept` names
JSON records the error and returns an empty list; a browser still gets the error page,
unchanged.

`Application::clientWantsJson()` became public for this. `Model` could have tested the
header itself, and that second copy is precisely the thing that drifts — one path
answering HTML because somebody improved the other one.

## The reversal is the interesting part

With the fix reverted, the test does not fail. It **dies**:

```
Exception: Application::close() called with msg: {"error":"unavailable", …}
```

That is the defect stated exactly: not a wrong answer, an absent one. A test written to
assert the returned value can only ever report `Error`, never `Failure`, because there
is no return.

It also showed something worth fixing on the way past. That payload carried
`"title": "Maintenance Mode"` — for a **database fault**. The parameter's default is
right for the branch it was named after and misleading everywhere else: an API client
reporting "Maintenance Mode" sends whoever reads it to go and check the deploy. The
title is now carried only when it says something — an actual maintenance stop, or a
caller that passed one.

## Fixed

- A failed `_getList()` query no longer ends a request whose client asked for JSON; it
  sets `sqlError` and returns an empty list, which is what `ApiListResponse::error()`
  needs.
- `Application::clientWantsJson()` is public, so there is one implementation of the
  question rather than one per caller.
- The JSON error payload no longer says `"title": "Maintenance Mode"` for faults that
  have nothing to do with maintenance.

## Documentation

- [API guide](../../Pramnos_API_Guide.md) — *When something fails on the server*: what
  an API client is told when a list query fails, and when the framework stops the
  request outright.
