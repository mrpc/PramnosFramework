---
date: 2026-08-21
categories: [Changelog]
---

# A `{@see}` pointing at a class that was never written

`WebSocketClient`'s docblock referred readers to a `\Pramnos\Broadcasting\PusherProtocolClient`
for the Pusher protocol layer. That class was described in the guide, planned, and never built.

<!-- more -->

## Fixed

The reference is gone; the docblock now says what the Pusher handshake actually involves — send
`pusher:subscribe` once `pusher:connection_established` arrives, and answer `pusher:ping`, which is
the *application-layer* ping and distinct from the protocol ping the transport already handles —
and points at the guide section that spells it out.

Reported by a consuming project that had adopted the client the day it shipped, went looking for
the named class, did not find it, and then swept all 489 files in `src/` to establish that it was
the only dangling reference. It was. The cost was somebody else's afternoon and the fix was one
docblock.

## Added

`tests/Unit/Docs/SeeReferencesResolveTest.php` — every fully-qualified `{@see \Some\Class}` in
`src/` must name something that exists.

Same class of problem as the docs-retrievability test and here for the same reason: **it is not
visible in the diff of a single change.** A reference can point at something planned and never
written, or since renamed, and nothing fails — it reads as authoritative right up until somebody
goes looking. A consumer should not be the mechanism that discovers it.

Only fully-qualified references are checked. A relative `{@see Drivers\Foo}` resolves against the
namespace of the file it appears in, and doing that properly means parsing `use` statements —
worth adding if relative references ever cause the same problem, and not worth pre-empting.

There is a second test asserting the check can actually fail. A guard that cannot fail is the thing
it is guarding against, one level up.
