---
date: 2026-07-27
categories:
  - Changelog
  - Database
tags:
  - schema
  - migration
  - postgresql
  - comments
  - bugfix
---

# Column comments are now applied when altering a table

Adding a documented column to an existing table via `Schema::table()` /
`alterTable()` now applies its `->comment()`. Previously the comment was
silently dropped: `SchemaGrammar::compileAlter()` never emitted the comment
statements, so on PostgreSQL a migration that added a column with a comment
produced the `ALTER TABLE ... ADD COLUMN` but no `COMMENT ON COLUMN`.

<!-- more -->

## Fixed

`SchemaGrammar::compileAlter()` now appends `compileCommentStatements()` — the
same step `compileCreate()` already runs — so table and column comments are
applied in alter mode too.

- **PostgreSQL** emits the separate `COMMENT ON COLUMN` / `COMMENT ON TABLE`
  statements for columns added (or a table commented) via alter.
- **MySQL** is unchanged: it carries comments inline in the column definition
  (`... COMMENT '...'`) and its `compileCommentStatements()` returns `[]`, so no
  duplicate or extra statement is produced.

```php
// Now applies the comment on PostgreSQL as well as MySQL:
$app->database->schema()->table('messages', function ($table) {
    $table->string('pinned_track', 500)->nullable()
          ->comment('Snapshot of the now-playing track pinned to this message.');
});
```

## Why it went unnoticed

No framework migration had ever added a *commented* column through the alter
path — comments were only used when creating tables (`compileCreate`), which was
unaffected. The gap surfaced when an application migration added a documented
column to an existing table.

## Backward compatibility

Additive and fully backward compatible: no public signatures change, and
behaviour only changes where a comment was previously being discarded. MySQL
output is identical to before.

## Tests

- Unit (`SchemaGrammarTest`): `compileAlter()` emits `COMMENT ON COLUMN` on
  PostgreSQL and keeps the comment inline (no separate statement) on MySQL.
- Integration (`SchemaBuilderPostgreSQLTest`): a column added via `table()` on a
  real PostgreSQL database has its comment stored, verified through
  `pg_description`.
