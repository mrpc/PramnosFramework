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

## Reference

For complete documentation on routing, see:

- [v1.2 New Features — Modern Routing Engine](1.2-new-features.md#46-phase-7---modern-routing-engine)
- [v1.2 New Features — Router::group() + #[RouteGroup]](1.2-new-features.md#64-routergroup--routegroup)

**Topics covered in detailed reference:**

- Route definition with all HTTP methods
- Parameter binding and constraints
- Named routes and URL generation
- Route groups with prefixes and namespaces
- Middleware application and exclusion
- Attribute-based routing discovery
- Model binding (implicit and explicit)
- Resource routes and REST conventions
- Route caching for performance
- Route debugging and listing
