---
date: 2026-08-25
categories: [Changelog]
---

# A cache hit was telling the browser whatever PHP had already said

`x-pramnos-cache: HIT` next to `pragma: no-cache`, noticed in a running install. The
headers were safe and nobody had chosen them.

<!-- more -->

## Added

- **`cacheControl`** — what a hit tells the *browser*. `null` by default, which leaves
  things exactly as they were.

  What they were is worth stating. `Pragma: no-cache`, `Expires: 1981` and
  `Cache-Control: no-store, no-cache, must-revalidate` come from PHP's
  `session.cache_limiter`, which defaults to `nocache` and fires on `session_start()`.
  A front controller calls `$app->init()` before the pipeline, so they are queued before
  the page cache is asked anything — and with `'session' => 'lazy'` an anonymous visitor
  starts no session and gets none of them. Which headers a hit carried therefore
  depended on whether a session happened to start.

  **The accident was in the safe direction, which is why the default does not change
  it.** A hit is a *shared* copy and `no-store` is the right thing to say about one: the
  browser will not keep the anonymous page and hand it back after the visitor signs in.
  The dangerous shape is the reverse — a hit carrying `public, max-age=3600` lets a
  browser or a CDN keep the anonymous page for an hour and serve it to a signed-in user,
  and `purgeUrl()` can reach neither.

  What the default costs is the second cache layer, so the knob exists for pages that
  really are public:

  ```php
  'cacheControl' => 'public, max-age=300',
  ```

  Setting it also removes the leftover `Pragma` and `Expires`. Those have to go
  together: `Cache-Control: public` alongside a queued `Pragma: no-cache` is worse than
  either alone, because every HTTP/1.0 intermediary believes the `Pragma`. They are
  cleared with `header_remove()` rather than through the `Response`, which cannot
  unqueue a header it never carried.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) gains **What a hit tells the
  browser**, including the two things to be sure of before pointing a CDN at it — the
  bypass rules protect the server-side cache, not somebody's browser, and nothing here
  can purge a CDN.
