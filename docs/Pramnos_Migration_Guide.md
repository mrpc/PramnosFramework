---
use_cases:
  - Writing a new migration or naming its file
  - Running, rolling back or checking the status of migrations
  - Understanding batching, the migration cutoff, or how migrations are discovered
  - Fixing a migration that fails on an installation whose schema already has the object
  - Writing a migration that must coexist with an application's own tables and views
  - Working out why migrate:status reports migrations that will never run
  - Configuring migration_cutoff or the features gate for an existing schema
  - Finding out whether a migration's statements actually succeeded
  - Reading a Ran with errors row, or deciding a migration's outcome in its own code
  - Writing a migration that alters a table which may hold data it cannot accept
  - Backfilling a new column without running the deploy out of memory
  - Adopting a single framework migration without running the rest
  - Moving an application off the legacy schemaversion version ledger
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

### The CLI and the request lifecycle answer the same question

`migrate`, `migrate:status`, the MCP status tools and the dev panel's migration
card all read the same answer to "which migrations apply to this installation"
as auto-run does: `Application::migrationScope()`, reached through
`MigrationLoader::scopeFor($app)`. Two filters come from `app.php`, and both
apply on every one of those paths:

- **`features`** gates the framework's per-feature migration directories. A
  feature the application has not enabled contributes no migrations.
- **`migration_cutoff`** excludes every migration whose filename timestamp is at
  or before it. A migration with no timestamp in its name is never excluded by a
  cutoff, on either path.

This matters most on an installation whose schema predates the migration system.
Such an installation sets a cutoff precisely so the baseline epoch is skipped —
and a reader that ignored it would report the whole epoch as pending work and,
worse, attempt it.

**Overrides.** `--path` and `--cutoff` still win, because running one directory
or one epoch deliberately is what they are for. Note that they are independent:
`--path` names *where to look* and does not switch the cutoff off, so
`migrate --path=app/Migrations` on an installation with a cutoff still respects
it. Pass `--cutoff` to change that.

**What `migrate:status` shows.** Out-of-scope migrations are listed, not omitted,
with the reason in the Status column:

```
 create_sessions_table            | framework | core         | Skipped (cutoff)
 create_broadcast_events_table    | framework | broadcasting | Skipped (feature: broadcasting)
```

followed by a count and, when one is in force, the cutoff that decided it. The
report is the only place to find out *why* something is never going to run, so a
row that is out of scope says so rather than disappearing — a CLI that hides
pending migrations is as unhelpful as one that invents them. `--path` applies no
feature gate, because the directory was named explicitly.

**Without a database connection**, `migrate:status` says so and still lists what
it found on disk. The console reaches an application without initialising it, so
that is an ordinary state for the command; what is on disk does not depend on a
connection, and only the Ran/Pending distinction does.

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

**An adopter can be empty, and that is the point.** A migration whose entire body
is a `$dependencies` list is a legitimate and supported way to say «this
application needs that framework migration, and nothing else»:

```php
// app/Migrations/2026_09_05_000001_adopt_delayed_jobs.php
class AdoptDelayedJobs extends \Pramnos\Database\Migration
{
    public array $dependencies = ['create_delayed_jobs_table'];

    // No up(). The base class's is a no-op, and there is nothing to add:
    // the dependency does the work and is ordered before this.
}
```

The runner pulls the dependency from the pool, runs it first, and records both —
so neither runs again, and the adopter is a permanent record of *why* that
framework table exists in an application that does not run the framework's
migrations wholesale. Verified by
`LegacyLedgerAdoptionTest::testAnEmptyMigrationCanAdoptAFrameworkMigrationByDependency()`.

### Coming from the legacy version ledger

Both migration systems write to `schemaversion` and key it differently. The legacy
path — `Application::runMigration()` — stores a migration's `$version` (`0.010`);
the runner stores its slug (`migration0010`). An application that migrated for
years through the old path therefore has a full ledger that the runner cannot read
a single row of: `migrate:status` reports every one of those migrations as
pending, and `migrate` would run them again against a schema that already has
their changes.

`migration_cutoff` does not help, and it is worth knowing why: it filters on the
filename timestamp, and a legacy `MigrationNNNN` class has none — `filterCutoff()`
lets a migration with no timestamp through by design.

```bash
php pramnos migrate:adopt-legacy --dry-run   # what would be recorded
php pramnos migrate:adopt-legacy             # record it
```

For each of the application's migrations it looks up the `$version` the object
declares, and where the ledger already holds that version it records the object's
slug. **It records; it never executes** — the work was done by the other path
years ago, and running it again is the outcome this exists to prevent.

Two things it will not do, both deliberate:

- **A migration the ledger has never heard of stays pending.** The only evidence
  that it ran would be this inventing it, and a migration wrongly marked as
  applied is a schema change that silently never happens.
- **A slug the runner already knows is left alone**, so running it twice is a
  no-op.

A migration with no `$version` — every modern, timestamped one — is skipped, so
pointing this at a mixed directory is safe.

## Check the data before you change it

