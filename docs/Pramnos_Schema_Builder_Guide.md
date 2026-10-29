---
use_cases:
  - Creating or altering a table inside a migration
  - Choosing a column type, modifier, index or foreign key
  - Writing DDL that must work on MySQL, PostgreSQL and TimescaleDB
  - Guarding DDL behind a database capability check
  - Choosing between a continuous aggregate and a materialised view
---

# Pramnos Schema Builder Guide

The **Schema Builder** (DDL — Data Definition Language) provides a programmatic interface for defining and modifying database schemas. It supports MySQL 8.0+, PostgreSQL 17+ and TimescaleDB 2.26.4+ with dialect-aware syntax. The suite runs against MySQL 8.0, PostgreSQL 18 and TimescaleDB 2.31, so a construct that only an older TimescaleDB refuses — a continuous aggregate with `percentile_cont`, say — is checked on such a host before it ships. TimescaleDB before 2.31 will stop being officially supported in a future release ([Upgrade Guide](Pramnos_Upgrade_Guide.md#planned-not-yet-in-effect)).

**Class:** `Pramnos\Database\SchemaBuilder`  
**Entry point:** `$db->schema()` — returns a builder bound to the current database connection. `$db->schemaBuilder()` returns the same kind of builder.

## Overview

The Schema Builder enables you to:

- Create and drop tables with proper type mapping
- Add, modify, and drop columns
- Define indexes, primary keys, and foreign keys
- Create views, materialized views, triggers and sequences (dialect-specific)
- Manage TimescaleDB hypertables and time-series features
- Detect database capabilities and conditionally execute DDL

## Getting Started

### Create a Table

```php
$db = \Pramnos\Database\Database::getInstance();
$schema = $db->schema();

$schema->create('users', function ($table) {
    $table->bigIncrements('userid');                 // BIGINT UNSIGNED AUTO_INCREMENT PK / BIGSERIAL
    $table->string('username', 50)->unique();       // VARCHAR(50) UNIQUE
    $table->string('email')->unique();              // VARCHAR(255) UNIQUE
    $table->string('password', 60);                 // VARCHAR(60) for hashed password
    $table->timestamps();                           // nullable created_at, updated_at
    $table->timestamp('last_login')->nullable();    // nullable timestamp
    $table->boolean('active')->default(true);       // TINYINT(1) / BOOLEAN
    $table->index('email');                         // named users_email_index
});
```

### Modify an Existing Table

```php
$schema->table('users', function ($table) {
    $table->string('phone')->nullable();                              // Add column
    $table->dropColumn('obsolete_field');                             // Remove column
    $table->modifyColumn('username', 'string', ['length' => 100]);   // Change column type
    $table->dropIndex('users_email_index');                           // Remove index by name
});
```

`table()` is an alias of `alterTable()`, and `create()` of `createTable()`.

### Drop a Table

```php
$schema->dropIfExists('users');
```

## Column Types

The most common types — the [complete reference](#complete-column-type-reference) lists every one:

| Method | MySQL | PostgreSQL | Notes |
|---|---|---|---|
| `increments($name)` / `bigIncrements($name)` | INT / BIGINT UNSIGNED AUTO_INCREMENT | SERIAL / BIGSERIAL | Primary key |
| `string($name, $length = 255)` | VARCHAR(n) | VARCHAR(n) | |
| `integer($name)` | INT | INTEGER | 32-bit |
| `bigInteger($name)` | BIGINT | BIGINT | 64-bit |
| `text($name)` | TEXT | TEXT | Large text |
| `boolean($name)` | TINYINT(1) | BOOLEAN | |
| `float($name, $total = 8, $places = 2)` | FLOAT | REAL | |
| `decimal($name, $total = 8, $places = 2)` | DECIMAL(p, s) | DECIMAL(p, s) | |
| `date($name)` | DATE | DATE | |
| `time($name)` | TIME | TIME | |
| `dateTime($name)` | DATETIME | TIMESTAMP | |
| `timestamp($name)` | TIMESTAMP | TIMESTAMP | |
| `json($name)` / `jsonb($name)` | JSON | JSON / JSONB | |
| `uuid($name)` | CHAR(36) | UUID | |
| `enum($name, $values)` | ENUM('a', 'b') | VARCHAR + CHECK | `enumType()` for a native PostgreSQL enum |

## Column Modifiers

```php
$table->string('email')
    ->nullable()           // allow NULL
    ->default('guest')     // set DEFAULT
    ->unique()             // UNIQUE constraint
    ->after('username')    // column ordering (MySQL)
    ->comment('User email'); // column comment

$table->integer('count')->unsigned();               // UNSIGNED (MySQL)
$table->timestamp('seen_at')->useCurrent();         // DEFAULT CURRENT_TIMESTAMP
$table->integer('score')->check('score >= 0');      // CHECK constraint
```

`first()`, `autoIncrement()`, `primary()`, `storedAs()`, `virtualAs()`, `charset()` and
`collation()` are also available on a column.

`charset()` and `collation()` are written on MySQL and MariaDB (`CHARACTER SET … COLLATE …`
after the type) and ignored on PostgreSQL. A column that holds emoji under a unique key takes
`utf8mb4_bin`: the tables' default, `utf8mb4_unicode_ci`, compares every character outside the
Basic Multilingual Plane as equal, so 😍 and 💝 are the same value to it and the second insert
is refused or ignored.

```php
$table->string('emoji', 64)->collation('utf8mb4_bin');
```

## Indexes

```php
$table->primary('userid');                  // PRIMARY KEY
$table->unique('email');                    // UNIQUE index
$table->index('created_at');                // Regular index

// Composite indexes, with an explicit name
$table->index(['country', 'city'], 'idx_location');
$table->unique(['userid', 'token'], 'uq_user_token');
```

## Foreign Keys

```php
$table->unsignedBigInteger('author_id');
$table->foreign('author_id')
    ->references('userid')
    ->on('users')
    ->onDelete('CASCADE')      // CASCADE, RESTRICT, SET NULL, NO ACTION
    ->onUpdate('CASCADE');
```

`cascadeOnDelete()`, `cascadeOnUpdate()`, `nullOnDelete()` and `noActionOnDelete()` are
shorthands, and `name()` sets the constraint name.

The referenced table is named as the one being built is: `->on('users')` references the
installation's prefixed `users`, and `->on('authserver.roles')` the `{prefix}authserver_roles` a
MySQL database has. On MySQL the two columns must have the same type, sign included — an
`increments()` is `INT UNSIGNED`, so the column referencing it is `->integer(…)->unsigned()`.

## Timestamps

```php
$table->timestamps();        // nullable created_at, updated_at (TIMESTAMP)
$table->timestampsTz();      // the same as TIMESTAMPTZ on PostgreSQL
$table->softDeletes();       // nullable deleted_at TIMESTAMP
$table->softDeletesTz();     // nullable deleted_at TIMESTAMPTZ
```

## Conditional DDL (DatabaseCapabilities)

Execute DDL conditionally based on database features:

```php
$schema->create('metrics', function ($table) {
    $table->bigIncrements('id');
    $table->timestamp('time');
    $table->float('value');
    $table->index('time');
});

$schema->ifCapable(
    \Pramnos\Database\DatabaseCapabilities::TIMESCALEDB,
    function (\Pramnos\Database\SchemaBuilder $schema) {
        // TimescaleDB only
        $schema->createHypertable('metrics', 'time');
    },
    function () {
        // Plain PostgreSQL or MySQL: stays a regular table
    }
);
```

See [Capability-Conditional DDL](#capability-conditional-ddl) for `ifCapable()` against
`whenCapable()`.

## TimescaleDB Features

### Create Hypertables

```php
$schema->createHypertable('metrics', 'time', ['chunk_time_interval' => '1 day']);
```

### Time Bucketing

```php
// Dialect-transparent time_bucket() — a QueryBuilder method
$qb = $db->queryBuilder();
$bucket = $qb->timeBucket('1 hour', 'time');

$result = $qb
    ->select([$bucket . ' AS bucket', 'AVG(value) AS avg_value'])
    ->from('metrics')
    ->groupBy([$bucket])
    ->get();
```

### Retention Policies

```php
$schema->addRetentionPolicy('metrics', '30 days', 'time');   // third argument: time column, default 'created_at'
```

### Continuous Aggregates

The body is a SQL string:

```php
$schema->createContinuousAggregate(
    'metrics_hourly',
    "SELECT time_bucket('1 hour', time) AS bucket, AVG(value) AS avg_value
       FROM metrics GROUP BY bucket"
);
```

See [TimescaleDB Operations](#timescaledb-operations) for what is materialised on creation.

## Views

Views take their body as a SQL string; a builder query becomes one with `toSql()` only when
it has no bindings, so write the SQL directly:

```php
$schema->createView('active_users',
    'SELECT userid, username, email FROM users WHERE active = 1');

$schema->dropView('active_users');

// Materialized view (PostgreSQL; a regular VIEW on MySQL)
$schema->createMaterializedView('user_stats',
    'SELECT userid, COUNT(*) AS post_count FROM posts GROUP BY userid');
```

## Complete Column Type Reference

| Blueprint method | MySQL | PostgreSQL |
|---|---|---|
| `tinyInteger(name)` | `TINYINT` | `SMALLINT` |
| `smallInteger(name)` | `SMALLINT` | `SMALLINT` |
| `integer(name)` | `INT` | `INTEGER` |
| `bigInteger(name)` | `BIGINT` | `BIGINT` |
| `unsignedInteger(name)` | `INT UNSIGNED` | `INTEGER` |
| `unsignedBigInteger(name)` | `BIGINT UNSIGNED` | `BIGINT` |
| `increments(name)` | `INT UNSIGNED AUTO_INCREMENT PK` | `SERIAL PK` |
| `bigIncrements(name)` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | `BIGSERIAL PK` |
| `char(name, length)` | `CHAR(n)` | `CHAR(n)` |
| `string(name, length=255)` | `VARCHAR(n)` | `VARCHAR(n)` |
| `text(name)` | `TEXT` | `TEXT` |
| `mediumText(name)` | `MEDIUMTEXT` | `TEXT` |
| `longText(name)` | `LONGTEXT` | `TEXT` |
| `float(name)` | `FLOAT` | `REAL` |
| `double(name)` | `DOUBLE` | `DOUBLE PRECISION` |
| `decimal(name, total, places)` | `DECIMAL(p,s)` | `DECIMAL(p,s)` |
| `boolean(name)` | `TINYINT(1)` | `BOOLEAN` |
| `date(name)` | `DATE` | `DATE` |
| `time(name)` | `TIME` | `TIME` |
| `dateTime(name)` | `DATETIME` | `TIMESTAMP` |
| `timestamp(name)` | `TIMESTAMP` | `TIMESTAMP` |
| `timestampTz(name)` | `TIMESTAMP` | `TIMESTAMPTZ` |
| `year(name)` | `YEAR` | `INTEGER` |
| `binary(name)` | `BLOB` | `BYTEA` |
| `json(name)` | `JSON` | `JSON` |
| `jsonb(name)` | `JSON` (fallback) | `JSONB` |
| `uuid(name)` | `CHAR(36)` | `UUID` |
| `enum(name, values[])` | `ENUM('v1','v2')` | `VARCHAR(n) CHECK (col IN (...))` |
| `geometry(name)` | `GEOMETRY` | `GEOMETRY` |
| `timestamps()` | nullable `created_at` + `updated_at` TIMESTAMP | same |
| `timestampsTz()` | nullable TIMESTAMP | nullable TIMESTAMPTZ |
| `softDeletes()` | nullable `deleted_at` TIMESTAMP | same |

## ALTER TABLE

```php
$schema->alterTable('#PREFIX#users', function ($table) {
    // Add columns
    $table->string('phone', 20)->nullable()->after('email');
    $table->integer('login_count')->default(0);

    // Drop columns
    $table->dropColumn('old_field');
    $table->dropColumn(['field_a', 'field_b']);

    // Rename a column
    $table->renameColumn('old_name', 'new_name');

    // Modify an existing column (change type and/or attributes)
    $table->modifyColumn('bio', 'text');                              // type only
    $table->modifyColumn('status', 'string', ['length' => 100])      // type + length
          ->nullable(false)
          ->default('active');

    // Drop an index
    $table->dropIndex('idx_old_name');

    // Drop a foreign key
    $table->dropForeign('fk_old_constraint');

    // Add a new unique constraint
    $table->unique('email', 'uq_email');

    // Add a new index
    $table->index(['country', 'city'], 'idx_location');

    // Add a new foreign key
    $table->foreign('new_col')->references('id')->on('other_table')->nullOnDelete();
});
```

### `modifyColumn()` Dialect Behaviour

| Dialect | Generated SQL |
|---|---|
| MySQL | `ALTER TABLE t MODIFY COLUMN name type [modifiers]` — single statement |
| PostgreSQL | Up to 3 separate `ALTER COLUMN` statements: `TYPE`, `SET/DROP NOT NULL`, `SET/DROP DEFAULT` |
| TimescaleDB | Same as PostgreSQL |

### Choosing the ALTER algorithm (MySQL/MariaDB)

```php
$schema->alterTable('#PREFIX#visits', function ($table) {
    $table->modifyColumn('duration', 'integer')->nullable(false)->default(0);
    $table->algorithm('instant');   // INSTANT, INPLACE or COPY
});
```

`algorithm()` appends `, ALGORITHM=...` to every `ALTER TABLE` statement the Blueprint emits on
MySQL/MariaDB. If the server cannot honour it — e.g. `INSTANT` on a change that needs a table
rebuild — the statement fails with the server's own error instead of silently falling back to a
slower algorithm. Use it when backfilling a default on a table too large to rebuild unnoticed.
It is a no-op on PostgreSQL/TimescaleDB, where column changes are already metadata-only.

### Dropping a CHECK constraint

```php
$schema->dropCheck('applications.oauth2_application_grants', 'chk_oauth2_grant_type');
```

`DROP CONSTRAINT`, which PostgreSQL, MySQL 8.0.19+ and MariaDB share — `DROP CHECK` is MySQL's
alone. On MariaDB a CHECK written on a column belongs to the column and is named after it; no
`DROP CONSTRAINT` reaches it, so the column is redefined without it, keeping its type,
nullability, default and comment. A CHECK a later migration may need to drop is better named and
table-level from the start.

### Moving a table into a schema

```php
$schema->moveToSchema('deferredwrites', 'pramnos');   // → pramnos.deferredwrites
```

**Not `renameTable()`.** PostgreSQL's `ALTER TABLE … RENAME TO` takes a *bare* name — it cannot
move a table between schemas, and handing it a qualified one is a syntax error rather than a
move. The statement for that is `SET SCHEMA`, and it is a catalogue update: no row is copied,
whatever the table holds. On MySQL a schema is flattened into the table name, so the same
intention compiles to a rename — also a catalogue update.

It answers `false` rather than raising in the three states a migration meets:

| State | Answer |
| --- | --- |
| the table is not there | `false` — an installation that never had it |
| it is already in the target schema | `false` — an installation that has already migrated |
| something is already at the destination | `false`, **and the source is untouched** |

The third one is why it checks: a half-finished manual move leaves a table of the same name in
both schemas, and overwriting the destination would destroy the rows somebody had already moved.

This is what a migration uses when a table turns out to be in the wrong place *after* it has
been deployed. Where nothing has been deployed yet, correct the migration that creates it
instead — a move is a step every installation then has to run for ever.

## View Operations

```php
// Regular views (all backends)
$schema->createView('v_active_users', 'SELECT * FROM users WHERE active = 1');
$schema->createOrReplaceView('v_active_users', 'SELECT * FROM users WHERE active = 1');
$schema->dropView('v_active_users');            // IF EXISTS by default
$schema->dropView('v_active_users', false);     // strict — error if not found

// Materialized views (PostgreSQL/TimescaleDB)
// On MySQL: falls back silently to a regular VIEW
$schema->createMaterializedView('mv_stats', 'SELECT region, COUNT(*) FROM orders GROUP BY region');
$schema->refreshMaterializedView('mv_stats');
$schema->refreshMaterializedView('mv_stats', true); // CONCURRENTLY (PG: allows concurrent reads)
$schema->dropMaterializedView('mv_stats');
```

### Which kind of relation owns a name

`dropView()` and `dropMaterializedView()` are not interchangeable. PostgreSQL refuses
`DROP VIEW` on a materialized view outright:

```
ERROR:  "usage_statistics" is not a view
HINT:  Use DROP MATERIALIZED VIEW to remove a materialized view.
```

`hasView()` cannot tell you which you have — it answers yes for a plain view, a
materialized view and a TimescaleDB continuous aggregate alike, which is the right
question only when all you need to know is whether a `SELECT` will be answered.
`getRelationKind()` is the one to ask before a drop:

```php
$kind = $schema->getRelationKind('applications.usage_statistics');

// null → nothing owns the name, free to create
// 'v'  → a plain view (a continuous aggregate reports 'v' too)
// 'm'  → a materialized view
// 'r'  → an ordinary table  ('p' partitioned, 'f' foreign)

if ($kind === 'v') {
    $schema->dropView('applications.usage_statistics');
}
```

The letters are PostgreSQL's own `pg_class.relkind` values. MySQL has neither
materialized views nor a relkind, so it answers with the only two it can distinguish,
`v` and `r`.

The reason to reach for it is rarely a re-run — a view the framework created is a view
it can replace. It is a *different owner*: an application that made the same name a
materialized view because it wanted a cached read, and refreshes it on a schedule of
its own. Dropping that relation swaps a cached read for a full aggregation underneath
a running installation, and leaves whatever refreshes it pointed at nothing. Neither
belongs in a migration, so check the kind and leave what you did not create.

## Introspection

```php
if ($schema->hasTable('users')) {
    // table exists
}

if ($schema->hasColumn('users', 'email')) {
    // column exists
}

if ($schema->hasIndex('users', 'idx_users_email')) {
    // index exists
}
```

`hasTable()` and `hasColumn()` remember a table or column they **found**, per connection, for
the life of the process. Code that guards on an optional table can ask on every call and pay
one catalogue query a process. A missing table is never remembered, so one a migration creates
is seen at once. The answers are forgotten:
- by every DDL method of the builder;
- by any `DROP`, `ALTER` or `RENAME` the same connection runs, hand-written included;
- by the migration runner, before and after each migration.

A table dropped by **another** connection or process is caught three ways:
- a query that fails because the table is not there (MySQL 1146, PostgreSQL 42P01) makes the
  connection forget, so the process is wrong once and right from then on;
- a migration moves the schema generation, which restarts the orchestrator's daemons and makes
  any worker loop forget between tasks — see the Workers and Daemons Guide;
- and by hand, after DDL through a second connection in the same process:

```php
\Pramnos\Database\SchemaBuilder::forgetWhatExists();      // every connection
\Pramnos\Database\SchemaBuilder::forgetWhatExists($db);   // one
```

```php

$schema->columnLength('usertokens', 'token');   // 255 for a varchar(255); 0 for text, a number, or no answer

// By what they cover, not by name: a table older than the framework holds the same index as
// `tokenid` and the same key as `usertokens_ibfk_1`, and a check by name adds a second one.
$schema->indexColumns('tokenactions');                         // ['tokenid' => ['tokenid'], …]
$schema->hasIndexOn('tokenactions', ['action_time', 'method']); // any name, these columns in this order
$schema->hasForeignKeyOn('usertokens', 'applicationid', 'applications');

if ($schema->hasView('reports.daily')) {
    // a plain view, a materialized view or a continuous aggregate — any of the three
}

$schema->getRelationKind('reports.daily'); // 'v' | 'm' | 'r' | null — which one it is
```

`hasIndex()` matches on the index **name**, not on its columns. Two indexes over the
same columns are legal, so a guard asking "is there an index on this column" would
skip creating the one a migration needs because an unrelated one happens to cover the
same ground. A constraint-backed index — a UNIQUE constraint, a primary key — counts
as existing, because what a caller is really asking is whether creating it would
collide.

It is what makes an index idempotent in a migration:

```php
if (!$schema->hasIndex('usertokens', 'idx_usertokens_token_lookup')) {
    $schema->alterTable('usertokens', function ($table) {
        $table->unique(['token_lookup'], 'idx_usertokens_token_lookup');
    });
}
```

Without it the alternatives are catching the driver's duplicate-index error or leaving the
guard out, which are two ways of writing "I could not ask".

**Each driver asks its own question, and PostgreSQL asks a different one.** MySQL reads
`information_schema.statistics`; PostgreSQL reads `pg_indexes`, because the standard has no index
view — indexes are not part of it — and `information_schema.statistics` does not exist there at all.
A grammar for a driver the framework does not know inherits the MySQL-shaped query, on the
reasoning that an unknown driver is more likely to resemble that than nothing.

Two consequences worth knowing when a guard answers wrongly:

- **A qualified name is split on PostgreSQL.** `authserver.usertokens` becomes schema
  `authserver`, table `usertokens` — without that, `tablename = 'authserver.usertokens'` matches
  nothing and every index on the framework's own tables reads as absent.
- **With no schema, PostgreSQL excludes `pg_catalog` and `information_schema`** rather than
  searching everything. Those carry thousands of indexes, and a name colliding with one of them
  would answer yes for a table your project owns.



## TimescaleDB Operations

All TimescaleDB methods return `false` silently on non-TimescaleDB backends:

```php
$schema->createHypertable('events', 'created_at', [
    'chunk_time_interval' => '7 days',
]);

$schema->addSpaceDimension('events', 'device_id', 4);

$schema->enableCompression('events', [
    'segmentby' => 'device_id',
    'orderby'   => 'created_at DESC',
]);

$schema->addCompressionPolicy('events', '60 days');
$schema->addRetentionPolicy('events', '365 days');

// Continuous aggregate — TimescaleDB native / PG MATERIALIZED VIEW / MySQL VIEW
$schema->createContinuousAggregate(
    'hourly_events',
    "SELECT time_bucket('1 hour', created_at) AS bucket, COUNT(*) FROM events GROUP BY bucket"
);
```

**Not every aggregate can be one.** TimescaleDB requires a continuous aggregate to select
from a hypertable, to bucket on that hypertable's time dimension with `time_bucket()`, and
to stay inside the SQL it can maintain incrementally — so a query built on CTEs, or over an
ordinary table, is refused at `CREATE` rather than merely being slow. The boundary, the
exact refusals, a qualifying/non-qualifying pair, and the materialised-view-plus-`add_job`
fallback with the three costs it carries are in
**[Caching an aggregate: continuous aggregate, or materialised view](Pramnos_Hypertable_Guide.md#caching-an-aggregate-continuous-aggregate-or-materialised-view)**.
Read it before designing a rollup, not after the refusal.

**What is materialised when it is created.** The aggregate is created `WITH NO DATA` and then
refreshed up to `now()`. TimescaleDB materialises only buckets that lie wholly inside a refresh
window, so every closed bucket is filled in and **the open one is left live**: a real-time
aggregate (`timescaledb.materialized_only = false`) goes on showing rows that arrive in it.
PostgreSQL's default, `WITH DATA`, would materialise the open bucket as well and move the
watermark to its end — freezing it at the moment of creation until it closed, which the refresh
policy cannot undo because it refreshes closed buckets only. Over an integer time column the
initial refresh is refused (it takes an integer window); the aggregate is created empty, the
`migrations` log says so, and its refresh policy fills it in.

`createContinuousAggregate()` is safe to call against a name that is already taken: it
returns without issuing any DDL and writes a line to the `migrations` log saying the
existing definition was kept.

That guard is there because neither `CREATE MATERIALIZED VIEW` nor MySQL's `CREATE VIEW`
is idempotent. Without it the call raises `continuous aggregate "…" already exists` and
takes the surrounding migration with it — on an installation whose only difference is
that it built the same rollup first, which is a common thing for an application to have
done.

Two consequences to know:

- **The definition in force is the one that was already there.** A changed aggregate
  body does not land on an installation that has the old one; dropping and rebuilding
  it is a deliberate act, because on TimescaleDB it discards materialised history.
- **The log line is the only notice.** It is written to the `migrations` channel, not
  raised, so a migration that must know should ask `hasView()` itself first.

Creating an aggregate does not schedule its refresh. `addContinuousAggregatePolicy()`
does that — a native job on TimescaleDB, a row in `pramnos.framework_policies` on every
other backend — and `Pramnos\Database\ContinuousAggregateRegistry` is where the
framework declares the parameters once, so the creating migration and a later repair
use the same ones. See
**[Keeping a rollup refreshed](Pramnos_Hypertable_Guide.md#keeping-a-rollup-refreshed)**:
an unrefreshed materialised view is frozen at the moment it was created, which is worse
than a missing one because it still answers.

## Capability-Conditional DDL

`ifCapable()` on `SchemaBuilder` runs a callback only when the backend supports the capability.

```php
$schema->createTable('#PREFIX#events', function ($table) {
    $table->bigIncrements('eventid');
    $table->string('action', 64);
    $table->integer('userid')->nullable();
    $table->timestampTz('action_time')->default(new \Pramnos\Database\Expression('NOW()'));
});

$schema->ifCapable(\Pramnos\Database\DatabaseCapabilities::TIMESCALEDB,
    function (\Pramnos\Database\SchemaBuilder $schema) {
        $schema->createHypertable('#PREFIX#events', 'action_time', [
            'chunk_time_interval' => '14 days',
        ]);
        $schema->enableCompression('#PREFIX#events', ['segmentby' => 'action']);
        $schema->addCompressionPolicy('#PREFIX#events', '60 days');
    }
    // No fallback — stays as regular table on MySQL and plain PostgreSQL
);

// With fallback
$schema->ifCapable(
    \Pramnos\Database\DatabaseCapabilities::MATERIALIZED_VIEWS,
    fn($s) => $s->createMaterializedView('mv_stats', $query),
    fn($s) => $s->createView('mv_stats', $query) // MySQL: regular VIEW
);
```

**API:** `ifCapable(string $capability, callable $callback, ?callable $fallback = null): mixed`  
The `$callback` receives `SchemaBuilder $schema` (not `Database`).

### `ifCapable()` or `whenCapable()`

Inside a migration the two differ in what happens when the capability is absent:

| | Absent, no fallback | Use when |
|---|---|---|
| `ifCapable()` | The migration is **declined** — recorded, reported, retried by the next `migrate` | The capability *is* the work: a continuous aggregate, a conversion that is all the migration does |
| `whenCapable()` | Nothing, and the migration is applied | An extra on work already done that something else repairs later — a table's hypertable, which `HypertableRegistry` declares and `timescale:ensure` reapplies |

A guard on an engine — `ENGINE_POSTGRESQL`, `ENGINE_MYSQL`, `ENGINE_MARIADB` — never declines:
the migration is choosing its dialect, and a MariaDB will not become PostgreSQL.

```php
$schema->createTable('authserver.twofactor_attempts', function ($table) { /* … */ });

// The table is the work; the hypertable is an extra.
$schema->whenCapable(DatabaseCapabilities::TIMESCALEDB, function () use ($schema) {
    HypertableRegistry::apply($schema, 'authserver.twofactor_attempts');
});
```

### DatabaseCapabilities Constants

| Constant | True when |
|---|---|
| `DatabaseCapabilities::TIMESCALEDB` | TimescaleDB |
| `DatabaseCapabilities::MATERIALIZED_VIEWS` | PostgreSQL or TimescaleDB |
| `DatabaseCapabilities::ENUMS` | PostgreSQL (native `CREATE TYPE … AS ENUM`) |
| `DatabaseCapabilities::JSONB` | PostgreSQL |
| `DatabaseCapabilities::SEQUENCES` | PostgreSQL, MariaDB ≥ 10.3 |
| `DatabaseCapabilities::RETURNING` | PostgreSQL, MariaDB ≥ 10.5 |
| `DatabaseCapabilities::NATIVE_JSON` | PostgreSQL, MySQL ≥ 5.7.8 (**not** MariaDB) |
| `DatabaseCapabilities::CHECK_CONSTRAINTS` | PostgreSQL, MariaDB ≥ 10.2, MySQL ≥ 8.0.16 |

Convenience methods: `hasMaterializedViews(): bool`, `hasEnums(): bool`, `hasTimescaleDB(): bool`,
`hasSequences(): bool`, `hasReturning(): bool`, `hasNativeJson(): bool`, `hasCheckConstraints(): bool`.

### Engine, flavor and version

`isMySQL()` means *"the MySQL family"* and is therefore **true on MariaDB as well**. Every
capability gate in the framework reads it as "compile MySQL-compatible grammar" — backtick
quoting, `information_schema` introspection, `AUTO_INCREMENT` — all of which MariaDB does.

`isMariaDB()` is the narrowing predicate: true only when the MySQL-family server is
specifically MariaDB. It implies `isMySQL() === true`.

```php
$caps = $db->schema()->getCapabilities();

$caps->isMySQL();      // true on MySQL *and* MariaDB
$caps->isMariaDB();    // true only on MariaDB
$caps->getVersion();   // "10.11.6" — normalised, vendor noise stripped
$caps->atLeast('10.5') // version-aware gating
```

The flavor is detected from the **live server version string**, never from configuration:
MariaDB installations are configured as `type = 'mysql'` and must stay that way. Detection
degrades safely — an unconnected `Database` reports an unknown version, and every
version-gated capability then answers `false`, so new behaviour is always opt-in.

Prefer asking the feature question over the identity question:

```php
// Good — works on PostgreSQL and MariaDB 10.3+, no-ops elsewhere
if ($caps->has(DatabaseCapabilities::SEQUENCES)) { … }

// Fragile — says nothing about what the server can actually do
if ($caps->isPostgreSQL()) { … }
```

## Triggers

```php
$schema = $db->schema();

// MySQL trigger
$schema->createTrigger(
    'trg_log_insert',
    'orders',
    'AFTER',
    'INSERT',
    "BEGIN
        INSERT INTO order_audit (order_id, action, created_at)
        VALUES (NEW.id, 'insert', NOW());
    END"
);

// PostgreSQL trigger (body references a trigger function)
$schema->createTrigger(
    'trg_log_insert',
    'orders',
    'AFTER',
    'INSERT',
    'EXECUTE FUNCTION log_order_insert()'
);

// Drop with IF EXISTS guard
$schema->dropTrigger('trg_log_insert', 'orders', ifExists: true);
```

| | MySQL | PostgreSQL |
|---|---|---|
| Trigger body | Inline `BEGIN … END` PL/SQL | `EXECUTE FUNCTION fn_name()` |
| DDL verb | `CREATE TRIGGER` | `CREATE OR REPLACE TRIGGER` |
| Drop syntax | `DROP TRIGGER [IF EXISTS] name` | `DROP TRIGGER [IF EXISTS] name ON table` |

## Sequences (PostgreSQL and MariaDB 10.3+)

Sequences are monotonically increasing integer generators. They are supported on PostgreSQL and
on MariaDB 10.3+, where the framework selects a MariaDB DDL grammar that emits real
`CREATE SEQUENCE` / `NEXTVAL()` / `SETVAL()` / `DROP SEQUENCE` statements.

On Oracle MySQL — and on a MariaDB older than 10.3, which has no sequence objects — all methods
are **silent no-ops** and return `0` as a sentinel.

```php
$schema = $db->schema();

// Create a sequence
$schema->createSequence(
    'order_seq',
    start:     1000,
    increment: 5,
);

// Advance and read
$id = $schema->nextVal('order_seq');

// Reposition
$schema->setVal('order_seq', 500, isCalled: false);
$next = $schema->nextVal('order_seq'); // → 500 (exact value)

// Drop
$schema->dropSequence('order_seq', ifExists: true);
```

**Batch ID reservation pattern:**

```php
$schema->setVal('order_seq', $currentMax + 1000, isCalled: false);
// Assign IDs locally without DB round-trips for the next 1000 records
```

MySQL compatibility:

```php
$id = $schema->nextVal('order_seq');
if ($id === 0) {
    // No sequence objects on this server — fall back to AUTO_INCREMENT
    $db->query("INSERT INTO orders ...");
    $id = (int) $db->getInsertId();
}
```

Or ask before you act, which is clearer than reading a sentinel:

```php
if ($schema->getCapabilities()->hasSequences()) {
    $id = $schema->nextVal('order_seq');
} else {
    // AUTO_INCREMENT path
}
```

Note the dialect differences the grammar hides for you: MariaDB spells the negative cycle
option `NOCYCLE` (one word, not PostgreSQL's `NO CYCLE`), and its `NEXTVAL`/`SETVAL` take a
bare identifier rather than PostgreSQL's string literal. MariaDB's third `SETVAL` argument is
named `is_used` but carries the same meaning as PostgreSQL's `is_called`.

## Time Bucketing

`QueryBuilder::timeBucket(string $interval, string|Expression $column): Expression` returns a dialect-appropriate SQL expression:

```php
$qb  = $db->queryBuilder();
$bucket = $qb->timeBucket('15 minutes', 'recorded_at');

$result = $qb
    ->select([$bucket . ' AS bucket', 'AVG(value) AS avg_value'])
    ->from('sensor_readings')
    ->groupBy([$bucket])
    ->orderBy($bucket, 'asc')
    ->get();
```

| Interval | TimescaleDB | PostgreSQL | MySQL |
|---|---|---|---|
| `'1 hour'` | `time_bucket('1 hour', col)` | `date_trunc('hour', col)` | `FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(col)/3600)*3600)` |
| `'15 minutes'` | `time_bucket('15 minutes', col)` | `to_timestamp(floor(extract(epoch from col)/900)*900)` | `FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(col)/900)*900)` |
| `'1 day'` | `time_bucket('1 day', col)` | `date_trunc('day', col)` | `FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(col)/86400)*86400)` |
| `'1 month'` | `time_bucket('1 month', col)` | `date_trunc('month', col)` | `DATE_FORMAT(col, '%Y-%m-01')` |

## Grammar Injection

```php
$schema->setGrammar(new \Pramnos\Database\Grammar\PostgreSQLSchemaGrammar());
$grammar = $schema->getGrammar(); // SchemaGrammarInterface
```

## Migration-Support Helpers

These methods are primarily used inside `Migration` subclasses:

```php
// Execute a raw DDL statement
$this->DB()->statement("CREATE OR REPLACE FUNCTION ...");

// Execute a SELECT and return the first row
$row = $this->DB()->selectOne(
    "SELECT 1 FROM information_schema.tables WHERE table_name = ?",
    ['users']
);

// Get the PDO-compatible driver name
if ($this->DB()->getDriverName() === 'pgsql') { /* PostgreSQL branch */ }

// Get capabilities
if ($this->DB()->capabilities()->hasTimescaleDB()) { /* hypertable branch */ }

// Schema-qualified builder
$this->schema('public')->create('users', function ($table) { ... });
```
