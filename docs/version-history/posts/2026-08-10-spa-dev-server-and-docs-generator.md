---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - spa
  - frontend
  - scaffolding
  - docker
---

# SPA follow-ups: the dev server, the site root, and the docs generator

Three defects found by running a scaffolded project instead of reading it: the Vite
dev server had nothing to serve, the site root rendered the MVC page instead of the
SPA, and `npm run docs:build` died on `require is not defined`.

<!-- more -->

## `npm run dev` answered 404

```
  ➜  Local:   http://localhost:8084/assets/spa/
This localhost page can't be found — HTTP ERROR 404
```

Correct, and unavoidable: a Vite dev server serves an `index.html`, and this project
has none — the page is a PHP shell served by Apache. The dev server was never the
thing to open.

The fix is the pattern Laravel uses. While it runs, the dev server writes
`www/assets/spa/.vite/hot` containing its origin (a tiny inline Vite plugin), and
the shell prefers that file over any build output:

```php
if (is_file($hotFile)) {
    $origin  = rtrim(trim(file_get_contents($hotFile)), '/');
    $scripts = [$origin . '/assets/spa/@vite/client', $origin . '/assets/spa/frontend/main.js'];
}
```

Note the base prefix on **both** URLs — with `base: '/assets/spa/'` the dev server
serves its own client underneath it too, and a bare `/@vite/client` is a 404
(confirmed against a running server). `server.cors` is enabled because the page and
the modules now come from different origins, and the old `/api` proxy is gone: the
page is served by the backend, so API calls were always same-origin.

Result: `./dockernpm run dev`, keep browsing the application URL, get HMR against
the real backend with real session cookies. Stop the dev server and the shell
returns to the built bundle by itself.

## The site root served the MVC page

`curl http://localhost:8190/` on a `--app-style=spa` project returned the
server-rendered home page. The catch-all rewrite is guarded by `!-d`, and the
document root **is** a directory, so `/` never reached it — Apache's `DirectoryIndex`
picked `index.php`. The generated `.htaccess` now sets `DirectoryIndex spa.php`
first.

## `npm run docs:build` broke on a SPA project

```
ReferenceError: require is not defined in ES module scope
This file is being treated as an ES module because it has a '.js' file extension
and '/var/www/html/package.json' contains "type": "module".
```

The SPA scaffolding adds `"type": "module"` to `package.json`, which retroactively
turned the CommonJS API-docs generator into an ES module. It ships as
`scripts/apidoc-to-openapi.cjs` now, with the npm script and `project:resync`
updated to match. An existing project gets the new file from
`pramnos project:resync --scripts`; the stale `.js` copy is inert afterwards and can
be deleted.

## Tests

`InitSpaScaffoldingTest` gained the hot-file wiring, the base-prefixed Vite client,
the `DirectoryIndex`, the `.cjs` generator under `"type": "module"` — plus a check
that runs `node --check` over **every** generated JavaScript file, because the
missing comma that broke `vite.config.js` (`tailwindcss()pramnosHotFile()`) was
invisible to substring assertions and only surfaced in a real build.
