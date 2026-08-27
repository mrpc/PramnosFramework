---
use_cases:
  - Registering a route, a route group or a REST resource
  - Using #[Route] attribute routing on a controller
  - Attaching middleware to routes
  - Binding a model to a route parameter
  - Diagnosing a URL that answers 404 only when it carries a query string
  - Working out why HEAD requests, link checkers or uptime monitors report a page as missing
  - Answering 405 Method Not Allowed instead of 404 for a wrong HTTP verb
  - Generating OpenAPI documentation from route attributes
  - Putting the administration screens behind an /admin prefix with their own layout
---

# Pramnos Modern Routing Guide

The **Routing Engine** in Pramnos v1.2 supports both attribute-based and fluent API routing with parameter binding, groups, and middleware support.

**Class:** `Pramnos\Routing\Router`

## Getting Started

### Define Routes

```php
// routes/web.php or routes/api.php
$router = \Pramnos\Routing\Router::getInstance();

// Simple GET route
$router->get('/users', 'UserController@index');

// GET with parameter
$router->get('/users/{id}', 'UserController@show');

// POST route
$router->post('/users', 'UserController@store');

// PATCH/PUT route
$router->patch('/users/{id}', 'UserController@update');

// DELETE route
$router->delete('/users/{id}', 'UserController@destroy');

// Multiple methods
$router->match(['get', 'post'], '/search', 'SearchController@handle');

// All HTTP methods
$router->any('/webhook', 'WebhookController@handle');
```

### Route Parameters

```php
// Simple parameter
$router->get('/users/{id}', 'UserController@show');

// Constrained parameter (regex)
$router->get('/posts/{id}', 'PostController@show')->where('id', '[0-9]+');

// Multiple constraints
$router->get('/files/{year}/{month}/{slug}', 'FileController@show')
    ->where('year', '[0-9]{4}')
    ->where('month', '[0-9]{2}')
    ->where('slug', '[a-z0-9-]+');

// Global constraints (apply to all routes)
$router->pattern('id', '[0-9]+');
$router->pattern('slug', '[a-z0-9-]+');
```

### Named Routes

```php
$router->get('/users/{id}', 'UserController@show')->name('users.show');

// Generate URL from name
$url = route('users.show', ['id' => 42]);
// → /users/42
```

## Route Groups

### Prefix & Middleware

```php
$router->group([
    'prefix'     => 'admin',
    'middleware' => ['auth', 'admin'],
], function ($router) {
    $router->get('/dashboard', 'Admin/DashboardController@show');
    $router->get('/users', 'Admin/UserController@index');
    $router->post('/users', 'Admin/UserController@store');
});

// Routes:
// GET /admin/dashboard
// GET /admin/users
// POST /admin/users
```

### Route Groups with Namespaces

```php
$router->group(['namespace' => 'Api'], function ($router) {
    $router->get('/users', 'UserController@index');
    // Resolves to Api\UserController@index
});
```

### Nested Groups

```php
$router->group(['prefix' => 'api'], function ($router) {
    $router->group(['prefix' => 'v1', 'middleware' => 'api'], function ($router) {
        $router->get('/users', 'UserController@index');
    });
});

// Route: GET /api/v1/users
```

## Middleware

### Apply Middleware

```php
// Single route
$router->get('/profile', 'ProfileController@show')->middleware('auth');

// Multiple middleware
$router->post('/users', 'UserController@store')
    ->middleware(['auth', 'verified']);

// Entire group
$router->group(['middleware' => ['auth', 'csrf']], function ($router) {
    $router->post('/settings', 'SettingsController@update');
});

// Exclude middleware
$router->get('/login', 'AuthController@login')
    ->withoutMiddleware(['csrf']);  // CSRF not required for login form
```

## Attribute-Based Routing

### Route Attributes (PHP 8+)

```php
<?php
namespace App\Controllers;

use Pramnos\Routing\Attributes\{Route, Middleware, Name};

class UserController extends \Pramnos\Application\Controller
{
    #[Route('GET', '/users')]
    #[Name('users.index')]
    public function index()
    {
        // GET /users
    }
    
    #[Route('GET', '/users/{id}')]
    #[Name('users.show')]
    public function show($id)
    {
        // GET /users/{id}
    }
    
    #[Route('POST', '/users')]
    #[Middleware('auth')]
    public function store()
    {
        // POST /users (requires auth)
    }
    
    #[Route('PATCH', '/users/{id}')]
    #[Middleware('auth')]
    public function update($id)
    {
        // PATCH /users/{id}
    }
}
```

