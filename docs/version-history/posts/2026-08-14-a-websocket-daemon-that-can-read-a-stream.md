---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - realtime
  - broadcasting
  - redis
---

# A WebSocket daemon that can read a Redis stream

SSE gained replay when `RedisStreamDriver` landed: `id:` frames, `Last-Event-ID`,
and a driver that can hand a reconnecting client the events it missed. Using it
turned out to be blocked by one class — the WebSocket daemon could not read a
stream at all, and said nothing about it.

<!-- more -->

## The silence

An application with both transports has two consumers of one backplane. SSE reads
through `SubscribableDriverInterface`, which the stream driver implements. The
WebSocket server cannot: it runs a single-threaded `stream_select()` loop, so it
uses a raw RESP socket — and the only one that existed issued `SUBSCRIBE`.

`SUBSCRIBE` on a key that only ever receives `XADD` is a **perfectly healthy
subscription that is never delivered anything**. No error, no warning, no events.
So the choice was:

- publish with `RedisDriver` → the daemon works, SSE loses its reconnect window;
- publish with `RedisStreamDriver` → SSE replays perfectly, the daemon receives
  nothing.

The only way out was to publish every event twice, which puts two representations
of one event on the backplane — the thing a driver abstraction exists to prevent.

## `RedisStreamSocket`

The same shape as the pub/sub socket, issuing `XREAD BLOCK 0` instead. That is one
command whose reply arrives when an entry does, which is exactly the property a
select loop needs.

```php
use Pramnos\Broadcasting\RedisStreamSocket;

$server->useRedisIngest(new RedisStreamSocket($redisConfig, ['app:chat'], $lastIds));
```

`useRedisIngest()` now takes the `RedisIngestInterface` both implementations share,
so every existing call still type-checks and the choice of ingest follows the
choice of driver instead of being independent of it.

The driver's `envelope` field is passed through unchanged, so the server's fan-out
cannot tell which transport brought the event. An entry written by something else
is handed over as a JSON object of its fields rather than dropped.

## The cursor is the bonus

A subscription has no position; a stream read does. A worker restarted mid-deploy
with `SUBSCRIBE` misses whatever was published while it was down. `cursors()`
returns the last id read per stream — persist it, hand it back as the
constructor's third argument, and the restart costs nothing. Absent a cursor,
reading starts at `$`: new entries only.

Cursors survive `close()` too, because a caller that closes in order to reconnect
wants to carry on where it was.

## Also

`RedisSubscriberSocket`'s docblock now says it is **pub/sub only**, and what
pairing it with the stream driver produces. That sentence would have saved reading
the RESP framing to find out.

## Documentation

- [Realtime Guide](../../Pramnos_Realtime_Guide.md) — "The ingest has to match the
  driver", with the pairing table and the cursor.
