---
use_cases:
  - Serving anonymous and crawler traffic from cache instead of re-rendering
  - Making a page uncacheable because it shows something personal
  - Invalidating a cached page after an editor changes the content behind it
  - Diagnosing why a page is never cached, or is cached when it should not be
  - Serving anonymous traffic from cache when the framework starts a session
  - Deciding between the PHP cache path and the static-file writer
---

# Page Cache Guide

`Pramnos\Cache\Page\PageCache` serves whole rendered pages back to anonymous and
crawler traffic, so the second visitor to a page does not pay for rendering it.

It is **off by default**, and every default it does have is the one that caches
less rather than more. That is deliberate: the failure mode of a page cache is
serving one visitor's page to another, and it is silent.

---

## Turning it on

Three things — a middleware in the pipeline, a `pagecache` block in `app.php`, and
a front controller that returns a `Response`.

```php
// app.php
'middleware' => [
    \Pramnos\Http\Middleware\PageCacheMiddleware::class,   // first
    \Pramnos\Http\Middleware\SessionTrackingMiddleware::class,
    \Pramnos\Http\Middleware\AuthMiddleware::class,
],

'pagecache' => [
    'enabled' => true,
    'store'   => 'redis',
    'ttl'     => 3600,
],
```

**Register it first.** The reason a page cache is fast is not the storage — it is
how little has run by the time it answers. A middleware that starts a session in
front of it has already spent most of what it saves.

With no `pagecache` block at all the middleware is inert: it looks nothing up and
stores nothing. Adding it to a pipeline can never, by itself, change what
visitors see.

**Where the block goes.** `app.php` is read first, then `Settings` — so a row in
the `settings` table works too, and `app.php` wins if both exist. `app.php` is the
better place: it is in the repository and reviewable, and reading it costs nothing,
where a settings lookup for a key the store does not hold falls through to a
database query the cache exists to avoid.

### The pipeline has to return a `Response`

`store()` needs a `Response` — something with a status, headers and a body to keep,
and something to hang `X-Pramnos-Cache` on. `Application::render()` returns a
**string**, so a front controller that ends with

```php
echo $pipeline->run($request, fn () => $app->render());     // nothing is ever cached
```

hands the middleware a string on the way back out, and a string is left alone: there
is no reliable body to store and guessing at one would cache half a page. Wrap it:

```php
$response = $pipeline->run(
    $request,
    fn () => \Pramnos\Http\Response::make((string) $app->render())
);
$response->send();
```

This is worth checking first, because the symptom is identical to a `pagecache`
block that was never read: no `X-Pramnos-Cache` header, nothing stored, no error.

---

## What gets cached, and what never does

A request is served from cache only if **all** of these hold. They are checked in
this order, cheapest first.

| # | Rule | Config |
|---|---|---|
| 1 | The cache is enabled | `enabled` |
| 2 | The method is safe | `methods` — default `GET`, `HEAD` |
| 3 | The host is one we cache | `hosts` — default: all |
| 4 | The path is in the allow-list, if there is one | `onlyPaths` |
| 5 | The path is not excluded | `bypassPaths` |
| 6 | No excluded query parameter is present | `bypassQuery` |
| 7 | **No authentication *or session* cookie is present** | `bypassCookies` |
| 8 | **No authentication header is present** | `bypassHeaders` |

`PageCache::bypass()` is checked as part of that list, so it applies to **both**
halves: a bypassed request is neither stored nor answered from the cache.

A response is **stored** only if the request passed all of them *and*:

- its status is on the list — `statuses`, default `[200]` only;
- it does **not** carry `Set-Cookie`;
- its body is not empty;
- its body contains no `privateMarkers` string;
- its body does not contain this response's **CSP nonce** — see below;
- the debug toolbar is not collecting — `skipWhileDebugging`, default `true`.

`Set-Cookie` is refused outright rather than filtered away, because a response
that is setting a session is per-visitor in its body too.

