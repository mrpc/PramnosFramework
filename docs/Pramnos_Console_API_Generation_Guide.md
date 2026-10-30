---
use_cases:
  - Generating a REST API for a model
  - Generating the front-end of a SPA project
  - Keeping generated API and front-end code in step
---

# Generating APIs and front-ends

Part of the [console guide](Pramnos_Console_Guide.md).

## Front-End Generation (SPA projects)

The counterparts of `create:view` and `create:service`, for the Svelte stack.

### `create:screen`

```bash
php bin/pramnos create:screen Invoices --table=invoices   # a CRUD screen
php bin/pramnos create:screen Dashboard --blank           # no list
php bin/pramnos create:screen Invoices --resource=invoice  # over an endpoint
```

With `--table` it produces exactly what `create:crud` produces for its
front-end half, and nothing else: no model, no API controller, no routes. That
is the case where the API already exists — hand-written, or somebody else's —
and only the screen is missing.

`--blank` writes a screen with no list, for a dashboard, a report or a settings
page. The generated CRUD screen is a poor starting point for those: two thirds
of it is list plumbing to delete, and what remains imports components it no
longer uses.

Every screen registers itself in `screens/registry.js`, so it is reachable
without editing `App.svelte`. A screen the registry does not name is a file the
bundler does not even include.

`create:screen` is to the SPA what `create:view` is to MVC: the way to add a
screen without generating a CRUD for a table you do not want.

### `create:component`

```bash
php bin/pramnos create:component StatusBadge
```

Writes **two** files: `components/StatusBadge.svelte` and
`__tests__/StatusBadge.test.js`. The test is the point of the command rather
than a nicety — `create:service` writes a test stub, which is why services in a
scaffolded project have tests, and the front end had no such command, which is
why components did not.

The name is used as you typed it (`sales-report` becomes `SalesReport`); it is
not singularised or pluralised, because a component is not a database table.

### What `create:crud` generates for a SPA

The screen is a peer of the generated API controller, and every control matches
the column's **type**, read from the same introspection the MVC generator uses:

| Column | Control |
|---|---|
| `boolean` / `tinyint(1)` | checkbox |
| `text` / `longtext` / `json` | textarea |
| `date` | `<input type="date">` |
| `datetime` / `timestamp` | `<input type="datetime-local">`, converted to the space form the database wants |
| `integer` / `decimal` / `float` | `<input type="number">`, with `step="any"` where fractions are allowed |
| a foreign key | a searchable picker against the referenced resource's own list endpoint |

The `COLUMN COMMENT` becomes the field's label, `NOT NULL` becomes `required`,
and a blank nullable field is saved as `null` rather than `''` — they compare,
sort and `COALESCE` differently, and a form that cannot express the difference
converts every unset optional column to `''` on the first save.

Columns in the generator's exclusion list — `password`, `salt`, `apikey`,
`token`, the model-maintained timestamps — are never offered.

The list sorts, searches and pages **on the server**, and its state lives in the
URL: a link to "page 3, sorted by listeners" is a link somebody can send, and a
background re-read leaves the reader where they were.

### The shared components

The shared components are written once per project and **never overwritten**
afterwards. A generated screen imports the first five; `Omnibox.svelte` and
`HelpPanel.svelte` are used by the application shell:

| File | What it is |
|---|---|
| `components/DataTable.svelte` | Table + card layouts from one column definition; consumes `ApiListResponse::paginated()` |
| `components/Pagination.svelte` | Numbered, windowed, keyboard-reachable |
| `components/ConfirmDialog.svelte` | Focus trap, Escape, optional typed-phrase mode |
| `components/Field.svelte` | The control-per-type renderer described above |
| `lib/i18n.svelte.js` | `t()` / `tHtml()`, a client for the framework's own catalogue |
| `components/Omnibox.svelte` | Cross-entity search over `GET /admin/search` (the entities `app/search.php` registers) |
| `components/HelpPanel.svelte` | The screen's help page over `GET /help/screen`, shown with the `help` feature ([help in a SPA](Pramnos_Admin_Assistant_Guide.md#help-in-a-spa)) |
| `lib/ui.js` | The classes the shared components draw with, per UI system (`scaffold_theme`): daisyUI, Bootstrap 5 or plain-css |

