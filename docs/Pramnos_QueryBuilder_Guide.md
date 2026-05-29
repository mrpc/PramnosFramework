# Pramnos QueryBuilder Guide

The **QueryBuilder** provides a fluent, dialect-aware interface for constructing SQL queries programmatically. It supports MySQL 8.0+, PostgreSQL 14+, and TimescaleDB, handling dialect differences automatically.

**Class:** `Pramnos\Database\QueryBuilder`  
**Entry point:** `$db->queryBuilder()` — returns a fresh builder bound to the current database connection.

## Getting Started

### Basic Patterns

```php
$db = \Pramnos\Database\Database::getInstance();

// SELECT with conditions
$activeUsers = $db->queryBuilder()
    ->from('users')
    ->where('active', 1)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

while ($activeUsers->fetch()) {
    echo $activeUsers->fields['username'] . "\n";
}

// INSERT
$db->queryBuilder()
    ->table('users')
    ->insert(['username' => 'jane', 'email' => 'jane@example.com']);

// UPDATE
$db->queryBuilder()
    ->table('users')
    ->where('userid', 5)
    ->update(['active' => 0]);

// DELETE
$db->queryBuilder()
    ->from('users')
    ->where('active', 0)
    ->delete();
```

## SELECT Queries

### Column Selection

#### `select(array|string $columns = ['*']): static`

Sets the SELECT column list. Accepts individual strings, comma-separated strings, or an array.

```php
// Select specific columns
$qb->select('userid', 'username', 'email');

// Array format with aliases
$qb->select(['u.userid', 'u.username', 'g.groupname']);

// SQL expressions
$qb->select('COUNT(*) as total');

// Raw expressions
$qb->select($qb->raw("TO_CHAR(created_at, 'YYYY-MM') as month"));
```

#### `distinct(): static`

Adds `DISTINCT` to the SELECT.

```php
$qb->select('country')->distinct()->from('users');
// → SELECT DISTINCT country FROM users
```

### Table & Aliasing

#### `from(string $table): static` / `table(string $table): static`

Sets the FROM table with optional alias.

```php
$qb->from('users');
$qb->from('users u');           // with alias
$qb->from('users AS u');        // explicit AS

// INSERT/UPDATE/DELETE prefer table()
$qb->table('users')->insert([...]);
```

### WHERE Conditions

#### `where(string $column, mixed $operator = null, mixed $value = null): static`

Adds a WHERE condition. Supports multiple calling patterns:

```php
// Two-argument: column = value (shorthand)
$qb->where('active', 1);
$qb->where('status', 'pending');

// Three-argument: column operator value
$qb->where('age', '>=', 18);
$qb->where('name', 'ILIKE', '%john%');

// Nested closure (parenthesized group)
$qb->where(function ($q) {
    $q->where('status', 'active')->orWhere('role', 'admin');
});
// → WHERE (status = 'active' OR role = 'admin')
```

#### `orWhere(...)`: static`

OR variant. Same calling conventions as `where()`.

```php
$qb->where('role', 'admin')->orWhere('role', 'superuser');
// → WHERE role = 'admin' OR role = 'superuser'
```

#### `whereIn(string $column, array $values): static`

```php
$qb->whereIn('userid', [1, 2, 3]);
// → WHERE userid IN (1, 2, 3)

// Negation
$qb->whereIn('status', ['active', 'pending'], 'and', true);
// → WHERE status NOT IN ('active', 'pending')
```

#### `whereNull(string $column): static` / `whereNotNull(string $column): static`

```php
$qb->whereNotNull('email');
// → WHERE email IS NOT NULL
```

#### `whereBetween(string $column, array $values): static`

```php
$qb->whereBetween('age', [18, 65]);
// → WHERE age BETWEEN 18 AND 65
```

#### `whereRaw(string $sql, array $bindings = []): static`

Raw WHERE clause for dialect-specific expressions.

