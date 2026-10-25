---
use_cases:
  - Building a SELECT, INSERT, UPDATE or DELETE with the fluent builder
  - Converting existing raw SQL to query-builder calls
  - Joining, grouping or paginating a query
  - Processing a large result set in batches
  - Inspecting the SQL a builder chain produces
  - Reading a column whose name has upper-case letters, such as parentToken
  - Selecting or filtering on a column named after a reserved word, such as order or usage
---

# Pramnos QueryBuilder Guide

The **QueryBuilder** provides a fluent, dialect-aware interface for constructing SQL queries programmatically. It automatically handles dialect differences between MySQL, PostgreSQL, and TimescaleDB, and supports advanced features like window functions, subqueries, and set operations.

**Class:** `Pramnos\Database\QueryBuilder`  
**Entry point:** `$db->queryBuilder()` — returns a fresh builder bound to the current database connection.

---

## Foundational Concepts

### Read/Write Replicas

Applications that scale horizontally typically run one primary database for writes and one or more read replicas for SELECT queries. The `Database` class maintains separate `read` and `write` connections, automatically routing queries based on their type.

#### Configuration

Add `read` and `write` blocks to your `settings.php`:

```php
'database' => [
    'type'      => 'mysql',
    'write' => [
        'hostname' => 'db-primary.example.com',
        'user'     => 'app_rw',
        'password' => 'secret',
        'database' => 'myapp',
    ],
    'read' => [
        'hostname' => 'db-replica.example.com',
        'user'     => 'app_ro',
        'password' => 'secret',
        'database' => 'myapp',
    ],
    'port'      => 3306,
    'prefix'    => 'pramnos_',
    'collation' => 'utf8mb4_unicode_ci',
]
```

PostgreSQL / TimescaleDB works identically:

```php
'database' => [
    'type'   => 'postgresql',
    'write'  => ['hostname' => 'pg-primary', 'user' => 'app', 'password' => '...', 'database' => 'myapp'],
    'read'   => ['hostname' => 'pg-replica', 'user' => 'app', 'password' => '...', 'database' => 'myapp'],
    'schema' => 'public',
]
```

#### How Routing Works

`Database::isWriteQuery(string $sql): bool` checks the first SQL keyword. Queries beginning with `SELECT`, `SHOW`, `EXPLAIN`, `DESC`, or `DESCRIBE` are treated as reads; everything else as a write.

```php
$db = \Pramnos\Database\Database::getInstance();

// Automatically uses the READ connection
$result = $db->query("SELECT * FROM #PREFIX#users WHERE active = 1");

// Automatically uses the WRITE connection — the query builder routes the
// same way, since it runs on the same execute() underneath.
$db->queryBuilder()->table('users')->where('userid', 42)->update(['last_login' => date('Y-m-d H:i:s')]);
```

#### API Reference

| Method | Description |
|---|---|
| `getConnection(bool $isWrite = false)` | Returns the appropriate live connection, reconnecting if needed |
| `isConnectionAlive(mixed $connection): bool` | Checks if connection handle is open |
| `isWriteQuery(string $sql): bool` | Returns `true` if the query's first keyword implies a write operation |

If the `read`/`write` config keys are absent, one connection serves all queries.

### Connection Health & Auto-reconnect

