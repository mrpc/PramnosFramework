---
date: 2026-08-13
categories:
  - Changelog
  - Added
tags:
  - debugbar
  - debugging
  - performance
---

# The Time tab starts answering the question in its name

It showed one segment and a start time. On a SPA it showed one segment, because
`routing` and `controller` are instrumented on the MVC path and the API path has
no timers at all. What it never showed was the two things the toolbar already
knew and had never subtracted.

<!-- more -->

## Added

**Client versus server, as one bar and one sentence.**

```
client 210ms = server 42ms + 168ms elsewhere
```

Both numbers were already there — the browser measured the call, the server
reported its share — and the difference is network, queueing and the browser's
own work. A call that spends 40ms in PHP and 210ms in the air is not a slow
endpoint, and optimising the endpoint is the wrong afternoon.

**SQL as a share of server time**, from the query collector's `total_ms`. "24ms
of 40ms was the database" is the difference between an indexing problem and an
application one. Red above half.

Both are absent rather than zero when a number is missing: a bar claiming 0ms of
network for a response that only carried a header would be inventing.

**A waterfall across requests**, in the requests tab, oldest first on a shared
axis — the way a browser's network panel reads. This is the insight no
per-request tab can give: three calls of 200ms each are a 200ms page if they
overlap and a 600ms page if they do not, and a tab that shows each of them
separately cannot tell you which you have. A polling loop looks like a comb; a
staircase is a chain of calls waiting on each other. Failed requests are red
there too, and clicking a bar picks that request.

A request with no client duration — the page itself, or a response that only
carried a header — is drawn as a mark rather than a bar, because a width would
be a guess.

## Still to come

The other half of this: instrumenting the API path the way MVC is (`route`,
`middleware`, `action`, `serialize`) and a real `bootstrap` segment around
`Application::init()`, where DB connect, service providers and session start are
the classic invisible cost. The `boot` segment that exists today measures the
DebugBar provider's own registration window, not application boot, and is
misnamed for it.
