---
use_cases:
  - Choosing between an MVC, SPA or hybrid application layout
  - Deciding whether logic belongs in a service or a model
  - Understanding the directory layout of a Services + API + SPA project
  - Working on a SPA front end and its JSON API
  - Rendering a server-rendered page from a service, without an ActiveRecord model
  - Using a Model from a service, a queue worker or an attribute-routed controller
  - Adapting the scaffolded SPA API client to an app that authenticates differently
---

# Pramnos Application Styles Guide

PramnosFramework supports two application styles. They are not in competition —
pick the one that fits the app, and mix where it helps. The framework's building
blocks (Router, middleware, QueryBuilder, migrations, queue, broadcasting, cache)
are shared by both.

| | **MVC + Models** | **Services + API + SPA** | **Services + server-rendered pages** |
|---|---|---|---|
| Front controller | `www/index.php` → `Application::init()/exec()/render()` | thin dispatcher → `Router` + middleware pipeline | either |
| Routing | `src/Api/routes.php` and/or controller conventions | `#[Route]` attributes on `src/Api/Controllers` | `#[Route]` on `src/Controllers` |
| Domain layer | `src/Models` (ActiveRecord) | `src/Services` (plain classes + QueryBuilder) | `src/Services` — the same ones |
| View layer | `src/Views` (server-rendered templates/themes) | none — a JS SPA (served by a thin app-shell page) consumes the JSON API | `src/Views`, fed from services |
| API docs | `apidoc.json` + `@api` comment blocks → OpenAPI | `php pramnos api:docs` (from `#[Route]`) | as the API half |

**The third column is not a third project layout.** It is the second one with server-
rendered pages beside the JSON — for the pages a crawler has to read, or a form that
should work without JavaScript. The services are the same objects; only the thing
consuming them differs.

> The middle column read **"View layer: none"** until 2026-08-16. That was true of the
> style as first written and stopped being true the moment an application in it added a
> controller returning HTML — which is a normal thing to do and which the framework has
> always supported. A comparison table is a claim like any other, and this one was
> quietly telling readers that something they were already doing was not a supported
> option.

**No model is required for a view.** `View::addModel()` is the only place
`Pramnos\Application\Model` is structurally needed; skip it and `$this->model` is
`false` in the template, which is the *no model* case rather than an error.
`Controller::getModel()` type-checks nothing. Data reaches a template as plain view
properties:

```php
$view = $this->getView('Directory');
$view->stations = (new StationDirectory())->live(20, 0);
return \Pramnos\Http\Response::make((string) $view->display('index'));
```

