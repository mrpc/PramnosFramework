# Pramnos Migration Guide

The **Migration System** provides version control for your database schema. Migrations are PHP files that define schema changes and can be rolled back to any previous state.

**Classes:**
- `Pramnos\Database\Migration` — Base migration class
- `Pramnos\Database\MigrationRunner` — Execution engine
- `Pramnos\Database\MigrationLoader` — File discovery and loading

## Creating Migrations

### Generate a Migration File

```bash
php vendor/bin/pramnos make:migration create_users_table
php vendor/bin/pramnos make:migration add_email_to_users
```

This creates a file like `database/migrations/2026_05_29_000001_create_users_table.php`

### Migration Structure

```php
<?php

use Pramnos\Database\Migration;

class CreateUsersTable extends Migration
{
    /**
     * Run the migration.
     */
    public function up(\Pramnos\Database\Database $db)
    {
        $schema = $db->schemaBuilder();
        
        $schema->create('users', function ($table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->index('created_at');
        });
    }
    
    /**
     * Rollback the migration.
     */
    public function down(\Pramnos\Database\Database $db)
    {
        $schema = $db->schemaBuilder();
        $schema->dropIfExists('users');
    }
}
```

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

## Advanced: Using MigrationRunner Programmatically

```php
$db = \Pramnos\Database\Database::getInstance();
$runner = new \Pramnos\Database\MigrationRunner($db);
$loader = new \Pramnos\Database\MigrationLoader($db);

// Load migrations
$migrations = $loader->loadFromDirectories(['database/migrations']);

// Run all pending
$runner->run($migrations);

// Rollback
$runner->rollback($migrations, 1);  // rollback 1 batch
```

## Reference

For complete API reference and advanced features, see:

- [v1.2 New Features — Migration System Overhaul](1.2-new-features.md#9-phase-4-migration-system-overhaul)
- [v1.2 New Features — MigrationLoader and CLI](1.2-new-features.md#10-phase-4-migrationloader-and-cli-commands)

**Topics covered in the detailed reference:**

- Complete SchemaBuilder API (column types, indexes, etc.)
- Migration file discovery and loading
- Batch management and versioning
- Rollback semantics and rollback tracking
- Auto-run configuration and fingerprinting
- Framework vs application migration directories
- Migration testing and verification
