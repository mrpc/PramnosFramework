---
use_cases:
  - Writing or changing a controller
  - Calling getModel() from a controller and getting "Cannot find model"
  - Using a site model from an administration-area controller
  - Rendering a view or passing data into a template
  - Pointing the framework at views that live in a tpl/ subdirectory
  - Understanding how a request becomes a response (middleware pipeline, Response object)
  - Handling errors or registering an exception handler
  - Telling the user what happened after a redirect (flash messages)
  - Reading application configuration in app/app.php
  - Storing configuration an administrator edits at runtime (the settings table)
  - Allowing an external domain the Content-Security-Policy is blocking
  - Diagnosing a form POST or SSO redirect the browser cancels
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
        
        // Page heading (read by the template), browser title and breadcrumbs
        $view->header = 'Dashboard';
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'My Dashboard';
        $this->addbreadcrumb('Dashboard', sURL . 'Example/dashboard');
        
        return $view->display('dashboard');
    }
}
```

### How a controller finds its model

`$this->getModel('Name')` turns a name into one class name, checks it with
`class_exists()`, and builds the model as `new $class($this, $name)`. It reads two things
from the application: `applicationInfo['namespace']` (the `namespace` key of `app/app.php`)
and `$application->appName` (`''` for the site; set only when the application is built as
`Application::getInstance('Sub')`).

#### Lookup order

1. **Build one candidate class name**, from the first rule that applies:

    | `namespace` set? | `appName` | Candidate |
    |---|---|---|
    | yes | `''` | `\<namespace>\Models\<name>` |
    | yes | `'Sub'` | `\<namespace>\Sub\Models\<name>` |
    | no | `''` | as the first row, with `Pramnos` as the namespace |
    | no | `'Sub'` | as the second row, with `Pramnos` as the namespace |

2. **Try it as written.** If the class exists, it is constructed with the name exactly as
   you passed it.
3. **Try it with the class's first letter upper-cased** (`ucfirst($name)`; the namespace in
   front of it is kept as it is). If that class exists, it is constructed with the
   upper-cased name.
4. **Otherwise throw** a plain `\Exception`: `Cannot find model: <name> (Class: <candidate>)`.
   The message always shows the candidate from step 2.

That is the whole search. In particular:

- **There is one namespace, not a list.** With `appName` set, the site's
  `\<namespace>\Models\` is not tried as a fallback.
- **The administration area is not consulted.** Unlike `getController()` and `getView()`,
  which try `<namespace>\Admin\…` first, `getModel()` ignores `$application->area`. A
  controller in `<namespace>\Admin\Controllers\` gets the site's `<namespace>\Models\X`,
  and a class under `<namespace>\Admin\Models\` is never found.
- **There is no framework fallback.** With a `namespace` set, nothing under `\Pramnos\`
  is tried.
- **Nothing is cached.** Every call constructs a new instance; two calls return two
  different objects.
- **The name may contain a sub-namespace.** `getModel('Billing\Invoice')` resolves
  `\<namespace>\Models\Billing\Invoice`.

The layout `pramnos init` writes — models in `src/Models/`, `namespace <Ns>\Models;`,
`'namespace' => '<Ns>'` in `app/app.php`, PSR-4 `<Ns>\` → `src/`, and
`Application::getInstance()` with no name — is the first row of the table, and works
as-is. `pramnos make model` writes the class to the same place `getModel()` looks.

#### The name you pass is the model's name

The second constructor argument becomes the model's `modelname`, and a model that does not
declare `$_dbtable` takes its table from it: `'#PREFIX#' . $name . 's'`. So with a class
`Order` that declares no table:

- `getModel('Order')` → table `#PREFIX#Orders`
- `getModel('order')` → table `#PREFIX#orders` if `Order` is already loaded,
  `#PREFIX#Orders` if it is not (see the case notes below): the same call reaches a different
  table depending on what ran earlier in the request
- `getModel('Billing\Invoice')` → table `#PREFIX#Billing\Invoices`

Declare `$_dbtable` on every model reached through `getModel()` — the scaffolded and
`make model` templates already do — and call it with the class's exact short name.

#### Case sensitivity

PHP class names are case-insensitive; the Composer autoloader on a case-sensitive
filesystem is not (it looks for `src/Models/<name>.php` exactly). Therefore:

- `getModel('Users')` — always works.
- `getModel('users')` — works through step 3 when the class is not loaded yet
  (`Users.php` is autoloaded, name passed `'Users'`); if `Users` is already loaded, step 2
  matches it case-insensitively and the name passed is `'users'`.
- `getModel('USERS')` / `getModel('uSERS')` — works only if the class is already loaded
  (name passed as written); otherwise throws. The result therefore depends on what ran
  earlier in the request.

#### Examples

Application with `'namespace' => 'Shop'`, models in `src/Models/`.

