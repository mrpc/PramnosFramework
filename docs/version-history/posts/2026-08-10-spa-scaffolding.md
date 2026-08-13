---
date: 2026-08-10
categories:
  - Changelog
  - Features
tags:
  - scaffolding
  - spa
  - svelte
  - frontend
  - docker
  - testing
---

# `init` scaffolds a working SPA — Svelte + daisyUI, Vite, Vitest and Docker

`pramnos init` gained an **application style** question. Pick `spa` (or `hybrid`)
and it generates the whole front end — sources, build, tests, and the Node
toolchain inside the app image — instead of leaving three stubs to copy by hand.

<!-- more -->

## Before

SPA support existed in three unconnected pieces: cookie-as-API-credential auth
(Phase 16), the Services + API app style with `create:service`, and three
scaffolding stubs. The stubs were referenced by **no code at all** — not `init`,
not `scaffold:views` — so the documented path was "copy them to your document
root". Nothing wired the API prefix, the routing, the build, the tests or the
container.

## After

```bash
php vendor/bin/pramnos init --app-style=spa --spa-stack=svelte
```

| `--app-style` | Result |
|---|---|
| `mvc` | Unchanged default — server-rendered controllers, views, themes |
| `spa` | SPA at the site root; API and scaffolded server-rendered areas keep reaching the front controller |
| `hybrid` | MVC stays in charge, SPA mounted under `/app` |

| `--spa-stack` | Sources | Build | Tests |
|---|---|---|---|
| `svelte` | `frontend/` — Svelte 5 runes, Tailwind v4 + daisyUI v5 | Vite → `www/assets/spa/` | Vitest + jsdom + `@testing-library/svelte` |
| `vanilla-vite` | `frontend/` — plain ES modules | Vite → `www/assets/spa/` | Vitest + jsdom |
| `vanilla` | `www/assets/js/` — served as written | none | `node --test`, zero dependencies |

Svelte is what the interactive prompt offers first, and what an *invalid* value
falls back to — but the flag has no default when it is absent: a run with
`--app-style=spa` and no `--spa-stack` asks the question. **A non-interactive run
must pass it explicitly.**

*(Corrected 2026-08-14: this post described `svelte` as the default, which is only
true of the invalid-value fallback.)*

### What lands in the project

- **An API client** (`lib/api.js`) covering both authentication modes the
  framework supports: the session cookie for a same-origin SPA
  (`credentials: 'same-origin'`, what `UnifiedAuthMiddleware` accepts) and a
  Bearer token for anything else. Failures throw an `ApiError` carrying the
  status, so screens can branch on `401` / `422` instead of parsing strings.
- **A shell** (`www/spa.php`) that handles both cache-busting modes and chooses
  at runtime: Vite's manifest hashes when a build exists, file-mtime stamps when
  it does not. It sends `no-cache` for itself.
- **Routing** that keeps the scaffolded server-rendered areas reachable. A SPA
  project still has a login page and admin CRUD; the generated `.htaccess` lists
  exactly the prefixes `init` created and sends everything else to the shell, so
  client-side routes survive a refresh without swallowing `/login`.
- **Tests, and a runner.** The API client contract (cookies, Bearer, JSON
  encoding, `204`, error statuses, a non-JSON error body) plus Svelte component
  tests for the root screen. `./testjs` runs them in the container, falling back
  to the host.
- **Docker that matches the stack.** Node/npm are installed in the app image only
  when something needs them, Vite's dev-server port is published, and
  `./dockernpm` runs npm inside the container. `init` finishes with
  `npm install && npm run build` so the app is visible immediately.

## Verified end to end, not just asserted

Each stack was generated into a throw-away project and actually run —
`npm install`, `npm run build`, `npm test` — which caught three defects that unit
tests over generated strings would have missed:

- Vitest resolved Svelte's **server** build under jsdom, so every component test
  failed with *"mount(...) is not available on the server"*. Fixed with
  `resolve.conditions: ['browser']`.
- `node --test tests/js/` (a directory argument) makes Node 24 try to *load* the
  directory as a module; the run dies before a single test executes. Now an
  explicit glob.
- The `vanilla-vite` stack never imported its stylesheet from the entry point, so
  Vite emitted no CSS and the built page rendered unstyled.

## Developing: keep the app URL open

`./dockernpm run dev` starts Vite, but the Vite port serves **no HTML** — there is
no `index.html`, the page comes from the application. While the dev server runs it
writes `www/assets/spa/.vite/hot`, and the shell loads the Vite client and entry
module from it; stop it and the shell falls back to the built bundle. So HMR
happens on the normal application URL, against the real backend and real session
cookies — no proxy, no second origin to log into.

## Tests

`tests/Unit/Console/InitSpaScaffoldingTest.php` — the MVC default stays SPA-free;
each stack's sources, build config, dependencies and test runner; the shell's two
cache-busting modes; SPA and hybrid routing; Node in the image only when needed;
`.gitignore` coverage; the API layer always scaffolded; and the summary telling
the developer how to build, develop and test.
