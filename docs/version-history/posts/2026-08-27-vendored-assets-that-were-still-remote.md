---
date: 2026-08-27
categories: [Changelog]
---

# Vendored assets that were still remote

`pramnos init` downloads a library's stylesheet and stops there. For FontAwesome, Bootstrap
Icons and any web font, the stylesheet *is* a list of remote URLs — so "install locally"
produced a local file pointing at somebody else's server, and the project's own generated CSP
then refused it.

<!-- more -->

## Fixed

**A downloaded stylesheet now brings what it points at.** Every `url()` reference is fetched
into a `files/` directory beside the stylesheet and rewritten to the local copy.
FontAwesome's `all.min.css` is nothing but `@font-face` rules naming `../webfonts/*.woff2`;
vendoring the CSS alone left a project whose icons were empty boxes, and there was nothing in
the output to say so. A failed download leaves the original URL alone — a stylesheet that
half-works beats one rewritten to a file that is not there.

**Two catalog keys for hosts that care who is asking.** `user_agent` is sent for that
entry, because Google Fonts serves `woff2` to a browser and `ttf` to anything else — three
times the bytes, and the wrong format. And a stylesheet URL that is not a filename
(`css2?family=…`, whose basename is `css2`) is saved as `<key>.css` rather than as a file
with no extension that no server sends as CSS.

**The `plain-css` theme self-hosts Inter.** Its header carried three
`fonts.googleapis.com` tags while the same command generated a CSP restricting `style-src`
to `'self'`. The browser refuses that stylesheet outright, so every scaffolded plain-css
project rendered in the fallback font stack, with two console errors and no other sign — two
halves written by one command, disagreeing. Widening the policy for two hosts is the wrong
end of it: we already download assets at scaffold time, and a typeface is an asset. Self-hosted
it is also one fewer third-party request per page, one fewer dependency on somebody else's
uptime, and one fewer visitor IP address handed to Google, which under the GDPR is not a
stylistic preference.

**The generated `Dockerfile` installs the cache client.** Choosing `redis` wrote the compose
service and the `'method' => 'redis'` setting into an image with no `redis` extension, so a
brand-new project ran on files from its first request — and said nothing, because falling
back is what `Cache` is supposed to do. `memcached` had the same hole. Both are now
`pecl install`ed into the image when selected.

**And `CacheBackendCheck` is registered by default**, so the fallback is reported rather
than merely survivable. `degraded`, naming both stores — *Running on file, configured for
redis* — and hinting at the missing extension. Not `down`: the site is working, and a check
that pages somebody for a working site is a check that gets muted.

## Why this took a real project to find

A silent fallback is invisible in exactly the conditions where it matters. The settings said
redis, `docker compose ps` said redis, every page worked, and the cache was on local disk —
which means invalidation is per-server, and a two-node deployment serves whatever the node
that was not asked still holds. Two Redis-only bugs in this framework's own cache adapters
could not surface for as long as no project ever actually reached Redis.

## Documentation

- `Pramnos_Console_Guide.md` — *A vendored stylesheet brings what it points at*, with the
  `user_agent` key and the filename rule.
- `Pramnos_Cache_Guide.md` — the check to read instead of comparing the two properties by
  hand, and the image extension.
- `Pramnos_Health_Guide.md` — `cache` in the built-in table, and why it is degraded rather
  than down.
