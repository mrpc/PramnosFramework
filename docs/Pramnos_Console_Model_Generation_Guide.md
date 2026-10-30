---
use_cases:
  - Generating a model from an existing table
  - Generating a controller, its views, or a whole CRUD
  - Choosing what the generators write and where
---

# Generating models, controllers and views

Part of the [console guide](Pramnos_Console_Guide.md).

## Model Generation

### Basic Model Creation

```bash
php bin/pramnos create:model User
```

This generates a model class with:
- Database table mapping
- Primary key configuration
- Basic CRUD methods
- Type-safe property declarations
- API list method with pagination

### Which column becomes the primary key

From the table, when there is a table to ask. `create:model` reads the key out of the schema, so a
legacy table keyed on `customer_id` gets a model that says `customer_id`. The naming convention
(singular table name plus `id`) would produce `customerid`, which is not a column: such a model
loads nothing and inserts a new row on every save, which presents as «the edit form does not
work». `create:api` reads the key the same way, so the two generators agree about a table.

The convention is still the answer in the two cases where the schema cannot be asked:

- the **migration-wizard path**, where the migration has been written and not yet run, so the table
  does not exist;
- a **composite primary key**, because `Pramnos\Application\Model` addresses a row by one column
  and there is no honest single answer — the convention at least produces a name a developer will
  recognise as needing an edit.

### Generated Model Structure

For a `users` table with `userid`, `username`, `email`, `created_at` and `updated_at`
(rendered from `scaffolding/templates/crud-model.stub`; docblocks shortened):

```php
<?php
namespace MyApp\Models;

/**
 * User Model
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\OrmModel
{

    /**
     * (Primary Key)
     * @var int
     */
    public $userid;
    /**
     * Username
     * @var string
     */
    public $username;
    /**
     * Email address
     * @var string
     */
    public $email;
    /**
     * @var string
     */
    public $created_at;
    /**
     * @var string
     */
    public $updated_at;

    /**
     * Primary key in database
     * @var string
     */
    protected $_primaryKey = "userid";

    /**
     * Database table
     * @var string
     */
    protected $_dbtable = "users";

    /**
     * Load from database
     * @return $this
     */
    public function load($userid, $key = null, $debug = false)
    {
        return parent::_load($userid, null, $key, $debug);
    }

    /**
     * Save to database
     * @return $this
     */
    public function save($autoGetValues = false, $debug = false): static
    {

        // `: static`, as OrmModel::save() declares: without it the class does not load.
        parent::_save(null, null, $autoGetValues, $debug);

        return $this;
    }

    /**
     * Delete from database
     * @return $this
     */
    public function delete($userid)
    {
        return parent::_delete($userid, null, null);
    }

    /**
     * Return all data as array
     * @return array
     */
    public function getData()
    {
        $data = parent::getData();

        return $data;
    }

    /**
     * Return an API-formatted list with pagination, field selection, and search
     * @return array
     */
    public function getApiList($fields = [], $search = '',
        $order = '', $page = 0, $itemsPerPage = 10,
        $debug = false, $returnAsModels = false, $useGetData = true)
    {
        return parent::_getApiList(
            $fields, $search, $order, '', '', '',
            null, null, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData
        );
    }
}
```

What varies with the table:

- **Column comments** become the property docblocks.
- **`getData()`** casts integer and float columns back to `int` / `float`, booleans to `bool`,
  and puts JSON columns back into the array — so the API returns typed values, not strings.
- **Timestamps.** A table with neither `created_at` nor `updated_at` gets
  `protected bool $timestamps = false;`; a table with only one of them gets the missing one's
  `$createdAtColumn` / `$updatedAtColumn` set to `''`.
