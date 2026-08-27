---
use_cases:
  - Writing or changing a controller
  - Rendering a view or passing data into a template
  - Pointing the framework at views that live in a tpl/ subdirectory
  - Understanding how a request becomes a response (middleware pipeline, Response object)
  - Handling errors or registering an exception handler
  - Telling the user what happened after a redirect (flash messages)
  - Reading application configuration in app/app.php
  - Serving anonymous traffic from cache when the framework starts a session
  - Turning off framework behaviour an application does not want
  - Shortening text for a listing, a column or a meta description
---

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

## Reading the request body

`$_POST` is filled by PHP **for POST only**. A handler that reads it under DELETE,
PUT or PATCH finds nothing, and nothing about the code says it will. That has
already shipped as three separate bugs in one application — banning worked and
unbanning was impossible, an endpoint worked over POST and failed over DELETE on
the same route, a third accepted JSON and refused the form-encoded body every
other endpoint used. All three passed their unit tests, because a test that seeds
`$_POST` for a DELETE constructs a state no real request can produce.

**Use `body()`.** It answers for whatever method this request actually used:

```php
$request = new \Pramnos\Http\Request();

$fields = $request->body();                 // the body, whatever the method
$id     = $request->bodyValue('id');        // one field
$reason = $request->bodyValue('reason', ''); // …with a default
```

| Method | What `body()` returns |
| --- | --- |
| `POST` | `$_POST` (form-encoded), or the decoded JSON body |
| `PUT` / `DELETE` / `PATCH` | the decoded body — PHP fills nothing for these |
| `GET` / `HEAD` | `$_GET`, because on a GET the query *is* the input |
| anything else | the decoded body |

Two things it does that `allCurrent()` does not:

- **It reads the method live**, from `$_SERVER`, rather than using the one captured
  when the singleton was built. The captured one is right in production and stale
  anywhere the method is set afterwards — including every test, which is how a fix
  can pass over HTTP and fail under PHPUnit.
- **It decodes on demand**, so it still works when the object was constructed under
  a different method.

### JSON bodies

A JSON body is detected from the content type, or from a body that starts with `{`
or `[` when the header is absent (a hand-written `curl` and more than one HTTP
client omit it), and is decoded **associatively** — arrays all the way down. A
shallow `(array) json_decode($raw)` casts only the top level and leaves every
nested value an `stdClass`, which makes a handler that iterates a nested list and
checks `is_array()` reject the whole payload.

A body that declares or looks like JSON and is not valid JSON yields an **empty**
array, deliberately. It is not handed to `parse_str`, because
`parse_str('{"id":7}', $out)` produces `['{"id":7}' => '']` — non-empty, so an
`empty()` fallback never fires, and nonsense, so nothing reads correctly either.

`Request::$putData`, `$deleteData` and `$patchData` remain public and are what
`all('DELETE')` and friends return; `body()` is the accessor that saves you having
to know which one applies.

### The raw body, when you need the bytes

A signature to verify, a WebAuthn assertion, a JSON manifest — anything where the
exact bytes matter and a decoded array will not do. Read it with
`Request::rawBody()`:

```php
$raw = \Pramnos\Http\Request::rawBody();   // '' when the request carried no body
```

**Never `file_get_contents('php://input')`.** It is a stream, and reading it is
not repeatable: for `multipart/form-data` the second read returns an empty string
under every SAPI, and with `enable_post_data_reading` off so does the first one
after anything else has read it. So a request whose body had already been read —
by `body()`, by a middleware, by another handler — reached the second reader as a
request with *no body*, and the handler answered "malformed or missing payload"
for a payload that was present. `rawBody()` reads once and keeps the result, so
every reader in a request gets it, in whatever order they ask.

It returns a `string`, never `false`: a `false` from `file_get_contents()` passes
an `if ($raw === '')` check for "no payload" and then reaches `json_decode()`,
which turns a 400 into a 500.

