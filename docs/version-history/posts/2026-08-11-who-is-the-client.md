---
date: 2026-08-11
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - http
  - security
  - cache
  - middleware
---

# The rate limiter counted the proxy, and counted it badly

Behind a reverse proxy every visitor shared one bucket, so the limit fired for
everybody at once. Reading `X-Forwarded-For` to fix that would have been worse:
the header is written by the client.

<!-- more -->

## Added

**`Request::clientIp()` — one answer to "who is the client".**

The framework had no answer. It had seventeen inline reads of
`$_SERVER['REMOTE_ADDR']` with four different fallbacks (`'0.0.0.0'`, `''`,
`'none'`, `'unknown'`), and three places that read `CF-Connecting-IP` with no
check at all.

`REMOTE_ADDR` is the connecting peer. Behind a proxy, a CDN or a load balancer
that peer *is* the proxy — one address for the whole world. A per-IP rate limit
becomes a global one, and anything binding to the address binds every visitor to
the same value.

The obvious repair is a total bypass, which is the interesting part. A client
that sets a fresh random `X-Forwarded-For` on every request gets a fresh bucket
every time and defeats the limiter completely — while the logs show a healthy
spread of addresses and the limiter reports that it is working. A control that
appears to work and does not is worse than no control at all.

So a forwarding header is believed only when the peer that delivered it is
itself a trusted proxy, and the chain is walked **from the right** — the end the
infrastructure appended — taking the first address that is not a trusted hop.
The leftmost entry is the client-supplied end of the chain and is never trusted.

```php
// app.php — nothing is trusted by default
'trusted_proxies' => ['private_ranges'],
'trusted_proxies' => ['cloudflare'],
'trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32', '192.0.2.7'],
```

With the list empty the answer is `REMOTE_ADDR`, unchanged. That is both the
safe default and the previous behaviour, so an application that does not opt in
sees no difference.

`X-Real-IP` is deliberately not consulted: it is single-valued, so there is no
chain to walk. `Forwarded` (RFC 7239) is understood, including its obfuscated
`for=_hidden` identifiers, which are dropped rather than used as an address.

**`Cache::increment()` — an atomic counter.** Redis `INCRBY` and Memcached
`increment`, with `supportsAtomicCounter()` to ask first. The Memcached adapter
had no counter at all; it creates one through `add`, which is atomic, so a race
to create it loses no increments either way.

**`TooManyRequestsException`.** An `\Exception` with code 429 — so every
existing handler is unaffected — that carries a `Retry-After` value.

## Fixed

**The sliding window undercounted exactly when it mattered.** `RateLimitMiddleware`
did load → filter → count → append → save with no lock and no compare-and-set.
Two concurrent requests both read the same list, both append, and the second
save overwrites the first. Under a burst of N simultaneous requests the stored
count could advance by as little as 1 — and a flood is concurrent by definition,
while the slow trickle it counted perfectly is the case nobody needed protection
from.

Where the cache can count atomically the limit is now a fixed window on the
server's own counter, which is exact under concurrency. The trade is at the
window boundary: up to 2× the limit can pass across one. For a spam gate that is
the better bargain.

Where it cannot — the Array and File adapters — the sliding window remains, and
`RateLimitConcurrencyTest` **measures** the loss rather than asserting it away.
The docblock says the count is approximate there. Documented slop is defensible;
silent slop in a security control is not.

`ThrottleMiddleware` had the same shape and now counts through `apcu_inc()`,
which creates and increments in one operation. An application that overrode its
storage seams keeps its own behaviour — routing around a subclass's storage
would be a worse bug than the race it fixes.

**A dropped Redis connection no longer means no limit.** `increment()` returns
`false` for "the counter did not work", which is not zero. Reading it as an empty
bucket would open the door at the moment the site is under strain.

**`Retry-After` goes through the response.** Both limiters emitted it with a bare
`header()` call — invisible to anything inspecting or buffering the response, and
silent in CLI and tests. It is now carried on the exception and set by
`ExceptionHandler::render()`, and it says how much of the window is actually
left rather than always the full length.

**`CF-Connecting-IP` was trusted unconditionally in three places** —
`User\Token`, `SessionTrackingMiddleware` and the `System\Session` addon — so any
client could dictate the address written into its own session and token records.
Those records read as evidence. They now go through the resolver.

!!! warning "Installations behind Cloudflare must configure this"
    This is the one change that needs action. An application relying on the old
    unconditional `CF-Connecting-IP` read will record the Cloudflare edge address
    instead of the visitor's until it sets `'trusted_proxies' => ['cloudflare']`
    in `app.php`. Nothing breaks, but the addresses in new session and token rows
    are wrong until it does.

    `ClientIpResolver::CLOUDFLARE_RANGES` is a snapshot of the published list and
    does change; an installation that cares should pin its own copy.
