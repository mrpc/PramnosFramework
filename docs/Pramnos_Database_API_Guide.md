---
use_cases:
  - Reading from or writing to the database from a controller or model
  - Handling a query result set
  - Deciding between raw SQL and the fluent query builder
  - Diagnosing a query that returns nothing or the wrong rows
  - Holding a database handle in a long-lived worker or daemon
  - Reading a table's columns, types or foreign keys from code
---

# Pramnos Database API Guide

This guide explains how to properly use the Pramnos database API for database operations in the Pramnos MVC framework.

> **v1.2 Update:** A new fluent **QueryBuilder** API is now available for more expressive query construction. See [Pramnos_QueryBuilder_Guide.md](Pramnos_QueryBuilder_Guide.md) for modern query patterns. The `Database::query()` and `prepareQuery()` methods remain unchanged and fully supported.

## Overview

The Pramnos framework provides a database abstraction layer that must be used for all database operations. The key principle is to **always use `prepareQuery()` for parameter binding, then `query()` for execution**.

**For new code, consider using the QueryBuilder API:**

```php
// Modern approach (v1.2+)
$users = $db->queryBuilder()
    ->from('#PREFIX#users')
    ->where('active', 1)
    ->get();

// Legacy approach (still supported)
$sql = $db->prepareQuery("SELECT * FROM users WHERE active = %d", 1);
$result = $db->query($sql);
```

## Database Access in Controllers

In Pramnos controllers, the database connection is accessed through the application instance:

```php
// CORRECT: Access database through application
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);

// INCORRECT: Direct access to database property
$sql = $this->application->database->prepareQuery("..."); // This will cause "Call to a member function on null" error
```

**Important**: Always use `$this->application->database` in controllers, not `$this->application->database`.

## Core Pattern

### The Two-Step Process

1. **Prepare Query**: Use `prepareQuery()` with printf-style formatting
2. **Execute Query**: Use `query()` to execute the prepared query

```php
// CORRECT: Pramnos pattern with printf-style formatting
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s AND status = %d", $email, $status);
$result = $this->application->database->query($sql);

// INCORRECT: Don't use ? placeholders
$this->application->database->prepareQuery("SELECT * FROM users WHERE id = ?", [$userId]); // Wrong syntax!
```

### Printf-Style Format Specifiers

- `%s` - String values
- `%d` - Integer values  
- `%%` - Literal % character

**Note**: Only `%s` and `%d` are commonly used in the Pramnos framework.

## A literal `%` in the SQL, and `LIKE`

`prepareQuery()` is `sprintf` underneath, so a `%` in the query string is a format directive unless
you say otherwise. Two rules follow, and the second used to be a crash:

**With arguments, write `%%` for a literal percent.** That is what the method documents and what
every `LIKE` needs:

```php
// Wildcards, with a bound argument beside them
$sql = $db->prepareQuery(
    "SELECT * FROM `#PREFIX#users` WHERE `userid` = %d AND `username` LIKE 'a%%'",
    7
);
```

**With no arguments, a single `%` is left alone.** Until 1 September 2026 it was not: `vsprintf` ran
whether or not there was anything to substitute, so

```php
$db->prepareQuery("SELECT * FROM `#PREFIX#queueitems` WHERE `payload` LIKE '%ApplyStart%'");
```

raised **`ValueError: Missing padding character`** — `sprintf` reads the trailing `%'` as «pad with
the next character» and there is no next character. The `@` in front of `vsprintf` suppressed
warnings, not exceptions, so this was a fatal error on an ordinary query. It no longer calls
`vsprintf` when there is nothing to substitute.

`%%` still collapses to `%` on that path, because callers have relied on that for years — a query
holding `DATE_FORMAT(\`created\`, '%%c')` with no arguments still reaches the database as `'%c'`.

A `%s` or `%d` with no argument passes through to the database instead of raising, which is a
malformed query rather than a fatal. If you write a placeholder, pass its value.

## Reading a table's schema: `getColumns()`

```php
$result = $db->getColumns('stations');
while ($result->fetch()) {
    $result->fields['Field'];       // column name
    $result->fields['Type'];        // 'varchar'      — the bare type
    $result->fields['ColumnType'];  // 'varchar(255)' — as declared (MySQL)
    $result->fields['Null'];        // 'YES' / 'NO'
    $result->fields['Comment'];
    $result->fields['Key'];         // 'PRI' on the primary key
    $result->fields['ForeignKey'], $result->fields['ForeignTable'];
}
```

Columns come back **in the order the table declares them**, and the result is
**cached for an hour** per table, because a schema rarely changes and
introspection is not cheap.

Two things follow from that cache, and both have a name:

**A schema change through the schema builder invalidates it.** Every DDL method
on `SchemaBuilder` — `createTable()`, `alterTable()`, `dropTable()`,
`dropTableIfExists()`, `renameTable()` — flushes the table it touched, so a
migration is visible to the next read. A raw `$db->query('ALTER TABLE …')` does
not; call `$db->forgetColumns($table)` yourself if you do that.

**Pass `$fresh` when a stale answer would be wrong rather than merely old:**

```php
$db->getColumns($table, $schema, false, true);   // never from the cache
```

Code generators do this, because the framework's documented order of work is
`create:migration`, migrate, `create:crud` — so a generator runs minutes after
the schema changed, and an hour-old answer describes the table as it was before
the migration.

On PostgreSQL, `PrimaryKey` and `ForeignKey` come back as booleans rather than
MySQL's `'PRI'` / `1`; `ForeignTable` and `ForeignColumn` name the referenced
table and column on both. Code reading these should accept either spelling —
`true`, `'t'` and `1` all mean yes, depending on how the row was cast on the way
out.

> **Fixed 2026-08-24 on PostgreSQL, two faults in the flags.** `ForeignKey` was
> computed from `information_schema.constraint_column_usage`, and for a FOREIGN
> KEY that view lists the column of the **referenced** table — so on
> `streams(station_id) → stations(id)` the flag was true on `id`, the primary
> key, and false on `station_id`. It was never true for a foreign key on any
> table, so every generator gated on it saw none: the SPA form rendered a number
> input where its searchable picker belongs, the MVC form a bare input instead of
> its select2, and `unsigned` is decided from the same flag so generated
> migrations differed too. `ForeignTable` beside it was correct all along.
>
> `PrimaryKey` answered correctly through the old view by coincidence — for a
> PRIMARY KEY constraint it *does* list the table's own columns — so the two
> looked symmetric while one of them was luck. Both now read
> `key_column_usage`, which is the view that lists what this table constrains.

