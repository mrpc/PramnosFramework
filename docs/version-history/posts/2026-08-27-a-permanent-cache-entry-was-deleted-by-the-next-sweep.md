---
date: 2026-08-27
categories: [Changelog]
---

# A permanent cache entry was deleted by the next sweep

`timeout = 0` means "never expires" — `save()` documents it and `load()` honours it. The
file adapter's expiry test did not, and it is what the garbage collector asks.

<!-- more -->

## Fixed

**`FileAdapter` reads `timeout = 0` as never expiring, everywhere.**

`checkIfFileIsExpired()` compared `filemtime($file) < time() - $details['timeout']`, with no
guard on the timeout. With a timeout of `0` that is true of every file written more than a
moment ago — so `cleanup()`, the sampled garbage collection, deleted exactly the entries a
caller had asked to keep permanently.

Sampled, which is what made it hard to attribute: a permanent value survives for a while and
then is gone, so it presents as "the cache does not work" rather than as a rule about zero.
`load()` had the guard (`$timeout > 0 && …`) all along, which is why the same value could be
readable and yet be deleted by the next sweep.

**And `getAllItems()` reports the seconds actually left.**

It returned `'ttl' => $isExpired ? 0 : -1` — a boolean widened back out into the field that
is supposed to carry a duration. Every live entry read as `-1`, "never expires", so the cache
browser's TTL column said nothing expires: the one thing that column exists to say, said
wrongly, on the screen an operator opens to find out when a value will be dropped. An entry
saved to be permanent was listed the other way round, as expired, in red.

The expiry was always computable — `filemtime + timeout` — and both readers now go through one
`remainingTtl()`:

| `ttl` | Means |
| --- | --- |
| a positive integer | seconds left |
| `-1` | never expires |
| `0` or negative | past its timeout — `expired` is true |
| absent (null) | the file is not a readable cache entry; it is never deleted |

## Documentation

- `Pramnos_Cache_Guide.md` — *Cache with Timeouts*, and a new *What `getAllItems()` reports*.
