# Pramnos Framework Guide

Pramnos is a PHP MVC framework designed for building robust web applications with a focus on security, modularity, and clean code architecture. This guide covers the framework's structure, conventions, and best practices.

## Overview

### Core Components

The Pramnos framework follows the Model-View-Controller (MVC) pattern with these key components:

- **Controllers**: Handle HTTP requests and business logic
- **Models**: Manage data and business rules
- **Views**: Present data to users (HTML templates)
- **Application**: Central application management
- **Database**: Data access layer with security features

### Directory Structure

```
src/
├── Controllers/          # Application controllers
├── Models/              # Data models and business logic
├── Views/               # HTML templates and view files
├── Api/                 # API controllers and endpoints
│   └── Controllers/     # API-specific controllers
├── OAuth2/              # OAuth2 specific components
│   ├── routes.php       # OAuth2 route definitions
│   └── sso_routes.php   # SSO route definitions
└── Application.php      # Main application class

app/
├── api.php              # API application entry point
├── app.php              # Main application entry point
├── config/              # Configuration files
├── language/            # Internationalization files
├── Migrations/          # Database migration files
└── themes/              # UI themes and assets

www/
├── index.php            # Web application entry point
└── api/                 # API endpoint entry points
```

## Controllers

### Basic Controller Structure

```php
<?php
namespace YourNamespace\Controllers;

class ExampleController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Define public actions (no authentication required)
        $this->addaction(['public_action', 'another_public']);
        
        // Define authenticated actions (login required)
        $this->addAuthAction(['private_action', 'dashboard']);
        
        // Set module name for views and navigation
        $this->_modulename = 'Example';
        
        parent::__construct($application);
    }
    
    public function display()
    {
        // Default action when controller is accessed without specific method
        return $this->dashboard();
    }
    
    public function dashboard()
    {
        $view = $this->getView('Example');
        
        // Set page title and breadcrumbs
        $this->header = 'Dashboard';
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'My Dashboard';
        $this->addbreadcrumb('Dashboard', sURL . 'Example/dashboard');
        
        return $view->display('dashboard');
    }
}
```

### Controller Conventions

1. **Class Names**: Use PascalCase (e.g., `UserDashboard`, `ApiController`)
2. **File Names**: Match class names (e.g., `UserDashboard.php`)
3. **Namespaces**: Use project-specific namespaces (e.g., `Project\Controllers`)
4. **Methods**: Use camelCase for action methods

### Authentication and Authorization

```php
// Public actions (no login required)
$this->addaction(['login', 'register', 'forgotPassword']);

// Authenticated actions (login required)
$this->addAuthAction(['dashboard', 'profile', 'settings']);

// Check if user is authenticated
$currentUser = \Pramnos\User\User::getCurrentUser();
if ($currentUser) {
    // User is logged in
    $userId = $currentUser->userid;
    $username = $currentUser->username;
}
```

### URL Handling and Redirects

```php
// Always use sURL constant for URLs (works in subdirectories)
$this->redirect(sURL . 'Controller/action');

// Add breadcrumbs
$this->addbreadcrumb('Home', sURL);
$this->addbreadcrumb('Dashboard', sURL . 'Dashboard/dashboard');

// Set page headers
$this->header = 'Page Title';
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->title = 'Browser Title';
```

### Security: CSRF Protection

Pramnos Framework includes built-in CSRF (Cross-Site Request Forgery) protection. It uses a session-stable, random parameter name to prevent malicious form submissions.

#### 1. In your Template/View
Use the `Session::getTokenField()` method to include the protection in your forms:

```html
<form action="<?php echo sURL; ?>User/save" method="POST">
    <!-- This generates the hidden CSRF field -->
    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
    
    <input type="text" name="username" />
    <button type="submit">Save</button>
</form>
```

#### 2. In your Controller (Option A: Direct Check)
Validate the token before processing the POST data:

```php
public function save()
{
    $session = \Pramnos\Http\Session::getInstance();
    
    if (!$session->checkToken('POST')) {
        $this->addError('Security token invalid or expired. Please try again.');
        return $this->redirect(sURL . 'User/profile');
    }
    
    // Process form data safely...
}
```