> **Fixed 2026-08-24, three faults in one method.** Nothing invalidated the
> cache at all, so the sequence above wrote a model and a form for the old
> columns and reported success — and because the store is shared, re-running the
> command did not clear it either. Neither query had an `ORDER BY`, so column
> order was whatever the server felt like, usually alphabetical. And only
> `DATA_TYPE` was selected on MySQL, so `tinyint(1)` — the boolean convention —
> arrived as `tinyint` and every generated form rendered every boolean column as
> a number input. `ColumnType` is the declared type; `Type` is unchanged for
> callers that read it.

## Real Prepared Statements: `preparedQuery()`

`prepareQuery()` + `query()` interpolates escaped values into the SQL string.
`preparedQuery()` is different: it runs the statement through the driver's own
prepared-statement engine (`pg_prepare`/`pg_execute`, or mysqli), so values are
bound as parameters and never become part of the SQL text.

It is the PDO-style bridge, added for code migrating off a raw `\PDO` handle, and
it takes the SQL verbatim — PostgreSQL `RETURNING`, `ON CONFLICT`, `DISTINCT ON`
and stored-function calls all survive untouched.

```php
$db = \Pramnos\Database\Database::getInstance();

// Named placeholders — a name may repeat, and binds at each occurrence.
$result = $db->preparedQuery(
    'SELECT * FROM stations WHERE ownerid = :owner AND status = :status',
    ['owner' => $userId, 'status' => 1]
);

// Positional placeholders.
$result = $db->preparedQuery(
    'SELECT * FROM stations WHERE id IN (?, ?)',
    [$first, $second]
);
```

The two styles are mutually exclusive within one statement, exactly as with PDO.
Keys may be written with or without the leading colon (`'owner'` or `':owner'`).