The cache is per request — `Request::resetInstance()` clears it, so a worker
handling several requests in one process does not answer with the previous body.

In a test, supply a body with `Request::setRawInput($body)`; that is the same
value `rawBody()` returns, which is what makes a handler's body-reading branch
reachable at all.

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

#### Templates in a subdirectory

The convention is flat: `views/<module>/<template>.<type>.php`. That is what the
reference application and all three scaffolded themes use, and nothing needs to be
configured for it.

An application migrating off the **legacy** view layer will have them one level
down — `pramnos_application_view` built `<path>/tpl/<template>.<type>.php`. Declare
it once on a base view rather than moving the files:

```php
abstract class AppView extends \Pramnos\Application\View
{
    protected $tplSubdirectory = 'tpl';
}
```

It is a declaration, not a search. Trying `tpl/` whenever the flat path misses would
put a `file_exists()` on every render of every project, for ever, to serve a layout
none of them use — and would quietly establish a second convention the framework
then owes support for.

#### Where the framework looks for an application's views

When a view is not found on the controller's own paths, `getView()` falls back to
the application directory. That path comes from **`APPS_PATH`** when it is defined,
and from `ROOT/<INCLUDES>` otherwise.

The distinction matters for an installation whose applications do not live beside
its framework code: `INCLUDES` describes where the *code* is, `APPS_PATH` describes
where the *applications* are. In a stock layout they are the same directory. When
they are not, a fallback built from the wrong one searches somewhere that does not
exist — and finds nothing, which is indistinguishable from a view that is genuinely
absent.

`INCLUDES` itself defaults to `src`.

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

### When a template is not found

`getTpl()` returns `false`, logs *"Cannot find view template"* and leaves the view's
output alone — a missing template is a failure to report, not a page to abandon.

One thing happens before that, and it is worth knowing because it looks like magic
otherwise: **if the request carries `?format=json` and the view has a model with a
`getJsonList()` method, the model answers instead.** That is how a view can serve a
DataTables-style JSON endpoint at the same route as its HTML page without a template for
it.

```php
class Posts extends \Pramnos\Application\Model
{
    public function getJsonList(): string { /* … */ }
}
```

The check is `is_object($this->model) && method_exists(...)`. It used to be
`isset($this->model)`, which is why this section exists: `$model` is declared
`public $model = false`, and `isset()` answers *not null* rather than *not empty*, so the
guard passed for every view that has no model at all — and `method_exists(false, …)` is a
`TypeError` on PHP 8. The branch that exists to recover from a missing template was
taking the page down instead, on any request with `?format=json`. Reported from a
consuming application's home page.

### Layouts and partials

A template can declare a layout and fill named sections of it:

```php
<?php $this->layout('layouts/main'); ?>
<?php $this->section('content'); ?>
    <h1><?= $this->e($this->title) ?></h1>
<?php $this->endsection(); ?>
```

```php
<!-- src/Views/layouts/main.html.php -->
<!doctype html>
<html><head><title><?= $this->e($this->title) ?></title></head>
<body><?= $this->yield('content') ?></body></html>
```

`$this->insert('partials/card', ['item' => $item])` renders a partial with extra
variables.

**Where the framework looks**, in order:

1. the name as an absolute path;
2. `src/Views/<ViewName>/` — the view's own directory, so a per-view override wins;
3. `ROOT/views/`;
4. **`src/Views/`** — beside the view directories, where something shared by several
   of them belongs;
5. a theme override, if the active theme allows them.

Both `.html.php` and `.tpl.php` are tried at each step.

**Debug output.** In debug mode an HTML view appends a comment naming the template it
rendered and when. It is emitted only when `Application::isDebugMode()` says so — the
`APP_DEBUG` environment variable, the `DEVELOPMENT` constant, or the debug setting. It
used to be unconditional, which put the application's file layout into the source of
every public page.