If you do want a model, [it costs 1.54 µs to construct one](#using-a-model-outside-an-mvc-request).

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

which writes `src/Services/BillingService.php` plus a matching test stub.

---

## The `Service` base class

A service extends `Pramnos\Application\Service`. That is the whole contract —
there is nothing to register and no method you must implement:

```php
namespace App\Services;

use Pramnos\Application\Service;

class BillingService extends Service
{
    /**
     * Invoices past their due date and still unpaid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdue(int $days = 30): array
    {
        return $this->measure('overdue', fn(): array => $this->queryBuilder('invoices')
            ->where('due_at', '<', gmdate('Y-m-d', time() - $days * 86400))
            ->where('paid', 0)
            ->getAll());
    }
}
```

What the base gives you:

| Member | What it does |
| --- | --- |
| `__construct(?Database $database = null)` | Takes the connection to use, or none. |
| `$this->database()` | The connection, **resolved on first use** — a service constructed in a test that never queries never opens one. |
| `$this->queryBuilder(?string $table)` | A `QueryBuilder` on that connection, with the table applied when named. |
| `$this->measure(string $name, callable $work)` | Runs `$work`, returns its value untouched, and records how long it took. |

Two things follow from inheriting, and neither costs a line of code:

**The service becomes visible in the debug toolbar.** Constructing it records it
in the **Domain** tab, next to the request's models. A `measure()` call adds the
duration of one operation. This is why the base class exists at all: a plain
class offers the framework nothing to observe, so in a Services project the tab
that should describe the domain layer was empty for a request that had done all
of its work in services. See the
[Debugging Guide](Pramnos_Debugging_Guide.md#what-this-request-did-to-the-domain-layer).

**A unit test stays a unit test.** Because the connection is lazy, `new
BillingService()` in a test that only exercises pure logic does not reach for a
database; and where the test does need one, inject it:

```php
$service = new BillingService($databaseDouble);
```

### Feeding the debug toolbar from a non-`Api` application

A Services + API + SPA project routes `#[Route]` attributes to controllers returning
`Response::json()`, and **never goes near `Pramnos\Application\Api`**. That class attaches the
`_debug` payload and sends the debug headers in `protected` methods, so reading it leads to the
conclusion that a non-`Api` application cannot feed the toolbar without reimplementing them.

It can, and it is one line:

```php
$pipeline->add(new \Pramnos\Debug\ApiDebugMiddleware());
```

That covers every routing style. Both `Api` methods are thin delegations to public statics, and
the middleware calls the same two:

| What `Api` does privately | The public seam |
| --- | --- |
| `_attachDebugPayload($body)` | `ApiDebugPayload::attachTo(string $body): string` |
| `_sendServerTiming()` | `ApiDebugPayload::sendHeaders(): void` |

Use those directly if the middleware does not fit — a custom kernel, a response type the pipeline
does not see. The rule about **which** bodies can carry the key lives in `attachTo()` and nowhere
else: a top-level JSON array, a plain string, HTML, or a body that already has a `_debug` key is
returned untouched, because mangling a response to annotate it is worse than not annotating it.

Inert outside development — `ApiDebugPayload::isEnabled()` asks the toolbar whether any collector
is registered, and collectors are registered only in debug mode. In production it is one array
check per request.

> A consumer filed this as a gap twice: the `Api` methods **are** still `protected`, which is
> literally true and stopped being the obstacle when the middleware shipped. Their tripwire
> checked a *name* — is this method still protected — rather than a *construction* — is the
> capability reachable. It was documented in the Debugging and Upgrade guides and not here, which
> is the half that was actually missing: on this page, where a project that will hit it is
> standing.

### What the scaffolded API client assumes — and what to replace

`scaffold:spa` writes `frontend/lib/api.js`, and it speaks **the framework's own
API contract**, not HTTP in general. That is the right default for a project
built on `Pramnos\Application\Api`, and the wrong one for a Services + API + SPA
project that routes with `#[Route]` and authenticates with
`Authorization: Bearer`. It shares none of it.

Stated plainly, so the divergence is a decision rather than a discovery:

| The stub does this | An attribute-routed, Bearer app replaces it with |
| --- | --- |
| `apiKey` header on every request, derived from `md5(str_replace('/api/', '/', getUrl()))` and injected as `window.__PRAMNOS__` | nothing — there is no API-key layer to satisfy |
| `accessToken` header for a bearer session (the framework's own header name) | `Authorization: Bearer <token>` |
| `POST /account/login`, `/account/login2fa`, `GET /me` | your own auth endpoints |
| `credentials: 'same-origin'`, so a website session authenticates the SPA | keep it if `UnifiedAuthMiddleware` serves your web session too; drop it for a token-only API |

What is worth keeping in either case, because none of it is contract-specific:
the `ApiError` class with the HTTP status attached, so a screen reacts to `401`
and `422` instead of parsing messages; the `204` handling; and the debug-panel
recording (`record`, `reportError` from `./debug.js`) that feeds the SPA debug
panel. Rewriting the transport and keeping those is the smaller job.

**`create:api-client` supersedes the stub for the endpoints themselves.** It
generates one typed function per documented operation from the OpenAPI document
— see [Typed endpoints from the OpenAPI
document](#typed-endpoints-from-the-openapi-document) — and it delegates to
`lib/api.js` for the transport. So the split is: the generator owns the
endpoints, and `lib/api.js` is yours to adapt to how your application
authenticates.

> Filed by a project building a Svelte admin panel against the scaffolding,
> which wrote its own client and reported that the docs presented the stub as
> *the* contract without saying which parts assume `src/Api/`. This is not a
> bug in the stub — it is legitimate divergence — but a reader meeting it should
> not have to derive that from the code.

### Using a Model outside an MVC request

`Model::__construct()` requires a `Controller`, which reads like a hard dependency on
the MVC stack. It is not. Of the five references to `$this->controller` inside `Model`,
two are real uses — `getModel()` delegation and the error path — and three exist only to
hand the same controller to the next model it constructs.

```php
use Pramnos\Application\ServiceController;

$post = new \App\Models\Post(ServiceController::shared());
$post->load($id);
```

That works from a service, a queue worker, a console command or an attribute-routed API
controller. **Measured**: `new Controller()` is 1.54 µs, and the
`Application::getInstance()` behind it is 1.3 ms cold and 0.002 ms warm. The dependency
costs nothing and looks like it costs a great deal — which is why it has a name now
rather than a workaround per project.

Use `shared()` rather than constructing one per model: models built from the same
controller can resolve each other through `getModel()`, and a fresh controller re-runs a
reflection and a permissions normalisation for nothing.

**It grants no permissions.** `Controller::__construct()` takes a permissions array and
this passes none, so any permission check answers as it would for a request with none.
Code outside a request has no user, and a controller that quietly behaved as though it
did would be worse to have in the framework than the inconvenience it removes.

This does not settle whether your services *should* use models — see
[why services instead of models](#why-services-instead-of-models). It settles that the
`Controller` argument is not the reason to decide either way.

### Converting an existing class: pass the connection explicitly

The lazy fallback is `Factory::getDatabase()`, which is right for a service written against
this base and **a hazard for one being moved onto it**. A class that previously reached its
database some other way — an application-level singleton, an injected handle, a second
connection — silently changes which database it talks to the moment its constructor is left
defaulted. Nothing reports it. Every query still succeeds, somewhere else.

```php
// Converting: keep the handle the class already had
class SettingsService extends Service
{
    public function __construct(MyDatabase $db)
    {
        parent::__construct($db);
    }
}
```

A consumer caught this before it shipped, and the numbers are why it is worth its own
section: **59 call sites** constructed the class they were converting, and it had been built
on an application-level `getInstance()`. Had the two resolvers differed, a conversion sold as
observability would have repointed all 59. They passed the instance in and pinned it with a
test.

**And convert selectively.** Inheritance adds nothing to a class that runs no queries and has
no steps to time. The shape worth converting is one with several steps, on every request, that
is slow without anybody being able to say which step — `measure()` answers that without
temporary `microtime()` calls that get left in the code. The same consumer converted one
service out of sixty-five, which is about the right ratio.

`measure()` re-throws whatever the callback threw, after recording the attempt —
a failed call is the one worth seeing in the toolbar, so it is recorded, not
swallowed.

Overriding the constructor is allowed; call `parent::__construct()` if you do, or
the service will simply not announce itself (everything else keeps working —
instrumentation is never load-bearing).

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
php pramnos api:docs
```

It finds `src/Api/Controllers` or `src/Controllers` on its own and writes under
whichever document root the project has, printing both — check that `Scanned …` line
against where your API actually lives before publishing what it produced.

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
./dockernpm run dev        # Vite dev server + HMR
./testjs                   # front-end tests (Vitest, or node --test)
```

### Adding a SPA to an application that already exists

`init` scaffolds a whole project and **refuses to run** where one already is. For an
existing application there is a separate command:

```bash
php pramnos scaffold:spa --spa-stack=svelte          # at the site root
php pramnos scaffold:spa --app-style=hybrid          # mounted under /app
php pramnos scaffold:spa --dry-run                   # report, write nothing
```

It writes the same files from the same stubs as `init --app-style=spa`, because it
calls the same method — there is no second implementation to drift.

**Nothing you already have is overwritten.** A file the project already has is left
byte-for-byte and reported as `kept (yours)`, so running it twice does nothing the
second time and running it when you are unsure is safe. `--force` overwrites, if that
is genuinely what you want.

It also records `app_style` and `spa_stack` in `app/app.php`. That matters more than
it looks: `spa:dev`, `spa:build` and `project:resync` all read those keys, and without
them the front end exists while every command that should help with it reports that
the project has none. A project that already declares a style keeps it.

**If your front end lives somewhere else**, say so rather than renaming the
directory:

```php
// app/app.php
'spa_source_dir' => 'admin-ui/',
```

`project:resync` reads it, and when it finds nothing it now says *where it looked* and
how to change that — the message used to be the same sentence for "this project has no
SPA" and "your sources are not where I assumed".

### Developing with HMR — keep browsing the app URL

Do **not** open the Vite port: there is no `index.html` there, so it answers 404.
The page is always served by the application; only the modules come from Vite.

While `npm run dev` runs it writes `www/assets/spa/.vite/hot` with its origin, and
the shell switches to loading the Vite client and the entry module from the dev
server. Stop it and the shell silently goes back to the built bundle. So you edit
a component, keep the same URL open, and get HMR against the real backend — real
sessions, real cookies, no proxy and no CORS gymnastics in the app itself.

Tailwind and daisyUI are configured from CSS alone (`@import "tailwindcss";`
`@plugin "daisyui";`) — there is no `tailwind.config.js` to keep in sync.

Every stack ships with tests for the API client (cookie auth, Bearer auth, JSON
encoding, `204`, error statuses) and, for Svelte, component tests for the root
screen. They are meant to be extended, not deleted.

### Typed endpoints from the OpenAPI document

Screens hand-write path strings and field names, while the OpenAPI document in the same
repository knows both — so a rename in the backend is found in the browser, one screen
at a time.

```bash
php pramnos create:api-client          # → lib/endpoints.js + lib/endpoints.d.ts
php pramnos create:api-client --dry-run
```

One function per documented operation, and the types an editor reads:

```js
import { listThings, readThing, createThing } from './lib/endpoints.js';

const page  = await listThings({ page: 2, search: 'ada' });   // query params, blanks omitted
const thing = await readThing(42);                            // path params, encoded
await createThing({ label: 'new' });                          // body, typed from the schema
```

Four things worth knowing:

- **It sits on top of `lib/api.js`, not instead of it.** The `apiKey` header, the bearer
  token, the session cookie, the `ApiError`, the two-factor flow and the debug-panel
  recording all live there — none of it is derivable from a document. The generated
  functions delegate the call.
- **JSDoc and a `.d.ts`, not TypeScript.** A scaffolded project is plain JavaScript;
  this gives the editor the same checking without adding a compiler to the build.
- **Both files are regenerated.** Staying in step with the backend means being
  rewritten from the document, so do not edit them — re-run the command after changing
  the API. (The opposite of `scaffold:spa`, which never overwrites: that one adds *your*
  files, this one owns its own.)
- **Where the document is silent, the type is `any`.** A generated type that is
  confidently wrong is worse than one that admits it does not know, because the first is
  trusted.

Generate the document first — `npm run docs:build` in a project with the docs tooling,
or `php pramnos api:docs` for an attribute-routed one.

### Adding a feature: `create:crud`

The generator reads `app_style` from `app.php`, so one command produces the
halves this project actually needs:

```bash
./myapp create:crud thing --table=things
```

| Style | Produces |
|---|---|
| `mvc` | model + controller + server-rendered views |
| `spa` | model + API controller + routes + a front-end screen |
| `hybrid` | both, over **one** model — a single domain object, two controllers |

The screen lands in your `spa_source_dir` — `frontend/screens/` by default, or
`www/assets/js/screens/` without a build step — and registers itself in
`screens/registry.js`, which the application reads to build its navigation.
`--target=mvc|spa|both` overrides the style's choice for one run.

**What the SPA screen actually is**, on the Svelte stack: a list that sorts,
searches and pages on the server through the model's `getApiList()` pipeline,
with its state in the URL, over a form whose every control matches the column's
*type* — a checkbox for a boolean, a date input for a date, a textarea for text,
a searchable picker for a foreign key, the `COLUMN COMMENT` as the label and
`NOT NULL` as `required`. It comes from the same introspection the MVC generator
reads, so the screen matches the migration that created the table.

**On the two vanilla stacks** it is a plain ES module exporting `mount(target)`
plus `fetchPage()` / `saveRecord()` / `deleteRecord()`, which is the same shape
without a component model: server-side paging and search, sortable headers, a
pager with buttons, and a form whose input `type` follows the column's SQL type.
It builds its DOM with `textContent` and `.value`, never `innerHTML` — a record's
own text is untrusted, and a generated file is the worst place to leave that
decision to whoever edits it next.

`main.js` walks `screens/registry.js` and mounts the screen the route names, so a
generated screen is reachable and linkable with nothing to edit in the shell. The
only vanilla gap left is the scaffolded **admin** screen, which is Svelte-only:
three tabs of hand-written DOM is not a starting point worth generating, and the
endpoints behind it are framework-side, so a vanilla project can still reach
them.

Two more doors on the front end, the counterparts of `create:view` and
`create:service`:

```bash
./myapp create:screen Dashboard --blank      # a screen with no list
./myapp create:component StatusBadge         # a component *and its test*
```

The screen imports five shared components — `DataTable`, `Pagination`,
`ConfirmDialog`, `Field` and `lib/i18n.svelte.js` — written once per project and
never overwritten afterwards, because the value of shipping a `DataTable` is
that you extend it. `project:resync --spa-components` takes a newer version when
you want one. See the [Console
Guide](Pramnos_Console_Guide.md#front-end-generation-spa-projects) for the
control-per-type table and the components' contracts.

### The shell is a page, not a view

The shell is deliberately **not** an MVC view: it is not rendered through a theme
or `getView()`. It is a tiny page that emits the app-shell HTML and boots the JS
client. That means the standard cache discipline applies — **the HTML shell is
dynamic and must not be cached; the assets it references are cached hard and
busted on change** (exactly what Laravel/Rails/Symfony do with fingerprinted
assets).

The generated shell (`www/spa.php`, or `www/app.php` in a hybrid project) picks
its assets at runtime, so the same file is correct in development, before the
first build, and in production:

- **A dev server is running** (`.vite/hot` exists) → the Vite client and the
  entry module are loaded from it, with HMR.
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

### Two build settings worth knowing about

**`publicDir` is pinned**, to `frontend/static`. Vite's default is `<root>/public`,
and the generated `vite.config.js` lives at the project root — so in an application
whose web root *is* `public/` a build copies **the entire site** into the SPA's output
directory: legacy pages, uploads, everything. Nothing warns; the build succeeds and
the output directory quietly grows by the size of the site. Put files that should be
copied verbatim into `frontend/static`, or change the setting to wherever yours live.

**The palette is derived, and says when it cannot be.** `scripts/build-theme.mjs`
runs before every build and every dev-server start, reading the server-rendered
theme's `:root` custom properties so the two halves of the application do not look
like two products. When that stylesheet is missing — or exists but declares no
custom properties — it now **warns**, names the path it tried and what to change.
It used to report the fallback in the same voice as a success, so a project whose
theme lives elsewhere built cleanly and shipped in the framework's brand colour.
