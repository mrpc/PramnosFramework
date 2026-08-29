---
use_cases:
  - Writing a new migration or naming its file
  - Running, rolling back or checking the status of migrations
  - Understanding batching, the migration cutoff, or how migrations are discovered
---

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
    public bool   $conditional   = false;       // true = creates nothing on some engines

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
| `$conditional` | `bool` | `false` | This migration legitimately creates nothing on some engines — see below |

### `$conditional` — a migration that correctly creates nothing

A few migrations are conditional by design. `pramnos.framework_policies` exists on MySQL and
plain PostgreSQL and **must not** exist on TimescaleDB, which manages its own policies: the
migration runs, records itself applied, and creates nothing — correctly.

From the outside that is indistinguishable from a migration whose table somebody dropped by
hand: the history says applied and the table is not there. That is the most alarming thing a
drift check can report, and reporting it about a migration behaving exactly as designed is how a
check stops being read. So the migration says so, and
[`schema-drift`](Pramnos_MCP_Guide.md#schema-drift) lists it apart.

It is **declared, not detected**: "does this `return` depend on the engine" is not a question to
answer by pattern-matching somebody's source.

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

Pending `autoExecute = true` migrations run automatically during the request
lifecycle (`Application::exec()`), guarded by a fingerprint fast-path so an
up-to-date schema costs a single indexed lookup. By default auto-run scans the
framework's per-feature migration directories (gated by the enabled `features`),
which is all a standard app needs.

An application can extend or narrow this through its `app.php` descriptor:

```php
// app.php
return [
    'namespace' => 'MyApp',
    'features'  => [],           // gates which framework feature dirs run

    // Auto-run configuration (all keys optional):
    'migrations' => [
        // Application-owned migration directories, scanned IN ADDITION to the
        // framework dirs (absolute paths). An app baseline placed here auto-runs
        // on the same fingerprint fast-path as framework migrations.
        'paths'     => [__DIR__ . '/Migrations'],

        // Set false to skip the framework feature directories entirely. Use this
        // when the app manages a schema that collides with a framework table
        // (e.g. its own `sessions` layout) — only the app's own `paths` run.
        // Defaults to true (unchanged behaviour for existing apps).
        'framework' => false,
    ],

    // Skip every migration at or before this timestamp. Set it to the baseline
    // timestamp so auto-run only applies genuinely new, post-baseline migrations
    // (the baseline itself is applied once by the explicit `migrate` command).
    'migration_cutoff' => '2025-01-01 00:00:01',
];
```

#### Standalone auto-migration (`Application::migrate()`)

Applications that do **not** go through the full `init()`/`exec()` request
lifecycle — for example a front controller using attribute routing, or a console
bootstrap — can still get auto-migration on every execution by calling the
public `migrate()` method:

```php
$app = \Pramnos\Application\Application::getInstance(); // resolves app.php
$app->migrate(); // connects the DB if needed, then runs pending migrations
```

`migrate()` connects the database if it is not already wired (without starting a
session, booting addons or running session tracking), honours the same `app.php`
`migrations`/`migration_cutoff`/`features` config as the lifecycle path, and
**never throws** — auto-migration is best-effort infrastructure, so failures are
logged rather than allowed to take down a request or a command.

#### Depending on a framework migration you don't otherwise run

An application that opts out of the framework directories (`'framework' =>
false`) — or simply doesn't enable a feature — can still pull in *individual*
framework migrations **on demand**, by declaring them as `$dependencies`:

```php
// app/Migrations/2026_03_01_000001_use_delayed_jobs.php
class UseDelayedJobs extends \Pramnos\Database\Migration
{
    // Pull in the framework's delayed_jobs table before this migration runs,
    // even though this app does not run the framework migrations wholesale.
    public array $dependencies = ['create_delayed_jobs_table'];

    public function up(): void { /* ... uses the delayed_jobs table ... */ }
}
```

When a declared dependency is neither in the current batch nor already applied,
the `MigrationRunner` resolves it from an **on-demand pool** of *all* framework
migrations (`Application::frameworkMigrationPool()` — not feature-gated, keyed by
slug), pulls it in transitively (its own dependencies too), and orders it before
its dependent. Only the migrations actually depended upon are executed — so you
adopt exactly the framework table/feature you need, and nothing else. A slug that
resolves from neither the batch nor the pool still fails with "unknown
dependency", so genuine misconfiguration is not masked. The pool is loaded
lazily, only when a missing dependency is actually encountered, so apps with no
cross dependencies pay nothing.

This works on both paths: the standalone/auto-run (`Application::migrate()`) and
the explicit `migrate` console command wire the same pool.

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