Step 4 was missing until 2026-08-16, which made a shared layout the one thing that
could not be found — and a declared layout that does not resolve renders the child
**alone**: a page returned with `200`, no `<head>`, and, until the same change, nothing
in any log. It presents as a stylesheet that failed to load. If a page comes back
looking unstyled and structurally bare, check the log for `Layout not found:` before
looking at anything else.

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


## Declining the automatic session

`Application::init()` starts a session on every request. That is the right default
for an application whose pages are mostly signed-in, and the wrong one for a site
serving anonymous traffic: every response carries `Set-Cookie: PHPSESSID`, including
a page render for a visitor who will never read or write a thing.

**That is what stops the page cache from ever storing anything.**
[`PageCache`](Pramnos_Page_Cache_Guide.md) refuses to store a response that sets a
cookie — correctly, because such a response is per-visitor in its body too. So the
two features are mutually exclusive until you say otherwise:

```php
// app/app.php
return [
    'session' => 'lazy',
    ...
];
```

Check what you are actually sending:

```bash
curl -D- -o /dev/null -s https://example.test/ | grep -i set-cookie
```

### What "lazy" means, and what it does not

It means **do not create a session for a visitor who has none**. A request arriving
with a session cookie still starts one at exactly the point it always did.

That distinction is the whole reason the mode is usable. Around two hundred places
in the framework read `$_SESSION` directly — `Session::staticIsLogged()` above all —
and "never start one" would report every signed-in visitor as anonymous until
something happened to call a token helper.

| Request | Eager (default) | Lazy |
|---|---|---|
| Anonymous, no cookie | session started, `Set-Cookie` sent | no session, no cookie, **cacheable** |
| Carrying a session cookie | session started | session started |
| Signs in during the request | session started | started by the login path |

### It also changes the cache headers you send

`session_start()` does more than set a cookie. PHP's `session.cache_limiter` defaults
to `nocache`, so starting a session queues three response headers:

```
Pragma: no-cache
Expires: Thu, 19 Nov 1981 08:52:00 GMT
Cache-Control: no-store, no-cache, must-revalidate
```

Nothing in this framework asks for those, and nothing removes them. So in lazy mode an
anonymous visitor gets none of them and an eager one gets all three — which means
**which cache headers a response carries depends on whether a session happened to
start**, not on any decision about the page.

