---
date: 2026-08-12
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - realtime
  - sse
  - redis
  - broadcasting
---

# SSE events published during a reconnect are no longer lost

Three true statements that add up to a false one. `EventSource` reconnects by
itself, so the client side needs no code. `maxRuntime: 95` ends the stream
deliberately, to stay under an edge timeout. The Redis backplane is pub/sub.

Together: every client reconnects on a schedule, and everything published in the
window between the close and the new subscription is delivered to nobody.
Nothing errors. Two applications lost events this way before anyone noticed.

<!-- more -->

## Added

- **`id:` frames.** `SseWriter::event()` writes the backplane's event id, and
  `stream()` attaches it automatically — an application callback that just calls
  `$sse->event(...)` produces one without knowing it exists. Without an id frame
  the browser has nothing to remember and `Last-Event-ID` never arrives, so the
  spec's own answer to this could not even begin.
- **`Last-Event-ID` handling.** `stream()` resumes from the header the browser
  sends on reconnect, from `?since=` for clients that keep their own cursor, or
  from an explicit `sinceId` when the endpoint knows better. A first connection
  starts live rather than replaying whatever history exists.
- **`SubscriptionOptions::$sinceId`** — the driver-facing half. A string, because
  ids belong to the backplane: a row id in a table, a `1699…-0` entry id in a
  Redis stream.
- **`RedisStreamDriver`** — the same envelope on a Redis **Stream** instead of
  pub/sub. `XADD` with `MAXLEN ~` caps history per channel (default 1000
  entries), `XREAD` blocks for what comes next, and a cursor replays what was
  missed. Separate from `RedisDriver` rather than a flag on it: they have
  different storage, different memory behaviour, and "how much history do I
  keep?" is not a question pub/sub can be asked.

## Fixed

- **`DatabaseDriver` lost the same window although its events were durable.**
  They were in the table the whole time; the loop started at `latestId()` and
  stepped over them because nothing told it where to begin. It now honours
  `sinceId`, which was close to a one-line fix once the option existed.
- The event id is passed to consumers as a fourth argument. Callbacks written
  before this take three parameters and are unaffected.

## Unchanged on purpose

`RedisDriver` still uses pub/sub and is still the default. It is right for a
WebSocket daemon that stays connected, and switching a deployment to streams is
a decision about retention — not something to apply behind an operator's back.

## What replay does not solve

A capped stream covers a reconnect, not a laptop closed for an hour. The guide
now says so, and says what to do instead: a snapshot on connect, with stable ids
so the client can discard duplicates. An event published *during* the snapshot
query arrives both ways, and that is the safe direction to err in.