```php
$qb->whereRaw("LOWER(username) = %s", ['johndoe']);
$qb->whereRaw("ST_DWithin(geom, ST_MakePoint(%s, %s)::geography, 1000)", [23.72, 37.98]);
$qb->whereRaw("created_at > NOW() - INTERVAL '7 days'");
```

#### `whereExists(Closure $callback): static`

EXISTS subquery condition.

```php
$result = $db->queryBuilder()
    ->from('products')
    ->whereExists(function (\Pramnos\Database\QueryBuilder $sub) {
        $sub->select(['1'])
            ->from('order_items')
            ->whereRaw('order_items.product_id = products.id')
            ->whereRaw("order_items.status = 'pending'");
    })
    ->get();
```

### Joins

#### `join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static`

```php
$qb->join('orders o', 'o.userid', '=', 'u.userid');
// → INNER JOIN orders o ON o.userid = u.userid

$qb->join('roles r', 'r.roleid', '=', 'u.roleid', 'left');
// → LEFT JOIN roles r ON r.roleid = u.roleid
```

#### `leftJoin(...)`, `rightJoin(...)`, `crossJoin(...)`

Convenience methods:

```php
$qb->leftJoin('profiles p', 'p.userid', '=', 'u.userid');
$qb->rightJoin('categories c', 'c.id', '=', 'p.category_id');
$qb->crossJoin('colors');  // CROSS JOIN (no ON clause)
```

#### `joinRaw(string $sql): static`

```php
$qb->joinRaw("LEFT JOIN permissions p ON p.userid = u.userid AND p.active = 1");
```

### Ordering & Grouping

#### `orderBy(string $column, string $direction = 'asc'): static`

```php
$qb->orderBy('created_at', 'desc');
$qb->orderBy('username');           // defaults to 'asc'
$qb->orderBy('id', 'asc')->orderBy('name', 'asc');  // multiple columns
```

#### `latest(string $column = 'created_at'): static` / `oldest(...)`

Shortcuts for `orderBy(..., 'desc')` and `orderBy(..., 'asc')`.

```php
$qb->from('posts')->latest()->limit(10)->get();
// → ORDER BY created_at DESC
```

#### `groupBy(string|array $columns): static`

```php
$qb->groupBy('country');
$qb->groupBy(['country', 'city']);
```

#### `having(string $column, mixed $operator = null, mixed $value = null): static`

Same calling convention as `where()`.

```php
$qb->groupBy('country')->having('count', '>', 100);
// → GROUP BY country HAVING count > 100
```

### Pagination

#### `limit(int $value)` / `offset(int $value): static`

```php
$qb->limit(25);
$qb->offset(50);  // page 3 with limit(25)
```

#### `forPage(int $page, int $perPage = 15): static`

Shorthand for `offset(($page - 1) * $perPage)->limit($perPage)`.

```php
$result = $db->queryBuilder()
    ->from('products')
    ->orderBy('name')
    ->forPage(3, 20)   // page 3, 20 per page
    ->get();
```

### Execution & Results

#### `get(): Result`

Compiles and executes the query.

```php
$result = $qb->from('users')->where('active', 1)->get();
```

#### `first(): Result`

Adds `LIMIT 1` and executes.

```php
$result = $qb->from('users')->where('username', 'jane')->first();
if ($result->numRows > 0) {
    echo $result->fields['email'];
}
```

#### `count(): int`

Executes a `COUNT(*)` aggregate.

```php
$total = $qb->from('users')->where('active', 1)->count();

// Pagination example
$qb = $db->queryBuilder()->from('orders')
    ->where('status', 1)
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->offset(40);

$total = $qb->count();  // Clones internally, strips ORDER BY/LIMIT/OFFSET
$rows  = $qb->get();
```

#### Aggregates: `sum()`, `avg()`, `min()`, `max()`

```php
$total   = $qb->from('orders')->sum('amount');
$average = $qb->from('products')->avg('price');
$cheapest = $qb->from('products')->min('price');
$priciest = $qb->from('products')->max('price');
```

#### `exists(): bool` / `doesntExist(): bool`