### Auto-Discovery of Routes

```php
// Scan controllers for attributes
$router->discoverRoutes([
    'path'      => 'app/Controllers',
    'namespace' => 'App\\Controllers',
]);
```

## HEAD is answered by GET

**A route registered for GET answers HEAD as well.** You do not declare it and you cannot forget
it.

```php
$router->addRoute('station/{slug}', 'GET', 'StationController@show');
```

| Request | Route |
|---|---|
| `GET /station/athens` | matches, `{slug}` = `athens` |
| `HEAD /station/athens` | matches, `{slug}` = `athens` |
| `POST /station/athens` | no match |

This is RFC 9110 §9.3.2 — *"The HEAD method is identical to GET except that the server MUST NOT
send content"* — and it matters far more than the verb's reputation suggests: HEAD is what link
checkers, uptime monitors, `curl -I` and several crawlers send first. Until 2026-08-18 the router
kept a separate table per method and answered **404 to HEAD for every page**, so an entirely
reachable site reported as entirely broken. See the changelog post *Every page answered 404 to
HEAD*.

**Declaring HEAD explicitly still wins**, and is worth doing when the answer can be cheaper than
the GET:

```php
// A GET that renders a report, and a HEAD that only decides whether it exists.
$router->addRoute('report/{id}', 'GET',  'ReportController@show');
$router->addRoute('report/{id}', 'HEAD', 'ReportController@exists');
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
$router->addRoute('station/{slug}', 'GET', 'StationController@show');
```

| Request | `{slug}` receives |
|---|---|
| `/station/athens` | `athens` |
| `/station/athens?fbclid=abc` | `athens` |
| `/station/athens?return=/station/other` | `athens` |

The parameters themselves are read the ordinary way — `$request->get('fbclid', '', 'get')` or
`$_GET` — not from the route.

This is worth stating because it was wrong until 2026-08-18, and wrong in a way that only showed
on routes with a placeholder. The pattern was tried against the URI with its query string still
attached, and a placeholder compiles to `[^/]+`, which a query string satisfies — so
`/station/athens?fbclid=abc` matched with a slug of `athens?fbclid=abc`, and the controller
answered 404 for a page that exists. Every link shared on a network that appends a tracking
parameter was affected. See the changelog post *A placeholder that ate the query string*.

**One exception, by design:** a route registered *with* a query string in its own URI matches on
that exact form.

```php
// Matches `/legacy?page=2` and nothing else.
$router->addRoute('legacy?page=2', 'GET', 'LegacyController@page');
```

Use it only for an address that must be preserved verbatim; ordinary paging belongs in a
placeholder or in `$_GET`.

## Leading slashes, and the shape of a request URI

**Write the leading slash wherever you like — in the route, in `Request::create()`, in
`$_SERVER['REQUEST_URI']`.** Matching normalises all three.

```php
$router->get('/stations/{id}', 'StationController@show');   // route: with
$router->get('stations/{id}',  'StationController@show');   // route: without — same thing

Request::create('/stations/7');   // → getRequestUri() === 'stations/7'
Request::create('stations/7');    // → getRequestUri() === 'stations/7'
```

`getRequestUri()` always answers **without** leading or trailing slashes, whichever way the
Request was built. Code that concatenates it into a path should still write
`'/' . ltrim($uri, '/')` rather than `'/' . $uri` — cheap, and it survives a caller who sets the
static directly.

This is worth stating because it was wrong until 2026-08-24. `Request::create()` stored its
argument verbatim while the constructor trimmed, so the two disagreed; `Route::matches()` then
prefixed a slash unconditionally and tried `//stations/7` against an anchored pattern. **Every
route with a placeholder missed, and every static route worked** — which is why it survived so
long: the routes anybody tests first are the ones that were fine. See the changelog post *The
slash that only broke the routes with placeholders*.

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

## Route Model Binding

### Implicit Binding

```php
$router->get('/users/{user}', 'UserController@show');

// Automatically inject User model
class UserController extends \Pramnos\Application\Controller
{
    public function show(\App\Models\User $user)
    {
        // $user is automatically loaded by ID from the URL
        return view('users.show', ['user' => $user]);
    }
}
```

### Custom Key

```php
$router->get('/posts/{post:slug}', 'PostController@show');
// Binds by slug instead of ID
```

## Fallback & View Routes

### Catch-All Route

```php
// Must be last!
$router->get('/{path}', 'PageController@show')->where('path', '.*');
```

### Redirect Route

