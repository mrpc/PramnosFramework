---
date: 2026-08-20
categories: [Changelog]
---

# A report of zeroes

The DevPanel's slowest-endpoints report finally had data in it, and it looked like this:

```
Endpoint                                              Method  Calls  Avg ms  Max ms
http://127.0.0.1/devpanel/logs?request=f768ff13af8a…  GET     1      0.0 ms  0.0 ms
http://127.0.0.1/devpanel/logs?request=8faf840bfe40…  GET     1      0.0 ms  0.0 ms
```

Two defects, one screen.

<!-- more -->

## Fixed

**Every duration was zero.** `Token::addAction()` holds the row and `updateAction()`
completes it with the status and the duration — but only the API path calls
`updateAction()`. A web request is written by the shutdown flush, which wrote the held row
exactly as it was held: no duration, no status, for every page view ever logged. The flush
now fills in both, which is the whole point of writing at shutdown rather than at the start
of the request: by then the request is over and the answers exist.

A negative `$return_status` still means "do not record an outcome" — that decision is now
stored as an explicit null so the flush can tell it from "nobody has said yet".

**Every URL was distinct.** `urls` is described as a deduplicated registry — one row per
endpoint — and it was given the absolute URL including the query string, so a page whose
query carries an id gets a row of its own on every call. Twenty rows of one call each is a
registry with nothing deduplicated in it and a report with nothing to compare.

The endpoint is now the path. The query is not lost: it goes into `params`, where a
request's inputs belong, whenever `params` would otherwise be empty — which is every GET,
since a GET's body is empty by definition. The scheme and host go too; every row in an
installation has the same one, and an application that needs them can replace the row
transformer, which is what `WriteSpool::transform()` is for.

Rows written before today keep their absolute URLs and will group separately until they age
out.

## Documentation

`Pramnos_Authentication_Guide.md` — what a logged request records, which path records it, and
where the query string goes.
