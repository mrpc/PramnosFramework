---
date: 2026-08-20
categories: [Changelog]
---

# A socket we could serve but not listen to

The realtime stack was complete in one direction. `LocalBroadcastServer` **is** a WebSocket
server and `PusherDriver` **publishes** to one — both of them the framework talking. Nothing
in `src/Pramnos/` opened an outbound WebSocket and read what somebody else was sending.

<!-- more -->

## Added

`\Pramnos\Http\WebSocketClient` — RFC 6455 transport, and nothing above it.

The case that found the gap: a directory of 2,251 radio stations reads what each one is
playing by polling its status page. Two providers in that catalogue would rather push, and one
of them pushes over a Reverb socket — **nine track changes for its entire network in ninety
seconds, at 350–430 bytes each, on one connection, no authentication**. The alternative is one
HTTP request per station per interval, permanently. The consuming application declined to
build it, and said why: 250 lines of handshake, masking and length forms is exactly the code
an application should never contain, and it had already paid for the length forms once — *«a
7-bit frame length made a working authorizer look like it was refusing»*, which in practice
means every payload over 125 bytes was misread. The events above are all in the 16-bit form.

**The caller keeps its own loop.** That decides the API, so the client is shaped like
`RedisSubscriberSocket` rather than like a typical client library: a worker multiplexing
sixty SSE reads, one WebSocket and a `.stop` sentinel in one process cannot use a client
that owns the loop or blocks in `read()`.

```php
$socket = new \Pramnos\Http\WebSocketClient('wss://example.test/app/key?protocol=7');
$socket->connect();

$read = [$socket->stream(), ...$otherStreams];
stream_select($read, $w, $e, 1);

foreach ($socket->read() as $message) {   // whole messages, [] when none
    handle($message);
}
```

Only `connect()` blocks. `read()` returns complete messages — never a fragment. A ping is
answered and not surfaced; a close surfaces as a closed socket, not as a message; client
frames are always masked, because an unmasked one is a protocol error the peer disconnects on.
`permessage-deflate` is declined by never offering it: declining costs a header we do not
send, while getting inflate wrong is silent corruption.

**The protocol above the transport stays out.** Pusher's `pusher:subscribe` exchange, its
`activity_timeout`, its channel auth belong to whoever is speaking Pusher — the same split as
`Client` and the APIs called over it. A `PusherClient` here would be a guess about one
provider; a WebSocket client is what every provider needs.

Two ceilings, not one: per frame, and per reassembled message. The second is the one that is
easy to miss — unlimited fragments that never set FIN grow a single buffer while no individual
frame looks suspicious.

## Fixed

Framing moved to `\Pramnos\Http\WebSocket\FrameCodec` and `MessageAssembler`, shared by the
client and `LocalBroadcastServer`. One implementation of the three length forms instead of one
per direction; the only asymmetry RFC 6455 imposes is *who* masks, which is one boolean.

Extracting it surfaced two faults in the server that had been there all along, both silent:

**It never read the FIN bit.** `parseFrame()` took the opcode from the first byte and ignored
`0x80` beside it, and the frame loop had no case for opcode `0x0`. A sender is free to split
any data message across a first frame, any number of continuation frames and a final one — so
a fragmented `pusher:subscribe` arrived as two halves, each an invalid JSON document, and the
subscribe was dropped. No error, no log line, and only against senders that fragment, which is
a property of the peer rather than of anything local.

**Completing the handshake discarded the read buffer.** Everything after the header terminator
is already a WebSocket frame, so a client that pipelined its first frame into the same segment
as its request lost it. The same client works whenever the kernel happens to deliver the two
writes separately, which is why nobody saw it.

A framing violation now closes the connection instead of being ignored. Once frame boundaries
are lost every later frame is misread too, so continuing feeds arbitrary slices of the stream
to the JSON decoder — plausible-looking garbage rather than an error.

Also: writing to a socket whose peer has gone now fails immediately and says so. A zero-length
write is ordinary backpressure on a non-blocking socket, so the write loop retried it — and on
a dead socket it retried until the timeout and then reported one. "Closed" and "slow" lead to
different fixes.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Consuming somebody else's WebSocket**, so one page now
covers both directions, and two `use_cases:` entries for the tasks that lead there.