### The debug toolbar stops pages being stored

`Application::render()` injects the toolbar into the HTML it returns, and a front
controller that wraps that string in a `Response` hands the toolbar to `store()`
along with the page. Nothing in between can tell: `privateMarkers` is empty by
default and a toolbar sets no cookie.

What would be stored is one developer's SQL with its bound values, their timings
and the files that ran — then served to everyone who asks for the page next. So
the cache refuses while any collector is registered, which is the same condition
that decides whether a toolbar is injected at all.

`APP_DEBUG` is meant to be off in production, which bounds this and does not close
it: a staging environment with real data and the cache on is an ordinary thing to
have, and there the failure is silent.

```php
'skipWhileDebugging' => false,   // only to measure the cache with the toolbar on
```

Turn it off only somewhere the stored pages cannot reach anybody else.

!!! note "There is no toolbar on a cache hit"
    A hit returns a `Response` before the application runs, and
    `DebugBarMiddleware` only decorates string responses — so a hit carries no
    toolbar at all. That is the correct outcome and an easy one to misread as
    "debug is broken". `X-Pramnos-Cache` in the response headers is what tells you
    a hit happened.

### The session is never *started* — but the session cookie is read

Not starting one is what lets the cache answer before the application boots. Every
rule above reads the request and nothing else: method, host, path, query, cookies,
headers.

The cookie is enough. A request carrying one is a visitor the server already holds
state for, so **the session cookie is in `bypassCookies` by default**:

```php
'bypassCookies' => [
    '#^(auth|remember|logged)#i',
    '#^PHPSESSID$#i',            // session_name(), whatever yours is called
],
```

Serving that visitor a page stored for somebody else, or storing their response for
everybody, are both wrong, and the second is the dangerous direction. It used to be
possible: `#^(auth|remember|logged)#i` never matched `PHPSESSID`, so an application
whose signed-in state lives only in `$_SESSION` had nothing on this list to stop it
— and a signed-in response that sets no cookie is not caught by the `Set-Cookie`
rule either.

What makes this a workable *default* is `'session' => 'lazy'`: with it an anonymous
reader carries no session cookie at all, so bypassing on one costs no cache hits.
Without lazy sessions the framework set `PHPSESSID` on every response anyway and
`store()` already refused those, so this takes away no caching that was previously
happening.

**If you want signed-in visitors served from cache**, drop the pattern and lean on
`privateMarkers` or `varyBy` instead — but understand that you are then promising
that the page is byte-identical for everybody who can reach it.

### A cache hit and the CSP nonce

The framework sends a `Content-Security-Policy` whose default is `default-src
'none'`, with a per-response nonce (`Application::$cspNonce`) stamped into every
inline `<script>` and `<style>` it writes. Two things follow, and the second is the
one that decides whether your pages are cacheable at all.

**A hit still gets a policy.** `sendCspHeader()` is called from
`Application::render()`, which a hit never reaches, so a cached page used to go out
with **no policy at all** — a page that looks perfect and whose scripts run because
nothing is left to stop them. `PageCacheMiddleware` now builds a fresh policy and
attaches it to the hit. Nothing to configure.

**A page with a nonced inline script is never stored.** A stored body freezes the
nonce it was rendered with and hands that same one to every visitor for the whole
TTL — and a nonce that is reused is not a nonce; its entire property is being
unguessable per response. Replaying the stored header would be that bug; not
replaying it was the first one. So the two features are mutually exclusive for such
a page, and `store()` takes the side that fails loudly:

| | outcome |
|---|---|
| store the body with its nonce | one predictable nonce, shared for the TTL |
| store it and re-send a fresh policy | the policy matches nothing in the body |
| **refuse to store it** (what happens) | the page is simply never cached |

The way to make such a page cacheable is to render it without a per-response nonce:
move the script to a file, use a hash-based policy, or drop the inline script. That
is a decision only the application can make, which is why the framework will not
make it silently.

