---
use_cases:
  - Adding or changing a JSON API endpoint
  - Scaffolding a REST API controller
  - Handling API authentication, CORS, pagination or versioning
  - Adding middleware to an API route
---

# Pramnos REST API Guide

The Pramnos Framework provides comprehensive support for building REST APIs with automatic endpoint generation, request/response handling, and OAuth2 authentication.

## REST API Scaffolding

### Generate API with pramnos init

```bash
php vendor/bin/pramnos init --rest-api
```

This creates:
- `src/Api/Controllers/` - API endpoint controllers
- `routes/api.php` - API route definitions
- `www/api/index.php` - API entry point
- Configuration for versioning and CORS

### Manual API Setup

```php
// app/app.php
\Pramnos\Application\Application::getInstance()
    ->register('api', function () {
        return include __DIR__ . '/api.php';
    });
```

## API Controllers

### Basic Structure

```php
<?php
namespace App\Api\Controllers;

use Pramnos\Application\Api as ApiController;

class UsersController extends ApiController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * List all users (GET /api/users)
     */
    public function index()
    {
        $users = \App\Models\User::all();
        return $this->json($users, 200);
    }
    
    /**
     * Get single user (GET /api/users/{id})
     */
    public function show($id)
    {
        $user = \App\Models\User::find($id);
        
        if (!$user) {
            return $this->json(['error' => 'Not found'], 404);
        }
        
        return $this->json($user, 200);
    }
    
    /**
     * Create user (POST /api/users)
     */
    public function store()
    {
        $data = $this->request->json();
        
        try {
            $user = \App\Models\User::create([
                'username' => $data['username'],
                'email'    => $data['email'],
                'password' => hash('sha256', $data['password']),
            ]);
            
            return $this->json($user, 201);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }
    
    /**
     * Update user (PATCH /api/users/{id})
     */
    public function update($id)
    {
        $user = \App\Models\User::find($id);
        
        if (!$user) {
            return $this->json(['error' => 'Not found'], 404);
        }
        
        $data = $this->request->json();
        $user->update($data);
        
        return $this->json($user, 200);
    }
    
    /**
     * Delete user (DELETE /api/users/{id})
     */
    public function destroy($id)
    {
        $user = \App\Models\User::find($id);
        
        if (!$user) {
            return $this->json(['error' => 'Not found'], 404);
        }
        
        $user->delete();
        return $this->json(null, 204);
    }
}
```

## Routing

### Define API Routes

```php
// routes/api.php
$router = \Pramnos\Routing\Router::getInstance();

// API version 1
$router->group(['prefix' => 'api/v1'], function ($router) {
    // Users endpoints
    $router->get('/users', 'Api/UsersController@index');
    $router->get('/users/{id}', 'Api/UsersController@show');
    $router->post('/users', 'Api/UsersController@store');
    $router->patch('/users/{id}', 'Api/UsersController@update');
    $router->delete('/users/{id}', 'Api/UsersController@destroy');
    
    // Posts endpoints
    $router->get('/posts', 'Api/PostsController@index');
    $router->get('/posts/{id}', 'Api/PostsController@show');
});
```

### Route Groups & Middleware

```php
$router->group([
    'prefix'     => 'api/v1',
    'middleware' => ['api', 'auth:api'],
], function ($router) {
    // Protected endpoints
    $router->post('/profile/update', 'Api/ProfileController@update');
    $router->post('/tokens', 'Api/TokenController@create');
});
```

## Request Handling

### JSON Requests

```php
// Get JSON body
$data = $this->request->json();

// Get specific field
$email = $this->request->json('email');

// Get with defaults
$page = $this->request->json('page', 1);

// Raw body
$raw = $this->request->getRawBody();
```

### Validation

```php
use Pramnos\Validation\Validator;

public function store()
{
    $data = $this->request->json();
    
    $validator = new Validator();
    $validator->add('username', 'required|min:3|max:50|unique:users');
    $validator->add('email', 'required|email|unique:users');
    $validator->add('password', 'required|min:8');
    
    if ($validator->fails()) {
        return $this->json($validator->errors(), 422);
    }
    
    // Process valid data...
}
```

## Response Handling

### JSON Responses

```php
// Success with data
return $this->json(['user' => $user], 200);

// Created
return $this->json($user, 201);

// No content
return $this->json(null, 204);

// Client error
return $this->json(['error' => 'Invalid input'], 400);

// Not found
return $this->json(['error' => 'Not found'], 404);

// Server error
return $this->json(['error' => 'Internal error'], 500);
```

