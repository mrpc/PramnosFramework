---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - realtime
  - broadcasting
---

# The ingest dropped the id it had just read

`RedisStreamSocket` read each entry's id, used it to advance its cursor, and threw it away. So a
WebSocket worker could not tell **when** an event was published — while an SSE stream could, from
the same backplane, because `SseWriter::stream()` has always passed the id to `onEvent`.

The interesting part is why that stayed invisible, and what it made impossible.

<!-- more -->

## The asymmetry

```php
// SSE — the id arrives
$sse->stream($driver, $channels, function ($channel, $event, $payload, $w, $id) { … });

// WebSocket — it did not
$server->useIngestRouter(function ($channel, $event, $payload) { … });
```

Same events, same Redis stream, two transports, and only one of them could date what it received.

## Why it stayed invisible, and why that is the dangerous part

An ingest with no cursor starts at `$` — new entries only. A worker that never replays never sees
an old event, so nothing is ever stale and the missing id costs nothing.

**Persisting `cursors()` is exactly the change that breaks that**, and persisting them is the
whole advantage of reading a stream rather than subscribing to one: a worker restarted mid-deploy
with `SUBSCRIBE` misses everything published while it was down, while one reading from its last id
is handed the gap. The framework documents that as the reason to use it.

So the two were mutually exclusive. Turn on the feature the guide recommends, and every WebSocket
client at once — the transport most listeners are on — receives the deploy window as fresh events.

For a durable event that is correct and desirable. For an **ephemeral** one it is not, and the
reporting application had the case that makes it concrete: a typing indicator carries no timestamp
of its own, and a chat client naturally sets its state from receipt time. A replayed cue announces
that somebody is typing who stopped minutes ago.

## The fix

The entry id travels with the message, and the router receives it fourth:

```php
$server->useIngestRouter(
    function (string $channel, string $event, $payload, ?string $id = null): array {
        if ($event === 'typing' && $id !== null) {
            $publishedAt = (int) explode('-', $id)[0];   // "<ms>-<seq>"
            if ($publishedAt < (int) (microtime(true) * 1000) - 10_000) {
                return [];   // too old to mean anything
            }
        }

        return [[$channel, $event, $payload]];
    }
);
```

**Passed last and defaulted**, exactly as the filing asked, so a router written with three
parameters keeps working — PHP does not object to an argument a closure has not declared.

`null` rather than an empty string when the ingest has no notion of one: `RedisSubscriberSocket`
is pub/sub and has no entry ids, and a router must be able to tell "no such thing here" from a
position it might mistake for one. There is a test for each.

## What this cost to find, and what found it

Nothing in the framework's own tests would have caught it. The ingest was returning a correct
`['channel' => …, 'message' => …]` envelope; nothing was broken, an id was simply absent from a
structure that had never carried one.

What found it was an application asking for a feature it could not have — cursor persistence —
and working out *why* it could not have it. The report arrived with the mechanism, the invisibility
condition, and the exact signature to add. Worth recording as a shape: **the most useful bug
reports are about the thing you could not build, not the thing that broke.**

## Fixed

- `RedisStreamSocket::drain()` includes each entry's `id` in the message it returns.
- `LocalBroadcastServer` passes it to the ingest router as a defaulted fourth argument.
- `RedisIngestInterface` documents the field as optional, so an implementation without ids stays
  valid.
- [The Realtime guide](../../Pramnos_Realtime_Guide.md) shows the ephemeral-event case beside the
  cursor-persistence advice that used to conflict with it.
