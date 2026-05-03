# Project Progress - Pramnos Framework v1.2

## 📅 Last Updated: 2026-05-03 (session 2)

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

---

## 🛠️ Work in Progress

### Phase 1.3: QueryBuilder Grammar/Adapter Pattern
*Prerequisite for DDL Schema Builder.*
- [ ] `Grammar` interface / abstract class
- [ ] `MySQLGrammar`, `PostgreSQLGrammar`, `TimescaleDBGrammar`
- [ ] Dialect logic migrated from `Database::prepare()` into Grammars
- [ ] Complete missing DML features (`whereNull`, `whereBetween`, `UNION`, CTEs, subqueries, window functions)

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
