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

**For detailed API reference, see:**
- [v1.2 New Features — DDL Schema Builder](1.2-new-features.md#6-ddl-schema-builder)

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

## Reference

For the complete API reference with all methods and options, see:

- [v1.2 New Features — DDL Schema Builder](1.2-new-features.md#6-ddl-schema-builder)

**Topics covered in the detailed reference:**

- Table creation and modification API
- All column types and modifiers
- Index definition (single, composite, full-text, spatial)
- Foreign key constraints
- View and materialized view creation
- TimescaleDB hypertables, retention policies, and continuous aggregates
- Capability detection with `ifCapable()`
- Dialect-specific DDL handling
