# Project Progress - Pramnos Framework v1.2

## 📅 Last Updated: 2026-05-03 (session 17)

## 🚀 Completed Milestones

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
- **Framework Test Pass Rate:** 833/833 pass (0 failures, 0 errors) — includes unit, integration, and characterization suites.
- **Urbanwater Integration Suite:** 5 176 / 5 176 tests passing (0 failures, 0 errors) — runs against live PostgreSQL + TimescaleDB via Docker.
- **PHP Compatibility:** 8.4 (tested in Docker).
- **Database Compatibility:** MySQL 8.0, PostgreSQL 14, TimescaleDB.

## 📝 Notes
- The Internal Migration has successfully transitioned the most critical parts of the framework to the new architecture while maintaining 100% backward compatibility.
- All legacy SQL fragments passed to `Model` or `Datasource` are handled via `whereRaw()` and similar methods — existing applications don't break.
- Several DML QueryBuilder features were previously marked as done prematurely (UNION, CTEs, window functions, whereNull, etc.). Status corrected above.
- The Grammar/Adapter pattern is now formally in the Roadmap as a prerequisite to Schema Builder. Without it, dialect-specific SQL differences continue to accumulate as scattered `if ($db->type == 'postgresql')` checks.
