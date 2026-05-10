# Project Progress - Pramnos Framework v1.2

## 📅 Last Updated: 2026-05-10 (session 48)

## 🚀 Completed Milestones

### Admin user scaffolding false-success fix (2026-05-10, session 48)

- [x] **`Init.php` PHP snippet**: Fixed two-condition bug in `createAdminUser()`:
  - `userid > 0` success check → `empty($user->getErrors())`. The `$userid = 1` class default never changes on failed INSERT, making `userid > 0` always TRUE even when the user wasn't created. `getErrors()` is the reliable indicator (populated by `_save()` on any failure).
  - Added `empty($user->getErrors())` guard to the two-save condition (`userid > 1`) so a failed first save doesn't trigger a redundant re-save. The `> 1` threshold is preserved to avoid the double-INSERT bug in `_save()` for userid=1 (admin sentinel uses md5 — intentional per characterization tests).
- Commit: `68f954b`

### QueryBuilder schema resolution + Auth service refactor (2026-05-10, session 47)

- [x] **`QueryBuilder::from()` / `join()` / `crossJoin()`**: MySQL-only schema resolution — `authserver.foo` → `authserver_foo` via `SchemaBuilder::resolveTableName()`. Guards skip PostgreSQL (schema.table works natively) and aliased expressions (containing space).
- [x] **`TwoFactorAuthService`**: Refactored all 13 raw `prepareQuery()/query()` calls to fluent QueryBuilder chains (`->table('authserver.user_twofactor')->select/where/first/insert/update/delete`). Removed private table-name properties; schema resolution now handled by QueryBuilder.
- [x] **`Loginlockout`**: Refactored `recordFailedAttempt()`, `clearSuccessfulLoginState()`, and `loadRow()` to QueryBuilder; removed `tbl()` helper.
- 3 commits: `4e7e153`, `4778039`, `6b52d37`

### Auth Tables → authserver Schema + Service Fixes (2026-05-10, session 46)

- [x] **Migrations 000017–000026**: All 10 auth migrations now create tables in the `authserver` schema (PostgreSQL) / `authserver_` prefix (MySQL), matching urbanwater's layout exactly:
  - `authserver.loginlockouts` (was `loginlockout`)
  - `authserver.user_twofactor`, `authserver.twofactor_setup`, `authserver.twofactor_attempts`
  - `authserver.user_activity_log`, `authserver.user_privacy_settings`, `authserver.user_consents`
  - `authserver.data_processing_records`, `authserver.gdpr_requests`
  - `authserver.daily_activity_summary` (continuous aggregate / view)
- [x] **MigrationRunner Kahn sort bug fixed**: `array_splice($queue, 0, 0, $insertable)` caused high-priority-number siblings to jump ahead; replaced with full `topoQueue()` re-sort after each batch. Regression test added.
- [x] **`TwoFactorAuthService`**: Replaced all hardcoded bare table names with `$db->schema()->quoteTable('authserver.tablename')` resolved in constructor. Works correctly on both MySQL and PostgreSQL.
- [x] **`Loginlockout`**: Added `tbl(Database $db)` helper using `quoteTable('authserver.loginlockouts')`; replaced all hardcoded `loginlockout` references.
- [x] **`SchemaBuilder::enableCompression()`**: Fixed quoting bug — was emitting `ALTER TABLE "authserver.twofactor_attempts"` (dot inside quotes); now uses `$this->getGrammar()->quoteTable($resolved)`.
- [x] **`CreateDailyActivitySummaryView` migration**: MySQL fallback SQL body now uses `quoteTable()` for `authserver.user_activity_log` to avoid "Unknown database 'authserver'" error on MySQL.
- [x] **Admin creation fix**: `Init.php` two-save pattern ensures `setPassword()` applies correct salt for userid > 1; `2>/dev/null` changed to `2>&1`; diagnostic message added when migrations fail.
- [x] **Integration tests** — `FrameworkMigrationsMySQLTest`: 10 new tests (000017–000026), verifying `authserver_*` table presence on MySQL.
- [x] **Integration tests** — `FrameworkMigrationsPostgreSQLTest`: 10 new tests (000017–000026), verifying tables are in `authserver` schema (not `public`) on PostgreSQL.
- [x] **Integration tests** — `FrameworkMigrationsTimescaleDBTest`: updated setUp + all assertions to use `authserver` schema.
- [x] **Integration tests** — `TwoFactorAuthServiceMySQLTest` + `TwoFactorAuthServicePostgreSQLTest`: updated `dropTables()`/`createTables()` and all inline SQL to use `authserver_*` / `authserver.*` notation.
- [x] **Integration tests** — `LoginlockoutMySQLTest` + `LoginlockoutPostgreSQLTest`: updated `dropTable()`/`createTable()` and all inline SQL to use `authserver_loginlockouts` / `authserver.loginlockouts`.

### MySQL index atomicity + test isolation fixes (2026-05-09, session 45)

- [x] **Root cause**: `SchemaGrammar::compileCreate()` emitted N+1 separate SQL statements for MySQL (CREATE TABLE + N CREATE INDEX). Between statements, `Database::getConnection()` ran `SELECT 1` health-check; any reconnect between them left the table without indexes, causing "Table doesn't exist" on the CREATE INDEX.
- [x] **Fix**: Added `inlineIndexes()` template method (false in base, true in MySQL) and `compileInlineIndex()`. MySQL now embeds all non-unique indexes as `KEY name (cols)` clauses inside the single CREATE TABLE statement — making table creation fully atomic.
- [x] **Fix**: `Database::close()` was only resetting `_dbConnection` but leaving `_writeConnection`/`_readConnection` pointing to the closed mysqli object. Now resets all three.
- [x] **Fix**: `UserTokenManagementCharacterizationTest::setUp()` now explicitly DROPs all user tables before `User::setupDb()`, preventing stale-schema failures where `CREATE TABLE IF NOT EXISTS` silently skipped re-creation.
- [x] **Fix**: `QueryBuilderMySQLTest` `setUp`/`tearDown` wrapped DROP TABLE calls in `SET FOREIGN_KEY_CHECKS = 0/1` for defensive isolation.
- [x] **Regression tests** (unit): `testMySQLCreateTableEmbeddsNonUniqueIndexesInline` + `testPostgreSQLCreateTableStillEmitsPostCreateIndexStatements` in `SchemaBuilderUnitTest`.
- [x] **Regression tests** (integration): `testNonUniqueIndexesExistAfterCreateTable` + `testCreateTableWithIndexesIsFullyUsableAfterCreation` in `SchemaBuilderMySQLTest` — verify indexes physically exist in `information_schema.statistics` after `createTable()`.
- [x] **Stability confirmed**: 5× full coverage run: all 1747 tests pass consistently.

### Scaffold bug fix — create_authserver_rbac_functions ordering (2026-05-09, session 44)

- [x] **Root cause identified**: MigrationRunner Kahn sort splices newly-ready migrations at queue position 0 (`array_splice($q, 0, 0, $new)`). This displaced `create_authserver_user_roles_table` (priority 40) behind the audit_log→rbac_functions chain (50→75), causing `CREATE TRIGGER … ON authserver.user_roles` to fail because the table didn't exist yet.
- [x] **Fix**: added `create_authserver_user_roles_table` and `create_authserver_user_deyas_table` as explicit dependencies of migration 000036, guaranteeing correct ordering regardless of queue-insertion behaviour.
- [x] **Regression tests**: added 2 unit tests to `MigrationRunnerUnitTest` — one models the exact authserver scenario (11-migration graph), one documents the general sibling-displacement pattern.
- [x] commit: `18be917`

### AuthServer RBAC Schema Completion (2026-05-08, session 43)

- [x] **Migration 000031** — `authserver.user_deyas`: composite PK (userid, deyaid), org membership table; no FK to deya (application-level concern)
- [x] **Migration 000032** — `authserver.permission_templates`: reusable permission blueprints with `{deyaid}` placeholder support in object_id_pattern
- [x] **Migration 000033** — `authserver.role_templates`: role blueprints bundling permission_templateids (JSON array in TEXT, cross-DB compatible)
- [x] **Migration 000034** — `authserver.permission_inheritance`: parent→child object hierarchy with full/read_only/custom inheritance modes
- [x] **Migration 000035** — `authserver.effective_permissions` VIEW: deny-takes-priority aggregation on both PostgreSQL and MySQL
- [x] **Migration 000036** — 7 PL/pgSQL functions + 2 triggers (PostgreSQL only; no-op on MySQL): set_permission_priority, check_user_deya_membership, apply_permission_template, apply_role_template, log_audit_event, check_permission_with_inheritance, get_user_effective_permissions
- [x] **Schema fix** — `authserver.permissions.object_id`: BIGINT → VARCHAR(100) to support wildcards and template placeholders
- [x] **Schema fix** — `authserver.permissions`: added unique constraint `uq_authserver_perms_grant` on (subject_type, subject_id, object_type, object_id, action, grant_type) for ON CONFLICT support
- [x] **Integration tests** — `FrameworkMigrationsMySQLTest`: 5 new tests (user_deyas, permission_templates, role_templates, permission_inheritance, effective_permissions view + deny-takes-priority assertion)
- [x] **Integration tests** — `FrameworkMigrationsPostgreSQLTest`: 6 new tests (all 5 tables/view + PL/pgSQL functions with trigger validation + apply_permission_template execution test)

### Auth Migrations — TimescaleDB Hypertable Tests (2026-05-08, session 43)

- [x] **`FrameworkMigrationsTimescaleDBTest`** (6 tests) — verifies auth migrations create real TimescaleDB hypertables (not plain table fallback):
  - `twofactor_attempts` — hypertable in timescaledb_information.hypertables + INSERT/SELECT test
  - `user_activity_log` — hypertable + INSERT/SELECT test
  - `user_consents` — hypertable + INSERT/SELECT test
  - `data_processing_records` — hypertable + INSERT/SELECT test
  - `gdpr_requests` — hypertable + INSERT/SELECT test
  - `daily_activity_summary` — continuous aggregate verified in timescaledb_information.continuous_aggregates; CALL refresh_continuous_aggregate() + row count assertion

## 🚀 Completed Milestones

### GDPR Migrations (2026-05-08, session 42 continued)

- [x] **Migration 000021** — `user_activity_log` hypertable: 1-day chunks, compress after 30 days, retain 24 months; ifCapable(TIMESCALEDB)
- [x] **Migration 000022** — `user_privacy_settings`: plain table, PK=userid; share_usage_analytics, marketing_emails, data_processing flags
- [x] **Migration 000023** — `user_consents` hypertable: 1-month chunks, compress after 6 months, retain 7 years; ifCapable(TIMESCALEDB)
- [x] **Migration 000024** — `data_processing_records` hypertable: 1-week chunks, compress after 90 days, retain 36 months; ifCapable(TIMESCALEDB)
- [x] **Migration 000025** — `gdpr_requests` hypertable: 1-month chunks, compress after 1 year, retain 7 years; ifCapable(TIMESCALEDB)
- [x] **Migration 000026** — `daily_activity_summary`: TimescaleDB continuous aggregate; materialized view fallback on plain PG; plain view on MySQL
- [x] **Migration 000027** — GDPR columns on `users` table: gdpr_consent, gdpr_consent_date, gdpr_data_export_requested, gdpr_deletion_requested, gdpr_deletion_date (idempotent, uses hasColumn checks)
- All 7 migrations pass `FrameworkMigrationsMySQLTest` and `FrameworkMigrationsPostgreSQLTest`

### Pramnos\Auth\Scopes + OAuthPolicyHelper (2026-05-08, session 42 continued)

- [x] **`Pramnos\Auth\Scopes`** — `src/Pramnos/Auth/Scopes.php`; static OAuth2 scope registry: `getScopes()` (grouped), `getScopeDescriptions()` (flat map), `getDefaultScopes()`, `hasInvalidScopes()`, `resolveInheritedScopes()` (transitive, dedup, sorted), `areApplicationScopesGranted()` (requires applications table); unit tests: `ScopesTest` (12 tests)
- [x] **`Pramnos\Auth\OAuthPolicyHelper`** — `src/Pramnos/Auth/OAuthPolicyHelper.php`; default auth methods (client_secret_basic/post, private_key_jwt) + default grant types (authorization_code, client_credentials, device_code, refresh_token, exchange_token); unit tests: `OAuthPolicyHelperTest` (6 tests)

### Pramnos\Auth\TwoFactorAuthService + TOTPHelper (2026-05-08, session 42 continued)

- [x] **`Pramnos\Auth\TOTPHelper`** — `src/Pramnos/Auth/TOTPHelper.php`; static RFC 6238 TOTP utility: `generateSecret()`, `generateCode()`, `verifyCode()` with drift tolerance, `getQRCodeUrl()`, `generateBackupCodes()`, `hashBackupCode()`, `verifyBackupCode()`, `isValidSecret()`, `getRemainingTime()`
- [x] **`Pramnos\Auth\TwoFactorAuthService`** — `src/Pramnos/Auth/TwoFactorAuthService.php`; full 2FA lifecycle: `startSetup()`, `completeSetup()`, `verifyCode()` (TOTP + backup code), `disable()`, `regenerateBackupCodes()`, `cleanupExpiredSessions()`; replay protection; attempt logging
- [x] **Migrations 000018–000020** — `user_twofactor` (PK=userid, unix timestamps), `twofactor_setup` (15-min TTL), `twofactor_attempts` (TimescaleDB hypertable via `ifCapable(TIMESCALEDB)`: 7-day chunks, compress after 7 days, retain 2 years; plain table on MySQL/plain PG)
- [x] **Tests** — `TOTPHelperTest` (15 unit), `TwoFactorAuthServiceMySQLTest` (17 integration), `TwoFactorAuthServicePostgreSQLTest` (17 integration, `#[RunTestsInSeparateProcesses]`)

### Pramnos\Auth\Loginlockout (2026-05-08, session 42)

- [x] **Migration `000017`** — `database/migrations/framework/auth/2020_01_01_000017_create_loginlockout_table.php`; `loginlockout` table with (locktype, lookupvalue) unique index; unix integer timestamps for cross-DB compatibility; priority 70
- [x] **`Pramnos\Auth\Loginlockout`** — `src/Pramnos/Auth/Loginlockout.php`; progressive lockout (3→60s, 5→300s, 7→900s, 10+→3600s); sliding window 900 s; API: `recordFailedAttempt($scope, $identifier)`, `getLockoutStatus($scope, $identifier)`, `clearSuccessfulLoginState($scope, $identifier)`
- [x] **Integration tests × 2 databases** — `LoginlockoutMySQLTest` (11) + `LoginlockoutPostgreSQLTest` (11, `#[RunTestsInSeparateProcesses]`); covers row creation, counter increment, all 4 lockout thresholds, sliding window reset, scope isolation, clear+restart

