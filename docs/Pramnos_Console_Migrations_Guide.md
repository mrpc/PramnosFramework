---
use_cases:
  - Running and writing migrations from the console
  - Moving old log files into the database
  - Seeding a database
  - Using the interactive migration wizard
---

# Migrations and seeds from the console

Part of the [console guide](Pramnos_Console_Guide.md).

## Migration System

### Creating Migrations

```bash
php bin/pramnos create:migration create_users_table
```

This writes `app/migrations/<Y_m_d_His>_create_users_table.php` — the timestamp prefix keeps
the files in order — rendered from `scaffolding/templates/migration.stub`:

```php
<?php
namespace MyApp\Migrations;

use Pramnos\Database\Blueprint;
use Pramnos\Database\Migration;

/**
 * create_users_table
 * Auto-generated: 25/12/2024 10:30
 */
final class CreateUsersTable extends Migration
{
    public $description = 'create_users_table';

    /**
     * Set true to wrap up() in BEGIN/COMMIT/ROLLBACK on PostgreSQL.
     * Leave false for TimescaleDB-native operations (e.g. createHypertable).
     */
    public bool $transactional = false;

    public function up(): void
    {
        // TODO: implement
    }

    public function down(): void
    {
        // TODO: implement
    }
}
```

The class name is the PascalCase of the name given. There is no registry to update: the
migration loader discovers every file in `app/migrations/`, and `php bin/pramnos migrate`
runs the pending ones. Called **without** a name, `create:migration` starts the
[interactive wizard](#interactive-migration-wizard) instead.

## Log Migration

### Migrating Log Files

`logs:convert` converts older log formats to the structured single-line JSON format:

```bash
# Migrate all .log files in a directory
php bin/pramnos logs:convert /path/to/logs --all

# Migrate specific file
php bin/pramnos logs:convert /path/to/application.log

# Migrate without backup
php bin/pramnos logs:convert /path/to/application.log --no-backup
```

### Migration Features

- **Preserves timestamps** - Extracts timestamps from various log formats
- **Handles multiline logs** - Properly processes stack traces and error messages
- **Creates backups** - Original files are backed up with `.bak` extension
- **Progress tracking** - Shows progress bar for large files
- **Error handling** - Continues processing if individual lines fail

### Example Migration Output

With `--all` over a directory:

```
Processing: application.log
 1000/1000 [============================] 100%  1 sec

Migration Summary:
Files processed: 1/1 (Failed: 0)
Lines processed: 1000 (Converted: 987)
Duration: 0.45 seconds
```

The `Files processed` line appears only in directory mode.

## db:seed — Database Seeder Command

```bash
# Run all seeders in database/seeds/
php bin/pramnos db:seed

# Run a single seeder by class name
php bin/pramnos db:seed UsersSeeder

# Run seeders from a custom directory
php bin/pramnos db:seed --path=/custom/seeds/
```

Seeders must extend `Pramnos\Database\Seeder` and implement `run()`:

```php
use Pramnos\Database\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insert('users', [
                'name'  => 'User ' . $i,
                'email' => 'user' . $i . '@example.com',
            ]);
        }
    }
}
```

Default seeds path: `ROOT . '/database/seeds/'`. Exit codes: `0` = success, `1` = failure.

---

## Interactive Migration Wizard

`create:migration` (called without a name argument) launches an interactive wizard:

```bash
php bin/pramnos create:migration
```

The wizard collects the full schema definition — columns, types, nullable/default/unique flags, foreign keys — and writes a migration with a complete `up()` / `down()`. After generating the migration it prompts to optionally create a Model, Web Controller, API Controller, and Seeder from the same session.

```
 Migration description: create users table
 Table name (use #PREFIX# for the db prefix, e.g. #PREFIX#users): #PREFIX#users
 Add auto-increment primary key userid? [yes]
 Does this table hold personal data (anything about an identifiable person)? [no] yes

 ── Columns ──────────────────────────────────────────────────────────
 Tip: to set an empty string as default, type '' (two single quotes).
 Column name (Enter to finish): name
   Type [string (VARCHAR)]:
   Length [255]: 100
   Nullable? [no]:
   Default value (blank = none, '' = empty string):
   Unique? [no]:

 Column name (Enter to finish):              ← blank = done

 Add timestamps (created_at / updated_at)? [yes]
 Add another table to this migration? [no]:

 ✓ Migration created: app/migrations/2026_05_06_120000_create_users_table.php

 Personal data — add this to your application config (app.php), or those rows
 are readable by every diagnostic tool that reads the database:

     'personal_data' => ['tables' => ['users']],

 See docs/Pramnos_Security_Guide.md — "Personal data and the denial list".

 Run this migration now? [yes]

 ── Also create ───────────────────────────────────────────────────────
 Create Model (Users)? [yes]
 Create Web Controller (UsersController)? [yes]
 Create API Controller (UsersApiController)? [yes]
 Create Seeder (UsersSeeder with fake data)? [yes]
```

### The personal-data question

Asked once per table, and the answer is not written into the migration — it is
printed as a line to paste into `app.php`. That is deliberate: the declaration has
to live where the application is configured, because it is read at boot by
processes that never load a migration file.

The question is there because the list it feeds is a **denial** list. A table
nobody classifies is readable in full by anything that reads the database on a
developer's behalf, and the standing weakness of a denial list is that it is a
list somebody has to remember to update later. Creating the table is the one
moment somebody knows the answer.

Answering `no`, or ignoring the printed line, is not a hole in the floor: column
names like `email`, `phone` and `token` are withheld wherever they appear,
whatever table they are in. That is a heuristic and it is stated as one — it
reduces what leaks from a table nobody classified, and it does not replace
classifying it. See the
[Security guide](Pramnos_Security_Guide.md#personal-data-and-the-denial-list).

### UI-aware controller and view generation

The wizard reads the application's UI setup:

| Setting | Effect on the generated code |
|---|---|
| `scaffold_theme` in `app/app.php` (`plain-css`, `bootstrap`, `tailwind`) | Which view templates are written; `bootstrap` also sets `$dt->bootstrap = true` |
| Select2 registered on the Document (`isScriptRegistered('select2')`) | Foreign-key fields load their options over AJAX from `fkOptions()` instead of in full |

The list view is always the framework's server-side DataTable, whatever the theme.

### Non-interactive usage

```bash
php bin/pramnos create:migration create_users_table   # blank stub, no wizard
```

### Seeder fake-data heuristics

| Column name contains | Generated fake value |
|---|---|
| `email` | `'user' . $i . '@example.com'` |
| `name` | `'Name ' . $i` |
| `status` | `['active','inactive','pending'][$i % 3]` |
| `password` | `password_hash('password' . $i, PASSWORD_DEFAULT)` |
| `token` | `bin2hex(random_bytes(16))` |
| `uuid` type | UUID v4 formatted string |
| `boolean` type | `($i % 2 === 0)` |
| `decimal/float` type | `round($i * 9.99, 2)` |
| fallback | `'value_' . $i` |

Auto-managed columns (`id`, `created_at`, `updated_at`, `deleted_at`) are never included in seed inserts.

---
