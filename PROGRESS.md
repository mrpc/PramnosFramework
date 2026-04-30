# Project Progress - Pramnos Framework v1.2

## 📅 Last Updated: 2026-04-30

## 🚀 Completed Milestones

### Phase 1.1: Foundations
- [x] Read/Write Replicas Support in `Database.php`.
- [x] Auto-reconnect logic for database connections.
- [x] Database Capabilities detection (MySQL/Postgres/TimescaleDB).

### Phase 1.2: Internal Migration (DML & Core)
- [x] **QueryBuilder Implementation:**
    - Fully fluent API for SELECT, INSERT, UPDATE, DELETE.
    - Support for `RETURNING` clause (PostgreSQL).
    - Support for `INSERT ... ON CONFLICT` (Upsert).
    - Support for Raw expressions via `Expression` class and `raw()` helper.
    - Added `whereRaw`, `joinRaw`, `orderByRaw`, `groupByRaw`, `havingRaw` for legacy compatibility.
- [x] **Core Refactoring:**
    - **`Pramnos\Application\Model`**: Refactored `_load()`, `_delete()`, `getCount()`, `_getPaginated()`, and `_getList()` to use QueryBuilder.
    - **`Pramnos\Database\Database`**: Refactored `insertDataToTable()` and `updateTableData()` to use QueryBuilder.
    - **`Pramnos\Html\Datatable\Datasource`**: Completely refactored the complex `render()` method to use QueryBuilder, eliminating hundreds of lines of manual SQL concatenation.
- [x] **Testing & Verification:**
    - Ran full framework test suite (153 tests) with 100% success.
    - Verified compatibility with MySQL and PostgreSQL.

## 🛠️ Work in Progress

### Phase 1.3: DDL & Schema Builder
- [ ] Implement `SchemaBuilder` for fluent migrations.
- [ ] Add TimescaleDB specific extension builders.

## 📈 Quality Metrics
- **Test Pass Rate:** 100% (153/153 tests passing).
- **PHP Compatibility:** 8.4 (tested in Docker).
- **Database Compatibility:** MySQL 8.0, PostgreSQL 14, TimescaleDB.

## 📝 Notes
- The Internal Migration has successfully transitioned the most critical parts of the framework to the new architecture while maintaining 100% backward compatibility.
- All legacy SQL fragments passed to `Model` or `Datasource` are handled via `whereRaw()` and similar methods, ensuring existing applications don't break.
