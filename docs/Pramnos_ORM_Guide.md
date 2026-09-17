---
use_cases:
  - Defining a model, its relationships or its casts
  - Using scopes, accessors, mutators or model events
  - Enabling soft deletes or automatic timestamps
  - Scoping a model's rows to one tenant, account or organisation
---

# Pramnos ORM Guide

The **ORM** (Object-Relational Mapping) layer provides an elegant way to work with database tables as PHP objects. Models encapsulate table definitions, relationships, scopes, and casting logic.

**Base Class:** `Pramnos\Application\OrmModel`

## Getting Started

### Defining a Model

```php
<?php
namespace App\Models;

use Pramnos\Application\OrmModel;

class User extends Model
{
    // Optional: specify table name (default is plural of class name)
    protected static $table = 'users';
    
    // Fillable attributes — fields that can be mass-assigned
    protected $fillable = ['username', 'email', 'password'];
    
    // Hidden from serialization
    protected $hidden = ['password'];
    
    // Type casting
    protected $casts = [
        'active'     => 'boolean',
        'created_at' => 'timestamp',
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

## CRUD Operations

!!! warning "There is no static query API"
    `User::find(42)`, `User::where(…)`, `User::all()` and `User::create([...])` do **not**
    exist. `OrmModel` declares no `__callStatic` and none of its traits add one, so a method
    written from an Eloquent-shaped example dies with
    `Error: Call to undefined method App\Models\User::where()` — at runtime, in whatever
    path first reaches it.

    This page used to show exactly that. What follows is the surface the class has.

Every operation goes through an **instance**. A model is a row-shaped object: you make one,
load it, change its properties, and save or delete it.

### Create

```php
$user = new \App\Models\User();
$user->username = 'jane_doe';
$user->email    = 'jane@example.com';
$user->save();

echo $user->userid;   // the key the insert produced
```

`save()` inserts when the object is new and updates when it was loaded. `create:crud`
generates a `save()` that forwards to `_save()`, which is where the timestamps, the casts
and the events happen — so an application overriding it should call the parent.

### Read

```php
// One row, by primary key. The object is returned either way; check what came back.
$user = new \App\Models\User();
$user->load(42);

// Many rows, as models. The first argument is a WHERE clause without the keyword.
$active = (new \App\Models\User())->_getList(
    "`active` = 1",
    '`username` ASC'
);

// The paginated envelope an API endpoint answers with — filtering, sorting,
// searching and the total, from the request.
$page = (new \App\Models\User())->_getApiList();
```

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
$user = new \App\Models\User();
$user->load(42);
$user->email = 'newemail@example.com';
$user->save();
```

There is no bulk update through the model. A statement that touches many rows is
query-builder work, and it is worth writing the scope back in by hand:

```php
$db->queryBuilder()->table('#PREFIX#users')
    ->where('organization_id', $tenantId)
    ->where('active', 0)
    ->update(['active' => 1]);
```

### Delete

```php
$user = new \App\Models\User();
$user->delete(42);
```