A statement that validates existing rows — `ADD CONSTRAINT`, `CREATE UNIQUE
INDEX` — succeeds on a fresh database and fails on the one the migration was
written for. A database that has run without a foreign key is exactly where a
deleted parent left a child row behind; a table that predates a unique index is
exactly where two rows share a value, because that is *why* the index is being
added.

Failing there is bad enough. Failing **halfway** is worse: a migration that drops
the old index before creating the new one leaves the installation with neither,
on a column every read uses.

So check first, and decline when the data is not ready.

```php
public function up(): void
{
    $duplicates = $this->duplicateGroups('#PREFIX#settings', 'setting');

    if ($duplicates !== []) {
        $named = [];
        foreach ($duplicates as $group) {
            $named[] = "'" . $group['value'] . "' (" . $group['rows'] . ' rows)';
        }

        $this->decline(
            'settings.setting has duplicate values, so a unique index cannot be created: '
            . implode(', ', $named)
            . '. Decide which row is correct, delete the others, and run migrate again.'
        );

        return;                 // nothing was changed
    }

    // … safe to proceed
}
```

### What the base class gives you

| Method | Answers |
|---|---|
| `orphanCount($table, $column, $refTable, $refColumn)` | how many rows would violate a foreign key |
| `duplicateGroups($table, $column, $limit = 5)` | which values repeat, and how often |
| `duplicateCount($table, $column)` | how many values repeat |
| `decline($reason)` | refuse, and record why |

`NULL` is not a violation in either: a nullable foreign key means «no parent» and
a unique index accepts any number of `NULL`s, so counting them would decline a
migration that would have worked.

**A count that cannot be taken is zero, not a refusal.** A missing table, a
permission, a view standing in for a table — declining on any of those would make
an unrelated fault look like dirty data and send an operator hunting rows that do
not exist. The statement itself is the check of last resort.

### A decline is loud, and it comes back

This is the part that separates a guard from a silence. `decline()` records
`RESULT_DECLINED` with the reason in `error_message`, so:

- `migrate` prints `Declined:` with the reason and a closing line saying these
  stay pending;
- `migrate:status` shows `Declined` in the Status column and prints the reason
  under the table;
- and because `getRanSlugs()` counts only `RESULT_OK`, **the migration is
  attempted again by the next `migrate`** — repair the rows and it applies itself.

`Declined` is not the same as the `Skipped (cutoff)` or `Skipped (feature: …)`
that `migrate:status` computes from scope. Those never ran. This one ran, looked
at the data, and said no.

**Decline; do not repair.** Deleting rows is not a migration's decision to take on
an operator's behalf. Two rows for `sitename` may mean the wrong one has been in
effect for months, and an orphaned audit row is a record of something that
happened. Name what is wrong and let a person choose.

### Backfills: scope them, bound them, and let SQL do what SQL can

A migration that fills a new column from an old one is where deploys die. Three
things, in ascending order of what they cost:

- **Say what you do not need.** A digest for a token that is revoked or expired is
  work for an answer nobody asks for, and on a long-lived installation those rows
  are most of the table.
- **Add the `WHERE` that makes it re-runnable.** Without `WHERE new_column IS
  NULL`, the second deploy pays the whole cost again.
- **Do not read the table into PHP.** Selecting every row before writing anything
  is what turns «slow» into «out of memory» — measured at 48 MB for 50 000 rows,
  so around a gigabyte at a million. Use a keyset cursor on the primary key, in
  batches, so the buffer stays flat and a table changing underneath cannot make an
  offset walk skip rows.

And when the value needs no PHP, one statement does it:

```php
// 130 ms where row-by-row PHP took 5 617 ms, on 50 000 rows
$digest = $caps->isPostgreSQL()
    ? "encode(sha256(token::bytea), 'hex')"
    : 'LOWER(SHA2(token, 256))';
```

Whatever expression you use, **assert in a test that it equals what PHP computes**.
A digest the database writes and the application does not match on is an
authentication outage, and nothing else in the suite would notice.

## When a statement is refused

`addQuery()` queues statements and `executeQueries()` runs them **tolerantly**: a
statement the database rejects does not stop the ones behind it. That is
deliberate and has to stay. A re-run of a migration whose
`ALTER TABLE … ADD COLUMN` is already applied must not abandon the eleven
statements after it, and installations exist with a hundred-odd numbered
migrations relying on exactly that.

What is *not* deliberate is losing the fact that it happened. `up()` returning
was once the only thing either ledger looked at, so a migration whose **every**
statement was rejected was indistinguishable from one that worked — recorded as
applied, reported by `migrate:status` as `Ran`, with the only trace in
`var/logs/upgradeerrors.log`, which nothing points at.

### The third state

`MigrationRunner` records one of three results:

| Constant | `result` | Meaning |
|---|---|---|
| `RESULT_OK` | `1` | completed, every statement accepted |
| `RESULT_RAN_WITH_ERRORS` | `2` | `up()` returned, but statements were rejected |
| `RESULT_FAILED` | `0` | `up()` threw; the migration did not complete |

