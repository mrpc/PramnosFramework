---
date: 2026-08-24
categories: [Changelog]
---

# A page cache, and the two things its spec could not know

A consuming application arrived with a 420-line specification for a full-page
cache — written to replace about 140 lines of inline `if` in its own front
controller, with the requirements derived from the seven bugs that code had
actually produced rather than from imagination. Its inventory of what the
framework already provided was checked claim by claim and was accurate in all
nine.

Two things in it could not have been right, and both were about the framework's
own internals rather than about page caching.

<!-- more -->

## Added — `Pramnos\Cache\Page\PageCache`

Three files: the engine, `Http\Middleware\PageCacheMiddleware`, and a
`pagecache:purge` console command. The
[Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) is the reference; what
follows is what is worth knowing that is not simply "it caches pages".

**Every default is the one that caches less.** `enabled` is false, `statuses` is
`[200]` alone, a response carrying `Set-Cookie` is refused outright, and nothing
is served to a request presenting an authentication cookie or header. A project
that adds the middleware and writes no configuration gets a working site, not a
randomly shared one. The failure mode of a page cache is somebody else's account
page, and it is silent.

**The session is never consulted**, which is what lets `serveEarly()` answer
before the application boots — every rule reads the request and nothing else. The
cost is stated plainly in the guide: an application keeping logged-in state only
in `$_SESSION` has no cookie for the decision to see and must set a marker.

**Normalisation is where a page cache is won.** Tracking parameters are dropped
and what remains is sorted, so `?a=1&b=2` and `?b=2&a=1` are one entry and every
campaign link is not a permanent miss. The implementation this replaces keyed on
the raw query string; advertising traffic — the traffic a page cache exists for —
had a 0% hit rate, and anyone could fill the store by appending junk.

**Tags, because otherwise the only invalidation is the clock.** "The correction
appears within an hour" is why full-page caches get switched off after the first
urgent edit.

## Fixed in the spec — the stampede lock was not a lock

§6.5 asked for `FlatCache::increment()` as the lock behind
stale-while-revalidate, with `'store' => 'file'` as the default store.

The framework already says why that cannot work, in
`Cache::supportsAtomicCounter()`:

> Only the adapters backed by a server that implements an atomic increment can —
> Redis and Memcached. The File and Array adapters cannot… Probing for the method
> would report the File adapter as atomic, which is precisely the "looks like it
> works and does not" answer this method exists to prevent.

On the file store `increment()` is a load followed by a save. Under concurrency
every caller reads the same value and every caller believes it took the lock —
**at exactly the moment a stampede happens, which is the only moment the lock is
for.**

So the lock asks the store instead of assuming: `swap()` (Redis `GETSET`) where
the counter is atomic, and a `mkdir()` lock where it is not — the same primitive
this repository's own test runner uses, for the same stated reason. Both branches
are tested for the property that matters: five arrivals inside the stale window,
exactly one render.

## Fixed in the spec — the purge would have inherited a 268 ms traversal

§6.7's `purgeUrl()`/`purgeTag()` were to be built on the category machinery.
Measured yesterday: clearing one category is **268 ms**, because Redis `SCAN`
walks the whole keyspace whatever the `MATCH` says — `MATCH` filters what comes
back, not what is traversed. A per-record purge would cost the size of the
database.

The page cache therefore keeps its own indexes — a hash per tag and per URL,
holding their entry keys — over `FlatCache`, which writes keys verbatim rather
than through the category-mangling `Cache`. A purge reads that tag's members and
deletes them: the size of the tag.

This does not fix the underlying category flush, which is still
[written up as its own piece of work](../../Pramnos_Test_Suite_Performance.md).
It means the page cache does not depend on it.

## Also built — the static-file writer, with its measurement attached

§10 was marked second priority and explicitly not a v1 blocker; it is included.
`'writer' => 'static'` writes `index.html` and `index.html.gz` so a rewrite rule
serves them without PHP starting.

The spec's own §10.3 measured the benefit at ~1.3 ms/hit for that application and
concluded it was worth little there — which is right, and the honest reason is in
the guide: **the gain scales with the weight of the bootstrap being skipped, not
with the web server.** An application already using `serveEarly()` saves a
millisecond or two; one that boots fully before consulting the cache saves tens.

Three sharp edges are handled rather than documented away: a URL with a query
string is never written as a file (a rewrite rule cannot apply `ignoreQuery`, so
it would serve the clean page for `?page=2`); files are written to a temporary
name and renamed; and purges remove the static twin, without which the rewrite
keeps serving the file the purge reported having removed.

A URL that decodes to contain `..` writes nothing — a page cache that turns
request paths into filesystem paths is a directory traversal waiting to happen,
and the check belongs in the writer rather than in whatever calls it.

## Tests

105, over four files. The weight is deliberately on the refusals — the cases
where the right answer is *not* to cache — because those fail silently and stay
failed: the `Set-Cookie` response, five spellings of an authentication cookie,
six HTTP methods, five statuses, the private marker, the header whitelist, and
three traversing URLs.

Coverage: `PageCache` 95.8%, `PageCachePurge` 95.2%, `PageCacheMiddleware` 100%.
What remains uncovered is the Memcached and Memcache adapter construction —
neither extension is loaded in the container — and two `defined()` branches.
