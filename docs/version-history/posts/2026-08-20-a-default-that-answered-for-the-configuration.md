---
date: 2026-08-20
categories: [Changelog]
---

# A default that answered for the configuration

`Cache::getInstance()` declared `$method = 'memcached'`. The constructor reads the
application's `cache` setting first and then applies the argument — `if ($method != '')
{ $this->method = $method; }` — so the argument nobody passed overwrote the configuration
everybody set.

<!-- more -->

## Fixed

On an installation configured for Redis, with no memcached to connect to, a single request
answered three ways:

```php
new Cache();                          // {"method":"redis","items":10}
Cache::getInstance();                 // {"method":"file","items":0}
Cache::getInstance(null, null, '');   // {"method":"redis","items":10}
```

The middle one is what the application actually ran on. `initializeAdapter()` could not reach
memcached, walked down to memcache, then to the file adapter, and the process ended up with a
private on-disk cache that shared nothing with the Redis store the rest of the application was
using. Nothing errored. The guard immediately below, `if ($this->method == '') { $this->method =
'memcached'; }` — the branch meant to catch "nothing was configured" — could never be reached,
because the signature had already answered.

It hit every caller without an opinion, which is every caller that should have one:
`CacheServiceProvider::register()`, whose docblock says it warms the singleton "so it picks up
the current Settings values"; `Factory::getCache()`; `View::cache()`; the four SQL-cache entry
points in `Database`, which resolved the setting themselves and then defaulted to `'memcached'`
when it was absent; and the DevPanel's Cache screen, the one place that exists to show what the
cache holds, which printed the item counts of an empty file store. Because `getInstance()` keys
its instances per category, the first caller in the process — the service provider — made that
file store the shared one.

`$method` now defaults to `''`, which the constructor already understood as "whatever is
configured". Passing a method still wins, so nothing that named a backend changes.

## Added

Two things that would have made the above visible on the day it started, rather than in a
diff:

**A downgrade is logged at `warning` level**, once per process per transition, whether the
adapter was abandoned because it could not connect, because its extension is missing, or
because the method name was not recognised at all:

```
Cache: falling back from "redis" to "memcached" - could not connect to 127.0.0.1:6379.
```

A cache that silently changes store is a bug with no symptom of its own — a value written to
Redis and read back from disk is indistinguishable from an expiry, and the application keeps
answering, from a per-process cache it believes is shared.

**`$cache->method` now names the store the instance ended up with**, following the fallback
chain instead of repeating the request, and `getStats()['method']` reports the same name.
They came from different places before: one from the requested method, one from the adapter
that was actually built, so after any fallback the DevPanel labelled a store with the name of
the one it had failed to reach. The original request is kept as `$cache->requestedMethod`, so
`$cache->method !== $cache->requestedMethod` is now the question "did a fallback happen", and
the legacy `_connect()`, which treats the method as a class name, still reads what it was
given.

`ArrayAdapter::getStats()` reported `'adapter' => 'array'` and no `'method'` key at all; it now
reports both, and `Cache::getStats()` fills the name in for any adapter that does not name
itself rather than passing on `AbstractAdapter`'s `'unknown'` placeholder.

## Documentation

`Pramnos_Cache_Guide.md` — when to pass a method and when to leave it out, the warning line
and how to read it, and the `method` / `requestedMethod` distinction.
