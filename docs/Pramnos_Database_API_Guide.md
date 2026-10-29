---
use_cases:
  - Reading from or writing to the database from a controller or model
  - Handling a query result set
  - Deciding between raw SQL and the fluent query builder
  - Diagnosing a query that returns nothing or the wrong rows
  - Holding a database handle in a long-lived worker or daemon
  - Sending reads to a replica and writes to a primary
  - Reading a table's columns, types or foreign keys from code
  - Running an application's MySQL-written SQL on PostgreSQL without rewriting it
  - Finding the SQL that would mean something else on PostgreSQL than on MySQL
  - Writing binary data to a blob or bytea column, and reading it back as bytes
  - Searching inside a migrated blob/bytea column for a substring, when LIKE matches nothing
---

# Pramnos Database API Guide

This guide explains how to read from and write to the database in a Pramnos application: getting
the handle, the query builder, the lower-level raw-SQL paths, result handling, errors,
transactions, replicas and the session settings the framework applies for you.

## Overview

There are three ways to send a statement, in order of preference:

| Path | Use it for | Placeholders |
|---|---|---|
| `queryBuilder()` | Almost everything. The builder knows the dialect: it quotes identifiers per driver, resolves `authserver.x` to a schema on PostgreSQL and a prefixed table on MySQL, and binds values instead of interpolating them. | none — values are arguments; `?` inside `whereRaw()` |
| `preparedQuery($sql, $bindings)` | Hand-written SQL the builder cannot express (driver-specific functions, `RETURNING`, `DISTINCT ON`, stored functions). Values are bound by the driver. | `:name` or `?` |
| `prepareQuery($sql, ...$args)` + `query($sql)` | The lower-level path: values are escaped and interpolated into the SQL text. Existing code uses it widely and it remains supported. | `%s`, `%d` |

```php
$db = $this->application->database;

// Query builder
$users = $db->queryBuilder()
    ->from('#PREFIX#users')
    ->where('active', 1)
    ->get();

// Raw SQL, escaped and interpolated
$sql = $db->prepareQuery("SELECT * FROM #PREFIX#users WHERE active = %d", 1);
$result = $db->query($sql);
```

Framework code that you modify should move raw SQL to the builder; where a statement cannot be
expressed there (DDL, `information_schema` introspection, TimescaleDB functions), leave a one-line
comment saying why. See the [Query Builder guide](Pramnos_QueryBuilder_Guide.md) for the full API.

## Getting the database handle

| Where you are | Handle |
|---|---|
| Controller | `$this->application->database` (`Controller::$application` is public) |
| Model | `\Pramnos\Database\Database::getInstance()` — a model holds its controller in `$this->controller`, not an `application` property, so `$this->application` is `null` there |
| Anywhere else | `\Pramnos\Database\Database::getInstance()` or `\Pramnos\Framework\Factory::getDatabase()` |
| Migration | `$this->application->database`, or `$this->schema()` for DDL |

In a model, `$this->controller->application->database` also works when the model was built with a
controller.

## The query builder

```php
$db = $this->application->database;

// SELECT with conditions and ordering
$users = $db->queryBuilder()
    ->from('#PREFIX#users')
    ->where('status', 1)
    ->where('role', 'admin')
    ->orderBy('last_login', 'desc')
    ->limit(10)
    ->get();

// JOIN
$applications = $db->queryBuilder()
    ->select('a.*', 'u.username as owner_name')
    ->from('applications a')
    ->join('users u', 'u.id', '=', 'a.user_id')
    ->where('a.status', 'active')
    ->get();

// INSERT — returns the execution result, not the new id
$db->queryBuilder()
    ->table('#PREFIX#users')
    ->insert([
        'username' => 'newuser',
        'email'    => 'user@example.com',
        'status'   => 1,
    ]);
$newId = $db->getInsertId();
// On PostgreSQL this is the id from the inserted table's own serial or identity sequence, not
// LASTVAL(). TimescaleDB takes a sequence value of its own whenever an insert opens a chunk,
// and LASTVAL() would answer that one.

// UPDATE and DELETE
$db->queryBuilder()->table('applications')
    ->where('id', $id)->where('user_id', $userId)
    ->update(['name' => $name, 'redirect_uri' => $redirectUri]);

$db->queryBuilder()->table('user_tokens')
    ->where('expires_at', '<', date('Y-m-d H:i:s'))
    ->delete();
```

