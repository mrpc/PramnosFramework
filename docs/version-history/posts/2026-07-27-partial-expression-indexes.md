---
date: 2026-07-27
categories:
  - Changelog
  - Database
tags:
  - schema
  - migration
  - indexes
  - postgresql
---

# Partial indexes and column ordering in the schema builder

`Blueprint::index()` and `Blueprint::unique()` now return an `IndexDefinition`
whose `->where()` makes the index PARTIAL, and index columns may carry an
`ASC` / `DESC` sort direction.

<!-- more -->

## Added

```php
$schema->table('messages', function ($table) {
    // Partial index + descending order
    $table->index(['is_deleted', 'created_at DESC'], 'idx_active')
          ->where('is_deleted = false');

    // Partial UNIQUE (only rows where email IS NOT NULL are unique)
    $table->unique('email', 'uq_email')->where('email IS NOT NULL');
});
```

- **`->where(string $predicate)`** — appends `WHERE (<predicate>)` to the created
  index. The predicate is passed through verbatim, so it is dialect-specific SQL.
- **Column ordering** — a column written as `"created_at DESC"` is emitted as
  `"created_at" DESC`: the identifier is quoted, the trailing `ASC`/`DESC` kept.
- A **partial `unique()`** is compiled as `CREATE UNIQUE INDEX ... WHERE ...`
  (a partial unique cannot be a table constraint), both in `createTable()` (moved
  out of the inline column list to a post-create statement) and in `table()`/alter.

On PostgreSQL these become native partial indexes. `IndexDefinition` is returned
additively (`index()`/`unique()` previously returned `void`), so existing callers
are unaffected. MySQL keeps inlining plain indexes as before; a `WHERE` predicate
is Postgres-specific.

## Tests

- Unit (`SchemaGrammarTest`): partial index with `DESC`, partial unique as a
  `CREATE UNIQUE INDEX`, partial unique moved out of `CREATE TABLE`, and a MySQL
  plain-index BC check.
- Integration (`SchemaBuilderPostgreSQLTest`): a real partial index (with `DESC`)
  and a real partial unique index, verified through `pg_indexes.indexdef`.
