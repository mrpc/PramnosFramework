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

### Generate the API layer with `pramnos init`

```bash
php vendor/bin/pramnos init --rest-api=y
```

`--rest-api` takes a value (`y`/`n`); given on its own it means yes. Without the option,
`init` asks. Answering yes creates:

- `src/Api.php` — the application's API kernel, a subclass of `Pramnos\Application\Api`
- `src/Api/Controllers/` — API endpoint controllers
- `src/Api/routes.php` — the route definitions, included by `Api::_executeCore()`
- `www/api/index.php` and `www/api/.htaccess` — the API entry point
- an `'api'` section in `app/app.php` (prefix and version)

### Generating an endpoint controller

```bash
php pramnos create:api Stations
```

writes a controller extending `Pramnos\Application\ApiCrudController`: list, read, create,
update and delete actions over a model, each starting with `$this->guard('<action>')`, which
answers `401 not_authenticated` or `403 forbidden` from the framework's permission store.
Override `authorize()` in the generated class to tighten it for that resource.

## API Controllers

### Basic Structure

An API controller is an ordinary `Pramnos\Application\Controller`. Each action returns a
`Pramnos\Http\Response` — `Response::json()` sets the body, the status and the content type:

```php
<?php
namespace App\Api\Controllers;

use Pramnos\Http\Response;
use Pramnos\Validation\Validator;

class Stations extends \Pramnos\Application\Controller
{
    /** GET /1.0/stations/{id} */
    public function show($id): Response
    {
        $station = new \App\Models\Station($this);
        $station->load((int) $id);

        if (empty($station->stationid)) {
            return Response::json(['error' => 'NotFound'], 404);
        }

        return Response::json($station->getData());
    }

    /** POST /1.0/stations */
    public function store(): Response
    {
        $request = \Pramnos\Framework\Factory::getRequest();

        // Throws ValidationException; the API kernel answers it as 422 ValidationError.
        $data = Validator::validate($request->body(), [
            'name'     => 'required|min:3|max:50',
            'email'    => 'required|email',
        ]);

        $station = new \App\Models\Station($this);
        $station->name  = $data['name'];
        $station->email = $data['email'];
        $station->save();

        return Response::json($station->getData(), 201);
    }
}
```

## Routing

### Define API Routes

`src/Api/routes.php` is included with `$this` bound to the `Api` instance, and its return
value is the response. Routes take a closure or a `[Class, 'method']` pair; the controller
is resolved through the container, and URI parameters are injected by name:

```php
// src/Api/routes.php
$router     = new \Pramnos\Routing\Router($this);
$newRequest = new \Pramnos\Http\Request();

$router->group(
    ['prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')],
    function (\Pramnos\Routing\Router $r): void {
        $r->get('/stations/{id}', [\App\Api\Controllers\Stations::class, 'show']);
        $r->post('/stations', [\App\Api\Controllers\Stations::class, 'store']);

        // A closure works too
        $r->get('/hello', fn () => \Pramnos\Http\Response::json(['hello' => 'world']));
    }
);

return $router->dispatch($newRequest);
```

The verbs are `get`, `post`, `put`, `patch`, `delete`, `options`, `head` and `match()`.

### Route Groups & Middleware

Middleware is given as **instances** (or class names with a no-argument constructor) — there
are no string aliases:

```php
$r->group([
    'prefix'     => '/admin',
    'middleware' => [new \Pramnos\Http\Middleware\RateLimitMiddleware(600, 3600)],
], function (\Pramnos\Routing\Router $r): void {
    $r->post('/tokens', [\App\Api\Controllers\Tokens::class, 'create']);
});

// Or on a single route
$r->post('/stations', [Stations::class, 'store'])
  ->middleware(new \Pramnos\Http\Middleware\ApiKeyScopeMiddleware(['stations:write']));
```

