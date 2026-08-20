---
date: 2026-08-20
categories: [Changelog]
---

# A default that named a store nobody installed

The [cache default fixed this morning](2026-08-20-a-default-that-answered-for-the-configuration.md)
was the one in `getInstance()`'s signature. There was a second one, in the constructor, and
it produced the same outcome from the other direction.

<!-- more -->

## Fixed

```php
if ($this->method == '') { $this->method = 'memcached'; }
```

On an installation with no `cache` section — `Settings::getSetting('cache')` returning
`false` — that is what decided the store. No memcached, so the chain walked down to
memcache, then to the file adapter, on a machine with Redis running and working. Reported
from a project in exactly that state.

**The default is now the first backend whose extension is present**, Redis first, then
memcached, then memcache, then file. Naming a store nobody installed is not a default, it is
an answer — and it also cost two pointless hops through adapters that could never load, each
of which (since fallbacks began logging this morning) wrote a warning about abandoning a
store nobody had asked for.

**A Redis cache with no connection details of its own now uses the framework's Redis.**
`REDIS_HOST` and friends are the documented way to configure Redis and
`\Pramnos\Redis\ConnectionManager` is what reads them — but this class read only its own
`cache` settings and otherwise assumed `localhost`. In a container stack, where Redis is a
service name, that is the difference between a working cache and a file. Adopted value by
value, so a `cache` section that names a hostname keeps it.

Measured on the reporting installation, with its `cache` settings removed entirely: before,
`requested=memcached resolved=file`, an empty store; after, `requested=redis resolved=redis`,
reading the 22 live entries the rest of the application had put there.

**The DevPanel's Cache tab shows both** — the adapter in use and what was configured, with a
badge when they differ. An installation running Redis and caching to disk used to look
exactly like an installation configured for disk.

**A failed Redis connect no longer writes two entries.** phpredis raises a PHP warning *and*
returns false; `ConnectionManager` already turns that into an exception naming the host and
port, and callers log it. The raw warning is silenced, so a cache falling back does not put a
second line in the log for a condition already reported — in the exact situation where the
log matters most.

## Documentation

`Pramnos_Cache_Guide.md` — a section on what happens when nothing is configured, and how a
Redis cache finds its server.