**Return value.** A `Result` on success, or **`false`** when the statement could
not be prepared. It does not throw for that case unless the connection is in
strict mode (see [`throwOnError`](#how-database-failures-surface-and-throwonerror)).
A caller writing `$db->preparedQuery(...) ?: []` to keep a read failure from
taking a page down is a deliberate pattern — but be aware that it makes a broken
query and an empty table look the same. Turn on `throwOnError` while developing
so the difference is loud.

**Missing bindings throw.** A `:name` with no value in the array is a
programming error and raises `\InvalidArgumentException`, as does a positional
count that does not match the number of `?`.

### What counts as SQL, and what does not

Both `preparedQuery()` and the `%s`/`%d` engine behind `execute()` have to decide
which parts of a statement are really SQL before they can find placeholders in
it. Two kinds of text are skipped:

- **Single-quoted string literals.** `'%'` in a `LIKE` pattern is data, and
  `':notabind'` is a string, not a placeholder. `''` is an escaped quote and
  keeps the literal open.
- **Comments.** `--` and `/* … */` everywhere, plus `#` on MySQL. Nothing inside
  a comment is read: a placeholder written there is prose, and — the part that
  matters in practice — **an apostrophe there is just an apostrophe**.

That last point is worth stating plainly, because the parser did not always know
it:

```php
// Fine. The possessive in the comment is prose, and :minutes still binds.
$db->preparedQuery(
    "SELECT /* a JOIN's clause */ (:minutes || ' minutes')::interval AS v",
    ['minutes' => 15]
);
```

Before this was handled, the apostrophe in `JOIN's` read as the start of a string
literal, so every placeholder after it was left in the SQL unbound; the statement
failed and `preparedQuery()` answered `false`. In a consuming application that
turned a working page into an empty one, with no error anywhere — the read did
`?: []` and the page truthfully reported that it had no rows.

**The two dialects genuinely disagree**, and the framework follows each server's
own rule rather than picking one:

| | MySQL | PostgreSQL |
|---|---|---|
| `#` to end of line | comment | operator — still SQL |
| `--` followed by space | comment | comment |
| `--` with no space (`5--3`) | arithmetic → `8` | comment → `5` |
| `/* a /* b */ c */` | ends at the first `*/` | nests; ends at the last |
| `/*!40101 … */` | **executed** — placeholders inside it bind | ordinary comment |

You do not need to do anything with this table; it is here so that a statement
which behaves differently on two servers has a documented reason. Write comments
however you like, apostrophes included.

### A prepared statement whose connection died underneath it

Nothing to do; worth knowing it happens and that it is handled, because the failure it prevents is
quiet.

Every connection has an idle timeout — MySQL's `wait_timeout` is eight hours by default, a managed
PostgreSQL or a connection pooler is usually far less — and the processes that hold a handle longest
are the ones nobody is watching: a queue worker, a scheduled command, a daemon. They prepare a
statement once and execute it for hours. A restarted database, a failover, an operator's `KILL`
clearing a lock, and a pooler recycling a backend all produce the same thing.

`execute()` notices that the connection is gone rather than that the statement failed, reconnects,
re-prepares and runs it once. **Once**, and only for that condition: re-running a statement whose
result was never seen is harmless for a `SELECT` and a duplicate for an `INSERT`, so a retry is only
correct when the server cannot have applied it — which is what «the connection was already gone»
means. A retry on any error would silently double writes.

Two things about it are worth writing down, because both were true for a long time and both were
invisible:

**The retry could not fire on MySQL.** The gate read `mysqli_errno()` after an `execute()` that
returned false — but mysqli's default error mode has been `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
since PHP 8.1, so `execute()` raises `mysqli_sql_exception` instead of returning false. The branch
holding the gate was unreachable code. The failure the retry existed to survive was the one failure it
could not, and the symptom was an uncaught exception in a process nobody is watching.

**The retry could not fire on PostgreSQL either, for two different reasons.** The gate asked
`pg_connection_status()`, which reports the last *known* state rather than polling: with the backend
already terminated it answers `OK` before any operation, still `OK` at the instant the failing
`pg_execute()` returns, and `BAD` only afterwards — so it was consulted at the one moment the answer
was wrong. It reads the error text now, which says «server closed the connection unexpectedly» right
there. And once that was fixed the retry still failed, because the prepared-statement cache is keyed
on `md5($query)` alone: after reconnecting it handed back a plan name belonging to a session
PostgreSQL had already forgotten. A plan does not outlive its session, so the cache is scoped to the
connection that made it.

If you keep a `Database` handle across a long idle period yourself, you need nothing extra. If you
hold a raw `mysqli`/`PgSql\Connection` from `getConnectionLink()`, you own that problem.

## Result Handling

**CRITICAL**: Pramnos query results use different patterns for single vs multiple records:

### Single Record Queries
For queries that return a single record (e.g., `SELECT * FROM users WHERE id = %d` or `SELECT COUNT(*) as total`):

```php
$sql = $database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $database->query($sql);

// Single record - NO fetch() needed, NO [0] index
if ($result->numRows > 0) {
    $userData = $result->fields; // Direct associative array of column => value
    $userName = $result->fields['name'];
    $userEmail = $result->fields['email'];
}

// COUNT queries (single result)
$sql = $database->prepareQuery("SELECT COUNT(*) as total FROM users");
$result = $database->query($sql);
$count = $result->fields['total']; // Direct access, NOT $result->fields[0]['total']
```

### Multiple Record Queries
For queries that return multiple records (e.g., `SELECT * FROM applications ORDER BY created_at`):

```php
$sql = $database->prepareQuery("SELECT * FROM applications WHERE user_id = %d ORDER BY created_at DESC", $userId);
$result = $database->query($sql);

// Multiple records - USE fetch() to iterate
$applications = [];
while ($result->fetch()) {
    $applications[] = $result->fields; // Each row as associative array
}
```

### Common Mistakes to Avoid

❌ **WRONG** - Never use `[0]` index for single records:
```php
// These patterns are INCORRECT for Pramnos framework:
$user = $result->fields[0];           // Wrong!
$count = $result->fields[0]['total']; // Wrong!
```

✅ **CORRECT** - Direct access for single records:
```php
// These patterns are CORRECT for Pramnos framework:
$user = $result->fields;           // Correct!
$count = $result->fields['total']; // Correct!
```

### Key Points:
- **Single records**: Check `$result->numRows > 0`, then access `$result->fields` directly (no `[0]` index)
- **Multiple records**: Use `while ($result->fetch())` to iterate through rows
- **Never use** `$result->fields[0]` - this is from other frameworks, not Pramnos
- Access column data via `$result->fields` (associative array)

## Database Operations

### SELECT Operations

```php
// Single record
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);
if ($result->numRows > 0) {
    $user = $result->fields; // Associative array of columns
}

// Multiple records
$sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE user_id = %d ORDER BY created_at DESC", $userId);
$result = $this->application->database->query($sql);
$applications = [];
while ($result->fetch()) {
    $applications[] = $result->fields;
}

// String and integer parameters
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s AND status = %d", $email, $status);
$result = $this->application->database->query($sql);
```

## The Modern Way: Fluent Query Builder (v1.2+)

While `prepareQuery()` and `query()` are the foundational patterns, v1.2 introduces a fluent **Query Builder** for cleaner, more maintainable code, especially for complex queries.

### Advantages:
- No need to remember `%s` / `%d` specifiers.
- Automatic table quoting.
- Easier joins and conditions.
- Better cross-database compatibility (MySQL/PostgreSQL).

### Examples:

```php
// SELECT with conditions and ordering
$users = $this->application->database->queryBuilder()
    ->from('#PREFIX#users')
    ->where('status', 1)
    ->where('role', 'admin')
    ->orderBy('last_login', 'desc')
    ->limit(10)
    ->get();

// Complex JOINs
$applications = $this->application->database->queryBuilder()
    ->select('a.*', 'u.username as owner_name')
    ->from('applications a')
    ->join('users u', 'u.id', '=', 'a.user_id')
    ->where('a.status', 'active')
    ->get();

// INSERT with auto-binding
$newId = $this->application->database->queryBuilder()
    ->table('#PREFIX#users')
    ->insert([
        'username' => 'newuser',
        'email' => 'user@example.com',
        'status' => 1
    ]);
```

#### Raw Expressions
If you need database-specific functions (like PostGIS or native MySQL functions), use `raw()`:

```php
$locations = $this->application->database->queryBuilder()
    ->from('locations')
    ->whereRaw("ST_Distance(geom, ST_MakePoint(?, ?)) < 1000", [23.7, 37.9])
    ->get();
```

### INSERT Operations

```php
$sql = $this->application->database->prepareQuery(
    "INSERT INTO applications (client_id, client_secret, name, redirect_uri, user_id, created_at) VALUES (%s, %s, %s, %s, %d, %s)",
    $clientId, $clientSecret, $name, $redirectUri, $userId, date('Y-m-d H:i:s')
);
$result = $this->application->database->query($sql);

// Get inserted ID
$insertId = $this->application->database->getLastInsertId();
```

### UPDATE Operations

```php
$sql = $this->application->database->prepareQuery(
    "UPDATE applications SET name = %s, redirect_uri = %s WHERE id = %d AND user_id = %d",
    $name, $redirectUri, $id, $userId
);
$result = $this->application->database->query($sql);
```

### DELETE Operations

```php
// Simple delete
$sql = $this->application->database->prepareQuery("DELETE FROM user_tokens WHERE expires_at < %s", date('Y-m-d H:i:s'));
$result = $this->application->database->query($sql);

// With parameters
$sql = $this->application->database->prepareQuery("DELETE FROM applications WHERE id = %d AND user_id = %d", $id, $userId);
$result = $this->application->database->query($sql);
```

### Queries Without Parameters

```php
// For queries without parameters, you can use query() directly
$result = $this->application->database->query("SELECT COUNT(*) as count FROM users");
```

## Model Integration

### In Model Classes

Models extend `Pramnos\Application\Model` and should use the database API through `$this->application->database` (passed in constructor):

```php
class Application extends \Pramnos\Application\Model
{
    public function loadByClientId($clientId)
    {
        $sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE client_id = %s", $clientId);
        $result = $this->application->database->query($sql);
        
        if ($result->numRows > 0) {
            foreach (array_keys($result->fields) as $key) {
                if (property_exists($this, $key)) {
                    $this->$key = $result->fields[$key];
                }
            }
            return true;
        }
        return false;
    }
    
    public function save()
    {
        if (isset($this->id) && $this->id > 0) {
            // Update existing record
            $sql = $this->application->database->prepareQuery(
                "UPDATE applications SET name = %s, client_secret = %s, redirect_uri = %s WHERE id = %d",
                $this->name, $this->client_secret, $this->redirect_uri, $this->id
            );
            $this->application->database->query($sql);
        } else {
            // Insert new record
            $sql = $this->application->database->prepareQuery(
                "INSERT INTO applications (name, client_id, client_secret, redirect_uri, created_at) VALUES (%s, %s, %s, %s, %s)",
                $this->name, $this->client_id, $this->client_secret, $this->redirect_uri, date('Y-m-d H:i:s')
            );
            $this->application->database->query($sql);
            $this->id = $this->application->database->getLastInsertId();
        }
    }
}
```

### Model Instantiation

Models should be instantiated with the controller:

```php
// In a Controller
$model = new \Model($this);
$model->load($id);

```


## Controllers and Database Access

### In Controller Classes

Controllers should use `$this->application->database` for database operations:

```php
class Token extends \Pramnos\Application\Controller
{
    public function revokeToken()
    {
        $token = $_POST['token'] ?? '';
        
        // Revoke access token
        $sql = $this->application->database->prepareQuery("UPDATE user_tokens SET revoked = 1 WHERE access_token = %s", $token);
        $this->application->database->query($sql);
        
        // Also revoke refresh token if it matches
        $sql = $this->application->database->prepareQuery("UPDATE user_tokens SET revoked = 1 WHERE refresh_token = %s", $token);
        $this->application->database->query($sql);
        
        $this->response(['revoked' => true]);
    }
}
```

## Common Patterns

### Checking if Record Exists

```php
$sql = $this->application->database->prepareQuery("SELECT COUNT(*) as count FROM applications WHERE client_id = %s", $clientId);
$result = $this->application->database->query($sql);
$exists = false;
if ($result->numRows > 0) {
    $exists = $result->fields['count'] > 0;
}
```

### Getting Multiple Records

```php
$sql = $this->application->database->prepareQuery("SELECT * FROM user_tokens WHERE user_id = %d AND revoked = 0 ORDER BY created_at DESC", $userId);
$result = $this->application->database->query($sql);

$tokens = [];
while ($result->fetch()) {
    $tokens[] = $result->fields;
}
```

### Complex Queries with JOINs

```php
$sql = $this->application->database->prepareQuery(
    "SELECT ut.*, a.name as app_name 
     FROM user_tokens ut 
     JOIN applications a ON ut.client_id = a.client_id 
     WHERE ut.user_id = %d AND ut.expires_at > %s",
    $userId, date('Y-m-d H:i:s')
);
$result = $this->application->database->query($sql);
while ($result->fetch()) {
    // Process $result->fields
}
```

## Best Practices

### 1. Always Use Parameter Binding

**Never** concatenate user input directly into SQL queries:

```php
// WRONG - SQL injection risk
$result = $this->application->database->query("SELECT * FROM users WHERE email = '{$email}'");

// CORRECT - Use printf-style parameter binding
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s", $email);
$result = $this->application->database->query($sql);
```

### 2. Handle Database Errors

Always check if query operations succeed:

```php
try {
    $sql = $this->application->database->prepareQuery("INSERT INTO users (email) VALUES (%s)", $email);
    $result = $this->application->database->query($sql);
    // Handle success
} catch (Exception $e) {
    // Handle database error
    error_log("Database error: " . $e->getMessage());
    return false;
}
```

### 3. Use Transactions for Multiple Operations

For operations that need to be atomic:

```php
try {
    $this->application->database->beginTransaction();
    
    // First operation
    $sql1 = $this->application->database->prepareQuery("INSERT INTO applications (name, client_id) VALUES (%s, %s)", $name, $clientId);
    $this->application->database->query($sql1);
    
    // Second operation
    $sql2 = $this->application->database->prepareQuery("INSERT INTO user_tokens (user_id, token) VALUES (%d, %s)", $userId, $token);
    $this->application->database->query($sql2);
    
    $this->application->database->commit();
} catch (Exception $e) {
    $this->application->database->rollback();
    throw $e;
}
```

### 4. Model Loading Patterns

Follow consistent patterns for loading data in models:

```php
// Single record by ID
public function loadById($id)
{
    $sql = $this->application->database->prepareQuery("SELECT * FROM table_name WHERE id = %d", $id);
    $result = $this->application->database->query($sql);
    
    if ($result->numRows > 0) {
        foreach (array_keys($result->fields) as $key) {
            if (property_exists($this, $key)) {
                $this->$key = $result->fields[$key];
            }
        }
        return true;
    }
    return false;
}

// Multiple records
public function loadByUserId($userId)
{
    $sql = $this->application->database->prepareQuery("SELECT * FROM table_name WHERE user_id = %d", $userId);
    $result = $this->application->database->query($sql);
    
    $records = [];
    while ($result->fetch()) {
        $records[] = $result->fields;
    }
    return $records;
}
```

### 5. Validate Input Before Database Operations

```php
public function updateApplication($id, $name, $redirectUri)
{
    // Validate input
    if (empty($name) || empty($redirectUri)) {
        throw new \InvalidArgumentException('Name and redirect URI are required');
    }
    
    if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException('Invalid redirect URI');
    }
    
    // Proceed with database operation
    $sql = $this->application->database->prepareQuery("UPDATE applications SET name = %s, redirect_uri = %s WHERE id = %d", $name, $redirectUri, $id);
    $this->application->database->query($sql);
}
```

## Error Handling and Best Practices

### How database failures surface (and `throwOnError`)

The two query paths signal failure differently, and the prepared path also differs
by driver — know which one you are on:

| Path | On a SQL/connection error | Notes |
| --- | --- | --- |
| `query($sql)` / `runQuery()` (raw execution) | **Throws** `\Exception` via `setError()` | The message is `"<errno>:<text> ::: SQL QUERY: <sql>"`. Wrap it in try/catch. |
| `execute($sql, ...)` on **PostgreSQL** (prepared statements — the fluent **Query Builder** runs through here) | **Returns `false`** when the statement cannot be prepared (`pg_prepare` returns false) | Silent: a caller that ignores the return value swallows the error. |
| `execute($sql, ...)` on **MySQL** | **Throws** `mysqli_sql_exception` | Since PHP 8.1 `mysqli` defaults to `MYSQLI_REPORT_STRICT`; existing callers catch `\Exception`. |

So on PostgreSQL a failed `execute()` returns `false` and code that does not check the
return value proceeds as if a write succeeded. To make those failures loud — and to get
a single, driver-independent exception type on **both** drivers — opt into strict mode:

```php
$db = \Pramnos\Framework\Factory::getDatabase();
$db->throwOnError = true; // process-wide, opt-in — default stays false (backward compatible)

try {
    $db->getQueryBuilder()->table('accounts')->insert(['balance' => 100]);
} catch (\Pramnos\Database\QueryException $e) {
    // $e->getMessage() carries the driver error; $e->getQuery() returns the failing SQL.
    \Pramnos\Logs\Logger::log($e->getMessage(), 'billing');
    throw $e; // don't pretend the write happened
}
```

Key points:

- **Default is unchanged (BC).** `throwOnError` is `false` out of the box and does NOT
  alter the historical per-driver behaviour: PostgreSQL still returns `false` on a prepare
  failure, MySQL still throws `mysqli_sql_exception` (which existing callers catch via
  `catch (\Exception)`). Turn it on only where a silently dropped write would be a
  correctness bug (billing, migrations, anything transactional).
- **`QueryException extends \RuntimeException`** and adds `getQuery()` so you can log the
  offending SQL. It is raised only in strict mode; `query()` continues to throw its
  historical `\Exception`.
- **Driver parity in strict mode.** With `throwOnError = true`, a prepare failure becomes a
  `QueryException` on **both** drivers — the PostgreSQL `false` return and the MySQL
  `mysqli_sql_exception` are each translated — so a single `catch (QueryException)` works
  everywhere. With `throwOnError = false` the two drivers keep their (different) historical
  signals; the framework does not force them to match, precisely to preserve BC.

#### Turning it on from settings — and why a test suite should

```php
// app/config/settings.php
'database' => [
    // …
    'throwOnError' => true,
],
```

Read on construction, so it survives the singleton being reset — which a test suite does
constantly, and which is why setting the property once at bootstrap did not work.

**The framework's own fixtures set it, and finding out why is the argument for yours doing the
same.** A suite that runs one backend cannot see this asymmetry at all: on MySQL every failure
throws whatever the flag says, so the tests look thorough. Point the same tests at PostgreSQL
with the flag off and they still pass — while silently exercising the `false` branch nobody
handles.

Switching it on in this framework's lanes, with no other change, immediately surfaced:

- an inbox listing that **crashed on PostgreSQL and reported the error politely on MySQL**, from
  the same `try`/`catch` — the `catch` is what made it look handled;
- two second-factor tests that had been passing **against a missing accounts table**, asserting a
  refusal that was happening because the query failed rather than because the password was wrong;
- a characterization test that pinned the lenient path and had to be taught that the message can
  arrive as an exception.

None of those was a new bug. All three were invisible because the lenient answer to a failed
query — `false`, or `0` from `count()` — is a value a screen will happily print. *You have no
messages* is a sentence; a missing table is not.

**Where to put it.** In a test environment, always. In production, wherever a silently dropped
write would be a correctness bug — and know that turning it on converts existing silent wrong
answers into exceptions, which is the point and also a deployment risk worth taking deliberately.

### `error_text` is the last error, not the first

`$db->error_text` (and `getError()`, which falls back to it) describes **the statement
that just ran**. Read it immediately after the call that returned `false`:

```php
if ($db->execute($sql) === false) {
    \Pramnos\Logs\Logger::error('query failed: ' . $db->error_text);
}
```

A statement that succeeds clears it, so an empty `error_text` means "nothing went wrong
here" rather than "nothing has gone wrong yet".

> **Corrected 2026-08-20.** It used to be the *first* error of the request. The captures
> in `prepare()` are guarded with `empty($this->error_text)` — deliberately, because the
> PostgreSQL retry path runs `DEALLOCATE` and overwrites `pg_last_error()` — but nothing
> reset the property between statements, so the first failure in a request answered for
> every failure after it. `currentQuery`, which `setError()` appends to the message it
> throws, was set by `query()` alone, so anything raised from a prepared statement quoted
> whichever unprepared query had run last.
>
> Together they produced messages naming a real error and a real query that belonged to
> two different statements minutes apart — worse than no message, because it sends the
> reader to the wrong file. A DevPanel query failing on a PostgreSQL syntax error was
> reported as a bind-parameter mismatch in the session INSERT from application boot.

### Proper Error Handling for Database Operations

Always wrap database operations in try-catch blocks to handle potential errors gracefully:

```php
// Single record with error handling
private function getUserData(int $userId): ?array
{
    try {
        $sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
        $result = $this->application->database->query($sql);
        
        if ($result && $result->numRows > 0) {
            return $result->fields;
        }
        return null;
    } catch (\Exception $e) {
        error_log("Error getting user data: " . $e->getMessage());
        return null;
    }
}

// Multiple records with error handling
private function getUserApplications(int $userId): array
{
    try {
        $sql = $this->application->database->prepareQuery("
            SELECT * FROM applications 
            WHERE user_id = %d 
            ORDER BY created_at DESC
        ", $userId);
        
        $result = $this->application->database->query($sql);
        $applications = [];
        while ($result->fetch()) {
            $applications[] = $result->fields;
        }
        return $applications;
    } catch (\Exception $e) {
        error_log("Error getting user applications: " . $e->getMessage());
        return [];
    }
}

// Insert/Update/Delete with error handling
private function createApplication(array $data): bool
{
    try {
        $sql = $this->application->database->prepareQuery("
            INSERT INTO applications (name, client_id, user_id, created_at) 
            VALUES (%s, %s, %d, NOW())
        ", $data['name'], $data['client_id'], $data['user_id']);
        
        $this->application->database->query($sql);
        return true;
    } catch (\Exception $e) {
        error_log("Error creating application: " . $e->getMessage());
        return false;
    }
}
```

### Common Validation Patterns

Always validate that database results exist before accessing them:

```php
// ✅ CORRECT - Validate result before access
$sql = $this->application->database->prepareQuery("SELECT name FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);

if ($result && $result->numRows > 0) {
    $name = $result->fields['name'];
} else {
    $name = 'Unknown User';
}

// ❌ WRONG - Direct access without validation
$name = $result->fields['name']; // Could cause errors if query fails
```

### Return Type Consistency

Methods should have consistent return types with proper defaults:

```php
// Return array for multiple records (always return array, never null)
private function getItems(): array
{
    try {
        // ... database query ...
        return $items;
    } catch (\Exception $e) {
        return []; // Always return empty array on error
    }
}

// Return nullable for single records
private function getItem(int $id): ?array
{
    try {
        // ... database query ...
        return $item;
    } catch (\Exception $e) {
        return null; // Return null on error for single records
    }
}
```

## Common Mistakes to Avoid

1. **Don't use PDO methods directly** - Always use the Pramnos database API
2. **Don't skip parameter binding** - Even for "safe" values, always use printf-style formatting  
3. **Don't use `?` placeholders** - Pramnos uses printf-style (`%s`, `%d`, etc.)
4. **Don't mix database access patterns** - Be consistent throughout your application

## Database Connection Access

In different contexts, access the database through:

- **Models**: `$this->application->database` (passed in constructor)
- **Controllers**: `$this->application->database` (available as property)
- **Repositories**: `$this->application->database` (injected via constructor)
- **Application**: `$this->application->database` (global application instance)

### Which server am I talking to?

`$database->type` holds the *configured* engine — `'mysql'` or `'postgresql'`. It is a
configuration value, not an observation: **MariaDB is configured as `'mysql'`** and always has
been, because it speaks MySQL's wire protocol and is reached through the same driver.

Two additive accessors ask the live connection instead of the configuration file:

```php
$database->getServerVersion(); // raw, e.g. "10.11.6-MariaDB-1:10.11.6+maria~ubu2204"
$database->isMariaDB();        // true only for a MariaDB server
```

Both degrade safely: with no live connection `getServerVersion()` returns `''` **without
opening one**, and `isMariaDB()` answers `false`. The result is cached per connection and
re-detected by `connect()`.

For anything beyond identity, go through `DatabaseCapabilities` — it turns
(engine, flavor, version) into "can it?" answers:

```php
$caps = new \Pramnos\Database\DatabaseCapabilities($database);

$caps->isMySQL();    // true on MySQL *and* MariaDB — "the MySQL family"
$caps->isMariaDB();  // narrows it to MariaDB specifically
$caps->getVersion(); // "10.11.6" / "8.0.36" / "14.10" — normalised for version_compare()
$caps->atLeast('10.5');

$caps->hasSequences();        // PostgreSQL, MariaDB >= 10.3
$caps->hasReturning();        // PostgreSQL, MariaDB >= 10.5
$caps->hasNativeJson();       // PostgreSQL, MySQL >= 5.7.8 — *not* MariaDB
$caps->hasCheckConstraints(); // PostgreSQL, MariaDB >= 10.2, MySQL >= 8.0.16
```

`hasNativeJson()` is the one worth reading twice: MariaDB accepts the `JSON` keyword, but the
column is really `LONGTEXT` with a `CHECK (json_valid(...))` constraint — not a distinct type
and not binary storage. Code that only needs to *store* JSON is fine either way; code that
relies on the type itself must check.

See the [Schema Builder guide](Pramnos_Schema_Builder_Guide.md#capability-conditional-ddl) for
the full constant table and capability-conditional DDL.

## Error Handling

### Check Query Results

```php
$sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE id = %d", $id);
$result = $this->application->database->query($sql);

if ($result->numRows == 0) {
    throw new \Exception('Application not found');
}

$application = $result->fields;
```

### Transaction Support

```php
try {
    $this->application->database->beginTransaction();
    
    // Multiple database operations
    $sql1 = $this->application->database->prepareQuery("INSERT INTO applications (client_id, name) VALUES (%s, %s)", $clientId, $name);
    $this->application->database->query($sql1);
    
    $sql2 = $this->application->database->prepareQuery("INSERT INTO application_permissions (app_id, permission) VALUES (%d, %s)", $appId, $permission);
    $this->application->database->query($sql2);
    
    $this->application->database->commit();
} catch (\Exception $e) {
    $this->application->database->rollback();
    throw $e;
}
```

## Summary

## Advanced Database Features

### Database Migrations

The Pramnos framework includes a migration system for version-controlled database schema changes:

#### Creating Migrations

```php
<?php
namespace MyApp\Migrations;

class CreateUsersTable extends \Pramnos\Database\Migration
{
    public $version = '1.0.1';
    public $description = 'Create users table with authentication fields';
    public $autoExecute = true;

    public function up(): void
    {
        $sql = "CREATE TABLE `#PREFIX#users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `email` varchar(255) NOT NULL,
            `password_hash` varchar(255) NOT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->addQuery($sql);
        $this->executeQueries();
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `#PREFIX#users`";
        $this->addQuery($sql);
        $this->executeQueries();
    }
}
```

#### Multi-Database Support

```php
// PostgreSQL-specific migrations
class CreateUsersTablePostgreSQL extends \Pramnos\Database\Migration
{
    public function up(): void
    {
        if ($this->application->database->type === 'postgresql') {
            $sql = 'CREATE TABLE "#PREFIX#users" (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )';
            
            $this->addQuery($sql);
            $this->executeQueries();
        }
    }
}
```

### Advanced Query Patterns

#### Complex Joins and Subqueries

```php
// Complex join with subquery for analytics
$sql = $this->application->database->prepareQuery("
    SELECT 
        u.id,
        u.username,
        u.email,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total), 0) as total_spent,
        recent_orders.last_order_date
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    LEFT JOIN (
        SELECT 
            user_id, 
            MAX(created_at) as last_order_date
        FROM orders 
        WHERE created_at > %s
        GROUP BY user_id
    ) recent_orders ON u.id = recent_orders.user_id
    WHERE u.created_at > %s
    GROUP BY u.id, u.username, u.email, recent_orders.last_order_date
    HAVING COUNT(o.id) > %d
    ORDER BY total_spent DESC
    LIMIT %d",
    date('Y-m-d', strtotime('-30 days')), // Recent orders filter
    date('Y-m-d', strtotime('-1 year')), // User creation filter
    5, // Minimum order count
    50  // Limit results
);