The symptom, if you have not read this far: `X-Pramnos-Cache: MISS` on every single
request to a page that passes every bypass rule. `whyBypassed()` returns `null` for
it, correctly — this is a property of the response, not of the request.

!!! warning "The static-file writer sends no policy"
    With `'writer' => 'static'` a rewrite rule serves `index.html` and **PHP never
    runs**, so nothing framework-side can send a header. Send the policy from the
    web server:

    ```apache
    Header always set Content-Security-Policy "default-src 'none'; script-src 'self'; …"
    ```

    A static policy is exactly what a server directive does well — and it is all
    that is needed, because the nonce half is provably absent from anything stored.
    [`serveEarly()`](#serving-before-the-application-boots) does send one; it reads
    the `csp` block from `app.php` for it.

---

## The key

Two requests share a cached page when they agree on: method (with `HEAD` folded
into `GET`), scheme, host, path, the **filtered and sorted** query string, and
the `varyBy` values.

```php
'ignoreQuery' => ['utm_*', 'fbclid', 'gclid', '_ga', 'ref'],   // default
```

Tracking parameters are dropped before keying, and what remains is sorted. Both
matter more than they look:

- `?a=1&b=2` and `?b=2&a=1` are one entry, not two.
- Every campaign link would otherwise be a permanent miss — advertising traffic
  is exactly the traffic a page cache is for — and anyone could fill the store by
  appending junk parameters.

If you know precisely which parameters matter, name them instead. `varyQuery` is
a whitelist and everything else is discarded:

```php
'varyQuery' => ['page', 'sort'],
```

**Logged-in and anonymous do not vary the key.** A logged-in request is never
served from cache and never stored, so one key means one public view; splitting
it would only create an entry nothing can reach. Crawlers share the anonymous key
too, and differ only in TTL.

### `varyBy` — when one URL really is several pages

```php
'varyBy' => [
    'country' => fn($request) => $_COOKIE['country'] ?? 'gr',
],
```

Each distinct value gets its own entry. Keep the set small: two values double the
storage and halve the hit rate, and a resolver returning something unbounded — a
user id, a timestamp — gives every visitor their own entry, which is a cache that
only ever misses.

---

## TTL

```php
'ttl'      => 3600,
'ttlRules' => [
    '/news*'      => 60,        // first match wins
    '/stations/*' => 7200,
],
'botTtl'   => 86400,            // crawlers only
```

`ttlRules` accepts globs or delimited regexes — `'/api/*'` and `'#^/api#'` both
work, because configuration gets written by people who think in each.

`botTtl` is worth setting. Crawlers are the traffic least harmed by a stale page
and the most likely to ask for pages no human has requested in hours.

---

## Refusing to cache, from inside the render

```php
use Pramnos\Cache\Page\PageCache;

if ($cart->hasItems()) {
    PageCache::bypass('cart is not empty');
}
```

Static, because the caller is a controller, a view or a model that has no
reference to the cache — the same reason WordPress uses a constant for this.

The alternative some implementations use — emit a marker into the HTML and grep
the finished body for it — also works, and answers after the whole page has been
built, in a string search over the entire document, and silently stops working if
the marker is ever reworded. `privateMarkers` is available for cases where you
cannot reach the render, but prefer `bypass()` where you can:

```php
'privateMarkers' => ['id="logout-link"'],
```

---

## Invalidation

Without tags the only invalidation is the clock, which means "the correction
appears within an hour". That is why full-page caches get switched off after the
first urgent edit.

```php
// while rendering
PageCache::tag('station:' . $station->id, 'homepage');
```

```php
// when the data changes
$cache = new PageCache($config);
$cache->purgeTag('station:7');
$cache->purgeUrl('https://example.test/stations/7');
$cache->flush();
```

From the command line:

```bash
./pramnos pagecache:purge /stations/7
./pramnos pagecache:purge --tag=station:7 --tag=homepage
./pramnos pagecache:purge --all
```

A bare path is resolved against the configured site URL, because entries are
keyed by absolute URL — one installation can answer on several hosts.

`purgeUrl()` removes **every** variant of that address, not only the one matching
the purging request. An invalidation that cleared one `varyBy` variant would leave
the others serving the old page to exactly the visitors who see them.

### Why tags cost what they cost

Each tag keeps an index of its own entry keys, so a purge reads that tag's
members and deletes them — the size of the tag.

The obvious alternative is asking the store for every key matching a pattern, and
on Redis that is a trap: `SCAN` walks the **entire keyspace** whatever the `MATCH`
says, because `MATCH` filters what comes back rather than what is traversed. A
per-record purge built that way costs the size of the whole database — measured
at 268 ms on a store that was not even large. See
[Test Suite Performance](Pramnos_Test_Suite_Performance.md).

---

## Stale-while-revalidate, and the lock

```php
'staleWhileRevalidate' => 30,
'lockTtl'              => 30,
```

When an entry expires, the first request past expiry re-renders and everybody
arriving in the next 30 seconds is served the old copy rather than piling onto
the same render.

**The lock is chosen by asking the store, not by assuming.** The framework's own
`Cache::supportsAtomicCounter()` exists because the File and Array adapters
implement `increment()` as a load followed by a save — under concurrency every
caller reads the same value and every caller believes it won. A stampede lock
built on that fails at the only moment it is for.

So there are two implementations:

| Store | Lock |
|---|---|
| Redis, Memcached | `swap()` — Redis `GETSET`, one server-side operation |
| File, Array | a `mkdir()` lock — atomic on Linux, macOS and WSL alike |

Setting `lockTtl` to `0` means a lock is never honoured and every arrival
re-renders. Only sensible if renders are cheap enough not to need protecting.

---

## ETag, gzip and the debug header

`etag` and `gzip` are on by default.

- A matching `If-None-Match` is answered with a **304 and no body** — the
  cheapest possible hit. The 304 carries the `ETag`, as RFC 7232 §4.1 requires.
  `If-None-Match` is parsed rather than compared: a list (`"a", "b"`), a weak
  validator (`W/"a"`) and `*` are all understood.
- The gzipped copy is built **once at store time**, not per hit, and served to
  clients that accept it. After the render itself this is most of the CPU a page
  cache saves.
- **With `gzip` on, `Vary: Accept-Encoding` is always sent** — on the compressed
  and the uncompressed response alike. One URL then has two bodies, and any
  shared cache in front of the application must be told, or it will store one
  variant and hand it to a client that asked for the other. A client that sent no
  `Accept-Encoding` receiving compressed bytes is the classic "broken for some
  people only" report, and it never reproduces locally.

    A `Vary` your application already sends is merged, not replaced. With `gzip`
    off nothing is added, because there is then one body per URL and a needless
    `Vary` costs hit rate in every cache downstream.

    If your web server already compresses — `mod_deflate`, `gzip on` — prefer
    `'gzip' => false` and let it do the work; it emits the correct `Vary` itself,
    and you avoid storing two copies of every page.

- `X-Pramnos-Cache: HIT | STALE | HIT-304` and `Age:` are sent while
  `debugHeader` is true. Leave it on — it is how you find out the cache is not
  working — and turn it off if you would rather not advertise the arrangement.

### When you need to know *why*

`'debugDetail' => true` adds three more:

| Header | |
|---|---|
| `X-Pramnos-Cache-Key` | the key the entry is actually stored under |
| `X-Pramnos-Cache-TTL` | its lifetime in seconds |
| `X-Pramnos-Cache-Expires` | when it dies, as an HTTP date |

The key is the useful one. When a page is not cached the way you expect, the
question is almost always *"under what key did it go in?"* — and with `ignoreQuery`,
`varyBy` and `varyQuery` all feeding it, not being able to see the key means
debugging by guesswork.

```bash
curl -sD- -o /dev/null 'https://example.test/directory?utm_source=x' | grep -i x-pramnos
```

**Off by default**, unlike `debugHeader`. `HIT` and `Age` are ordinary things for a
cache to say; the key is internal, and publishing it to every visitor hands anybody
probing for cache-key collisions the normalisation rules for free.

Headers rather than an HTML comment: a body is what snapshot tools diff and what a
search engine indexes, and debug information does not belong in a stored page.

---

## Serving before the application boots

```php
// www/index.php
define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

\Pramnos\Cache\Page\PageCache::serveEarly();   // hit ⇒ sends and exits
```

Safe this early precisely because the decision reads only the request. This is
where the large savings are: not the storage lookup, but everything that does not
run behind it.

**No argument.** It reads `app.php` itself — a `require` of an array literal, which
is not the bootstrap this method exists to skip: what it skips is `Application::init()`
and its database, session, language and theme.

It used to *require* the config as an argument, which meant the `pagecache` block had
to be copied by hand into `index.php` beside the one in `app.php`. Two declarations of
the same rules, and this is the one that answers first — change `bypassCookies` in
`app.php`, forget the copy, and the early path keeps serving a signed-in page to
everybody from a rule set that exists nowhere else. Passing a config still works and
still wins, for a caller that wants a different one here on purpose.

`ROOT` has to be defined before the call, which every scaffolded front controller
already does on its first line. `APP_PATH` is used instead when it is defined.

**The hit carries a CSP.** Reading the file gets the `csp` block in the same breath,
so this path is no longer the exception — see
[a cache hit and the CSP nonce](#a-cache-hit-and-the-csp-nonce). If there is no
`app.php` to read, no policy is sent rather than a guessed one: the framework default
is `default-src 'none'`, and sending that to an application that needed hosts in its
`csp` block would break the page it was meant to protect.

---

## The static-file writer

```php
'writer'     => 'static',
'staticRoot' => ROOT . '/www/cache',
```

Pages are also written as real files — `index.html` and `index.html.gz` — so a
rewrite rule can serve them without PHP starting at all. This is what WP Super
Cache calls mod_rewrite mode.

```apache
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(auth|remember|logged) [NC]
RewriteCond %{DOCUMENT_ROOT}/cache/%{HTTP_HOST}%{REQUEST_URI}/index.html -f
RewriteRule ^(.*)$ /cache/%{HTTP_HOST}/$1/index.html [L]
```

**Measure before enabling it.** The gain scales with the weight of the bootstrap
being skipped, not with the web server. An application already using
`serveEarly()` saves a millisecond or two; one that boots fully before consulting
the cache saves tens. One consuming application measured 5.8 ms for its PHP hit
path against 4.5 ms static — real, and much smaller than the advertising for this
technique suggests.

Three things to know:

- **URLs with a query string are never written as files.** A rewrite rule cannot
  apply `ignoreQuery`, so it would serve the clean page for `?page=2`. Those
  requests stay on the PHP path, where normalisation still happens.
- Files are written to a temporary name and renamed, because a half-written page
  served to a visitor is worse than a slow one.
- Purges remove the static twin as well. They must — otherwise the rewrite keeps
  serving the file the purge reported having removed.
- Your rewrite conditions are now part of the bypass rules, and they are **not**
  checked against the PHP config. If they disagree, the file wins.

---

## Configuration reference

| Key | Default | |
|---|---|---|
| `enabled` | `false` | |
| `store` | `'file'` | `file`, `redis`, `memcached`, `memcache`, `array`. Unknown names fall back to `file` rather than throwing |
| `prefix` | `'pagecache:'` | key namespace within the store |
| `ttl` | `3600` | |
| `ttlRules` | `[]` | `pattern => seconds`, first match wins |
| `botTtl` | `null` | overrides `ttl` for crawlers |
| `methods` | `['GET','HEAD']` | |
| `statuses` | `[200]` | |
| `hosts` | `[]` | empty means all |
| `onlyPaths` | `[]` | empty means all |
| `bypassPaths` | `[]` | globs or delimited regexes |
| `bypassQuery` | `[]` | `name => true` or `name => [values]` |
| `bypassCookies` | `['#^(auth\|remember\|logged)#i', '#^' . session_name() . '$#i']` | the session cookie is on the list by default |
| `bypassHeaders` | `['Authorization']` | |
| `ignoreQuery` | `utm_*`, `fbclid`, `gclid`, … | dropped before keying |
| `varyQuery` | `null` | whitelist; overrides `ignoreQuery` |
| `varyBy` | `[]` | `name => callable` |
| `privateMarkers` | `[]` | body substrings that prevent storing |
| *(not configurable)* | — | a body containing this response's CSP nonce is never stored |
| `staleWhileRevalidate` | `30` | seconds past expiry |
| `lockTtl` | `30` | `0` disables locking |
| `gzip` | `true` | |
| `etag` | `true` | |
| `debugHeader` | `true` | `X-Pramnos-Cache` and `Age` |
| `debugDetail` | `false` | adds the key, TTL and expiry |
| `writer` | `'cache'` | or `'static'` |
| `staticRoot` | `ROOT . '/www/cache'` | |
| `headerWhitelist` | `content-type`, `content-language`, `link`, `vary` | the only response headers replayed |

`headerWhitelist` is a whitelist rather than a blacklist because the header that
must not be replayed to another visitor is always the one nobody thought of.

---

## When a page is not being cached

In order, and each is one line:

**Start here: is the application setting a cookie on every response?** This is the
first thing anyone hits, and it takes one command:

```bash
curl -D- -o /dev/null -s https://example.test/directory | grep -i set-cookie
```

Any `Set-Cookie` at all means nothing will ever be stored. `PHPSESSID` is the
usual culprit and it is not something the application wrote: the framework starts
a session in `Application::init()` on every request unless told otherwise, and
without turning that off the page cache and the session are mutually exclusive as
shipped.

```php
// app/app.php
'session'          => 'lazy',   // no session for a visitor who has none
'session_tracking' => false,    // no tracking cookies either
```

See [declining the automatic session](Pramnos_Framework_Guide.md#declining-the-automatic-session)
for what lazy mode does and does not change.

Then, in order, and each is one line:

1. `$cache->whyBypassed($request)` names the rule — `cookie:authtoken`,
   `bypassPaths`, `method:POST`, `disabled`, or `runtime:<reason>` when something
   called `PageCache::bypass()`. If it returns `null`, the request is cacheable and
   the problem is on the store side.
2. Check the response for `Set-Cookie`; anything that touches the session adds
   one.
3. Is the debug toolbar on? Nothing is stored while it collects — see
   `skipWhileDebugging` above.
4. Check the status is on `statuses` — a redirect is not cached by default.
5. Does the page have an inline `<script nonce="…">`? Then it is never stored, on
   purpose — see [a cache hit and the CSP nonce](#a-cache-hit-and-the-csp-nonce).
6. Is the front controller returning a `Response`, or echoing `render()`'s string?
   A string is passed through unstored — see
   [the pipeline has to return a `Response`](#the-pipeline-has-to-return-a-response).
7. `X-Pramnos-Cache` absent on a request you expected to hit means the lookup did
   not find an entry. Turn on `debugDetail` and compare `X-Pramnos-Cache-Key`
   across the two requests you expected to share a page — that is the answer
   almost every time.

## See also

- [Cache Guide](Pramnos_Cache_Guide.md) — the storage layer, adapters and categories
- [Routing Guide](Pramnos_Routing_Guide.md) — middleware registration
- [Test Suite Performance](Pramnos_Test_Suite_Performance.md) — the invalidation cost measurements
