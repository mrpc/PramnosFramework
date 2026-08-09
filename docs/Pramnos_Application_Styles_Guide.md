# Pramnos Application Styles Guide

PramnosFramework supports two application styles. They are not in competition —
pick the one that fits the app, and mix where it helps. The framework's building
blocks (Router, middleware, QueryBuilder, migrations, queue, broadcasting, cache)
are shared by both.

| | **MVC + Models** | **Services + API + SPA** |
|---|---|---|
| Front controller | `www/index.php` → `Application::init()/exec()/render()` | thin dispatcher → `Router` + middleware pipeline |
| Routing | `src/Api/routes.php` and/or controller conventions | `#[Route]` attributes on `src/Controllers` |
| Domain layer | `src/Models` (ActiveRecord) | `src/Services` (plain classes + QueryBuilder) |
| View layer | `src/Views` (server-rendered templates/themes) | none — a JS SPA (served by a thin app-shell page) consumes the JSON API |
| API docs | `apidoc.json` + `@api` comment blocks → OpenAPI | `php pramnos api:docs` (from `#[Route]`) |

The reference application and apps like it use **MVC + Models**. An app that is a
JSON API with a JavaScript front end is better served by **Services + API + SPA**;
this guide documents that style, since the MVC path is covered elsewhere.

---

## Why services instead of models

ActiveRecord models are excellent when the app is mostly CRUD over tables with
server-rendered forms. When the app's logic is richer than "load row → edit →
save" — rate limiting, moderation, external APIs, real-time fan-out, background
jobs — a **service layer** keeps that logic cohesive and testable:

- A **service** owns one slice of behaviour *and* its data access, behind
  intention-revealing methods (`postMessage()`, `recordPlay()`), with the
  framework **QueryBuilder** doing the SQL. Controllers stay thin — parse the
  request, call a service, return a response.
- There is **no ActiveRecord requirement**: the framework's `Database` /
  `QueryBuilder` are first-class on their own. You can still introduce a
  `Pramnos\Application\Model` where a table genuinely wants ActiveRecord
  ergonomics; the two coexist.
- Services are trivially unit-testable — inject the `Database` (or a double) in
  the constructor.

Scaffold one with:

```
php pramnos create:service BillingService
```

which writes `src/Services/BillingService.php` (an injectable `Database`, a
QueryBuilder example) plus a matching test stub.

---

## The API layer (attribute routing)

Controllers live in `src/Controllers` and declare their routes with `#[Route]`:

```php
use Pramnos\Routing\Attributes\Route;

class StatusController
{
    /** Current service status. */
    #[Route('/api/status', methods: 'GET', name: 'status.show')]
    public function show(): Response { /* … */ }
}
```

A thin front controller loads them and runs the middleware pipeline:

```php
$router = new Router($container);
$router->loadFromDirectory(ROOT . '/src/Controllers', 'App\\Controllers');
$router->addGlobalMiddleware(new CorsMiddleware(/* … */));
$router->addGlobalMiddleware(JsonResponseMiddleware::class);
echo $router->dispatch(Request::getInstance(), ['*']);
```

Attach auth (and other cross-cutting concerns) per route with
`#[Route(..., middleware: [AdminAuthMiddleware::class])]`. Only wire the
middleware the API actually needs — a token/JSON API typically does not need the
cookie-oriented CSRF/session middleware that a server-rendered app does.

### API documentation

Generate an OpenAPI 3.0 spec straight from the `#[Route]` attributes (see the
[Routing Guide](Pramnos_Routing_Guide.md#openapi-documentation-from-route)):

```
php pramnos api:docs --namespace='App\Controllers' --output=www/api/openapi.json
```

Enrich request/response schemas via a deep-merged `--overrides` document.

---

## The SPA front end

The front end is a single-page app served from the web root; it talks to the JSON
API over `fetch()`. Because the contract is the API, it can be anything (Svelte,
React, Vue, plain JS) without changing the backend.

### Scaffold it with `init`

`pramnos init` asks for the application style up front and generates the whole
front end — sources, build, tests, Docker tooling — so nothing has to be copied
by hand:

```bash
php vendor/bin/pramnos init --app-style=spa --spa-stack=svelte
```

| `--app-style` | What you get |
|---|---|
| `mvc` | The default. Server-rendered controllers, views and themes; no SPA. |
| `spa` | SPA at the site root. Page requests render the shell; the API and the scaffolded server-rendered areas (login, admin CRUD, OAuth) keep reaching the front controller. |
| `hybrid` | Both. MVC stays in charge, the SPA is mounted under `/app`. |

| `--spa-stack` | Sources | Build | Tests |
|---|---|---|---|
| `svelte` (default) | `frontend/` — Svelte 5 runes, Tailwind v4 + daisyUI v5 | Vite → `www/assets/spa/` | Vitest + jsdom + `@testing-library/svelte` |
| `vanilla-vite` | `frontend/` — plain ES modules | Vite → `www/assets/spa/` | Vitest + jsdom |
| `vanilla` | `www/assets/js/` — served exactly as written | none | `node --test`, zero dependencies |

Both build stacks put Node **inside the app image** and add two helper scripts,
so the host needs no toolchain at all:

```bash
./dockernpm install        # npm inside the container
./dockernpm run build      # production build
./dockernpm run dev        # Vite dev server + HMR, port published by compose
./testjs                   # front-end tests (Vitest, or node --test)
```

Tailwind and daisyUI are configured from CSS alone (`@import "tailwindcss";`
`@plugin "daisyui";`) — there is no `tailwind.config.js` to keep in sync.

Every stack ships with tests for the API client (cookie auth, Bearer auth, JSON
encoding, `204`, error statuses) and, for Svelte, component tests for the root
screen. They are meant to be extended, not deleted.

### The shell is a page, not a view

The shell is deliberately **not** an MVC view: it is not rendered through a theme
or `getView()`. It is a tiny page that emits the app-shell HTML and boots the JS
client. That means the standard cache discipline applies — **the HTML shell is
dynamic and must not be cached; the assets it references are cached hard and
busted on change** (exactly what Laravel/Rails/Symfony do with fingerprinted
assets).

The generated shell (`www/spa.php`, or `www/app.php` in a hybrid project) handles
**both** busting modes and picks between them at runtime, so the same file is
correct before and after the first build:

- **A Vite build wrote a manifest** → the shell reads
  `www/assets/spa/.vite/manifest.json` and emits the content-hashed filenames
  (`main-B7w2Jpx_.js`), plus whatever CSS the entry imports. The hash *is* the
  cache-buster.
- **No manifest yet, or no build at all** → it falls back to the plain asset
  paths stamped with their modification time (`/assets/js/main.js?v=…`). A deploy
  changes the mtime, the browser refetches, unchanged assets stay cached.

The shell itself sends `Cache-Control: no-cache, must-revalidate` — a cached
shell would keep pointing at assets that no longer exist. Complete the discipline
in the web server by setting `Cache-Control: max-age=31536000, immutable` on the
versioned assets under `www/assets/spa/`.

---

## Directory layout (Services + API + SPA)

```
frontend/          SPA sources (build stacks) — main.js, App.svelte, lib/api.js, __tests__/
src/
  Controllers/     attribute-routed API controllers
  Services/        application logic + data access (create:service)
  Middleware/      cross-cutting concerns (create:middleware)
  Models/          optional — only where ActiveRecord earns its keep
app/
  Migrations/      schema migrations
  config/          app configuration
www/ (or public/)  SPA shell (spa.php) + assets + generated api/openapi.json
```

Both styles use the same `app/`, migrations, queue, cache and broadcasting — only
the domain/view/routing choices differ.