### SchemaBuilderTimescaleDBTest (2026-05-08, session 41 continued)

- [x] **`SchemaBuilderTimescaleDBTest`** — extends `SchemaBuilderPostgreSQLTest`; all 24 PG tests inherited + 4 TimescaleDB-specific: `createHypertable()` (verified via `timescaledb_information.hypertables`), `addRetentionPolicy()` (verified via scheduler jobs), `addCompressionPolicy()` (verified via scheduler jobs), `createContinuousAggregate()` (verified via `continuous_aggregates` catalog), `ifCapable(TIMESCALEDB)` callback/fallback routing
- [x] **ROADMAP Phase 5 audit** — verified that Middleware Pipeline, Response, ExceptionHandler, Event System, and Service Provider tests already exist from prior sessions; ROADMAP updated to reflect reality
- **Tests:** 1638 passing (full suite), commit `3aefada`

### Messaging models + MessagingServiceProvider (2026-05-08, session 41)

- [x] **`Pramnos\Messaging\Mail`** — ORM model for `mails` table; STATUS_FAILED/SENT/QUEUED constants; load/save/delete/getList
- [x] **`Pramnos\Messaging\MailTemplate`** — ORM model for `mailtemplates` table; TYPE_EMAIL/SMS/PUSH + SENDMETHOD_* constants; `findByKey(category, language, type)` helper
- [x] **`Pramnos\Messaging\Message`** — ORM model for `messages` table; 10 TYPE_* state-machine constants; `countUnread(userId)` and `countUnreadNotifications(userId)` helpers
- [x] **`Pramnos\Messaging\MassMessage`** — ORM model for `massmessages` table; TYPE_*/STATUS_* constants
- [x] **`Pramnos\Messaging\MassMessageRecipient`** — ORM model for `massmessagerecipients` table; STATUS_PENDING/DELIVERED/FAILED constants
- [x] **`Pramnos\Messaging\MessagingServiceProvider`** — service provider for `messaging` feature key; register()/boot() hooks for applications
- [x] **Integration tests × 2 databases** — `MessagingModelsMySQLTest` (11) + `MessagingModelsPostgreSQLTest` (11, separate processes for PG singleton); cover save/load/update/delete/findByKey/countUnread
- [x] **Bug fix: `MailTemplate::findByKey()`** — used `reset()` instead of `[0]` since `_getList()` keyes by PK value, not sequential integer
- **Tests:** 1610 passing (full suite), commit `4dcd17d`

### SchemaBuilder centralized schema→prefix translation (2026-05-08, session 39)

- [x] **`SchemaBuilder::resolveTable()` centralised** — handles both `#PREFIX#` token and `schema.table`→`prefix_schema_table` for MySQL; PostgreSQL dot notation is preserved and handled by the grammar
- [x] **New public methods** — `resolveTableName(string $table): string` and `quoteTable(string $table): string` expose the resolved physical name and a fully-quoted form for embedding in raw SQL
- [x] **All framework migrations simplified** — use uniform `schema.table` notation (`pramnos.framework_policies`, `authserver.roles`, etc.); translation is automatic per-backend
- [x] **`PolicyEngine::policyTable()`** — delegates entirely to `$this->db->schema()->quoteTable('pramnos.framework_policies')`
- [x] **Authserver migrations (021–029) fixed** — removed manual `$caps->isPostgreSQL()` branching and `#PREFIX#` placeholders; all use `$schema->createTable('authserver.xxx', ...)` / `$schema->quoteTable('authserver.xxx')`
- [x] **`users.locationid` removed** — UrbanWater-specific FK to locations table stripped from framework users migration
- [x] **`massmessages` UW-specific fields removed** — `locationid`, `deyaid`, `zoneid`, `filters` stripped (geographic targeting belongs in UW, not the framework)
- [x] **Framework migration DDL tests updated** — MySQL and PostgreSQL integration test suites pass (50 tests); authserver table references updated to `authserver_` prefix for MySQL assertions
- [x] **SchemaBuilder integration tests for new methods** — `quoteTable()` and `resolveTableName()` covered in both `SchemaBuilderMySQLTest` and `SchemaBuilderPostgreSQLTest` (4 new tests per backend; 8 total)
- [x] **`timeBucket()` integration tests × 3 databases** — new `QueryBuilderTimescaleDBTest` extends PostgreSQL suite; MySQL/PG tests extended with `testTimeBucketGroupByHour*`; 373 QB tests total
- [x] **`authserver.slow_api_calls` view migration** — `database/migrations/framework/authserver/2020_01_01_000030_create_slow_api_calls_view.php`; joins tokenactions + usertokens + applications; MySQL: `authserver_slow_api_calls`, PG: `authserver.slow_api_calls`; 2 integration tests
- [x] **ROADMAP updated** — Token Action Tracking, Queue, QueryBuilder tests marked as complete
- **Tests:** 1510+ passing (full suite)

### `pramnos init` scaffolding improvements (2026-05-07, session 38)

- [x] **DB readiness polling** — `waitForDatabase()` polls `pg_isready` / `mysqladmin ping` before running migrations (max 30 attempts × 2s)
- [x] **Admin user creation** — after successful migrations, creates admin user via temp PHP file copied into container (avoids shell quoting issues)
- [x] **Default email domain** — changed from `pramnos.com` → `pramnos.net`
- [x] **Default values shown in ALL prompts** — ChoiceQuestion prompts now display selected default (e.g. `[plain-css]`, `[none]`, `[timescaledb]`)
- [x] **Extra libraries default** — changed to `[Y/n]` (yes by default)
- [x] **App CLI scaffold (urbanwater pattern)** — generates `{cliName}.php` (PHP entry point), `{cliName}` (bash wrapper: `docker-compose exec app php {cliName}.php "$@"`), and `src/Console.php` extending `\Pramnos\Console\Application`
- [x] **Library catalog cleanup** — removed `alpinejs`, `htmx`, `sweetalert2`; added `ckeditor 4.22.1`; default selection matches used stack (jquery, datatables, select2, leaflet, chartjs, ckeditor)
- [x] **Local-only asset delivery** — all selected libraries downloaded to `assets/vendor/{name}/{version}/` during init; no CDN references in generated theme files
- [x] **Per-page library loading** — `Application::registerVendorLibraries()` calls `$doc->registerScript()`/`registerStyle()` (register without include); controllers enqueue per-page via `addScript()`/`addStyle()`; theme renders only what's enqueued
- [x] **Correct migrate command** — uses `migrate --scope=framework` (not the non-existent `migrate:framework`)
- [x] **PHP signature compatibility** — generated `Application::init($settingsFile = '')` matches parent; no fatal error on instantiation
- [x] **stdout capture on failure** — `runProcessWithSpinner()` buffers both stdout and stderr; combines on failure so Symfony Console errors (written to stdout) are always surfaced
- [x] **5 regression tests** — cover CLI files scaffold, init() signature, local-path registration, migrate command name, no-CDN theme files
- **Tests:** 1489/1489 passing (all 15 InitCommandTest tests pass)

### Phase 3: Migration Wizard & Seeder Generator (2026-05-06, session 35)

- [x] **Interactive migration wizard** — `create migration` (no name) launches terminal wizard: description → table → PK → columns loop (type/length/nullable/default/comment/unique) → timestamps → soft-deletes → foreign keys loop → writes migration with full `up()`/`down()` bodies
- [x] **Post-wizard scaffold** — after migration creation, wizard asks: Model, Web Controller, API Controller, Seeder — all created without DB connection
- [x] **`Pramnos\Database\Seeder`** — new abstract base class with `insert(table, data)` helper using `Database::insertDataToTable()`
- [x] **`seeder.stub`** — new template: extends Seeder, loops `{{ count }}` times, injects `{{ fields }}` block
- [x] **`create seeder <Name>`** — standalone seeder creation (bare skeleton when no columns provided)
- [x] **Public helper methods on `Create`**: `buildMigrationUpBody()`, `buildMigrationDownBody()`, `blueprintCall()`, `generateFakeValue()`, `buildSeederFields()`
- [x] **`generateFakeValue()`** — name heuristics (email, status, phone, city, password, token, uuid, lat/lon, price …) with type fallbacks
- [x] **`migration.stub`** updated — added `Blueprint` import, `{{ up_body }}`/`{{ down_body }}` tokens
- [x] **`name` argument** changed REQUIRED → OPTIONAL (wizard needs it; other entities validate internally)
- [x] **18 unit tests** in `MigrationWizardHelpersTest.php`
- [x] **Characterization test** updated to assert `name` is optional
- [x] **`docs/1.2-new-features.md`** — Section 28 added
- **Tests:** 1456/1456 passing (1438 + 18 new)

### Phase 3: Scaffolding modernisation (2026-05-06, session 34)

- [x] **`create:migration`** — timestamp filename (`YYYY_MM_DD_HHmmss_slug.php`), PascalCase class name, uses `migration.stub` via `renderStub()`, drops legacy `migrations.php` list update
- [x] **`create:controller`** (simple path) — replaced broken inline heredoc (used undefined `$viewName`, `$modelNameSpace` etc.) with `renderStub('controller')` + auto-generates test stub
- [x] **`create:model`** — stub skeleton fallback when DB table absent (schema-first workflow); auto-generates test stub on fresh create
- [x] **Stubs updated** — `controller.stub` full CRUD skeleton; `migration.stub` / `model.stub` use `namespace {{ namespace }};` (full namespace from caller); fallbacks added to `getFallbackStub()`
- [x] **3 new unit tests** — `testRenderStubMigrationProducesCorrectClass`, `testRenderStubControllerProducesFullSkeleton`, `testRenderStubModelProducesActiveRecordSkeleton`

### Phase 2: Queue System backport (2026-05-05, session 33)

- [x] **`Pramnos\Queue\TaskInterface`** — `execute()`, `validate()`, `handleFailure()`, `getDescription()` contract
- [x] **`Pramnos\Queue\AbstractTask`** — default `validate()`, `handleFailure()`, `log()` helpers; `$name`, `$lastMessage` properties
- [x] **`Pramnos\Queue\QueueItem`** — ORM model for `queueitems` table; configurable `getItemShowUrl/EditUrl/DeleteUrl()` hooks replace hardcoded Urbanwater URLs
- [x] **`Pramnos\Queue\QueueManager`** — full queue lifecycle: `addTask()`, `getNextTask()` (split pending/stalled queries), all `markTask*` transitions, `getStats()`, `purgeOldTasks()`; `getTasksDirectory()` / `getTasksNamespace()` hooks replace hardcoded Urbanwater namespace scan; `getQueueTableName()` hook; `createQueueItemModel()` factory
- [x] **`Pramnos\Queue\Worker`** — dispatches to registered handlers; empty `$taskHandlers` by default; `createQueueManager()` factory hook; `processNextTask()` accepts `$startFromTimestamp` + `$reverseOrder` params
- [x] **`Pramnos\Console\Commands\ProcessQueue`** — full daemon command with live dashboard, DB reconnect loop, heartbeat, stop-file detection; `getDashboardTitle()` / `getControllerName()` / `createWorker()` / `createQueueManager()` hooks
- [x] **`Pramnos\Console\Commands\CleanupQueue`** — `queue:cleanup` command; `getControllerName()` / `createQueueManager()` hooks
- [x] **Tests** (`tests/Unit/Queue/QueueManagerTest.php` — 16 tests; `tests/Unit/Queue/WorkerTest.php` — 9 tests)
- [x] **Integration tests** (`tests/Integration/Queue/QueueManagerMySQLTest.php` — 8 tests; `tests/Integration/Queue/QueueManagerPostgreSQLTest.php` — 8 tests) — full lifecycle against real MySQL 8.0 + TimescaleDB
- [x] **Bug fix**: `queueitems` migration changed status column from `TINYINT` to `VARCHAR(20)` so string-based status comparisons work on both MySQL and PostgreSQL
- [x] **`Pramnos\Console\Commands\DbSeed`** — `db:seed` CLI command: scans `database/seeds/`, loads Seeder subclasses, runs all or a named seeder; `--path` option for custom directory
- **Tests:** 1479/1479 passing

### Phase 2: OAuth Server — league/oauth2-server integration (2026-05-07, session 37)

- [x] **`composer require league/oauth2-server:^8.5`** — 8 packages installed (lcobucci/jwt, defuse/php-encryption, etc.)
- [x] **`docker-compose.yml`**: pinned MySQL to `8.4` (tag `8.0` now resolves to 9.7.0 which was incompatible)
- [x] **Migrations** (authserver feature, framework scope):
    - `000025_create_applications_table` — OAuth2 client registry (apikey unique, callback, scope, owner, public_key, jwks_uri)
    - `000026_create_device_authorizations_table` — RFC 8628 Device Grant (ENUM/VARCHAR+CHECK status, unique device_code + user_code)
    - `000027_create_jwt_replay_prevention_table` — jti lookup table with expires_at index for cleanup
    - `000028_create_oauth2_client_auth_methods_table` — per-client auth method registry (ENUM/VARCHAR+CHECK)
    - `000029_create_oauth2_webhooks_tables` — endpoints + events tables (JSON/JSONB, FK cascade, delivery tracking)
- [x] **`Pramnos\Auth\Application`** — ORM model for applications table; `loadByApiKey()`, `validateCredentials()`, OAuth2 interface helpers
- [x] **OAuth2 Entities** (6): ClientEntity, UserEntity, ScopeEntity, AccessTokenEntity, AuthCodeEntity, RefreshTokenEntity
- [x] **OAuth2 Repositories** (6): ClientRepository, ScopeRepository (extensible), AccessTokenRepository, AuthCodeRepository, RefreshTokenRepository, UserRepository (delegates to User::validateUserCredentials)
- [x] **`OAuth2ServerFactory`** — wires 4 grant types; `generateKeyPair()` for RSA 2048-bit keys; persistent encryption key
- [x] **`OAuth2Middleware`** — Bearer token validation, scope checking, `getCurrentUserId()`, `revokeToken()`
- [x] **`AuthServerServiceProvider`** — registered in FeatureRegistry
- [x] **Integration tests**: 5 MySQL + 5 PostgreSQL migration tests (column types, schema placement, rollback, JSONB vs JSON)
- **Tests:** 1489/1489 passing (1479 + 10 new)

### Phase 2: Token Action Tracking — partial (2026-05-06, session 36)