| Call (in controller) | `appName` / area | Class(es) tried | Result |
|---|---|---|---|
| `getModel('Order')` | `''` / none | `\Shop\Models\Order` | `Shop\Models\Order`, name `'Order'` |
| `getModel('order')`, class not yet loaded | `''` / none | `\Shop\Models\order`, `\Shop\Models\Order` | `Shop\Models\Order`, name `'Order'` |
| `getModel('order')`, class already loaded | `''` / none | `\Shop\Models\order` | `Shop\Models\Order`, name `'order'` |
| `getModel('ORDER')`, class not yet loaded | `''` / none | `\Shop\Models\ORDER` (twice) | exception |
| `getModel('Order')` from `Shop\Admin\Controllers\Orders` | `''` / `Admin` | `\Shop\Models\Order` | `Shop\Models\Order` |
| `getModel('Report')`, class is `Shop\Admin\Models\Report` | `''` / `Admin` | `\Shop\Models\Report` | exception |
| `getModel('Billing\Invoice')` | `''` / none | `\Shop\Models\Billing\Invoice` | `Shop\Models\Billing\Invoice` |
| `getModel('Order')` | `'Sub'` / none | `\Shop\Sub\Models\Order` | found only if that class exists; `\Shop\Models\Order` is not tried |
| `getModel('Order')`, no `namespace` key | `''` / none | `Order` under `Models` in the `Pramnos` namespace | exception: the framework ships no models there |
| `getModel('')` | any | `\Shop\Models\` | exception |
| `getModel('list')` | any | `\Shop\Models\list`, `\Shop\Models\List` | exception — no such class can exist |

#### Known gaps

| Unsupported | What happens | Workaround |
|---|---|---|
| A model in an area's own namespace (`<Ns>\Admin\Models\X`) | Not searched; exception | `new \Ns\Admin\Models\X($this)` |
| A site model from a named sub-application (`appName` set) | Only `<Ns>\<appName>\Models\` is searched | `new \Ns\Models\X($this)` |
| A model class in any other namespace (a package, `\Pramnos\…` when a `namespace` is set) | Exception | `new \Vendor\Pkg\X($this)` |
| A class name PHP reserves: `String`, `Int`, `Object`, `Mixed`, `Parent`, `Self` (reserved class names) and `List`, `Class`, `Match`, `Readonly`, `Fn` (keywords) | The class cannot be declared at all, so no call can find it | Name the class something else (`StringModel`, `ListModel`), set `$_dbtable` explicitly, and `getModel('StringModel')` |
| Any capitalisation other than exact or lower-first, before the class is loaded | Exception (or success, if it happens to be loaded) | Pass the class's exact short name |
| Reusing one instance | Each call builds a new model | Keep the returned object in a variable |

Constructing the model directly is equivalent to what `getModel()` does: the constructor is
`__construct(Controller $controller, $name = '')`, and an omitted name defaults to the class's
short name.

### Controller Conventions

1. **Class Names**: Use PascalCase (e.g., `UserDashboard`, `ApiController`)
2. **File Names**: Match class names (e.g., `UserDashboard.php`)
3. **Namespaces**: Use project-specific namespaces (e.g., `Project\Controllers`)
4. **Methods**: Use camelCase for action methods

**Register actions with `addaction()`, never by redeclaring `$actions`.**
`Pramnos\Application\Controller` declares `public $actions = array();` **untyped**, and
PHP does not allow a subclass to add a type to an untyped inherited property. A controller
written as

```php
class Privacy extends \Pramnos\Application\Controller
{
    public array $actions = ['display'];   // ← fatal
}
```

dies at **autoload** time:

```
Fatal error: Type of App\Controllers\Privacy::$actions must be omitted
to match the parent definition in class Pramnos\Application\Controller
```

Not when the action runs — when `class_exists()` is called on the class, so the page is a
white screen with a stack trace rather than anything the error handler formats. The
constructor form above is the one every framework controller uses, and it is what the
dispatcher and the generated "every registered action has a method" test both read.

### Reading an id out of the URL

**An action's parameters are not URL segments.** The classic dispatcher passes the
request's arguments **array** to every action, so an action declaring
`mixed $id = null` receives that array — and `(int)` of an array is not the id.
Read it from the request instead:

```php
public function view(mixed $id = null): mixed
{
    // The route may pass one (an API routing table does); the dispatcher does not.
    if (!is_scalar($id) || (int) $id < 1) {
        $id = (int) \Pramnos\Http\Request::staticGetOption();
    }
    // …
}
```

A typed parameter (`?string $id`) makes the action a `TypeError` under the classic
dispatcher, and an untyped one silently receives the array. A unit test that calls
`addmember(5)` passes either way, because it constructs a state no request can produce —
so test such an action through a request.

**One id per path, and the rest as query parameters.** `staticGetOption()` returns
a single option, and the parser only fills it when the path has exactly one segment
after the action. `action/a/b` becomes `$_GET['a'] = 'b'` — so a second id in a
second segment does not arrive as an option *or* as an argument:

```php
adminUrl('Organizations/removemember/' . $orgId) . '?userid=' . $userId   // reads back
adminUrl('Organizations/removemember/' . $orgId . '/' . $userId)          // does not
```

And read the second id from `$_GET`/`$_POST` only — never from
`staticGetOption()`, which is the *first* segment. Resolving both the same way makes
them equal, so an update matches no row while the screen still reports success.

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

An action that changes state is also declared with `addWriteAction()`, and `exec()` then accepts
it only as a `POST` carrying the session's token — see
[Controller actions that change something](Pramnos_Security_Guide.md#controller-actions-that-change-something-addwriteaction).

### URL Handling and Redirects

```php
// Always use sURL constant for URLs (works in subdirectories)
$this->redirect(sURL . 'Controller/action');

// Add breadcrumbs
$this->addbreadcrumb('Home', sURL);
$this->addbreadcrumb('Dashboard', sURL . 'Dashboard/dashboard');

// Page heading is a view variable; the browser title is the document's
$view->header = 'Page Title';
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
1.  **Nonce Generation**: A unique, cryptographically secure nonce is generated once per request — in `Application::exec()`, and again just before rendering if `exec()` was overridden without calling the parent. Read it as `Application::currentInstance()?->cspNonce`.
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