```php
$router->redirect('/home', '/dashboard');
$router->redirectPermanent('/old-path', '/new-path');  // 301
```

### View Route

```php
// Render view directly without controller
$router->view('/about', 'pages.about');

// With data
$router->view('/contact', 'pages.contact', [
    'email' => 'contact@example.com',
]);
```

## Route Listing

### View All Routes

```bash
php vendor/bin/pramnos route:list

# Output:
# GET    /users              UserController@index
# GET    /users/{id}         UserController@show
# POST   /users              UserController@store
```

## Complete Example

```php
// routes/web.php
$router = \Pramnos\Routing\Router::getInstance();

// Public routes
$router->get('/', 'HomeController@show')->name('home');
$router->get('/about', 'PageController@show', ['page' => 'about'])->name('about');
$router->get('/contact', 'ContactController@show')->name('contact');
$router->post('/contact', 'ContactController@store');

// Authentication routes
$router->group(['middleware' => 'guest'], function ($router) {
    $router->get('/login', 'Auth/LoginController@show')->name('login');
    $router->post('/login', 'Auth/LoginController@store');
    $router->get('/register', 'Auth/RegisterController@show')->name('register');
    $router->post('/register', 'Auth/RegisterController@store');
});

// Protected routes
$router->group(['middleware' => ['auth', 'verified']], function ($router) {
    $router->get('/dashboard', 'DashboardController@show')->name('dashboard');
    $router->post('/logout', 'Auth/LogoutController@store')->name('logout');
    
    // User profile
    $router->get('/profile', 'ProfileController@show')->name('profile.show');
    $router->patch('/profile', 'ProfileController@update')->name('profile.update');
});

// Admin routes
$router->group(['prefix' => 'admin', 'middleware' => ['auth', 'admin']], function ($router) {
    $router->get('/', 'Admin/DashboardController@show')->name('admin.dashboard');
    
    $router->resource('users', 'Admin/UserController');  // REST resource
    $router->resource('posts', 'Admin/PostController');
});

// API routes
$router->group(['prefix' => 'api/v1', 'middleware' => 'api'], function ($router) {
    $router->get('/users', 'Api/UserController@index');
    $router->get('/users/{id}', 'Api/UserController@show')->where('id', '[0-9]+');
    $router->post('/users', 'Api/UserController@store')->middleware('auth:api');
});
```

## REST Resource Routes

### Auto-Generate CRUD Routes

```php
// Generates all 7 RESTful routes
$router->resource('posts', 'PostController');

// Generated routes:
// GET    /posts              PostController@index
// GET    /posts/create       PostController@create
// POST   /posts              PostController@store
// GET    /posts/{id}         PostController@show
// GET    /posts/{id}/edit    PostController@edit
// PATCH  /posts/{id}         PostController@update
// DELETE /posts/{id}         PostController@destroy
```

### Customize Resource Routes

```php
$router->resource('comments', 'CommentController')
    ->only('index', 'show', 'store', 'destroy')  // Exclude create/edit
    ->except(['create', 'edit'])                  // Same as above
    ->names('comments.list', 'comments.view');    // Custom names
```

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
separate `routes.php` + `@api` comment blocks to keep in sync. This is the
attribute-native alternative to the older apidoc/JSDoc flow.

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

That first line exists because its absence cost somebody an hour. The command used to
default to `src/Controllers` and report only where it wrote, so an application keeping
its API in `src/Api/Controllers` got `Wrote 1 path(s), 1 operation(s)` for 72
endpoints — every word of it true. **A document describing one endpoint of seventy-two
is not obviously broken; it is indistinguishable from an application that has one
endpoint**, so it gets published and believed.

For the same reason, a run that finds fewer operations than a sibling directory holds
says so and names it:

```
src/Api/Controllers holds 96 operation(s) — more than the 1 found in src/Controllers.
Re-run with --controllers=src/Api/Controllers if that is the API.
```

Nothing is switched under you, and the check is skipped entirely when you passed
`--controllers` yourself — naming the directory is a decision, not a guess to correct.

**`--namespace` follows `--controllers`.** It is derived from the application
namespace in `app/app.php` plus the path after `src/`, so
`--controllers=src/Api/Controllers` gives `App\Api\Controllers`. It used to append a
fixed `\Controllers` regardless, which is why passing `--controllers` alone found
nothing at all and still exited successfully.