$result = $this->application->database->query($sql);
$analyticsData = [];
while ($result->fetch()) {
    $analyticsData[] = $result->fields;
}
```

#### Geospatial Queries (PostgreSQL)

```php
// PostGIS spatial queries
if ($this->application->database->type === 'postgresql') {
    $sql = $this->application->database->prepareQuery("
        SELECT 
            id,
            name,
            ST_AsText(location) as location_text,
            ST_Distance(
                location, 
                ST_SetSRID(ST_MakePoint(%s, %s), 4326)
            ) as distance_meters
        FROM stores 
        WHERE ST_DWithin(
            location, 
            ST_SetSRID(ST_MakePoint(%s, %s), 4326), 
            %d
        )
        ORDER BY distance_meters
        LIMIT %d",
        $longitude, $latitude, // Target point
        $longitude, $latitude, // Search center
        5000, // 5km radius in meters
        10 // Limit results
    );
    
    $result = $this->application->database->query($sql);
}
```

### Transaction Management

#### Transactions announce themselves

`commitTransaction()` and `rollbackTransaction()` fire an event, so code that has
been holding work until the outcome is known can act on it without every call site
remembering to tell it:

```php
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;

Event::listen(ChangeFeed::EVENT_COMMITTED,   fn() => /* rows are durable */);
Event::listen(ChangeFeed::EVENT_ROLLED_BACK, fn() => /* drop what you held */);
```

| Event | Name | When |
|---|---|---|
| `ChangeFeed::EVENT_COMMITTED` | `database.transaction.committed` | after a **successful** `COMMIT` |
| `ChangeFeed::EVENT_ROLLED_BACK` | `database.transaction.rolledback` | after `ROLLBACK`, **whether or not it succeeded** |

The asymmetry is deliberate. A `COMMIT` that failed leaves rows that may not be
there, so nothing is announced. A `ROLLBACK` that failed is still a reason to drop
held work: "I could not undo that" is not grounds for announcing it, because a
listener that has written an audit row cannot take it back either.

By the time a listener runs, `inTransaction()` already answers `false`. A listener
that goes on to write or publish is therefore outside the transaction it came from,
which is what stops a re-entrant listener from buffering into a transaction that has
ended.

The names are for what happened, not for who listens: the [change
feed](Pramnos_Change_Feed_Guide.md) uses them to release the model changes it held,
and cache invalidation or an outbox wants the same seam.

!!! warning "One flag, not a depth counter"
    `inTransaction()` tracks state set by `startTransaction()` /
    `commitTransaction()` / `rollbackTransaction()`. It does not count depth, so a
    nested `startTransaction()` followed by the inner commit fires the committed
    event while the outer transaction is still open. And a raw `BEGIN` issued
    through `query()` is not tracked at all.

#### Advanced Transaction Patterns

```php
class OrderProcessor 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function processOrder($orderData, $orderItems)
    {
        try {
            // Start transaction
            $this->database->startTransaction();
            
            // Create order record
            $sql = $this->database->prepareQuery("
                INSERT INTO orders (user_id, total, status, created_at) 
                VALUES (%d, %s, %s, %s)",
                $orderData['user_id'],
                $orderData['total'],
                'pending',
                date('Y-m-d H:i:s')
            );
            $this->database->query($sql);
            $orderId = $this->database->getLastInsertId();
            
            // Add order items
            foreach ($orderItems as $item) {
                $sql = $this->database->prepareQuery("
                    INSERT INTO order_items (order_id, product_id, quantity, price) 
                    VALUES (%d, %d, %d, %s)",
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price']
                );
                $this->database->query($sql);
                
                // Update product inventory
                $sql = $this->database->prepareQuery("
                    UPDATE products 
                    SET stock_quantity = stock_quantity - %d 
                    WHERE id = %d AND stock_quantity >= %d",
                    $item['quantity'],
                    $item['product_id'],
                    $item['quantity']
                );
                $result = $this->database->query($sql);
                
                // Check if inventory update affected any rows
                if ($result->getAffectedRows() === 0) {
                    throw new \Exception("Insufficient inventory for product " . $item['product_id']);
                }
            }
            
            // Update user's order history
            $sql = $this->database->prepareQuery("
                UPDATE users 
                SET total_orders = total_orders + 1, last_order_date = %s 
                WHERE id = %d",
                date('Y-m-d H:i:s'),
                $orderData['user_id']
            );
            $this->database->query($sql);
            
            // Commit transaction
            $this->database->commitTransaction();
            
            return ['success' => true, 'order_id' => $orderId];
            
        } catch (\Exception $e) {
            // Rollback on any error
            $this->database->rollbackTransaction();
            \Pramnos\Logs\Logger::logError("Order processing failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

#### Nested Transactions and Savepoints

```php
class AdvancedTransactionManager 
{
    private $database;
    private $savepointCounter = 0;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function createSavepoint($name = null)
    {
        if ($name === null) {
            $name = 'sp_' . (++$this->savepointCounter);
        }
        
        if ($this->database->type === 'postgresql') {
            $this->database->query("SAVEPOINT {$name}");
        } else {
            $this->database->query("SAVEPOINT {$name}");
        }
        
        return $name;
    }
    
    public function rollbackToSavepoint($name)
    {
        if ($this->database->type === 'postgresql') {
            $this->database->query("ROLLBACK TO SAVEPOINT {$name}");
        } else {
            $this->database->query("ROLLBACK TO SAVEPOINT {$name}");
        }
    }
    
    public function releaseSavepoint($name)
    {
        if ($this->database->type === 'postgresql') {
            $this->database->query("RELEASE SAVEPOINT {$name}");
        } else {
            $this->database->query("RELEASE SAVEPOINT {$name}");
        }
    }
}
```

### Performance Optimization

#### Query Optimization and Analysis

```php
class QueryOptimizer 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function analyzeQuery($sql)
    {
        $explainSql = "EXPLAIN " . $sql;
        $result = $this->database->query($explainSql);
        
        $analysis = [];
        while ($result->fetch()) {
            $analysis[] = $result->fields;
        }
        
        return $analysis;
    }
    
    public function getSlowQueries($limit = 10)
    {
        if ($this->database->type === 'mysql') {
            $sql = "
                SELECT 
                    sql_text,
                    exec_count,
                    avg_timer_wait/1000000000000 as avg_time_seconds,
                    sum_timer_wait/1000000000000 as total_time_seconds
                FROM performance_schema.events_statements_summary_by_digest 
                ORDER BY avg_timer_wait DESC 
                LIMIT " . (int)$limit;
            
            $result = $this->database->query($sql);
            $slowQueries = [];
            while ($result->fetch()) {
                $slowQueries[] = $result->fields;
            }
            return $slowQueries;
        }
        
        return [];
    }
}
```

#### Connection Pool Management

```php
class ConnectionPoolManager 
{
    private static $connections = [];
    private static $maxConnections = 10;
    private static $currentConnections = 0;
    
    public static function getConnection($config)
    {
        $key = md5(serialize($config));
        
        if (isset(self::$connections[$key]) && self::$connections[$key]->connected) {
            return self::$connections[$key];
        }
        
        if (self::$currentConnections >= self::$maxConnections) {
            throw new \Exception("Connection pool exhausted");
        }
        
        $database = new \Pramnos\Database\Database($config);
        $database->connect();
        
        self::$connections[$key] = $database;
        self::$currentConnections++;
        
        return $database;
    }
    
    public static function releaseConnection($database)
    {
        // In a real implementation, you might pool connections
        // rather than closing them immediately
        $database->close();
        self::$currentConnections--;
    }
}
```

### Adjacency List Implementation

The framework includes specialized support for hierarchical data:

```php
$database = \Pramnos\Framework\Factory::getDatabase();
$categoryTree = new \Pramnos\Database\Adjacencylist(
    $database,
    'categories',     // table name
    'id',            // id field
    'parent_id',     // parent field
    'name'           // title field
);

// Get all categories as hierarchical array
$categories = $categoryTree->getArray();

// Get path to specific category
$categoryPath = $categoryTree->getPathAsArray(15); // category ID 15

// Set custom separator for path display
$categoryTree->separator = ' > ';
$fullPath = $categoryTree->getArray(null, 15);
```

### Advanced Model Patterns

#### Model Caching and Column Information

```php
class AdvancedModel extends \Pramnos\Application\Model
{
    // Column cache for performance
    private static $columnCache = [];
    
    public function getAvailableFields()
    {
        $database = \Pramnos\Database\Database::getInstance();
        $tableName = $this->getFullTableName();
        
        if (isset(self::$columnCache[$tableName])) {
            return array_column(self::$columnCache[$tableName], 'Field');
        }
        
        $fields = [];
        if ($database->type === 'postgresql') {
            $schema = $this->_dbschema ?? $database->schema;
            $sql = "SELECT column_name as \"Field\", data_type as \"Type\"
                    FROM information_schema.columns 
                    WHERE table_schema = '{$schema}' 
                    AND table_name = '" . str_replace('#PREFIX#', $database->prefix, $this->_dbtable) . "'";
        } else {
            $sql = "SHOW COLUMNS FROM `{$tableName}`";
        }
        
        $result = $database->query($sql);
        while ($result->fetch()) {
            $fields[] = $result->fields['Field'];
            if (!isset(self::$columnCache[$tableName])) {
                self::$columnCache[$tableName] = [];
            }
            self::$columnCache[$tableName][] = $result->fields;
        }
        
        return $fields;
    }
    
    public function getDynamicList($filter = '', $order = '', $page = 0, $itemsPerPage = 50, 
                                  $globalSearch = '', $fieldSearches = [])
    {
        $database = \Pramnos\Database\Database::getInstance();
        $availableFields = $this->getAvailableFields();
        
        // Build dynamic search conditions
        $searchConditions = $this->buildSearchConditions($availableFields, $globalSearch, $fieldSearches);
        
        // Combine with existing filter
        $finalFilter = $this->combineFilters($filter, $searchConditions);
        
        // Validate order fields against available fields
        $validatedOrder = $this->validateOrderFields($order, $availableFields);
        
        return $this->getList($finalFilter, $validatedOrder, $page, $itemsPerPage);
    }
}
```

### Error Handling and Resilience

#### Comprehensive Error Handling

```php
class ResilientDatabaseOperation 
{
    private $database;
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function executeWithRetry($sql, $params = [])
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                if (!$this->database->connected) {
                    $this->database->connect();
                }
                
                if (!empty($params)) {
                    $preparedSql = $this->database->prepareQuery($sql, ...$params);
                } else {
                    $preparedSql = $sql;
                }
                
                return $this->database->query($preparedSql);
                
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;
                
                \Pramnos\Logs\Logger::logError(
                    "Database query attempt {$attempt} failed: " . $e->getMessage()
                );
                
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt); // Exponential backoff
                    $this->database->refresh(); // Reconnect
                }
            }
        }
        
        throw new \Exception(
            "Database operation failed after {$this->maxRetries} attempts. Last error: " . 
            $lastException->getMessage()
        );
    }
    
    public function executeInTransaction($operations)
    {
        try {
            $this->database->startTransaction();
            
            foreach ($operations as $operation) {
                if (is_callable($operation)) {
                    $operation($this->database);
                } elseif (is_array($operation) && isset($operation['sql'])) {
                    $this->executeWithRetry($operation['sql'], $operation['params'] ?? []);
                }
            }
            
            $this->database->commitTransaction();
            return true;
            
        } catch (\Exception $e) {
            $this->database->rollbackTransaction();
            \Pramnos\Logs\Logger::logError("Transaction failed: " . $e->getMessage());
            throw $e;
        }
    }
}
```

### Database-Specific Features

#### PostgreSQL Advanced Features

```php
class PostgreSQLFeatures 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function useJSONQueries($table, $jsonColumn, $jsonPath, $value)
    {
        if ($this->database->type !== 'postgresql') {
            throw new \Exception("JSON queries are only supported in PostgreSQL");
        }
        
        $sql = $this->database->prepareQuery("
            SELECT * FROM \"{$table}\" 
            WHERE \"{$jsonColumn}\" ->> %s = %s",
            $jsonPath,
            $value
        );
        
        return $this->database->query($sql);
    }
    
    public function useArrayColumns($table, $arrayColumn, $searchValue)
    {
        $sql = $this->database->prepareQuery("
            SELECT * FROM \"{$table}\" 
            WHERE %s = ANY(\"{$arrayColumn}\")",
            $searchValue
        );
        
        return $this->database->query($sql);
    }
    
    public function useFullTextSearch($table, $textColumn, $searchTerm)
    {
        $sql = $this->database->prepareQuery("
            SELECT *, ts_rank(to_tsvector('english', \"{$textColumn}\"), plainto_tsquery('english', %s)) as rank
            FROM \"{$table}\" 
            WHERE to_tsvector('english', \"{$textColumn}\") @@ plainto_tsquery('english', %s)
            ORDER BY rank DESC",
            $searchTerm,
            $searchTerm
        );
        
        return $this->database->query($sql);
    }
}
```

## Database Security Best Practices

### SQL Injection Prevention

Always use the framework's parameter binding - never concatenate user input:

```php
// ✅ SECURE - Using parameter binding
$sql = $this->application->database->prepareQuery(
    "SELECT * FROM users WHERE email = %s AND role = %s AND active = %d",
    $userEmail,
    $userRole,
    1
);

// ❌ VULNERABLE - Direct concatenation
$sql = "SELECT * FROM users WHERE email = '" . $userEmail . "'"; // NEVER DO THIS!
```

### Sensitive Data Handling

```php
class SecureDataHandler 
{
    public function hashSensitiveData($data, $salt = null)
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(32));
        }
        
        return [
            'hash' => hash_pbkdf2('sha256', $data, $salt, 10000),
            'salt' => $salt
        ];
    }
    
    public function insertUserWithHashedPassword($userData)
    {
        $passwordData = $this->hashSensitiveData($userData['password']);
        
        $sql = $this->application->database->prepareQuery("
            INSERT INTO users (username, email, password_hash, password_salt, created_at) 
            VALUES (%s, %s, %s, %s, %s)",
            $userData['username'],
            $userData['email'],
            $passwordData['hash'],
            $passwordData['salt'],
            date('Y-m-d H:i:s')
        );
        
        return $this->application->database->query($sql);
    }
}
```

The Pramnos Database API provides a comprehensive, secure, and flexible foundation for all database operations, supporting both MySQL and PostgreSQL with advanced features for modern web applications.

## Session time zone (`database.timezone`)

Set `database.timezone` in the database settings to have the framework apply a
session time zone on connect, so `NOW()` and timestamp rendering match the app's
zone:

```php
// app settings
'database' => [
    // ... hostname, database, user, password ...
    'timezone' => 'Europe/Athens', // or 'UTC'
],
```

On connect the framework issues `SET TIME ZONE '<tz>'` (PostgreSQL) /
`SET time_zone = '<tz>'` (MySQL) on **each** connection (write and read replica).
The setting is **unset by default** — when absent, no SET is issued and the server
default is left untouched, so existing applications are unaffected.