- [x] **Migrations** (`urls` + `tokenactions`) — already existed; verified schema matches spec
- [x] **Sync trigger** — `sync_tokenactions_time` PL/pgSQL function + trigger added to `CreateTokenactionsTable.up()` for PostgreSQL; drops on `down()`
- [x] **`Token::updateAction()` for MySQL** — removed early MySQL `return`; method now records response metrics on all backends
- [x] **Integration tests** (`tests/Integration/User/TokenActionMySQLTest.php` — 3 tests; `tests/Integration/User/TokenActionPostgreSQLTest.php` — 4 tests incl. sync trigger verification)
- [x] **`FrameworkMigrationsPostgreSQLTest`** — added trigger existence check after `tokenactions` migration
- [x] **Bug fix**: `QueueManagerPostgreSQLTest` + `TokenActionPostgreSQLTest` now restore the MySQL singleton in `tearDown()`, preventing PostgreSQL state contamination of subsequent test classes
- **Pending**: `applications.slow_api_calls` VIEW migration (depends on `applications` table — part of OAuth Server)
- **Tests:** 1479/1479 passing

### Phase 2: DaemonOrchestrator backport (2026-05-05, session 33)

- [x] **`Pramnos\Console\DaemonOrchestrator`** (`src/Pramnos/Console/DaemonOrchestrator.php`) — abstract process supervisor backported from Urbanwater:
  - Abstract contract: `buildDesiredProcesses()`, `getDashboardTitle()`, `getEntryPoint()`, `getJobName()`
  - Overrideable hooks: `isOrchestratorEnabled()`, `getOrchestratorLockFile()`, `getStateFile()`, `getManagedLockFileGlobPattern()`
  - Reconcile engine: desired-vs-actual diff, stale heartbeat detection (300s), crash detection, pre-spawn dedup guard (`/proc` + `ps`), graceful stop, SIGTERM after grace period (30s)
  - Stop-file mechanism: `requestStop()`, `clearStopFile()`, `requestStopAll()`
  - State persistence: `loadState()` / `saveState()` — JSON to `getStateFile()`
  - Singleton flock guard: `tryAcquireOrchestratorLock()`, `releaseOrchestratorLock()`
  - Git-hash restart: `getCurrentGitHash()` — parses `.git/HEAD` without spawning a process; restarts all daemons on new deployment
  - Interactive dashboard: `renderInteractiveDashboard()` — calls `getDashboardTitle()` + `buildDesiredProcesses()`; all CommandBase dashboard primitives reused
  - Announcement dedup: `shouldAnnounceHealthyProcess()` — suppresses repeated [ok] log noise
  - Standard options: `--once`, `--interval`, `--php-binary`, `--dry-run`, `--interactive`, `--verbose-health`
- [x] **Tests** (`tests/Unit/Console/DaemonOrchestratorTest.php` — 26 tests): buildShellTokens, requestStop/clearStopFile, loadState/saveState round-trip, readWorkerPidFromLockFile, readOrchestratorPidFromLock, getCurrentGitHash, shouldAnnounceHealthyProcess (dedup, pid-change, verbose mode), readLastLogLine, getProcessLogFile
- [x] **`docs/1.2-new-features.md`** — Section 27 added (process definition keys, reconcile behaviour, stop-file mechanism, state file, overrideable hooks, migration guide, BC notes, test summary)
- [x] **`ROADMAP_1.2.md`** — Daemon Orchestrator marked `[x]`
- **Tests:** 1410/1410 passing (1384 + 26 new)

### Phase 2: CLI UX — CommandBase backport (2026-05-05, session 32)

- [x] **`Pramnos\Console\CommandBase`** (`src/Pramnos/Console/CommandBase.php`) — backport of `Urbanwater\ConsoleCommands\CommandBase`:
  - Lock-file job guards: `beginJob()`, `endJob()`, `heartbeat()`, `checkIfRunning()`, stale-lock detection, PID liveness check
  - Terminal control: `clearScreen()`, `hideCursor()`, `showCursor()`, `detectTerminalSize()`, `initializeInteractiveTerminal()`
  - Signal/shutdown: `configureInterruptHandling()`, `handleInterruptSignal()`, `handleShutdown()`
  - `getOrchestratorCommandName(): string` hook (default `'daemons:start'`) — overrideable without changing detection logic
  - Progress bar: `buildProgressBar(current, total, width=50)` — block-char `█` / `.` style extracted from Urbanwater commands
  - Text utilities: `formatBytes()`, `formatTime()`, `visibleLength()` (ANSI-aware), `truncateText()`, `wrapDashboardText()`
  - Dashboard: `buildDashboardHeader/Separator/Footer`, `padDashboardLine/Row`, `buildDashboardRows`, `buildSystemStatusSegments`, `buildCommandStateSection`, `buildDashboardHelpSection`, `buildDashboardAdventureSection`, `renderDashboardFrame`, `renderDashboardFrameAutoSystem`, `renderDashboardGameMode`
- [x] **Tests** (`tests/Unit/Console/CommandBaseTest.php` — 29 tests): all pure-computation methods + lock lifecycle
- [x] **`docs/1.2-new-features.md`** — Section 26 added (full API table, migration guide, BC notes)
- **Note:** `PramnosStyle` commit (`bcbf4e9`) was reverted — wrong approach (invention vs backport)
- **Tests:** 1384/1384 passing (1355 + 29 new)

### Phase 2: Event / Hook System (2026-05-05, session 32)

- [x] **`Pramnos\Event\Event`** (`src/Pramnos/Event/Event.php`) — static priority-ordered event bus:
  - `listen(event, listener, priority=10)` — accepts Closure, class-name string, or `ListenerInterface` instance
  - `fire(event, ...$args): array` — executes listeners in priority order; returns all return values; stops chain on `false`
  - `forget(event='')` — clear one event or all events
  - `hasListeners(event): bool`, `getListeners(event): array`
- [x] **`Pramnos\Event\ListenerInterface`** (`src/Pramnos/Event/ListenerInterface.php`) — `handle(mixed ...$args): mixed` contract
- [x] **Event/Listener Scaffolding** (`src/Pramnos/Console/Commands/Create.php`):
  - `create:event <Name>` — writes `src/Events/<Name>.php` (plain value-object class) + test stub
  - `create:listener <Name>` — writes `src/Listeners/<Name>.php` implementing `ListenerInterface` + test stub
  - `scaffolding/templates/event.stub` and `listener.stub` updated to use `declare(strict_types=1)` and `ListenerInterface`
  - Fallback skeletons added to `getFallbackStub()` for both types
- [x] **Tests** (`tests/Unit/Event/EventTest.php` — 17 tests; `tests/Unit/Console/CreateCommandUnitTest.php` — 2 new tests): basic fire/listen, argument forwarding, multiple args, zero-listener contract, return values, priority ordering, FIFO same-priority, propagation stopping, null-no-stop, class-based listener, hasListeners, forget(event), forget() all, getListeners order, cross-event isolation; event/listener stub content assertions
- [x] **`docs/1.2-new-features.md`** — Section 25 added (Event system API, listener types, priority, propagation, BC notes, test summary)
- [x] **`ROADMAP_1.2.md`** — Event/Hook System and Event/Listener Scaffolding marked `[x]`
- **Tests:** 1355/1355 passing (1338 + 17 new)

### Phase 3: Scaffolding System (2026-05-05, session 31)

- [x] **`scaffolding/` directory** — created with all template stubs and theme files:
  - `templates/`: `controller.stub`, `model.stub`, `migration.stub` (with `transactional=false`), `middleware.stub`, `event.stub`, `listener.stub`, `test.stub`
  - `themes/plain-css/`, `themes/bootstrap/`, `themes/tailwind/` — each with `header.php`, `footer.php`, `theme.html.php`, `style.css`
  - `assets.json` — pinned versions for 21 libraries (jQuery, Alpine.js, htmx, DataTables, Select2, Tom Select, Flatpickr, Chart.js, ApexCharts, Dropzone.js, FilePond, SweetAlert2, Toastify, Sortable.js, Cropper.js, Leaflet.js, TinyMCE, Quill, Font Awesome, Bootstrap Icons, Flowbite)
- [x] **`Init.php` — full wizard** (`src/Pramnos/Console/Commands/Init.php`):
  - Step 2: Feature selection (auth, authserver, queue, messaging) with gate — writes `features` array to `app.php`
  - Step 3: UI system selection (plain-css, bootstrap, tailwind) — loads theme from `scaffolding/themes/<ui>/`
  - Step 4: Library selection with gate ("Configure extra libraries? [y/N]") — downloads to `www/assets/vendor/`, writes manifest, `--no-download` flag for CI
  - Step 6: `docker-compose exec app php bin/pramnos migrate:framework` called after Docker startup and composer install; `--no-migrations` flag to skip
  - All steps driveable via CLI options (`--features`, `--ui-system`, `--libraries`, `--no-download`, `--no-migrations`)
  - BC: existing `setInputs` tests updated to provide 6 new inputs (4 feature + 1 UI + 1 library gate); options-driven tests unchanged
  - `renderStub(string $name, array $tokens): string` — loads stub from `scaffolding/templates/`, falls back to embedded skeleton if absent
- [x] **`Create.php` — middleware generator** (`src/Pramnos/Console/Commands/Create.php`):
  - `create:middleware <Name>` — writes `src/Middleware/<Name>.php` implementing `MiddlewareInterface`
  - Auto-generates `tests/Unit/<Name>MiddlewareTest.php` (never overwrites existing)
  - `renderStub()` + `generateTestStub(string, string, string $baseDir = '')` helpers (stub-based, fallback-safe)
  - `resolveScaffoldingDir()` walks up 6 directory levels to find `scaffolding/templates/`
- [x] **Unit tests** (`tests/Unit/Console/InitCommandUnitTest.php` — 9 tests; `tests/Unit/Console/CreateCommandUnitTest.php` — 5 tests): stub rendering, token substitution, fallback, scaffolded files, feature array, timescaledb→postgresql mapping, Docker files, library manifest with `--no-download`
- [x] **`docs/1.2-new-features.md`** — Section 24 added: wizard steps, CLI options table, Step 6 migration note, generated project structure, local asset download, `create:middleware`, stub system, BC notes

### Phase 5: Characterization Coverage — PostgreSQL mirrors (2026-05-05, session 30)

- [x] **`tests/Characterization/Application/ModelListApiPostgreSQLCharacterizationTest.php`** (7 tests, `#[RunTestsInSeparateProcesses]`) — mirrors `ModelListApiCharacterizationTest` against PostgreSQL (timescaledb:5432): `getCount()` all rows, `getCount()` with WHERE prefix, `_getList()` plain arrays + ordering, `_getList()` useGetData bug (characterization of known limitation), `_getApiList()` global search + JSON field decode, `_getApiList()` paginated error envelope (known limitation), `_getApiList()` structured filter arrays with OR groups.
- [x] **`tests/Characterization/Html/Datatable/DatasourcePostgreSQLCharacterizationTest.php`** (6 tests, `#[RunTestsInSeparateProcesses]`) — mirrors `DatasourceCharacterizationTest` against PostgreSQL (timescaledb:5432): paged rows + metadata, global search with JOIN (double-quote identifier quoting), per-column wildcard config, multi-column ordering (amount DESC → Gamma first), distinctField unique rows, date field formatting from Unix timestamp.
- [x] **`fix(user): add usertokens to setupDb()`** — `User::setupDb()` now creates `usertokens` table for both MySQL (backtick quoting, AUTO_INCREMENT, ENGINE=InnoDB) and PostgreSQL (double-quote quoting, SERIAL, separate `CREATE INDEX IF NOT EXISTS` statements). Fixes 9 pre-existing `UserTokenManagementCharacterizationTest` failures caused by `FrameworkMigrationsMySQLTest::down()` dropping the table before those tests run.
- [x] **`ROADMAP_1.2.md`** — all 5 `[~]` characterization test items changed to `[x]` with accurate notes on Auth/Logs being complete without DB-specific tests (no direct DB queries / file-based).
- [x] Full suite verified: **1320/1320 tests, 0 failures**.
- [x] commit: `74d4ec6` (usertokens setupDb fix); PG characterization tests in this commit.

### Migration System — Safety Improvements (2026-05-05, session 29)

- [x] **`Migration::$transactional = false`** — opt-in transaction flag. Set to `true` to wrap `up()` in `BEGIN`/`COMMIT`/`ROLLBACK` on PostgreSQL. TimescaleDB-native operations (e.g. `createHypertable()`) must leave this `false`.
- [x] **`MigrationRunner` — maintenance mode integration** — accepts `?Application $app` as 3rd constructor param. When provided, `run()` activates maintenance mode before the batch and deactivates it in `finally`. Skips deactivation if maintenance was already active.
- [x] **`MigrationRunner` — transaction wrapping** — if `$migration->transactional && $db->type === 'postgresql'`, `run()` wraps each migration in `BEGIN`/`COMMIT`/`ROLLBACK`. Silently ignored on MySQL (DDL = implicit COMMIT).
- [x] **Bug fix: `rollback()` no longer deletes history on failed `down()`** — prevents a migration appearing as "never ran" after a half-reverted schema.
- [x] **Bug fix: `executeQueries()` clears queue after execution** — prevents double-run if called more than once on the same instance.
- [x] Characterization tests updated to assert `transactional = false` in metadata defaults.
- [x] Verified: 1307 tests, all Migration/MigrationRunner tests pass. (9 pre-existing errors in UserTokenManagementCharacterizationTest — missing usertokens table, unrelated.)
- [x] commit: `e899ec5`

### Phase 5: Migration Characterization × 3 Databases (2026-05-05, session 28)

- [x] **`tests/Characterization/Database/MigrationMySQLCharacterizationTest.php`** (8 tests) — locks Migration base class behavior against MySQL 8.0: legacy `addQuery()`/`executeQueries()` creates real table; idempotent `down()` drops it; SchemaBuilder `up()`/`down()` lifecycle; `hasTable()` guard for idempotent `up()`; Phase 4 metadata defaults (scope/feature/priority/dependencies/autoExecute); `getSlug()` CamelCase → snake_case; `getDescription()` property delegation.
- [x] **`tests/Characterization/Database/MigrationPostgreSQLCharacterizationTest.php`** (7 tests) — mirrors MySQL test against PostgreSQL 14 (timescaledb host): double-quote quoting, `SERIAL`/`INTEGER` type mapping, idempotency on PG.
- [x] **`tests/Characterization/Database/MigrationTimescaleDBCharacterizationTest.php`** (5 tests) — TimescaleDB-specific path: hypertable creation via `createHypertable()`, `timescaledb_information.hypertables` registration, time-dimension column assertion, `down()` deregistration, `ifCapable(TIMESCALEDB, ...)` branching verified to take hypertable path on TimescaleDB backend.
- [x] Verified with `./dockertest --filter 'MigrationMySQLCharacterizationTest|MigrationPostgreSQLCharacterizationTest|MigrationTimescaleDBCharacterizationTest'` → **20 tests, 35 assertions, 0 failures**.
- [x] Updated `ROADMAP_1.2.md` — marked "Characterization Tests — Migration" as `[x]`.

