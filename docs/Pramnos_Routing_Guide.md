---
use_cases:
  - Registering a route or a route group
  - Using #[Route] attribute routing on a controller
  - Attaching middleware to routes
  - Diagnosing a URL that answers 404 only when it carries a query string
  - Working out how `?r=module/action/x` becomes a controller, an action and $_GET entries
  - Working out why HEAD requests, link checkers or uptime monitors report a page as missing
  - Answering 405 Method Not Allowed instead of 404 for a wrong HTTP verb
  - Generating OpenAPI documentation from route attributes
  - Moving an administration served from its own www/admin/index.php into the site's administration area
  - Running an old separate admin application beside the new area while screens are moved
  - Putting the administration screens behind an /admin prefix with their own layout
---

# Pramnos Routing Guide

The router supports fluent registration and `#[Route]` attributes, with URI parameters, named
routes, groups, permissions and middleware.

**Class:** `Pramnos\Routing\Router`

## Getting Started

### Define routes

A router is constructed with the IoC container, which it uses to build controllers:

```php
// src/Api/routes.php — included with $this bound to the Api instance
$router = new \Pramnos\Routing\Router($this);

// A closure
$router->get('/hello', fn() => ['message' => 'hello']);

// A controller method, as [class, method] — the class is built through the container
$router->get('/users',        [UserController::class, 'index']);
$router->get('/users/{id}',   [UserController::class, 'show']);
$router->post('/users',       [UserController::class, 'store']);
$router->put('/users/{id}',   [UserController::class, 'replace']);
$router->patch('/users/{id}', [UserController::class, 'update']);
$router->delete('/users/{id}', [UserController::class, 'destroy']);
$router->options('/users',    fn() => '');

// Several methods at once (returns the router, not a Route)
$router->match(['GET', 'POST'], '/search', [SearchController::class, 'handle']);

// The response of the matched route, or null when nothing matched
return $router->dispatch(new \Pramnos\Http\Request());
```

An action is a closure, a function name, a `[class, method]` pair or any other callable. A
`'Controller@method'` string is **not** an action: it is not callable, and the route returns
`null`.

`get()`, `post()`, `put()`, `patch()`, `delete()`, `options()` and `head()` take
`($uri, $action, $permissions = null)` and return the `Route`, so `->name()` and
`->middleware()` can be chained. `addRoute($uri, $methods, $action, $permissions)` is the
underlying form and returns the router.

### Route parameters

```php
$router->get('/users/{id}', function ($id) {
    return ['userid' => (int) $id];
});

// Optional parameter — the action's own default applies when it is absent
$router->get('/posts/{year}/{slug?}', function ($year, $slug = null) { /* … */ });
```

Matched values are passed to the action **by parameter name**; a parameter that did not match
is left to the method's default. A placeholder matches one path segment (`[^/]+`). There is no
per-route constraint API — validate the value in the action.

### Permissions

The third argument is the list of permission scopes the caller must hold:

```php
$router->get('/reports', [ReportController::class, 'index'], ['reports:read']);
```

`dispatch($request, $userPermissions)` throws `Pramnos\Auth\AuthorizationException` (code 403)
when the scopes are missing; `dispatchSafe()` returns an array with `error` set to
`RouteNotFound` or `InsufficientPermissions` instead of throwing.

### Named routes

```php
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.show');

$url = $router->route('users.show', ['id' => 42]);   // '/users/42'
$router->getByName('users.show');                    // the Route, or null
```

`route()` throws `InvalidArgumentException` for an unknown name. Optional placeholders that are
not supplied are stripped.

## Middleware

Middleware is a `Pramnos\Http\MiddlewareInterface` instance or its class name. A class name is
instantiated with `new`, so it must be a real class — there are no string aliases.

```php
use Pramnos\Http\Middleware\{AuthMiddleware, ThrottleMiddleware, CorsMiddleware};

// One route
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware(AuthMiddleware::class);

// Several
$router->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, new ThrottleMiddleware(60, 60));

// Every route the router dispatches
$router->addGlobalMiddleware(new CorsMiddleware(['https://app.example.com']));
```