```php
if ($db->queryBuilder()->from('users')->where('email', $email)->exists()) {
    throw new \RuntimeException('Email already registered');
}

if ($db->queryBuilder()->from('roles')->where('name', 'admin')->doesntExist()) {
    // seed admin role
}
```

#### `value(string $column): mixed` / `pluck(string $column): array`

```php
$email = $db->queryBuilder()->from('users')->where('userid', 42)->value('email');

$emails = $db->queryBuilder()->from('users')->where('active', 1)->pluck('email');
// → ['alice@example.com', 'bob@example.com', ...]
```

### Advanced Features

#### Window Functions

```php
$qb = $db->queryBuilder();
$result = $qb
    ->select([
        'id', 'name', 'category', 'price',
        $qb->over('RANK()', alias: 'price_rank',
            partition: ['category'],
            order: ['price' => 'asc']
        ),
    ])
    ->from('products')
    ->orderBy('category')
    ->get();
```

Supported functions: `RANK()`, `DENSE_RANK()`, `ROW_NUMBER()`, `NTILE()`, `SUM()`, `AVG()`, `MIN()`, `MAX()`, `COUNT()`, `LAG()`, `LEAD()`, `FIRST_VALUE()`, `LAST_VALUE()`

#### Subqueries

```php
// As SELECT column (correlated)
$result = $db->queryBuilder()
    ->select(['userid', 'username'])
    ->selectSub(function ($sub) {
        $sub->select('COUNT(*)')->from('orders')
            ->whereRaw('orders.userid = users.userid');
    }, 'order_count')
    ->from('users')
    ->get();

// As FROM source (derived table)
$result = $db->queryBuilder()
    ->select(['category', 'avg_price'])
    ->fromSub(function ($sub) {
        $sub->select(['category', $sub->raw('AVG(price) AS avg_price')])
            ->from('products')
            ->groupBy('category');
    }, 'cat_avgs')
    ->where('avg_price', '>', 5.00)
    ->get();
```

#### Set Operations

```php
// UNION (removes duplicates)
$active = $db->queryBuilder()->select('userid', 'email')->from('users')->where('active', 1);
$admins = $db->queryBuilder()->select('userid', 'email')->from('admin_users');
$result = $active->union($admins)->get();

// UNION ALL (keeps duplicates)
$q1 = $db->queryBuilder()->select('name')->from('buyers');
$q2 = $db->queryBuilder()->select('name')->from('sellers');
$result = $q1->unionAll($q2)->get();
```

#### Raw Expressions

```php
$qb->select('userid', $qb->raw("TO_CHAR(created_at, 'YYYY-MM') as month"));
$qb->orderBy($qb->raw('COALESCE(last_login, created_at)'), 'desc');
$qb->update(['last_login' => $qb->raw('NOW()')]);
```

#### Conditional Building

```php
$qb = $db->queryBuilder()->from('products');

// Adds WHERE only when $categoryId is set
$result = $qb->when($categoryId, fn($q) => $q->where('category_id', $categoryId))->get();

// With fallback
$result = $qb->when($sortField, 
    fn($q) => $q->orderBy($sortField),
    fn($q) => $q->orderBy('created_at', 'desc')
)->get();
```

## INSERT/UPDATE/DELETE

### INSERT

```php
$result = $db->queryBuilder()
    ->table('logs')
    ->insert([
        'message'    => 'User logged in',
        'userid'     => 42,
        'created_at' => $qb->raw('NOW()'),
    ]);
```

### UPDATE

```php
$db->queryBuilder()
    ->table('users')
    ->where('userid', 42)
    ->update(['last_login' => $qb->raw('NOW()')]);
```

### DELETE

```php
$db->queryBuilder()
    ->from('sessions')
    ->where('expires_at', '<', $qb->raw('NOW()'))
    ->delete();
```

### TRUNCATE

```php
$db->queryBuilder()->from('tmp_imports')->truncate();
```

### Atomic Operations

