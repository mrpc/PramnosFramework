# Pramnos Migration Guide

The **Migration System** provides version control for your database schema. Migrations are PHP files that define schema changes and can be rolled back to any previous state.

**Classes:**
- `Pramnos\Database\Migration` — Base migration class
- `Pramnos\Database\MigrationRunner` — Execution engine
- `Pramnos\Database\MigrationLoader` — File discovery and loading

## Migration Structure

### The Migration Base Class

```php
<?php

use Pramnos\Database\Migration;

class CreateUsersTable extends Migration
{
    public string $feature      = 'auth';       // Feature key ('auth', 'queue', ...)
    public string $scope        = 'framework';  // 'app' or 'framework'
    public int    $priority     = 20;           // Lower runs first
    public array  $dependencies = ['create_roles_table']; // Must run before this
    public string $description  = 'Creates the users table';
    public bool   $autorun      = true;         // false = requires --force
    public bool   $transactional = false;       // true = wrap in BEGIN/COMMIT on PostgreSQL

    public function up(): void
    {
        $this->schema()->createTable('users', function ($table) {
            $table->bigIncrements('userid');
            $table->string('username', 100)->unique();
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('users');
    }
}
```

### Migration Metadata Fields

| Property | Type | Default | Purpose |
|---|---|---|---|
| `$feature` | `string` | `''` | Feature key; empty = app migration |
| `$scope` | `string` | `'app'` | `'app'` or `'framework'` |
| `$priority` | `int` | `50` | Lower number runs first |
| `$dependencies` | `array` | `[]` | Slugs of migrations that must run before this one |
| `$autorun` | `bool` | `true` | `false` = requires `--force` |
| `$transactional` | `bool` | `false` | Wrap `up()` in `BEGIN`/`COMMIT`/`ROLLBACK` on PostgreSQL |

> **Note:** `$autoExecute` is a PHP 8.4 property hook that maps to `$autorun`. Existing code using `$autoExecute` continues to work unchanged.

### Protected Helpers

Migration subclasses have access to:

```php
// Get the database connection
$this->DB();

// Get a SchemaBuilder bound to the current connection
$this->schema();

// Schema-qualified builder (PostgreSQL)
$this->schema('public')->createTable('users', fn($t) => ...);
$this->schema('analytics')->createTable('events', fn($t) => ...);
```

### Slug and Timestamp Derivation

`getSlug()` and `getTimestamp()` check the **migration file's basename** first:

```
File:  app/Migrations/2024_03_15_143022_create_users_table.php
Class: CreateUsersTable

getSlug()      → 'create_users_table'   (from filename)
getTimestamp() → '2024_03_15_143022'    (from filename)
```

For legacy non-timestamped files, the class name is used as before.

## Running Migrations

### Manual Execution

```bash
# Run all pending migrations
php vendor/bin/pramnos migrate

# Rollback the last batch
php vendor/bin/pramnos migrate:rollback

# Rollback all migrations
php vendor/bin/pramnos migrate:reset

# Run specific migration
php vendor/bin/pramnos migrate --target=2026_05_29_000001_create_users_table
```

### Automatic Migration (Application Startup)

Migrations can run automatically when the application starts if configured:

```php
// In app configuration
'migrations' => [
    'autoExecute' => true,
    'directories' => ['database/migrations'],
    'migration_cutoff' => '2020_01_02_000000',  // Skip baseline migrations
],
```

## Migration Features

### Conditional DDL (Capabilities Check)

```php
public function up(\Pramnos\Database\Database $db)
{
    $caps = new \Pramnos\Database\DatabaseCapabilities($db);
    $schema = $db->schemaBuilder();
    
    $caps->ifCapable(
        DatabaseCapabilities::FEATURE_TIMESCALEDB,
        function () use ($schema) {
            // TimescaleDB-specific schema
            $schema->create('metrics', function ($table) {
                $table->timestamp('time')->index();
                $table->float('value');
            });
            $schema->createHypertable('metrics', 'time');
        },
        function () use ($schema) {
            // MySQL/PostgreSQL fallback
            $schema->create('metrics', function ($table) {
                $table->bigIncrements('id');
                $table->timestamp('time')->index();
                $table->float('value');
            });
        }
    );
}
```

### Raw SQL Migrations

```php
public function up(\Pramnos\Database\Database $db)
{
    // For complex operations not covered by SchemaBuilder
    $db->query("CREATE TRIGGER user_audit AFTER UPDATE ON users
               BEGIN
                 INSERT INTO audit_log VALUES (...);
               END");
}
```

### Data Migrations

