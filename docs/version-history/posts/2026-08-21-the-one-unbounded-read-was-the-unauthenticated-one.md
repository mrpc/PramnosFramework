---
date: 2026-08-21
categories: [Changelog]
---

# The one unbounded read was the unauthenticated one

Everything after the WebSocket handshake was bounded: 8 MiB per frame, a separate ceiling for a
reassembled message, and a framing violation closes the connection. The handshake buffer had no
ceiling and no deadline — and it is the read an unauthenticated peer controls.

<!-- more -->

## Fixed

| Limit | | On breach |
|---|---|---|
| `HANDSHAKE_HEADER_MAX` | 16 KiB before the header terminator | `431`, disconnect |
| `API_BODY_MAX` | 1 MiB declared `Content-Length` | `413`, disconnect |
| `HANDSHAKE_TIMEOUT` | 10 s in the `handshaking` state | `408`, disconnect |

`authorizeConnection()` runs *after* the headers are parsed, which cannot happen until the request
is complete — so a client that connects and writes without ever sending a blank line was appended
to for as long as it kept writing, on a connection that had not identified itself and could not. In
a single-process daemon that is not a slow client: reaching `memory_limit` is a fatal that takes
every connected client with it, and the supervisor then restarts a worker whose clients all
re-handshake at once.

Reported as a read of the loop rather than a measurement, by a project whose realtime daemon is
internet-facing by design — it advertises its host and port to every browser. That is the right way
round for this one.

**The body is refused, never truncated.** The HTTP API deliberately relies on accumulation, because
a body that has not fully arrived would be signed-but-truncated and `body_md5` would reject it as
tampering. So the fix cannot cut: the check is against the *declared* length, the moment the headers
are complete, before a byte of the body is buffered.

**Two numbers, not one.** A batch of events is legitimately larger than a header block, so one limit
cannot serve both.

**The deadline is separate from both**, because size does not cover it: a peer that sends one byte
and stops is under every ceiling and would hold a slot in `$clients` for ever, since nothing ages
out an unfinished handshake. It lives in the keepalive sweep, which already walks the clients. An
established connection is never touched by it, however long it has been connected — applying it
there would disconnect every long-lived subscriber, which is every subscriber.

## What the filing asked for that turned out not to be needed

A ceiling for a body with **no** `Content-Length`. Writing the test for it showed the guard was
unreachable: nothing waits for such a body, so it is dispatched on the read that completed the
headers and cannot accumulate across reads — and it cannot be signed either, so the signature check
refuses it a moment later. The dead guard is gone and the property is asserted instead, because
"unbounded growth is impossible here" is exactly the kind of claim that stops being true when
somebody makes the API wait for an undeclared body.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Limits on the pre-authentication read** under the WebSocket
transport.