### Phase 5: Characterization Coverage Wave 1 (2026-05-03, session 7)

- [x] Added `tests/Characterization` suite to `phpunit.xml` so characterization tests run through `./dockertest`.
- [x] Added `tests/Characterization/Auth/AuthCharacterizationTest.php` with coverage of singleton behavior, addon-driven auth success/failure flows, `authCheck()` trigger behavior, and `logout()` session/hook side effects.
- [x] Added `tests/Characterization/Auth/JWTCharacterizationTest.php` with coverage of malformed token parsing, HS256 round-trip encode/decode, allowed/unsupported algorithm guards, expiration/leeway behavior, and `sign()` contract checks.
- [x] Verified with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest'` (14 tests, 28 assertions, all passing).

### Phase 5: Characterization Coverage Wave 2 (2026-05-03, session 8)

- [x] Added `tests/Characterization/Database/AdjacencylistCharacterizationTest.php` to lock current behavior of path generation and SQL assembly for `getArray()` and `getPath()`.
- [x] Captured current `getPathAsArray()` runtime behavior as characterization: method currently throws due `Pramnos\\Database\\stdClass` resolution.
- [x] Verified with `./dockertest --filter AdjacencylistCharacterizationTest` (5 tests, 10 assertions, all passing).
- [x] Re-verified combined characterization suite with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest'` (19 tests, 38 assertions, all passing).

### Phase 5: Characterization Coverage Wave 3 (2026-05-03, session 9)

- [x] Added `tests/Characterization/Database/MigrationCharacterizationTest.php` to lock base `Migration` behavior: description getter, queued query execution order, exception-swallowing/continue semantics, and default no-op `up()`/`down()`.
- [x] Added test harness setup for migration logging side effects (`LOG_PATH`) to keep legacy `Logger::log()` path executable during tests.
- [x] Verified with `./dockertest --filter MigrationCharacterizationTest` (4 tests, 7 assertions, all passing).
- [x] Re-verified combined characterization suite with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest|MigrationCharacterizationTest'` (23 tests, 45 assertions, all passing).

### Phase 5: Characterization Coverage Wave 4 (2026-05-03, session 10)

- [x] Added `tests/Characterization/Html/Datatable/DatasourceCharacterizationTest.php` with integration-style characterization coverage for Datasource `render()` behavior: paging output shape, global search + join flow, and per-column wildcard configuration.
- [x] Captured current fallback behavior for totals: `iTotalRecords` / `iTotalDisplayRecords` currently return `0` when count subqueries fail and control flows through catch/log paths.
- [x] Added test harness setup for logger side effects (`LOG_PATH`) required by Datasource error/logging paths.
- [x] Verified with `./dockertest --filter DatasourceCharacterizationTest` (3 tests, 14 assertions, all passing).
- [x] Re-verified combined characterization suite with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest|MigrationCharacterizationTest|DatasourceCharacterizationTest'` (26 tests, 59 assertions, all passing).

### Phase 5: Characterization Coverage Wave 5 (2026-05-03, session 11)

- [x] Added `tests/Characterization/User/UserCharacterizationTest.php` to lock `User` contracts for lifecycle (create/load/update/activate/deactivate/delete), password branch behavior (`userid <= 1` vs `userid > 1`), and `User::getUser()` cache identity semantics.
- [x] Verified with `./dockertest --filter UserCharacterizationTest` (3 tests, 12 assertions, all passing).
- [x] Re-verified combined characterization suite with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest|MigrationCharacterizationTest|DatasourceCharacterizationTest|UserCharacterizationTest'` (29 tests, 71 assertions, all passing).

### Phase 5: Characterization Coverage Wave 6 (2026-05-03, session 12)

- [x] Added `tests/Characterization/Logs/LoggerAndMigratorCharacterizationTest.php` covering file-log write format contracts (`Logger::log`, `Logger::error`, `Logger::logPrepend`) and `LogMigrator::migrateFile()` conversion/backup behavior.
- [x] Verified with `./dockertest --filter LoggerAndMigratorCharacterizationTest` (4 tests, 21 assertions, all passing).
- [x] Re-verified combined characterization suite with `./dockertest --filter 'AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest|MigrationCharacterizationTest|DatasourceCharacterizationTest|UserCharacterizationTest|LoggerAndMigratorCharacterizationTest'` (33 tests, 92 assertions, all passing).

### Phase 5: Characterization Coverage Wave 7 (2026-05-03, session 13)

- [x] Added `tests/Characterization/Application/ModelCharacterizationTest.php` to lock Model contracts for `__init()` prefix substitution, cache-key generation format, `getChanges()` numeric-vs-string comparison semantics, and `getData()` metadata filtering.
- [x] Verified with `./dockertest --filter ModelCharacterizationTest` (4 tests, 12 assertions, all passing).

### Phase 5: Characterization Coverage Wave 8 (2026-05-03, session 13)

- [x] Extended `tests/Characterization/Html/Datatable/DatasourceCharacterizationTest.php` with deep DataTable scenarios: request-based ordering, `distinctField` unique-row behavior, and date formatting through field metadata.
- [x] Verified with `./dockertest --filter DatasourceCharacterizationTest` (6 tests, 20 assertions, all passing).

### Phase 5: Characterization Coverage Wave 9 (2026-05-03, session 13)

- [x] Added `tests/Characterization/User/TokenCharacterizationTest.php` to lock Token contracts for save/load (id + token string), `getData()` status/date mapping, and MySQL `updateAction()` no-op path behavior.
- [x] Added test-side table bootstrap for `#PREFIX#usertokens` to keep token persistence characterization deterministic.
- [x] Verified with `./dockertest --filter TokenCharacterizationTest` (3 tests, 11 assertions, all passing).
- [x] Re-verified aggregate characterization baseline with `./dockertest --filter 'ModelCharacterizationTest|DatasourceCharacterizationTest|TokenCharacterizationTest|AuthCharacterizationTest|JWTCharacterizationTest|AdjacencylistCharacterizationTest|MigrationCharacterizationTest|UserCharacterizationTest|LoggerAndMigratorCharacterizationTest'` (43 tests, 121 assertions, all passing).

### Roadmap Alignment Update (2026-05-03, session 13)

- [x] Added explicit "Backlog Διορθώσεων από Characterization Findings" section to `ROADMAP_1.2.md` with tracked follow-up fixes:
	- `Adjacencylist::getPathAsArray()` `stdClass` namespace bug
	- `Datasource::render()` count-subquery fallback returning `0`
	- `Logger` hard dependency on `LOG_PATH`
	- `Model::_generateSpecificCacheKey()` unresolved `#PREFIX#` placeholders

### Coverage Artifact Validation (2026-05-03, session 14)

- [x] Ran full suite with coverage via `./dockertest --coverage` (524 tests, 997 assertions, green).
- [x] Confirmed coverage HTML artifacts refresh correctly (`coverage/index.html`, `coverage/dashboard.html` timestamps updated).
- [x] Confirmed `coverage/clover.xml` remains stale (old mtime), causing XML-based coverage analysis to report outdated numbers.
- [x] Added this as an explicit backlog fix in `ROADMAP_1.2.md` under characterization findings.

### Phase 5: Characterization Coverage Wave 10 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/SettingsCharacterizationTest.php`, `tests/Characterization/Application/ControllerCharacterizationTest.php`, and `tests/Characterization/Application/ViewCharacterizationTest.php` (42 tests total) to lock Application contracts.
- [x] Captured legacy behaviors for settings loading/defaults, controller action/auth scopes, and view-model binding/default model selection.
- [x] Verified with `./dockertest --filter 'SettingsCharacterizationTest|ControllerCharacterizationTest|ViewCharacterizationTest'` (all passing).

### Phase 5: Characterization Coverage Wave 11 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Console/CommandsCharacterizationTest.php` (19 tests) to lock command naming, options/arguments, and guard clauses for Console commands.
- [x] Verified with `./dockertest --filter CommandsCharacterizationTest` (all passing).

### Phase 5: Characterization Coverage Wave 12 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Logs/LogManagerViewerCharacterizationTest.php` (21 tests) for Logger PSR-3 adapters, LogManager file inventory/stats/cleanup, and LogViewer filtering paths.
- [x] Captured current logger behavior that JSON level output requires non-empty extra context.
- [x] Verified with `./dockertest --filter LogManagerViewerCharacterizationTest` (all passing).

### Phase 5: Characterization Coverage Wave 13 (2026-05-03, session 15)

- [x] Added `tests/Characterization/User/UserTokenManagementCharacterizationTest.php` (10 tests) for token lifecycle (add/get/list/deactivate/expire/clear/cleanup) and `User::setPassword()` hashing branches.
- [x] Verified with `./dockertest --filter UserTokenManagementCharacterizationTest` (all passing).

### Phase 5: Characterization Coverage Wave 14 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Cache/FileAdapterCharacterizationTest.php` (19 tests) for FileAdapter/AbstractAdapter contracts: connect/save/load/delete/expiry/prefix/key generation/caching flags.
- [x] Added `tests/Characterization/Html/HtmlCharacterizationTest.php` (16 tests) for `Breadcrumb`, base `Html`, and `Date` utility contracts.
- [x] Added `tests/Characterization/General/GeneralCharacterizationTest.php` (53 tests) for `StringHelper` and `Helpers` behavior.
- [x] Added `tests/Characterization/Geolocation/GeolocationCharacterizationTest.php` and `tests/Characterization/Email/EmailCharacterizationTest.php` (24 tests combined) for math/validation contracts and Email fluent/error-state API.
- [x] Added `tests/Characterization/Theme/ThemeCharacterizationTest.php` and `tests/Characterization/Media/ThumbnailCharacterizationTest.php` (8 tests combined) for lightweight Theme/Media contracts.
- [x] Hardened cache test isolation (`FileAdapterCharacterizationTest`) to avoid suite-order side effects from `CACHE_PATH` and removed warning-producing path assumptions.
- [x] Re-verified full suite with `./dockertest` → 736 tests, 1353 assertions, green (PHPUnit deprecations only).

### Phase 5: Characterization Coverage Wave 15 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/ModelListApiCharacterizationTest.php` (7 tests) to lock list/count/API contracts for `Model`: `getCount()`, `_getList()`, `_getApiList()`, structured filter arrays, legacy `WHERE`-prefixed filter compatibility, and JSON field decoding path.
- [x] Captured two current implementation limitations as executable contracts:
	- `_getList()` with `useGetData=true` + `queryFields` can collapse payloads to empty arrays.
	- `_getApiList()` paginated mode can return an error envelope for specific field-selection inputs.
- [x] Verified with `./dockertest --filter ModelListApiCharacterizationTest` (7 tests, 25 assertions, all passing).
- [x] Re-verified full suite with `./dockertest` → 743 tests, 1378 assertions, green (PHPUnit deprecations only).

### Phase 5: Characterization Coverage Wave 16 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/ApiCharacterizationTest.php` (28 tests) to lock `Api` helper contracts for HTTP status text translation and response-envelope translation (`_httpStatusToText()`, `_translateStatus()`).
- [x] Verified known status-code mapping matrix and default-fallback behavior (`unknown => OK`).
- [x] Verified `_translateStatus()` contracts for string/array/non-array inputs, non-200 auto statusmessage injection, custom statusmessage preservation, and JSON output stability.
- [x] Verified with `./dockertest --filter ApiCharacterizationTest` (28 tests, 62 assertions, all passing).
- [x] Re-verified full suite with `./dockertest` → 764 tests, 1415 assertions, green (PHPUnit deprecations only).

### Phase 5: Characterization Coverage Wave 17 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/ApikeyCharacterizationTest.php` (6 tests) covering `Apikey` constructor-fill, insert/save, update/save, load-by-id, load-by-apikey, and `getData()` status/timestamp formatting.
- [x] Added deterministic setup for `applications` table (create-if-missing) and isolated cleanup by test prefix.
- [x] Verified with `./dockertest --filter ApikeyCharacterizationTest` (6 tests, 17 assertions, all passing).
- [x] Re-verified full suite with `./dockertest` → 770 tests, 1432 assertions, green (PHPUnit deprecations only).

### Phase 5: Characterization Coverage Wave 18 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/ApplicationRuntimeCharacterizationTest.php` (7 tests) for deterministic `Application` helper contracts: redirect flow with `_redirect`, breadcrumb rendering, controller-info storage, start-page flag toggle, extra-path map behavior, and maintenance mode file lifecycle.
- [x] Verified with `./dockertest --filter ApplicationRuntimeCharacterizationTest` (7 tests, 19 assertions, all passing).
- [x] Re-verified full suite with `./dockertest` → 777 tests, 1451 assertions, green (PHPUnit deprecations only).

### Phase 5: Characterization Coverage Wave 19 (2026-05-03, session 15)

- [x] Added `tests/Characterization/Application/LogControllerCharacterizationTest.php` (5 tests) to lock deterministic `LogController` helper behavior: whitelist auto-population/blacklist filtering/sorting, action-button rendering contract, and date-aware line processing callbacks.
- [x] Verified with `./dockertest --filter LogControllerCharacterizationTest` (5 tests, 26 assertions, all passing).
- [x] Re-verified full suite with `./dockertest` → 782 tests, 1477 assertions, green (PHPUnit deprecations only).

### Backlog Bug Fixes (2026-05-03, session 16)

- [x] **`Adjacencylist::getPathAsArray()` — `stdClass` namespace bug** (`src/Pramnos/Database/Adjacencylist.php`): `new stdClass()` resolved to `Pramnos\Database\stdClass`. Fixed to `new \stdClass()`.
- [x] **`Logger` PSR-3 level lost with empty context** (`src/Pramnos/Logs/Logger.php`): `formatLogEntry()` only used JSON format when `!empty($context)`, silently dropping `level` for calls like `Logger::info('msg', [])`. Fixed by adding `isset($entry['level'])` as an OR condition — JSON format is now always used when a PSR-3 level is set.
- [x] Updated `AdjacencylistCharacterizationTest` to replace the expected-Error test with a correct-behavior assertion after the namespace fix.
- [x] Added `testLoggerLevelIsPreservedWithoutExtraContext()` to `LogManagerViewerCharacterizationTest` to lock the corrected behavior.

