# Pramnos Schema Builder Guide

The **Schema Builder** (DDL — Data Definition Language) provides a programmatic interface for defining and modifying database schemas. It supports MySQL 8.0+, PostgreSQL 14+, and TimescaleDB with dialect-aware syntax.

**Class:** `Pramnos\Database\SchemaBuilder`  
**Entry point:** `$db->schemaBuilder()` — returns a fresh builder bound to the current database connection.

## Overview

The Schema Builder enables you to:

- Create and drop tables with proper type mapping
- Add, modify, and drop columns
- Define indexes, primary keys, and foreign keys
- Create views and stored procedures (dialect-specific)
- Manage TimescaleDB hypertables and time-series features
- Detect database capabilities and conditionally execute DDL

**Entry point:** `$db->schema()` — preferred alias. `$db->schemaBuilder()` also works.

## Getting Started

### Create a Table

```php
$db = \Pramnos\Database\Database::getInstance();
$schema = $db->schemaBuilder();

$schema->create('users', function ($table) {
    $table->id();                                    // BIGINT PRIMARY KEY AUTO_INCREMENT
    $table->string('username', 50)->unique();       // VARCHAR(50) UNIQUE
    $table->string('email')->unique();              // VARCHAR(255) UNIQUE
    $table->string('password', 60);                 // VARCHAR(60) for hashed password
    $table->timestamps();                           // created_at, updated_at
    $table->timestamp('last_login')->nullable();    // nullable timestamp
    $table->boolean('active')->default(true);       // BOOLEAN DEFAULT TRUE
    $table->index('email');                         // Email index for quick lookups
});
```

### Modify an Existing Table

```php
$schema->table('users', function ($table) {
    $table->string('phone')->nullable();            // Add column
    $table->dropColumn('legacy_field');             // Remove column
    $table->changeColumn('username', 'string', 100); // Modify column type
    $table->dropIndex('email');                     // Remove index
});
```

### Drop a Table

```php
$schema->dropIfExists('users');
```

## Column Types

The Schema Builder maps PHP type hints to database-native types:

| Method | MySQL | PostgreSQL | Notes |
|---|---|---|---|
| `id()` | BIGINT AUTO_INCREMENT | BIGSERIAL | Primary key |
| `string($name, $length = 255)` | VARCHAR(n) | VARCHAR(n) | |
| `integer($name)` | INT | INT | 32-bit |
| `bigInteger($name)` | BIGINT | BIGINT | 64-bit |
| `text($name)` | TEXT | TEXT | Large text |
| `boolean($name)` | BOOLEAN | BOOLEAN | 0/1 or true/false |
| `float($name, $precision, $scale)` | FLOAT | REAL | |
| `decimal($name, $precision, $scale)` | DECIMAL(p,s) | NUMERIC(p,s) | |
| `date($name)` | DATE | DATE | |
| `time($name)` | TIME | TIME | |
| `dateTime($name)` | DATETIME | TIMESTAMP | |
| `timestamp($name)` | TIMESTAMP | TIMESTAMP | Server timestamp |
| `json($name)` | JSON | JSON / JSONB | |
| `uuid($name)` | CHAR(36) | UUID | |
| `enum($name, $values)` | ENUM | Enum (PG only) | |

## Column Modifiers

```php
$table->string('email')
    ->nullable()           // allow NULL
    ->default('guest')     // set DEFAULT
    ->unique()             // UNIQUE constraint
    ->index()              // create index
    ->after('username')    // column ordering (MySQL)
    ->first()              // place at start (MySQL)
    ->comment('User email'); // column comment
```

## Indexes

```php
$table->primary('userid');                  // PRIMARY KEY
$table->unique('email');                    // UNIQUE index
$table->index('created_at');                // Regular index
$table->fullText('description');            // Full-text index (MySQL)
$table->spatialIndex('location');           // Spatial index (MySQL)

// Composite indexes
$table->index(['country', 'city']);
$table->unique(['userid', 'token']);
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

## Timestamps & Tracking

```php
$table->timestamps();        // created_at, updated_at (both TIMESTAMP)
$table->softDeletes();       // deleted_at (nullable TIMESTAMP)
$table->userstamps();        // created_by, updated_by (user IDs)
$table->rememberToken();     // For API tokens (String)
```

## Conditional DDL (DatabaseCapabilities)

Execute DDL conditionally based on database features:

```php
$caps = new \Pramnos\Database\DatabaseCapabilities($db);

