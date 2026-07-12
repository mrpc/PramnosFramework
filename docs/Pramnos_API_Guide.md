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
use Pramnos\Application\Response;

$response = new Response();
$response->setStatus(200);
$response->setHeader('Content-Type', 'application/json');
$response->setBody(json_encode(['data' => $user]));

return $response;
```

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
| `HTTP_APIKEY` missing | 403 | `APIKeyMissing` |
| API key invalid | 401 | `APIKeyInvalid` |
| JWT malformed / unreadable | 403 | `InvalidAccessToken` |
| JWT valid but user not found | 403 | `InvalidAccessToken` |

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