`migrate:status` shows the middle one as `Ran with errors` and prints what was
rejected underneath the table. `migrate` prints `Migrated*` for it and lists the
statements in its summary. The `error_message` column carries the detail, so it is
reachable from the ledger rather than from a log file.

A migration in this state is still **recorded and still counted as run**, and
`migrate` still exits `0`. Both are deliberate: a redundant statement must not
make a migration re-run for ever, and must not break a deploy script that has
been re-running the same migrations for years. The change is that the report no
longer claims something that did not happen.

### Deciding for yourself

A migration can inspect its own outcome instead of verifying its work against the
schema afterwards to find out whether the framework's report of it was true:

```php
public function up(): void
{
    $this->addQuery('DROP MATERIALIZED VIEW reports.usage CASCADE');
    $failures = $this->executeQueries();   // number rejected

    if ($this->hasFailedStatements()) {
        // Every entry is ['query' => …, 'error' => …, 'benign' => bool]
        foreach ($this->getFailedStatements() as $failure) {
            if (!$failure['benign']) {
                throw new \RuntimeException(
                    'reports.usage was not dropped: ' . $failure['error']
                );
            }
        }
    }
}
```

`failedStatementSummary()` is the one-line form the runner puts in the ledger, and
is empty when nothing failed — so it works as the condition.

### `benign` is a label, not a decision

`benign` marks a failure that looks like work already applied — a duplicate
column, a table that exists. **Nothing is skipped or failed on the strength of
it.** It exists so a report can separate the eleven redundant `ADD COLUMN`s of a
re-run from the one statement naming a table nobody created.

It cannot be more than a label, because it cannot be trusted enough to be one.
Only MySQL supplies an error code: its `mysqli_sql_exception` propagates with the
real errno (`1050`, `1060`, `1061`, `1091`, …). PostgreSQL failures arrive as a
plain `Exception` whose code is `0` — `Database::setError()` is called with error
number `0` on that driver and no SQLSTATE is captured anywhere — so on that side
the only discriminator is the message text, and message text is localisable. Gate
on it and a database running a non-English `lc_messages` would start failing
migrations whose statements were merely redundant, which is the one outcome the
tolerance exists to prevent.

### Two ways a statement fails

`Database::query()` throws for an execution error, but it also has one path that
returns `false` without throwing — a statement that cannot be prepared.
`executeQueries()` counts both. A check that only caught exceptions kept missing
the quieter one.

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

### A migration is not the first thing to touch the schema

The framework's migrations run inside applications that have their own. Every object
a framework migration creates may already be there — built by an app migration, by a
predecessor of the migration system, or by an operator. Written on the assumption of
an empty schema, a migration fails **partway through**: the statements before the
failure are committed, the ones after it never run, the runner records `result = 0`,
and it retries and fails again on every deploy that changes the fingerprint.

The checks below cover almost all of it.

**1. Ask what kind of relation owns a name before you drop it.** `DROP VIEW IF EXISTS`
does not protect you from a materialized view under the same name — PostgreSQL raises
`"x" is not a view` and the `IF EXISTS` is no help, because the relation does exist.

```php
// Replaces the framework's own view; leaves a consumer's materialization alone.
if ($schema->getRelationKind('reports.usage') === 'v') {
    $this->DB()->query('DROP VIEW IF EXISTS reports.usage CASCADE');
    $this->DB()->query('CREATE VIEW reports.usage AS ...');
}
```

Note what this deliberately does *not* do: pick `DROP MATERIALIZED VIEW` when the kind
is `m`. Completing the migration is the easy half. An application that made the name a
materialized view chose a cached read over a live one and refreshes it on a schedule,
so replacing it swaps a cached read for a full aggregation under an installation
serving traffic, and leaves the refresh job pointed at a relation that no longer
exists — failing on its own schedule, far from the deploy that caused it. Skip, and say
so in the log. A plain view is the framework's own and is replaced as usual, so the
framework never loses the ability to update what it created.

**2. Cast, do not assume a column's type.** The same logical column can be `VARCHAR` on
one installation and something narrower on another, when the application created the
table first and picked a type that validates its contents. Pick an expression defined
for both:

```sql
-- SPLIT_PART has no INET overload; HOST() has no VARCHAR one; ::text is defined for both
SPLIT_PART(ip_address::text, '.', 1)
```

**3. Prefer the builder method that already guards.** `createContinuousAggregate()`
returns quietly when the aggregate exists, and `hasIndex()` / `hasTable()` /
`hasColumn()` / `hasView()` exist so a guard can be asked rather than inferred from a
caught driver error.

**Say what you skipped.** A migration that quietly does nothing is indistinguishable
from one that worked. Log to the `migrations` channel with enough detail for an
operator to act on:

```php
\Pramnos\Logs\Logger::log(
    'reports.usage already exists as a materialized view and was left as it is. '
    . "To adopt the framework's live view, drop the relation together with whatever "
    . 'refreshes it and re-run this migration.',
    'migrations'
);
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