### Response Objects

```php
use Pramnos\Http\Response;

$response = new Response();
$response->setStatus(200);
$response->setHeader('Content-Type', 'application/json');
$response->setBody(json_encode(['data' => $user]));

return $response;
```

### `getData()` and the columns it returns

A model's `getData()` is what most CRUD endpoints serialise. **Since 2026-08-16 it
returns every column** — including `NULL`, booleans and decoded JSON.

It did not before. The old implementation kept only values that were `is_numeric()` or
`is_string()`, so a column holding `NULL` was **absent from the payload** rather than
`null`, and booleans and JSON columns disappeared with them.

That was not a neutral historical quirk. Overrides in one application do

```php
$data = parent::getData();
$data['reportid'] = (int) $data['reportid'];
```

unguarded — so a record with `NULL` in that column raised *Undefined array key* in
production and cast the missing value to `0`. **The absent key was producing warnings
and wrong numbers**, and that measurement is what decided the change rather than
tidiness.

Measured on that application before flipping: 54 models, 42 reaching this through
`parent::getData()`, **523 keys added across 48 models** — 411 `NULL`, 53 boolean,
55 array.

**What to check after upgrading**, in order of likelihood:

- code calling `implode()`, `http_build_query()`, or building SQL from the result — a
  JSON column now arrives as an **array** where a scalar was assumed;
- clients that reject unknown keys;
- anything reading `array_key_exists()` as *"this record has no value"*, which now means
  *"this column is null"*.

To get the old shape back, byte for byte, opt out **once** in a base model class:

```php
abstract class AppModel extends \Pramnos\Application\Model
{
    protected $getDataFullFidelity = false;   // the pre-1.2 shape
}
```

**`sqlError` is no longer in the payload, in either mode.** It is a string once a query
has failed, so the old type filter let it through — a failed read put an SQL error
message into whatever was being serialised.

**A model that declares no public properties used to return `[]`.** Columns assigned to
an undeclared property go through `Base::__set` into an internal bag, which the filter
dropped whole. They are read now. Where both exist, the declared property wins — the bag
is the fallback, not the source of truth.

### When something fails on the server

Two things that used to end an API request with a page of HTML no longer do.

**A failed list query.** `Model::_getList()` catches a query failure and, with its
`$displayerroroutput` default of `true`, called `showError()` — which **exits**. The two
lines after it, which record `sqlError` and return an empty list, are what
`ApiListResponse::error()` was written against and were unreachable on the one path
that needs them. A request whose `Accept` names JSON now takes those two lines instead,
so the caller can report the failure in its own envelope:

```json
{ "error": "...", "data": [], "pagination": null, "fields": [...] }
```

The page path is unchanged: without a list there is nothing useful to render, so a
browser still gets the error page.

**A terminal error of any kind.** `Application::showError()` — maintenance mode, an
unsupported PHP version, a database that will not answer — answers JSON to a client
that asked for it, with a real status:

```json
{ "error": "maintenance", "retry_after": 300 }
{ "error": "unavailable" }
```

`503` while `var/MAINTENANCE` exists, `500` otherwise. It previously sent an HTML page
with **no status code at all**, so an API client got `200 OK` and failed on parsing
rather than recognising the state. See the
[Framework guide](Pramnos_Framework_Guide.md) for the flag files and `Retry-After`.

## Authentication

### Token-Based Authentication

```php
// routes/api.php
$router->group(['middleware' => ['api', 'auth:api']], function ($router) {
    $router->post('/profile', 'Api/ProfileController@show');
    $router->post('/profile/update', 'Api/ProfileController@update');
});
```

### OAuth2

```php
// Generate token
POST /api/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=abc&client_secret=xyz&username=user&password=pass

// Use token
Authorization: Bearer token_here
```

## CORS Configuration

### Database-Driven CORS

Configure CORS origins in the database:

```php
// Enable specific origins
INSERT INTO cors_settings (origin, allowed_methods, allowed_headers)
VALUES ('https://example.com', 'GET,POST,PATCH,DELETE', 'Content-Type,Authorization');

// Origins are validated on each request automatically
```

### Middleware

```php
// routes/api.php
$router->group(['middleware' => ['cors']], function ($router) {
    // CORS headers added automatically
});
```

## Pagination

### Paginate API Results

