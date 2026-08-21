---
date: 2026-08-21
categories: [Changelog]
---

# A driver the guide offered and the container did not

`Pramnos_Realtime_Guide.md` has listed `redis-stream` among the backplane drivers for months —
*"use this one for SSE, where every reconnect opens a gap"*. The service provider never registered
it. So `default => 'redis-stream'` threw, was caught by the fallback, and the application ran on
the **null** driver: every broadcast discarded, with one log line.

<!-- more -->

## Fixed

`RedisStreamDriver` is registered on the same terms as `RedisDriver` — unconditionally, since the
connection is opened lazily. An application that followed the guide now gets the driver the guide
described.

The fallback log line also names the available drivers now. Falling back rather than throwing keeps
a misconfigured application bootable, which is the right trade — but the symptom is an application
that broadcasts nothing and the cause is usually one character (`redis-streams`), so the list is
the cheapest possible shortcut to the diagnosis.

## Fixed

**`broadcast:serve` now pairs the ingest with the driver instead of hard-coding pub/sub.**

```
Redis ingest: app:chat.updates via XREAD (backplane: redis-stream)
```

This is the rule the guide states twice, applied here rather than left to the operator: a
`SUBSCRIBE` on a key that only ever receives `XADD` is a perfectly healthy subscription that is
never delivered anything — no error, no warning, no events. Hard-coding pub/sub meant an
installation that wanted SSE replay, and therefore the stream driver, **could not use the shipped
command at all.**

That is not hypothetical, and it is the second half of the same story as the driver above. A
consuming project reported its divergence from the shipped wiring as deliberate — `XADD`/`XREAD`
rather than `PUBLISH`/`SUBSCRIBE`, for the SSE replay window — noting that the shipped pairing
would not have protected them. It would not have, because there was no way to get the shipped
pairing they needed: the driver was unregistered and the daemon only spoke pub/sub. They wrote
their own daemon, correctly.

The banner names the primitive **and** the backplane, because the failure it prevents is silent: an
operator reading only the channel list cannot tell a working ingest from one subscribed to a key
nothing publishes to.

Cluster gossip follows the same driver, for the same reason one layer down — gossip published with
`XADD` and read with `SUBSCRIBE` gives a cluster where every node believes it is alone.

Wiring `LocalBroadcastServer` yourself is still your pairing to get right; `useRedisIngest()` takes
either implementation and always did.

## Documentation

The driver list now says `redis-stream` is selectable and admits it was documented before it was
registered. The pairing section notes that the shipped daemon does it for you, and that a project
which wrote its own daemon over this may no longer need to.
