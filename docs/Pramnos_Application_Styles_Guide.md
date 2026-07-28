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
| View layer | `src/Views` (server-rendered templates/themes) | none — a static JS SPA consumes the JSON API |
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

The front end is a static single-page app served by the web server; it talks to
the JSON API over `fetch()`. Starting points ship as scaffolding stubs:

- `scaffolding/templates/spa-index.html.stub` — the HTML shell.
- `scaffolding/templates/spa-app.js.stub` — a tiny fetch wrapper + boot.

Copy them to your document root (e.g. `www/index.html`, `www/assets/js/app.js`)
and build out from there. Because the contract is the API, the front end can be
anything (vanilla JS, React, Vue) without changing the backend.

---

## Directory layout (Services + API + SPA)

```
src/
  Controllers/     attribute-routed API controllers
  Services/        application logic + data access (create:service)
  Middleware/      cross-cutting concerns (create:middleware)
  Models/          optional — only where ActiveRecord earns its keep
app/
  Migrations/      schema migrations
  config/          app configuration
www/ (or public/)  static SPA + generated api/openapi.json
```

Both styles use the same `app/`, migrations, queue, cache and broadcasting — only
the domain/view/routing choices differ.