**Which directives take sources**, in full — the list is
`Application::CSP_CONFIGURABLE`, and a test asserts every entry in it actually reaches
the header:

| Directive | Default | What another origin there is for |
| --- | --- | --- |
| `script-src` | `'self'` + nonce | A CDN or a map SDK |
| `style-src` | `'self'` + nonce | Web fonts, an icon stylesheet |
| `img-src` | `'self' data: blob:` (`blob:` for a picture the page made, such as the image-upload crop) | Tiles, avatars, a media host |
| `font-src` | `'self' data:` | `fonts.gstatic.com` |
| `connect-src` | `'self'` | An API or a WebSocket you call |
| `media-src` | `'self'` | Audio or video served elsewhere |
| `worker-src` | `'self' blob:` | A worker script from a CDN |
| `frame-src` | `'self'` | What you embed — a video, a map, a payment frame |
| `frame-ancestors` | `'self'` | Who may embed **you** — a partner portal |
| `form-action` | `'self'` | Where a form POST may end up, including after a redirect |

Everything else in the policy is deliberately fixed. `default-src 'none'` is the floor the
rest is measured against, `object-src 'none'` and `base-uri 'self'` are worth keeping
absolute, `style-src-attr` and `upgrade-insecure-requests` take no sources, and a web
manifest a browser will fetch is same-origin in practice.

**A `csp` key that is not in that table is logged and ignored.** It is not a boot failure,
because the same policy is built on a page-cache hit before there is an application to
refuse on behalf of — but it will not silently look applied either. If a directive appears
in the header without the sources you set, the log line naming it is the first place to
look.

#### 3. `form-action` and an OAuth2 authorization server

If your application is an authorization server, it must name its registered client
callbacks:

```php
'csp' => [
    'form-action' => [
        'https://client-one.example',
        'https://client-two.example',
    ],
]
```

Without them, SSO logins that go **through the login form** fail in a way that leaves no
server-side trace. A browser applies `form-action` to every redirect in the chain, not just
the form's `action` attribute, so:

```
POST /Home/login                                  same origin   → allowed
  → 302 /oauth/authorize?…                        same origin   → allowed
  → 302 https://client.example/login-sso?code=…   other origin   → BLOCKED
```

The authorization code is generated, written and named in a `Location` header; the browser
then cancels the navigation. Chrome reports the violation against the *initial*,
same-origin URL, so the console message reads as a paradox — and the user sees a page that
simply did not go anywhere.

It is also intermittent, for the same reason: a user who still holds a session takes
`GET /oauth/authorize` → 302 → client, which is no form submission and which works. Only
the attempt that passes through the login form is blocked, so the same client succeeds or
fails on whether the session had expired.

Keep `'self'` as the base — it is the mitigation for a form injected by an XSS that would
otherwise post credentials off-site — and add only the callbacks you have registered.

#### 4. Apache Configuration
When using the framework's built-in CSP, ensure you remove any manual `Content-Security-Policy` headers from your Apache `.htaccess` or VirtualHost files to prevent header duplication or conflicts.

---

## Reading the request body

`$_POST` is filled by PHP **for POST only**. A handler that reads it under DELETE,
PUT or PATCH finds nothing, and nothing about the code says it will. A unit test that
seeds `$_POST` for a DELETE passes anyway, because it constructs a state no real request
can produce.

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

An application whose templates sit one level down —
`<path>/tpl/<template>.<type>.php` — declares that once on a base view rather than moving
the files:

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

### What a template sees

A template runs in a scope of its own. It sees:

- `$this`, the view, with everything the controller assigned to it;
- `$lang`, the current language;
- `$model`, the view's model;
- `$_url`, the controller's address;
- `$doc`, the document;
- `$tpl` and `$type`, the template's name and type.

Those are copies. A variable a template assigns stays in the template, so a template can name
its own variables `$render` or `$type` without changing how the view is rendered. Its variables
do reach the layout it declares, so a layout can read a `$title` the template set.

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

The check is `is_object($this->model) && method_exists(...)`, not `isset($this->model)`:
`$model` is declared `public $model = false`, `isset()` answers *not null* rather than *not
empty*, and `method_exists(false, …)` is a `TypeError` on PHP 8. A view with no model and a
missing template therefore logs and renders nothing on `?format=json`, rather than failing.

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
5. **beside the file being drawn** — so a scaffold view the application did not override
   (the application has `src/Views/OAuth2/` for one file, and the framework's
   `security.html.php` is drawn) finds its own `../partials/…` there;
6. a theme override, if the active theme allows them;
7. **the bundled scaffold**, `scaffolding/themes/<scaffold_theme>/views/<ViewName>/`. A view the
   application overrode (`src/Views/OAuth2/delete_account.html.php`) inserts a partial it never
   copied (`delete_account_tasks`) and gets the framework's. A whole template has always fallen
   back there; a partial does too.

Both `.html.php` and `.tpl.php` are tried at each step.

**Debug output.** In debug mode an HTML view appends a comment naming the template it
rendered and when. It is emitted only when `Application::isDebugMode()` says so — the
`APP_DEBUG` environment variable, the `DEVELOPMENT` constant, or the debug setting —
never in production, where it would put the application's file layout into the source of
every public page.