With the `llm` feature enabled, the conversation components come with them:
`components/llm/Chat.svelte`, `components/llm/Scheduled.svelte`,
`components/llm/AiConsent.svelte`, `lib/llm/chat.js` and `lib/llm/markdown.js`, with
their tests under `__tests__/llm/` — and `package.json` gains the libraries they
render answers with (marked, DOMPurify).

Each ships with its test, into the project's `__tests__/` — where the project's
Vitest runner will actually run them. **The suite is green and the build is
warning-free on a project that has just been scaffolded**; anything else is a
framework bug worth reporting rather than something to fix locally.

Two details in those files look like slips and are not:

- `DataTable.svelte` initialises its search box with `$state(untrack(() => search))`.
  The box holds what the user is typing and an `$effect` keeps it in step with the
  prop, so the initialiser is a deliberate one-shot read — `untrack` is what says so,
  and without it Svelte reports `state_referenced_locally` on every build.
- `ConfirmDialog.test.js` filters its queries with `!el.disabled && el.tabIndex !== -1`
  before asserting on the focus trap. The dialog's backdrop is a `<button>` held out
  of the tab order on purpose, so an unfiltered `getAllByRole('button')` asserts
  against an element the trap is designed never to reach. The filter mirrors the
  component's own `focusable()`; if you change one, change both.

They stop being the framework's files the moment they exist, because the whole
value of shipping a `DataTable` is that a project extends it. So what was written
is recorded by hash in `app/shipped-files.json` — commit it — and a resync replaces
a component only while it is still exactly that:

```bash
php bin/pramnos project:resync --spa-components                # newer copies of untouched files
php bin/pramnos project:resync --spa-components --all          # …and add the ones not there yet
php bin/pramnos project:resync --spa-components --overwrite    # replace changed files too
```

A file the project changed is reported `kept (locally changed)`; one the framework
has no record of writing — a project from before the record, or the project's own
file at the same path — is `kept (not written by the framework)`. Both stay as they
are unless `--overwrite` is given. A file identical to the framework's is recorded
as it passes, so an older project gains its record on the first resync.

