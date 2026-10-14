---
use_cases:
  - Choosing between an MVC, SPA or hybrid application layout
  - Deciding whether logic belongs in a service or a model
  - Understanding the directory layout of a Services + API + SPA project
  - Working on a SPA front end and its JSON API
  - Rendering a server-rendered page from a service, without an ActiveRecord model
  - Using a Model from a service, a queue worker or an attribute-routed controller
  - Adapting the scaffolded SPA API client to an app that authenticates differently
  - Running one part of a server-rendered administration as a small SPA inside the theme
  - Giving a screen deep links, back/forward and a reload that stays where it was, without a full SPA
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

A controller in a Services project that returns HTML is a normal, supported thing to do;
the third column exists so that is visible rather than implied.

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
that should describe the domain layer would be empty for a request that did all
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
$router->addGlobalMiddleware(new \Pramnos\Debug\ApiDebugMiddleware());
// or, on a MiddlewarePipeline you build yourself:
$pipeline->pipe(new \Pramnos\Debug\ApiDebugMiddleware());
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

The `Api` methods are `protected`, and that is not an obstacle: the capability is reachable
through the middleware and the two public statics above, so nothing has to be reimplemented.

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

The generated file's own header says the same and points here, so the assumption is stated
where somebody meets the code.

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
costs nothing and looks like it costs a great deal — which is why it has a name
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

A widely used class is the risky case: every call site that constructs it is repointed at
once if the two resolvers differ. Pass the existing instance in, and pin it with a test that
asserts which connection the service uses.

**And convert selectively.** Inheritance adds nothing to a class that runs no queries and has
no steps to time. The shape worth converting is one with several steps, on every request, that
is slow without anybody being able to say which step — `measure()` answers that without
temporary `microtime()` calls that get left in the code. In a typical application that is a
handful of services, not all of them.

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

`init` scaffolds a whole project and **refuses to run** where one already is (unless
given `--force`). For an existing application there is a separate command:

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

`project:resync` reads it, and when it finds nothing it says *where it looked* and how
to change that, so "this project has no SPA" and "your sources are not where I assumed"
are distinguishable.

### Developing with HMR — keep browsing the app URL

Do **not** open the Vite port: there is no `index.html` there, so it answers 404.
The page is always served by the application; only the modules come from Vite.

While `npm run dev` runs it writes `www/assets/spa/.vite/hot` with its origin, and
the shell switches to loading the Vite client and the entry module from the dev
server. Stop it and the shell silently goes back to the built bundle. So you edit
a component, keep the same URL open, and get HMR against the real backend — real
sessions, real cookies, no proxy and no CORS gymnastics in the app itself.