### Phase 4: Migration System Overhaul (2026-05-03, session 16)

- [x] **Enhanced `Migration` base class** (`src/Pramnos/Database/Migration.php`):
  - New metadata fields: `$feature`, `$scope` (default `'app'`), `$priority` (default `50`), `$dependencies`, `$autorun` (default `true`), `$description`.
  - PHP 8.4 hooked property `$autoExecute` (BC alias) — delegates get/set to `$autorun` with no backing storage.
  - `getSlug()` — derives migration identifier from class name: strips `YYYY_MM_DD_HHmmss_` timestamp prefix or converts CamelCase to snake_case.
  - `getTimestamp()` — extracts `YYYY_MM_DD_HHmmss` prefix for cutoff filtering and tie-breaking sort.
  - `extractSlugFromName()` / `extractTimestampFromName()` — protected static helpers exposed for testing.

- [x] **New `MigrationRunner` class** (`src/Pramnos/Database/MigrationRunner.php`):
  - `__construct(?Database $db = null, string $historyTable = 'framework_migrations')`.
  - `ensureHistoryTable()` — `CREATE TABLE IF NOT EXISTS` with full Phase 4 columns (`migration`, `scope`, `feature`, `batch`, `execution_time`, `result`, `error_message`, `description`, `ran_at`). MySQL and PostgreSQL variants (SERIAL/TIMESTAMPTZ/INT/DATETIME).
  - `run(array $migrations, array $options = [])` — full pipeline: sort → filterAutorun → filterCutoff → getPending → nextBatch → execute `up()` per migration → recordHistory. Catches `\Throwable`; failed migrations are recorded with `result=0` and `error_message`; batch continues.
  - `rollback(array $migrations, array $options = [])` — last-batch detection, reverse-order `down()` calls, `deleteHistoryRow()` per rolled-back migration.
  - `getPending(array $migrations)` — queries `getRanSlugs()` (result=1 only) then calls `filterAlreadyRan()`.
  - `sort(array $migrations, array $alreadyRan = [])` — Kahn's topological sort; deps in `$alreadyRan` treated as already satisfied (enables incremental run() calls across batches); throws `RuntimeException` on cyclic dependency or unresolvable dep.
  - `filterAutorun(array, bool $force)`, `filterCutoff(array, string $cutoff)`, `filterAlreadyRan(array, array $ranSlugs)` — all public for composable use.

- [x] **Unit tests** (`tests/Unit/Database/MigrationRunnerUnitTest.php`) — 18 tests, all green:
  - Slug extraction from timestamped / non-timestamped / CamelCase class names.
  - Timestamp extraction and null return for legacy names.
  - BC defaults (feature/scope/priority/dependencies/autorun/autoExecute alias).
  - `autorun=false` reflects via `autoExecute` property hook.
  - Sort: priority ascending, datetime tie-break, dependency ordering, transitive chains, cycle detection, unresolvable dep exception.
  - `filterAutorun` (with and without force), `filterCutoff` (older, exact match, untimestamped), `filterAlreadyRan`.

- [x] **MySQL integration tests** (`tests/Integration/Database/MigrationRunnerMySQLTest.php`) — 10 tests, all green:
  - `ensureHistoryTable()` column presence + idempotency.
  - `run()` creates tables, records history with correct metadata (scope, feature, result, ran_at).
  - Batch number increments across separate `run()` calls.
  - Failed migration records result=0 + error_message; subsequent migration in same batch still runs.
  - `getPending()` excludes successful migrations, includes failed (retryable), includes new ones.
  - `rollback()` calls `down()`, drops tables, removes history rows.

- [x] **PostgreSQL integration tests** (`tests/Integration/Database/MigrationRunnerPostgreSQLTest.php`) — 7 tests, all green: same coverage as MySQL against the TimescaleDB/PostgreSQL Docker container.

- [x] Re-verified full suite with `./dockertest` → **818 tests, 1598 assertions, 0 failures**.

### Phase 4: MigrationLoader and CLI Commands (2026-05-03, session 17)

- [x] **`Migration::getSlug()` / `getTimestamp()` filename-first** (`src/Pramnos/Database/Migration.php`): Both methods now check the migration file's basename first (e.g. `2024_03_15_143022_create_users.php`) before falling back to the class short name. PHP class names cannot start with digits, so the file is the authoritative source for timestamp-based ordering.
- [x] **`MigrationRunner` additions** (`src/Pramnos/Database/MigrationRunner.php`):
  - `rollback()` gains a `batch` option (`['batch' => N]`) to target a specific batch.
  - `rollbackAll(array $migrations)` — rolls back all batches in reverse order.
  - `getHistory(): array` — returns all history rows for `migrate:status`.
  - Fixed latent `fetchNext()` double-read bug in `getRanSlugs()`, `fetchBatchRows()`, and `getHistory()` (pre-read before while loop caused first row to be counted twice).
- [x] **`MigrationLoader`** (new `src/Pramnos/Database/MigrationLoader.php`): Discovers Migration subclasses from a directory by including each `*.php` file and matching classes by their defining file path (safe with `include_once` deduplication). Methods: `loadFromDirectory()`, `loadFromDirectories()`.
- [x] **5 CLI Commands** (all new in `src/Pramnos/Console/Commands/`):
  - `Migrate` — runs pending migrations with `--scope`, `--feature`, `--force`, `--cutoff` filters.
  - `MigrateRollback` — rolls back last batch (or `--batch=N`).
  - `MigrateReset` — rolls back all batches with confirmation prompt.
  - `MigrateRefresh` — reset + re-run all migrations.
  - `MigrateStatus` — formatted Table showing Ran / Failed / Pending per migration.
- [x] All 5 commands registered in `Console\Application::registerCommands()`.
- [x] **Unit tests** (`tests/Unit/Database/MigrationLoaderUnitTest.php`) — 8 tests: loads only Migration subclasses, ignores plain PHP, slug from timestamped filename, CamelCase slug fallback, metadata accessible, empty/nonexistent dir, `loadFromDirectories()`.
- [x] **Integration tests** added to MySQL and PostgreSQL runner test files: `testRollbackWithBatchOptionRollsBackSpecificBatch`, `testRollbackAllRemovesAllBatches`, `testGetHistoryReturnsAllRows`, `testGetHistoryReturnsEmptyArrayWhenNoMigrationsRan`.
- [x] Re-verified full suite with `./dockertest` → **833 tests, 1651 assertions, 0 failures**.

### Phase 4: Feature Registry (2026-05-03, session 18)

- [x] **`UnknownFeatureException`** (new `src/Pramnos/Application/UnknownFeatureException.php`): Thrown when `loadFromConfig()` is called with an unregistered key. Message includes the unknown key and the full list of known keys. `getFeatureKey()` provides programmatic access.
- [x] **`FeatureRegistry`** (new `src/Pramnos/Application/FeatureRegistry.php`): Static registry separating *known* (registered) features from *enabled* (app-configured) ones. API: `register()`, `loadFromConfig()`, `isEnabled()`, `getEnabled()`, `getKnown()`, `getProvider()`, `getMigrationPaths()`, `getDefinition()`, `initDefaults()`, `reset()`. Built-ins: `core`, `auth`, `authserver`, `messaging`, `queue`. `core` is always enabled. Defaults load lazily on first call.
- [x] **`Application::init()` integration** (`src/Pramnos/Application/Application.php`): Calls `FeatureRegistry::loadFromConfig($this->applicationInfo['features'] ?? [])` immediately after establishing the DB connection, so all subsequent code can rely on `isEnabled()`.
- [x] **Unit tests** (`tests/Unit/Application/FeatureRegistryUnitTest.php`) — 20 tests covering: core always enabled, enabled/disabled state, unknown key exception (message + `getFeatureKey()`), accumulation across multiple `loadFromConfig()` calls, empty array no-op, `getEnabled()` always includes core, `getKnown()` lists all built-ins, custom feature registration, overwrite semantics, `getProvider()` null and FQCN, `getMigrationPaths()` empty and set, `getDefinition()` null for unknown, `initDefaults()` after reset, lazy default loading, `reset()` clears state, `UnknownFeatureException` with/without known keys, extends RuntimeException.
- [x] **`docs/1.2-new-features.md`** — Section 11 added (Feature Registry, UnknownFeatureException, Application integration, usage patterns, BC notes).

### Phase 4: Service Providers (2026-05-03, session 18)

- [x] **`ServiceProvider`** (new `src/Pramnos/Application/ServiceProvider.php`): Abstract base class with `register()` and `boot()` lifecycle hooks (both no-ops by default). Constructor injects `Application $app` stored as `protected $app`.
- [x] **`Application` additions** (`src/Pramnos/Application/Application.php`):
  - `$serviceProviders` property — holds queued providers.
  - `addProvider(ServiceProvider $provider): void` — queues a provider before `init()`.
  - `bootServiceProviders()` — called by `init()` after `FeatureRegistry::loadFromConfig()`. Instantiates providers from enabled features (skips null/nonexistent FQCNs), merges with manually-added providers, runs `register()` on all, then `boot()` on all.
- [x] **Unit tests** (`tests/Unit/Application/ServiceProviderUnitTest.php`) — 9 tests: abstract class, no-op defaults, $app accessible in register/boot, two-phase order invariant (all register before any boot), null provider skipped silently, FQCN provider instantiable and booted, manual addProvider, multiple providers phase order, $app property is protected Application type.
- [x] **`docs/1.2-new-features.md`** — Section 12 added (ServiceProvider, Application changes, bootstrap lifecycle, usage pattern, BC notes).
- [x] Re-verified full suite with `./dockertest` → **867 tests, 1714 assertions, 0 failures**.

### Phase 4: Health Check & Observability (2026-05-03, session 18)

- [x] **`HealthStatus`** (enum, new `src/Pramnos/Health/HealthStatus.php`): `Ok / Degraded / Down` backed by strings. `worst()` returns the more severe of two statuses (Ok < Degraded < Down).
- [x] **`HealthCheckResult`** (new `src/Pramnos/Health/HealthCheckResult.php`): Immutable value object with `status`, `name`, `message`, `details`. Named constructors `ok()`, `degraded()`, `down()`. `toArray()` for JSON output.
- [x] **`HealthCheck`** (interface, new `src/Pramnos/Health/HealthCheck.php`): `getName(): string` + `run(): HealthCheckResult`. Implementations must not throw.
- [x] **`HealthRegistry`** (new `src/Pramnos/Health/HealthRegistry.php`): Static registry. `register()`, `get()`, `getNames()`, `run()`, `runAll()`, `reset()`. `runAll()` returns `{status, checks}` aggregate.
- [x] **Built-in checks** (all new in `src/Pramnos/Health/Checks/`):
  - `DatabaseConnectivityCheck` — `SELECT 1` probe with latency measurement.
  - `DiskSpaceCheck` — free MB vs degraded/down thresholds.
  - `MemoryLimitCheck` — PHP memory usage % vs degraded/down thresholds.
- [x] **`health:check` CLI command** (new `src/Pramnos/Console/Commands/HealthCheck.php`): Table or `--json` output. `--only=name1,name2` filter. Exit codes: 0=ok, 1=degraded, 2=down. Registered in `Console\Application`.
- [x] **Unit tests** (`tests/Unit/Health/HealthCheckUnitTest.php`) — 25 tests covering: HealthStatus worst(), named constructors, toArray(), readonly properties, HealthRegistry CRUD + runAll() + reset(), DiskSpaceCheck all three statuses, MemoryLimitCheck, custom check interface.
- [x] **`docs/1.2-new-features.md`** — Section 13 added.
- [x] Re-verified full suite with `./dockertest` → **892 tests, 1765 assertions, 0 failures**.

### Phase 4: Scheduled Tasks System (2026-05-03, session 18)

- [x] **`CronExpression`** (new `src/Pramnos/Scheduling/CronExpression.php`): 5-field cron parser. Supports wildcards, ranges (`N-M`), steps (`*/N`, `N-M/N`), comma lists, combinations. `isDue(\DateTimeInterface)` evaluates against a given moment. `withTime('HH:MM')` clones with updated hour/minute fields.
- [x] **`ScheduledTask`** (new `src/Pramnos/Scheduling/ScheduledTask.php`): Wraps callable/command/job with timing. Fluent API: `everyMinute()`, `everyNMinutes()`, `everyFiveMinutes()`, `hourly()`, `daily()`, `weekly()`, `monthly()`, `yearly()`, `cron()`, `at()`, `withoutOverlapping()`, `description()`. `run()` dispatches to the right execution path. `getSummary()` for CLI display.
- [x] **`Scheduler`** (new `src/Pramnos/Scheduling/Scheduler.php`): Static factory + registry. `command()`, `call()`, `job()`, `all()`, `getDue()`, `reset()`. Designed for registration in `ServiceProvider::boot()`.
- [x] **`schedule:run` CLI command** (new): Runs due tasks. `--pretend` for dry-run. Exit 0/1 for success/failure. Registered in Console Application.
- [x] **`schedule:list` CLI command** (new): Table of all registered tasks. Registered in Console Application.
- [x] **Unit tests** (`tests/Unit/Scheduling/SchedulingUnitTest.php`) — 29 tests: CronExpression parsing (wildcard, exact, range, step, list, day-of-week, monthly), isDue() correct/incorrect, withTime(), ScheduledTask fluent methods (daily, hourly, everyFiveMinutes, at, weekly, monthly), callable run, job handle(), Scheduler factory methods, all(), getDue() filtering, reset().
- [x] **`docs/1.2-new-features.md`** — Section 14 added.
- [x] Re-verified full suite with `./dockertest` → **921 tests, 1837 assertions, 0 failures**.

### Phase 4: Policy Engine (2026-05-03, session 18)

- [x] **`framework_policies` system migration** (new `src/Pramnos/Database/SystemMigrations/Core/2020_01_01_000002_create_framework_policies_table.php`): Creates `framework_policies` table for `core` feature. MySQL and PostgreSQL DDL variants. 2020 timestamp so installations with `migration_cutoff` skip it.
- [x] **`FeatureRegistry::initDefaults()`** updated: `core` feature now includes `migrations` path pointing to `src/Pramnos/Database/SystemMigrations/Core`.
- [x] **`PolicyRecord`** (new `src/Pramnos/Policy/PolicyRecord.php`): Immutable value object for `framework_policies` rows. `fromRow()` handles JSON config decoding, null fields, bool casting. All properties readonly.
- [x] **`PolicyEngine`** (new `src/Pramnos/Policy/PolicyEngine.php`): Reads and executes due policies. No-op on TimescaleDB. Policy types: `retention` (DELETE older than interval), `aggregate_refresh` (REFRESH MATERIALIZED VIEW / TRUNCATE+INSERT), `compression` (no-op), `cache_rebuild` (TRUNCATE+INSERT). MySQL `INTERVAL` conversion. `quoteIdentifier()` for SQL injection prevention. Methods: `run()`, `getAllEnabled()`, `register()`, `setEnabled()`, `remove()`.
- [x] **`service:policy-engine` CLI command** (new `src/Pramnos/Console/Commands/PolicyEngine.php`): `--list`, `--pretend`. Registered in Console Application. Exit 0/1 for success/failure.
- [x] **Unit tests** (`tests/Unit/Policy/PolicyRecordUnitTest.php`) — 6 tests: full row mapping, JSON config decoding, pre-decoded config array, missing optional fields null, disabled policy bool, all properties readonly.
- [x] **`docs/1.2-new-features.md`** — Section 15 added.
- [x] Re-verified full suite with `./dockertest` → **927 tests, 1866 assertions, 0 failures**.

