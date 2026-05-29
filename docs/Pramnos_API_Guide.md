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

For complete documentation on API features, see:

- [v1.2 New Features — REST API Scaffolding](1.2-new-features.md#66-rest-api-scaffolding--pramnos-init---rest-api)
- [v1.2 New Features — Response Object](1.2-new-features.md#18-phase-4-formal-response-object)
- [v1.2 New Features — Database-Driven CORS](1.2-new-features.md#67-database-driven-cors-pf-43--phase-15-convergence-test)

**Topics covered in detailed reference:**

- Complete API controller lifecycle and hooks
- All HTTP methods and status codes
- Request parsing and content negotiation
- Response serialization and formatting
- Pagination, filtering, and sorting strategies
- API versioning and deprecation handling
- Error handling and exception formatting
- Rate limiting and throttling
- API documentation generation
