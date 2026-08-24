---
date: 2026-08-25
categories: [Changelog]
---

# A config key that did nothing

`'debugComment' => false` sat in `PageCache::defaults()` and was read nowhere.
Anybody who set it to `true` got nothing, and no explanation.

<!-- more -->

## Removed

- `debugComment`.

## Added

- `'debugDetail' => false` — adds `X-Pramnos-Cache-Key`, `X-Pramnos-Cache-TTL` and
  `X-Pramnos-Cache-Expires` to a cached response.

```bash
curl -sD- -o /dev/null 'https://example.test/directory?utm_source=x' | grep -i x-pramnos
```

## The key is the one that matters

`X-Pramnos-Cache: HIT` tells you the cache worked. It does not help at all when the
cache is *not* working, and that is when anybody looks.

The question then is almost always *"under what key did it go in?"* — with
`ignoreQuery`, `varyBy` and `varyQuery` all feeding the key, two requests you
expected to share a page can quietly key differently, and nothing visible says so.
The reporting application replaced an inline debug mechanism that printed the key,
the TTL and the expiry, and had been using all three.

The header shows **the key the entry is stored under**, not one recomputed for the
response. A recomputed key would agree with itself and disagree with reality, which
is the single thing it exists to rule out.

## Off by default, which the request did not ask for

The filing asked for these under `debugHeader`, which defaults to `true`. They are
under a separate key that defaults to `false` instead.

`HIT` and `Age` are ordinary things for a cache to say. A cache key is internal, and
publishing it to every visitor hands anybody probing for cache-key collisions the
normalisation rules for free. An application that wants them everywhere sets one
key; nobody gets them without deciding to.

## Not a comment in the body

`debugComment` is removed rather than implemented, and the filing's own reasoning is
why: a body is what snapshot tools diff and what a search engine indexes, and debug
information does not belong in a stored page — which a cached page is, by
definition.

A configuration key that does not exist is a clearer answer than one that silently
does nothing.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) — a new "When you need to
  know *why*" section, and the diagnosis checklist now points at the key header
  rather than at recomputing it by hand.