### Backlog Bug Fixes — Part 2 (2026-05-04, session 23)

- [x] **`Logger::getDefaultLogPath()` — LOG_PATH fallback** (`src/Pramnos/Logs/Logger.php`): `LOG_PATH` constant may be undefined in separate-process tests and CLI contexts; now falls back to `sys_get_temp_dir()` via `defined('LOG_PATH')` guard. `ensureLogDirectories()` simplified to create only the final log directory.
- [x] **`LogManager` — class-constant crash fix** (`src/Pramnos/Logs/LogManager.php`): `private const DEFAULT_LOG_PATH = LOG_PATH . DS . 'logs'` evaluated at class-load time, crashing when `LOG_PATH` is undefined. Replaced with `private static function getDefaultLogPath()` using the same fallback guard; all `self::DEFAULT_LOG_PATH` references updated.
- [x] **`Model::_fixDb()` — #PREFIX# resolution** (`src/Pramnos/Application/Model.php`): when DB prefix is empty, `str_replace('', '', '#PREFIX#records')` left the token unresolved in the cache key. Fixed by resolving `#PREFIX#` → `$database->prefix` and `#THISPREFIX#` → `$this->prefix.'_'` first, then stripping the prefix.
- [x] **`Model::_resolveFieldResultName()` + filtering blocks** (`src/Pramnos/Application/Model.php`): added private helper that normalises field expressions (table.column prefix, AS aliases, identifier quotes) to a bare column name; used in `_getList()` and `_getPaginated()` to correctly identify fields in result rows regardless of how they were specified in the query.
- [x] **`Datasource` count queries** (`src/Pramnos/Html/Datatable/Datasource.php`): total/display counts used `COUNT(a.\`field\`)` (MySQL-only backtick, broke PG) and wrapped via raw `query()` which never bound `?` parameters from QB WHERE clauses. Fixed: count QBs now use `->select(['COUNT(*) as num'])->get()` so dialect quoting and parameter binding are handled correctly. Eliminates the catch-path 0 fallback for both count values.
- [x] **`Apikey::getList()` — iterator bug** (`src/Pramnos/Application/Api/Apikey.php`): `foreach ($result as $app)` does not iterate `Result` objects (they don't implement `Traversable`); silently returned an empty array. Changed to `while ($result->fetch()) { new Apikey($result->fields); }`.
- [x] Updated characterization tests to assert the corrected behavior: `ModelCharacterizationTest` (cache key `'15-records'` not `'15-#PREFIX#records'`), `DatasourceCharacterizationTest` (real count values instead of 0 fallback).
- [x] Re-verified full suite with `./dockertest` → **1027 tests, 2636 assertions, 0 failures, 0 skipped**.

### Phase 1: Adjacencylist QB Migration (2026-05-04, session 22)

- [x] **`Adjacencylist` — cross-dialect fix** (`src/Pramnos/Database/Adjacencylist.php`): replaced all hardcoded MySQL backtick queries with QueryBuilder calls. The QB emits dialect-correct quoting (backticks MySQL / double-quotes PG). `getArray()` uses a single QB chain instead of 3 separate SQL string branches; inner ancestor walk converted to QB; `getPathAsArray()` converted; `extraWhereRaw()` helper strips the leading WHERE keyword for `whereRaw()`.
- [x] **`AdjacencylistCharacterizationTest` updated** — mock now intercepts `execute()` (QB calls execute, not query); `queryBuilder()` passes through to real implementation with `type=mysql`/`prefix=''` set on the mock; routes result fixtures by binding value; extraWhere assertion checks condition presence + single WHERE occurrence.
- [x] **`AdjacencylistPostgreSQLCharacterizationTest` converted** from 7×markTestSkipped to 7 live integration tests against Docker TimescaleDB/PG 14. All contracts mirror the MySQL test.
- [x] Re-verified full suite with `./dockertest` → **1027 tests, 2636 assertions, 0 failures, 0 skipped**.

### Phase 5: Characterization Coverage Wave 21 — Adjacencylist + User PG (2026-05-04, session 22)

- [x] **`tests/Characterization/Database/AdjacencylistMySQLCharacterizationTest.php`** (7 tests) — live MySQL integration: `getArray()` all items with full ancestor paths, `getArray($parent)` subtree filter (paths still built from root), `getArray(null, $itemId)` single-item fetch, `getPath()` full chain, `getPath()` null for missing item, `getPathAsArray()` chain order + stdClass type, `getPathAsArray()` root single-element.
- [x] **`tests/Characterization/Database/AdjacencylistPostgreSQLCharacterizationTest.php`** (7 tests, all skipped) — formal record that Adjacencylist uses MySQL-only backtick quoting; all tests call `markTestSkipped()` with a pointer to ROADMAP_1.2.md Phase 1. Mirror structure is preserved so tests can be un-skipped after QB migration.
- [x] **`tests/Characterization/User/UserPostgreSQLCharacterizationTest.php`** (3 tests, `#[RunTestsInSeparateProcesses]`) — mirrors `UserCharacterizationTest` against PG/TimescaleDB: full lifecycle (create/load/otherinfo/activate/deactivate/delete), password-hash branching by userid, `getUser()` cache identity.
- [x] **`tests/fixtures/app/pg_settings.php`** — PG-only settings fixture; used in setUp() of separate-process tests to point `Factory::getDatabase()` at the `timescaledb` Docker container.
- [x] **`fix(user): advance PG bigserial sequence in setupDb()`** — `setupDb()` now runs `setval(pg_get_serial_sequence(...), MAX(userid))` after the explicit Guest insert; without this, the next auto-generated userid collided with Guest (id=1), silently failing the INSERT.
- [x] **`fix(user): activate()/deactivate() use integer literals`** — replaced `$database->convertBool()` (which returns `'t'`/`'f'`) with `1`/`0` and `%d` format; `active` is declared `smallint` on PG, and PG rejects `'t'` for a non-boolean column.
- [x] Re-verified full suite with `./dockertest` → **1027 tests, 2609 assertions, 0 failures, 7 skipped**.

### Phase 5: SchemaBuilder Integration Tests + fetchNext() Removal (2026-05-04, session 21)

- [x] **`fix(migration): $autorun → $autoExecute`** (commit `2f8448c`) — `MigrationRunner` and tests had stale `$autorun` references introduced in the previous session; reverted to the existing public property `$autoExecute`. BC rule violated and corrected.
- [x] **`refactor(result): remove fetchNext(); improve fetch() fast path`** (commits `2708c05`, `9342f1e`) — `fetchNext()` was added as an alias but never shipped to production. Removed entirely. `fetch()` fast-path added: when `cursor === -1 && !$skipDataFix`, returns pre-fetched `$fields` directly and seeks the DB cursor to row 1, eliminating the double-read of row 0 for single-row results. The `skipDataFix` exception is documented in the docblock (callers wanting raw string values must re-read from DB because `$fields` may already be type-converted). All `->fetchNext()` call sites updated: `MigrationRunner`, `Datasource`, `PolicyEngine`, `Model`, integration tests.
- [x] **`test(schema): SchemaBuilder integration tests MySQL + PG`** (commit `36fc9ec`) — 20 MySQL tests (`SchemaBuilderMySQLTest`) and 22 PG tests (`SchemaBuilderPostgreSQLTest`) against live Docker containers. Covers: `hasTable`/`hasColumn`, drop idempotency, all integer/string/boolean/json/enum/datetime types, nullable+default, `AUTO_INCREMENT`/`SERIAL`, `timestamps()`/`softDeletes()`, column comments (apostrophe edge case), `createIndex`/`dropIndex`/`createUniqueIndex`, FK via `KEY_COLUMN_USAGE`/`pg_indexes`, `alterTable` add/drop, `renameTable`, `truncate`, view lifecycle, PG materialized view lifecycle, PG BOOLEAN/TIMESTAMPTZ/UUID/JSONB/enum→CHECK, standalone `DROP INDEX`.

### Phase 4: Framework System Migrations — MySQL integration tests + PostgreSQL tests (2026-05-04, session 20)

- [x] **Rewrote all framework migrations** to match UW production schema (commit 5ebf589) — old placeholder schemas replaced with real column sets, proper types, indexes, FKs, and PKCE columns on `usertokens`.
- [x] **`FrameworkMigrationsMySQLTest`** (`tests/Integration/Database/`) — 17 tests covering all migrations against MySQL 8.0: table existence, column types, index presence, FK constraints, `TINYINT` status on `queueitems`, idempotency.
- [x] **`FrameworkMigrationsPostgreSQLTest`** (`tests/Integration/Database/`) — 22 tests against TimescaleDB/PG 14: same as MySQL + authserver schema placement, `queue_status` ENUM in `pg_type`, JSONB columns on `audit_log` and `tokenactions`, hypertable registration via `timescaledb_information.hypertables`.
- [x] **`SchemaBuilder::hasTable()` false-positive fix** — added `resolveSchema()` that uses `$this->db->database` on MySQL to scope `information_schema.tables` queries to the current database only (prevents `performance_schema.users` from being seen as `users`).
- [x] **`SchemaBuilder::createTable()` / `dropTableIfExists()`** — wrap with `SET FOREIGN_KEY_CHECKS = 0/1` on MySQL to allow creates/drops of tables involved in FK relationships without requiring dependency order.
- [x] **`SchemaBuilder::createHypertable()`** — interval options (`chunk_time_interval`, `compress_after`, `drop_after`) now emitted as `INTERVAL '...'` not bare string literals (PostgreSQL rejects `unknown`-typed args to polymorphic INTERVAL parameter).
- [x] **`PostgreSQLSchemaGrammar::compileCommentStatements()`** — replaced `addslashes()` with `str_replace("'", "''", ...)` for correct standard SQL apostrophe escaping (PostgreSQL does not support `\'`).
- [x] **Migration schema corrections**: signed `BIGINT` on `users.userid` (matches BIGSERIAL semantics and legacy `userstogroups` FK), `unsignedInteger` on `massmessagerecipients.messageid` (matches `massmessages.messageid INT UNSIGNED`), removed TEXT column defaults (MySQL forbids inline defaults on BLOB/TEXT).
- [x] **`TokenCharacterizationTest`** — fixed stale query-cache false positives: `cacheflush('usertokens')` added to `setUp()` and `tearDown()`; orphaned characterization rows cleaned up by `notes = 'characterization'` guard.
- [x] **`UserTest::setUp()`** — swapped order: `User::setupDb()` called before `DELETE WHERE userid=1` to prevent "table doesn't exist" errors when MigrationRunner tests drop `users` in tearDown.
- [x] **`SchemaBuilderUnitTest::testSchemaBuilderResolvesPrefix`** — updated mock to `willReturnCallback` to tolerate the `SET FK_CHECKS` calls that now precede `DROP TABLE`.
- [x] **`docs/1.2-new-features.md`** — Section 16 fully updated: correct file listing, real UW schemas, integration test table.
- [x] Re-verified full suite with `./dockertest` → **966 tests, 2450 assertions, 0 failures**.

### Phase 4: Framework System Migrations (2026-05-03, session 19)

- [x] **Moved system migrations out of `src/`**: All framework migrations now live in `database/migrations/framework/{feature}/`.
- [x] **Updated `FeatureRegistry::initDefaults()`** — migration paths point to `database/migrations/framework/{feature}/`.
- [x] `core`, `auth`, `authserver`, `messaging`, `queue` features all have migration files using `SchemaBuilder` / `Blueprint` API.
- [x] All migrations idempotent (`hasTable()` guard + `dropTableIfExists()`). Cross-table dependencies via `$dependencies`.
- [x] 2020 timestamp prefix — installations with `migration_cutoff` skip framework tables automatically.
- [x] **`docs/1.2-new-features.md`** — Section 16 added (schema reference, namespace map, idempotency notes, timestamp rationale, BC notes).
- [x] Re-verified full suite with `./dockertest` → **927 tests, 1866 assertions, 0 failures**.

### Phase 4: Security — View Escaping Helpers (2026-05-05, session 27)

- [x] **`e(mixed $value, string $encoding = 'UTF-8'): string`** (new global in `src/Pramnos/helpers.php`): `htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE`; `null`/`false` → `''`. Guarded by `function_exists`.
- [x] **`View::escape()` and `View::e()`** (new instance methods): delegate to global `e()` for template use.
- [x] **Unit tests** (`tests/Unit/Application/ViewEscapeTest.php`) — 15 tests, 23 assertions.
- [x] **`docs/1.2-new-features.md`** — Section 23 added.

### Phase 4: Security — Session Cookie Hardening (2026-05-05, session 27)

- [x] **`Session::start()`**: `ini_set('session.use_strict_mode', '1')` before `session_start()` — rejects attacker-supplied session IDs.
- [x] **`Session::reset()`**: added `session_regenerate_id(true)` (session fixation prevention) and `regenerateCsrfToken()` (CSRF token rotation on login/logout).
- [x] **`Session::isHttps(): bool`** (new static helper): accepts `'on'` and `'1'` — fixes IIS/CGI environments. `start()` now uses this instead of inline check.
- [x] **`Request::isHttps()`**: updated to accept `'1'` for consistency with `Session::isHttps()`.
- [x] **Unit tests** (`tests/Unit/Http/SessionSecurityTest.php`) — 11 tests, 11 assertions.
- [x] **`docs/1.2-new-features.md`** — Section 22 added.

### Phase 4: Security — CSRF Hardening (2026-05-05, session 27)

- [x] **`Session::regenerateToken()`** and **`start()`**: `random_bytes(5)` → `random_bytes(32)` (40-bit → 256-bit entropy). `start()` silently upgrades existing short tokens on first request.
- [x] **`Session::getFingerprint()`**: `md5()` → `hash_hmac('sha256', ...)`. Output is now a 64-char hex string.
- [x] **`Session::getCsrfToken()`** (new): synchronizer token, 256-bit, stored in `$_SESSION['csrf_token']`, generated lazily.
- [x] **`Session::verifyCsrfToken(string $submitted): bool`** (new): timing-safe `hash_equals()` comparison.
- [x] **`Session::regenerateCsrfToken()`** (new): regenerates `$_SESSION['csrf_token']`.
- [x] **`CsrfMiddleware`** (new `src/Pramnos/Http/Middleware/CsrfMiddleware.php`): protects POST/PUT/PATCH/DELETE; reads `_csrf_token` field or `X-CSRF-Token` header; throws 419; static `token()` and `tokenField()` helpers.
- [x] **Unit tests** (`tests/Unit/Http/CsrfTest.php`) — 22 tests, 35 assertions.
- [x] **`docs/1.2-new-features.md`** — Section 21 added.

### Phase 4: PHP 8.1 Minimum Version (2026-05-05, session 27)

- [x] `composer.json` `require.php` bumped from `>=7.4` → `>=8.1`.
- [x] `require-dev.php` bumped to `>=8.1`; `phpunit/phpunit` dropped `^9.5` (required PHP < 8.1).
- [x] `web-token/jwt-framework` constraint narrowed from `^2.2|^3.0` → `^3.0` (2.x was incompatible with PHP 8.1).
- [x] `docs/1.2-new-features.md` — Section 20 added: rationale, feature table, cleanup notes.

### Phase 4: Centralized Error / Exception Handler (2026-05-05, session 27)

- [x] **`ExceptionHandler`** (new `src/Pramnos/Http/ExceptionHandler.php`): `render(\Throwable, format, debug): Response` — HTML (friendly or debug with escaped stack trace) and JSON (`{"error":…,"code":…}` envelope, + debug fields). `log(\Throwable): void` — delegates to `Logger::error()`, logs all exceptions (not just SQL). `detectFormat(): string` — sniffs `HTTP_ACCEPT` for early-bootstrap contexts. HTTP status: preserves 4xx/5xx codes, maps everything else to 500.
- [x] **`Application::exec()`** updated: replaced 25-line ad-hoc catch block with 5-line delegation to `ExceptionHandler`. Detects format from `$doc->getType()`, debug from `DEVELOPMENT` constant.
- [x] **Unit tests** (`tests/Unit/Http/ExceptionHandlerTest.php`) — 18 tests, 39 assertions, 100% logic coverage.
- [x] **`docs/1.2-new-features.md`** — Section 19 added with output format table, status mapping table, logging notes, full API reference, BC notes.

### Phase 4: Formal Response Object (2026-05-04, session 27)

- [x] **`Response`** (new `src/Pramnos/Http/Response.php`): Immutable-style fluent builder. Static factories: `make()`, `json()`, `redirect()`. Mutators: `withStatus()`, `withHeader()`, `withRawHeader()`, `withoutHeader()`, `withBody()`. Accessors: `getStatusCode()`, `getBody()`, `getHeader()`, `getHeaderLine()`, `hasHeader()`, `getHeaders()`. Emission: `send()` (delegates to `http_response_code()` + `header()` + `echo`; `@codeCoverageIgnore`).
- [x] **Unit tests** (`tests/Unit/Http/ResponseTest.php`) — 23 tests, 45 assertions, 100% logic coverage.
- [x] **`docs/1.2-new-features.md`** — Section 18 added with getting-started examples, middleware inspection pattern, factory helper examples, full API reference.

### Phase 4: Middleware Pipeline (2026-05-05, session 26)

- [x] **`MiddlewareInterface`** (new `src/Pramnos/Http/MiddlewareInterface.php`): `handle(Request $request, callable $next): mixed`. PSR-15-inspired contract using the framework's own Request class.
- [x] **`MiddlewarePipeline`** (new `src/Pramnos/Http/MiddlewarePipeline.php`): `pipe(MiddlewareInterface|string): static` + `run(Request, callable): mixed`. Builds the onion chain via `array_reduce`+`array_reverse`. Accepts instances or FQCN strings (lazy `new $fqcn()`).
- [x] **Built-in middleware** (all new in `src/Pramnos/Http/Middleware/`):
  - `AuthMiddleware` — throws 401 or redirects if session is not logged in.
  - `CorsMiddleware` — sets `Access-Control-*` headers; short-circuits OPTIONS preflight with 204.
  - `ThrottleMiddleware` — per-IP APCu counter; throws 429 on limit; passes through if APCu unavailable.
  - `MaintenanceModeMiddleware` — throws 503 when `maintenance.flag` exists at app root.
- [x] **`Route::middleware()`** (new, `src/Pramnos/Routing/Route.php`): variadics, returns `$this`. `getMiddleware()` / `hasMiddleware()` accessors.
- [x] **`Router::addGlobalMiddleware()`** (new): global middleware runs before route-specific for every dispatch. `dispatch()` and `dispatchSafe()` both run the combined pipeline.
- [x] **`Router::get()` / `post()` / `put()` / `delete()` / `patch()` / `options()`**: now return `Route` (was `Router`). Enables `$router->get(...)->middleware(...)` fluent chaining. `addRoute()` and `match()` still return `Router`.
- [x] **`Controller::addMiddleware(string|array $actions, middleware): static`** (new): per-action or wildcard `'*'` middleware. Private `_runThroughMiddleware()` wraps action calls in `exec()`; if no middleware registered, calls action directly — identical to pre-middleware code path.
- [x] **Unit tests** (`tests/Unit/Http/MiddlewarePipelineTest.php`) — 20 tests: empty pipeline, single MW, onion order, short-circuit, result transform, FQCN lazy instantiation, Route accumulation/chaining, Router global MW + dispatch integration, Controller action-specific/wildcard/array/short-circuit/fluent.
- [x] **`docs/1.2-new-features.md`** — Section 17 added with full getting-started examples (route, global, controller), execution order diagram, write-your-own guide, standalone usage, full API reference table, all 4 built-ins documented.
- [x] Re-verified full suite: **1177/1177 tests, 2892 assertions, 0 failures**.

### Phase 1.2: QB Subqueries & Window Functions (2026-05-04, session 25)

- [x] **`QueryBuilder::selectSub(QueryBuilder|Closure, string $alias)`** — adds a correlated or uncorrelated subquery as a SELECT column; bindings go into the `select` slot (precede WHERE bindings). Closure receives a fresh QB.
- [x] **`QueryBuilder::fromSub(QueryBuilder|Closure, string $alias)`** — sets the FROM clause to a derived table; bindings go into the `from` slot (between `select` and `join`/`where`). Accepts QB or Closure.
- [x] **`QueryBuilder::over(string|Expression $fn, ?string $alias, array|string $partition, array $order, string $frame): Expression`** — builds a dialect-aware window function OVER expression. Partition and order columns are quoted by the grammar (backticks MySQL / double-quotes PG). Function fragment is passed verbatim.
- [x] **`GrammarInterface::compileWindowOver()`** — new contract method.
- [x] **`Grammar::compileWindowOver()`** — base implementation shared by all dialects (quoting via `quoteColumn()`). Handles PARTITION BY, ORDER BY (assoc and indexed), and optional ROWS/RANGE frame clause.
- [x] **Unit tests** — 18 new tests; total **101/101**.
- [x] **MySQL integration tests** — 6 new tests (selectSub correlated, fromSub derived table, fromSub binding order, RANK(), ROW_NUMBER(), SUM() OVER); total **91/91**.
- [x] **PostgreSQL integration tests** — 7 new tests (same coverage + cumulative SUM with ROWS frame); total **80/80**.
- [x] **`docs/1.2-new-features.md`** updated with full API reference and examples for all new methods.
- [x] Re-verified full suite: **1157/1157 tests, 2860 assertions, 0 failures**.

### Phase 1.2: QB Convenience & Aggregate Methods (2026-05-04, session 24)

- [x] **`QueryBuilder` — new methods** (all BC-additive, original builder never mutated by aggregates):
  - **Joins:** `rightJoin()`, `crossJoin()`
  - **Ordering/paging:** `latest()`, `oldest()`, `forPage(int $page, int $perPage)`
  - **Conditional:** `when(mixed $condition, Closure, ?Closure $default)`
  - **Aggregates:** `sum()`, `avg()`, `min()`, `max()` (all clone-based, return typed scalars)
  - **Existence checks:** `exists()`, `doesntExist()` (use `SELECT EXISTS(...)` on DB)
  - **Single-value helpers:** `value(string $col)`, `pluck(string $col): array`
  - **DML helpers:** `increment(string $col, step=1)`, `decrement(string $col, step=1)` — use `update()` internally, return affected rows
  - **Chunked processing:** `chunk(int $size, Closure $callback)` — stops when callback returns `false`
  - **Locking:** `lockForUpdate()`, `sharedLock()` — compiled via new `compileLock()` Grammar hook
  - **Sub-query conditions:** `whereExists()`, `whereNotExists()`, `orWhereExists()`, `orWhereNotExists()`
  - **Date-part conditions:** `whereDate()`, `whereYear()`, `whereMonth()`, `whereDay()`, `whereTime()` — compiled via new `compileDatePartExtraction()` Grammar hook; dialect-transparent (MySQL functions vs. PG EXTRACT/cast)
- [x] **`Grammar` base** — added `compileLock()` hook (default `''`) and `compileDatePartExtraction()` hook (MySQL: DATE/YEAR/MONTH/DAY/TIME functions); `compileWheres()` handles new `Exists`, `NotExists`, `DatePart` where types; `compileSelect()` appends `compileLock()` output and handles CROSS JOIN without ON clause.
- [x] **`MySQLGrammar`** — `compileLock()`: `FOR UPDATE` / `LOCK IN SHARE MODE`.
- [x] **`PostgreSQLGrammar`** — `compileLock()`: `FOR UPDATE` / `FOR SHARE`; `compileDatePartExtraction()`: `(col)::date`, `EXTRACT(...)`.
- [x] **`Result`** — `public ?int $mysqlAffectedRows` property + `getAffectedRows()` prefers it for MySQL; fixes `increment()`/`decrement()` returning -1 after prepared-statement `close()`.
- [x] **`Database::execute()`** — captures `$statement->affected_rows` before `finally { $statement->close() }` for MySQL DML prepared statements; stores in `$obj->mysqlAffectedRows`.
- [x] **Unit tests** (`tests/Unit/Database/QueryBuilderUnitTest.php`) — 35 new tests; total 83/83.
- [x] **MySQL integration tests** (`tests/Integration/Database/QueryBuilderMySQLTest.php`) — 35 new tests (including `qb_events` table for date-part tests); total 85/85.
- [x] **PostgreSQL integration tests** (`tests/Integration/Database/QueryBuilderPostgreSQLTest.php`) — 35 new tests (same coverage, PG dialect: `EXTRACT()`, `::date` casts, `FOR SHARE`, `BEGIN/COMMIT`); total 73/73.
- [x] **`docs/1.2-new-features.md`** updated with full API reference for all new methods.
- [x] Re-verified full suite: **1126/1126 tests, 2791 assertions, 0 failures**.

### Phase 1.1: Foundations
- [x] Read/Write Replicas Support in `Database.php`.
- [x] Auto-reconnect logic for database connections.
- [x] Database Capabilities detection (`DatabaseCapabilities::isMySQL()` / `isPostgreSQL()` / `hasTimescaleDB()`, runtime detection via `pg_extension`).

### Phase 1.2: QueryBuilder — DML Core

**Implemented:**
- [x] `select()`, `distinct()`, `from()` / `table()`
- [x] `INSERT` (basic), `UPDATE`, `DELETE`
- [x] `RETURNING` clause support (PostgreSQL/TimescaleDB)
- [x] `where()`, `orWhere()`, `whereIn()`, `whereRaw()`, nested where via Closure
- [x] `join()` (type-parametric: inner/left/right/full/cross), `joinRaw()`, `leftJoin()`
- [x] `orderBy()`, `orderByRaw()`, `groupBy()`, `groupByRaw()`
- [x] `having()`, `havingRaw()`
- [x] `limit()`, `offset()`, `clearOrderingAndPaging()`
- [x] `returning()`
- [x] `raw()` / `Expression` class for inline dialect-specific SQL
- [x] `get()`, `first()` (returns `Result`), `getBindings()`, `toSql()`
- [x] Cache-aware `get()` with `cursor = -1` initialization (correct iteration semantics)
- [x] `whereNull()` / `whereNotNull()` / `orWhereNull()` / `orWhereNotNull()`
- [x] `whereBetween()` / `whereNotBetween()` / `orWhereBetween()` / `orWhereNotBetween()`
- [x] `UNION` / `UNION ALL` via `union(QueryBuilder)` / `unionAll(QueryBuilder)`
- [x] `truncate()`
- [x] `insertOrIgnore()` — MySQL `INSERT IGNORE`, PostgreSQL `ON CONFLICT DO NOTHING`
- [x] `upsert(values, conflictColumns, updateValues)` — MySQL `ON DUPLICATE KEY UPDATE`, PostgreSQL `ON CONFLICT DO UPDATE`

**Not yet implemented:**
- [ ] CTEs via `with()`
- [ ] Subqueries as SELECT columns or FROM source
- [ ] Window functions (`OVER`, `PARTITION BY`, `RANK`, `ROW_NUMBER`)
- [ ] QueryBuilder Grammar/Adapter Pattern (see Roadmap)

### Phase 1.2: Internal Migration to QueryBuilder

- [x] **`Pramnos\Application\Model`**: `_load()`, `_delete()`, `getCount()`, `_getPaginated()`, `_getList()` use QueryBuilder.
- [x] **`Pramnos\Database\Database`**: `insertDataToTable()` and `updateTableData()` use QueryBuilder.
- [x] **`Pramnos\Html\Datatable\Datasource`**: `render()` fully rewritten with QueryBuilder (eliminates manual SQL concatenation). Uses `fetchNext()` for correct iteration.
- [ ] `Pramnos\Database\Migration` — pending Schema Builder
- [ ] `Pramnos\Database\Adjacencylist` — pending
- [ ] `Pramnos\Auth\Auth` — pending
- [ ] `Pramnos\User\*` — pending
- [ ] `Pramnos\Logs\*` — pending

### Phase 1.2: Multi-Dialect Correctness Fixes (2026-05-03)

Bug fixes required after verifying against the Urbanwater PostgreSQL test suite (5 176 tests):

- [x] **`Database::prepare()` — `%X` inside string literals**: `preg_replace_callback` now skips single-quoted SQL literals (e.g. `'%display-read-%'`) when counting and replacing `%i`/`%d`/`%s`/`%b` placeholders. Previously caused PostgreSQL syntax errors on ILIKE queries.
- [x] **`Result::fetchNext()` — double-read on 1-row results**: `pg_result_seek($result, 1)` on a 1-row result returns `false` without moving the cursor. Return value is now checked; sets `$this->eof = true` if seek fails.
- [x] **`Result::fetch()` — missing EOF guard**: Added early `if ($this->eof) return null` before `cursor++` to prevent re-reading past the end.
- [x] **`Database::query()` cache-hit — cursor off-by-one**: Cache-hit path was initializing `$obj->cursor = 0`; first `fetch()` call would skip `result[0]`. Fixed to `cursor = -1`.
- [x] **`Model::_getPaginated()` — COUNT query inherits ORDER BY**: `clone $qb` carried the ORDER BY into the COUNT query, causing PostgreSQL to reject it (*"column must appear in GROUP BY"*). Fixed by calling `->clearOrderingAndPaging()` on the count query builder.
- [x] **`QueryBuilder::clearOrderingAndPaging()`**: New public method — removes `$orders`, unsets `$limit`/`$offset`, clears `$bindings['order']`. Used by `_getPaginated()` count path.
- [x] **`User::save()` PostgreSQL path**: Replaced direct `pg_fetch_result()` call with `$dbresult->fields['userid']` for consistency with the Result API.
- [x] **`Datasource::render()` error handling**: `die()` replaced with `throw new \Exception()`; `Exception` catch widened to `Throwable`; added null result guard.

### Phase 1.2: Testing Infrastructure

- [x] `tests/bootstrap.php` — Added missing `DB_USERSTABLE`, `DB_USERGROUPSTABLE`, `DB_USERGROUPSUBSCRIPTIONS`, `DB_USERDETAILSTABLE`, `DB_PERMISSIONSTABLE` constants required by User tests.
- [x] `tests/Unit/Pramnos/Application/ModelFilterKeywordTest.php` — 16 unit tests for `_stripSqlKeyword()` covering WHERE, ORDER BY, GROUP BY stripping.
- [x] `tests/Integration/Database/PostgreSQLPreparedStatementTest.php` — Regression test for duplicate prepared statement bug (requires TimescaleDB container).
- [x] `tests/Integration/Database/QueryBuilderMySQLTest.php` — 35 integration tests against MySQL. Schema: `qb_products` + `qb_tags`. Covers: SELECT/DISTINCT/first, all WHERE variants (null/notNull/between/notBetween/in/raw/nested/or*), INNER JOIN/LEFT JOIN/joinRaw, GROUP BY/HAVING/havingRaw, ORDER BY/LIMIT/OFFSET, clearOrderingAndPaging, raw expressions, INSERT/UPDATE/DELETE, TRUNCATE, insertOrIgnore, upsert (3 variants), fetchAll, fetchNext.
- [x] `tests/Integration/Database/QueryBuilderPostgreSQLTest.php` — 37 integration tests against PostgreSQL/TimescaleDB. Same schema + PostgreSQL-specific: RETURNING on INSERT/UPDATE/DELETE (4 tests), insertOrIgnore with RETURNING, upsert with RETURNING, ILIKE, single-row fetchNext eof guard.

### Phase 1.2: Database Class Coverage (2026-05-03, session 3)

- [x] **`tests/Unit/Database/DatabaseCapabilitiesTest.php`** — 40 unit tests (mocked DB). Covers: `has()` for all 8 features/engines, TimescaleDB detection via query mock + cache hit, `isMySQL`/`isPostgreSQL`/`hasTimescaleDB`, `ifCapable()` all 3 paths.
- [x] **`tests/Unit/Database/QueryBuilderUnitTest.php`** — 12 unit tests. Covers: `compileDelete()` via `toSql()` (no-where and with-where), `orderByRaw` compiled SQL, `groupByRaw` compiled SQL, INSERT/UPDATE stub dispatch.
- [x] **`QueryBuilderMySQLTest`** extended — 6 new integration tests: `Result::__get`, `getInsertId`, `getAffectedRows`, `getNumFields`, `free`.
- [x] **Bug fix — `Result::getAffectedRows()` MySQL**: Was calling `mysqli_affected_rows($mysqli_result)` (wrong type). Fixed to `mysqli_affected_rows($this->database->getConnectionLink())`.
- [x] **Bug fix — `DatabaseCapabilities::ifCapable()` PHP 8.4 deprecation**: `callable $ifFalse = null` → `?callable $ifFalse = null`.

### Phase 1.3: Grammar/Adapter Pattern (2026-05-03, session 3)

- [x] **`GrammarInterface`** — defines the full compile contract: `compileSelect`, `compileWheres`, `compileHavings`, `compileInsert`, `compileInsertOrIgnore`, `compileUpsert`, `compileUpdate`, `compileDelete`, `compileTruncate`, `quoteColumn`, `getPlaceholder`.
- [x] **`Grammar` (abstract)** — shared dialect-neutral implementation with template-method hooks: `compileReturning()` (empty by default) and `wrapColumnForOperator()` (identity by default).
- [x] **`MySQLGrammar`** — backtick quoting, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`.
- [x] **`PostgreSQLGrammar`** — double-quote quoting, `ON CONFLICT DO NOTHING / DO UPDATE SET`, `RETURNING`, `::text` cast for LIKE/ILIKE on non-text columns.
- [x] **`TimescaleDBGrammar`** — extends PostgreSQLGrammar (stub; hooks ready for time_bucket, hypertable DDL in Phase 1.4).
- [x] **`Expression`** — extracted to `Expression.php` for PSR-4 autoloader hygiene.
- [x] **`QueryBuilder` refactored** — grammar injected in constructor (auto-selected from `$db->type` / `$db->timescale`); `setGrammar()` / `getGrammar()` added; state accessors added for Grammar read-only access; all compile logic removed from QB. QB: 1180 → 914 lines.

### Phase 1.3: DatabaseCapabilities Alignment (2026-05-03, session 4)

- [x] **New constants** — `TIMESCALEDB` (alias for `FEATURE_TIMESCALEDB`), `JSONB` (alias for `FEATURE_JSONB`), `MATERIALIZED_VIEWS`, `ENUMS` — aligns with Backport Spec Section 14.1.
- [x] **Static WeakMap cache** — replaced instance `$cache` array with `static WeakMap` keyed by Database object. Per-connection caching without stale entries across test runs.
- [x] **New methods** — `hasMaterializedViews(): bool`, `hasEnums(): bool`.
- [x] Old `FEATURE_*` constants retained with `@deprecated` docblocks for BC.

### Phase 1.2: CTEs / Triggers / Sequences (2026-05-03, session 6)

- [x] **`QueryBuilder::with()`** — adds a CTE; closure, QB instance, or raw string; `withRecursive()` shortcut. `getCtes()` accessor for Grammar. `WITH RECURSIVE` emitted when at least one CTE is marked recursive.
- [x] **`GrammarInterface::compileCtes()`** — new contract method; base implementation in `Grammar` compiles `WITH [RECURSIVE] name AS (…)` prefix and prepends to `compileSelect()` output.
- [x] **Trigger DDL** — `SchemaGrammarInterface::compileCreateTrigger()` / `compileDropTrigger()`; `SchemaGrammar` base: MySQL syntax (`CREATE TRIGGER … FOR EACH ROW`); `PostgreSQLSchemaGrammar` override: `CREATE OR REPLACE TRIGGER … EXECUTE FUNCTION fn()` with `DROP TRIGGER … ON table`.
- [x] **Sequence DDL** — `SchemaGrammarInterface::compileCreateSequence()` / `compileDropSequence()`; base (MySQL) returns `''` (silent no-op); `PostgreSQLSchemaGrammar` implements full `CREATE SEQUENCE IF NOT EXISTS … START WITH … INCREMENT BY … CYCLE`.
- [x] **`SchemaBuilder::createTrigger()` / `dropTrigger()`** — delegates to grammar; supports `#PREFIX#` table resolution.
- [x] **`SchemaBuilder::createSequence()` / `dropSequence()`** — delegates to grammar; MySQL calls are silently ignored (empty SQL guard).
- [x] **Tests** — 7 new CTE tests in `QueryBuilderUnitTest`, 14 new trigger/sequence tests in `SchemaBuilderUnitTest`.

### Phase 1.4: timeBucket() Dialect Translation (2026-05-03, session 5)

- [x] **`GrammarInterface::compileTimeBucket()`** — new contract method.
- [x] **`Grammar` (base/MySQL)** — `FROM_UNIXTIME` arithmetic for sub-month intervals; `DATE_FORMAT` for month/year. Static helpers: `parseInterval()`, `unitToSeconds()`, `unitToDateTruncPrecision()`.
- [x] **`PostgreSQLGrammar::compileTimeBucket()`** — `date_trunc` for count=1 standard units; `to_timestamp(floor(extract(epoch…) / N) * N)` for arbitrary sub-month intervals.
- [x] **`TimescaleDBGrammar::compileTimeBucket()`** — native `time_bucket('interval', col)`.
- [x] **`QueryBuilder::timeBucket(string $interval, string|Expression $column): Expression`** — delegates to the injected grammar; returned `Expression` is usable in `select`, `groupBy`, `orderBy`, `where`, `having`.
- [x] **Tests** — 31 new unit tests added to `QueryBuilderUnitTest.php`: all three dialects × standard intervals × arbitrary intervals × Expression column passthrough × GROUP BY integration.

### Phase 1.4: DDL / Schema Builder (2026-05-03, session 4)

**New classes:**
- [x] **`ColumnDefinition`** — fluent column descriptor; all modifiers (`nullable`, `default`, `unsigned`, `autoIncrement`, `primary`, `unique`, `after`, `first`, `comment`, `check`, `storedAs`, `virtualAs`, `charset`, `collation`).
- [x] **`ForeignKeyDefinition`** — fluent FK descriptor; `references()`, `on()`, `onDelete()`, `onUpdate()`, cascade shortcuts.
- [x] **`Blueprint`** — table structure accumulator; full column-type API (integer, string, text, boolean, timestamp, timestampTz, json, jsonb, uuid, enum, decimal, geometry, …); `timestamps()`, `softDeletes()`; index/FK helpers; ALTER-mode methods (`dropColumn`, `renameColumn`, `dropIndex`, `dropForeign`).
- [x] **`SchemaGrammarInterface`** — DDL compile contract (createTable, alterTable, drop, views, materialized views, indexes, introspection).
- [x] **`SchemaGrammar`** (abstract) — shared compilation via Template Method: `compileCreate`, `compileAlter`, `compileColumn`, `compileDrop`, index DDL, view DDL; hooks: `compileAutoIncrement`, `compileTableOptions`, `compileDefaultValue`, `compileColumnPosition`, `inlineForeignKeys`.
- [x] **`MySQLSchemaGrammar`** — backtick quoting, `TINYINT(1)` boolean, `ENGINE=InnoDB` options, `UNIQUE KEY` syntax, `RENAME TABLE`, inline FK, `AUTO_INCREMENT`, MySQL-only AFTER/FIRST column positioning.
- [x] **`PostgreSQLSchemaGrammar`** — double-quote quoting, `SERIAL`/`BIGSERIAL` auto-increment, `BOOLEAN`/`TIMESTAMPTZ`/`BYTEA`/`JSONB`/`UUID` types, ENUM→VARCHAR+CHECK, separate FK ALTER statements, full MATERIALIZED VIEW support.
- [x] **`TimescaleDBSchemaGrammar`** — extends PostgreSQL (stub; hooks ready for hypertable DDL).

**`SchemaBuilder` — full implementation:**
- [x] `createTable(table, callback)`, `create()` (legacy alias)
- [x] `alterTable(table, callback)`
- [x] `dropTable()`, `dropTableIfExists()`, `drop()` (legacy alias), `renameTable()`
- [x] `truncate()`
- [x] `hasTable()`, `hasColumn()`
- [x] `createIndex()`, `createUniqueIndex()`, `dropIndex()`
- [x] `createView()`, `createOrReplaceView()`, `dropView()`
- [x] `createMaterializedView()`, `refreshMaterializedView()`, `dropMaterializedView()`
- [x] `createHypertable()`, `addSpaceDimension()`, `enableCompression()`, `addCompressionPolicy()`, `addRetentionPolicy()` (all silent no-op on non-TimescaleDB backends)
- [x] `createContinuousAggregate()` — native TimescaleDB / MATERIALIZED VIEW (PG) / VIEW (MySQL) fallback chain
- [x] `ifCapable(capability, callback, fallback)` — capability-conditional DDL per Backport Spec Section 14.3
- [x] `getGrammar()` / `setGrammar()` — grammar injection
- [x] `$db->schema()` alias added to `Database` class

**Tests:**
- [x] **`tests/Unit/Database/SchemaBuilderUnitTest.php`** — 85 unit tests (no DB connection). Covers: grammar selection (MySQL/PG/TimescaleDB), all MySQL column types, all PG column types, CREATE TABLE (columns, PK, UNIQUE, FK, indexes), ALTER TABLE (add/drop/rename column), DROP/RENAME, index DDL, view DDL, materialized views, `ifCapable` (3 paths), prefix resolution, new DatabaseCapabilities constants/methods, Blueprint helpers.

---

## 🛠️ Work in Progress

### Phase 1.4: TimescaleDB Extension Builder
- [x] `time_bucket()` dialect translation in QueryBuilder
- [ ] Continuous aggregate CLI/migration support

---

## 📈 Quality Metrics
- **Framework Test Pass Rate:** 1027/1027 pass (0 failures, 0 errors, 0 skipped) — includes unit, integration, and characterization suites.
- **Urbanwater Integration Suite:** 5 176 / 5 176 tests passing (0 failures, 0 errors) — runs against live PostgreSQL + TimescaleDB via Docker.
- **PHP Compatibility:** 8.4 (tested in Docker).
- **Database Compatibility:** MySQL 8.0, PostgreSQL 14, TimescaleDB.

## 📝 Notes
- The Internal Migration has successfully transitioned the most critical parts of the framework to the new architecture while maintaining 100% backward compatibility.
- All legacy SQL fragments passed to `Model` or `Datasource` are handled via `whereRaw()` and similar methods — existing applications don't break.
- Several DML QueryBuilder features were previously marked as done prematurely (UNION, CTEs, window functions, whereNull, etc.). Status corrected above.
- The Grammar/Adapter pattern is now formally in the Roadmap as a prerequisite to Schema Builder. Without it, dialect-specific SQL differences continue to accumulate as scattered `if ($db->type == 'postgresql')` checks.