#### 2. In your Controller (Option B: Validation Rule)
You can also use the `csrf` validation rule for a cleaner approach:

```php
public function save()
{
    $request = new \Pramnos\Http\Request();
    $session = \Pramnos\Http\Session::getInstance();
    $token = $session->getToken();

    $data = $request->validate([
        $token => 'csrf', // Automated CSRF validation
        'name' => 'required|string',
    ]);
    
    // Continues only if validation (including CSRF) passes
}
```

#### 3. Manual Token Regeneration
For maximum security, you should regenerate the CSRF token after sensitive events like User Login or Logout:

```php
// After successful login
$session = \Pramnos\Http\Session::getInstance();
$session->regenerateToken();
```

> [!TIP]
> The `Session::reset()` method automatically calls `regenerateToken()`, so resetting your session state for a new user also secures their future forms.

> [!TIP]
> The CSRF token is stable per session, meaning multiple tabs can be open simultaneously without breaking the protection.

---

### Security: Content Security Policy (CSP)

Pramnos Framework provides built-in support for a **Nonce-based Content Security Policy**. This approach allows for a highly restrictive `script-src` and `style-src` while still permitting legitimate inline content throughout your application.

#### 1. How It Works
1.  **Nonce Generation**: A unique, cryptographically secure nonce is generated once per request in `Application::exec()`. This value is accessible via `Application::getInstance()->cspNonce`.
2.  **Header Emission**: The `Content-Security-Policy` header is automatically sent to the browser before any content is rendered.
3.  **Auto-Injection**: The framework's HTML rendering layer (`DocumentTypes\Html`) automatically post-processes the output to inject the `nonce` attribute into all:
    -   Inline `<script>` tags (those without a `src` attribute).
    -   Internal `<style>` tags.

#### 2. Configuring CSP Domains
While the framework provides secure defaults, you must explicitly whitelist external domains used by your application in `app/app.php` under the `csp` key:

```php
// app/app.php
'csp' => [
    'script-src' => [
        'https://maps.googleapis.com',
        'https://cdn.jsdelivr.net'
    ],
    'style-src' => [
        'https://fonts.googleapis.com',
        'https://cdnjs.cloudflare.com'
    ],
    'img-src' => [
        'https://maps.gstatic.com',
        'https://*.tile.openstreetmap.org'
    ],
    'font-src' => [
        'https://fonts.gstatic.com'
    ],
    'connect-src' => [
        'https://maps.googleapis.com'
    ]
]
```

#### 3. Apache Configuration
When using the framework's built-in CSP, ensure you remove any manual `Content-Security-Policy` headers from your Apache `.htaccess` or VirtualHost files to prevent header duplication or conflicts.

---

## Views and Templates

### View Structure

```php
// In controller
$view = $this->getView('ViewName');
$view->data = $someData;
$view->user = $currentUser;
return $view->display('template_name');
```

**Important**: View template files must use the `.html.php` extension, not just `.html`. This allows for PHP code execution within templates when needed.

### Template Files

Templates are stored in `/src/Views/ViewName/template_name.html.php`:

```html
<div>
    <h1>{{header}}</h1>
    
    <!-- Use sURL for all links -->
    <a href="<?php echo sURL;?>Controller/action">Link Text</a>
    
    <!-- Display data -->
    <p>Welcome, <?php echo $this->user->username;?>!</p>
    
    
</div>
```


## Routing



### URL Patterns

- **Controllers**: `/ControllerName/action`

## Application Configuration

### Application Class

```php
<?php
namespace YourNamespace;

class Application extends \Pramnos\Application\Application
{
    public function __construct()
    {
        parent::__construct();
        
        // Set application-specific configuration
        $this->setConfig('app_name', 'Your App Name');
        $this->setConfig('version', '1.0.0');
    }
    
    public function exec($query = '')
    {
        // Custom application logic before execution
        
        // Call parent execution
        parent::exec($query);
    }
}
```

### Configuration Files

Configuration is typically stored in `/app/config/settings.php`:

```php
<?php
return [
    'database' => [
        'host' => 'localhost',
        'username' => 'dbuser',
        'password' => 'dbpass',
        'database' => 'dbname'
    ],
    'app' => [
        'name' => 'Your Application',
        'version' => '1.0.0',
        'timezone' => 'UTC'
    ],
    'security' => [
        'session_timeout' => 3600,
        'password_hash_algo' => PASSWORD_DEFAULT
    ]
];
```


