---
date: 2026-08-21
categories: [Changelog]
---

# The descriptor ceiling has a second consumer

`descriptorCeiling()` shipped so the WebSocket server could warn before `select(2)` stops serving
everybody. An application promptly borrowed it for its own loop — and was re-deriving the 90%
itself.

<!-- more -->

## Added

`LocalBroadcastServer::isNearDescriptorCeiling(int $watched): bool`, and the server's own warning
now goes through it, so there is one definition of "close" rather than one per application.

Requested by a consumer that had already adopted the ceiling for a feed worker multiplexing 58 SSE
sockets and one WebSocket, with the observation that made it worth adding: computing 90% of a
literal `1024` locally *"would stop agreeing the day PHP is rebuilt"*.

Their measurement is also the argument against the obvious alternative. 58 feeds cost 69
descriptors — stable, about 7% of the ceiling — so a cap was their first instinct and measuring
said no: it would have added feeds silently unlistened to guard against something ten times away.
A warning is the right instrument at that distance; a cap is not.

It lives on `LocalBroadcastServer` rather than somewhere more neutral because the sibling already
does and a consumer has already reached for it. Moving both would be tidier and would break them.

## Documentation

The realtime guide, under *Running more than one daemon*, now says the cliff applies to an
application's own `stream_select()` loop too, and to count everything in the set rather than only
the interesting sockets.
