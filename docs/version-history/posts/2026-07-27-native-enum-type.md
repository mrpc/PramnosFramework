---
date: 2026-07-27
categories:
  - Changelog
  - Database
tags:
  - schema
  - migration
  - enum
  - postgresql
  - mysql
---

# Native enum types in the schema builder

`Blueprint::enumType()` adds a column backed by a NATIVE enum type — a real
PostgreSQL `CREATE TYPE ... AS ENUM`, or an inline MySQL `ENUM(...)`. This
complements the existing `enum()`, which stores a `VARCHAR` + `CHECK` on
PostgreSQL.

<!-- more -->

## Added

```php
$schema->createTable('users', function ($table) {
    $table->increments('id');
    $table->enumType('role', 'user_role', ['root', 'administrator', 'moderator', 'simple_user'])
          ->default('simple_user');
});
```

- **PostgreSQL** emits `CREATE TYPE "user_role" AS ENUM (...)` *before* the
  `CREATE TABLE` (via a new pre-create step) and types the column as
  `"role" "user_role"`. A type shared by several columns is created once.
- **MySQL** has no named types, so the column is an inline
  `ENUM('root', ...)` and no `CREATE TYPE` is produced.

Choose `enumType()` when you want a real database enum type; keep `enum()` when
you prefer the portable `VARCHAR` + `CHECK` representation on PostgreSQL.

## How it works

A new `compilePreCreateStatements()` hook on `SchemaGrammar` (empty by default)
lets a dialect emit statements that must run before `CREATE TABLE`. The
PostgreSQL grammar overrides it to emit the `CREATE TYPE` for each distinct
native-enum type; column type `enum_native` maps to the quoted type name on
PostgreSQL and to an inline `ENUM(...)` on MySQL.

Additive and backward compatible: `enum()` is unchanged, and `enumType()` /
`compilePreCreateStatements()` are new.

## Tests

- Unit (`SchemaGrammarTest`): PostgreSQL creates the type before the table and
  types the column as it (no CHECK); MySQL is inline with no `CREATE TYPE`; a
  shared type is created once.
- Integration (`SchemaBuilderPostgreSQLTest`): a real native enum type with its
  labels in order, and the column reported as `USER-DEFINED` / the enum
  `udt_name`, verified via `pg_type` / `pg_enum` / `information_schema`.
