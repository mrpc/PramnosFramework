---
date: 2026-08-25
categories: [Changelog]
---

# A service worker that refuses to cache HTML

`init --service-worker=y` writes one. Its design comes from reading a production
service worker in a consuming application and cataloguing what had gone wrong with it —
two incidents recorded in its own comments, and three more still live.

<!-- more -->

## Added

- **`--service-worker` (default no)** — scaffolds `<web-root>/sw.js` and the lines that
  register it, in the theme footer for MVC pages and in the SPA shell for SPA ones. It
  caches static assets in the browser: `GET`, same-origin, and only paths ending in a
  stylesheet, script, font or image extension.

  **Off by default on purpose.** A service worker is the most persistent thing an
  application can install on somebody else's machine — it keeps itself alive across
  reloads, so a mistake in one is not corrected by the next deployment the way a mistake
  in a page is; the fixed page has to get past the worker first.

  **HTML is never intercepted**, and that is the whole design. Once a worker caches HTML
  it needs a hand-maintained list of URLs never to cache — the signed-in pages, the
  checkout, the profile editor. The worker this is drawn from had eleven such entries,
  grown one at a time, and every page added to that application was a chance to forget
  one. The consequence is a visitor's personal page stored in a stranger's browser,
  where nothing on the server can reach it — the same failure the page cache's bypass
  rules exist to prevent, except those rules cover a store the application owns.

  **Two strategies, chosen by whether a URL can change meaning.** `assets/vendor/<lib>/<version>/`
  and the content-hashed SPA build are cache-first and never revalidated: a new version
  is a new path. Everything else is stale-while-revalidate, because `assets/css/style.css`
  never changes its URL and cache-first there is stale *forever* — which is how the
  original served a *Maintenance Mode* page through hard reloads for a day, having stored
  an error response as though it were a real one. Only `response.ok` is stored now.

  **There is no cache version to bump.** The version prefix is what made that incident
  permanent: two of the original's three caches had unversioned names, so the sweep that
  deletes caches "not in the current list" could never reach them — a bump purged one and
  left the others stale for good. Nothing here needs one. Immutable entries stay valid by
  definition, everything else revalidates itself, and what bounds the cache is a cap
  enforced on write. Which also replaces a `setInterval` cleanup that could not run at
  all: a browser terminates an idle service worker long before a six-hour timer fires.

  Two more corrections worth naming. The file sits at the **web root**, because a
  worker's scope is the directory it is served from and one under `assets/` could only
  see `assets/…`. And the registration URL comes from `sURL` rather than a literal
  `/sw.js`, so an application in a subdirectory registers at its own path instead of
  404ing — or, worse, claiming a scope above itself.

## Documentation

- New [Service Worker Guide](../../Pramnos_Service_Worker_Guide.md). Most of it is about
  what the worker refuses to do and why, including the two things it does *not* check —
  there is no `Set-Cookie` test, because that is a forbidden response header that
  `fetch()` never exposes, so the check would always pass and protect nothing; the path
  filter is what makes it unnecessary.

  It also covers removal, which is the part people need in a hurry: deleting `sw.js`
  alone does not remove it from browsers that already have it — a 404 on the script
  unregisters the worker, but only when the browser next checks, up to a day later.