It matters most on a page-cache hit, where the page did not come from the application
at all and the headers are left over from `init()`. That is safe as it stands — telling
a browser not to store a *shared* copy is right, and it is what stops an anonymous page
being handed back after sign-in — but it is not a decision. Say what you mean with
[`cacheControl`](Pramnos_Page_Cache_Guide.md#what-a-hit-tells-the-browser), which also
clears the leftover `Pragma` and `Expires`.

### If your application writes to `$_SESSION`

Call `ensureStarted()` first, on any request that may not have a session yet:

```php
$this->application->session->ensureStarted();
$_SESSION['wizard_step'] = 2;
```

It is idempotent and costs one `session_status()` check when a session already
exists.

!!! warning "A write without it is lost silently"
    PHP will happily let you write to `$_SESSION` when no session has been started.
    The value goes into a plain array and disappears at the end of the request —
    no error, no warning. **This is why the mode is opt-in rather than the
    default.**

The framework's own write paths already call it: signing in, the pending two-factor
step, passkey challenges, validation errors and old input, flash messages and
errors, and `?lang=`.

## Declining session tracking

`SessionTrackingMiddleware` records visitors in the `sessions` table. It runs
automatically unless the application **names** it in `middleware` — so, until this
key existed, the way to switch it off was to declare it and then arrange not to run
it, in two places, each needing a comment explaining the other.

```php
// app/app.php
return [
    'session_tracking' => false,
    ...
];
```

Omission is not refusal, and one application learned that the expensive way: its
config carried the comment *"session tracking is deliberately NOT wired"* while the
middleware had been running the whole time — two cookies and a database upsert on
every request, crawler hits included — and a passing test guarded the claim while
the behaviour was the opposite.

Accepts the spellings a config file actually contains: `false`, `0`, `'0'`,
`'false'`, `'no'`, `'off'` and `''` all decline. `true`, `1`, `'yes'`, `'on'` leave
it on, as does omitting the key.

The two inference rules still work and are still checked, after this one: naming the
middleware in `middleware`, or registering the deprecated `Addon\System\Session`.
An explicit answer is never overruled by a guess about one.

!!! tip "Is anything reading the table?"
    Session tracking earns its cost when something reads `sessions` — an active
    devices screen, an admin presence view. If your application answers those from
    somewhere else, this is two cookies and one write per request for a table with
    no reader.

## Flash messages and errors

A controller tells the user what happened by flashing a sentence and redirecting. The message
survives exactly one request: it is shown on the page the redirect lands on, and **not again on
a reload**.

```php
// In a controller
$this->addMessage('Saved.');
$this->addError('That record no longer exists.');

$this->redirect(sURL . 'organizations');
```

```php
// In a view or layout
foreach ($this->messages as $message) {
    echo '<div class="alert alert-success">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
}
foreach ($this->flashErrors as $error) {
    echo '<div class="alert alert-danger">'
        . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
}
```

Outside a view, read them from the request: `$request->messages()` and
`$request->flashErrors()`.

!!! warning "Do not pass messages as query parameters"
    `redirect(sURL . 'things?error=not_found')` is the shape to avoid. The text ends up in the
    URL, so it is shown again on every reload, it stays in browser history, it is in whatever
    the user pastes when asking for help, and it is user-controlled input arriving at a page
    that displays it.

    The framework itself did this in **sixty-seven** places until 2026-08-17. All are converted.

!!! note "If your application reads the flash through `Base`"
    All four members keep working, and they are a supported way to do it — they are `protected`
    on `Base`, so every controller, model and theme inherits them:

    | Member | Consumes the flash? | Typical use |
    | --- | --- | --- |
    | `hasErrors()` / `hasMessages()` | **no** | the gate: `if ($this->hasErrors())` |
    | `_printErrors()` / `_printMessages()` | yes | renders `<span>`s |
    | `_getErrors()` / `_getMessages()` | yes | the raw array, e.g. for a JSON response |

    All four now consult the same per-request capture, because `View::__construct()` drains
    `$_SESSION` into it. Without that, a gate answers `false` for a flash that arrived perfectly
    well and **the printer behind it is never reached** — which is silent: nothing fails, the page
    simply says nothing. That happened to a reference application, in its theme header and five
    views, and took three real HTTP requests against two framework versions to find. Neither its
    5497-test suite nor this framework's saw it.

    The gates are non-destructive on purpose: a gate that consumed would leave the printer with
    nothing, which is the same silence by the opposite route.

    One residual: a `View` snapshots the bags when it is constructed, so an application that
    prints the flash **both** in its theme header and in a template will print it twice. Which
    one prints is the application's call — the framework cannot make that choice without
    breaking the other.

### Three things to know about the mechanism

- **`$this->messages` is not `$this->errors`.** `errors` is the per-field output of a validator
  (`['email' => ['Not an email address.']]`); `messages` and `flashErrors` are whole sentences.
  A template that iterates one expecting the other gets field names where it wanted text.
- **Reading consumes.** The session entry is cleared by the first read of the request, and the
  values stay available for the rest of it — so a controller and a template can both read them
  without one silently eating the other's.
- **It needs a session.** With none, `addMessage()` keeps the value on the object for the
  current request only, which is what a CLI or API context wants anyway.

### Checking for errors in the same request

```php
if ($this->hasErrors()) {
    // …
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

### `getInstance()` builds; `currentInstance()` only looks

`Application::getInstance()` is a **factory**. With no instance for the key it
reads `app.php`, defines constants and runs the whole constructor — setting up
the database, the language and the session. That is what you want when you want
an application.

It is emphatically not what you want when you only need to read a setting:

```php
// Wrong in low-level code — may construct an entire application
$proxies = Application::getInstance()->applicationInfo['trusted_proxies'] ?? [];

// Right — null when there is none, and nothing is built
$app     = Application::currentInstance();
$proxies = $app?->applicationInfo['trusted_proxies'] ?? [];
```

Use `currentInstance()` anywhere that runs **before the application is ready**:
CSRF verification, middleware that runs early in the pipeline, and anything on
the database connection path. The last one is not hypothetical — reading the
application name while a connection was being opened built an application, which
set up `Settings`, which queried the database through the very connection still
being established. `ConnectionPathPurityTest` now fails the build if that
returns.

**Four directories are guarded the same way.** Nothing under `src/Pramnos/Auth/`,
`src/Pramnos/Http/Middleware/`, `src/Pramnos/User/` or `src/Pramnos/Database/` may
call `Application::getInstance()`; `ApplicationFactoryPurityTest` reads the source
and fails the build if it does, with an enumerated exemption list that is
currently empty. `ConnectionPathPurityTest` guards the connection path in
particular; this is the wider rule that path is one instance of.

That guard exists because this section did not prevent the mistake. Nine call
sites were still using the factory, and the placements say why the rule matters:

- inside a **token-minting** method, in `SessionExchange`;
- inside **`User::getCurrentUser()`** — asking *who is signed in* constructed an
  entire application, database and session included;
- inside **`Database::displayError()`**, where building an application builds
  `Settings`, which queries the database that just failed. That also made the
  method's own `error_log()` fallback unreachable, so a database error outside a
  request went nowhere at all;
- and in four middleware and driver methods that wanted to write one property or
  read two booleans.

Every one of them was written as `if ($app)` or `if (!is_object($app))` — a guard
for a null the factory cannot return. So the guard was dead and the construction
was live, and the source had been saying so all along.

One practical consequence when you convert a call site: `currentInstance()`
declares `?Application` while `getInstance()` declares nothing, so any test that
installs a plain `stdClass` in the registry as a fake application starts failing
with a `TypeError`. That is the correct type being enforced — the registry is
meant to hold applications — but it is the first thing you will see. Use a real
subclass with an empty constructor.

If you genuinely need to *build* an application from guarded code, add the file to
`ApplicationFactoryPurityTest::EXEMPT` with the reason. Having to write the reason
down is the point.

### Outside the guarded directories: audited, and twelve calls kept

The remaining `getInstance()` calls in `src/` were examined one by one rather than
left as an unknown. Eleven were converted and one deleted:

- the CSP-nonce reads in the debug panel and in the `Html` and `Raw` renderers, where
  rendering already happens inside a request and the `if ($app && …)` guard was
  already written for a null the factory could not return;
- `Broadcastable::resolveBroadcastingManager()`, where building an application in
  order to ask whether broadcasting is configured was a side effect inside a `try`
  whose `catch` reports "not configured";
- `RouteList`'s third fallback strategy, which asks whether a global instance
  *exists*;
- `TestClient`, whose `if ($appInstance === null)` fallback was unreachable and
  carried a coverage-ignore saying so — it is now live;
- five generated templates the scaffolder writes into every project, now
  `currentInstance()?->…`;
- and a line in the session-cleanup addon that assigned `$app` and **never read it**,
  so an entire application was constructed for a variable nothing used. Deleted.

**Twelve are kept deliberately**, and they fall into two groups:

1. **Console bootstraps** — `Console\Application`, `TimescaleDrain`, `TimescaleEnsure`,
   `PolicyEngine`, `BroadcastServe`, `BaseTestCase`, and the two bootstrap scripts the
   scaffolder generates. Building an application is what these are *for*; the factory
   is the correct call.
2. **Constructor fallbacks** in `Controller` and `Theme`: `__construct($application =
   null)` resolving the current one when none was passed. In a real request both calls
   answer identically, and `currentInstance()` would put **null** into
   `$this->application` for the standalone case — which every unit test that builds a
   controller by hand relies on not happening. The blast radius is every controller
   and every theme, for no gain in production.

The guard covers the directories where the factory is a hazard rather than a choice.
These twelve are choices.

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
| `MaintenanceModeMiddleware` | Returns 503 while `var/MAINTENANCE` **or** `maintenance.flag` exists |
| `CsrfMiddleware` | Validates CSRF token on POST/PUT/PATCH/DELETE |

#### Maintenance mode: which flag, and what the client is told

There are two ways the site goes down on purpose, and until 2026-08-15 they watched
different files:

| Raised by | Flag |
| --- | --- |
| `Application::startMaintenance()` | `<ROOT>/var/MAINTENANCE` |
| `MigrationRunner` (for the duration of a batch) | `<ROOT>/var/MAINTENANCE` |
| A deployment script doing `touch` | whatever it touches — historically `<ROOT>/maintenance.flag` |

`MaintenanceModeMiddleware` watched only the last one. So an application that
registered it exactly as shown above and then ran a migration **served the whole
migration from the live site** — the runner raised its flag, the middleware watched
the other, and nothing looked wrong. With no constructor argument it now watches both.
Pass a path explicitly and that path is the only one watched, because an application
that named its own file has said which file it means.

`Retry-After` is an hour by default in the middleware, five minutes from
`Application::showError()`. Define `PRAMNOS_MAINTENANCE_RETRY_AFTER` to set both:

```php
define('PRAMNOS_MAINTENANCE_RETRY_AFTER', 900);   // 15 minutes
```

**What the client gets.** `Application::showError()` — which the constructor calls when
`var/MAINTENANCE` exists, so a router-dispatched application reaches it too — now sends
a status and a content type:

| Situation | Status | Body |
| --- | --- | --- |
| Maintenance, browser | `503` + `Retry-After` | the HTML page, unchanged |
| Maintenance, `Accept: application/json` | `503` + `Retry-After` | `{"error":"maintenance","retry_after":300,…}` |
| Any other fatal (PHP version, addon, database) | `500` | as above, `"error":"unavailable"`, no retry |

It previously sent **neither**, which produced two failures that look unrelated and
are one bug: a JSON client got `200 OK` with a page of HTML and failed on parsing
rather than recognising the state, and a crawler got the maintenance page as a `200`,
making it eligible to be indexed in place of the real page.

A client is treated as wanting JSON when its `Accept` names `application/json` or it
sends `X-Requested-With: XMLHttpRequest`. Browsers send neither, so no list of API
paths has to be kept in step with the router.

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

## Shortening text

`Pramnos\General\StringHelper::excerpt()` cuts text to a length without splitting a
word:

```php
use Pramnos\General\StringHelper;

StringHelper::excerpt($post->body, 120);          // 'The quick brown fox…'
StringHelper::excerpt($post->body, 120, ' [more]');
```

Three things it guarantees, each of them a bug it was written to remove:

- **At most `$length` characters, ellipsis included.** Size a column or a meta
  description with it and the number holds.
- **HTML is stripped before measuring**, so the length is a length of visible text — an
  excerpt of markup gives prose, not an unclosed `<span class="…">`.
- **A word longer than the limit is cut, not lost.** `Καθηγητήςμαθηματικών` at 10 gives
  `Καθηγητής…`. It used to give the ellipsis on its own — `mb_strrpos()` finds no space,
  returns `false`, `mb_substr()` reads that as 0 — so one long word came back looking
  like missing text. A Greek compound, a name with no space, a URL and a hashtag are all
  that shape, and a listing page is where you find out.

`null` is an empty string, not a `TypeError`. A negative length is an
`InvalidArgumentException`.

### `Helpers::shortenText()` is a deprecated alias

It forwards, so there is one implementation. Two behaviours changed on the way and both
are visible to existing callers:

| | before | now |
|---|---|---|
| result length | could exceed `$length` — the suffix was appended *after* cutting to it | never exceeds `$length` |
| one long word | the suffix alone | the word, hard-cut |

The default suffix is the character `…` rather than the entity `&hellip;`. It renders the
same in HTML, and it is correct in the places the entity was wrong — a plain-text email, a
JSON field, or anything that escapes the result and turned `&hellip;` into a visible
`&amp;hellip;`. It also has to be one character, because the length now includes it, and
charging eight for an ellipsis leaves almost nothing of a short excerpt. A suffix you pass
yourself is used and counted literally.

`$charset` is ignored; `excerpt()` uses the internal encoding, which this framework sets
to UTF-8.

### Not to be confused with `CommandBase::truncateText()`

The console has its own, and it is not a duplicate — it measures **visible** width,
ignoring ANSI escape codes, and it *does* split words. That is right for a table column
in a terminal and wrong for prose. Use `excerpt()` for anything a person reads as a
sentence.

`symfony/string` is installed too, and its `truncate($length, $ellipsis, cut: false)`
guarantees the opposite of `excerpt()`: it extends to the **next** word boundary, so a
limit of 5 on `The quick brown fox` returns ten characters, and a single long word comes
back whole and unmarked. Useful when you want at least `$length`; not when the bound is
the point.

## Reading a user agent

`Pramnos\General\Helpers::getBrowser($agent)` returns the browser, its version, the
operating system and its version, the rendering engine — and **which engine worked it
out**:

```php
$b = \Pramnos\General\Helpers::getBrowser($_SERVER['HTTP_USER_AGENT'] ?? '');

$b->browser;    // 'Chrome'
$b->version;    // '120.0'
$b->majorver;   // '120'
$b->platform;   // 'Windows'    — the operating system
$b->os_number;  // '10'         — its version
$b->engine;     // 'Blink'
$b->detector;   // 'device-detector' | 'browscap' | 'sniff'
```

### Install the parser, or get a name and nothing else

Three engines are tried in order, and only the first fills every field:

| `detector` | Needs | Fills |
|---|---|---|
| `device-detector` | `composer require matomo/device-detector` | everything above |
| `browscap` | PHP's `browscap` ini pointing at a browscap.ini | all but `os_number` |
| `sniff` | nothing | `browser` only |

**`sniff` is what an installation gets by default**, and it is a six-branch regex that
returns a lowercase name — `version`, `platform`, `majorver`, `os_number` and `engine`
come back empty. The method has always returned a valid object either way, which is what
made this hard to notice: one consuming application measured 3,040 visits with a browser
name and 771 with an operating system, and nothing had gone wrong as far as any code
could tell.

`matomo/device-detector` is a **suggest**, not a requirement — a framework should not put
a user-agent parser into every project that installs it. Add it when you store or report
any of these fields:

```bash
composer require matomo/device-detector
```

Its regexes ship inside the package, so unlike `browscap/browscap-php` there is no data
file to provision and no monthly refresh: staleness becomes `composer update` rather than
a cron job, and there is no way for a missing download to degrade it silently.

### What `detector` is for

An empty `version` used to mean either *this agent is not identifiable* or *there was no
parser running*, and those call for opposite responses — the first is a fact about the
visitor, the second is a missing package. `detector` is how a caller tells them apart,
and it is the reason the field exists at all.

### Two details that are deliberate

**`platform` is the operating system.** device-detector has a `platform` of its own and
it means the CPU architecture — `x64`, `ARM`. Passing that through would have been the
obvious mapping and would have quietly changed what a public field means for every
existing caller.

**A crawler gets a name and nothing else.** `Googlebot` with an empty version and engine,
rather than an invented version. This object goes into statistics tables a row at a time,
and a fabricated number is worse there than an empty one.

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