**A declared layout that does not resolve renders the child alone**: a page returned with
`200`, no `<head>`, and a `Layout not found:` line in the log. It presents as a stylesheet
that failed to load. If a page comes back looking unstyled and structurally bare, check the
log for `Layout not found:` before looking at anything else.

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
    public function init($settingsFile = '')
    {
        parent::init($settingsFile);
        // application-specific setup: register libraries, services, resolvers…
        return $this;
    }
}
```

This is the shape `init` scaffolds. Override `init()` for setup, and leave `exec()` alone
unless you call `parent::exec()` — it is where the route is dispatched and the CSP nonce is
created. Application settings (name, features, `csp`, `middleware`, `auth`, …) are not set
in code: they live in `app/app.php` and are read into `$this->applicationInfo`.

### Configuration Files

The connection and environment settings are in `app/config/settings.php`, which `init`
writes to read its values from `.env`:

```php
<?php
loadDotenv(dirname(__DIR__, 2));

return [
    'database' => [
        'type'     => envvar('APP_DB_TYPE', 'postgresql'),
        'hostname' => envvar('APP_DB_HOST', 'localhost'),
        'database' => envvar('APP_DB_NAME', 'app'),
        'user'     => envvar('APP_DB_USER', 'app'),
        'password' => (string) envvar('APP_DB_PASSWORD', ''),
        'prefix'   => (string) envvar('APP_DB_PREFIX', ''),
    ],
    'dbsettings'  => true,
    'language'    => 'en',
    'development' => false,
    'forcessl'    => false,
];
```

The database keys are `hostname` and `user` — `Database` reads those names.


### Runtime settings — the `settings` table

Configuration files are read-only and deployed. The `settings` table is the other half: a
key/value store an administrator writes at runtime, read through
`Pramnos\Application\Settings`.

```php
use Pramnos\Application\Settings;

$from = Settings::getSetting('mail_from', 'noreply@example.com');

Settings::setSetting('mail_from', 'hello@example.com');
```

`setSetting()` writes one statement — an upsert onto `settings.setting` — so two
administrators saving the same key in the same second get two saves, not a duplicate-key
error on whoever was second. That requires the unique index the framework migration
`2026_05_26_000051` adds:

```sql
CREATE UNIQUE INDEX uq_settings_name ON settings (setting);
```

**Check that your installation has it.** The migration *declines* on a table that already
holds two rows for one name — deleting somebody's configuration is not a migration's
decision — so an installation can be fully migrated and still be without the constraint:

```sql
SELECT setting, COUNT(*) FROM settings GROUP BY setting HAVING COUNT(*) > 1;
```

Resolve whatever that returns, then re-run migrations. Until it is empty, `setSetting()`
detects the missing index and falls back to a read-then-write, which is correct for one
writer and races for two.

The answer is read from the catalogue **once per process**, because a settings form calls
`setSetting()` once per field. A long-lived worker that was running when the migration
applied keeps the old answer until it restarts, or until something calls
`Settings::forgetSchemaFacts()`.

**A long-lived process sees a change with `Settings::refresh()`.** Values are read from the
table once and kept, which is right for a request and wrong for a process that runs for
hours: an administrator saves a new API key, and the worker goes on using the old one.
`refresh()` drops what was read from the database — the settings file's own keys stay — so
the next `getSetting()` reads the table again. The queue worker calls it before every task it
claims, never while idle; a daemon of your own does the same at the top of each unit of work.

!!! warning "Encrypted values"
    The settings listed in `Settings::ENCRYPTED_SETTINGS` — `smtp_pass` and
    `database_readonly_dsn` — are encrypted with `APP_KEY` before they are written. Without a
    key, `smtp_pass` is stored in clear and converts itself on the next save once
    `key:generate` has run (an unreadable mail password is a worse outcome than a readable
    one), while `database_readonly_dsn` is refused. See the
    [Security guide](Pramnos_Security_Guide.md#settings-that-are-encrypted-automatically).


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

### Reading and writing a session value

```php
$session = \Pramnos\Http\Session::getInstance();

$session->set('checkout_step', 2);
$session->get('checkout_step');     // 2, or null when absent
$session->has('checkout_step');     // true — and true for a stored null
$session->remove('checkout_step');
```

`set()` calls `ensureStarted()` first, because in lazy mode a write can be the first thing
that needs a session at all — without it the value would be assigned to an array that is never
persisted and read back as null on the next request. `has()` and `remove()` deliberately start
nothing: asking whether something is stored must not hand a session cookie to a visitor with no
state, and there is nothing to remove from a session that does not exist.

`has()` is not `get($key) !== null`. It distinguishes a stored `null` from an absent key, which
is the question a caller actually has when deciding whether to compute a value again.

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

## Telling a crawler from a reader — `BotDetector`

```php
$detector = new \Pramnos\Http\Middleware\BotDetector();