$caps->ifCapable(
    \Pramnos\Database\DatabaseCapabilities::FEATURE_TIMESCALEDB,
    function () use ($schema) {
        // TimescaleDB only — create hypertable
        $schema->create('metrics', function ($table) {
            $table->id();
            $table->timestamp('time')->index();
            $table->float('value');
        });
        $schema->createHypertable('metrics', 'time');
    },
    function () use ($schema) {
        // Plain PostgreSQL or MySQL fallback
        $schema->create('metrics', function ($table) {
            $table->id();
            $table->timestamp('time')->index();
            $table->float('value');
        });
    }
);
```

## TimescaleDB Features

### Create Hypertables

```php
$schema->createHypertable('metrics', 'time', 'chunk_time_interval' => '1 day');
```

### Time Bucketing

```php
// Dialect-transparent time_bucket()
$result = $db->queryBuilder()
    ->select([
        $db->schemaBuilder()->timeBucket('1 hour', 'time'),
        $db->queryBuilder()->raw('AVG(value) as avg_value'),
    ])
    ->from('metrics')
    ->groupBy(1)
    ->get();
```

### Retention Policies

```php
$schema->addRetentionPolicy('metrics', '30 days');
```

### Continuous Aggregates

```php
$schema->createContinuousAggregate('metrics_hourly', function ($q) {
    $q->select([
        $db->schemaBuilder()->timeBucket('1 hour', 'time'),
        $q->raw('AVG(value) as avg_value'),
    ])
    ->from('metrics')
    ->groupBy(1);
});
```

## Views

```php
// Create a view from a QueryBuilder
$schema->createView('active_users', function () use ($db) {
    return $db->queryBuilder()
        ->select('userid', 'username', 'email')
        ->from('users')
        ->where('active', 1);
});

// Drop a view
$schema->dropView('active_users');

// Materialized view (PostgreSQL)
$schema->createMaterializedView('user_stats', function () use ($db) {
    return $db->queryBuilder()
        ->select('userid', $db->queryBuilder()->raw('COUNT(*) as post_count'))
        ->from('posts')
        ->groupBy('userid');
});
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

## Introspection

```php
if ($schema->hasTable('users')) {
    // table exists
}

if ($schema->hasColumn('users', 'email')) {
    // column exists
}
```

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

### DatabaseCapabilities Constants

| Constant | True when |
|---|---|
| `DatabaseCapabilities::TIMESCALEDB` | TimescaleDB |
| `DatabaseCapabilities::MATERIALIZED_VIEWS` | PostgreSQL or TimescaleDB |
| `DatabaseCapabilities::ENUMS` | PostgreSQL (native `CREATE TYPE … AS ENUM`) |
| `DatabaseCapabilities::JSONB` | PostgreSQL |

Convenience methods: `hasMaterializedViews(): bool`, `hasEnums(): bool`, `hasTimescaleDB(): bool`.

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

## Sequences (PostgreSQL only)

Sequences are monotonically increasing integer generators. All methods are **silent no-ops** on MySQL and return `0` as a sentinel.

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
    // Running on MySQL — fall back to AUTO_INCREMENT
    $db->query("INSERT INTO orders ...");
    $id = $db->insertId();
}
```

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

## Backward Compatibility

- `$db->schemaBuilder()` continues to work; `$db->schema()` is the preferred alias.
- `SchemaBuilder` previously existed as a stub. All original methods (`create()`, `drop()`, `truncate()`, `createHypertable()`, `addRetentionPolicy()`) still exist with the same signatures.
- `ColumnDefinition`, `ForeignKeyDefinition`, and `Blueprint` are new — purely additive.
- `Blueprint::addColumn()` was `protected`; it is now `public`.