API-key and token authentication need no route middleware: `Api::exec()` runs them for every
request (see [the pipeline](#apiexec-middleware-pipeline)).

## Request Handling

### Request bodies

```php
$request = \Pramnos\Framework\Factory::getRequest();

$data  = $request->body();                     // JSON or form body, as an array
$email = $request->bodyValue('email');         // one field
$page  = $request->bodyValue('page', 1);       // with a default
$q     = $request->get('q', '', 'get');        // a query-string value
```

A JSON body decodes to arrays, never objects.

### Validation

`Validator::validate()` returns the validated (whitelisted) fields or throws
`Pramnos\Validation\ValidationException`. Inside an API route it needs no catch: the kernel
answers it as `422` with `"error": "ValidationError"` and the per-field `errors`.

```php
use Pramnos\Validation\Validator;
use Pramnos\Validation\ValidationException;

try {
    $data = Validator::validate($request->body(), [
        'username' => 'required|min:3|max:50',
        'email'    => 'required|email',
        'password' => 'required|min:8',
    ]);
} catch (ValidationException $e) {
    return Response::json(['errors' => $e->errors()], $e->getStatus());
}
```

## Response Handling

### JSON Responses

```php
use Pramnos\Http\Response;

return Response::json(['user' => $data]);            // 200
return Response::json($data, 201);                    // Created
return Response::make('', 204);                       // No content
return Response::json(['error' => 'Invalid input'], 400);
return Response::json(['error' => 'NotFound'], 404);
```

### The two «not found» codes, and which is which

They are different answers and easy to get backwards from outside:

| `error` | meaning | who emits it |
|---|---|---|
| `EndpointNotFound` | **no such route.** Nothing on this application answers to that controller name | `Api::exec()`, when `getController()` cannot resolve one |
| `NotFound` | **no such record.** The route exists and ran; the thing it was asked for is not there | `Api::notFound()`, from your own action |

A client can act on the distinction — the first means the URL is wrong, the second means the
id is — so a consumer that answers `NotFound` for a missing route is telling callers to
retry a path that will never work.

**`EndpointNotFound` is only for a route that does not exist.** `Api::exec()` catches
`Pramnos\Application\ControllerNotFoundException` and nothing broader, so a controller whose
constructor throws — a missing dependency, a failed database connection — answers 500, not
404. That matters because a 500 gets looked at, while a 404 reads as a path the client
mistyped and gets ignored. Everything else propagates to the handler that distinguishes a
`ValidationException`, a 403 by code and a SQL error. **If you have overridden `exec()`**, catch the type rather than matching the
message:

```php
catch (\Pramnos\Application\ControllerNotFoundException $exception) {   // 404
```

Its `getController()` returns the name that did not resolve, and `getContext()` the request
URI and signed-in username — **for a log, not for the response.** They are kept out of the
exception's message so that a mistyped URL never echoes who is signed in.

### Response Objects

`Response` is immutable-style: each `with…()` returns the response.

```php
use Pramnos\Http\Response;

return Response::make(json_encode(['data' => $data]), 200)
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Cache-Control', 'no-store');

// Response::redirect($url, 302), ->withStatus(), ->withBody(), ->withoutHeader()
```

### `getData()` and the columns it returns

A model's `getData()` is what most CRUD endpoints serialise, and it is also what a model
becomes inside `json_encode()`: `Model` implements `JsonSerializable` with `getData()`, so a
response holding models one level down — a list of related records, a model in a field —
encodes each as its columns. Encoding the public properties instead reaches `$controller`, the
application and back to the model: *Recursion detected*, and a 500.

`getData()` **returns every column** — including `NULL`, booleans and decoded JSON. A
column holding `NULL` is present as `null` rather than absent, so an override such as

```php
$data = parent::getData();
$data['reportid'] = (int) $data['reportid'];
```

never meets *Undefined array key*. Things that depend on the full shape:

- a JSON column arrives as an **array** — not something to pass to `implode()`,
  `http_build_query()` or SQL;
- `array_key_exists()` on the result means *"this column exists"*, not *"it has a value"*.

To get the filtered shape (only numeric and string values) instead, opt out **once** in a base
model class:

```php
abstract class AppModel extends \Pramnos\Application\Model
{
    protected $getDataFullFidelity = false;   // only numeric and string values
}
```

**`sqlError` is never in the payload, in either mode**, so a failed read cannot put an SQL
error message into whatever is being serialised.

**Undeclared columns are included.** Columns assigned to an undeclared property go through
`Base::__set` into an internal bag, and `getData()` reads it. Where both exist, the declared
property wins — the bag is the fallback, not the source of truth.

### When something fails on the server

An API request that fails on the server gets JSON, not a page of HTML.

**A failed list query.** `Model::_getList()` catches a query failure. For a browser, with its
`$displayerroroutput` default of `true`, it calls `showError()`, which renders the error page and
exits. For a request whose `Accept` names JSON it records `sqlError` and returns an empty list
instead, so the caller can report the failure in its own envelope:

```json
{ "error": "...", "data": [], "pagination": null, "fields": [...] }
```

The page path keeps the error page: without a list there is nothing useful to render.

**A terminal error of any kind.** `Application::showError()` — maintenance mode, an
unsupported PHP version, a database that will not answer — answers JSON to a client
that asked for it, with a real status:

```json
{ "error": "maintenance", "retry_after": 300 }
{ "error": "unavailable" }
```

`503` while `var/MAINTENANCE` exists, `500` otherwise — a real status, so an API client
recognises the state instead of failing to parse a `200` page. See the
[Framework guide](Pramnos_Framework_Guide.md) for the flag files and `Retry-After`.

## Authentication

### Token-Based Authentication

Every API request already passes `ApiAuthMiddleware` (see [the pipeline](#apiexec-middleware-pipeline)):
an `apiKey` header names the client, and an `accessToken` header — or `Authorization: Bearer` —
names the user. A request with no token is anonymous, not refused, so an action that needs a
signed-in user checks for one:

```php
$user = \Pramnos\User\User::getCurrentUser();
if (!is_object($user) || (int) $user->userid < 2) {   // user 1 is the anonymous account
    return Response::json(['error' => 'not_authenticated'], 401);
}
```

A controller extending `ApiCrudController` gets this, plus the permission check, from
`$this->guard('<action>')`.

### `POST /account/login` — a password for a bearer token

The framework's own API login, in `Pramnos\Auth\Controllers\ApiAccount`. It runs the same
login flow the HTML form does — lockout, credentials, second factor — and swaps the last step
for a token:

```
POST /account/login          {"username": "...", "password": "..."}
  → 200 {"status":"success","access_token":"<jwt>","token_type":"Bearer","user":{…}}
  → 400 {"error":"missing_credentials"}
  → 401 {"error":"two_factor_required","methods":[…]}   then POST /account/login2fa {"code":"…"}
  → 401 {"error":"invalid_credentials"}
  → 403 {"error":"email_unverified"}                    the password was right; the address is not confirmed
  → 429 {"error":"too_many_attempts","retry_after": <seconds>}
  → 405 {"error":"method_not_allowed"}                  not a POST

POST /account/logout         accessToken: <jwt>   (or Authorization: Bearer <jwt>)
```

Either header is read, and the body may be JSON or a form post.

### What `POST /account/login2fa` answers

The second leg carries only the code — the pending login lives server-side — and these answers
are the whole of what a client has to branch on:

```
POST /account/login2fa       {"code": "123456"}
  → 200 {"status":"success","access_token":"<jwt>","token_type":"Bearer","user":{…}}
  → 400 {"error":"missing_code"}
  → 401 {"error":"invalid_code"}
  → 429 {"error":"too_many_attempts","retry_after": <seconds>}
  → 405 {"error":"method_not_allowed"}
```

Four things to build against:

- **A success is byte-for-byte the shape of a login that needed no second factor.** Both legs share
  `tokenResponse()`, so a client needs one code path for the outcome, not two.
- **`401 invalid_code` leaves the pending login intact.** Try the next code from the authenticator;
  there is no need to re-send the password. That is the reason this is a second request at all.
- **`429` is not `401`.** A client that treats them the same will keep retrying, which is what turns
  a lockout into a loop — honour `retry_after`.
- **`400 missing_code` costs no attempt.** The code is checked for emptiness before the flow is
  asked, so a bug in your form cannot spend your users' attempts and lock them out.

It must be a `POST`; a code in a query string ends up in access logs, browser history and every
proxy in between, and is worth something for as long as it is valid.


**Tokens do not expire by default.** `auth.token_ttl` in `app.php` is the opt-in, in seconds:

```php
'auth' => ['token_ttl' => 3600],
```

Left unset — or set to `0`, or to a negative number — the token never expires. An expiry by
default would sign every existing client out at once on upgrade, so it is a decision an
installation makes.

A TTL is stamped in **two** places, because two different pieces of code end a token: the JWT's
`exp` claim, which `JWT::decode()` refuses once past, and the `usertokens.expires` column, which
`loadByToken()` refuses. They are always the same number.

The `nbf` claim is backdated twelve hours on purpose. A "not before" of exactly now makes the
token invalid on any client whose clock runs a few seconds behind the server's, which is a login
that fails for some users some of the time; `exp` is what actually bounds the token.

With no expiry, **revocation is the only thing that ends an API session** — which is what
`/account/logout` does, and why a client that discards the token without calling it leaves a
credential valid for ever.

### OAuth2

```php
// Generate token
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=abc&client_secret=xyz&username=user&password=pass

// Use token
Authorization: Bearer token_here
```

## CORS Configuration

`Api::exec()` adds `CorsMiddleware` to every API request; nothing has to be declared on a
route. The allowed origins come from **top-level** keys in `app/app.php`:

```php
// app/app.php — top level, not inside 'api'
'cors_origins' => ['https://spa.example.com'],
// or read them per application from the database:
'cors_from_db' => true,
```

With neither, every origin is allowed (`['*']`). The database form reads `cors_enabled` and
`cors_origins` from `applications.application_settings` — see
[Database-Driven CORS](#database-driven-cors).

## Pagination

### Paginate API Results

A model's `getApiList()` paginates, searches, sorts and selects fields in one call — it is what
`create:api` controllers use:

```php
public function display()
{
    $request = \Pramnos\Framework\Factory::getRequest();
    $model   = new \App\Models\Station($this);

    return $model->getApiList(
        $request->get('fields', [], 'get'),
        $request->get('search', '', 'get'),
        $request->get('sort', '', 'get'),
        (int) $request->get('page', 0, 'get', 'int'),    // 0 = every row
        (int) $request->get('limit', 20, 'get', 'int')
    );
}
```

The answer carries `data`, `fields` and, when `page > 0`, `pagination` (`currentpage`,
`itemsperpage`, `totalitems`, `totalpages`, `hasnext`, `hasprevious`).

### The calling application's page sizes

A list built on `ApiListQuery` (`getApiList()`, `ApiCrudController`) follows the page sizes of the
application whose API key made the request — its **Access & limits** tab. Where pagination is
enforced, `page=0` (every row) becomes the first page at the default size; no page is larger than
the maximum. The response's `itemsperpage` says which size was used. See
[Access and limits](Pramnos_AuthServer_Integration_Guide.md#access-and-limits).

## Sorting, filtering and field selection

The list engine assembles its SQL through `Application\ApiList\ApiListSqlBuilder`, and two of
its three jobs are security rather than formatting:

| What arrives from the caller | What decides whether it reaches the SQL |
| --- | --- |
| `?order=name,-created` | the field must be in the model's available-fields whitelist, **and** match `^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$` |
| a structured filter's `op` | must be one of `= != <> < > <= >= LIKE ILIKE IN "NOT IN" "IS NULL" "IS NOT NULL"` |
| a filter's `value` | goes through `prepareInput()`; an `IN` list escapes every member |
| `?fields=a,b` | the primary key is added if absent, because a row the screen cannot link is not a row |

### The three shapes a list parameter arrives in

`fields` and `search` each come off a URL, so each is accepted three ways and the caller does not
choose which:

| Parameter | Shapes |
| --- | --- |
| `fields` | `?fields=id,name` (comma-separated), `?fields=["id","name"]` (URL-encoded JSON), or an array from application code |
| `search` | `?search=yannis` (across everything), `?search={"username":"yan"}` (URL-encoded JSON, one term per field), or an array |

A JSON `search` read as a global one would look for the literal `{"username":"yan"}` in every
column and find nothing — which presents as "the column filters do not work" rather than as a
parsing question.

With no `fields` at all the **source's** default set is used, not its whole schema: a source
curates that list to what it is willing to expose, so "no preference" must never mean "everything".
The same set is the fallback when nothing the caller named survives validation.

With a join, a field may be named with or without its table prefix — the schema reports
`u.username` because that is what the `SELECT` needs, and requiring the caller to know the alias
would make every joined list harder to call than it is to build. The `fields` key in the answer is
the caller's list with prefixes stripped: it describes what was asked for, not what the query
selected, so the primary key added below does not appear in it unless the caller named it.

**Anything unrecognised is dropped, not rejected.** A filter is a request, not an instruction, and
answering "no such field" would let a caller enumerate the schema by watching which names produce
an error. The same reasoning applies to an order token: an unknown field is skipped, and if that
leaves nothing the order falls back to the primary key descending — so the list still works and
tells the caller nothing.

### When the query fails, the answer is still JSON

A list endpoint answers a caller that sent a page number and a search box, so a failure has to
come back in the same envelope:

```json
{"error": "Database query failed: …", "data": [], "pagination": null, "fields": [...],
 "debug": {"filter": "where …", "order": "order by …", "selectFields": "…"}}
```

The fragments in `debug` are the only way to see what was actually run — the caller never sent a
`WHERE` clause, so without them "it returned nothing" and "it failed" look the same from outside.

That distinction is the reason `apiListLastError()` exists at all. An unpaginated fetch returns an
empty array both when nothing matched and when the query failed; the error is what tells them
apart, and reporting a failure as an empty list is how a broken filter looks exactly like a table
with nothing in it.

### DataTables counts two totals

`recordsTotal` is the count *before* the search box and `recordsFiltered` the count after. The
engine has the second one already, and recomputes the first **only when a search is active** —
with no search the two are identical and the extra query buys nothing, on a screen that is drawn
on every page load. Reporting the filtered count as both would make the table say a search matched
everything there is, and offer one page where there are ninety.

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

Validates the `HTTP_APIKEY` header via a caller-supplied checker callable, then (optionally) validates a JWT `HTTP_ACCESSTOKEN` (or `Authorization: Bearer`). On success it sets the request's identity — what `User::getCurrentUser()` answers for this request — without reading or writing the session; a request with no token is anonymous. On failure it short-circuits and returns a JSON error envelope.

```php
new \Pramnos\Http\Middleware\ApiAuthMiddleware(
    apiKeyChecker: fn(string $k) => $app->checkApiKey($k),
    authKey:       $app->authenticationKey,
    appNamespace:  $app->applicationInfo['namespace'] ?? null,
    publicPaths:   $app->publicApiPaths(),   // addresses that need no key
)
```

| Condition | HTTP status | `error` key |
|---|---|---|
| `HTTP_APIKEY` missing, and no same-origin session (below) | 403 | `APIKeyMissing` |
| no key, same-origin session + CSRF valid, but not signed in | 401 | `Unauthenticated` |
| API key invalid | 401 | `APIKeyInvalid` |
| JWT malformed / unreadable | 403 | `InvalidAccessToken` |
| JWT valid but user not found | 403 | `InvalidAccessToken` |

#### An endpoint that must be reachable without a key

Some endpoints exist precisely to be called by somebody who has no credential **yet**: a
pairing handshake, an inbound webhook from a third party, a device-code poll. Declare them:

```php
// app/app.php
'public_api_paths' => [
    '/1.0/wordpress/pair',
    '/1.0/hooks/*',
],
```

Patterns are globs matched against the request path, query string ignored. Override
`Api::publicApiPaths()` instead when the list is computed rather than written.

**These addresses are open to the internet.** The framework stops asking for a key and
requires nothing else, so whatever the endpoint needs — a signature, a one-time code, a rate
limit — is the endpoint's own job. List exact routes rather than a prefix that will grow.

**A bearer token still counts on them.** One that verifies makes its user the caller, exactly
as on a keyed route; one that does not leaves the call anonymous rather than refused, since
the endpoint is open and what an anonymous caller gets is its decision. With no token nothing
changes — the endpoint reads whatever it always read.

`/<version>/mcp` is on the list without being declared. A remote MCP connector has no key to
send, and the endpoint answers an unauthenticated call with the `401` that tells the client
where to get a token — see the [MCP guide](Pramnos_MCP_Guide.md#authentication-is-the-one-this-server-already-does).

A request that cannot say what path it is on is **not** public: the decision reads the
request's own URI, not the process-wide static, because a static answers with whatever was
written last and this decides whether authentication runs at all.

#### An authentication scheme of your own

`ApiAuthMiddleware` reads `Authorization: Bearer` as an access-token JWT and answers
`InvalidAccessToken` before your endpoint runs — so `Authorization` is the framework's
unless you take it first. `Api::configureApiPipeline()` is where:

```php
class Api extends \Pramnos\Application\Api
{
    protected function configureApiPipeline(\Pramnos\Http\MiddlewarePipeline $pipeline): void
    {
        $pipeline->pipe(new \App\Http\PluginTokenMiddleware());
    }
}
```

It is called after CORS and the JSON content type and **before authentication**, which is
the position that matters: your middleware runs first, so it can answer a request itself or
claim a header. To run *after* authentication, do the work in the controller — the
pipeline's remaining position is the dispatch.

`checkApiKey()` is not the place for this: it is handed the key and nothing else, so it cannot
tell which route it is on.

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

`/admin/users`, `/admin/logs` and `/admin/summary` answer only to whoever may open the
screen they serve (`admin.users`, `admin.logs`, `admin.dashboard`), as
[`AdminAccess`](Pramnos_Authorization_Guide.md) decides: the administration floor, usertype
98 unless set otherwise, or a grant under `admin_access = permissions`. A grant made in the
permission store for the action itself also opens it. Anyone else gets 403. `/admin/search`
is open to any signed-in user, and its sources decide what they show (see the
[Search guide](Pramnos_Search_Guide.md#permissions)).

Without the header the request is anonymous and answers 403 `APIKeyMissing` — a search box
that renders, an endpoint that answers, and typing that does nothing.

#### What a key may do: `ApiKeyScopeMiddleware`

`ApiAuthMiddleware` decides whether a key is valid. What the key's application may do is
the `scope` field on its administration screen — a space-separated list — and a route
declares what it needs:

```php
$router->post('/api/v1/stations', [Stations::class, 'store'])
       ->middleware(new \Pramnos\Http\Middleware\ApiKeyScopeMiddleware(['stations:write']));

// on a discovered route
#[Route('/api/v1/stations', methods: 'POST', apiKeyScopes: ['stations:write'])]
```

Put it after `ApiAuthMiddleware`. Every declared scope must be among the application's
**Allowed Scopes**, by the rule the token endpoint applies to the same field,
`Application::scopesBeyond()`: an exact match, and **an empty field restricts nothing**. A
refusal is `403` with `"error": "insufficient_scope"`, naming the missing scopes.

- **An application with no Allowed Scopes is not restricted.** To hold a key to a route,
  give its application a list; the declaration then refuses what the list leaves out.
- **A key with no application row is refused**: nothing says what it may do.
- **A route that declares nothing is unaffected**, and so is a request with no key — the
  application's own signed-in page, admitted on its session — and one with the site's own key.
- `apiKeyScopes` is not `permissions`. The route's `permissions` are the scopes of the signed-in
  user's **token**; `apiKeyScopes` are the scopes of the **client** calling. A route can
  declare both.
- A key stored somewhere other than `applications` passes a resolver:
  `new ApiKeyScopeMiddleware(['stations:write'], fn (string $key): array|null|false => …)` —
  the Allowed Scopes, `null` for the application itself, `false` for a key nothing knows.

#### Usage per application: `ApplicationStatsMiddleware`

`applications.application_stats` is the per-application usage table. On TimescaleDB it is a
hypertable, with hourly and daily aggregates the framework maintains. Add the recorder to
the API group, first, so it also sees what the middleware after it refuses:

```php
$router->group(['prefix' => '/api/v1', 'middleware' => [
    new \Pramnos\Http\Middleware\ApplicationStatsMiddleware(),
    new \Pramnos\Http\Middleware\RateLimitMiddleware(600, 3600),
    new \Pramnos\Http\Middleware\ApiAuthMiddleware(...),
]], $routes);
```

- **One row per application per minute.** A request adds itself to its minute's row:
  totals, successes and failures, the status bucket, bytes in and out, and the average,
  minimum and maximum response time. `application_stats_hourly` and `_daily` sum those rows.
- A rate-limit refusal counts under `rate_limited_requests`; any other exception counts by
  its code, or as a 500. Both still reach the client unchanged.
- Only a key with an `applications` row is recorded. A request with no key, or with the
  site's own key, is the application calling itself.
- **Opt-in and best-effort.** It is one upsert per API request, so an installation chooses
  it. A failed write is swallowed, because the request has already been served.
- A key stored elsewhere passes `fn (string $apiKey): int` as the first argument — the
  application id, 0 for none.

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
1. `Authorization: Bearer <jwt>` — validates JWT, loads user from `usertokens` with explicit scopes (by the token's text, then by its verified `jti` — an `/oauth/token` access token is stored by its identifier)
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
CorsMiddleware → JsonResponseMiddleware → [configureApiPipeline()] → ApiAuthMiddleware → ApplicationPolicyMiddleware → _executeCore()
```

`configureApiPipeline()` is the application's hook (see
[An authentication scheme of your own](#an-authentication-scheme-of-your-own)).

`ApplicationPolicyMiddleware` applies the calling application's limits — HTTPS, its IP lock, its
browser origins and its rate limit — once `ApiAuthMiddleware` has named it from the API key. A
request with no application (the site's own key, a signed-in page, a public path) passes it.
Every answer to an application carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; over the
limit it is `429` with `Retry-After`. See
[Access and limits](Pramnos_AuthServer_Integration_Guide.md#access-and-limits).

Configure CORS with `cors_origins` or `cors_from_db` in `app/app.php`, at the top level or
inside `'api' => [...]` (where `init` writes them); the top level wins where both are set:

```php
'cors_origins' => ['https://spa.example.com'],   // config-based
// OR:
'cors_from_db' => true,  // read from applications.application_settings
```

---

## Database-Driven CORS

`CorsMiddleware::fromApplicationSettings(string $appName, ?Database $db = null): self` reads `cors_enabled` and `cors_origins` from `applications.application_settings` joined with `applications` to load the CORS policy from the database. Falls back to `['*']` when:
- the database is unavailable or the table does not exist
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