```php
public function index()
{
    $page    = $this->request->get('page', 1);
    $perPage = $this->request->get('per_page', 20);
    
    $qb = \App\Models\User::queryBuilder();
    $total = $qb->count();
    $users = $qb->forPage($page, $perPage)->get();
    
    return $this->json([
        'data'       => $users,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'last_page'  => ceil($total / $perPage),
    ], 200);
}
```

## Sorting, filtering and field selection

The list engine assembles its SQL through `Application\ApiList\ApiListSqlBuilder`, and two of
its three jobs are security rather than formatting:

| What arrives from the caller | What decides whether it reaches the SQL |
| --- | --- |
| `?order=name,-created` | the field must be in the model's available-fields whitelist, **and** match `^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$` |
| a structured filter's `op` | must be one of `= != <> < > <= >= LIKE ILIKE IN "NOT IN" "IS NULL" "IS NOT NULL"` |
| a filter's `value` | goes through `prepareInput()`; an `IN` list escapes every member |
| `?fields=a,b` | the primary key is added if absent, because a row the screen cannot link is not a row |

**Anything unrecognised is dropped, not rejected.** A filter is a request, not an instruction, and
answering "no such field" would let a caller enumerate the schema by watching which names produce
an error. The same reasoning applies to an order token: an unknown field is skipped, and if that
leaves nothing the order falls back to the primary key descending — so the list still works and
tells the caller nothing.

Quoting is per driver, and `LIKE` becomes `ILIKE` on PostgreSQL. That last one matters more than
it looks: `LIKE` on PostgreSQL is case-sensitive, so the same search box would match `Yannis` and
not `yannis` on one engine and both on the other — reported as a broken search rather than as a
driver difference.

```php
// A structured filter. Top-level entries are ANDed; an `or` group is parenthesised.
$sql = ApiListSqlBuilder::buildFilterFromConditions(
    [
        ['field' => 'usertype', 'op' => '>=', 'value' => 50],
        ['or' => [
            ['field' => 'username', 'op' => 'LIKE', 'value' => '%a%'],
            ['field' => 'email',    'op' => 'LIKE', 'value' => '%a%'],
        ]],
    ],
    ['usertype', 'username', 'email']   // the whitelist
);
```

Two edges worth knowing, both asserted:

- `['field' => 'x', 'op' => '=', 'value' => null]` emits `IS NULL`, because `= NULL` matches
  nothing and reads as a bug in the caller's data rather than in the query.
- `IN` with an empty array is **dropped**: `IN ()` is not valid SQL, and inventing `IN (NULL)`
  would silently change the meaning to "nothing matches".

## Versioning

```php
// Multiple API versions
$router->group(['prefix' => 'api/v1'], function ($router) {
    // Version 1 endpoints
});

$router->group(['prefix' => 'api/v2'], function ($router) {
    // Version 2 endpoints (breaking changes from v1)
});
```

## Reference

**Related Guides:**
- [Pramnos_Framework_Guide.md](Pramnos_Framework_Guide.md) — Middleware pipeline, Response Object, ExceptionHandler
- [Pramnos_Routing_Guide.md](Pramnos_Routing_Guide.md) — Router::group(), #[RouteGroup] attribute
- [Pramnos_Authentication_Guide.md](Pramnos_Authentication_Guide.md) — OAuth2 server, JWT, login lockout, 2FA
- [Pramnos_Security_Guide.md](Pramnos_Security_Guide.md) — CSRF, session hardening

---

## API Middleware

### JsonResponseMiddleware

Sets the `Content-Type` response header before passing to `$next`. Always a pass-through — never short-circuits.

```php
// Content-Type: application/json; charset=utf-8 (default)
// Content-Type: application/xml; charset=utf-8  (when HTTP_ACCEPT=application/xml)
new \Pramnos\Http\Middleware\JsonResponseMiddleware()
```

### ApiAuthMiddleware

Validates the `HTTP_APIKEY` header via a caller-supplied checker callable, then (optionally) validates a JWT `HTTP_ACCESSTOKEN`. On success sets `$_SESSION['logged']` and `$_SESSION['user']`. On failure short-circuits and returns a JSON error envelope.

```php
new \Pramnos\Http\Middleware\ApiAuthMiddleware(
    apiKeyChecker: fn(string $k) => $app->checkApiKey($k),
    authKey:       $app->authenticationKey,
    appNamespace:  $app->applicationInfo['namespace'] ?? null,
)
```