```php
// Increment
$db->queryBuilder()->from('posts')->where('postid', 123)->increment('views');

// Decrement
$db->queryBuilder()->from('wallets')->where('userid', 42)->decrement('balance', 9.99);
```

### PostgreSQL RETURNING

```php
// INSERT and get the new ID
$result = $db->queryBuilder()
    ->table('users')
    ->returning('userid')
    ->insert(['username' => 'jane', 'email' => 'jane@example.com']);

$newId = $result->fields['userid'];

// UPDATE and retrieve modified row
$result = $db->queryBuilder()
    ->table('users')
    ->where('userid', 5)
    ->returning(['userid', 'updated_at'])
    ->update(['active' => 0]);
```

### Insert Variants

#### `insertOrIgnore(array $values): Result`

```php
$db->queryBuilder()
    ->table('user_subscriptions')
    ->insertOrIgnore(['userid' => 42, 'topic' => 'alerts']);
// Second call with same keys does nothing — no exception
```

#### `upsert(array $values, array $conflictColumns, array $updateValues = []): Result`

```php
$db->queryBuilder()
    ->table('user_settings')
    ->upsert(
        ['userid' => 5, 'setting_key' => 'theme', 'setting_value' => 'dark'],
        ['userid', 'setting_key'],   // conflict target
        ['setting_value']            // columns to update on conflict
    );
```

## Batch Processing

### Chunked Iteration

```php
$db->queryBuilder()
    ->from('users')
    ->where('active', 1)
    ->orderBy('userid')
    ->chunk(500, function (array $rows, int $page) {
        foreach ($rows as $user) {
            sendWelcomeEmail($user['email']);
        }
        // return false here to stop early
    });
```

> **Important:** Always include `ORDER BY` with `chunk()`. Without deterministic ordering, rows may be skipped or duplicated.

## Result Objects

`get()`, `first()`, and write operations return a `Pramnos\Database\Result` instance.

### Cursor-based Iteration

```php
$result = $qb->from('logs')->orderBy('logid', 'desc')->get();

while ($result->fetch()) {
    echo $result->fields['message'] . "\n";
}
```

### Fetch All At Once

```php
$rows = $qb->from('users')->get()->fetchAll();
// $rows is a plain PHP array of associative arrays
```

### Properties & Methods

| Property / Method | Description |
|---|---|
| `$result->fields` | Associative array of current row |
| `$result->numRows` | Total rows in result set |
| `$result->eof` | `true` once all rows read |
| `$result->getNumRows()` | Rows count (method form) |
| `$result->getAffectedRows()` | Rows affected by UPDATE/DELETE |
| `$result->getInsertId()` | Auto-increment ID from INSERT (MySQL) |
| `$result->fetchAll()` | All rows as array |
| `$result->fetch()` | Advance cursor |
| `$result->free()` | Free resource |

## Debugging

### `toSql(): string`

Returns compiled SQL without executing:

```php
echo $qb->from('users')->where('active', 1)->toSql();
// → SELECT * FROM "users" WHERE "active" = '...'
```

### `getBindings(): array`

Returns bound parameter values:

```php
$bindings = $qb->from('users')->where('active', 1)->getBindings();
// → ['where' => [1], 'join' => [], ...]
```

## Complete Example — Paginated List

```php
$db = \Pramnos\Database\Database::getInstance();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$qb = $db->queryBuilder()
    ->select('u.userid', 'u.username', 'u.email', 'g.groupname')
    ->from('users u')
    ->leftJoin('usergroups g', 'g.groupid', '=', 'u.groupid')
    ->where('u.active', 1)
    ->orderBy('u.username')
    ->forPage($page, $perPage);

// count() clones internally — ORDER BY/LIMIT/OFFSET stripped automatically
$total = $qb->count();
$users = $qb->get()->fetchAll();

// Use $users and $total for rendering
```

## Backward Compatibility

`QueryBuilder` is new and purely additive. The existing `Database::query()`, `Database::prepareQuery()`, and `Database::execute()` methods are unchanged and continue to work exactly as before. No migration required for existing code.