```php
public function up(\Pramnos\Database\Database $db)
{
    // Migrate data between schemas
    $db->queryBuilder()
        ->from('old_table')
        ->get()
        ->chunk(100, function ($rows) use ($db) {
            foreach ($rows as $row) {
                $db->queryBuilder()
                    ->table('new_table')
                    ->insert([
                        'id' => $row['old_id'],
                        'name' => strtoupper($row['old_name']),
                    ]);
            }
        });
}
```

## Migration Files Naming

### Timestamp Convention

Migration files use the timestamp prefix: `YYYY_MM_DD_HHMMSS_description.php`

```
2026_05_29_090000_create_users_table.php
2026_05_29_091500_add_email_to_users.php
2026_05_30_143000_create_posts_table.php
```

The timestamp determines execution order.

### Framework vs Application Migrations

- **Framework migrations:** `database/migrations/framework/`  
  Created by the framework for core features.
  
- **Application migrations:** `database/migrations/app/`  
  Created by you for your application schema.

**Important:** Framework migrations use the `2020_01_01_*` baseline epoch. The `migration_cutoff` setting allows legacy installations to skip these.

## Batching & Rollback

Migrations are grouped into "batches." Each `migrate` run increments the batch number. You can rollback by batch:

```bash
# Rollback the latest batch
php vendor/bin/pramnos migrate:rollback

# Rollback specific number of batches
php vendor/bin/pramnos migrate:rollback --steps=3

# Rollback all batches
php vendor/bin/pramnos migrate:reset
```

## Status & Information

```bash
# Show migration status
php vendor/bin/pramnos migrate:status

# Show pending migrations
php vendor/bin/pramnos migrate:pending
```

## MigrationRunner

`MigrationRunner` handles execution order, history recording, rollback, and cutoff filtering.

**Namespace:** `Pramnos\Database\MigrationRunner`

```php
new MigrationRunner(
    ?Database $db = null,
    string $historyTable = 'framework_migrations',
    ?Application $app = null  // enables maintenance-mode integration
)
```

### Running Migrations

```php
$runner = new MigrationRunner($db);

// Run all pending migrations (sorted, filtered, recorded)
$result = $runner->run($migrations);
// $result = ['ran' => ['create_roles_table', ...], 'failed' => [...]]

// With options
$result = $runner->run($migrations, [
    'force'  => true,                    // include autorun=false migrations
    'cutoff' => '2022_01_01_000000',     // skip migrations at or before this date
]);
```

### History Table Schema

`ensureHistoryTable()` creates `framework_migrations` if it does not exist:

```sql
migration        VARCHAR(255)   -- slug, e.g. 'create_users_table'
scope            VARCHAR(255)   DEFAULT 'app'
feature          VARCHAR(255)   NULL
batch            INT            NULL
execution_time   DOUBLE         NULL    -- seconds
result           SMALLINT       DEFAULT 1   -- 1=success, 0=failed
error_message    TEXT           NULL
description      VARCHAR(255)   NULL
ran_at           TIMESTAMP      DEFAULT NOW()
```

### Sorting

`sort(array $migrations, array $alreadyRan = []): array`

Returns migrations in execution order:
1. Topological sort — dependencies run before dependents
2. Priority ascending — lower `$priority` runs first
3. Timestamp ascending — older `YYYY_MM_DD_HHmmss` prefix runs first

```php
$sorted = $runner->sort($migrations);
// Throws RuntimeException if a cycle is detected
```

### Filtering

```php
// Exclude autorun=false migrations (pass force=true to include them)
$runner->filterAutorun($migrations, force: false);

// Skip migrations at or before the cutoff timestamp
$runner->filterCutoff($migrations, cutoff: '2022_01_01_000000');

// Remove already-ran slugs
$runner->filterAlreadyRan($migrations, ranSlugs: ['create_roles_table']);
```

### Rollback

```php
// Rollback the last batch
$result = $runner->rollback($migrations);
// $result = ['rolledBack' => ['create_users_table', ...]]

// Rollback specific batch number
$result = $runner->rollback($migrations, ['batch' => 3]);

// Rollback all batches
$result = $runner->rollbackAll($migrations);
```

### Pending Migrations

```php
// Returns migrations whose slug does not appear as result=1 in history
$pending = $runner->getPending($migrations);

// History for migrate:status
$history = $runner->getHistory();
```

## MigrationLoader

Discovers and instantiates `Migration` subclasses from PHP files in a directory.

**Namespace:** `Pramnos\Database\MigrationLoader`

```php
// Load from one directory
$migrations = MigrationLoader::loadFromDirectory(
    ROOT . '/app/Migrations',
    $app
);

// Load from multiple directories
$migrations = MigrationLoader::loadFromDirectories(
    [
        ROOT . '/app/Migrations',
        ROOT . '/vendor/pramnos/framework/migrations',
    ],
    $app
);
```

Files are sorted alphabetically before loading, so `YYYY_MM_DD_HHmmss_` prefixes naturally produce chronological order.
