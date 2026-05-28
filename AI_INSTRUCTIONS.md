# AI Context & Instructions for Pramnos Framework

## Project Overview
Pramnos Framework is a modern, independent PHP framework targeting **PHP 8.4+**. It is
built natively without relying on external framework vendors (no Laravel, no Symfony
dependencies). The framework supports its own MVC architecture, custom HTTP
request/response routing, database abstractions (MySQL 8.0, PostgreSQL 14,
TimescaleDB), caching layers (Redis/Memcached/File), and a full migration system.

## Core Directives
1. **Never Assume Laravel/Symfony Paradigms.** Always inspect `src/Pramnos/*` logic
   before assuming DI rules, ORM behavior, or router conventions.
2. **PHP 8.4 minimum.** All production code in `src/Pramnos/*` must be PHP 8.4
   compatible. Use typed properties, match expressions, named arguments, and native
   attributes freely.
3. **Multi-Database Dialects.** The framework connects to MySQL and PostgreSQL/TimescaleDB
   simultaneously. Any changes to `src/Pramnos/Database/` must work across both dialects.
4. **Backward Compatibility is a hard constraint.** No existing public method signature
   may change. New capabilities are always additive.
5. **Always run tests via `./dockertest`**, never `vendor/bin/phpunit` directly. The
   Docker environment provides PHP 8.4, the correct extensions, and all three database
   backends.

## Source Layout
```
src/Pramnos/
  Application/   — Bootstrapping, ServiceProviders, FeatureRegistry, Settings
  Auth/          — OAuth2 server, JWT, 2FA, GDPR, RBAC, Permissions
  Database/      — QueryBuilder, SchemaBuilder, MigrationRunner, Model, DataTable
  Debug/         — DebugBar, collectors (Query, Memory, Timeline, Migrations, …)
  Http/          — Request, Response, Router, Middleware pipeline
  Logs/          — Logger, LogMigrator, PSR-3 adapter
  Notification/  — Notifier, Mail/DB/SMS channels
  Queue/         — QueueItem, AbstractTask, daemon orchestration
  User/          — User model, Token, session tracking
  General/       — Helpers, string utilities, Faker
database/
  migrations/framework/   — Framework-level auto-run migrations (scope=framework)
    core/         — sessions, settings, framework_policies, indexes
    auth/         — users, 2FA, GDPR, activity log
    authserver/   — OAuth2 server schema (PostgreSQL schemas, RBAC, device codes)
    applications/ — application_settings, application_stats, views
    messaging/    — mails, mailtemplates, messages, massmessages
    queue/        — queueitems
    notifications/— notifications
tests/
  Characterization/  — Freeze observable behavior before refactoring (× 3 databases)
  Integration/       — Live DB tests (MySQL + PostgreSQL + TimescaleDB via Docker)
  Unit/              — Isolated tests, no DB or filesystem I/O
```

## Migration System
- **Framework migrations** live in `database/migrations/framework/` and run
  automatically on every boot (via `Application::runAutoMigrations()`).
- All baseline migrations use the synthetic timestamp `2020_01_01_*`. Legacy
  installations that pre-date the migration system set `migration_cutoff =
  2020_01_02_000000` to skip them.
- **New framework migrations must use the current date as the prefix**
  (e.g. `2026_05_28_000001_add_something.php`). Never reuse `2020_01_01_*`.
- The history table is `schemaversion`. `ensureHistoryTable()` upgrades legacy
  3-column tables (when, key, extra) by adding the new columns transparently.

## Docker & Testing Infrastructure
The testing environment is fully containerised. Do NOT run PHP or PHPUnit natively on
the host.

**Docker Compose services:**
| Service | Image | Role |
|---|---|---|
| `db` | `mysql:8.0` | Primary MySQL datastore |
| `timescaledb` | `timescale/timescaledb:latest-pg14` | PostgreSQL + TimescaleDB |
| `redis` | `redis:alpine` | Cache layer |
| `app` | custom PHP 8.4 + Apache | Test runner / web server |

**Test commands:**
```bash
./dockertest                        # full suite
./dockertest --filter ClassName     # single class or method
./dockertest --coverage             # HTML coverage report
./dockertest --testdox              # human-readable output
```

**PHPUnit conventions:**
- PHPUnit 11 — use native PHP Attributes (`#[CoversClass(...)]`), not docblock annotations.
- Every test method has a doc-block explaining *what* and *why*, plus `// Arrange / Act / Assert` section comments.
- Integration tests must verify actual DB state, not just SQL string output.
