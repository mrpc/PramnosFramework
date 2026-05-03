# Project Progress - Pramnos Framework v1.2

## 📅 Last Updated: 2026-05-03 (session 3)

## 🚀 Completed Milestones

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

---

## 🛠️ Work in Progress

### Phase 1.4: DDL / Schema Builder
*Next step after Grammar is in place.*
- [ ] Fluent DDL interface (`createTable`, `alterTable`, `dropTable`, indexes, constraints, views)

### Phase 1.4: DDL / Schema Builder
- [ ] Fluent DDL interface (`createTable`, `alterTable`, `dropTable`, indexes, constraints, views)
- [ ] TimescaleDB Extension Builder (`createHypertable`, `addRetentionPolicy`, `timeBucket`, etc.)

---

## 📈 Quality Metrics
- **Framework Test Pass Rate:** 159/171 pass, 12 errors (all pre-existing: no DB connection in local env).
- **Urbanwater Integration Suite:** 5 176 / 5 176 tests passing (0 failures, 0 errors) — runs against live PostgreSQL + TimescaleDB via Docker.
- **PHP Compatibility:** 8.4 (tested in Docker).
- **Database Compatibility:** MySQL 8.0, PostgreSQL 14, TimescaleDB.

## 📝 Notes
- The Internal Migration has successfully transitioned the most critical parts of the framework to the new architecture while maintaining 100% backward compatibility.
- All legacy SQL fragments passed to `Model` or `Datasource` are handled via `whereRaw()` and similar methods — existing applications don't break.
- Several DML QueryBuilder features were previously marked as done prematurely (UNION, CTEs, window functions, whereNull, etc.). Status corrected above.
- The Grammar/Adapter pattern is now formally in the Roadmap as a prerequisite to Schema Builder. Without it, dialect-specific SQL differences continue to accumulate as scattered `if ($db->type == 'postgresql')` checks.
