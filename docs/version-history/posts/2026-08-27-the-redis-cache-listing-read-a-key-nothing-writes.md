---
date: 2026-08-27
categories: [Changelog]
---

# The Redis cache listing read a key nothing writes

Found by giving a project a working Redis. The moment the cache stopped silently falling back
to files, the dashboard showed no namespaces at all.

<!-- more -->

## Fixed

**`RedisAdapter::getCategories()` reads the category index the adapter actually maintains.**

It read a `memcachedtags` JSON blob — and nothing has ever written that key. Three adapters
read it (`Redis`, `Memcache`, `Memcached`); no code anywhere sets it. So on Redis the method
always answered `[]`, `getStats()` always reported **0 categories**, and the cache dashboard
showed an empty namespace list beside an item count in the dozens. Not an empty cache: a
listing that could not see one.

The real index was in the same file all along. `save()` writes a `catindex:<category>` set
and a `catindexed:<category>` marker per category, and `clear($category)` already trusts
them. Enumerating the markers means the listing and invalidation now read the same source of
truth, rather than two that can disagree.

**The item count was about Redis, not about the cache.** It was `dbSize()`, which counts the
whole database — sessions, queue payloads, another application's keys, and the adapter's own
bookkeeping (two keys per category). It also subtracted one for the `memcachedtags` key,
which does not exist. It now counts the entries under the cache's own prefix. That costs a
`keys()` scan where `dbSize()` was O(1), and the trade is deliberate: the screen it feeds
cannot list entries without a scan anyway, and it is an authenticated dashboard somebody
opens occasionally rather than a request path.

## How it stayed invisible

`Cache` falls back to files when the configured backend is unreachable, and it *reports* the
fallback — the DevPanel line reads *"file fell back from redis"*. An application configured
for Redis whose PHP image has no `redis` extension therefore runs on files, passes every
test, and behaves differently in production.

Its own test made it worse rather than better: it **wrote the `memcachedtags` key by hand**
and asserted `getCategories()` read it back. A green test proving the reader worked against a
fixture production never produces. Both tests now go through `save()`, which is the only way
the question means anything, and a second one pins that an empty cache reports an empty list
— the distinction the old fixture could not make.

## Documentation

- `Pramnos_Cache_Guide.md` — a new *What a listing can and cannot see, per adapter*, with
  what each backend can enumerate and what a silent fallback hides.
