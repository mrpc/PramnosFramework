---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - cache
  - redis
  - console
---

# `cache:clear` no longer wipes every installation sharing the backend

The cache adapters prefix every read and every write. `clear()` — the one
operation where the damage is largest — ignored that prefix and flushed the whole
database. Since `cache:clear` runs on most deploys, every release quietly emptied
the sessions, rate limiters and settings caches of every co-tenant sharing the
server.

<!-- more -->

## The path

```
cache:clear → CacheClear::clearCache('') → Cache::clear('') → redis->flushDb()
```

The category path did it correctly — `prefix + category + '_*'`. Only "clear
everything" threw the prefix away. A subsystem that defines an isolation rule and
then breaks it itself, in the single case that matters most.

Worse: at least one deployment had chosen `cache:clear` **over**
`redis-cli FLUSHDB` precisely because FLUSHDB ignores prefixes. The replacement
did the same thing, one layer down.

## What it does now

`clear('')` sweeps `prefix*` with `SCAN` and deletes in batches of 500. `KEYS`
walks the entire keyspace in one blocking pass, stalling every other client on a
large database, so the category path was moved to the same sweep — that is its
only change.

With **no prefix** there is nothing to scope to, so flushing remains the correct
meaning of "clear everything" — logged, because at that point it genuinely is
global.

`SCAN`+`DEL` is slower than `FLUSHDB` on a large keyspace. That is the trade:
clearing is not a hot path, and destroying another installation's data is not an
acceptable optimisation.

## Asking for a global flush

It is still available, by name:

```bash
./myapp cache:clear --all      # flush the ENTIRE backend, co-tenants included
```

`Cache::flushEverything()` and `Adapter::flushEverything()` back it. `--all` and
`--category` are refused together, since they ask for opposite things.

## The other adapters

The report asked whether this is a Redis problem or a contract problem. It is the
contract:

- **Memcached** — `flush()` empties the whole server too. It cannot enumerate
  keys, so a prefixed installation now clears the category indexes the adapter
  already maintains; what that misses expires on its own rather than being taken
  from someone else.
- **Memcache** (legacy) — cannot enumerate or delete by prefix at all. A prefixed
  `clear('')` now refuses and logs why, pointing at `flushEverything()`. Refusing
  is worse than working, and much better than silently destroying a neighbour's
  cache.
- **File** — already correct: it scopes to the prefix directory.
- **Array** — per-process, so its whole store is its own.

## Tests

`RedisAdapterTest`, against a real Redis:

- two prefixes in one database — `clear('')` on one leaves the other's keys
  **intact** (verified to fail, taking the neighbour's data with it, when the
  old `flushDb()` is restored);
- unprefixed keys written by something else survive;
- an empty prefix still flushes globally;
- a category clear removes that category only, in that installation only;
- `flushEverything()` is still global;
- 1200 keys clear correctly, so the SCAN cursor advances and DEL batches.

`CacheClearTest` covers `--all` and its refusal alongside `--category`; the
Memcache and Memcached suites now assert the scoped behaviour they previously
pinned in its unsafe form.
