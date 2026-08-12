---
date: 2026-08-13
categories:
  - Changelog
  - Fixed
tags:
  - realtime
  - redis
  - broadcasting
---

# The stream driver had the gap it was built to close

`RedisStreamDriver` shipped yesterday to fix SSE losing events across a
reconnect. Its first integration test — against a live Redis rather than the
fake the unit tests drive — showed it had the same bug, in miniature, on every
single read.

<!-- more -->

The consume loop started each channel at Redis's `$` cursor, which reads as
"only what arrives from now on". That is what it means, but only for one read:
`$` resolves to *whatever is newest at the moment the read is issued*. So a loop
that blocks, times out, and re-issues the read with `$` again silently skips
everything published in between.

```php
$redis->xAdd('t:x', '*', ['a' => '1']);
$redis->xRead(['t:x' => '$'], 0, 300);   // blocks, times out, returns nothing
$redis->xAdd('t:x', '*', ['a' => '2']);  // published in the gap
$redis->xRead(['t:x' => '$'], 0, 300);   // → nothing. The entry is invisible.
```

Once per read timeout, for the lifetime of every subscription. The driver built
to close a gap had reopened it, smaller and more often.

## Fixed

"Start from now" is resolved to a concrete entry id — the stream's newest, via
`XREVRANGE`, or `0-0` when the stream is empty, which is right precisely because
an empty stream has no history to replay. Every subsequent read continues from
where the last one stopped, so there is no moment the cursor is not pointing at
something.

## Why the unit tests could not have caught it

They drive an injected fake, and the fake returned whatever the test scripted
regardless of the cursor — it could not model the one behaviour that mattered,
because that behaviour lives in Redis. The unit tests proved the cursor
arithmetic; only a server could answer what `$` actually means across two reads.

`tests/Integration/Broadcasting/RedisStreamDriverIntegrationTest.php` now covers
it against the live container: the envelope round trip, replay from a cursor
across a gap, no replay without one, trimming under `MAXLEN ~`, and an event
published mid-subscription arriving live — which is the test that failed.

The Realtime guide gains a note for anyone implementing
`SubscribableDriverInterface` over another log: a cursor has to be a fixed
point, not a moving one.