What is derived automatically: paths and methods (with `{param}` segments becoming
path parameters), `operationId` (from the route name), `summary`/`description`
(from the handler's docblock), a `bearerAuth` security requirement for routes that
declare permissions or an auth middleware, and `tags` from the controller name.

What cannot be inferred from routes alone — request/response schemas, examples — is
supplied through the `--overrides` document, which is **deep-merged** over the
generated one (scalars and objects are overridden per key; the generated paths are
preserved). This mirrors the `openapi-overrides.json` convention used elsewhere.

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
be called at all. Five bundled actions had one — `ServicesController`'s `stop`,
`start`, `restart` and `logs`, which is every button on the services screen, and
`LogController::clearFile()`. All are fixed, and a structural test now walks every
bundled controller so the next one fails in the suite rather than on a click.

## An administration area under a prefix

Admin screens usually want to live under one path, with their own layout and a
floor on who may reach any of them:

```
/admin              → the admin front page
/admin/Users        → the Users controller
/admin/Applications/edit/5
```

Configure it once:

```php
// app/app.php
'admin' => [
    'prefix'             => 'admin',     // omit, or leave empty, to switch the area off
    'theme'              => 'admin',     // theme used inside the area (optional)
    'min_usertype'       => 80,          // floor for reaching any of it (optional)
    'default_controller' => 'Dashboard', // what the bare /admin opens (optional)
],
```

Set `default_controller` unless you want the bare prefix to fall through to the
site's own default — which is usually the public home page, and which for a
signed-in visitor usually redirects to their account. An administrator clicking the
area's logo would leave the area.

That is the whole setup. **No second set of controllers, and no per-controller
prefix handling** — `/admin/Users` and `/Users` are served by the same
`UsersController`, because the prefix is removed before routing splits the path
into controller and action. Actions, `_option` and the key/value tail behave
exactly as they do without the prefix, so there is no second code path.

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
    `/admin/Settings` and `/Settings` are the same controller, and only the first
    goes through the prefix. So a controller relying on the area's floor is
    protected on exactly the paths an attacker has no reason to use.

    `SettingsController` was in that state until 2026-08-27: it declared its
    actions with `addAuthAction()`, which requires only *being signed in*, and had
    no usertype floor. `/admin/settings` correctly refused an ordinary account;
    `/settings` served it the whole form — including the SMTP host, user and
    password rendered into fields — and `POST /Settings/saveSystem` rewrote
    `site_url`, `forcessl`, `admin_mail` and the login lockout rules.

    Every screen inside the area needs its own `requiredUserType` and a
    `requireMinUserType()` call in each action. Treat the area's floor as defence
    in depth, never as the check.

The two refusals differ on purpose. A guest is sent to sign in with a `return=`
carrying the address they asked for, so signing in lands them where they were
going. A signed-in user below the floor is sent to the site root instead —
showing them a login form they are already past reads as a broken session, and
they retype their password rather than understanding they lack the privilege.

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

**User-facing links stay bare on purpose.** An administrator clicking "My account"
wants the public account page, not an admin-framed copy of it, so `account`,
`login`, `register`, `Passkey` and `TwoFactorAuth` are addressed with `sURL`.

### Navigation

Admin `NavItem`s point into the area automatically, from anywhere — including the
public site header, which shows the same section. With no area configured they
are plain application URLs, as before. The underlying calls:

```php
adminUrl('Users');                       // the helper the views use
\Pramnos\Http\AdminArea::url('Users');   // what it delegates to
\Pramnos\Http\AdminArea::isActive();     // is this request inside the area?
```

### One thing to know

Detection happens in `Application::__construct()`, because the prefix has to be
gone before anything constructs a `Request` — that is when the path is split. An
application whose front controller builds a `Request` *before* its `Application`
will route the prefix as a controller name. No scaffolded front controller does
that, and the fix is to swap the two lines.

The prefix must match a **whole segment**: `/administration` is not inside an area
mounted at `admin`, and `REQUEST_URI` is never rewritten, so every `return=`,
log line and session record keeps the address the visitor actually asked for.

## Reference

For related guides:

- [Pramnos_Framework_Guide.md](Pramnos_Framework_Guide.md) — Middleware pipeline, global middleware
- [Pramnos_API_Guide.md](Pramnos_API_Guide.md) — API middleware, CORS, authentication

**Topics covered:**

- Route definition with all HTTP methods
- Parameter binding and constraints
- Named routes and URL generation
- Route groups with prefixes, middleware, permissions, and name prefixes
- Attribute-based routing discovery (`#[Route]`, `#[RouteGroup]`)
- Resource routes and REST conventions
- Distinguishing a wrong verb from a wrong address (`allowedMethodsFor()`, 405 with `Allow`)