| Condition | HTTP status | `error` key |
|---|---|---|
| `HTTP_APIKEY` missing, and no same-origin session (below) | 403 | `APIKeyMissing` |
| API key invalid | 401 | `APIKeyInvalid` |
| JWT malformed / unreadable | 403 | `InvalidAccessToken` |
| JWT valid but user not found | 403 | `InvalidAccessToken` |

#### Calling your own API from your own page

One caller legitimately has no API key: a page of your own application. A key names
the *client*, and for a same-origin request from your own document the client is you —
and a page cannot be given one anyway, because anything the document can read, a
reader of the document can read.

So a request with **no API key** is accepted when both of these hold:

- the session carries an active `web_session` token — which every web login creates;
- the request carries `X-CSRF-Token` matching the session's CSRF token.

The cookie alone is not enough: the browser attaches it to a cross-site request too.
The CSRF token is the half that proves the caller read your page.

Nothing to configure — the pieces are already on a scaffolded page:

- the document prints `<meta name="csrf" content="…">` in the `<head>`, for a
  signed-in visitor only (an anonymous page gets no tag, because reading the token
  would start a session on every public URL);
- `assets/js/pf-utils.js` exposes `window.pfApiHeaders(extra)`, which adds the header
  from that tag. Use it for your own calls:

```js
fetch('/api/1.0/admin/users', {
    credentials: 'same-origin',
    headers: pfApiHeaders({ 'Accept': 'application/json' })
});
```

Without the header the request is anonymous and answers 403 `APIKeyMissing`, which is
what `Html\SearchBox` did before this existed: the box rendered, the endpoint
answered, and typing did nothing.

### UnifiedAuthMiddleware (SPA / same-origin auth)

Accepts either a Bearer JWT **or** a session cookie + `X-CSRF-Token` header. Use this for first-party route groups where you don't require API keys.

```php
$router->group([
    'prefix'     => '/api/v1',
    'middleware' => [
        new CorsMiddleware(['https://myapp.com']),
        new JsonResponseMiddleware(),
        new UnifiedAuthMiddleware(authKey: $app->authenticationKey),
    ],
], function (Router $r): void {
    $r->get('/profile', [ProfileController::class, 'show']);
});
```

**Auth resolution order:**
1. `Authorization: Bearer <jwt>` — validates JWT, loads user from `usertokens` with explicit scopes
2. Session cookie + `X-CSRF-Token` header — if session has an active `web_session` token and CSRF matches
3. No credentials → 401 JSON envelope

### Testing an endpoint's status code

Dispatch the request through the kernel the entry point uses, then read the status
off the kernel:

```php
$api = new \Pramnos\Application\Api();
$api->init();
$api->exec();

$body   = (string) $api->render();
$status = $api->lastStatusCode;      // int|null — null before the first dispatch
```

**Why not `http_response_code()`.** Under CLI the kernel does not emit the status —
there is nowhere to put it — so a test could not observe it at all. And the status
is half of what an endpoint promises: 400 "you sent no credentials", 401 "they were
wrong" and 405 "wrong verb" are three different instructions to a client, and all
three can carry a body of the same shape. A test asserting only on the body cannot
tell them apart, and an endpoint whose status silently changed kept passing.

`lastStatusCode` is set for every dispatch, whatever the SAPI, for both response
kinds — a `Response` object's own status, and the status inside the legacy
array/string envelope.

The one thing it does not cover is a middleware short-circuit (a missing or
invalid API key): those never reach the dispatch, and put their status in the body
instead. Read `$decoded['status']` as a fallback, and only when it is numeric —
plenty of endpoints use the word for something else (`{"status":"ok"}`).

### Api::exec() middleware pipeline

`Api::exec()` automatically runs:

```
CorsMiddleware → JsonResponseMiddleware → ApiAuthMiddleware → _executeCore()
```

Configure CORS via `app.php`:

```php
'api' => [
    'cors_origins' => ['https://spa.example.com'],   // config-based
    // OR:
    'cors_from_db' => true,  // read from application_settings table
],
```

---

## Database-Driven CORS

`CorsMiddleware::fromApplicationSettings(string $appName): self` queries `application_settings` joined with `applications` to load the CORS policy from the database. Falls back to `['*']` when:
- DB is unavailable or `authserver` feature not enabled
- No row found for `$appName`
- `cors_enabled = false`

```php
// In Api::exec() — automatic when 'cors_from_db' => true
$cors = CorsMiddleware::fromApplicationSettings($applicationInfo['name']);

// Construct from pre-fetched data
$cors = CorsMiddleware::fromCorsData(
    enabled: true,
    rawOrigins: ['https://app.example.com']
);
```