With soft deletes enabled the row is stamped rather than removed; see
[Soft Deletes](#soft-deletes) for what that changes about reads.

### What about `find()`, `where()` and the rest?

They are a genuine gap rather than a deliberate omission: a static entry point forwarding to
a query object is what makes a global scope pleasant to use, and without one every model in
a project ends up reaching for the query builder — which is exactly where scopes stop
applying. Until it exists, the instance methods above are the whole of the read surface.

## Relationships

### One-to-Many (hasMany)

```php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class, 'userid', 'userid');
    }
}

// Usage
$user = new User();
$user->load(42);
$posts = $user->posts();  // the relation object
$posts = $user->posts;    // the rows, via the magic property
```

### One-to-One (hasOne)

```php
class User extends Model
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
class Post extends Model
{
    public function author()
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }
}

$post = new Post();
$post->load(1);
$user = $post->author;  // the user who authored this post
```

### Many-to-Many (through pivot table)

```php
class User extends Model
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'userid', 'roleid');
    }
}

// Usage
$user = new User();
$user->load(42);
$roles = $user->roles;  // array of Role objects

// Attach a role
$user->roles()->attach(5);  // attach role 5
$user->roles()->sync([1, 2, 3]);  // sync to roles 1, 2, 3
```

### Relationship Eager Loading

```php
// Reduce N+1 queries. `with()` is an instance method returning the model, so the
// read that follows is the usual one.
$users = (new User())->with('posts', 'profile')->_getList();

foreach ($users as $user) {
    echo count($user->posts);  // no additional queries
}
```

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
Post::addGlobalScope('tenant', fn(string $f): string =>
    ($f === '' ? '' : "({$f}) AND ") . 'tenant_id = ' . Auth::tenantId()
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
protected $casts = [
    'active'      => 'boolean',      // 0/1 ↔ true/false
    'created_at'  => 'timestamp',    // String → DateTime
    'metadata'    => 'json',         // JSON string ↔ array/object
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

Until 2026-08-14 this section documented `Pramnos\Database\Casts\Castable`, which has never
existed. For a transformation the list above cannot express, use an
[accessor and mutator](#accessors--mutators) below — they are called for exactly this, and
they are real.

## Accessors & Mutators

Computed properties and automatic value transformation:

```php
class User extends Model
{
    // Accessor (transform on read)
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
    
    // Mutator (transform on write)
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = hash('sha256', $value);
    }
}

// Usage
$user->full_name;  // calls getFullNameAttribute()
$user->password = 'secret';  // calls setPasswordAttribute()
```

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
$post->delete();        // sets deleted_at, not a hard DELETE
$post->restore();       // clears it
$post->trashed();       // true / false
$post->forceDelete();   // the real DELETE

// Reads exclude stamped rows automatically. Both of these are *instance* methods
// returning the model, so the read after them is the usual one.
$all     = (new Post())->withTrashed()->_getList();
$deleted = (new Post())->onlyTrashed()->_getList();
```

## Timestamps

Automatic tracking of creation and update times:

```php
class User extends Model
{
    // Timestamps are enabled by default
    public $timestamps = true;
    
    // Customize column names
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
}
```

The model automatically sets `created_at` on insert and `updated_at` on every change.

### A legacy `Model` leaves a NOT NULL date to the column

`Pramnos\Application\Model` — the older base, without `$timestamps` — writes every column it
finds, and a `NOT NULL` column the model has nothing to say about used to be written as `''`.
Fine for a string; impossible for a date.

So a table declaring

```sql
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

could not be inserted into by a model that never set `created_at`: strict MySQL and PostgreSQL
both refuse `''` as a datetime, and the intent — *use the column's own default* — became a
request for timestamp zero.

A `NOT NULL` date, time or timestamp holding `null` is now **omitted from the write** instead:
on an insert the default fills it, and on an update the stored value stays. Everything else
still coerces to `''`, because models have relied on that since long before this.

Set the column and it is written as given — the omission applies only when there is nothing to
say about it.

## Model Events

Hook into model lifecycle events:

```php
class User extends Model
{
    protected static function booting()
    {
        static::creating(function ($model) {
            $model->uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        });
        
        static::updating(function ($model) {
            $model->updated_by = auth()->id();
        });
        
        static::deleted(function ($model) {
            Log::info("User {$model->userid} deleted");
        });
    }
}
```

Available events: `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `restoring`, `restored`

## Complete Example

```php
// Define model
class Post extends \Pramnos\Application\OrmModel
{
    protected $table = 'posts';

    protected $casts = [
        'published'  => 'boolean',
        'created_at' => 'timestamp',
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
        return $this->appendCondition($filter, '`published` = 1');
    }
}

// Usage
$recentPosts = (new Post())
    ->applyScope('published')
    ->_getList(null, '`created_at` DESC');

foreach (array_slice($recentPosts, 0, 10) as $post) {
    echo $post->title . ' by ' . $post->author->username . "\n";
}
```

## Reference

**Related Guides:**
- [Pramnos_Database_API_Guide.md](Pramnos_Database_API_Guide.md) — QueryBuilder and low-level database operations
- [Pramnos_Migration_Guide.md](Pramnos_Migration_Guide.md) — Schema versioning
- [Pramnos_Console_Guide.md](Pramnos_Console_Guide.md) — Model and CRUD generation wizard

**Topics covered:**
- Complete Model API with all CRUD methods
- Relationship types (hasMany, hasOne, belongsTo)
- Query scopes and eager loading
- Soft deletes and timestamp handling
- Model factories and seeders
