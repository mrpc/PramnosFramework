---
date: 2026-07-29
categories:
  - Changelog
  - Docs
tags:
  - scaffolding
  - spa
  - frontend
  - caching
---

# SPA scaffolding: a build-less app-shell with asset cache-busting

The "Services + API + SPA" scaffolding gains a PHP app-shell stub so a build-less
SPA gets correct cache-busting out of the box, and the styles guide now spells out
the shell-vs-assets cache discipline.

<!-- more -->

See the [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md#the-spa-front-end).

## Why

The only SPA shell stub was a static `spa-index.html.stub` with **no cache-busting
at all** — fine if you run a build tool that emits content-hashed filenames, but a
foot-gun for the build-less app the style is meant to make easy: on deploy the
browser keeps serving the old `app.js` against the new API. The framework itself
punted on versioning, so every app re-invented it (or shipped the bug).

## Added

- **`scaffolding/templates/spa-index.php.stub`** — a one-file PHP app-shell (now the
  documented default). It stamps each asset URL with the file's modification time
  (`app.css?v=…`) using `__DIR__`, so it is self-contained wherever it is copied: a
  deploy changes the mtime → the browser refetches, unchanged assets stay cached
  far-future. It is explicitly **not** an MVC view (no theme/`getView()`); it is a
  page a thin front controller renders for unmatched non-API GETs.
- The existing **`spa-index.html.stub`** stays for build-tool setups, where the
  content hash in the filename is already the cache-buster.

## Docs

The styles guide's "The SPA front end" section now explains the pattern the major
frameworks share — **dynamic HTML shell (never cached) + fingerprinted assets
(cached hard, busted on change)** — and which stub to pick by whether you run a
front-end build, with the `Cache-Control` headers to set on each.

## Notes

Additive and docs-only on the runtime side (stubs are copy-manually starting
points, not wired into a command), so nothing changes for existing apps.
