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

### Create

```php
// Using mass assignment
$user = User::create([
    'username' => 'john_doe',
    'email'    => 'john@example.com',
    'password' => hash('sha256', 'secret'),
]);

// Using new() and save()
$user = new User();
$user->username = 'jane_doe';
$user->email = 'jane@example.com';
$user->password = hash('sha256', 'secret');
$user->save();
```

### Read

```php
// Get by primary key
$user = User::find(42);

// Get first matching
$user = User::where('email', 'john@example.com')->first();

// Get all
$users = User::all();

// With conditions
$activeUsers = User::where('active', 1)->orderBy('username')->get();
```

### Update

```php
// Update via model instance
$user = User::find(42);
$user->email = 'newemail@example.com';
$user->save();

// Bulk update
User::where('active', 0)->update(['active' => 1]);

// Update with increment/decrement
$user->increment('login_count');
$user->decrement('credits', 5);
```

### Delete

```php
// Delete specific record
$user = User::find(42);
$user->delete();

// Bulk delete
User::where('active', 0)->delete();

// Force delete (bypasses soft deletes)
$user->forceDelete();
```

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
$user = User::find(42);
$posts = $user->posts();  // lazy load
$posts = $user->posts;    // eager load (via magic property)
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

$post = Post::find(1);
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
$user = User::find(42);
$roles = $user->roles;  // array of Role objects

// Attach a role
$user->roles()->attach(5);  // attach role 5
$user->roles()->sync([1, 2, 3]);  // sync to roles 1, 2, 3
```

### Relationship Eager Loading

```php
// Reduce N+1 queries
$users = User::with('posts', 'profile')->get();

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
class User extends Model
{
    use SoftDeletes;
    
    protected $dates = ['deleted_at'];
}

// Usage
$user->delete();  // sets deleted_at to now

// Query active records (excludes soft-deleted)
$users = User::active()->get();

// Include soft-deleted records
$users = User::withTrashed()->get();

// Only soft-deleted records
$users = User::onlyTrashed()->get();

// Force delete
$user->forceDelete();
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
class Post extends Model
{
    protected $table = 'posts';
    
    protected $fillable = ['title', 'content', 'published'];
    
    protected $casts = [
        'published' => 'boolean',
        'created_at' => 'timestamp',
    ];
    
    public function author()
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }
    
    public function scopePublished($query)
    {
        return $query->where('published', 1);
    }
}

// Usage
$recentPosts = Post::published()->latest('created_at')->limit(10)->get();

foreach ($recentPosts as $post) {
    echo $post->title . " by " . $post->author->username . "\n";
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