Order: global middleware → group middleware → route middleware → action. Groups are described
under [Route Groups](#route-groups) below.

## Attribute-Based Routing

```php
<?php
namespace App\Controllers;

use Pramnos\Http\Middleware\AuthMiddleware;
use Pramnos\Routing\Attributes\Route;

class UserController
{
    #[Route('/users', methods: 'GET', name: 'users.index')]
    public function index() { /* … */ }

    #[Route('/users/{id}', methods: 'GET', name: 'users.show')]
    public function show($id) { /* … */ }

    #[Route('/users', methods: 'POST', middleware: [AuthMiddleware::class])]
    public function store() { /* … */ }

    #[Route('/users/{id}', methods: ['PUT', 'PATCH'], permissions: ['users:write'])]
    public function update($id) { /* … */ }
}
```

`#[Route]` takes `uri` first, then `methods` (a string or an array, default `'GET'`), `name`,
`permissions`, `middleware` and `apiKeyScopes`. It is repeatable on one method.

### Discovering attribute routes

```php
$router->loadFromDirectory(ROOT . '/src/Controllers', 'App\\Controllers');
```

Every public method of every class under the directory is inspected for `#[Route]`; a class
carrying [`#[RouteGroup]`](#routegroup-attribute) has its routes registered inside that group.

## HEAD is answered by GET

**A route registered for GET answers HEAD as well.** You do not declare it and you cannot forget
it.

```php
$router->addRoute('station/{slug}', 'GET', [StationController::class, 'show']);
```

| Request | Route |
|---|---|
| `GET /station/athens` | matches, `{slug}` = `athens` |
| `HEAD /station/athens` | matches, `{slug}` = `athens` |
| `POST /station/athens` | no match |

This is RFC 9110 §9.3.2 — *"The HEAD method is identical to GET except that the server MUST NOT
send content"* — and it matters far more than the verb's reputation suggests: HEAD is what link
checkers, uptime monitors, `curl -I` and several crawlers send first. Routes are stored per method,
so without the fallback HEAD would answer 404 for every page and an entirely reachable site would
report as entirely broken.

**Declaring HEAD explicitly still wins**, and is worth doing when the answer can be cheaper than
the GET:

```php
// A GET that renders a report, and a HEAD that only decides whether it exists.
$router->addRoute('report/{id}', 'GET',  [ReportController::class, 'show']);
$router->addRoute('report/{id}', 'HEAD', [ReportController::class, 'exists']);
```

**The body is not the router's concern.** PHP's SAPI drops the content of a HEAD response. If your
action does expensive work to build output nobody will receive, check the method yourself:

```php
if (\Pramnos\Http\Request::getInstance()->getRequestMethod() === 'HEAD') {
    return Response::make('')->withHeader('Content-Type', 'application/json');
}
```

Only HEAD falls back. POST, PUT, PATCH and DELETE never borrow the GET table.

## Query strings and route matching

**A query string never takes part in matching, and never lands in a placeholder.**

```php
$router->addRoute('station/{slug}', 'GET', [StationController::class, 'show']);
```

| Request | `{slug}` receives |
|---|---|
| `/station/athens` | `athens` |
| `/station/athens?fbclid=abc` | `athens` |
| `/station/athens?return=/station/other` | `athens` |

The parameters themselves are read the ordinary way — `$request->get('fbclid', '', 'get')` or
`$_GET` — not from the route.

The query string is stripped **before** the pattern is tried, not after a miss. A placeholder
compiles to `[^/]+`, which a query string satisfies, so matching against the full URI would
hand the controller a slug of `athens?fbclid=abc` — and every link shared on a network that
appends a tracking parameter would answer 404.

**One exception, by design:** a route registered *with* a query string in its own URI matches on
that exact form.

```php
// Matches `/archive?page=2` and nothing else.
$router->addRoute('archive?page=2', 'GET', [ArchiveController::class, 'page']);
```

Use it only for an address that must be preserved verbatim; ordinary paging belongs in a
placeholder or in `$_GET`.

## Leading slashes, and the shape of a request URI

**Write the leading slash wherever you like — in the route, in `Request::create()`, in
`$_SERVER['REQUEST_URI']`.** Matching normalises all three.

```php
$router->get('/stations/{id}', [StationController::class, 'show']);   // route: with
$router->get('stations/{id}',  [StationController::class, 'show']);   // route: without — same thing

Request::create('/stations/7');   // → getRequestUri() === 'stations/7'
Request::create('stations/7');    // → getRequestUri() === 'stations/7'
```

`getRequestUri()` always answers **without** leading or trailing slashes, whichever way the
Request was built. Code that concatenates it into a path should still write
`'/' . ltrim($uri, '/')` rather than `'/' . $uri` — cheap, and it survives a caller who sets the
static directly.

`Route::matches()` trims the URI before adding the single leading slash its anchored pattern
expects. A doubled slash (`//stations/7`) would miss every route with a placeholder while every
static route still matched — the kind of failure that survives because the routes tested first
are the static ones.

## The legacy path decomposition — `Request::calcParams()`

Alongside the router there is the older mechanism: `?r=module/action/x`, decomposed by
`Request::calcParams()` into a controller, an action and `$_GET` entries. Applications migrating
off their own request class need to know exactly what it does, so here it is, measured — the
table is generated by running the code, and pinned by
`tests/Characterization/Http/RequestPathDecompositionTest.php`.

| `r=` | controller | action | `_option` | also in `$_GET` |
| --- | --- | --- | --- | --- |
| `dashboard` | `dashboard` | *empty* | — | — |
| `jobposts/view` | `jobposts` | `view` | — | — |
| `jobposts/view/479` | `jobposts` | `view` | `479` | — |
| `a/b/c/d` | `a` | `b` | — | `c => d` |
| `a/b/c/d/e` | `a` | `b` | `e` | `c => d`, `e => null` |

A trailing slash is not a segment: `jobposts/view/` and `jobposts/view` are the same request.
Keys already in `$_GET` — from a rewrite, a front controller, a middleware — survive.

### Which class a controller segment reaches

The controller segment is matched to a class without regard to case: `/twofactorauth`,
`/TwoFactorAuth` and `/TWOFACTORAUTH` all reach `TwoFactorAuth`, whether or not that class has
been loaded yet. The places are, in this order: the area's controllers
(`<Ns>\<Area>\Controllers`), the application's (`<Ns>\Controllers`), then the framework's
(`Pramnos\Application\Controllers`). The exact `ucfirst()` name is looked for in all three
first. Only when none has it are the autoloader's class map and PSR-4 directories searched, in
the same order, for a class of that name in another case. So a site controller reached from
inside an area costs what it always did, and only a URL in the wrong case pays for the search.
The segment is compared with names that exist and never becomes part of a path.

### Calling it twice on one request

Re-parsing is a supported pattern, and a common one: an application whose every URL carries a
language prefix cuts the first segment and calls `calcParams()` again, and a slug rewrite
(`oroi-xrhshs` → `page/oroi-chrisis`) does the same.

**Each call takes back the keys the previous call wrote, and only those.** `_option` and the
named pairs are the method's own output, so they are cleared before being produced again; a key
from a rewrite, a front controller, a middleware or a test is left alone.

That distinction is the whole of it, and both halves matter:

- Without the clear, a second parse would **inherit** the first. `el/parent/skipjobpost` then
  `parent/skipjobpost` would keep `_option = 'skipjobpost'` — the action's own name, from the parse
  where the prefix had shifted every index by one. An action whose `_option` names itself routes
  to itself.
- The pairs are worse than stale: the pairing loop writes a key only when it is not already set,
  so a surviving pair would **block** the new value: `page/view/k/first` then
  `page/view/k/second` would answer `first`.

Cleared before the query string is merged, so `?_option=x` still wins — that one was written by
whoever made the request. `resetInstance()` forgets the record, like the controller and the action
beside it.

### Only the path decides the decomposition

Two rules follow from the table above:

- **The controller and the action never become `$_GET` keys.** On `/jobposts/view/479`,
  `$_GET['jobposts']` is not set; only `_option` and the key/value tail after the action are
  written. Read `_option` and the named pairs.
- **The query string does not change the branch.** The number of slashes that chooses how the
  path is split is counted on the path alone, so `r=jobposts/view/479` and
  `r=jobposts/view/479?return=/account/settings` decompose identically (`_option = 479`).

### `$originalRequestNoChange` — the request as it arrived

Two statics, and the difference is the whole point of the second:

| | |
| --- | --- |
| `Request::$originalRequest` | the routing parameter, which `calcParams()` may rewrite |
| `Request::$originalRequestNoChange` | the same value as it arrived, never rewritten |

Assigned when `r` is read, **before** `calcParams()` runs. Use the second when you need the URL
the visitor actually asked for — building a canonical link, deciding whether to redirect to one —
and the first when you want whatever the current route resolved to.

If it were empty, an application building a canonical URL from it would get `sURL` alone,
compare that against the real address, find them different and redirect the page **to itself** —
an infinite loop with no error and no log line. That is why it is assigned before anything can
rewrite the request.

**The action is cleared on every call**, so `calcParams('module')` never inherits the previous
call's action. One request per process would hide the difference; a test suite or a worker is one
process for thousands of requests.

## Telling a wrong verb from a wrong address — 405

`getMatchedRoute()` answers for the request's own method: matched, or not. That
leaves two very different situations looking identical — a `GET` on a
`POST`-only endpoint falls through exactly as a path nobody declared does — so
an application can only answer **404** for both. That is honest and unhelpful:
it tells an integrator to check the address when the address was right.

`allowedMethodsFor()` asks the other question. It returns every method the
**path** is declared for, sorted, or an empty array when the path matches
nothing at all:

```php
$route = $router->getMatchedRoute($request);

if ($route !== null) {
    // dispatch as usual
}

$allowed = $router->allowedMethodsFor($request);

if ($allowed === []) {
    // Nothing serves this path under any method.
    return $this->notFound();                       // 404
}

// The path exists; the verb does not.
header('Allow: ' . implode(', ', $allowed));        // e.g. "GET, HEAD, POST"
return $this->methodNotAllowed($allowed);           // 405
```

RFC 9110 §15.5.6 makes the `Allow` header **mandatory** on a 405, which is why
the method exists on the router rather than in your application: matching a URI
pattern against a path — placeholders, optional segments, the query-string forms
— is the router's own rule, and re-deriving it from
`getRoutesWithPermissions()` would be a second spelling of the matching logic.

Two details worth knowing:

- **HEAD is reported wherever GET is**, because [the router serves it
  there](#head-is-answered-by-get). An `Allow` header that omitted HEAD would
  deny a request the router is about to answer successfully.
- **The request's own method is included** when it matches. The question is
  "what is this path declared for", not "what else could you have sent" — which
  is also what an `OPTIONS` response wants.

This is a question, not a decision. What you answer, and whether you send
`Allow` at all, stays with your application.

## Route Listing

```bash
php vendor/bin/pramnos route:list
php vendor/bin/pramnos route:list --json
```

The command lists the routes of the router the application publishes as `$app->router`. A
route file builds its own router and dispatches it, so it cannot be loaded from the CLI; an
application that wants its routes listed assigns the populated router in a service provider
or bootstrap (`$app->router = $router;`). With no router available the command prints an
explanation and an empty list.

## Route Groups

### Programmatic groups — `Router::group()`

```php
$router->group([
    'prefix'      => '/api/v1',
    'middleware'  => [ApiAuthMiddleware::class, ThrottleMiddleware::class],
    'permissions' => ['api:access'],
    'name'        => 'api.v1.',
], function (\Pramnos\Routing\Router $r): void {
    $r->get('/users',       [UserController::class, 'index'])->name('users.index');
    $r->post('/users',      [UserController::class, 'store'])->name('users.store');
    $r->get('/users/{id}',  [UserController::class, 'show'])->name('users.show');
});

// Registered routes:
//   GET  /api/v1/users       named api.v1.users.index
//   POST /api/v1/users       named api.v1.users.store
//   GET  /api/v1/users/{id}  named api.v1.users.show

// URL generation still works:
echo $router->route('api.v1.users.show', ['id' => 42]); // /api/v1/users/42
```

**Supported attributes:**

| Key | Type | Description |
|---|---|---|
| `prefix` | `string` | URI prefix prepended to every route URI |
| `middleware` | `array` | Middleware prepended before each route's own middleware |
| `permissions` | `array` | Permission scopes merged with each route's own permissions |
| `name` | `string` | Logical name prefix prepended to every named route |

### Nested groups

```php
$router->group(['prefix' => '/api', 'name' => 'api.'], function (Router $r): void {
    $r->group(['prefix' => '/v2', 'name' => 'v2.'], function (Router $r): void {
        $r->get('/items', fn() => ...)->name('items.index');
        // → GET /api/v2/items  named api.v2.items.index
    });
    $r->get('/status', fn() => ...);
    // → GET /api/status  (only outer prefix)
});
```

### Middleware ordering

```
global middleware → group middleware → per-route middleware → action
```

### `#[RouteGroup]` attribute

Apply group attributes declaratively on a controller class. `RouteDiscovery` reads the attribute and wraps all `#[Route]` methods in a `Router::group()` call:

```php
use Pramnos\Routing\Attributes\Route;
use Pramnos\Routing\Attributes\RouteGroup;

#[RouteGroup(
    prefix:      '/api/v1',
    middleware:  [ApiAuthMiddleware::class],
    permissions: ['api:access'],
    name:        'api.v1.',
)]
class UserController
{
    #[Route('/users',      methods: 'GET',  name: 'users.index')]
    #[Route('/users',      methods: 'POST', name: 'users.store')]
    public function index(): void { ... }

    #[Route('/users/{id}', methods: 'GET',  name: 'users.show')]
    public function show(int $id): void { ... }
}
```

**`#[RouteGroup]` constructor parameters:**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `prefix` | `string` | `''` | URI prefix |
| `middleware` | `array` | `[]` | Middleware FQCN strings |
| `permissions` | `array` | `[]` | Permission scopes |
| `name` | `string` | `''` | Name prefix |

## OpenAPI documentation from `#[Route]`

Because `#[Route]` attributes are the single source of truth the router dispatches
from, the framework can generate an OpenAPI 3.0 document from them directly — no
separate `@api` comment blocks to keep in sync.

```
php pramnos api:docs \
    --title='My API' --api-version=1.0.0 \
    --server=https://api.example.com \
    --overrides=src/openapi-overrides.json
```

**Where it looks and where it writes.** With no `--controllers` it takes the first of
`src/Api/Controllers` or `src/Controllers` that exists, and with no `--output` it
writes `<document root>/api/openapi.json`, where the document root is whichever of
`www`, `public`, `html` or `web` holds an `index.php`. Both are printed:

```
Scanned src/Api/Controllers (namespace App\Api\Controllers)
Wrote 72 path(s), 96 operation(s) to /srv/app/public/api/openapi.json
```

The first line says which directory was read because a scan of the wrong one is not
obviously broken: **a document describing one endpoint of seventy-two is indistinguishable
from an application that has one endpoint**, so it gets published and believed.

For the same reason, a run that finds fewer operations than a sibling directory holds
says so and names it:

```
src/Api/Controllers holds 96 operation(s) — more than the 1 found in src/Controllers.
Re-run with --controllers=src/Api/Controllers if that is the API.
```

Nothing is switched under you, and the check is skipped entirely when you passed
`--controllers` yourself — naming the directory is a decision, not a guess to correct.

### Commit the document and the viewer

`www/api/openapi.json` and `www/api/docs/` belong **in the repository**. Ignored as
"generated output", those two addresses answer 403 on every live site — and they are the only
two URLs an integrator ever opens.

Neither is build output in the sense that phrase usually carries. The document is written
by a CLI command that no deploy runs — the scaffolded deploy is `git fetch`,
`git reset --hard`, `composer install`, `migrate` — and the viewer is a static page with a
`<script src>` to a CDN and a `spec-url` to its sibling. **A file that is not in the commit
is a file production does not have.**

It fails quietly in the way that matters: nobody notices locally, because both files are on
disk and both work.

What keeps a committed artefact honest is a test that fails when it has drifted, not an
ignore rule. `api:docs` writing to a path under version control means `git diff --exit-code`
after a run is a complete check:

```bash
php pramnos api:docs --routes=src/Api/routes.php --no-html
git diff --exit-code www/api/openapi.json   # fails when the document is stale
```

If a project's `.gitignore` lists either path, delete the line, then `git add -f
www/api/openapi.json www/api/docs/`.

### Routes registered on the router — `--routes`

`#[Route]` attributes are one way to declare an API. The other is registering on the
router, which is what the framework's own dispatcher reads and what the scaffolder
generates in `src/Api/routes.php`. `--routes` reads the second, so an application that
registers its API on the router gets a document of it rather than one describing nothing.

```
php pramnos api:docs --routes=src/Api/routes.php
```

**A route file dispatches at the end** — `return $router->dispatch($newRequest);`, because
its return value is the response — so including it to read the routes would run a
controller. `Router::beginCollecting()` is what makes it possible: while collecting, every
`dispatch()` is a no-op returning `null` and every router built is kept.

```php
Router::beginCollecting();
try {
    include $routeFile;          // registers; the dispatch at the end does nothing
} finally {
    $routes = Router::stopCollecting();
}
```

Always in a `finally`. Collecting is process-wide, and a route file that raises would
otherwise leave every later `dispatch()` silently doing nothing.

It is a read, not a sandbox: including a route file runs whatever else that file does.
Point it at a file your own application owns.

**What a route can say is less than what an attribute can** — there is no docblock, no
parameter types and no response schema, because a closure registered against a URI carries
none of that. So this produces the *surface*: every address, every method, the path
parameters, the tag (the first segment that is not a version prefix), and a `bearerAuth`
requirement naming the scopes for routes that declare permissions. Use `--overrides` for
the rest.

Where both sources describe the same operation, **the attribute wins** — it has the
schema, the router has only the address. The merge is per operation rather than per path,
so a controller that documents one method of a resource does not erase the other three.

The overrides are merged once, over the combined document. An override for an operation
only the router knows therefore **adds** to the route-derived operation — a `404` beside
its summary, tags, parameters and security — rather than replacing it.

### Responses every operation shares

A route carries only its address, so a route-derived operation documents `200` and
nothing else. The `403` an API answers without an `apiKey` and the `401` it answers
without a session come from middleware, and neither source can see them. Declare them once
in the overrides file:

```json
{
  "x-pramnos-default-responses": {
    "403": { "description": "Missing or invalid apiKey header" }
  },
  "x-pramnos-secured-responses": {
    "401": { "description": "Not signed in" }
  }
}
```

| Key | Merged into |
|---|---|
| `x-pramnos-default-responses` | every operation, from both sources |
| `x-pramnos-secured-responses` | every operation with a `security` requirement — a route with permissions, or an attribute with permissions or an auth middleware |

An operation's own response for the same status wins over a default. Both keys are
consumed and do not appear in the written document.

**A `null` in an override removes a key.** That is how a default is taken back from the
few operations it does not apply to — a public address listed in `public_api_paths` answers
no `403`:

```json
{
  "paths": {
    "/1.0/hooks/stripe": { "post": { "responses": { "403": null } } }
  }
}
```

The viewer page (`docs/index.html`) is titled from the merged document, so `info.title` in
the overrides names it as well as the spec.

### It refuses to write an empty document

Zero operations from a scan is not a document, it is a failure — and writing it is the
worst available outcome, because the previous file is destroyed, the exit code is 0, and
the line printed reads like success to a deploy script and to a person skimming.

```
Scanned src/Api/Controllers (namespace App\Api\Controllers) and found no operations
— refusing to write /srv/app/www/api/openapi.json.
An empty document overwriting a good one is worse than no run at all. If the routes are
registered on the router rather than declared with #[Route], pass
--routes=src/Api/routes.php. If this API really has no operations yet, pass --allow-empty.
```

The command exits non-zero. `--allow-empty` is there for a new project that genuinely has
no operations yet, which is a real state and not one to guess at.

**`--namespace` follows `--controllers`.** It is derived from the application
namespace in `app/app.php` plus the path after `src/`, so
`--controllers=src/Api/Controllers` gives `App\Api\Controllers`, so passing
`--controllers` alone is enough.

What is derived automatically: paths and methods (with `{param}` segments becoming
path parameters), `operationId` (from the route name), `summary`/`description`
(from the handler's docblock), a `bearerAuth` security requirement for routes that
declare permissions or an auth middleware, and `tags` from the controller name.

What cannot be inferred from routes alone — request/response schemas, examples — is
supplied through the `--overrides` document, which is **deep-merged** over the
generated one (scalars and objects are overridden per key, a `null` removes the key; the
generated paths are preserved). This mirrors the `openapi-overrides.json` convention used elsewhere.

Programmatic use (e.g. to serve the spec live) goes through the same generator:

```php
use Pramnos\Routing\OpenApiGenerator;

$doc = (new OpenApiGenerator(
    ['title' => 'My API', 'version' => '1.0.0'],
    [['url' => 'https://api.example.com']],
    $overridesArray
))->fromDirectory(ROOT . '/src/Controllers', 'App\\Controllers');
```

## What a classic-MVC action must accept

`Controller::exec()` calls every action the same way:

```php
fn() => $this->$action($args)
```

`$args` is the request's arguments **array**. So an action must accept an array as
its first parameter — `mixed`, `array`, `iterable`, or nothing at all:

```php
// Right — and this is the convention the bundled controllers use
public function view(mixed $id = null): mixed
{
    $id = (int) \Pramnos\Http\Request::staticGetOption();
    // …
}

// Wrong — a guaranteed TypeError the moment anything routes to it
public function logs(string $name = ''): mixed
```

The URL segment is read with `Request::staticGetOption()`, not taken as an
argument. The parameter is kept for the signature's sake and ignored.

A scalar declaration is not a bug that appears under some input: the action cannot
be called at all. A structural test walks every bundled controller, so a scalar first
parameter fails in the suite rather than on a click.

## An administration area under a prefix

Admin screens usually want to live under one path, with their own layout and a
floor on who may reach any of them:

```
/admin              → the admin front page
/admin/Users        → the Users controller
/admin/Applications/edit/5
```

Configure it once — and **`pramnos init` writes this block for you**, because the same
command writes the sixteen controllers that live inside the area:

```php
// app/app.php
'admin' => [
    'prefix'             => 'admin',     // omit, or leave empty, to switch the area off
    'theme'              => 'admin',     // theme used inside the area (optional)
    'min_usertype'       => 98,          // floor for reaching any of it (optional)
    'default_controller' => 'Dashboard', // what the bare /admin opens (optional)
],
```

**Removing the block does not merely unmount the routes — it takes the controllers out of
scope.** `src/Admin/` is only on the controller path while the area is active, so without
it `<Ns>\Admin\Controllers\Users` is not a class anybody can reach, and `adminUrl('Users')`
renders `/Users` because the prefix is empty. The screens answer 404 while the menu still
lists them, since the navigation is built from the registry rather than from routes: the
area looks present and nothing in it opens.

`min_usertype` is the floor for the **area**, and 98 is what `init` writes — the floor of every
bundled screen (`AdminAccess::defaultUsertype()`). Somebody below it reaches the area only when a
role opens a screen to them (`admin_access = mixed` or `permissions`); each controller still
enforces its own check.

Set `default_controller` unless you want the bare prefix to fall through to the
site's own default — which is usually the public home page, and which for a
signed-in visitor usually redirects to their account. An administrator clicking the
area's logo would leave the area.

That is the whole setup: **no per-controller prefix handling**. The prefix is removed
before routing splits the path into controller and action, so actions, `_option` and
the key/value tail behave exactly as they do without it.

### Where the area's code lives

The area has its own directory, the counterpart of `src/Api/`:

```
src/Admin/Controllers/Users.php     namespace <Ns>\Admin\Controllers;
src/Admin/Views/users/…
src/Controllers/Home.php            the site's own
src/Views/home/…
```

Inside the area the framework looks there **first** and falls through to the site's
own — so an area holds the screens that belong to it, not a copy of the application. A
shared `Home`, a shared partial and an application with no `src/Admin` at all work
unchanged.

Outside the area, `src/Admin/` is not in scope. That is the part that matters:

```
/admin/Users   → <Ns>\Admin\Controllers\Users
/Users         → 404
```

**A controller left in `src/Controllers/` still answers on both paths**, which is what
keeps every existing project working — and is also a second front door to the same
page, in the public theme, with no sidebar and outside the area's floor. `pramnos init`
puts every admin screen under `src/Admin/`; `pramnos project:publish-views` publishes an
admin view group to `src/Admin/Views/` for the same reason. To move an existing
project's screens, move the file and change its namespace — nothing else refers to it.

`Health` is deliberately *not* an admin controller: `/health/check` is the JSON endpoint
an uptime monitor calls, and putting a usertype floor in front of a monitoring URL is
how a project finds out its monitor has been reporting "down" for a week.

The directory is named `Admin` by convention; `'area' => 'Ops'` in the `admin` config
block makes it `src/Ops/`.

### What changes inside the area

| | Outside | Inside |
|---|---|---|
| Route | `Users/edit/5` | `Users/edit/5` — identical |
| Theme | `theme` from app.php | `admin.theme`, when set |
| Access | each controller's own check | that, **plus** `min_usertype` |

The floor is not a replacement for the checks each controller makes; those still
run, and several are stricter. It is what stops the *area* being browsable, so a
screen that forgot its own check is not the only thing between an ordinary
account and the dashboard.

!!! danger "The floor does not protect a controller that has no check of its own"
    A controller in `src/Controllers/` answers on both `/admin/Settings` and
    `/Settings`, and only the first goes through the prefix. So a controller relying on
    the area's floor is protected on exactly the paths an attacker has no reason to
    use. Moving it under `src/Admin/` closes the bare path — but the check is still
    the thing that protects the screen.

    `addAuthAction()` requires only *being signed in*. A settings screen that relies on it
    and on the area's floor refuses an ordinary account at `/admin/settings` and serves it
    the whole form — SMTP credentials included — at `/settings`.

    Every screen inside the area needs its own `requiredUserType` and a
    `requireMinUserType()` call in each action — it is on `Application\Controller`, so
    every controller has it. Treat the area's floor — and the
    `src/Admin/` layout — as defence in depth, never as the check.

The two refusals differ on purpose. A guest is sent to sign in with a `return=`
carrying the address they asked for, so signing in lands them where they were
going. A signed-in user below the floor is sent to the site root instead —
showing them a login form they are already past reads as a broken session, and
they retype their password rather than understanding they lack the privilege.

The `return=` is the **whole path, prefix included** — `/admin/Users`, not `Users`, which
would land the guest on the site's page of that name. The same holds for a controller's own
`addAuthAction()` refusal inside the area. The sign-in page is the site's (`login`, or the
application's `'auth' => ['login_url' => …]`) unless the area has its own:

```php
\Pramnos\Http\AdminArea::useLogin(sURL . 'admin/login');   // e.g. in Application::init()
```

### Links inside the area

A view inside the area **cannot** link with a bare `sURL`:

```php
<a href="<?php echo sURL; ?>Users/edit/5">Edit</a>       <!-- leaves the area -->
<a href="<?php echo adminUrl('Users/edit/5'); ?>">Edit</a>  <!-- stays in it -->
```

The first one reaches the same controller with the *site* layout — no sidebar, no
admin chrome — which is a confusing way to lose somebody mid-task. Every table
row, "back" link and pagination control in the bundled admin views goes through
`adminUrl()` for that reason, and there is a test that walks all three themes
looking for the bare form.

With no area configured `adminUrl('Users')` is exactly `sURL . 'Users'`, so one
view serves an application that has an area and one that does not.

**With no path it is a base, and ends in a slash** — `adminUrl()` is to the area what
`sURL` is to the site, so `adminUrl() . 'Users'` and `adminUrl('Users')` are the same
string. The breadcrumb partials rely on that.

**In a template, use the constant.** Two bases, each naming what it is:

```php
<a href="<?php echo sURL; ?>login">Sign in</a>     <!-- the site -->
<a href="<?php echo URL; ?>Users">Users</a>        <!-- the administration area -->
<a href="<?php echo adminUrl('Users'); ?>">Users</a>   <!-- identical to the second -->
```

`URL` is the area's base and `sURL` the site's, so the two constants answer the two questions a
link has.

`adminUrl()` is the same answer for code that runs where constants may not be defined — a
controller under test, a CLI render — which is why the bundled views use it. Both are
`AdminArea::url()` underneath, so they cannot disagree, and both equal `sURL` when no area
is configured.

**User-facing links stay bare on purpose.** An administrator clicking "My account"
wants the public account page, not an admin-framed copy of it, so `account`,
`login`, `register`, `Passkey` and `TwoFactorAuth` are addressed with `sURL`.

### Navigation

Admin `NavItem`s point into the area automatically, from anywhere — including the
public site header, which shows the same section. With no area configured they
are plain application URLs. The underlying calls:

```php
adminUrl('Users');                       // the helper the views use
\Pramnos\Http\AdminArea::url('Users');   // what it delegates to
\Pramnos\Http\AdminArea::isActive();     // is this request inside the area?
\Pramnos\Http\AdminArea::servingUrl();  // the base of whatever is serving this request
```

`servingUrl()` is for a page reachable both at the site root and inside an area — the
DevPanel, Adminer and the debug-toolbar grant build their links from it. Inside the area it is
the area's URL, outside it `sURL`; with no area configured it is `URL`, which an application
mounted as a secondary application defines as its own URL. No trailing slash. `URL` alone
cannot answer this, because it is the area's URL on every request, inside the area or not.

### An administration with a front controller of its own

An administration served by its own `www/admin/index.php`, rather than by a prefix the site's
routing strips, says that it **is** the area, in its config:

```php
// the administration's config
'admin' => [
    'serve'        => true,      // this application is the area
    'prefix'       => 'admin',
    'min_usertype' => 90,
    'theme'        => 'admin',   // optional, as for an area
],
```

Every request it serves is then inside the area, from the constructor on:

- the navigation, the area's language, `adminUrl()`, help links and the side panel are built
  on the area's URL;
- screens apply the floor;
- `src/Admin/Controllers` and `src/Admin/Views` are in scope, as they are for an area;
- nothing in the route is rewritten.

The area's URL is `sURL . 'admin/'`, or `sURL` itself when the front controller in
`www/admin/` made `sURL` end in `admin/` already, so links never read `/admin/admin/`.

`AdminArea::serve('admin', 90)` in code does the same and holds for every request one process
serves. Called after `parent::init()`, though, it finds the framework's navigation already built
on the site's URL: it builds it again and logs that the config is the place for it. `detect()` is
for the other arrangement, where one front controller serves both.

### Moving a separate admin application into the area

An administration can be served two ways, and an application uses one or the other:

- **A separate application.** `www/admin/index.php` builds a second `Application` subclass,
  with its own config file (`app/admin.php`), its own `init()`, `exec()` and `render()`, and
  `'admin' => ['serve' => true]` (or `AdminArea::serve()`) to say it is the area.
- **The area.** The site's own application mounts `src/Admin/` under a prefix, from the
  `admin` block in `app/app.php`. One front controller, one config, one application class.

The area is what `pramnos init` writes, and it is the arrangement this section moves to.

#### What each one gives you

| | Separate application (`serve()`) | Area (`'admin' => ['prefix' => …]`) |
|---|---|---|
| Config | `app/admin.php`, a second copy of `features`, `personal_data`, `help`, `namespace`… | `app/app.php`, once |
| Controllers in scope | `<namespace of admin.php>\Admin\Controllers\X` first, then `<namespace of admin.php>\Controllers\X` | `<Ns>\Admin\Controllers\X` first, then `<Ns>\Controllers\X` |
| Views | `src/Admin/Views/` first, then `src/<appName>/Views/` or `src/Views/` | `src/Admin/Views/` first, then `src/Views/` |
| The area's floor | the `serve()` argument | `admin.min_usertype` |
| Bundled screens' own check (`AdminAccess`) | applies | applies |
| Theme and language inside | whatever the application sets | `admin.theme`, `admin.language` |
| `adminUrl()`, `URL` | `sURL . 'admin/'`, or `sURL` when it already ends in `admin/` | `sURL . '<prefix>/'` |
| Help levels | the administration's only | the site's outside the area, the administration's inside |
| Help and export links built in a queue worker | only from the `base` the request handed the job | from the `base`, or from `admin.prefix` in `app.php` |
| Public pages linking into the admin (header nav) | not from the site application, which has no prefix to build them on | yes — admin `NavItem`s point into the area from anywhere |

What both arrangements share: every bundled screen enforces its own floor through
`AdminAccess` (98 by default, `admin_default_usertype` to change it, roles under
`admin_access = mixed` or `permissions`), the Help screen and the Ask side panel are built on
the area's URL, and the guest is sent to sign in with the whole path in `return=`.

**Why move.** Two config files drift: a feature enabled in `app.php` and not in `admin.php`
is on for the site and off for the administration of the same site, and the two
applications disagree about which help pages exist. The separate application also has no
way to give the public site links into the administration, and its queue jobs can only
build area links from what the request handed them. The area has one config, one class,
and one place that knows the prefix.

#### Step by step

**1. Config: merge `app/admin.php` into `app/app.php`.**

Everything an application reads from `applicationInfo` comes from the one file its
constructor loaded, so after the move only `app.php` is read for the administration's
requests too:

- `features` — the union of both lists. A feature only the administration had enabled is
  now enabled for the site as well; that is the point, but check what each one registers.
- `personal_data`, `help` (including `help.paths` and `help.site`), `middleware`,
  `component_classes`, `scripts`, `css` — merge, and keep the administration's entries.
- `admin` — the area's block:

```php
// app/app.php
'admin' => [
    'prefix'             => 'admin',     // the URL the separate application had
    'theme'              => 'admin',     // the theme admin.php selected
    'min_usertype'       => 90,          // what admin.php passed to serve()
    'default_controller' => 'Dashboard', // what the bare /admin opened
    'language'           => 'english',   // optional: the administration's own language
],
```

Keep `prefix` equal to the separate application's directory name and every bookmarked
address, `return=` and emailed link keeps working.

**2. Controllers: `src/Admin/Controllers/`, namespace `<Ns>\Admin\Controllers`.**

`<Ns>` is `namespace` in `app.php`, and with the scaffolded `"<Ns>\\": "src/"` PSR-4 entry
the directory is `src/Admin/Controllers/`. A separate application whose `admin.php` already
set `namespace` to `<Ns>\Admin` has its controllers at exactly that class name, and they move
as they are. Any other namespace: move the file, change the `namespace` line — nothing else
refers to a controller by class name. `'area' => 'Ops'` in the `admin` block makes the
directory `src/Ops/` and the namespace `<Ns>\Ops\Controllers`.

A controller with the same name as one of the site's — both had a `Home` — keeps working:
inside the area `<Ns>\Admin\Controllers\Home` is found first, outside it the site's.

**3. Views: `src/Admin/Views/<group>/`.**

Inside the area, `src/Admin/Views/` is searched before `src/Views/`, so only the views that
differ from the site's need to be there. A view *class*, where there is one, is
`<Ns>\Admin\Views\<Name>`.

Then fix links. A view written for the separate application may have built links on `sURL`
or `URL` of *that* application, where `sURL` was its own directory. Inside the area `sURL`
is the site and `URL` is the area:

```bash
grep -rn "sURL" src/Admin/Views   # each hit: a link to the site, or one that should be adminUrl()
```

`adminUrl('Users/edit/5')` and `URL . 'Users/edit/5'` are the area's addresses; `sURL` is for
`login`, `account` and other user-facing pages.

**4. The application class: fold `init()` and `exec()` overrides into the site's.**

Whether a request is inside the area is decided in the constructor, before `init()` runs, so
the site's own `init()` can branch on it:

```php
public function init($settingsFile = '')
{
    parent::init($settingsFile);
    if (\Pramnos\Http\AdminArea::isActive()) {
        // what the separate application's init() did after parent::init()
    }
    return $this;
}
```

Remove the `AdminArea::serve()` call: in the site's application it would mark every request,
public pages included, as inside the area — the floor would apply to the home page. A
`render()` override that existed to select an admin layout is usually replaced by
`admin.theme`. An own sign-in page is `AdminArea::useLogin(sURL . 'admin/login')`.

**5. Front controller and `.htaccess`: delete `www/admin/`.**

The scaffolded `www/.htaccess` sends a path to `index.php?r=…` only when it is neither a file
nor a directory:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?r=$1 [QSA,L]
```

So as long as `www/admin/` exists the web server answers `/admin` from it and the site's
`index.php` never sees the request. Move anything else in that directory — admin-only CSS,
JavaScript, images — under the web root's `assets/`, then delete the directory: an empty
`www/admin/` still makes the bare `/admin` a directory request.

The site's `www/index.php` builds the `Application` before anything builds a `Request`, which
is what the area needs. An administration front controller that ran its own middleware
moves that list into `middleware` in `app.php`; it now runs on every request, so a
middleware meant only for the administration checks `AdminArea::isActive()`.

**6. What else carried the application's name.** An application constructed with a name
(`new Application('admin')`) reads `app/<name>.php`, looks for views under `src/<name>/` and
for legacy migrations under `app/Migrations/<name>/`. After the move none of those paths is
searched.

#### Running both during a transition

**Not under the same prefix.** Whichever front controller the web server picks for
`/admin/…` serves it; while `www/admin/` exists, that is the separate application, and the
site's prefix is never stripped from a request it never receives.

**Under two prefixes, yes.** Mount the area somewhere else while the separate application
keeps `/admin/`:

```php
// app/app.php — the area, while www/admin/ still serves /admin/
'admin' => ['prefix' => 'manage', 'min_usertype' => 90, 'default_controller' => 'Dashboard'],
```

The two are different PHP processes, so each request has one area state: `/manage/Users` is
the site's application in the area, `/admin/Users` is the separate application. What follows
from that:

- **Links follow the application that rendered them.** The site's admin navigation, the
  public header's admin items and help links built in a queue worker running the site's
  application point at `/manage/`; pages rendered by the separate application point at
  `/admin/`. Views that use `adminUrl()` or `URL` are correct in both; a view with a hardcoded
  `admin/` sends the area to the old application.
- **Sharing `src/Admin/` between them.** A separate application that serves the area puts
  `<its namespace>\Admin\Controllers` and `src/Admin/Views` in scope, as the area does. With
  `admin.php`'s `namespace` the same as `app.php`'s, the two reach the same controllers and views,
  so screens moved to `src/Admin/` answer at both addresses during the transition.
- **Keep the two floors equal** — the `serve()` argument and `admin.min_usertype` — or the same
  screen is open to different accounts depending on the address.
- **Finishing the transition** is the move above: switch `prefix` to `admin`, delete `www/admin/`.
  Links to `/manage/` from the transition then 404; if any were sent out, a rewrite from
  `manage/` to `admin/` in `www/.htaccess` keeps them working.

#### Checking the move

Request each of these and compare with what the separate application did:

| Request | As | Expected |
|---|---|---|
| `/admin` | administrator | `default_controller`, in `admin.theme` |
| `/admin/Users` | guest | redirect to sign in, `return=%2Fadmin%2FUsers` |
| `/admin/Users` | signed-in account below `min_usertype`, no role opening a screen | redirect to the site root |
| `/admin/Users` | administrator | the screen, links in it under `/admin/` |
| `/Users` | anyone | 404, when the controller is under `src/Admin/Controllers/` |
| a public page | administrator | header admin items lead to `/admin/…` |
| `/admin/Help` | administrator, `help` feature | the administration's pages; on a public page, the site's |

The `/Users` row is the one that matters most: a controller left in `src/Controllers/`
answers on both paths, the bare one outside the area's floor. And every screen keeps its own
`requiredUserType` / `requireMinUserType()` — the area's floor is defence in depth, not the
check.

### One thing to know

Detection happens in `Application::__construct()`, because the prefix has to be
gone before anything constructs a `Request` — that is when the path is split. An
application whose front controller builds a `Request` *before* its `Application`
will route the prefix as a controller name. No scaffolded front controller does
that, and the fix is to swap the two lines.

The prefix must match a **whole segment**: `/administration` is not inside an area
mounted at `admin`, and `REQUEST_URI` is never rewritten, so every `return=`,
log line and session record keeps the address the visitor actually asked for.

## Ending a request from a controller — `terminate()`, never `exit`

A controller that has written a complete response — a file download, another application's HTML,
a redirect it has already sent — has to stop the request before the framework renders a theme
after it. `Controller::terminate()` is how:

```php
http_response_code(200);
header('Content-Type: application/pdf');
readfile($path);
$this->terminate();
```

**Never write `exit` for this.** Under a test runner `exit` does not fail — it ends the process:
no summary, no failure count, and **exit status 0**, which every CI reads as a pass, while
nothing reports anything wrong.

`terminate()` exits in production and throws `ApplicationClosedException` under any test runner —
`PRAMNOS_TESTING`, which the framework's own test bootstrap defines, or either of the constants
PHPUnit defines whichever way it was installed. The type is the same one `Application::close()`
throws, so a test that already catches it around one part of the framework catches this too.

### Testing a controller that ends the request

Expect the exception; do not mock the method away:

```php
$this->expectException(\Pramnos\Application\ApplicationClosedException::class);
$controller->download('report.pdf');
```

The message names the class (`App\Controllers\Reports::terminate() called`), which is what tells
you *which* controller stopped a run that stopped somewhere unexpected.

### A redirect is `$this->redirect()`, not `header()` and `terminate()`

```php
$this->redirect($url);                        // records it, sends it, ends the request

// Where the URL must not reach the development `redirects` log — it carries a code or a token:
$this->application->setRedirect($url);
$this->application->redirect();
```

`Application::redirect()` records the destination, which `getRedirect()` returns and a
`TestClient` answers as the response's `Location`. A bare `header('Location: …')` leaves nothing:
PHP keeps no headers on the CLI, so the test sees a 302 with an empty `Location` and the redirect
goes untested. `RedirectsAreRecordedTest` fails on a bare one in `src/`.

### Why it is not a seam to override

It is `protected`, so a subclass still can. But a seam only helps somebody who already knows they
need it, and the next controller to end a request is written by somebody who does not. The default
is right, in one place, and overriding it is for a controller that needs something else rather than
for one that needs this.

## `sURL` is the script's base, not the site root

`sURL` comes from `getUrl()`, which ends in `dirname($_SERVER['SCRIPT_NAME'])`. That is the
site root right up until the project grows a second front controller:

```
www/index.php      →  sURL = https://example.com/
www/api/index.php  →  sURL = https://example.com/api/     ← same site, same request
```

So an asset under the document root, built during an API request as
`sURL . 'uploads/x.jpg'`, is `https://example.com/api/uploads/x.jpg` — a 404. The expensive
part is that it is *absolute and well-formed*, so every "is this a fetchable address" check
passes it: an external service handed it fetches it, gets a 404 and answers with an error that
never mentions the URL.

And `getUrl()` cannot answer at all from the command line: there is no `SERVER_NAME`, so a
scheduled task putting a public URL in an email, a webhook payload or a feed builds
`http:///uploads/x.jpg`.

### `SiteUrl` is the site root

```php
use Pramnos\Http\SiteUrl;

SiteUrl::to('uploads/' . $name);   // https://example.com/uploads/x.jpg
SiteUrl::get();                    // the root, with a trailing slash
```

It resolves in this order:

1. **`APP_URL`** in the environment — `init` writes the key into `.env` and `.env.example`,
   blank, because a development URL written there is copied into production by the first
   person who copies the file.
2. **`'site_url'`** in `app/config/app.php`, for a project that configures in PHP.
3. **The current request** — scheme and host, plus the path of the *document root* rather
   than of the script. It takes `SCRIPT_NAME` and removes the part of `SCRIPT_FILENAME`
   that sits below `DOCUMENT_ROOT`, so `www/api/index.php` under `www/` answers `/`, and a
   site mounted at `/shop` keeps its `/shop`.
4. **`''`** — CLI with nothing configured. Empty rather than a guess, and `to()` then
   returns the path unchanged: a relative path is visibly incomplete to whatever receives
   it, where a wrong absolute URL is accepted by everything and fetched by something.

`health:check` reports `site_url`, and reports **degraded** while the value is only being
inferred from a request — because a web request almost always infers a usable one, so
"it works on the site" says nothing about whether the scheduler can build a URL.

`sURL` is unchanged and still right for what it is: a link to another page of the
application served by the same front controller. Use `SiteUrl` for an address that has to
work somewhere else — an asset, an email, a webhook payload, a feed.

## Reference

For related guides:

- [Pramnos_Framework_Guide.md](Pramnos_Framework_Guide.md) — Middleware pipeline, global middleware
- [Pramnos_API_Guide.md](Pramnos_API_Guide.md) — API middleware, CORS, authentication

**Topics covered:**

- Route definition with all HTTP methods
- URI parameters, optional parameters and permissions
- Named routes and URL generation
- Route groups with prefixes, middleware, permissions, and name prefixes
- Attribute-based routing discovery (`#[Route]`, `#[RouteGroup]`)
- Distinguishing a wrong verb from a wrong address (`allowedMethodsFor()`, 405 with `Allow`)
