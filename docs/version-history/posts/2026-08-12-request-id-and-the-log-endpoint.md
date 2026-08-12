---
date: 2026-08-12
categories:
  - Changelog
  - Added
tags:
  - debugbar
  - debugging
  - logging
---

# A name for every request, and the log lines it wrote

The toolbar's data travels with the response it describes. That is what makes it
work with nothing to correlate and nothing to clean up — and it is also its
ceiling, which a deliberately broken endpoint made obvious: a request that dies
has no response to carry anything. An error page is not a JSON object, so there
is no `_debug` key, and the header that still gets through has room for a count
but never for a message. The toolbar could say *something was raised here* and
nothing more.

<!-- more -->

## Added

**`Pramnos\Debug\RequestId`** — a 16-character name for the current request,
issued only while the toolbar is active. `Logger` writes it on every line, the
response announces it in `X-Request-Id`, and it rides in the payload and in the
`X-Pramnos-Debug` summary.

**`Pramnos\Debug\RequestLog`** — reads those lines back out of the log directory:
the tail of each `*.log`, matched on the id, capped. Nothing here takes a path
from a caller.

**`GET /devpanel/logs?request=<id>`** — the endpoint, replying JSON and
`no-store`. It accepts the same signed `debug:token` grant that opened the
toolbar, as well as the DevPanel's own admin check: the developer holding a token
on a live server is usually not an admin user, and requiring both would have made
the feature useless exactly where it is needed.

**In the toolbar**, the Logs and Exceptions tabs offer *Ask the server for this
request's log lines* whenever a request has an id, and show what comes back under
"From the server's log". The request goes through the *unwrapped* `fetch`, so
looking does not add another row to the list being looked at.

### By id, never by time

"Everything logged between the request and its response" is the obvious
implementation and the wrong one. On a live server the toolbar is open for one
browser, by grant, while every other visitor is logging into the same seconds —
a time window hands their lines over too. That is a data leak wearing a debugging
hat, so a line qualifies only by carrying the id.

An incoming `X-Request-Id` is also deliberately ignored. Honouring one is the
conventional thing to do, but here the id decides which log lines are handed
back, and accepting a caller-supplied one means accepting a caller who chooses to
be indistinguishable from somebody else's request.

### Nothing changes in production

Ids are issued only when `DebugBarServiceProvider` boots, which only happens in
debug mode. With none issued, `RequestId::activeId()` is null, `Logger` adds
nothing, and every line keeps the exact shape it has always had.

## Changed

- **Picking a request no longer changes tab.** It jumped to SQL on every pick, so
  comparing one tab across two requests meant navigating back to it each time.
  The open tab stays open.
- **A request can be released**: click the selected row again, or the `✕` on the
  chip naming it. A selection with no way out is a mode, and a mode nobody can
  leave is where "the toolbar is showing the wrong numbers" comes from.
- **A failed request is red across the whole row**, not just in the status cell —
  including a `200` that raised something, which is the case nobody would go
  looking for. A red cell in the narrowest column of six is a signal placed where
  nobody is looking.