Long-running workers and daemon processes lose database connections when the server closes idle sockets (e.g., MySQL's `wait_timeout`). `Database::query()` detects a lost connection and transparently reconnects once before executing.

#### How It Works

On each query, if the connection is dead, the framework calls `tryReconnect()` before executing SQL. If reconnect succeeds, the query runs normally. If it fails, the original exception propagates.

#### API Reference

| Method | Description |
|---|---|
| `tryReconnect(): bool` | Non-fatal reconnect. Returns `true` on success, `false` on failure |
| `refresh(bool $throwOnFailure = true): bool` | Full reconnect. Throws `RuntimeException` on failure if `$throwOnFailure` is `true` |
| `isConnectionAlive(mixed $connection): bool` | Low-level check used internally |

#### Usage

For most applications, reconnect is **fully automatic** — no code changes needed:

```php
// Normal query — transparently reconnects if connection dropped
$result = $db->query('SELECT * FROM users WHERE active = 1');
```

For long-running daemons that want to pro-actively verify before a critical operation:

```php
// Non-fatal check (returns bool)
if (!$db->tryReconnect()) {
    $logger->warning('Database unavailable, skipping this cycle');
    sleep(5);
    continue;
}
```

For workers that should abort on connection failure:

```php
// Throws RuntimeException on failure
$db->refresh(throwOnFailure: true);
```

### DatabaseCapabilities — Runtime Detection

Features like `JSONB`, TimescaleDB hypertables, and spatial indexes are not available on every backend. `DatabaseCapabilities` detects the connected server's actual capabilities at runtime and provides a clean API to branch on them.

**Class:** `Pramnos\Database\DatabaseCapabilities`

#### Getting Started

```php
$db   = \Pramnos\Database\Database::getInstance();
$caps = new \Pramnos\Database\DatabaseCapabilities($db);

if ($caps->hasTimescaleDB()) {
    // use time_bucket(), hypertable APIs
} elseif ($caps->isPostgreSQL()) {
    // plain PostgreSQL fallback
} else {
    // MySQL fallback
}
```

#### Conditional Execution with `ifCapable()`

```php
$caps->ifCapable(
    \Pramnos\Database\DatabaseCapabilities::TIMESCALEDB,
    function (\Pramnos\Database\Database $db) use ($table) {
        // runs only on TimescaleDB
        $db->schema()->createHypertable($table, 'time');
    },
    function () {
        // runs on all other backends
    }
);
```

#### Feature Constants

| Constant | Value | Detected via |
|---|---|---|
| `TIMESCALEDB` | `'timescaledb'` | `pg_extension` catalog query |
| `FEATURE_JSON` | `'json'` | Always true (MySQL 5.7+, all PG versions) |
| `JSONB` | `'jsonb'` | PostgreSQL only |
| `FEATURE_FULLTEXT` | `'fulltext'` | MySQL only |
| `FEATURE_SPATIAL` | `'spatial'` | MySQL with spatial extensions |

#### API Reference

- `has(string $capability): bool` — Returns `true` if capability is supported
- `isMySQL(): bool` — Returns `true` for MySQL
- `isPostgreSQL(): bool` — Returns `true` for PostgreSQL and TimescaleDB
- `hasTimescaleDB(): bool` — Returns `true` only if TimescaleDB extension is loaded
- `ifCapable(string $capability, callable $ifTrue, ?callable $ifFalse = null): mixed` — Executes callback based on capability
- `supports(string $capability): bool` — Fluent alias for `has()`

---

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

#### Table prefixes

A bare table name takes the installation's prefix, as it does in the schema builder that
created the table. `createTable('settings')` makes `pramnos_settings`, and the builder reads
the same table:

```php
$qb->table('settings')              // → pramnos_settings settings
$qb->table('#PREFIX#settings')      // → pramnos_settings
$qb->table('pramnos_settings')      // → pramnos_settings  (already prefixed: not doubled)
$qb->table('settings s')            // → pramnos_settings s
```

- **In a SELECT, the prefixed table is aliased by its bare name.** So `things.id` in a column, a
  condition or a join still names it. `INSERT`, `UPDATE` and `DELETE` name the prefixed table
  without an alias.
- **Left as written:** a name already carrying the prefix, `#PREFIX#`, `schema.table` (resolved
  per driver, as below), a PostgreSQL catalogue (`pg_*`), a subquery, and a CTE of the same
  query.
- **With no prefix configured**, nothing changes.

An installation whose own tables were made **without** the prefix, beside prefixed framework
tables, turns this off and writes `#PREFIX#` where it means the prefixed ones:

```php
'database' => [
    'prefix'            => 'pramnos_',
    'prefix_bare_names' => false,   // table('settings') → settings
],
```

The framework's own queries write `#PREFIX#` without exception, so they hold under either
setting. `TablePrefixInQueriesTest` holds them to it, reading both the names written with
`#PREFIX#` somewhere and the tables the migrations create.

!!! danger "The suite cannot catch a missing prefix — a static check does"
    Both test fixtures declare `'prefix' => ''`, which makes `#PREFIX#users` and
    `users` the same string. Every test passes either way, so nothing about running
    the suite tells you a framework query would miss its prefix with
    `prefix_bare_names` off.

    `tests/Unit/Database/TablePrefixInQueriesTest.php` fails on any bare
    occurrence, in a `table()` / `from()` / join position, of a name the framework
    writes with `#PREFIX#` anywhere. It derives that list from the source, so a new
    table following the convention is covered without editing the test.

!!! tip "A configurable table name belongs behind one accessor"
    `User\User` computes `DB_USERSTABLE` into a property, and its users, user-details
    and user-friends tables each go through one private static accessor, which is also
    what makes them usable from the class's static methods. A query that names such a
    table itself bypasses the setting — and a constant that only some queries honour is
    worse than no constant: it works until somebody sets it.

#### Schema-qualified tables

`authserver.roles` is resolved per driver: a PostgreSQL schema, and a
prefix-flattened `prefix_authserver_roles` on MySQL, which has no schemas. This
is one of the reasons hand-written SQL against those tables silently matches
nothing, and why raw SQL over them should be converted to the builder.

**A `Model` over one resolves the same way.** `Model::getFullTableName()` sends a `_dbtable`
containing a dot through the same resolver, so

```php
class Role extends \Pramnos\Application\Model
{
    protected $_dbtable    = 'authserver.roles';
    protected $_primaryKey = 'roleid';
}
```

reads and writes the schema on PostgreSQL and `prefix_authserver_roles` on the MySQL family
(MariaDB included). The Model names a table in three places, and all three agree:

1. `getFullTableName()` resolves a dotted name, so MySQL never sees `yourdb.authserver.roles` — a
   cross-database reference.
2. A qualified name is checked *before* the connection's schema would be prepended on PostgreSQL:
   it has already said where it lives, and `public.authserver.roles` can only be wrong.
3. Column introspection splits the name into schema and table. Unsplit, it would match nothing,
   and an empty column list would build `INSERT INTO authserver.roles () VALUES ()`; a save with
   no writable columns throws instead of composing invalid SQL.

A model over a `pramnos.*` or `authserver.*` table needs nothing but the name.

TimescaleDB behaves as PostgreSQL throughout — it is an extension, and a settings `type` of
`timescaledb` is normalised to `postgresql` with a `timescale` flag when the settings are read.
Everything downstream compares `type == 'postgresql'` literally and depends on that.

`#PREFIX#` still wins over the dot: `#PREFIX#some.thing` has already said where the prefix goes,
and resolving the dot on top of that would rename the table twice.

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

#### `whereLike(string $column, string $pattern): static` / `orWhereLike(…)`

```php
$qb->whereLike('lastname', '%' . $term . '%');
// MySQL:      WHERE lastname LIKE ?
// PostgreSQL: WHERE translate(lastname::text, 'ΑΒΓ…ΆΈ…ςAB…', 'αβγ…αε…σab…')
//                  LIKE translate(?, 'ΑΒΓ…ΆΈ…ςAB…', 'αβγ…αε…σab…')
```

A `LIKE` that ignores case and accents on every database: `'%μαρια%'` finds `Μαρία`. MySQL
already compares so under the `_ci` collations its text columns are in. PostgreSQL's `LIKE`
tells both apart, so there the column and the pattern are folded alike: upper case to lower,
an accented or diaeresis letter to its bare one, `ς` to `σ`, for Greek and Latin. `ILIKE` would
leave accents apart, and `lower()` follows the database's locale, which under `C` leaves Greek
alone.

`where($column, 'LIKE', …)` stays the comparison as each database makes it. On PostgreSQL that
is case- and accent-sensitive, which is what an exact code or a case-sensitive key wants.

#### `whereLikeText(string $column, string $pattern): static` / `orWhereLikeText(…)`

```php
$qb->whereLikeText('payload', '%' . $term . '%');
// MySQL:      WHERE payload LIKE ?
// PostgreSQL: WHERE convert_from(payload, 'UTF8') LIKE $1
```

For a column typed `bytea` that really holds text — a MySQL `blob` a migration mapped across
without `--blob-as-text` (FW-278). `whereLike()`'s `::text` cast gives a `bytea`'s hex output
form on PostgreSQL, not the bytes decoded, so it never matches; this decodes the column first.
Throws on PostgreSQL if the column's bytes are not valid UTF-8 — a genuinely binary column has
no text to find. See the [Database API guide](Pramnos_Database_API_Guide.md#searching-inside-a-bytea-that-is-really-text-whereliketext).

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

**Placeholders: `?` or `%s`, one per binding.** This builder's own placeholders are
typed — `%s` string, `%i` integer, `%d` float, `%b` boolean — and a raw fragment may
use them directly. A `?` is also accepted and is replaced with the placeholder its
binding's type calls for, at the position it was written:

```php
$qb->whereRaw('channel_id IN (SELECT id FROM channels WHERE station_id = ?)', [$id]);
// compiles to … station_id = %i, bound in this clause's own position
```

A `?` inside a quoted string (`label = 'why?'`) is left alone, and a fragment with
**no** bindings is never rewritten — `whereRaw('enabled = TRUE')` and PostgreSQL's
`jsonb ? key` operator both mean what they say.

**A count mismatch throws immediately**, from the `whereRaw()` call itself:

```php
$qb->whereRaw('a = ?', [1, 2]);
// InvalidArgumentException: whereRaw() was given 2 binding(s) for 1 placeholder(s) in: a = ?
```

That is deliberate. Left to run, a mismatch would leave a literal `?` in the statement
with one binding too many; the server would reject it, `get()`/`first()` would return
**`false`**, and the only symptom would be `Attempt to read property "fields" on false`
in the *calling* code, several lines from the cause. A statement that cannot be prepared
is also written to the application error log with its SQL, so a `false` always has a trail.

`orWhereRaw()` and `orHavingRaw()` exist for the OR forms; `havingRaw()` behaves
identically for HAVING.

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

#### Joining on more than one column

Pass a closure instead of the column arguments. It receives a `JoinClause`, and
every condition you add to it lands in the same `ON`:

```php
use Pramnos\Database\JoinClause;

$qb->leftJoin('authserver.user_organizations uo', function (JoinClause $join) {
    $join->on('uo.userid', '=', 'ur.userid')
         ->on('uo.organization_id', '=', 'rd.organization_id');
});
// → LEFT JOIN authserver_user_organizations uo
//      ON uo.userid = ur.userid AND uo.organization_id = rd.organization_id
```

`on()` ANDs, `orOn()` ORs, and `on('a.x', 'b.x')` means equality. Both sides of a
condition are **column references**: there is no `where()` on a `JoinClause`,
because a comparison against a value belongs in the query's `WHERE`, where it is
bound as a parameter rather than pasted into the `ON`.

#### Aliases on qualified names

`authserver.roles rd` resolves the same way `authserver.roles` does — a schema on
PostgreSQL, a prefixed table on MySQL — and keeps the alias:

```php
$qb->table('authserver.user_roles ur')
   ->join('authserver.roles rd', 'rd.roleid', '=', 'ur.roleid');
// MySQL → FROM authserver_user_roles ur INNER JOIN authserver_roles rd ON …
```

#### `joinRaw(string $sql): static`

For a join the builder cannot express. A multi-condition `ON` does not need
one — pass a closure to `join()` (above):

```php
$qb->joinRaw("LEFT JOIN permissions p ON p.userid = u.userid AND p.active = 1");
```

`joinRaw()` takes the SQL as given — the table name is not resolved and nothing is
bound, so never build one out of user input.

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

#### `forPage(int $page, int $perPage): static`

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

// pluck() reads an alias a select() already projects, rather than overwriting it:
$years = $db->queryBuilder()->from('users')
    ->select($db->queryBuilder()->raw('YEAR(created_at) as year'))
    ->groupBy('year')
    ->pluck('year');
```

#### `getAll()` and `pluck()` answer `[]` for a failed query too — and only on PostgreSQL

`get()` keeps the distinction: **`false`** when the query failed, a `Result` when it succeeded
*including* when it matched nothing. `getAll()` and `pluck()` collapse both into `[]`, which is
the convenience they exist for.

Which engine you are on decides whether that can hide anything. With `throwOnError` off — the
default — a failed prepare **returns `false` on PostgreSQL** and **throws on MySQL**:

| | A missing table, via `getAll()` |
| --- | --- |
| PostgreSQL | `[]` — indistinguishable from an empty table |
| MySQL | throws `mysqli_sql_exception` |

So an application developed against one and deployed against the other gets a different failure
mode for free.

**Where this bites is not the method, it is where the method gets reached for.** `getAll()` is
the obvious way to read a list, and the lists whose empty answer is most plausible are the ones
where it is most consequential — **settings, permissions, bans, allowlists**. A ban list that
failed to read is an empty ban list, and one cache call later it is a *cached* empty ban list,
outliving the failure that caused it.

Three ways to keep the distinction, cheapest first:

```php
// 1. get() and check — no new API, works everywhere
$result = $db->queryBuilder()->from('url_blacklist')->get();
if ($result === false) {
    throw new \RuntimeException('blacklist unreadable — refusing to treat it as empty');
}
$patterns = $result->fetchAll();

// 2. getAllOrFail() — the same as getAll(), except a failed query throws QueryException.
//    One exception type on both drivers, so the per-driver split above stops mattering.
$patterns = $db->queryBuilder()->from('url_blacklist')->getAllOrFail();

// 3. connection-wide, when everything in a process should be loud
$db->throwOnError = true;
```

Reach for (2) on a read whose empty answer would be a decision, and **especially** when the
answer is about to be cached. See
[How database failures surface](Pramnos_Database_API_Guide.md#how-database-failures-surface-and-throwonerror)
for the driver detail.

#### A `try/catch` around a call that does not throw is a comment

Worth naming, because it is what this looks like in code somebody already worried about:

```php
try {
    $patterns = $db->queryBuilder()->from('url_blacklist')->getAll();
} catch (\Throwable $e) {
    // a membership failing open grants somebody another station's tools, so this denies
    return self::DENY;
}

return $this->cache($patterns);   // ← an unreadable table arrives here, as []
```

`getAll()` does not throw on PostgreSQL, so the `catch` is unreachable and the failure walks
straight into the branch that caches a miss. The right decision is written down and cannot run.

So when you find a `try/catch` around a convenience read, treat it as a **signal rather than a
guard**: somebody knew this could fail and which way it should go. Check what the call actually
does on failure, and if the answer is "returns `[]`", the fix is `getAllOrFail()` or `get()` —
the `catch` was already the right intention.

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

**A raw value is emitted, never bound.** `raw()` returns an `Expression`, and wherever
a value is expected the grammar writes the fragment itself in place of a placeholder:

```php
$qb->from('sessions')->where('expires_at', '<', $qb->raw('NOW()'));
// WHERE expires_at < NOW()      ← no placeholder, and nothing bound for it
```

Scalars beside it are still bound, in the position they were written:

```php
$qb->from('loginlockouts')
   ->where('locktype', 'ip')                       // %s  ← bound
   ->where('lockoutuntil', '>', $qb->raw('NOW()')) // inlined
   ->where('failedattempts', '>=', 3);             // %i  ← bound
```

That holds for `where()`, `orWhere()`, `having()`, `whereIn()` and both endpoints of
`whereBetween()`, as well as the `insert()` / `update()` / `upsert()` value maps.


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
$qb = $db->queryBuilder();
$result = $qb
    ->table('logs')
    ->insert([
        'message'    => 'User logged in',
        'userid'     => 42,
        'created_at' => $qb->raw('NOW()'),
    ]);
```

### UPDATE

```php
$qb = $db->queryBuilder();
$qb
    ->table('users')
    ->where('userid', 42)
    ->update(['last_login' => $qb->raw('NOW()')]);
```

### DELETE

```php
$qb = $db->queryBuilder();
$qb
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

#### `insertMany(array $rows): int`

```php
$written = $db->queryBuilder()->table('authserver.permissions')->insertMany([
    ['subject_id' => 7, 'object_type' => 'posts', 'action' => 'read'],
    ['subject_id' => 7, 'object_type' => 'posts', 'action' => 'write'],
]);   // 2
```

One `INSERT … VALUES (…), (…)` for up to 500 rows, and as many statements as it takes beyond
that. Every row has the first row's columns, in any order; a row with a column missing or extra
is refused with an `InvalidArgumentException` before anything is written. An `Expression`
value is inlined, as in `insert()`.

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

## Writing and reading a moment: `Instant`

A `timestampTz` column (`TIMESTAMP` on MySQL and MariaDB, `timestamptz` on PostgreSQL) takes a
moment the same way on every backend when the moment travels as a Unix time:

```php
use Pramnos\Database\Instant;

$db->queryBuilder()->table('authserver.audit_log')->insert(['event_timestamp' => Instant::at($db, time())]);
$query->where('event_timestamp', '>=', Instant::at($db, strtotime('-7 days')));
$query->select(['*', Instant::unix($db, 'event_timestamp', 'at')]);   // each row gets `at`, a Unix time
```

`Instant::at()` is `FROM_UNIXTIME()` / `to_timestamp()`, converted by the database in its
session's zone. `Instant::unix()` is `UNIX_TIMESTAMP()` / `EXTRACT(EPOCH …)` back. Use the two
together: a value written with `at()` and read as a string comes back in the session's zone.

A column whose readers already take the string the database answers has a string to match:

| | PostgreSQL | MySQL, MariaDB | Read back as |
|---|---|---|---|
| `Instant::utc($unix)` | `2026-10-16 09:00:00+00:00` | `2026-10-16 09:00:00` (UTC) | UTC: `new DateTimeImmutable($value, new DateTimeZone('UTC'))` |
| `Instant::local($unix)` | `2026-10-16 12:00:00+03:00` | `2026-10-16 12:00:00` (PHP's zone) | `strtotime($value)` |

The language-model tables and the push log use `utc()`; the activity log uses `local()`. Never
`date('c')`: MariaDB drops the offset (warning 1292, and strict mode refuses the row).

## Column names with upper-case letters

A bare column name containing an upper-case letter is quoted automatically:

```php
$db->queryBuilder()->table('usertokens')
    ->select(['tokenid', 'parentToken'])
    ->where('parentToken', 42)
    ->get();

// PostgreSQL: SELECT tokenid, "parentToken" FROM usertokens WHERE "parentToken" = $1
// MySQL:      SELECT tokenid, `parentToken` FROM usertokens WHERE `parentToken` = ?
```

You need this because **PostgreSQL folds an unquoted identifier to lower case**.
`SELECT parentToken` asks for `parenttoken`, which does not exist, and the query
fails. `INSERT` and `UPDATE` quote as well, so a camelCase column can be both written and
read; unquoted, the failure would look exactly like an empty result.

A bare identifier is quoted when it has an upper-case letter, and so is the column of a
qualified `table.column`. The table or alias in front is not: an alias written
`FROM usertokens UT` is folded to `ut`, and `"UT"` would name nothing.

| Written | Emitted | Why |
|---|---|---|
| `parentToken` | `"parentToken"` | Would otherwise fold |
| `tokenid` | `tokenid` | Folding cannot affect it |
| `ut.parentToken` | `ut."parentToken"` | The column would otherwise fold; the alias would not need it |
| `MAX(ut.lastused) AS x` | unchanged | An expression, not an identifier |
| `*`, `ut.*` | unchanged | Not a name |
| `"parentToken"` | unchanged | Already quoted |

### Reserved words

A column named after a reserved word — `order`, `key`, `usage`, `rank`, … — is quoted wherever
the builder names a column (`select`, `where`, `groupBy`, `orderBy`, `having`), bare or
qualified, and so is an alias that is one:

| Written | MySQL | PostgreSQL |
|---|---|---|
| `usage` | `` `usage` `` | `"usage"` |
| `c.usage` | `` c.`usage` `` | `c."usage"` |
| `tokens AS order` | ``tokens AS `order` `` | `tokens AS "order"` |
| `SUM(cost) AS Total` | unchanged | unchanged — the row keeps its `total` key |
| `$qb->raw('…')` | unchanged | unchanged — raw SQL is the caller's |

The list is the words that turn up as column names, from both dialects — `usage`, for one, is
reserved by MySQL alone. A word missing from it, quote yourself.

A **qualified** camelCase column — `ut.parentToken` in a join — is not quoted for
you, because the builder cannot tell a table alias from a schema without parsing
the reference. Quote it yourself, per dialect, or select the table with `*` and read
the field from the result:

```php
// Portable: the column name survives in the result either way.
$row = $db->queryBuilder()->table('usertokens')->select('*')
    ->where('tokenid', $id)->first();

$parent = $row->fields['parentToken'];
```

Applies to `select()`, `where()` and its variants, `whereIn`, `whereNull`,
`whereNotNull`, `whereBetween`, `groupBy`, `orderBy` and `having`.

## Debugging

### `toSql(): string`

Returns compiled SQL without executing:

```php
echo $qb->from('users')->where('active', 1)->toSql();
// → SELECT * FROM users WHERE active = %i   (typed placeholder; the value is in getBindings())
```

### `getBindings(): array`

Returns bound parameter values:

```php
$bindings = $qb->from('users')->where('active', 1)->getBindings();
// → [1]   — a flat list, in placeholder order
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
