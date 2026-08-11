---
date: 2026-07-28
categories:
  - Changelog
  - Features
tags:
  - migrations
  - redis
  - cache
  - queue
  - broadcasting
  - database
  - testing
  - daemons
---

# Serving init-less apps: migrations, capability accessors, test DB, daemon status

A cluster of additive changes so that an application which deliberately does **not**
adopt the framework's MVC request lifecycle (attribute-routed front controllers,
"Services + API + SPA" apps, console bootstraps) still gets the framework's
guarantees — auto-migration, pre-wired Redis capabilities, a real test database, and
a queryable daemon supervisor — without re-implementing the wiring itself.

<!-- more -->

See the [Migration](../../Pramnos_Migration_Guide.md), [Cache](../../Pramnos_Cache_Guide.md),
[Redis](../../Pramnos_Redis_Guide.md), [Queue](../../Pramnos_Queue_Guide.md),
[Realtime](../../Pramnos_Realtime_Guide.md), [Database](../../Pramnos_Database_API_Guide.md),
[Testing](../../Pramnos_Testing_Guide.md) and [Console](../../Pramnos_Console_Guide.md)
guides for the full walkthroughs.

## Added

**Auto-migration for non-MVC apps.** Auto-run migrations are no longer limited to
the framework feature directories or the full `init()`/`exec()` request lifecycle:

- **App-declared directories** — `app.php` `'migrations' => ['paths' => [...]]` lists
  app-owned dirs that auto-run scans in addition to the framework dirs, on the same
  fingerprint fast-path.
- **Framework opt-out** — `'migrations' => ['framework' => false]` skips the framework
  feature directories, so an app whose schema collides with a framework table (e.g. its
  own `sessions` layout) runs only its own paths. Defaults to `true`.
- **`Application::migrate()`** — public standalone entry point: connects the DB if
  needed (no session start / addons / session tracking), honours the same
  `migrations`/`migration_cutoff`/`features` config, and never throws (failures logged).
- **On-demand cross dependencies** — `MigrationRunner::setDependencyPool()` lets a
  migration declare a `$dependencies` on one *not* in the batch; the runner pulls it
  (transitively) from `Application::frameworkMigrationPool()` and orders it first,
  instead of failing "unknown dependency".

**Capability accessors on the `ConnectionManager`.** The Redis-backed capabilities can
now be obtained pre-wired from the shared `Pramnos\Redis\ConnectionManager`, so an app
configures Redis once (`ConnectionManager::setInstance()` in bootstrap) and gets the
capabilities without re-building adapters/drivers:

- **`FlatCache::default()` / `setDefault(?FlatCache)`** — a lazy, process-default flat
  cache on a `RedisAdapter` bound to the manager's host/port/db/password + prefix.
- **`BroadcastingManager::instance()` / `setInstance(?self)`** — a lazy default manager
  pre-wired with a `RedisDriver` on the manager, `redis` active. Named `instance()` to
  avoid clashing with the existing `setDefault(string)` driver selector.
- **`DelayedQueue::redis(string $namespace)`** — a factory for a Redis-backed delayed
  queue bound to the manager (`<prefix><namespace>:delayed`/`:data`).
- **`Database` session timezone** — a new `database.timezone` setting (default unset).
  When set, the framework issues `SET TIME ZONE` (PostgreSQL) / `SET time_zone` (MySQL)
  per connection; unset ⇒ no SET, so the connect path is byte-identical for existing apps.

All accessors resolve `ConnectionManager::getInstance()` lazily inside the method body,
so an app that binds the manager during bootstrap is always in effect.

**Init-less test database.** `Pramnos\Framework\Testing\TestDatabase` — a standalone
helper providing a raw `\PDO` connection (from the `database` settings, honouring
`database.timezone`) plus `assertDatabaseHas()` / `assertDatabaseMissing()` and
`setConnection`/`reset` seams, **without** running the MVC request lifecycle. For apps
that never call `init()` (and whose own `sessions` schema collides with the framework's
session tracking) but still want to seed the real database with plain SQL.

**Daemon health snapshot.** `Pramnos\Console\DaemonOrchestrator::status()` — a public,
read-only health snapshot returning the orchestrator's own liveness (`running`/`pid`
from the singleton lock), `heartbeat_age_seconds` (per-cycle state file age), and its
managed `daemons` (last-persisted state, each enriched with live process status) —
**without** running a reconcile cycle. Lets a dashboard read the real orchestrator
(`(new MyOrchestrator())->status()`) instead of keeping a separate legacy supervisor
just to report "is the supervisor up?".

## Tests

`ApplicationAppMigrationsTest` + the app-declared/framework-off/public-`migrate()` cases
in the MySQL+PostgreSQL auto-migration suites; `FlatCacheTest`, `BroadcastingManagerTest`,
`DelayedQueueTest`, `DatabaseTimezonePostgreSQLTest`; `TestDatabaseHelperTest`;
`DaemonOrchestratorTest`.