- **`--schema`** adds `protected $_dbschema = '<schema>';`.
- **A foreign key declared `ON DELETE SET NULL`** makes `save()` turn a `0` in that column
  into `null` before saving (see [Foreign keys](Pramnos_Console_Writing_Commands_Guide.md#foreign-key-detection)).
- **`load()` docblock** carries the pattern for a tenant-scoped model: refuse the row *and*
  empty the model with `forget()`, because every generated controller uses the return value
  of `load()`.

### Model Generation Options

```bash
# Generate model for specific table
php bin/pramnos create:model User --table=custom_users

# Generate model with schema specification (PostgreSQL)
php bin/pramnos create:model User --schema=public
```

### Model Registry

Generated models are automatically registered in `app/model-registry.json`:

```json
[
    {
        "className": "User",
        "namespace": "MyApp\\Models",
        "fullClassName": "MyApp\\Models\\User",
        "table": "users",
        "schema": "",
        "createdAt": "2024-12-25T10:30:00+00:00",
        "updatedAt": "2024-12-25T10:30:00+00:00"
    }
]
```

## Controller Generation

`create:controller` generates a complete CRUD controller from the database table
schema — it has a single behaviour, with no skeleton mode.

```bash
php bin/pramnos create:controller User
```

The table for the entity must already exist (create it first with
`create:migration`); the generator introspects it. If the table is missing you
get a clear error pointing you to `create:migration`. An existing controller
file is never overwritten.

This generates a complete CRUD controller with:
- Display action (list view, a server-side DataTable)
- Show action (detail view)
- Edit action (create/update form)
- Save action (form processing)
- Delete action (record removal)
- `data()` — the JSON feed for the DataTable
- `fkOptions()` — the Select2 option feed, only when the table has foreign keys

and the list / edit / show views for the project's theme. `show`, `data` (and
`fkOptions`) are public; `edit`, `save` and `delete` require a login.

### Generated Controller Structure

Rendered from `scaffolding/templates/crud-controller.stub`, for a `users` table
with `username` and `email` (docblocks and comments shortened):

```php
<?php
namespace MyApp\Controllers;

/**
 * User Controller
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\Controller
{

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Every action must be registered or Controller::exec() falls back to display().
        $this->addaction(['show', 'data']);
        $this->addAuthAction(['edit', 'save', 'delete']);
        parent::__construct($application);
    }

    public function display(): string
    {
        $dt = new \Pramnos\Html\Datatable('dt-user');
        $dt->source = sURL . 'User/data';
        $dt->bootstrap = false;            // true when the app's scaffold_theme is bootstrap
        // A search box under each column, on top of the global one.
        $dt->footerTextSearch = true;
        $dt->addColumn('Username', true, true, true, '', '', true, 'left', true)
           ->addColumn('Email', true, true, true, '', '', true, 'left', true)
           ->addColumn('Actions', true, false, false, 'html');

        $view = $this->getView('user');
        $view->datatable = $dt;
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'User';
        $this->application->addbreadcrumb('User', sURL . 'User');
        return $view->display();
    }

    public function show(): string
    {
        $view    = $this->getView('user');
        $request = new \Pramnos\Http\Request();
        // load() is used as an expression: a tenant-scoped model refuses a row by
        // returning a different instance, and only the return value carries that.
        $model   = (new \MyApp\Models\User($this))->load($request->getOption());
        $view->addModel($model);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $model->username . ' | User';
        $this->application->addbreadcrumb('User', sURL . 'User');
        $this->application->addbreadcrumb((string)$model->username, sURL . 'User/show/' . $model->userid);
        return $view->display('show');
    }

    public function edit(): string
    {
        $view    = $this->getView('user');
        $request = new \Pramnos\Http\Request();
        $model   = (new \MyApp\Models\User($this))->load($request->getOption());
        $view->addModel($model);
        // (foreign-key option loading goes here — see "Select2 changes what a
        // foreign-key field loads" above)
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = ($model->userid > 0 ? 'Edit' : 'Create') . ' | User';
        $this->application->addbreadcrumb('User', sURL . 'User');
        if ($model->userid > 0) {
            $this->application->addbreadcrumb((string)$model->username, sURL . 'User/show/' . $model->userid);
            $this->application->addbreadcrumb('Edit', sURL . 'User/edit/' . $model->userid);
        } else {
            $this->application->addbreadcrumb('Create', sURL . 'User/edit/0');
        }
        return $view->display('edit');
    }

    public function save(): void
    {
        $request = new \Pramnos\Http\Request();
        $model   = (new \MyApp\Models\User($this))->load($request->getOption());
        $model->username = trim(strip_tags((string) $request->get('username', '', 'post')));
        $model->email = trim(strip_tags((string) $request->get('email', '', 'post')));
        $model->save();
        $this->redirect(sURL . 'User');
    }

    public function delete(): void
    {
        $model   = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->delete($request->getOption());
        $this->redirect(sURL . 'User');
    }

    public function data(): void
    {
        \Pramnos\Framework\Factory::getDocument('json');

        $fields = ['userid', 'username', 'email'];
        $result = \Pramnos\Html\Datatable\Datasource::getList('users', $fields, false);

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id      = (int) $row[0];
            $showUrl = sURL . 'User/show/' . $id;
            array_shift($row);                 // drop the primary key
            // The first visible column links to the record.
            if (isset($row[0]) && $row[0] !== null && $row[0] !== '') {
                $row[0] = '<a href="' . $showUrl . '">'
                    . htmlspecialchars((string) $row[0], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            // Labelled icon links: view, edit, delete (with a confirm).
            $row[] = \Pramnos\Html\Icon::link($showUrl, 'view', 'View this record')
                   . \Pramnos\Html\Icon::link(sURL . 'User/edit/' . $id, 'edit', 'Edit this record')
                   . \Pramnos\Html\Icon::link(
                       sURL . 'User/delete/' . $id,
                       'delete',
                       'Delete this record',
                       ['data-confirm' => 'Are you sure?', 'class' => 'pf-action-danger']
                   );
            unset($row['DT_RowId']);
        }
        unset($row);

        echo json_encode($result);
        $this->terminate();
    }

    /**
     * Terminate the request. Isolated so it can be overridden/mocked in tests.
     */
    protected function terminate(): void
    {
        exit;
    }

}
```

Field assignments in `save()` follow the column type: integers
`$request->get('col', '', 'post', 'int')`, floats `(float)`, booleans `(bool)`,
everything else `trim(strip_tags((string) …))`.

## View Generation

### Basic View Creation

```bash
php bin/pramnos create:view User
```

### Full CRUD Views

```bash
php bin/pramnos create:view User --full
```

`--full` introspects the entity's table and generates the complete admin-style,
per-theme (plain-css / bootstrap / tailwind) CRUD view set — the same output as
`create:crud`. The table must already exist (otherwise it errors, pointing you to
`create:migration`). Without `--full`, `create:view` writes a single, table-free
placeholder view instead.

The full set:
- `<view>.html.php` - List view (renders the controller's server-side DataTable)
- `edit.html.php` - Create/edit form
- `show.html.php` - Detail view

## CRUD Generation

### Complete CRUD System

```bash
php bin/pramnos create:crud User
php bin/pramnos create:crud User --target=spa    # mvc | spa | both
```

`--target` decides what is generated; without it the application's `app_style` decides
(`spa` → `spa`, `hybrid` → `both`, anything else → `mvc`). The model is always generated:

| Target | Generates |
|---|---|
| `mvc` | Model, web controller, and the list / edit / show views |
| `spa` | Model, API controller + routes, and the SPA screen — no server-rendered views |
| `both` | All of the above |

The output reports each step:

```
Creating Model: OK
Creating API controller + routes: OK      (spa, both)
Creating SPA screen: …                    (spa, both)
Creating Controller: OK                   (mvc, both)
Creating View: OK                         (mvc, both)
```