## Error Handling

### Adding Errors

```php
// Add error messages
$this->addError('Something went wrong');
$this->addError('Validation failed: ' . $validationMessage);

// Check for errors
if ($this->hasErrors()) {
    // Handle errors
    return $this->showErrorPage();
}
```

### Exception Handling

```php
try {
    // Risky operation
    $result = $this->performOperation();
} catch (\Exception $e) {
    $this->addError('Operation failed: ' . $e->getMessage());
    error_log($e->getMessage());
    return $this->showErrorPage();
}
```


## Framework Factory Classes

### Common Factory Usage

```php
// Get authentication handler
$auth = \Pramnos\Framework\Factory::getAuth();

// Get document handler
$doc = \Pramnos\Framework\Factory::getDocument();

// Get current user
$user = \Pramnos\User\User::getCurrentUser();

// Get database instance (alternative method)
$database = \Pramnos\Database\Database::getInstance();
```

This guide provides a comprehensive overview of the Pramnos framework structure and conventions. Use it as a reference for building consistent, secure, and maintainable applications within the Pramnos ecosystem.

---

## Middleware Pipeline

A lightweight, composable middleware pipeline (PSR-15-inspired) for applying cross-cutting concerns — authentication, rate limiting, CORS, maintenance mode — without modifying controllers.

### Route Middleware

```php
use Pramnos\Http\Middleware\AuthMiddleware;
use Pramnos\Http\Middleware\ThrottleMiddleware;

$router->get('/dashboard', [DashboardController::class, 'index'])
       ->middleware(new AuthMiddleware());

$router->post('/api/export', fn() => exportData())
       ->middleware(
           new AuthMiddleware(),
           new ThrottleMiddleware(maxRequests: 5, perSeconds: 60)
       );
```

### Global Middleware (ServiceProvider::boot())

```php
public function boot(): void
{
    $router = $this->app->getRouter();

    $router->addGlobalMiddleware(new MaintenanceModeMiddleware());
    $router->addGlobalMiddleware(new CorsMiddleware(
        allowedOrigins: ['https://app.example.com'],
        allowCredentials: true
    ));
}
```

### Controller Middleware

```php
class ApiController extends \Pramnos\Application\Controller
{
    public function __construct()
    {
        $this->addMiddleware('*', new AuthMiddleware());
        $this->addMiddleware('export', new ThrottleMiddleware(5, 60));
        parent::__construct();
    }
}
```

### Writing Your Own Middleware

Implement `Pramnos\Http\MiddlewareInterface`:

```php
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'application/json')) {
            throw new \Exception('This endpoint only accepts JSON.', 415);
        }
        return $next($request);
    }
}
```

### Using the Pipeline Standalone

```php
use Pramnos\Http\MiddlewarePipeline;

$result = (new MiddlewarePipeline())
    ->pipe(new LoggingMiddleware())
    ->pipe(new AuthMiddleware())
    ->pipe(new ThrottleMiddleware(60, 60))
    ->run($request, fn($req) => $controller->myAction());
```

### Built-in Middleware

| Class | Description |
|---|---|
| `AuthMiddleware` | Throws 401 or redirects if not logged in |
| `CorsMiddleware` | Sets `Access-Control-*` headers; handles OPTIONS preflight |
| `ThrottleMiddleware` | Rate-limits by IP using APCu (requires `apcu` extension) |
| `MaintenanceModeMiddleware` | Returns 503 when `maintenance.flag` exists |
| `CsrfMiddleware` | Validates CSRF token on POST/PUT/PATCH/DELETE |

**Execution order:**

```
Global middleware (registration order)
    └─ Route-specific middleware (registration order)
           └─ Permission check (unchanged)
                  └─ Action method
```

---

## Response Object

`Pramnos\Http\Response` — an immutable-style fluent builder for HTTP responses.

```php
use Pramnos\Http\Response;

// Simple HTML response
return Response::make('<p>Hello</p>')->send();

// JSON API response
return Response::json(['user' => $user])->send();

// Redirect
return Response::redirect('/dashboard', 302)->send();

// Custom status + headers
return Response::make('Created', 201)
    ->withHeader('Location', '/api/users/42')
    ->withHeader('X-Request-Id', $requestId)
    ->send();
```

