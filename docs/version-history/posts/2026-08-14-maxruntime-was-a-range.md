---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - realtime
  - mcp
---

# `maxRuntime` was a range, and it read like a number

An SSE stream configured to run for 95 seconds ended somewhere between 95 and 115, and *where*
depended on how busy the channel was. A client using the same period for its own reconnect
therefore lost the race — **and lost it exactly on the busy installs**.

It now ends at 95, and the stream tells the client when to hand over.

<!-- more -->

## The range

A driver checks the deadline at the top of its loop and then blocks for `readTimeout` seconds
(`readTimeout = max(1, pingInterval)`):

```php
while (true) {
    if ($deadline !== null && time() >= $deadline) {
        break;
    }
    $entries = $connection->xRead($cursors, 0, $options->readTimeout * 1000);
    …
}
```

A deadline that falls *during* a read is not noticed until that read returns, so the stream
ended in `[maxRuntime, maxRuntime + pingInterval]`:

| Channel | Deadline noticed | Close landed at |
|---|---|---|
| Busy — an event arrives just after the deadline | on that event | ≈ `maxRuntime` |
| Idle — nothing arrives | at the next read timeout | up to `maxRuntime + pingInterval` |

`RedisStreamDriver`, `RedisDriver` and `DatabaseDriver` all had it.

## Why a range was worse than a wrong number

A client doing an **overlapping reconnect** — open the replacement, retire the old one once the
replacement proves itself — must hand over before the server closes, and had only `maxRuntime`
to go on. The obvious reading of the parameter gives you the client's period, and the obvious
reading is the one that loses:

- the client's clock starts at `open`, strictly **after** the server started its own;
- at equal periods the server therefore leads by however long the connection took;
- and it wins on the **busy** installs, where the close lands at the bottom of the range.

The failure is quiet, which is the worst part. The scheduled close arrives as a transport error,
the client backs off, everything recovers — so it presents as an occasional network blip that
gets worse under load. One consumer ran a 95-second client timer against `maxRuntime: 95`, under
a comment claiming it was *"ahead of"* the ceiling, and survived only because that panel's
channels were quiet enough that the server usually took the top of the range.

## The fix, in two parts

**The last read is clamped.** `SubscriptionOptions::blockingWindow($deadline)` returns
`min(readTimeout, deadline - now)`, never less than 1, and every blocking driver uses it. The
stream ends **at** `maxRuntime` regardless of traffic — which is what the parameter always read
like. One implementation rather than three, and a test that reads all three drivers' source and
fails if any of them goes back to the raw timeout, because the way this regresses is somebody
fixing one and not the others.

**The stream says when to hand over.** A client should still leave itself a margin, and now it
does not have to guess a constant it cannot see:

```js
source.addEventListener('stream-info', (e) => {
    const { max_runtime, ping_interval, handover_after } = JSON.parse(e.data);
    setTimeout(() => beginHandover(), handover_after * 1000);
});
```

`handover_after` is `maxRuntime` minus a tenth of it, bounded to 2–10 seconds, and never more
than half the runtime — so a four-second stream still advises something sane instead of
"reconnect immediately". Sent as its own event, so a client that has never heard of
`stream-info` is unaffected: `EventSource` dispatches by name. An unlimited stream sends none,
because there is no handover to schedule.

The filing offered the docblock alone as its cheapest option. Both of the others were cheap
too, and a number that is a number needs less documenting than a range that has to be explained.

## Two MCP tools that answered with errors

Reported and confirmed in the same round: of the five tools `mcp:serve` advertises, two could
not answer. **Neither failure was visible from outside** — the server starts cleanly, lists all
five, and both failures arrived as ordinary results with an `error` key or a database message
inside `content[0].text`, so `isError` was `false` on both. That is a shape worth remembering:
an MCP tool that returns an error *as a result* is invisible to anything watching for failures.

**`route-list` returned `{"error": "No router available"}` on every call.** The MCP server is
launched by `mcp:serve`, so the application behind it is the **console** kernel, which never
builds a router — routing is an HTTP concern. That branch was not a defensive fallback; it was
the tool's entire behaviour on its only reachable path.

It now builds a router and discovers `#[Route]` attributes, which need no HTTP request — that
being the point of attributes. The directories come from the PSR-4 map in the project's own
`composer.json` rather than an assumed `src/Controllers`, because that map is what the
autoloader uses. Routes registered inside a `routes.php` that **dispatches** at the end still
cannot be listed — including that file would serve a request rather than describe one — and the
error now says so, along with where it looked.

**`query-schema` returned `ERROR: column "conname" does not exist`.** `conname` is a
`pg_constraint` column and the query reads `information_schema`; it is `tc.constraint_name`.

There was already a test asserting that query used `information_schema.table_constraints`, and
it passed: it checked the right **table** and never the **column**, which is exactly how the
wrong column survived it. There is now one that checks the column, and refuses a bare `conname`.

## Also, from running a subset

`--filter ForeignKeyGuardMigrationTest` failed on its own with
`relation "users" does not exist`, while the full suite passed — the class alters `users` and
never created it. It creates a minimal one when absent now, and never drops it, because other
classes own richer versions.

That is the third order-dependency this work has turned up, and the reason to keep fixing them
is unchanged: **the point of running one class is to narrow down a failure, and narrowing must
not change the answer.**

## Fixed

- Drivers clamp their last blocking read, so a stream ends at `maxRuntime` rather than up to
  `pingInterval` seconds later. See the
  [Realtime guide](../../Pramnos_Realtime_Guide.md).
- `route-list` discovers attribute routes instead of reporting that the console has no router.
- `query-schema` selects a column that exists in `information_schema`.
- `ForeignKeyGuardMigrationTest` no longer depends on another class having created `users`.

## Added

- A `stream-info` event carrying `max_runtime`, `ping_interval` and `handover_after`.
- `SubscriptionOptions::blockingWindow()`.