if ($detector->isBot($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    $name = $detector->botName($_SERVER['HTTP_USER_AGENT'] ?? '');
}
```

Out of the box it carries about thirty-five patterns, one of which is a generic `bot` — so
it catches more than that count suggests: SemrushBot, AhrefsBot, PetalBot, Bytespider and
headless Chrome are all among them.

**What it misses are the crawlers that do not say "bot"**: `python-requests`,
`Go-http-client`, `okhttp`, Scrapy, Zabbix, link unfurlers, `Google-InspectionTool`. Those
are also the ones most likely to be hammering a site.

That is enough for what it was built for — keeping crawlers out of the `sessions` table —
and not enough to **count** anything. A counter that lets them through reports crawlers as
readers, and the symptom is a number slightly too high, which nobody can see is wrong.

### Widening it

```bash
composer require jaybizzle/crawler-detect
```

One compiled regular expression over about 1,500 crawlers, and **maintained** — which is the
part a hand-kept list cannot be, because a new crawler appears every week and the day the
list stops covering them is a day nothing reports. `isBot()` uses it when it is there.

A `suggest` rather than a `require`, for the same reason as `matomo/device-detector` beside
it: an application that never counts a visit should not carry a crawler list. **Without it
the class answers from its own patterns alone.**

`botName()` consults the framework's own patterns **first**, because they carry a label
somebody wrote — `Googlebot` — while the library can only report which fragment of the agent
string it matched on. The library is the wider net, not the better label.

## Declining session tracking

`SessionTrackingMiddleware` records visitors in the `sessions` table. It runs
automatically unless the application **names** it in `middleware`. To switch it off, say
so:

```php
// app/app.php
return [
    'session_tracking' => false,
    ...
];
```

Omission is not refusal: leaving the middleware out of `middleware` does not stop it,
because the framework registers it itself. Only this key does.

Accepts the spellings a config file actually contains: `false`, `0`, `'0'`,
`'false'`, `'no'`, `'off'` and `''` all decline. `true`, `1`, `'yes'`, `'on'` leave
it on, as does omitting the key.

**It applies however the middleware got there.** The setting stops the tracker both
when the framework registers it for you and when the application lists it in
`middleware` and runs it through the pipeline. The second is the case worth stating,
because it is the one an application reaches by wiring the tracker on purpose and
later wanting it off — and because the request still travels the rest of the
pipeline either way: declining to track is not declining to serve the page.

### What the row is, and the two things that keep it cheap

One row per **visitor**, keyed on `visitorid` — not one per request. The table is a live-visitor list
rather than a record, so it is written by an upsert and swept rather than accumulated.

The upsert is hand-written per dialect: `ON DUPLICATE KEY UPDATE` on MySQL and
`ON CONFLICT (visitorid) DO UPDATE … RETURNING logout` on PostgreSQL. The `RETURNING` is a feature, not
a flourish — **the upsert deliberately does not touch `logout`**, and reads back what it was. That flag
is how «an administrator ended this session» reaches the visitor: somebody else sets it in the table,
and the visitor's next request finds it and is signed out. An upsert that wrote every column, `logout`
included, would clear the instruction before it was ever read, and the eject button would silently do
nothing.

**A failure to write the row is logged and nothing else.** It is a tracking write: the visit is
not recorded and the request carries on, and nobody is signed out because a tracking write
failed. A revoked session still takes effect through the `logout` flag above, on the next
request that reaches the database. `url` and `agent` are `text`, because a URL such as an
OAuth callback carrying several scopes is easily longer than 255 characters.

Two settings keep the cost down, and both trade promptness for writes:

- **`session_write_interval`** (60s) — nothing is written twice in the same minute unless the visitor
  *navigated* somewhere new. A page that loads and then calls its own API would otherwise write the row
  twice a second apart, and a page making ten XHR calls ten times. Only a navigation records a URL: an
  XHR is the page talking to the server, and because it runs after the page that made it, recording its
  URL would leave the row permanently showing `/users/data` for somebody looking at `/users`.
- **`session_gc_divisor`** (100) — one request in N sweeps rows older than five minutes, rather than
  every request issuing the `DELETE`. Rows are allowed to be five minutes stale, so how promptly they
  go does not matter to anything that reads them.

The cost of both is that a visitor drops off the list up to a minute late, and a forced logout takes up
to a minute to be noticed. Neither is a promise anything makes.

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

The theme's layout shows them, above every screen:

```php
<!-- app/themes/<name>/theme.html.php -->
<main id="main-content">
    <?php echo \Pramnos\Html\Flash::render(); ?>
    [MODULE]
</main>
```

`Html\Flash::render()` draws errors then messages, escaped, as `pf-flash-error`
(`role="alert"`) and `pf-flash-message` (`role="status"`) inside a `pf-flash` block, and draws
nothing when there is nothing. Every scaffolded layout has the line and every scaffolded
stylesheet styles the hooks.

It **takes** the flash, and so does a view that shows it itself: `_printMessages()`,
`_printErrors()`, or `Request::takeMessages()` / `takeFlashErrors()`. The view renders before
the layout, so a screen that shows the flash in its own place shows it there, and the layout
repeats nothing. It also takes what the current request added without redirecting, which is
then shown on this page rather than on the next one.

A view that reads the flash without taking it uses the snapshot the view was built with:

```php
foreach ($this->messages as $message) { /* … */ }      // not taken: the layout shows it too
foreach ($this->flashErrors as $error) { /* … */ }
```

Outside a view, read them from the request: `$request->messages()` and
`$request->flashErrors()` read; `takeMessages()` and `takeFlashErrors()` read and take.

!!! warning "Do not pass messages as query parameters"
    `redirect(sURL . 'things?error=not_found')` is the shape to avoid. The text ends up in the
    URL, so it is shown again on every reload, it stays in browser history, it is in whatever
    the user pastes when asking for help, and it is user-controlled input arriving at a page
    that displays it.

!!! note "If your application reads the flash through `Base`"
    All four members work, and they are a supported way to do it — they are `protected`
    on `Base`, so every controller, model and theme inherits them:

    | Member | Consumes the flash? | Typical use |
    | --- | --- | --- |
    | `hasErrors()` / `hasMessages()` | **no** | the gate: `if ($this->hasErrors())` |
    | `_printErrors()` / `_printMessages()` | yes | renders `<span>`s |
    | `_getErrors()` / `_getMessages()` | yes | the raw array, e.g. for a JSON response |

    All four consult the same per-request capture, because `View::__construct()` drains
    `$_SESSION` into it. If they read the session directly, a gate would answer `false` for a
    flash that arrived perfectly well and **the printer behind it would never be reached** —
    silently: nothing fails, the page simply says nothing.

    The gates are non-destructive on purpose: a gate that consumed would leave the printer with
    nothing, which is the same silence by the opposite route.

    One residual: a `View` snapshots the bags when it is constructed, so a template that prints
    `$this->messages` and a layout that prints the flash both print it. A template that takes
    it, with `_printMessages()` or `takeMessages()`, leaves the layout nothing to repeat.

### Three things to know about the mechanism

- **`$this->messages` is not `$this->errors`.** `errors` is the per-field output of a validator
  (`['email' => ['Not an email address.']]`); `messages` and `flashErrors` are whole sentences.
  A template that iterates one expecting the other gets field names where it wanted text.
- **Reading consumes.** The session entry is cleared by the first read of the request, and the
  values stay available for the rest of it — so a controller and a template can both read them
  without one silently eating the other's.
- **It needs a session.** With none, `addMessage()` keeps the value on the object for the
  current request only, which is what a CLI or API context wants anyway.
- **A second request in the same process needs `Request::resetInstance()`.** The capture is
  per request, so serving two without resetting hands the second the first one's — already
  consumed — bag. One process, one request never sees this; a worker, a daemon or a test
  making two requests does, and what it sees is a flash mechanism that worked once and then
  went silent. `resetInstance()` clears the capture along with the rest of the derived state.

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
the database connection path. On that last path the factory is self-defeating:
building an application builds `Settings`, which queries the database through the very
connection still being opened. `ConnectionPathPurityTest` fails the build if a connection
path reaches the factory.

**Four directories are guarded the same way.** Nothing under `src/Pramnos/Auth/`,
`src/Pramnos/Http/Middleware/`, `src/Pramnos/User/` or `src/Pramnos/Database/` may
call `Application::getInstance()`; `ApplicationFactoryPurityTest` reads the source
and fails the build if it does, with an enumerated exemption list that is
currently empty. These are the places where the factory is a hazard: asking *who is signed
in* (`User::getCurrentUser()`) must not construct an application, and reporting a database
error must not query the database that just failed.

A tell-tale of the mistake is a guard such as `if ($app)` or `if (!is_object($app))` after a
`getInstance()` call: the factory never returns null, so the guard is dead and the
construction is live.

One practical consequence when you convert a call site: `currentInstance()`
declares `?Application` while `getInstance()` declares nothing, so any test that
installs a plain `stdClass` in the registry as a fake application starts failing
with a `TypeError`. That is the correct type being enforced — the registry is
meant to hold applications. Use a real subclass with an empty constructor.

If you genuinely need to *build* an application from guarded code, add the file to
`ApplicationFactoryPurityTest::EXEMPT` with the reason. Having to write the reason
down is the point.

### Where the factory is the right call

- **Console bootstraps** — `Console\Application`, `TimescaleDrain`, `TimescaleEnsure`,
  `PolicyEngine`, `BroadcastServe`, `BaseTestCase`, and the two bootstrap scripts the
  scaffolder generates. Building an application is what these are *for*.
- **Constructor fallbacks** in `Controller` and `Theme`: `__construct($application =
  null)` resolving the current one when none was passed. In a request both calls answer
  identically, and `currentInstance()` would put **null** into `$this->application` for a
  controller built by hand, which unit tests rely on not happening.

A few other callers outside the guarded directories also use the factory —
`Http\SiteUrl`, `Llm\Analyst\Analyst`, `Validation\FormRequest` and
`Framework\Testing\TestEnvironment`. In a request there is always an application, so they
behave as `currentInstance()` would; called before one exists, they construct it.

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

### Global Middleware

For the MVC application, list it in `app/app.php` — class names, or instances when a
constructor needs arguments:

```php
'middleware' => [
    \Pramnos\Http\Middleware\MaintenanceModeMiddleware::class,
    new \Pramnos\Http\Middleware\CorsMiddleware(
        allowedOrigins: ['https://app.example.com'],
        allowCredentials: true
    ),
],
```

The scaffolded front controller feeds `Application::getMiddleware()` into a
`MiddlewarePipeline` around the dispatch.
An application that dispatches through a `Router` of its own registers on that router:

```php
$router->addGlobalMiddleware(new MaintenanceModeMiddleware());
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

### A middleware can decide what the request *is*

A middleware is not limited to what happens around the dispatch. It can change the
route, and the application will dispatch what it decided:

```php
class HostRoutingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        if (($_SERVER['HTTP_HOST'] ?? '') === 'bio.example.com') {
            $request->setController('bio')->setAction('geekdom');
        }

        return $next($request);
    }
}
```

This is most of the reason to have a pipeline at all — a `Host` header, a locale
prefix, a maintenance switch, an A/B split: every one of them is "what is this
request" answered from something other than the path.

**How it works, and its one rule.** `init()` reads the route off the URL before the
pipeline runs, so `exec()` reads the request again at dispatch and takes the later
answer. The rule is that a route the application has assigned **by hand** still
wins:

| What happened between `init()` and `exec()` | What is dispatched |
|---|---|
| a middleware called `setController()` | the middleware's answer |
| nothing | the URL's, exactly as before |
| `$app->controller = 'dashboard';` | `dashboard` — an explicit statement outranks the URL |
| `$app->exec('reports')` | `reports` — the most explicit of all |

Set the action as well as the controller. A controller changed on its own leaves the
path's action behind, which is rarely the pair you meant.

### Built-in Middleware

| Class | Description |
|---|---|
| `AuthMiddleware` | Throws 401 or redirects if not logged in |
| `CorsMiddleware` | Sets `Access-Control-*` headers; handles OPTIONS preflight |
| `ThrottleMiddleware` | Rate-limits by IP using APCu (requires `apcu` extension) |
| `MaintenanceModeMiddleware` | Returns 503 while `var/MAINTENANCE` **or** `maintenance.flag` exists |
| `CsrfMiddleware` | Validates CSRF token on POST/PUT/PATCH/DELETE |

#### Maintenance mode: which flag, and what the client is told

There are several ways the site goes down on purpose:

| Raised by | Flag |
| --- | --- |
| `Application::startMaintenance()` | `<ROOT>/var/MAINTENANCE` |
| `MigrationRunner` (for the duration of a batch) | `<ROOT>/var/MAINTENANCE` |
| A deployment script doing `touch` | whatever it touches — conventionally `<ROOT>/maintenance.flag` |

With no constructor argument, `MaintenanceModeMiddleware` watches both `<ROOT>/var/MAINTENANCE`
and `<ROOT>/maintenance.flag`, so a migration's flag takes the site down for the middleware
too. Pass a path explicitly and that path is the only one watched, because an application
that named its own file has said which file it means.

#### Paths that still answer while it is up

The application's constructor answers the maintenance page and stops, before a route is
resolved — which is right for traffic and wrong for the person who raised the flag.
Maintenance is usually up *because* a schema is in flux, and that is exactly when somebody
needs to look at it. So a short list of paths boots anyway:

```php
'maintenance' => ['exempt' => ['adminer', 'devpanel']],
```

`['adminer']` by default. Naming the key **replaces** the default rather than adding to it,
so an installation that means "the panel as well" lists both.

**It grants one thing: that the application boots for that path.** Every route's own
authorisation still runs — for Adminer that is signed in plus `usertype >= 99`, or a
development environment plus the DevPanel's floor — so anybody else gets the 404 that route
gives everybody, and it is logged. Matching is segment-wise on the request path, so
`/adminerator` is not exempt and a subdirectory install needs no configuring.

Auto-migrations are held off by the flag regardless (`runAutoMigrations()` returns early
while it is up), so booting here cannot start the thing maintenance was raised to prevent.

#### Raising and clearing it by hand

```bash
php pramnos maintenance:on --reason="Adding an index to usertokens, ~20 minutes"
php pramnos maintenance:off
```

`maintenance:on` is for the case where you are about to do the work yourself — a
heavy migration, a data repair — and two things have to be true for that to be
usable:

- **Automatic migrations stand down.** `runAutoMigrations()` returns as soon as it
  sees the flag. Raising maintenance to run a migration by hand, and having the
  next request start migrating underneath you, is the opposite of what the flag is
  for. An explicit `migrate` is unaffected: it goes through `MigrationRunner`
  directly and clears only a flag it raised itself.
- **The console keeps working.** It is not traffic. `Application::__construct()`
  skips the maintenance page when `PRAMNOS_CONSOLE` is defined, which
  `Pramnos\Console\Application` does before it builds the application — keyed on
  that rather than on `PHP_SAPI`, because a cron worker or a deploy script booting
  an application under the CLI *should* still be held off a schema in flux, and it
  does not define it.

`maintenance:off` **takes down only a flag a person raised.** The flag records who
raised it, on its own line:

```
Maintenance started at: 05/09/2026 10:53. Reason: Adding an index
Origin: manual
```

| Origin | Written by | `maintenance:off` |
|---|---|---|
| `manual` | `maintenance:on` | clears it |
| `automatic` | `runMigration()`, `MigrationRunner` | refuses |
| `unknown` | a flag written before the origin was recorded | refuses |

The refusal is the point. A flag the framework raised means a schema is in flux
and something is either still running or died half way; clearing it because you
meant to clear your own is the mistake worth making impossible. `--force` is for
the second case, and says that it forced.

`unknown` is refused rather than assumed to be yours: there is no way to tell who
raised it, and the safe reading of "no way to tell" is "not yours".

The origin line is kept off the maintenance page — it is bookkeeping for whoever
may clear the flag, not something a visitor on a 503 has any use for.

#### The flag belongs to the process that raised it

`Application::runMigration()` raises the flag before `up()` and clears it in a
`finally`, so a migration that throws does not leave the site down behind it.
That matters more than it sounds: `Application::__construct()` refuses to build
an application at all while `var/MAINTENANCE` exists, so a flag left on disk puts
*every* subsequent request into maintenance mode — the web application, the API
front controllers, and the test bootstrap, which is how a whole suite starts
answering with a page instead of a test result.

It clears **only a flag it raised itself**. `startMaintenance()` returns early
when the file already exists, so it never overwrites one somebody else put up;
clearing unconditionally at the end would take the site *out* of a maintenance
window that a person entered on purpose.

#### What the page says

The reason passed to `startMaintenance()` is written into the flag, and the page
reads it back:

```php
$app->startMaintenance('Database migrations in progress');
```

`runMigration()` passes the migration's class and version, so the file names what
stopped the site. The reason is HTML-escaped before it reaches the page — it is
interpolated into the response, and one of its callers passes a class name
through.

**Under `DEVELOPMENT` the page also names the file to delete.** In production it
shows the reason alone: a public visitor on a 503 has no use for a path, while
the operator who needs it is the one running with `DEVELOPMENT` on, or reading the
log. Without a reason and without `DEVELOPMENT` the page carries only its title —
which is why passing a reason is worth the four words.

#### Nothing on this path may depend on `DS`

`Application::__construct()`'s **first act** is the flag check, and `DS` is
defined by `setDefines()` further down that same constructor. So every method the
maintenance path touches builds the flag's location through
`maintenanceFlagFile()`, which uses a literal `/`:

```php
protected function maintenanceFlagFile(): string
{
    return ROOT . '/var/MAINTENANCE';
}
```

Reaching for `DS` there is an uncaught `Error` on any entry point that has not
defined it — including a bare front controller, which is a shape the framework's
own scaffolding has produced:

```php
define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';
$app = new \MyApp\Application();       // nothing here defines DS
```

and nothing there should have to: a filesystem check cannot depend on constants
its own caller has not set yet. `DS` buys nothing in a path anyway — PHP's
filesystem functions accept `/` on every platform, Windows included.

This is worth knowing before adding to the path, because the flag goes up on its
own from `runMigration()` for the duration of every migration on a deploy. A
fault here turns a request arriving in that window into a PHP fatal instead of
the 503 with `Retry-After` this section is about. A test for it has to run in a
**subprocess** — the test suite's bootstrap defines `DS`, so nothing inside the
suite's own process can reproduce the condition.

`Retry-After` is an hour by default in the middleware, five minutes from
`Application::showError()`. Define `PRAMNOS_MAINTENANCE_RETRY_AFTER` to set both:

```php
define('PRAMNOS_MAINTENANCE_RETRY_AFTER', 900);   // 15 minutes
```

**What the client gets.** `Application::showError()` — which the constructor calls when
`var/MAINTENANCE` exists, so a router-dispatched application reaches it too — sends a
status and a content type:

| Situation | Status | Body |
| --- | --- | --- |
| Maintenance, browser | `503` + `Retry-After` | the HTML page, unchanged |
| Maintenance, `Accept: application/json` | `503` + `Retry-After` | `{"error":"maintenance","retry_after":300,…}` |
| Any other fatal (PHP version, addon, database) | `500` | as above, `"error":"unavailable"`, no retry |

Both matter: without the status a JSON client would get `200 OK` with a page of HTML and
fail on parsing rather than recognising the state, and a crawler would get the maintenance
page as a `200`, eligible to be indexed in place of the real page.

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
- `send(): static` — emits status code, headers, and body. A status already in effect — set with
  `http_response_code()` or a `header('HTTP/1.1 404 Not Found')` line — is not set again, since
  PHP 8.5 warns when `http_response_code()` follows such a line. `make($body)` still means 200:
  a front controller wrapping a page whose controller chose its own status passes it on,
  `Response::make($body, http_response_code() ?: 200)`.

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

Three things it guarantees:

- **At most `$length` characters, ellipsis included.** Size a column or a meta
  description with it and the number holds.
- **HTML is stripped before measuring**, so the length is a length of visible text — an
  excerpt of markup gives prose, not an unclosed `<span class="…">`.
- **A word longer than the limit is cut, not lost.** `Καθηγητήςμαθηματικών` at 10 gives
  `Καθηγητής…`, not the ellipsis on its own, which would read as missing text. A Greek
  compound, a name with no space, a URL and a hashtag are all that shape.

`null` is an empty string, not a `TypeError`. A negative length is an
`InvalidArgumentException`.

### `Helpers::shortenText()` is a deprecated alias

It forwards, so it has `excerpt()`'s guarantees: the result never exceeds `$length`, and a
single word longer than the limit is hard-cut.

Its default suffix argument, `&hellip;`, is mapped to the character `…`. It renders the same
in HTML and is also correct in a plain-text email, a JSON field, or anything that escapes the
result (where the entity would show as `&amp;hellip;`). It has to be one character because
the suffix counts against the length, and charging eight for an ellipsis leaves almost
nothing of a short excerpt. Any other suffix you pass is used and counted literally.

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
come back empty. The method returns a valid object either way, which is what makes this
hard to notice: statistics fill with browser names and no operating systems, and no code
reports an error.

`matomo/device-detector` is a **suggest**, not a requirement — a framework should not put
a user-agent parser into every project that installs it. Add it when you store or report
any of these fields:

```bash
composer require matomo/device-detector
```

Its regexes ship inside the package, so unlike `browscap/browscap-php` there is no data
file to provision and no monthly refresh: staleness becomes `composer update` rather than
a cron job, and there is no way for a missing download to degrade it silently.

A parse reads the package's regex files: about 60 ms for a desktop Chrome and 320 ms for a
phone or a Mac. So an agent is parsed **once a day**: the answer is kept in the process and in
the cache's `browsers` category, by agent, and a visitor — who keeps one agent — costs nothing
after the first page. A cache that cannot be reached means parsing again, not an error.

### What `detector` is for

Without it, an empty `version` could mean either *this agent is not identifiable* or *there
was no parser running*, and those call for opposite responses — the first is a fact about the
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
- **[Authorization Guide](Pramnos_Authorization_Guide.md)** — Gates, permissions, roles and access control
- **[Cache System Guide](Pramnos_Cache_Guide.md)** — Performance optimization
- **[Console Commands Guide](Pramnos_Console_Guide.md)** — CLI tools and generators
- **[Logging System Guide](Pramnos_Logging_Guide.md)** — Application monitoring
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** — Output formats
- **[Theme System Guide](Pramnos_Theme_Guide.md)** — UI theming and templates
- **[Email System Guide](Pramnos_Email_Guide.md)** — Email handling and notifications
- **[Media System Guide](Pramnos_Media_Guide.md)** — File uploads and media processing
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** — Multi-language support