Every mutator returns a **new cloned instance** — safe to share and branch:

```php
$base = Response::json([])->withHeader('X-Api-Version', '2');

$ok    = $base->withBody(json_encode(['ok' => true]))->withStatus(200);
$error = $base->withBody(json_encode(['error' => 'Not found']))->withStatus(404);
```

### In Middleware

```php
class AddSecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            return $response
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY');
        }

        return $response;
    }
}
```

### API Reference

**Static factories:**
- `Response::make(string $body = '', int $status = 200): static`
- `Response::json(mixed $data, int $status = 200, int $flags = 0): static`
- `Response::redirect(string $url, int $status = 302): static`

**Fluent mutators (return new instance):**
- `withStatus(int $code): static`
- `withHeader(string $name, string $value): static`
- `withRawHeader(string $name, string $value): static`
- `withoutHeader(string $name): static`
- `withBody(string $body): static`

**Accessors:**
- `getStatusCode(): int`, `getBody(): string`, `getHeader(string $name): array`
- `hasHeader(string $name): bool`, `getHeaders(): array`

**Emission:**
- `send(): static` — emits status code, headers, and body.

---

## Exception Handler

`Pramnos\Http\ExceptionHandler` — centralises exception rendering and logging.

```php
use Pramnos\Http\ExceptionHandler;

// Inside a catch block
ExceptionHandler::log($exception);
ExceptionHandler::render($exception, 'html', false)->send();
exit();

// Auto-detect format (JSON vs HTML)
$format = $doc->getType() === 'json' ? 'json' : 'html';
$debug  = defined('DEVELOPMENT') && DEVELOPMENT === true;
ExceptionHandler::log($exception);
ExceptionHandler::render($exception, $format, $debug)->send();

// Global handler for early-bootstrap / CLI
set_exception_handler(function (\Throwable $e) {
    ExceptionHandler::log($e);
    ExceptionHandler::render($e, ExceptionHandler::detectFormat())->send();
    exit(1);
});
```

### Output Formats

| Scenario | Format | Debug | Output |
|---|---|---|---|
| HTML app — production | `html` | `false` | Friendly error page |
| HTML app — development | `html` | `true` | Full stack trace (HTML-escaped) |
| JSON API — production | `json` | `false` | `{"error": "msg", "code": 422}` |
| JSON API — development | `json` | `true` | + `"exception"`, `"file"`, `"line"`, `"trace"` array |

**HTTP status mapping:** `getCode()` is used when in 400–599 range; everything else maps to **500**.

### API Reference

| Method | Description |
|---|---|
| `ExceptionHandler::render(\Throwable $e, string $format = 'html', bool $debug = false): Response` | Build a Response for the exception |
| `ExceptionHandler::log(\Throwable $e, string $logFile = 'pramnosframework'): void` | Write full exception detail to error log |
| `ExceptionHandler::detectFormat(): string` | Returns `'json'` or `'html'` based on `HTTP_ACCEPT` |

---

## Related Documentation

- **[Database API Guide](Pramnos_Database_API_Guide.md)** — Database operations and best practices
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** — User authentication and authorization
- **[Routing Guide](Pramnos_Routing_Guide.md)** — Modern routing with PHP 8 attributes
- **[Security Guide](Pramnos_Security_Guide.md)** — CSRF, XSS, sessions, 2FA
- **[Authorization Guide](Pramnos_Authorization_Guide.md)** — Policy engine and access control
- **[Cache System Guide](Pramnos_Cache_Guide.md)** — Performance optimization
- **[Console Commands Guide](Pramnos_Console_Guide.md)** — CLI tools and generators
- **[Logging System Guide](Pramnos_Logging_Guide.md)** — Application monitoring
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** — Output formats
- **[Theme System Guide](Pramnos_Theme_Guide.md)** — UI theming and templates
- **[Email System Guide](Pramnos_Email_Guide.md)** — Email handling and notifications
- **[Media System Guide](Pramnos_Media_Guide.md)** — File uploads and media processing
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** — Multi-language support
