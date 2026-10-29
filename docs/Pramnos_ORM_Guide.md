---
use_cases:
  - Defining a model, its relationships or its casts
  - Using scopes, accessors, mutators or model events
  - Enabling soft deletes or automatic timestamps
  - Scoping a model's rows to one tenant, account or organisation
  - Handling a failed save() — catching or checking why an insert or update didn't happen
---

# Pramnos ORM Guide

The **ORM** (Object-Relational Mapping) layer provides an elegant way to work with database tables as PHP objects. Models encapsulate table definitions, relationships, scopes, and casting logic.

**Base Class:** `Pramnos\Application\OrmModel`

## Getting Started

### Defining a Model

A controller reaches a model with `$this->getModel('User')`; which class that finds, and when
it does not, is in [How a controller finds its model](Pramnos_Framework_Guide.md#how-a-controller-finds-its-model).

```php
<?php
namespace App\Models;

use Pramnos\Application\OrmModel;

class User extends OrmModel
{
    // Table name. Left out, it is '#PREFIX#' . <class short name> . 's'.
    protected $_dbtable = 'users';

    // Primary key column
    protected $_primaryKey = 'userid';

    // Fillable attributes — fields that can be mass-assigned with fill()
    protected array $fillable = ['username', 'email', 'password'];

    // Type casting
    protected array $casts = [
        'active'     => 'boolean',
        'created_at' => 'datetime',
        'metadata'   => 'json',
    ];

    // Relationships
    public function posts()
    {
        return $this->hasMany(Post::class, 'userid', 'userid');
    }

    public function profile()
    {
        return $this->hasOne(Profile::class, 'userid', 'userid');
    }
}
```

!!! warning "Redeclared properties keep the parent's type"
    `OrmModel` declares `$fillable`, `$guarded` and `$casts` as `array`, `$timestamps` and
    `$softDelete` as `bool`, and the column-name switches as `string`. A subclass must
    redeclare them with the **same type** — `protected $fillable = [...]` without `array`
    is a fatal error when the class loads ("Type of User::$fillable must be array").
    `$_dbtable` and `$_primaryKey` come from `Model` and are untyped.

There is no `$hidden` property: nothing filters attributes out of `toArray()` or
`getData()` by name. A column that must not be published is left out of the endpoint's
field list, or removed in an overridden `getData()`.

## CRUD Operations

!!! warning "There is no static query API"
    `User::find(42)`, `User::where(…)`, `User::all()` and `User::create([...])` do **not**
    exist. `OrmModel` declares no `__callStatic` and none of its traits add one, so a method
    written from an Eloquent-shaped example dies with
    `Error: Call to undefined method App\Models\User::where()` — at runtime, in whatever
    path first reaches it.

Every operation goes through an **instance**. A model is a row-shaped object: you make one,
load it, change its properties, and save or delete it.

A model is constructed with the current controller — `new User($this)` inside a controller
action. Relations pass their parent's controller on to the models they create.

### What the base class gives you, and what `create:crud` adds

`OrmModel` itself has a public `save()` and protected `_load()`, `_delete()`, `_save()`.
The public `load($id)` and `delete($id)` used below are the thin wrappers a model declares
— `create:crud` generates them — forwarding to the protected methods:

```php
public function load($userid, $key = null, $debug = false)
{
    return parent::_load($userid, null, $key, $debug);
}

public function delete($userid)
{
    return parent::_delete($userid, null, null);
}
```

A model that overrides `save()` must keep it compatible with `OrmModel::save(): static`.
Timestamps, casts and events happen in `_save()` / `_delete()`, so an override calls them.

### Create

```php
$user = new \App\Models\User($this);
$user->username = 'jane_doe';
$user->email    = 'jane@example.com';
$user->save();

echo $user->userid;   // the key the insert produced
```

`save()` inserts when the object is new and updates when it was loaded.

### Read

```php
// One row, by primary key. The object is returned either way; check what came back.
$user = (new \App\Models\User($this))->load(42);

// Many rows, as models. The first argument is a WHERE clause without the keyword.
$active = (new \App\Models\User($this))->_getList(
    'active = 1',
    'username ASC'
);

// The paginated envelope an API endpoint answers with — filtering, sorting,
// searching and the total, from the request.
$page = (new \App\Models\User($this))->_getApiList();
```

The second argument is the order. Left out — `null` — the newest come first, by primary key;
an empty string `''` is no `ORDER BY` at all, the database's own order.

A paginated list runs two statements, a `COUNT` for the total and the page itself. A caller that
shows no total sets `$model->apiListCountTotal = false` first: the `COUNT` is not sent, the
envelope's `totalitems` is `null` and there is one page. Worth it where the filter is a
`LIKE '%term%'`, whose count reads the whole table. `User` has the same property.

`_getList()` returns models with every scope applied — a global scope registered with
`addGlobalScope()` is merged into the filter here, which is the reason to reach for it
rather than for the query builder.

!!! danger "The query builder does not know about your scopes"
    `queryBuilder()->table('users')->get()` is the right tool for a report, a join or an
    aggregate. It is the wrong one for reading a tenant's rows: it bypasses every global
    scope, so isolation added later silently does not apply. If you go around the model, say
    so in the query — an explicit `where('organization_id', …)` — rather than leaving the
    reader to notice what is missing.

### Update

```php
$user = (new \App\Models\User($this))->load(42);
$user->email = 'newemail@example.com';
$user->save();
```

An update writes only the columns whose value differs from what the model loaded, and a save
with nothing changed writes nothing. A column another process changed meanwhile keeps that
change: a background job saving a model's statistics and an administrator renaming the same
record both keep their change, whichever saves second. A different spelling of the same value
(`5` loaded, `'5'` posted) is not a change; any other difference is, including `0123` to `123`.

The whole row is written instead when the model is new, was never loaded, the save is forced
(`_save(..., $force = true)`), or the model declares `protected $_saveWholeRow = true;`. Use the
last for a model whose save is meant to put the whole row back as the model holds it.

This does not settle two writers of the **same** column: the one that saves last wins. Where that
matters, lock the row in a transaction (`$db->queryBuilder()->table(...)->lockForUpdate()`) before
reading and writing it.

There is no bulk update through the model. A statement that touches many rows is
query-builder work, and it is worth writing the scope back in by hand:

```php
$db->queryBuilder()->table('#PREFIX#users')
    ->where('organization_id', $tenantId)
    ->where('active', 0)
    ->update(['active' => 1]);
```

### Error handling

A failed `save()` — a constraint violation, a column that no longer exists, anything the
driver refuses — throws `\Pramnos\Database\QueryException`, whether the object was new
(an INSERT) or loaded (an UPDATE). `$model->sqlError` is also set, for code that reads it
instead of catching:

```php
try {
    $user->save();
} catch (\Pramnos\Database\QueryException $ex) {
    // $ex->getMessage() is safe to show; the driver detail is behind getDriverMessage().
}
```

If `save()` ran inside a transaction the caller started, a failure rolls it back before the
exception reaches the caller — the connection is usable again regardless of what the catch
block does next.

### Delete

```php
(new \App\Models\User($this))->delete(42);
```

With soft deletes enabled the row is stamped rather than removed; see
[Soft Deletes](#soft-deletes) for what that changes about reads.

### What about `find()`, `where()` and the rest?

They do not exist. A static entry point forwarding to a query object would make a global
scope pleasant to use; without one, the instance methods above are the whole of the read
surface, and reaching for the query builder instead is exactly where scopes stop applying.

## Relationships

### One-to-Many (hasMany)

```php
class User extends OrmModel
{
    public function posts()
    {
        return $this->hasMany(Post::class, 'userid', 'userid');
    }
}

// Usage
$user  = (new User($this))->load(42);
$posts = $user->posts();  // the relation object
$posts = $user->posts;    // the rows, as an Orm\Collection, via the magic property
```

### One-to-One (hasOne)

```php
class User extends OrmModel
{
    public function profile()
    {
        return $this->hasOne(Profile::class, 'userid', 'userid');
    }
}

$profile = $user->profile;  // single profile or null
```

### Belongs-To (inverse of hasMany/hasOne)

```php
class Post extends OrmModel
{
    public function author()
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }
}

$post = (new Post($this))->load(1);
$user = $post->author;  // the user who authored this post
```

### Many-to-Many (through pivot table)

```php
class User extends OrmModel
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'userid', 'roleid');
    }
}

// Usage
$user  = (new User($this))->load(42);
$roles = $user->roles;  // Orm\Collection of Role models
```

The relation reads through the pivot table; it does not write to it. There is no
`attach()` / `sync()` / `detach()` — maintain pivot rows with the query builder:

```php
$db->queryBuilder()->table('user_roles')->insert(['userid' => 42, 'roleid' => 5]);
```

Relations return a `Pramnos\Application\Orm\Collection`, which is countable, iterable and
JSON-serialisable.

### Relationship Eager Loading

```php
// Reduce N+1 queries. `with()` takes one name or an array of names, and returns the
// model, so the read that follows is the usual one.
$users = (new User($this))->with(['posts', 'profile'])->_getList();

foreach ($users as $user) {
    echo count($user->posts);  // no additional queries
}
```

Pass several relations as an **array**: `with('posts', 'profile')` loads only `posts`.

## Scopes

A scope is a reusable piece of WHERE logic. Scopes work on **filter strings**, not
on a fluent query object: a scope method receives the filter built so far and
returns it with its own condition appended.

### Local scopes

Define `scopeXxx(string $filter, ...$args): string` on the model and reach it with
`applyScope()`. `appendCondition()` does the ANDing and the parenthesising:

```php
class Post extends OrmModel
{
    public function scopePublished(string $filter): string
    {
        return $this->appendCondition($filter, "status = 'published'");
    }

    public function scopeOlderThan(string $filter, int $days): string
    {
        return $this->appendCondition(
            $filter,
            "created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
    }
}

$posts = $post->applyScope('published')->_getList();
$stale = $post->applyScope('olderThan', 30)->_getList();
```

`applyScope()` queues the scope for the **next query only** and returns `$this`, so
calls chain.

### Global scopes

Registered once per model class and applied to every query that model makes:

```php
// currentTenantId() is your application's own lookup.
Post::addGlobalScope('tenant', fn(string $f): string =>
    ($f === '' ? '' : "({$f}) AND ") . 'tenant_id = ' . (int) currentTenantId()
);
```

Remove one permanently with `Post::removeGlobalScope('tenant')`, or skip it for a
single query with `$post->withoutGlobalScope('tenant')->_getList()`.

### Where scopes apply

Global scopes and the soft-delete filter apply to **every** list path a model has:
`_getList()`, `_getPaginated()`, the datatable row count, and `_getApiList()` — which
is what the REST endpoints, the generated CRUD controllers and the datatables call.

This matters most when a global scope is what separates one tenant's data from
another's, which is the use the example above shows. A scope that held on some paths
and not others would not be a partial feature; it would be a leak, and one that shows
up only on whichever screen happens to paginate.

A caller's own filter is **combined** with the scopes, never replaced by them, so an
endpoint that accepts a filter cannot be used to shed the tenant condition.

One limit, stated because it is invisible otherwise: a *local* scope queued with
`applyScope()` is consumed by the first query it reaches, so on a datatables request
it applies to the page and not to the total row count. Global scopes and soft deletes
are re-derived per call and are unaffected. Put tenant isolation in a global scope.

## Casting

Automatic type conversion for model attributes:

```php
protected array $casts = [
    'active'      => 'boolean',      // 0/1 ↔ true/false
    'created_at'  => 'datetime',     // string → DateTimeImmutable
    'metadata'    => 'json',         // JSON string ↔ array
    'login_count' => 'integer',
    'balance'     => 'float',
];
```

### The casts that exist

`$casts` takes a **string type**, and this is the whole list:

| Cast | Turns a stored value into |
| --- | --- |
| `int`, `integer` | `int` |
| `float`, `double` | `float` |
| `bool`, `boolean` | `bool` |
| `string` | `string` |
| `array`, `json` | an array, JSON-decoded when the value is a string |
| `datetime`, `date` | a `DateTimeImmutable` |
| `timestamp` | a Unix timestamp as `int` |

**There is no custom-cast interface.** A cast class name is not recognised, and — this is the
part worth knowing — it does not fail either: an unrecognised cast falls through to the
`default` arm and the value is returned **unchanged**. So `'address' => AddressCast::class`
looks like it works, and silently does nothing.

For a transformation the list above cannot express, use an
[accessor and mutator](#accessors-mutators) below — they are called for exactly this.

## Accessors & Mutators

Computed properties and automatic value transformation:

```php
class User extends OrmModel
{
    // Accessor (transform on read). Receives the raw stored value, if any.
    public function getFullNameAttribute($value = null)
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    // Mutator (transform on write). What it RETURNS is what gets stored.
    public function setPasswordAttribute($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}

// Usage
$user->full_name;            // calls getFullNameAttribute()
$user->password = 'secret';  // calls setPasswordAttribute(), stores its return value
```

The method name is `get`/`set` + the attribute in StudlyCase + `Attribute`:
`full_name` → `getFullNameAttribute`. A mutator that returns nothing stores `null`.

## Soft Deletes

Mark records as deleted without removing them from the database:

```php
class Post extends \Pramnos\Application\OrmModel
{
    // The trait is already on OrmModel; this is the switch that turns it on.
    protected bool $softDelete = true;

    // Optional — the default is `deleted_at`.
    protected string $deletedAtColumn = 'deleted_at';
}

// Usage
$post->delete($id);     // the generated delete(): sets deleted_at, not a hard DELETE
$post->softDelete();    // the same, on the loaded row
$post->restore();       // clears it
$post->trashed();       // true / false

// Reads exclude stamped rows automatically. Both of these are *instance* methods
// returning the model, so the read after them is the usual one.
$all     = (new Post($this))->withTrashed()->_getList();
$deleted = (new Post($this))->onlyTrashed()->_getList();
```

There is no `forceDelete()`. With `$softDelete` on, `_delete()` always stamps; a hard
delete of a soft-deletable table is query-builder work
(`$db->queryBuilder()->table('posts')->where('post_id', $id)->delete()`).

## Timestamps

Automatic tracking of creation and update times:

```php
class User extends OrmModel
{
    // Timestamps are enabled by default
    protected bool $timestamps = true;

    // Customize column names; '' disables that column
    protected string $createdAtColumn = 'created_at';
    protected string $updatedAtColumn = 'updated_at';
}
```

The model sets the created column on insert (unless it already holds a value) and the
updated column on every save. `$model->withoutTimestamps()->save()` skips both.

### `Model` and a NOT NULL date column

`Pramnos\Application\Model` — the base without `$timestamps` — writes every column it finds,
and a `NOT NULL` column the model has nothing to say about is written as `''`. Fine for a
string; impossible for a date: strict MySQL and PostgreSQL both refuse `''` as a datetime.

So a `NOT NULL` date, time or timestamp holding `null` is **omitted from the write**: on an
insert the column's own default fills it, and on an update the stored value stays. A table
declaring

```sql
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

therefore accepts a model that never sets `created_at`. Every other `NOT NULL` column still
coerces `null` to `''`, which existing models rely on.

Set the column and it is written as given — the omission applies only when there is nothing to
say about it.

## Model Events

Hook into model lifecycle events with `on()` per event, or `observe()` with an object
whose public methods are named after the events. `User` below is a stand-in for any
`OrmModel`-based class an application writes — not `\Pramnos\User\User`, the framework's
own class, which predates `OrmModel` and wires the same events in by hand; see
[`User` participates too](Pramnos_Change_Feed_Guide.md#user-participates-too) in the
Change Feed Guide for that one specifically:

```php
User::on('creating', function (User $model) {
    $model->uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
});

User::on('deleted', function (User $model) {
    \Pramnos\Logs\Logger::log("User {$model->userid} deleted");
});

// Or one object for several events:
User::observe(new UserObserver());   // UserObserver::creating(), ::updated(), …
```

Available events: `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`.

A listener on a "before" event (`creating`, `updating`, `deleting`) that returns `false`
cancels the operation. Listeners are registered per model class and live for the process;
`User::flushEventListeners()` removes them, which is what a test's `tearDown()` wants.

These are per-class hooks. For one stream of every model's saves and deletes, see the
[Change Feed Guide](Pramnos_Change_Feed_Guide.md).

## Complete Example

```php
// Define model
class Post extends \Pramnos\Application\OrmModel
{
    protected $_dbtable    = 'posts';
    protected $_primaryKey = 'post_id';

    protected array $casts = [
        'published'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }

    /**
     * A local scope takes the filter string and returns it with one more
     * condition on it — it is not a query-builder callback.
     */
    public function scopePublished(string $filter): string
    {
        return $this->appendCondition($filter, 'published = 1');
    }
}

// Usage, inside a controller action
$recentPosts = (new Post($this))
    ->applyScope('published')
    ->_getList(null, 'created_at DESC');

foreach (array_slice($recentPosts, 0, 10) as $post) {
    echo $post->title . ' by ' . $post->author->username . "\n";
}
```

## `getData()` answers columns, and only columns

```php
$post->getData();   // ['post_id' => 42, 'body' => '…', 'scheduled_at' => null]
```

Everything this layer declares — `fillable`, `guarded`, `casts`, `timestamps`,
`softDelete`, `loadedRelations`, `eagerLoad` and the rest — is machinery and is filtered
out. The list is **derived** from the class rather than written out: `OrmModel` declares no
columns of its own, so every instance property it or its traits declare is machinery by
construction, and a trait that gains one is covered the day it does.

A model that adds its own switch excludes it the same way:

```php
protected static function internalProperties(): array
{
    return parent::internalProperties() + ['myOwnSwitch' => true];
}
```

A constant would not do: a subclass redefining one shadows the parent's and loses
everything in it.

!!! warning "This is why it is filtered rather than allowlisted"

    Filtering against the base model's list alone would not know this class, so every
    endpoint answering an ORM model would publish its bookkeeping before the first real
    column.

    Two of those keys are worth more than the noise. **`fillable` and `guarded` are the write
    allowlist**: publishing them tells a caller exactly which fields an endpoint will accept
    from a request body, which is otherwise something to guess at.

    The alternative shape — a `$columns` allowlist per model — would be worse here. Several
    models declare no column list at all, so filtering *out* the internals is the shape that
    works for all of them.

## Scoping a model to a tenant — refuse the row *and* empty the model

A model that must never hand out another organisation's row does it by overriding `load()`:

```php
public function load($id, $key = null, $debug = false)
{
    parent::_load($id, null, $key, $debug);

    if ((int) $this->organization_id !== $this->currentOrganizationId()) {
        return $this->forget();     // ← both halves of the refusal
    }

    return $this;
}
```

**`forget()` is not optional here.** `_load()` has already written every column onto
`$this` by the time the override gets to look, so a refusal that only changes the return
value leaves the object holding the row it just refused:

```php
$model = new Thing($this);
$model->load((int) $id);           // return value discarded
if ($model->thing_id == 0) { … }   // populated, so this passes
$model->getData();                 // somebody else's row
```

An isolation test does not catch this on its own: a test naturally writes
`$found = (new Thing($this))->load($id)` and uses the return value, which is correct — the
leak is in callers that discard it.

`forget()` restores every column the load wrote to what a fresh instance has, empties
`_initialData` and marks the model new, so a later `save()` would insert rather than update
the row that was refused. It clears **only** the columns that load read — not a list the
model declares, because a hand-written list is right until somebody adds a column and then
leaks exactly that one.

`create:crud` generates `$model = (new Thing($this))->load($id);`, so both halves hold
whichever way the caller is written. Use the return value in your own code for the same
reason.

## Reference

**Related Guides:**
- [Pramnos_Database_API_Guide.md](Pramnos_Database_API_Guide.md) — QueryBuilder and low-level database operations
- [Pramnos_Migration_Guide.md](Pramnos_Migration_Guide.md) — Schema versioning
- [Pramnos_Console_Guide.md](Pramnos_Console_Guide.md) — Model and CRUD generation wizard
- [Pramnos_Testing_Guide.md](Pramnos_Testing_Guide.md) — model factories (`OrmModel::factory()`) and seeders
- [Pramnos_Change_Feed_Guide.md](Pramnos_Change_Feed_Guide.md) — one event stream for every model's saves and deletes

**Topics covered:**
- Model definition and the instance CRUD surface
- Relationship types (hasMany, hasOne, belongsTo, belongsToMany)
- Query scopes and eager loading
- Soft deletes and timestamp handling
- Model events