Tailwind is configured from CSS alone — there is no `tailwind.config.js` to keep in
sync. `frontend/app.css` imports Tailwind, the palette (`theme.css`) and `ui.css`, the
stylesheet of the project's UI system; see
[The whole SPA follows the UI system](#the-whole-spa-follows-the-ui-system).

**Form markup on tailwind is daisyUI 5's**, which is not daisyUI 4's. `form-control`,
`label-text` and `label-text-alt` do not exist in 5 and style nothing, so a wrapper
written against them stops stacking: the label becomes an inline-flex box beside a
field shrunk to its content, and three of them render as three ragged rows of
different widths. `Field.svelte` and the generated screens use a `fieldset` instead,
with its parts from `lib/ui.js` (`fieldset w-full`, `label`, `input input-bordered w-full`
on tailwind):

```svelte
<fieldset class={ui('fieldset')}>
    <label class={ui('label')} for={id}>{label}</label>
    <input {id} class={ui('input')} />
</fieldset>
```

Three details are deliberate. `fieldset` stacks its children with no class at all,
so the layout survives even where daisyUI is not loaded — which a `div` leaning on
`form-control` did not. The name stays on a real `<label for>` rather than a
`<legend>`, because a legend names a *group* and a control whose only accessible
name comes from one has no name of its own. And inputs carry `w-full`, because in daisyUI 5
nothing stretches them.

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

**The navigation is drawn twice from that one list.** From `lg` up it is a row of
buttons in the header. On a phone it is daisyUI's `dock` along the bottom of the
screen, with an icon and a label per screen. A row that does not wrap pushes a
phone's page wider than the screen, and a menu button hides the screens, which are
what the application is for. Give a registry entry an `icon` (an SVG string) to
replace the plain square. With more than five screens, the dock shows icons only and
keeps the labels for screen readers and for the title a long press shows.

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
that you extend it. `project:resync --spa-components` takes a newer version of
those you have not changed, and keeps the rest. See the [Console
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

### The bundle is committed

`www/assets/spa/` goes **into the repository**, and it is the one thing about the build
output worth knowing before a first deploy.

Nothing in a deploy builds it. The webhook this framework scaffolds runs `git fetch`,
`git reset --hard`, `composer install`, `migrate` — no `npm install`, no `npm run build`,
and a production server usually has no Node at all. So an ignored bundle is a **site with
no front end**, and it is invisible locally, where the files are on disk and everything
works.

So the working loop is: build, then commit.

```bash
./dockernpm run build
git add www/assets/spa && git commit
```

`www/assets/spa/.vite/hot` is the one file that stays out, and it is not tidiness. `npm run
dev` writes *this machine's* dev-server origin into it, and the shell then loads modules
from whatever it says — committed, it would point every visitor's browser at a developer's
laptop. `.vite/manifest.json` beside it is the opposite: production reads it for the
content-hashed filenames, so it must be committed. The scaffolded `.gitignore` names the
one file rather than the directory for exactly that reason.

**To build on the server instead**, put `www/assets/spa/` back in your own `.gitignore` and
add the build to your deploy commands. That is a legitimate arrangement and it is not the
default, because the default has to be the one that works with the deploy the framework
actually generates.

The shell itself sends `Cache-Control: no-cache, must-revalidate` — a cached
shell would keep pointing at assets that no longer exist. Complete the discipline
in the web server by setting `Cache-Control: max-age=31536000, immutable` on the
versioned assets under `www/assets/spa/`.

---

## A SPA section inside a server-rendered area

One part of a server-rendered application, all of `/admin/Users` for example, can run as a
small single-page application **inside the theme's page**. The theme draws the page: the menu,
the header, the help panel and Ask, the flash, the debug toolbar. The section draws the content.

### Declare it, and return it from a controller

```php
// app/app.php
'spa_sections' => [
    'users' => ['ability' => 'admin.users'],   // entry: frontend/sections/users/main.js
],
```

```php
// src/Admin/Controllers/Users.php
public function display()
{
    return $this->spaSection('users', ['canDelete' => $this->user->usertype >= 99]);
}
```

`spaSection()` returns, in place of a view, a mount element and the entry's tags:

```html
<div data-pf-section="users" data-base="/admin/Users" data-config="{…}" data-props="{…}"></div>
<script type="module" src="/assets/spa/section-users-3f2a.js"></script>
```

- **`data-base`** is the controller's own address: inside the area, `/admin/Users`; on the site,
  `/Users`. A section works under whatever address its controller has.
- **`data-config`** carries the API's prefix and key, read by `lib/api.js`.
- **`data-props`** carries the initial data passed to `spaSection()`.
- **No inline script**, so a nonce-based CSP has nothing to refuse.
- **`ability`**, when declared, is checked first (`AdminAccess`, the area's floor). A viewer it
  refuses gets a 403 and nothing.
- **Never page-cached.** The page is that person's.

The tags come from the same three places as the SPA shell's:

1. the Vite dev server, while its hot file is there (HMR included);
2. otherwise the build's manifest, hashed;
3. otherwise, with no build, the entry file under the web root, stamped with its modification
   time. Declare that one: `'entry' => 'assets/js/sections/users.js'`.

### Deep links, reloads, back and forward

An action a controller does not have falls back to `display()`. So every address under the
controller (`/admin/Users/edit/5`, `/admin/Users/roles?page=2`) is answered with the same page,
and the section's router reads the rest of the path. A deep link opens the screen it names, a
reload stays there, and back/forward move between screens. A link outside the base is an
ordinary link to another server page; the router does not touch it.

### The section's entry

```js
// frontend/sections/users/main.js
import { mount } from 'svelte';
import { section } from '../../lib/section.js';
import { flash, title, about } from '../../lib/page.js';
import Users from './Users.svelte';

const users = section('users');              // the element, base, props, router
mount(Users, { target: users.element, props: { router: users.router, ...users.props } });
```

`users.router`:

- `current()` returns `{ path: '/edit/5', segments: ['edit', '5'], query }`.
- `start(onRoute)` calls back now and on every change, and returns a function that stops it.
- `go(path, query, { replace })` moves to a screen of the section.
- `href(path, query)` builds an address under the base.

The vanilla stack uses the same two modules without Svelte.

**Data comes from the API, as the page's.** `lib/api.js` sends the session cookie, the page's
CSRF token from `<meta name="csrf">`, and the key from `data-config`. `ApiAuthMiddleware` accepts
that from the same origin. A section has no login and no token of its own, and every endpoint
checks its own ability.

**The page's parts, from the section** (`lib/page.js`):

| Call | What it does |
|---|---|
| `flash(text, 'message'\|'error')` | Adds to the layout's `.pf-flash`, the block `Html\Flash` draws, so a section's "Saved." looks like every server page's. Without one, it draws one beside the section. |
| `title(text)` | Sets `document.title`, and the theme's heading where it marks one with `data-pf-page-title`. |
| `about(kind, id, label)` | The record the page shows, for the side panel: Ask is about it, as on a server page whose controller declares `$assistSubject`. |

There is no notifications feed to reach. The framework's unread counts are the navigation badges,
which the theme draws on every page load.

### One build, many sections

Every `frontend/sections/<name>/main.js` is an entry of the same Vite build. The scaffolded
`vite.config.js` finds them, and the manifest lists each by its path, which is what `spaSection()`
reads. A project scaffolded before adds the same to its `rollupOptions.input`:

```js
input: { main: 'frontend/main.js', 'section-users': 'frontend/sections/users/main.js' },
```

An application without a Vite setup gets one with `init --app-style=hybrid`. The build-less
stack's sections are plain modules under the web root.

### Testing a section

- Its router and page calls are plain JavaScript: test them with `test:js` and the
  `pramnos-testing` helpers (see the [Testing Guide](Pramnos_Testing_Guide.md#testing-the-javascript-of-an-application)).
- Its components are tested with Vitest, like the SPA's.
- On the server, a `TestClient` request to a deep link (`/admin/Users/edit/5`) proves the address
  is answered with the mount element inside the area.

### The whole SPA follows the UI system

Every Svelte file the scaffolder writes — the shared components (`DataTable`, `Pagination`,
`ConfirmDialog`, `Field`, `Omnibox`, `HelpPanel`), the shell `App.svelte`, the admin, chat and
scheduled-report screens, and the screens `create:crud` generates — asks `lib/ui.js` for each part
by name, `ui('btn.sm')` or `ui('card')`, instead of writing one framework's classes. `lib/ui.js`
maps each part per UI system: daisyUI for `tailwind`, Bootstrap 5's own classes for `bootstrap`,
and the plain stylesheet's for `plain-css`. Sizes and variants combine: `ui('btn.primary', 'size.sm')`.

The system is read when the page runs, from `scaffold_theme` in app/app.php: the SPA shell
publishes it as `window.__PRAMNOS__.ui`, and a section receives it in `data-config`. So a section
inside a Bootstrap page draws Bootstrap's buttons, tables and modals, and follows
`project:switch-ui` with the page.

A full SPA builds its own stylesheet, so the build has to carry the same system.
`frontend/ui.css`, which `app.css` imports, is that system's stylesheet:

| `scaffold_theme` | `frontend/ui.css` |
|---|---|
| `tailwind` | `@plugin "daisyui";` |
| `bootstrap` | Bootstrap from npm, the theme's `theme-tokens.css` and `style.css` from the web root, and only the daisyUI components Bootstrap has no counterpart for |
| `plain-css` | The theme's `theme-tokens.css` and `style.css`, and the same daisyUI components |

Those daisyUI components are the chat, the phone dock, stats, joined groups, dividers, tabs
and the loading indicator. They are written as daisyUI classes in every system. A part both
daisyUI and the other stylesheet define (`btn`, `card`, `alert`, `badge`, form fields, `table`,
`navbar`, `progress`, …) always goes through `ui()`, because otherwise two stylesheets would draw
it. A framework test fails on a stub that writes one directly. `bootstrap` is in `package.json`
whatever the system, and Tailwind's layout utilities (`flex`, `gap-2`, `lg:hidden`) compile into
the bundle under every system. `ui.css` maps the colour names they use (`bg-base-100`,
`text-error`) onto the system's own colours.

The framework writes `ui.css`; `project:switch-ui` rewrites it, keeping the previous copy under
`var/switch-ui/`. Then run `npm run build`, because the bundle carries the stylesheet. A project
whose `app.css` registers daisyUI itself (`@plugin "daisyui";`) keeps drawing with daisyUI until
that line becomes `@import "./ui.css";`, and the switch says so. Where a system has no class for a part (a layout utility plain
CSS lacks, a modal that is not Bootstrap's script-driven one, the help side panel), the map
uses a `pf-ui-*` hook, which the scaffolded `bootstrap` and `plain-css` stylesheets style.

```js
import { ui } from '../lib/ui.js';
// <button class={ui('btn.primary')}>…   in a component of the application's own, too
```

A part is added to all three maps. A framework test checks that every part exists in each, and
that every hook a map uses has a rule.

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
custom properties — it **warns**, names the path it tried and what to change, so a
project whose theme lives elsewhere does not build cleanly and ship in the framework's
brand colour.