**What a synced file imports comes with it.** When a file the project has will, once synced,
import another file of the same sync that the project lacks, that file is created without
`--all`, and so is whatever it imports in turn. A newer `HelpPanel.svelte` imports `lib/ui.js`,
and updating the one without creating the other breaks the build ("Could not resolve
../lib/ui.js"). A file that nothing present imports is still only offered.

It is **not** included in a plain `project:resync`, for the same reason.

Two conditions decide whether it writes anything at all:

- **`app_style` must not be `mvc`.** That is the default, so a project that has never asked for a
  front end has no source directory to write into, and the resync will not decide it should have one.
  This holds even with `spa_stack => 'svelte'` configured: the stack being right does not create
  somewhere to put the files.
- **`spa_stack` must be `svelte`.** The shared components *are* Svelte. A project on a no-build
  stack has an SPA and nothing that compiles `.svelte`, so the files would sit there as text.

Where they land follows `spa_source_dir` when it is set, so a project whose front end lives in
`admin-ui/` receives them there rather than having to rename its directory to be helped.

When nothing is written, the command says `Nothing to sync` and explains why: that `app_style`
is `mvc`, or which directory it looked in for the front end (and whether it exists). A project on
another stack gets that same explanation, so if `--spa-components` appears to do nothing, check
`app_style` and `spa_stack` in `app/app.php`.

### Generated View Examples

#### List View (user.html.php)

The DataTable is built and configured in the controller (`display()`); the view
just renders the widget shell inside the theme wrapper. The columns and AJAX
data source live in the controller, so the view stays thin (this is the
plain-css theme; bootstrap/tailwind use their own wrappers):
```php
<div class="page-section">
    <?php echo \Pramnos\Application\Application::getInstance()->renderBreadcrumbs(); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Users</h2>
        <a href="<?php echo sURL; ?>User/edit/0" class="btn btn-primary">+ New User</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
```

#### Edit Form (edit.html.php)

The Bootstrap theme's form, for `username` and `email` (both NOT NULL). Every value is
escaped, NOT NULL columns get `required`, and the form carries the CSRF token field:

```php
<?php
defined('SP') or die('No startpoint defined...');
$isNew = ($this->model->userid ?? 0) <= 0;
?>
<div class="container py-4" style="max-width:640px">
    <?php echo \Pramnos\Application\Application::getInstance()->renderBreadcrumbs(); ?>
    <h2 class="mb-4"><?php echo $isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>User/save/<?php echo $this->model->userid; ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars((string)($this->model->username ?? '')); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="text" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars((string)($this->model->email ?? '')); ?>" required>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>User" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
```

The control follows the column type: a checkbox for `boolean`, a `<textarea>` for
`text` / `longtext`, `date` / `datetime-local` inputs for dates, `number` for numeric
columns, and a `<select>` for a foreign key.

## API Generation

### Creating API Endpoints

```bash
php bin/pramnos create:api User
php bin/pramnos create:api User --table=custom_users   # a table that does not follow the convention
```

The argument is the **entity** name: the controller is written as `src/Api/Controllers/User.php`
(class `User` in the `…\Api\Controllers` namespace), it works over the `User` model, and the table
is derived from the name (`users`) unless `--table` names it. The table must exist. An existing
controller is never overwritten.

The target directory is created if it is not there, parents included — a project adding its
first API controller has no `Api/` yet. If it cannot be created the command **fails with the
path**, rather than reporting a file it did not write — which would send people looking for a
bug in generated code that was never on disk.

This generates a complete REST API controller with:
- GET endpoints for listing and individual records
- POST endpoints for creating records
- PUT endpoints for updating records
- DELETE endpoints for removing records
- Automatic API documentation (PHPDoc format)

### Generated API Controller

Rendered from `scaffolding/templates/api-controller.stub`, for a `users` table with
`username` and `email` (NOT NULL) and a nullable `firstname`. The apiDoc blocks are
shortened here; the generated ones also list every field, the success shapes and the
error responses.

```php
<?php
namespace MyApp\Api\Controllers;

/**
 * User Controller
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\ApiCrudController
{

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
    }

    /**
     * @api {get} 1.0/user List
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName listUser
     * @apiDescription List of User objects with pagination, search, sorting and field selection
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     *
     * @apiParam  {Number} [page=0] Page number for pagination. Set to 0 to get all results
     * @apiParam  {Number} [limit=20] Limit number of results per page
     * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
     * @apiParam  {String} [search] Global search term, or a JSON object for field-specific search
     * @apiParam  {String} [fields] Fields to return (comma-separated or JSON array)
     */
    public function display()
    {
        // Authorisation lives in ApiCrudController::authorize(), so it is one
        // decision per action. Override authorize() to tighten it for this resource.
        if (($denied = $this->guard('list')) !== null) {
            return $denied;
        }

        $model = new \MyApp\Models\User($this);

        $fields = \Pramnos\Http\Request::staticGet('fields', array(), 'get');
        $search = \Pramnos\Http\Request::staticGet('search', '', 'get');
        $sort = \Pramnos\Http\Request::staticGet('sort', '', 'get');
        $page = (int) \Pramnos\Http\Request::staticGet('page', 0, 'get', 'int');
        $limit = (int) \Pramnos\Http\Request::staticGet('limit', 20, 'get', 'int');

        // useGetData: true, so a list returns the same shape as a single read.
        return $model->getApiList(
            $fields,
            $search,
            $sort,
            $page,
            $limit,
            false, // debug
            false, // returnAsModels
            true   // useGetData
        );
    }

    /**
     * @api {get} 1.0/user/:userid Read
     */
    public function readUser($userid)
    {
        if (($denied = $this->guard('read')) !== null) {
            return $denied;
        }

        // load() is used as an expression: a tenant-scoped model refuses a row by
        // returning a different instance, and only the return value carries that.
        $model = (new \MyApp\Models\User($this))->load($userid);
        if ($model->userid == 0) {
            return array(
                'status' => 404
            );
        }
        $data = $model->getData();
        return array('data' => $data);
    }

    /**
     * @api {post} 1.0/user Create
     * @apiBody {String} username Username
     * @apiBody {String} email Email address
     * @apiBody {String} [firstname] First name
     */
    public function createUser()
    {
        if (($denied = $this->guard('create')) !== null) {
            return $denied;
        }

        $model = new \MyApp\Models\User($this);

        $model->username = trim(strip_tags((string) \Pramnos\Http\Request::staticGet('username', '', 'post')));
        $model->email = trim(strip_tags((string) \Pramnos\Http\Request::staticGet('email', '', 'post')));
        $value = \Pramnos\Http\Request::staticGet('firstname', null, 'post');
        $model->firstname = $value === null ? null : trim(strip_tags((string) $value));

        $model->save();

        return array(
            'status' => 201,
            'data' => $model->getData()
        );
    }

    /**
     * @api {put} 1.0/user/:userid Update
     */
    public function updateUser($userid)
    {
        if (($denied = $this->guard('update')) !== null) {
            return $denied;
        }

        $model = (new \MyApp\Models\User($this))->load((int) $userid);
        if ($model->userid == 0) {
            return array(
                'status' => 404
            );
        }

        $value = \Pramnos\Http\Request::staticGet('username', $model->username, 'put');
        $model->username = $value === null ? null : trim(strip_tags((string) $value));
        $value = \Pramnos\Http\Request::staticGet('email', $model->email, 'put');
        $model->email = $value === null ? null : trim(strip_tags((string) $value));
        $value = \Pramnos\Http\Request::staticGet('firstname', $model->firstname, 'put');
        $model->firstname = $value === null ? null : trim(strip_tags((string) $value));

        $model->save();
        return array(
            'status' => 202,
            'data' => $model->getData()
        );
    }

    /**
     * @api {delete} 1.0/user/:userid Delete
     */
    public function deleteUser($userid)
    {
        if (($denied = $this->guard('delete')) !== null) {
            return $denied;
        }

        $model = (new \MyApp\Models\User($this))->load((int) $userid);
        if ($model->userid == 0) {
            return array(
                'status' => 404
            );
        }
        $model->delete($userid);
        return array(
            'status' => 202
        );
    }
}
```

**Authorisation.** Every action begins with `guard('<action>')`, inherited from
`ApiCrudController`. It answers `401` (`not_authenticated`) when there is no signed-in user
on the request — the user comes from the request's token, and user 1, the anonymous
account, does not count — and `403` (`forbidden`) when `authorize($action)` refuses.
`authorize()` asks the permission store for `list` / `read` / `create` / `update` /
`delete`: an explicit allow or deny decides, and no rule at all allows — except inside an
organisation (`OrganizationScope` set), where no rule is a refusal. Override `authorize()`
in the generated controller to tighten it, e.g.
`return parent::authorize($action) && $this->requestUser()->isAdmin();`.

**Field assignments** follow the column type: integers through `staticGet(…, 'int')`,
floats with `(float)`, booleans accept `true` / `on` / `yes` / `1`, strings are
`trim(strip_tags((string) …))`, and a nullable column keeps `null` instead of becoming `''`.
A nullable integer column sent as `0` on create is stored as `null`. A tenant column
(`organization_id` / `organizationid`) is never read from the request: create assigns
`$this->currentOrganizationId()`, and update leaves it alone.

### API Route Registration

`create:api` adds the entity's routes to `src/Api/routes.php`, **inside** the version group
(whose prefix comes from `APIVERSION`), so they answer at `/1.0/user`. A route already
registered for the resource is not added twice. Rendered from
`scaffolding/templates/api-routes.stub`:

```php
$r->delete(
    '/user/{userid}',
    function ($userid) {
        return (new \MyApp\Api\Controllers\User($this))->deleteUser($userid);
    }
);

$r->put(
    '/user/{userid}',
    function ($userid) {
        return (new \MyApp\Api\Controllers\User($this))->updateUser($userid);
    }
);

$r->get(
    '/user/{userid}',
    function ($userid) {
        return (new \MyApp\Api\Controllers\User($this))->readUser($userid);
    }
);

$r->get(
    '/user',
    function () {
        return (new \MyApp\Api\Controllers\User($this))->display();
    }
);

$r->post(
    '/user',
    function () {
        return (new \MyApp\Api\Controllers\User($this))->createUser();
    }
);
```

A hand-written `routes.php` with no version group gets the same block, using `$router`,
before its `return $router->dispatch($newRequest);` line.