`get()` and `first()` return a `Result` (see [Result handling](#result-handling)); `getAll()`
returns an array of rows, `count()` an integer and `value($column)` a single value.

On PostgreSQL, `->returning('id')` before `insert()` makes the statement return the new key.

### Raw expressions

For database-specific functions (PostGIS, native MySQL functions), use `whereRaw()` with `?`
placeholders and a bindings array, or `raw()` for a value:

```php
$locations = $db->queryBuilder()
    ->from('locations')
    ->whereRaw("ST_Distance(geom, ST_MakePoint(?, ?)) < 1000", [23.7, 37.9])
    ->get();
```

## Raw SQL with `prepareQuery()`

`prepareQuery()` escapes each argument through the driver and substitutes it into the SQL text;
`query()` then runs the string.

```php
$sql = $db->prepareQuery(
    "SELECT * FROM users WHERE email = %s AND status = %d",
    $email, $status
);
$result = $db->query($sql);
```

### Format specifiers

- `%s` — a string. Leave it unquoted: the method adds the quotes.
- `%d` — an integer.
- `%%` — a literal `%`.

`?` and `:name` are **not** placeholders for `prepareQuery()` — they belong to
`preparedQuery()` and the builder. A `null` argument becomes SQL `null`, and `= null` after
`WHERE` becomes `IS NULL`.

`#PREFIX#` is replaced by the configured table prefix and `#CP#` by the controller prefix. On
PostgreSQL, backticks become double quotes and a configured schema is put in front of
`#PREFIX#` names.

A PHP `bool` given to `execute()`, the query builder or `selectOne()` is bound as `'1'` or `'0'`
on PostgreSQL. A `boolean` column reads that as it reads `'true'`, and a `smallint` takes it too,
which is what a MySQL `tinyint(1)` flag becomes when the table is moved.

### A literal `%` in the SQL, and `LIKE`

`prepareQuery()` is `sprintf` underneath, so a `%` in the query string is a format directive unless
you say otherwise. Two rules follow.

**With arguments, write `%%` for a literal percent.** Every `LIKE` needs it:

```php
// Wildcards, with a bound argument beside them
$sql = $db->prepareQuery(
    "SELECT * FROM `#PREFIX#users` WHERE `userid` = %d AND `username` LIKE 'a%%'",
    7
);
```

**With no arguments, a single `%` is left alone.** With nothing to substitute, `vsprintf` is not
called, so this is safe:

```php
$db->prepareQuery("SELECT * FROM `#PREFIX#queueitems` WHERE `payload` LIKE '%ApplyStart%'");
```

Running it through `vsprintf` would raise `ValueError: Missing padding character` — `sprintf`
reads the trailing `%'` as «pad with the next character» and there is no next character.

`%%` still collapses to `%` on that path, so a query holding `DATE_FORMAT(\`created\`, '%%c')`
with no arguments reaches the database as `'%c'`.

A `%s` or `%d` with no argument passes through to the database instead of raising, which is a
malformed query rather than a fatal. If you write a placeholder, pass its value.

### Seeing what actually ran: `getCurrentQuery()`

`getCurrentQuery()` returns the SQL this connection last ran — after `prepareQuery()`'s
substitution or the query builder's own assembly, not the caller's template:

```php
$db->query($db->prepareQuery("SELECT * FROM users WHERE email = %s", $email));
$db->getCurrentQuery(); // "SELECT * FROM users WHERE email = 'someone@example.com'"
```

For a test asserting that a dynamically built `WHERE` clause escaped its arguments correctly,
or any other after-the-fact check — it is not meant to be parsed or relied on for program logic.
Empty string on a connection that has run nothing yet.

## Real prepared statements: `preparedQuery()`

`preparedQuery()` runs the statement through the driver's own prepared-statement engine
(`pg_prepare`/`pg_execute`, or mysqli), so values are bound as parameters and never become part
of the SQL text. It takes the SQL verbatim — PostgreSQL `RETURNING`, `ON CONFLICT`,
`DISTINCT ON` and stored-function calls all survive untouched, which also makes it the bridge for
code moving off a raw `\PDO` handle.

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
taking a page down is a deliberate pattern — but it makes a broken query and an
empty table look the same. Turn on `throwOnError` while developing so the
difference is loud.

**With an empty bindings array** the statement goes through `query()` instead, and a failure
**throws** `\Exception` as `query()` does — there is nothing to prepare.

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
  a comment is read: a placeholder written there is prose, and **an apostrophe
  there is just an apostrophe**.

```php
// Fine. The possessive in the comment is prose, and :minutes still binds.
$db->preparedQuery(
    "SELECT /* a JOIN's clause */ (:minutes || ' minutes')::interval AS v",
    ['minutes' => 15]
);
```

If an apostrophe in a comment were read as the start of a string literal, every placeholder
after it would stay unbound, the statement would fail, and a caller doing `?: []` would show an
empty page with no error anywhere.

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

## Result handling

`query()`, `preparedQuery()`, `get()` and `first()` return a `Result`. Its current row is
`$result->fields`, an associative array of column => value; `$result->numRows` is the row count.

### Single record

The first row is already loaded — no `fetch()` and no `[0]` index:

```php
$user = $db->queryBuilder()->from('users')->where('id', $userId)->first();

if ($user->numRows > 0) {
    $userData = $user->fields;          // column => value
    $userName = $user->fields['name'];
}

// COUNT
$count = $db->queryBuilder()->from('users')->count();

// Raw SQL equivalent
$result = $db->query($db->prepareQuery("SELECT COUNT(*) AS total FROM users"));
$total  = $result->fields['total'];     // not $result->fields[0]['total']
```

### Multiple records

Iterate with `fetch()`, or take everything with `fetchAll()`:

```php
$result = $db->queryBuilder()
    ->from('applications')
    ->where('user_id', $userId)
    ->orderBy('created_at', 'desc')
    ->get();

$applications = [];
while ($result->fetch()) {
    $applications[] = $result->fields;
}

// or
$applications = $db->queryBuilder()->from('applications')->where('user_id', $userId)->getAll();
```

### Key points

- **Single records**: check `$result->numRows > 0`, then read `$result->fields` directly.
- **Multiple records**: `while ($result->fetch())`, or `getAll()` / `fetchAll()`.
- `$result->fields[0]` is never a row — `fields` is the current row, keyed by column name.
- `getAffectedRows()` on the result of a write tells you how many rows it touched.
- On PostgreSQL, values come back as PHP types by column type (`int4`/`int8` → `int`,
  `numeric`/`float8` → `float`, `bool` → `bool`, `bytea` → bytes). The type names are in
  `$result->columnTypes`. They are read from the type OID that comes with the result, through
  `Database::PG_BUILTIN_TYPES`, and not from `pg_field_type()`. The first `pg_field_type()` in
  a PHP process makes PHP run `select oid,typname from pg_type`, which reads every row of
  `pg_type`. On TimescaleDB that table has a row for every chunk, so this costs tens of
  thousands of rows on every request. Only a column of a type outside the built-in list (an
  enum, a domain, an extension's type) still asks the server.

## What a datatable search actually looks for

`_getApiList()` (through `ApiListQuery`) builds its `WHERE` from the search terms a datatable
posts, and four of the rules are not obvious from the outside. Worth knowing before deciding a
filter is broken.

**A Greek word is searched without its final sigma.** `Γιάννης` becomes `%Γιάννη%`. Greek inflects
the ending — a visitor types the nominative and the row holds the genitive — so dropping the last
`ς`/`σ` is the cheapest stemming there is and it is why the search box feels like it works. Only the
*ending*: `Κώστας` becomes `Κώστα%`, not `Κώτα%`.

**On PostgreSQL, a Greek term is compared through `unaccent()`.**

```sql
unaccent(CAST(a."name" AS TEXT)) ILIKE unaccent('%Γιάννη%')
```

So `Γιάννης`, `ΓΙΑΝΝΗΣ` and `Γιαννης` all find each other — a form filled in caps and an import that
lost its accents are both ordinary. A term with no Greek in it stays a plain `ILIKE`.

**A numeric column is matched with `=`, not `LIKE`.** Filtering an integer column by `9` with
`LIKE '%9%'` returns 9, 19, 29, 90 and 1999, which reads as a broken filter rather than a broad one.
The column's type comes from `Model::$columnCache`, so this only applies where the model has read
the schema — a joined table whose alias cannot be resolved falls back to `LIKE`.

**A term that already contains `%` is used as it is.** `Γιαν%` is left anchored rather than wrapped
again, because the caller has said where the wildcard goes.

And one rule that is a boundary rather than a convenience: **a search field that is not in the field
list produces no condition at all.** The names arrive from the query string, so the list is what
keeps a request parameter out of the statement. A filter that silently does nothing on a joined
listing is usually the qualified-name mapping — send `title` and the list may hold `posts.title`,
which the builder maps, but only for a name that is there.

## Bytes: `Binary`, and reading a `bytea`

PostgreSQL takes a bound string as text: bytes that are not UTF-8 are refused, and a backslash is
read as an escape. At the moment of binding, nothing says the column is `bytea`, so the value
says it:

All three run on the same binding path and treat `Binary` the same way — the builder is the
one to reach for in new code; `execute()` and `prepareQuery()`/`query()` are shown so the rest
of this section can explain how each reads the value back:

```php
use Pramnos\Database\Binary;

$db->queryBuilder()->table('files')->insert(['name' => $name, 'body' => new Binary($bytes)]);
$db->execute('INSERT INTO files (name, body) VALUES (%s, %s)', ...[$name, new Binary($bytes)]);
$db->query($db->prepareQuery('INSERT INTO files (name, body) VALUES (%s, %s)', $name, new Binary($bytes)));
```

On PostgreSQL it is bound with `pg_escape_bytea()`, and on MySQL as the bytes themselves. In
`prepareQuery()`, where the value is written between quotes, it is a `bytea`'s hex form
(`'\x00ff…'`) on PostgreSQL and MySQL's escape of the bytes. A `WHERE id = %s` comparison of a
binary column takes `Binary` the same way. Read
back through `query()`, `execute()` or the builder, a `bytea` is the bytes again, as a MySQL
`blob` always was. PostgreSQL itself returns hex text (`\x00ff…`), and the result decodes it.

### Searching inside a `bytea` that is really text: `whereLikeText()`

A MySQL `blob` moved by `db:migrate-from-mysql` without `--blob-as-text` (see the
[Moving from MySQL guide](Pramnos_Moving_From_MySQL_Guide.md#text-kept-in-a-blob))
arrives as `bytea` even when every value in it is text — a `serialize()` string, most often.
`whereLike()` casts the column with a bare `::text`, which on a real text column is a no-op,
but on `bytea` is PostgreSQL's *hex output format* (`\x4142…`) — so a pattern that is visibly
inside the stored value never matches:

```php
// Never matches: ::text on a bytea gives its hex form, not the bytes read as text.
$db->queryBuilder()->table('imported_rows')->whereLike('payload', '%needle%')->get();

// Decodes the column with convert_from(column, 'UTF8') before comparing.
$db->queryBuilder()->table('imported_rows')->whereLikeText('payload', '%needle%')->get();
```

`whereLikeText()` (and `orWhereLikeText()`) is for exactly this case — a column typed `bytea`
whose bytes are decodable text. It throws if they are not: a column of genuine binary data
(an image, a file) has no text to find, and PostgreSQL's `convert_from()` refusal is better
surfaced as a clear failure than hidden behind a query that silently returns nothing. On
MySQL it compiles to the same plain `LIKE` as `whereLike()`, since a `blob`'s bytes already
compare correctly as text there.

If the column still has no data in it, mapping it to `text` at migration time with
`--blob-as-text` avoids the question entirely — `whereLikeText()` is for a column that
already exists as `bytea`, or one that must stay `bytea` for other callers.

## Writing a point: `'geometry'` and the coordinate order

`insertDataToTable()` and `updateTableData()` take a `'type' => 'geometry'` field and convert it to
PostGIS for you, on PostgreSQL. Three shapes are accepted for the value:

```php
// a "lat, lon" string — what a form posts
$db->insertDataToTable('places', [
    ['fieldName' => 'location', 'value' => '37.9838, 23.7275', 'type' => 'geometry'],
]);

// a named pair — what an API request carries
$db->insertDataToTable('places', [
    ['fieldName' => 'location', 'value' => ['latitude' => 37.9838, 'longitude' => 23.7275], 'type' => 'geometry'],
]);

// anything else: passed to ST_GeomFromText(), so a polygon or a line works
$db->insertDataToTable('areas', [
    ['fieldName' => 'area', 'value' => 'POLYGON((23.7 37.9, 23.8 37.9, 23.8 38.0, 23.7 37.9))', 'type' => 'geometry'],
]);
```

Each entry is one column; the values are keyed by `fieldName`, so the same name twice in one call
keeps only the last.

The first two both compile to `ST_SetSRID(ST_MakePoint(23.7275, 37.9838), 4326)` — **longitude
first**, because that is what `ST_MakePoint()` takes while people write latitude first. Getting it
backwards is silent: nothing errors, every insert succeeds, and `ST_MakePoint(37.9838, 23.7275)` is a
spot in the Indian Ocean rather than Athens. If a map is drawing points in the wrong place and the
numbers in the table look right, this is the first thing to check.

Details worth having:

- **`0, 0` is a coordinate**, not an empty value — the origin is in the Gulf of Guinea and the
  pattern accepts it. So is a negative number in either position.
- **The WKT branch is interpolated into `ST_GeomFromText('…')` as given.** Validate the shape
  yourself before passing anything that came from a request; only the `"lat, lon"` pattern and the
  named pair are checked numerically.
- **The conversion is gated on the driver, not the column.** On MySQL the value is written through
  unchanged, because `ST_MakePoint` and `ST_SetSRID` are PostGIS spellings — MySQL names its
  spatial functions differently and takes the SRID as an argument rather than a wrapper.
- **Each method has its own copy of the conversion.** A change to one and not the other gives you a
  row that is inserted correctly and moves when it is edited.

## Reading a table's schema: `getColumns()`

```php
$result = $db->getColumns('stations');
while ($result->fetch()) {
    $result->fields['Field'];       // column name
    $result->fields['Type'];        // 'varchar'      — the bare type
    $result->fields['ColumnType'];  // 'varchar(255)' — as declared (MySQL only)
    $result->fields['Null'];        // 'YES' / 'NO'
    $result->fields['Comment'];
    $result->fields['Key'];         // MySQL: 'PRI' on the primary key
    $result->fields['PrimaryKey'];  // PostgreSQL: boolean
    $result->fields['ForeignKey'];  // MySQL 1/0, PostgreSQL boolean
    $result->fields['ForeignTable'], $result->fields['ForeignSchema'], $result->fields['ForeignColumn'];
}
```

Columns come back **in the order the table declares them**, and the result is
**cached for an hour** per table, because a schema rarely changes and
introspection is not cheap.

**The primary-key flag has a different name per driver.** MySQL answers `Key` = `'PRI'`;
PostgreSQL answers `PrimaryKey` as a boolean and has no `Key` column. `ForeignKey` is `1`/`0` on
MySQL and a boolean on PostgreSQL. Code reading these should accept either spelling — `true`,
`'t'` and `1` all mean yes, depending on how the row was cast on the way out. `ForeignTable`,
`ForeignSchema` and `ForeignColumn` name the referenced table on both. On MySQL `Type` is the bare
`DATA_TYPE`, so `tinyint(1)` — the boolean convention — appears as `tinyint` there and as
`tinyint(1)` in `ColumnType`.

On PostgreSQL the constraint flags are read from `pg_constraint`, which lists the columns *this*
table constrains. (`information_schema.constraint_column_usage` lists the **referenced** column for
a foreign key, which would put the flag on the wrong table.)

Two things follow from the cache:

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
the migration. The cache store is shared (files, redis), so without invalidation the stale answer
would outlive the process too.

## A prepared statement whose connection died underneath it

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

How the condition is detected, per driver:

- **MySQL**: from the `mysqli_sql_exception` the call throws. Since PHP 8.1 mysqli's default error
  mode is `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, so a failed `execute()` raises rather than
  returning false, and the error number is read from the exception.
- **PostgreSQL**: from the error text («server closed the connection unexpectedly» and similar).
  `pg_connection_status()` reports the last *known* state rather than polling, and still answers
  `OK` at the instant a failing `pg_execute()` returns, so it cannot be the gate.
- **Prepared-plan cache**: a cached plan is reused only on the connection that prepared it. A plan
  does not outlive its session, so after a reconnect the statement is prepared again.

The same holds for a plain `query()`: `runQuery()` fails, `isConnectionGone()` distinguishes a dead
connection from a bad statement, the connection is replaced, and the statement is sent once more.

**`getConnection()` does not probe the handle**, and on MySQL it must not. A probe there means a
statement of the framework's own before every statement of yours, and that has two costs:

- a round trip per query — on a typical page, more pings than statements;
- **your second statement stops describing your first.** `FOUND_ROWS()`, `ROW_COUNT()` and
  `LAST_INSERT_ID()` are per-connection state: they report on the *last* statement the connection ran,
  and with a probe in between that is the probe. A limited select over seven rows would report a
  total of **one**, and an `UPDATE` touching three rows would report `-1`.

So liveness is **remembered, not asked**: `isConnectionGone()` decides it at the one moment
the answer is knowable — when a statement has just failed — and `getConnection()` replaces a link that
has been marked. PostgreSQL additionally checks `pg_connection_status()`, which is a local read of a
cached state rather than a statement, so it costs nothing.

`isConnectionAlive()` can be called deliberately. On MySQL it probes with `SELECT 1`, which costs a
round trip and becomes the connection's last statement, so anything reading per-connection state
afterwards describes the probe. On PostgreSQL it reads `pg_connection_status()`.

If you keep a `Database` handle across a long idle period yourself, you need nothing extra — the first
statement after the idle fails, reconnects and is retried. If you hold a raw
`mysqli`/`PgSql\Connection` from `getConnectionLink()`, you own that problem.

### A statement that failed inside a transaction, on PostgreSQL

A statement that fails inside a transaction aborts it, and an aborted transaction refuses everything
until it ends, the `DEALLOCATE` that would drop the statement's plan included. The plan then stays
on the server, so it stays known to the connection, and the next execute of the same SQL reuses
it. Plans are named after the SQL's hash, so the plan on the server is the right one. Inside a
transaction, a plan is looked for on the server (`pg_prepared_statements`) before it is prepared:
there, a collision would abort the transaction itself, and nothing could recover after it. That
holds whether the transaction was opened with `startTransaction()` or with raw `START TRANSACTION`.

## A paginated list that groups

`_getPaginated()` takes a `$group` clause, and two shapes of it behave differently in a way worth
knowing before you use it.

**Grouping by the primary key** — a join that multiplies rows, grouped back down to one per parent —
can return models:

```php
$this->_getPaginated(
    20, $page, null, 'ORDER BY a.title', null, null, false,
    'LEFT JOIN #PREFIX#comments c ON c.postid = a.postid',
    'a.postid, a.title, COUNT(c.commentid) AS comments',
    'GROUP BY a.postid, a.title'
);
```

**Grouping by anything else** — «a page of categories with counts» — must name its fields and ask for
arrays rather than models:

```php
$this->_getPaginated(
    20, $page, null, 'ORDER BY a.category', null, null, false, '',
    'a.category, COUNT(*) AS total',
    'GROUP BY a.category',
    false                              // ← arrays: a group is not a row
);
```

Two reasons, and neither is arbitrary. The primary key is normally forced into the select, because a
returned model without its key cannot be reloaded or saved — and in a grouped query a column that is
neither grouped nor aggregated is **invalid SQL**, refused by PostgreSQL outright and by MySQL under
`ONLY_FULL_GROUP_BY`. So when the group clause does not name the key, the caller's field list is used
exactly as given. And a model is a row: with no key to index by, the result is a list, which is what
`$returnAsModels = false` returns.

The total is counted as `SELECT COUNT(*) FROM (…) as grouped_query` rather than with `count(a.key)`,
because the latter counts rows *within* each group. Getting that wrong is invisible on the first page:
a listing of twelve rows in three groups would report the size of the first group as the total, the
page arithmetic would follow that number, and the last page would be empty or unreachable — on a screen
that renders perfectly.

## Cloning a `Database`

`clone $db` gives a second object over the **same** link: queries through either reach the
same session. The link belongs to the original. Closing or destroying the clone drops its
handles and closes nothing, so the original stays connected; `close()` on the clone returns
`false`. Closing the original ends the link for both.

A clone that calls `connect()` opens a link of its own, owns it, and closes it as usual.

A link handed to a second instance some other way — two `Database` objects over one
connection — is owned by both. Whichever closes second, often a destructor at shutdown, finds it
already closed; `close()` then returns `false` instead of throwing.

## Model integration

Models extend `Pramnos\Application\Model` and are constructed with their controller. The base class
already provides loading, saving and listing (`_load()`, `_save()`, `_getList()`,
`_getPaginated()`, `_getApiList()`); models generated by `create:crud` wrap them in public `load()`
/ `save()` methods. Custom queries inside a model take the handle from `Database::getInstance()`:

```php
class Application extends \Pramnos\Application\Model
{
    public function loadByClientId($clientId)
    {
        $db = \Pramnos\Database\Database::getInstance();
        $result = $db->queryBuilder()
            ->from('applications')
            ->where('client_id', $clientId)
            ->first();

        if ($result->numRows === 0) {
            return false;
        }
        foreach ($result->fields as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return true;
    }

    public function loadByUserId($userId): array
    {
        return \Pramnos\Database\Database::getInstance()->queryBuilder()
            ->from('applications')
            ->where('user_id', $userId)
            ->getAll();
    }
}
```

In a controller:

```php
$model = new \App\Models\Application($this);
$model->load($id);
```

### Reading a model's columns

`Database::getColumns()` is the introspection call; it is cached and invalidated for you (see
[Reading a table's schema](#reading-a-tables-schema-getcolumns)). A model that needs its own field
list reads it through its full table name:

```php
class Station extends \Pramnos\Application\Model
{
    public function getAvailableFields(): array
    {
        $db = \Pramnos\Database\Database::getInstance();
        $result = $db->getColumns($this->getFullTableName());

        $fields = [];
        while ($result->fetch()) {
            $fields[] = $result->fields['Field'];
        }
        return $fields;
    }
}
```

`Model::$columnCache` is a **public static** property of the base class; a subclass must not
redeclare it with narrower visibility, which is a fatal error at class load.

## Who ran this query: the PostgreSQL session variables

On PostgreSQL the framework labels every connection, so a query caught in
`pg_stat_activity` or a slow-query log can be attributed to a request rather than to a
process id. `Database::setTrackingInfo()` writes them; the application bootstrap, the API
dispatcher (`Api::exec()`) and the queue console commands call it for you.

```sql
SELECT pid, application_name, query,
       current_setting('app.userid',       true) AS userid,
       current_setting('app.request_path', true) AS path,
       current_setting('app.client_ip',    true) AS ip
FROM pg_stat_activity
WHERE state = 'active';
```

| Variable | What is in it |
|---|---|
| `application_name` | The application, plus `_u<userid>` when somebody is signed in, plus the connecting address |
| `app.userid` | The signed-in user's id, or **`guest`** |
| `app.session_id`, `app.client_ip`, `app.user_agent` | The caller |
| `app.request_path`, `app.http_method`, `app.request_time` | The request |

**`app.userid` is `guest` for anonymous traffic, not null.** That is the distinction worth
knowing when you write the `WHERE`: a variable that was never set reads as null, and one
set to `guest` reads as `guest`. Anonymous is a value here, not an absence — which matters
because anonymous traffic is most of what anybody is chasing when they open that view:
every login attempt, every public endpoint, every request whose token has just expired.

Pass your own alongside them, and they are readable under `app.`:

```php
$db->setTrackingInfo($userId, 'Reporting', [
    'userid' => $userId,
    'tenant' => $tenantId,      // becomes app.tenant
]);
```

A **null** value is skipped rather than written, so `current_setting('app.tenant', true)`
answers null for it — "there was no tenant", which is a different answer from the empty
string. The one exception is `userid`, which has a default: a null there gives you `guest`.

Nothing here can fail a request. The writes are suppressed and their result is not read,
because this runs while the connection is being opened and tracing must never be the reason
something does not load.

## Error handling

### How database failures surface (and `throwOnError`)

The two query paths signal failure differently, and the prepared path also differs
by driver — know which one you are on:

| Path | On a SQL/connection error | Notes |
| --- | --- | --- |
| `query($sql)` / `runQuery()` (raw execution) | **Throws** `\Exception` via `setError()` | The message is `"<errno>:<text> ::: SQL QUERY: <sql>"`. Wrap it in try/catch. |
| `execute($sql, ...)` on **PostgreSQL** (prepared statements — the query builder and `preparedQuery()` run through here) | **Returns `false`** when the statement cannot be prepared (`pg_prepare` returns false) | Silent: a caller that ignores the return value swallows the error. |
| `execute($sql, ...)` on **MySQL** | **Throws** `mysqli_sql_exception` | PHP 8.1+ `mysqli` defaults to `MYSQLI_REPORT_STRICT`; callers catching `\Exception` see it. |

### A wrong argument count is rejected before it reaches the driver

`execute($sql, ...$arguments)` counts the statement's own placeholders — `%i`/`%d`/`%s`/`%b`
tokens on both drivers, or native `$1`/`$2`/… when the SQL was written with PostgreSQL's own
placeholder syntax instead of the framework's — and compares that to `count($arguments)`
before binding anything. A mismatch throws `QueryException` with a message naming both
numbers (`"This statement takes 2 bound argument(s); 1 given."`), instead of reaching the
driver and coming back as mysqli's own warning or PostgreSQL's `"bind message supplies N
parameters, but prepared statement ... requires M"` — correct on the driver's part, but
pointing at the framework's statement cache rather than the caller that built the wrong
argument list. The most common cause is a stale `$arguments` array reused across two calls
with different SQL.

So on PostgreSQL a failed `execute()` returns `false` and code that does not check the
return value proceeds as if a write succeeded. `Model::_load()` checks it: a load that fails
throws a `QueryException` on PostgreSQL as it throws `mysqli_sql_exception` on MySQL, rather than
looking like a row that is not there. To make those failures loud — and to get
a single, driver-independent exception type on **both** drivers — opt into strict mode:

```php
$db = \Pramnos\Framework\Factory::getDatabase();
$db->throwOnError = true; // opt-in; the default is false

try {
    $db->queryBuilder()->table('accounts')->insert(['balance' => 100]);
} catch (\Pramnos\Database\QueryException $e) {
    // getDetail() is the whole thing — driver text and SQL. For a log.
    \Pramnos\Logs\Logger::log($e->getDetail(), 'billing');
    throw $e; // don't pretend the write happened
}
```

#### `getMessage()` is safe to print. The driver's text is not.

| | carries | print it? |
|---|---|---|
| `getMessage()` | a short sentence naming nothing — or what the call site chose, like *«Could not save App\Models\Token.»* | **yes** |
| `getDriverMessage()` | the driver's own text: the table, the index that was violated, the value that violated it | log only |
| `getQuery()` | the failing SQL, values and all | log only |
| `getDetail()` | all of the above in one string | log only |

The driver volunteers more than anyone expects. A PostgreSQL duplicate key reads:

```
0:ERROR:  duplicate key value violates unique constraint "idx_usertokens_token_lookup"
DETAIL:  Key (token_lookup)=(35153ba8…) already exists.
::: SQL QUERY:
INSERT INTO public.usertokens ("userid", "tokentype", … ) VALUES ($1, … $21) RETURNING tokenid
```

An endpoint that returns `getMessage()` to its client — for example as an OAuth
`error_description`, from the same `catch` that raises its own refusals — must never hand out text
like that. That is why the driver text lives in `getDriverMessage()`.

**Except for a developer.** On a developer's machine (`APP_DEBUG`, or `DEVELOPMENT`) and in an
application's own test run, `getMessage()` carries the driver's text after the safe sentence. A
failing test, or a page in development, then says why the database refused the query, not only
that it did. `TestEnvironment` turns it on for the run; `QueryException::$detailed = false`
turns it off, and `true` turns it on anywhere. Production is unchanged: the message names nothing.

A call site that can say something useful without saying what, should:

```php
throw new \Pramnos\Database\QueryException(
    $driverText,          // getDriverMessage()
    $sql,                 // getQuery()
    $previous,
    'Could not save ' . static::class . '.'   // getMessage()
);
```

**To log a `QueryException`, log `getDetail()`**, not `getMessage()`.

Key points:

- **`throwOnError` is `false` by default** and does not alter the per-driver behaviour:
  PostgreSQL returns `false` on a prepare failure, MySQL throws `mysqli_sql_exception`.
  Turn it on where a silently dropped write would be a correctness bug (billing,
  migrations, anything transactional).
- **`QueryException extends \RuntimeException`** and adds `getQuery()`,
  `getDriverMessage()` and `getDetail()` so you can log what you must not print. In strict
  mode it replaces the per-driver signals; `query()` continues to throw a plain
  `\Exception`. **`Model::_save()` raises one unconditionally**, strict mode or not — a save
  that did not save has no fail-soft reading, and a `QueryException` can be told apart from an
  application's own refusals in a `catch`.
- **Driver parity in strict mode.** With `throwOnError = true`, a prepare failure becomes a
  `QueryException` on **both** drivers — the PostgreSQL `false` return and the MySQL
  `mysqli_sql_exception` are each translated — so a single `catch (QueryException)` works
  everywhere. With `throwOnError = false` the two drivers keep their different signals.

#### Turning it on from settings — and why a test suite should

```php
// app/config/settings.php
'database' => [
    // …
    'throwOnError' => true,
],
```

Read on construction, so it survives the singleton being reset, which a test suite does
constantly.

**The framework's own test fixtures set it, and the reason is the argument for yours doing the
same.** A suite that runs one backend cannot see the asymmetry: on MySQL every failure throws
whatever the flag says, so the tests look thorough. Point the same tests at PostgreSQL with the
flag off and they still pass — while silently exercising the `false` branch nobody handles. A
query against a missing table then reads as "no rows", and a test asserting a refusal can pass
because the query failed rather than because the input was wrong. The lenient answer to a failed
query — `false`, or `0` from `count()` — is a value a screen will happily print. *You have no
messages* is a sentence; a missing table is not.

**Where to put it.** In a test environment, always. In production, wherever a silently dropped
write would be a correctness bug — and know that turning it on converts existing silent wrong
answers into exceptions, which is the point and also a deployment risk worth taking deliberately.

### `error_text` is the last error, not the first

`$db->error_text` (and `getError()`, which falls back to it) describes **the statement
that just ran**. Read it immediately after the call that returned `false`:

```php
if ($db->queryBuilder()->table('files')->insert(['name' => $name]) === false) {
    \Pramnos\Logs\Logger::error('query failed: ' . $db->error_text);
}
```

Every `prepare()` starts by clearing it, so an empty `error_text` means "nothing went wrong
here" rather than "nothing has gone wrong yet".

!!! danger "`prepare($sql)` takes one argument — it is not `prepareQuery()`"
    `prepare($sql)` opens a prepared statement from `$sql` **as given**: no sprintf-style
    substitution, no escaping. Calling it with extra arguments —
    `prepare('… = %s', $value)`, the shape an application-level class of the same name may
    take (sprintf-style escaping) — throws `ArgumentCountError` rather than silently
    discarding them and running `$sql` with its `%s` left as literal text: a broken `WHERE`
    clause that still runs, answering every row instead of one. Reach for `prepareQuery($sql,
    ...$args)` for sprintf-style substitution, or bind through `execute()`/`queryBuilder()`.

### Handling errors in application code

```php
// Single record
private function getUserData(int $userId): ?array
{
    try {
        $result = $this->application->database->queryBuilder()
            ->from('users')->where('id', $userId)->first();

        return ($result && $result->numRows > 0) ? $result->fields : null;
    } catch (\Exception $e) {
        \Pramnos\Logs\Logger::logError('Error getting user data', $e);
        return null;
    }
}

// Multiple records
private function getUserApplications(int $userId): array
{
    try {
        return $this->application->database->queryBuilder()
            ->from('applications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->getAll();
    } catch (\Exception $e) {
        \Pramnos\Logs\Logger::logError('Error getting user applications', $e);
        return [];
    }
}
```

`getAll()` answers `[]` both for "no rows" and — on PostgreSQL with `throwOnError` off — for a
failed query. Turn strict mode on where the difference matters.

Always check a result before reading it:

```php
if ($result && $result->numRows > 0) {
    $name = $result->fields['name'];
} else {
    $name = 'Unknown User';
}
```

Keep return types consistent: a list method returns `[]` on failure, a single-record method
returns `null`.

### Input validation

Validate before writing — the database is the last line, not the first:

```php
public function updateApplication($id, $name, $redirectUri)
{
    if (empty($name) || empty($redirectUri)) {
        throw new \InvalidArgumentException('Name and redirect URI are required');
    }
    if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException('Invalid redirect URI');
    }

    \Pramnos\Database\Database::getInstance()->queryBuilder()
        ->table('applications')
        ->where('id', $id)
        ->update(['name' => $name, 'redirect_uri' => $redirectUri]);
}
```

## Which server am I talking to?

`$database->type` holds the *configured* engine — `'mysql'` or `'postgresql'` (a configured
`'timescaledb'` is stored as `'postgresql'` with `$database->timescale = true`). It is a
configuration value, not an observation: **MariaDB is configured as `'mysql'`**, because it speaks
MySQL's wire protocol and is reached through the same driver.

Two accessors ask the live connection instead of the configuration file:

```php
$database->getServerVersion(); // raw, e.g. "10.11.6-MariaDB-1:10.11.6+maria~ubu2204"
$database->isMariaDB();        // true only for a MariaDB server
```

Both degrade safely: with no live connection `getServerVersion()` returns `''` **without
opening one**, and `isMariaDB()` answers `false`. The result is cached per connection and
re-detected by `connect()`.

For anything beyond identity, go through `DatabaseCapabilities` (or `$database->capabilities()`) —
it turns (engine, flavor, version) into "can it?" answers:

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

## Transactions

The methods are `startTransaction()`, `commitTransaction()` and `rollbackTransaction()`. Each
returns `true` on success and `false` on failure — `commitTransaction()` and
`rollbackTransaction()` catch and log their own exceptions rather than throwing — so check the
return value of the commit.

```php
$db = $this->application->database;

try {
    $db->startTransaction();

    $db->queryBuilder()->table('applications')
        ->insert(['client_id' => $clientId, 'name' => $name]);
    $appId = $db->getInsertId();

    $db->queryBuilder()->table('application_permissions')
        ->insert(['app_id' => $appId, 'permission' => $permission]);

    if (!$db->commitTransaction()) {
        throw new \RuntimeException('Commit failed');
    }
} catch (\Exception $e) {
    $db->rollbackTransaction();
    throw $e;
}
```

### Transactions announce themselves

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

Listed, alongside every other extension point the framework fires, in the
[Extensibility Guide](Pramnos_Extensibility_Guide.md)'s catalog.

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

### Running one block at a different isolation level

```php
$rows = $database->withIsolationLevel('read uncommitted', function () use ($database) {
    return $database->queryBuilder()->table('classifieds')->where('status', 1)->getAll();
});
```

Sets the session's transaction isolation level for the duration of `$callback`, restoring
whatever it was before — even if `$callback` throws. This is a MySQL/InnoDB-specific tool for
a MySQL/InnoDB-specific problem: a long-running or busy table where a plain `SELECT` would
otherwise wait on a writer's locks, and the data does not have to be exact to be useful (a
dashboard count, a report that will be re-run).

**A deliberate no-op on PostgreSQL.** PostgreSQL has no true `READ UNCOMMITTED` — it silently
runs as `READ COMMITTED` instead (documented PostgreSQL behaviour), and its MVCC never blocks
a reader on a writer at *any* isolation level, which is the actual problem `READ UNCOMMITTED`
exists to sidestep on MySQL/InnoDB. There is nothing here to change on PostgreSQL, so nothing
is sent — `$callback` still runs and its return value still comes back.

Accepts the four standard levels, case-insensitively: `read uncommitted`, `read committed`,
`repeatable read`, `serializable`. An unrecognised string throws `\InvalidArgumentException`
before anything touches the connection.

### A multi-step write

```php
class OrderProcessor
{
    private $database;

    public function __construct($application)
    {
        $this->database = $application->database;
    }

    public function processOrder(array $orderData, array $orderItems): array
    {
        $db = $this->database;

        try {
            $db->startTransaction();

            $db->queryBuilder()->table('orders')->insert([
                'user_id'    => $orderData['user_id'],
                'total'      => $orderData['total'],
                'status'     => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $orderId = $db->getInsertId();

            foreach ($orderItems as $item) {
                $db->queryBuilder()->table('order_items')->insert([
                    'order_id'   => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                ]);

                // A conditional decrement: stays raw because the SET is an expression.
                $result = $db->preparedQuery(
                    'UPDATE products SET stock_quantity = stock_quantity - :qty
                      WHERE id = :id AND stock_quantity >= :qty',
                    ['qty' => (int) $item['quantity'], 'id' => (int) $item['product_id']]
                );
                if (!$result || $result->getAffectedRows() === 0) {
                    throw new \RuntimeException('Insufficient inventory for product ' . $item['product_id']);
                }
            }

            if (!$db->commitTransaction()) {
                throw new \RuntimeException('Commit failed');
            }
            return ['success' => true, 'order_id' => $orderId];
        } catch (\Exception $e) {
            $db->rollbackTransaction();
            \Pramnos\Logs\Logger::logError('Order processing failed', $e);
            return ['success' => false];
        }
    }
}
```

### Savepoints

There is no savepoint API; the SQL is the same on both engines, so issue it directly inside a
transaction:

```php
$db->query('SAVEPOINT sp_1');
// …
$db->query('ROLLBACK TO SAVEPOINT sp_1');   // or: RELEASE SAVEPOINT sp_1
```

## Migrations

Schema changes go through migrations, written with the schema builder. The full reference —
file naming, metadata, running, rollback — is the [Migration guide](Pramnos_Migration_Guide.md).

```php
<?php

use Pramnos\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->schema()->createTable('users', function ($table) {
            $table->increments('id');
            $table->string('username', 50)->unique();
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('users');
    }
}
```

The file is named with a timestamp prefix, e.g. `2026_05_29_090000_create_users_table.php`, and
generated by `create:migration`. The schema builder emits the right DDL for MySQL, PostgreSQL and
TimescaleDB, so one migration covers all three; `$this->addQuery()` remains for a statement the
builder cannot express.

## Advanced query patterns

### Joins, aggregates and subqueries

Analytics queries that need a derived table are a reasonable place for raw SQL. With
`preparedQuery()` the values are bound by the driver:

```php
$result = $db->preparedQuery(
    'SELECT u.id, u.username, u.email,
            COUNT(o.id) AS order_count,
            COALESCE(SUM(o.total), 0) AS total_spent,
            recent_orders.last_order_date
       FROM users u
       LEFT JOIN orders o ON u.id = o.user_id
       LEFT JOIN (
            SELECT user_id, MAX(created_at) AS last_order_date
              FROM orders
             WHERE created_at > :recent
             GROUP BY user_id
       ) recent_orders ON u.id = recent_orders.user_id
      WHERE u.created_at > :since
      GROUP BY u.id, u.username, u.email, recent_orders.last_order_date
     HAVING COUNT(o.id) > :minOrders
      ORDER BY total_spent DESC
      LIMIT 50',
    [
        'recent'    => date('Y-m-d', strtotime('-30 days')),
        'since'     => date('Y-m-d', strtotime('-1 year')),
        'minOrders' => 5,
    ]
);
$analyticsData = $result ? $result->fetchAll() : [];
```

### Geospatial queries (PostgreSQL)

```php
if ($db->type === 'postgresql') {
    $result = $db->preparedQuery(
        'SELECT id, name, ST_AsText(location) AS location_text,
                ST_Distance(location, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)) AS distance
           FROM stores
          WHERE ST_DWithin(location, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), :radius)
          ORDER BY distance
          LIMIT 10',
        ['lon' => $longitude, 'lat' => $latitude, 'radius' => 5000]
    );
}
```

Longitude comes first in `ST_MakePoint()` — see [Writing a point](#writing-a-point-geometry-and-the-coordinate-order).

### PostgreSQL JSON, arrays and full-text search

```php
// JSON field
$db->preparedQuery('SELECT * FROM events WHERE payload ->> :key = :value',
    ['key' => 'type', 'value' => 'login']);

// Array column
$db->preparedQuery('SELECT * FROM posts WHERE :tag = ANY(tags)', ['tag' => 'news']);

// Full-text search
$db->preparedQuery(
    "SELECT *, ts_rank(to_tsvector('english', body), plainto_tsquery('english', :q)) AS rank
       FROM articles
      WHERE to_tsvector('english', body) @@ plainto_tsquery('english', :q)
      ORDER BY rank DESC",
    ['q' => $searchTerm]
);
```

Table and column names cannot be bound; never build them from request input.

### Inspecting a plan

`EXPLAIN` is a read statement and goes through `query()` like any other:

```php
$result = $db->query('EXPLAIN ' . $sql);
$plan = $result->fetchAll();
```

For live activity, slow queries and replication on PostgreSQL, `DatabaseInspector` and the
DevPanel's database page already collect them.

## Adjacency list

`Adjacencylist` reads hierarchical rows (an id and a parent id in the same table):

```php
$database = \Pramnos\Framework\Factory::getDatabase();
$categoryTree = new \Pramnos\Database\Adjacencylist(
    $database,
    'categories',     // table name
    'id',             // id field
    'parent_id',      // parent field
    'name'            // title field
);

// All categories as a hierarchical array
$categories = $categoryTree->getArray();

// The path to one category, as an array of items from the root
$categoryPath = $categoryTree->getPathAsArray(15);

// The path as a string, joined with $separator (default ' » ')
$categoryTree->separator = ' > ';
$fullPath = $categoryTree->getPath(15);
```

## Database security

### SQL injection

Values go in as builder arguments, `preparedQuery()` bindings or `prepareQuery()` arguments —
never concatenated into the SQL:

```php
// Secure
$users = $db->queryBuilder()
    ->from('users')
    ->where('email', $userEmail)
    ->where('role', $userRole)
    ->where('active', 1)
    ->get();

// Vulnerable — never do this
$sql = "SELECT * FROM users WHERE email = '" . $userEmail . "'";
```

Identifiers (table and column names) cannot be bound by any of the three paths. When one comes
from a request, check it against a fixed list first.

### Passwords

Store passwords with `password_hash()` and check them with `password_verify()`; never store them
reversibly, and never write your own salting scheme.

```php
$db->queryBuilder()->table('users')->insert([
    'username'      => $userData['username'],
    'email'         => $userData['email'],
    'password_hash' => password_hash($userData['password'], PASSWORD_DEFAULT),
    'created_at'    => date('Y-m-d H:i:s'),
]);
```

## Read/write replicas

A primary for writes and one or more replicas for reads is how a database scales past one
machine, and the routing is automatic: declare the two roles and application code does not
change.

```php
'database' => [
    'type'   => 'postgresql',
    'prefix' => '',
    'write' => [
        'hostname' => 'db-primary',
        'user'     => 'app',
        'password' => '…',
        'database' => 'myapp',
    ],
    'read' => [
        'hostname' => 'db-replica',
        'user'     => 'app',
        'password' => '…',
        'database' => 'myapp',
    ],
],
```

**Where each key comes from.** Once either block is present, the connection keys —
`hostname`, `user`, `password`, `database` — are taken from the `write` block (or the `read`
block if there is no `write`); top-level values for those four are not read. A key the `read`
block omits falls back to those write values, so a replica that differs only in hostname says
only that. `port` may be set per block and otherwise falls back to the top level. `type`,
`prefix`, `schema`, `timezone`, `collation` and `throwOnError` stay top-level.

### How a statement picks a link

`isWriteQuery()` takes the statement's first word, whatever whitespace follows it. `SELECT`, `SHOW`, `EXPLAIN`,
`DESC` and `DESCRIBE` go to the read link; **everything else goes to the write link**, which is the
safe direction to be wrong in — an unrecognised statement reaches the primary rather than
being refused by a read-only replica. A `WITH … SELECT` therefore goes to the primary, since a
common table expression can write.

The two links are independent sessions. The configured time zone and collation are applied
to each, and a link a failed statement has shown to be gone is replaced on the next call
without disturbing the other one.

### What to know before turning it on

- **Replica lag.** A write and an immediate `SELECT` of the same row are two connections,
  and the replica may not have the write yet. Code that must read its own write has to
  avoid the split for that read — the usual shape is to use the value already in hand
  rather than re-reading it.
- **This is not failover.** Two hostnames are configuration, not a cluster: a primary that
  is down is an outage, not a promotion. Put a proxy, a managed service or a virtual IP in
  front and point the framework at that.
- **No block means no split.** With neither `read` nor `write` present the framework uses
  the single top-level connection, for reads and writes alike. After a lost connection it
  reconnects that one connection for both roles, so reads stay in the session of the writes:
  inside the writes' transactions, and seeing what they just wrote.
- **The id of a row just inserted is read on the write link.** `getInsertId()` asks
  PostgreSQL's `currval()` in the session that ran the INSERT. With a split, the read link
  is another server, where the sequence was never used.

On PostgreSQL, `DatabaseInspector::getReplicationStatus()` reports `pg_stat_replication` —
client address, state, sync state and lag in seconds — and the DevPanel's database page
renders it.

See [One server to several](Pramnos_Multi_Server_Guide.md) for the rest of what changes
when an application stops being on one machine.

## MySQL's SQL on PostgreSQL (`database.translate_mysql`)

An application moving from MySQL to PostgreSQL usually has hand-written SQL in MySQL's dialect.
Much of it is mechanically translatable, and should not be rewritten by hand:

```php
'database' => ['type' => 'postgresql', 'translate_mysql' => true],
```

Each statement is then translated on its way to the server. That covers `query()`, `execute()`,
`prepare()` and `prepareQuery()`, and the query builder's raw fragments too, `whereRaw()` and
`joinRaw()` included, which is how the admin datatables pass their conditions.

| MySQL | PostgreSQL |
|---|---|
| `` `name` `` | `"name"` |
| `IFNULL(a, b)`, `IF(c, a, b)`, `ISNULL(x)` | `COALESCE(a, b)`, `CASE WHEN c THEN a ELSE b END`, `((x) IS NULL)` |
| `LCASE(x)`, `UCASE(x)`, `RAND()` | `lower(x)`, `upper(x)`, `random()` |
| `LIMIT 20, 10` | `OFFSET 20 LIMIT 10` — the two arguments stay where they were, literal or a placeholder each |
| `DELETE FROM t WHERE … [ORDER BY …] LIMIT n`, `UPDATE t SET … WHERE … LIMIT n` | `… WHERE ctid IN (SELECT ctid FROM t WHERE … LIMIT n)` — through `query()`, `prepare()` and `prepareQuery()`, with or without the translation |
| `UNIX_TIMESTAMP()`, `UNIX_TIMESTAMP(x)` | `EXTRACT(EPOCH FROM …)::bigint` |
| `FROM_UNIXTIME(x)`, `FROM_UNIXTIME(x, fmt)` | `to_timestamp(x)`, `to_char(to_timestamp(x), …)` |
| `CURDATE()`, `CURTIME()` | `CURRENT_DATE`, `LOCALTIME` |
| `DATE_FORMAT(d, '%d/%m/%Y')` | `to_char(d, 'DD"/"MM"/"YYYY')` |
| `DATE_ADD(d, INTERVAL 7 DAY)`, `DATE_SUB(…)` | `(d + (7) * INTERVAL '1 day')`, `(d - …)` |
| `GROUP_CONCAT([DISTINCT] x [ORDER BY …] [SEPARATOR s])` | `string_agg([DISTINCT] (x)::text, s [ORDER BY …])` |
| `CONCAT(a, b, …)` | `((a)::text \|\| (b)::text \|\| …)` — NULL when any argument is, as in MySQL; PostgreSQL's own `concat()` would skip it. `CONCAT_WS` is left as it is: both skip a NULL |
| `INSERT IGNORE …` | `INSERT … ON CONFLICT DO NOTHING` |
| `… ON DUPLICATE KEY UPDATE n = n + VALUES(n)` | `… ON CONFLICT (key) DO UPDATE SET n = t.n + EXCLUDED.n` |
| `REPLACE INTO t (…) VALUES …` | `INSERT INTO t (…) VALUES … ON CONFLICT (key) DO UPDATE SET …` |
| `SHA2(x, 256)` (224, 384, 512; 0 is 256) | `encode(sha256(convert_to((x)::text, 'UTF8')), 'hex')` |
| `CAST(x AS SIGNED)`, `CAST(x AS UNSIGNED [INTEGER])` | the leading run of digits as `bigint`, `0` where there is none — never an error, as in MySQL |
| `DAY(d)`, `MONTH(d)`, `YEAR(d)` | `EXTRACT(DAY FROM d)::int`, … |
| `a REGEXP p`, `a RLIKE p`, `a NOT REGEXP p` | `a ~* p`, `a ~* p`, `a !~* p` — case-insensitive, as under a `_ci` collation |
| `GET_LOCK(name, t)`, `RELEASE_LOCK(name)` | `pg_try_advisory_lock(hashtext(name))`, `pg_advisory_unlock(…)`, as 1 or 0 |
| `JSON_EXTRACT(doc, '$.a.b[0]')`, `JSON_UNQUOTE(x)` | `((doc)::jsonb #> '{a,b,0}')`, `((x)::jsonb #>> '{}')` |
| `DELETE a FROM t a JOIN u b ON … WHERE …` | `DELETE FROM t a USING u b WHERE (…) AND (…)` |
| `SELECT x * 2 AS d … HAVING d < 25` | `… HAVING (x * 2) < 25`, in a subquery too |
| `SET FOREIGN_KEY_CHECKS = 0` / `1` | `SET session_replication_role = 'replica'` / `'origin'` |

Some of these have limits:

- **`GET_LOCK` does not wait.** The lock is taken at once or not at all, whatever the timeout.
- **`JSON_EXTRACT` takes one literal path**, without wildcards. Any other is left as written.
- **`DELETE` through a join** is translated when the first table is the one deleted from and
  every join is an inner one with `ON`. A `LEFT JOIN` is left as written.
- **An alias in `HAVING`** is replaced only when given with `AS`, and not when its expression
  names a column of the same name (`SUM(n) AS n`).
- **`session_replication_role`** takes a superuser and switches off every trigger, not only the
  foreign keys. That is what an import or a test needs, and a connection without the right is
  refused rather than quietly checked.

**Only what means the same in both is translated.** Every construct above is invalid in
PostgreSQL, so SQL already written for it passes untouched, and a statement that goes through
twice comes out the same. String literals and comments are never altered: they are only read
past, MySQL's `\'` escapes included.

**Upserts need a conflict target**, and PostgreSQL wants it named where MySQL fires on any unique
key. The translator uses the table's one unique key whose columns the insert names, read from
the catalogue. Where two qualify, or none, the statement is refused with
`Pramnos\Database\Dialect\DialectException`, which says to write `ON CONFLICT (…)` yourself,
rather than guessed at. A bare column on the right of an update (`n = n + 1`) is the row already
there, as in MySQL, and is qualified with the table's name, since PostgreSQL would find it
ambiguous with `EXCLUDED`. The same holds for a statement that is already
`ON CONFLICT … DO UPDATE SET`: written so, or translated by `prepareQuery()` and then added to
(`$sql . ', n = n + 1'`). Each run reads it again. An insert with an alias (`INSERT INTO t AS x`)
is not read for it: name the alias yourself.

**Left alone, on purpose**: what would run on PostgreSQL and mean something else. That is a
`LIKE` that ignored case under MySQL's collation, a `GROUP BY` that leaves columns out, `"…"`
used as a string, and a comparison of text with a number. They are the dialect linter's to
report, not the translator's to change. A `DATE_FORMAT` pattern `to_char` cannot express
(`%U`), or one that is not a literal, is also left as written, to fail where it can be seen.
`REGEXP_SUBSTR()` and `REGEXP_LIKE()` need nothing: PostgreSQL has both from 15. A regular
expression that has to tell cases apart is written `~` for PostgreSQL.

Through `prepareQuery()` a pattern is written for printf, like everything else in the
statement: `DATE_FORMAT(d, '%%Y-%%m')` is MySQL's `'%Y-%m'`, and it reaches PostgreSQL as
`to_char(d, 'YYYY"-"MM')`. A literal percent in the pattern is `%%%%` there, as `%%` is anywhere
else in that statement.

### `db:dialect-lint`: what would mean something else

```bash
php bin/pramnos db:dialect-lint                # src and app
php bin/pramnos db:dialect-lint src/Admin --json
php bin/pramnos db:dialect-lint --schema       # column types and keys from the database too
```

```
src/Admin/Users.php:88  like  LIKE ignores case on MySQL (a _ci collation) and not on PostgreSQL: ILIKE, or lower() on both sides
src/Reports/Visits.php:41  group-by  v.name is selected but neither grouped nor aggregated: …
3 finding(s): 2 like, 1 group-by
```

It reads the SQL in the code as it will run, and names each place by file and line. A string
with variables in it, strings joined with `.`, and a variable built up with `.=` over several
statements are each one piece of SQL, a variable standing in as `?`. Each finding is at the line
of the part it is in.

| Rule | What it finds | What to do |
|---|---|---|
| `like` | `LIKE`, which ignores case on MySQL's `_ci` collations and does not on PostgreSQL | `ILIKE`, or `lower()` on both sides — or nothing, where case should count |
| `group-by` | a column selected or ordered by but neither grouped nor aggregated, and a column a `SELECT DISTINCT` orders by without selecting it: MySQL returned one of the values, PostgreSQL refuses it | `GROUP BY` it, aggregate it, or select it |
| `double-quoted-string` | `"…"` used as a value: a string to MySQL, a column name to PostgreSQL | single quotes |
| `implicit-cast` | a text column compared with a number, which MySQL converts and PostgreSQL refuses. With `--schema` only | compare like with like, cast one side, or make the column a number |

A string counts as SQL when it has a statement's shape or a condition's, not when it merely holds
the word "delete". Markup, an operator's name alone (`'LIKE'`) and prose are not read: a prompt
that says *like "daily"* is sentences, not a condition. A line ending in `// dialect-ok` is one
somebody has looked at and is not reported again. The command exits `1` while anything is found.

**A grouped key groups its table**, as PostgreSQL has it: `GROUP BY i.invoiceid` lets the other
columns of `i` through. With `--schema` the primary keys decide this. Without it, a grouped
column whose name ends in `id` is taken for its table's key, so that table's columns are not
reported. Neither are the unqualified ones, when the grouped column is the only clue.

**`--schema`** reads the column types and primary keys from the application's database, MySQL's
or PostgreSQL's. Without it a text column cannot be told from a number. A table is known by its
name with and without the prefix, and by its alias. An unqualified column is judged only when one
table of the statement has it.

Off by default, and only on PostgreSQL. It is a bridge for the move, not a dialect to keep
writing in: new code goes through the query builder.

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
default is left untouched.
