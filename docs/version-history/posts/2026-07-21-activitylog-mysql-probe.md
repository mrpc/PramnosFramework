---
date: 2026-07-21
categories:
  - Changelog
  - Authserver
tags:
  - authserver
  - activity-log
  - mysql
  - schema
  - bugfix
---

# Activity log now writes on MySQL (schema-aware table probe)

`Pramnos\Auth\ActivityLog` silently recorded nothing on MySQL: its
table-existence probe used an unqualified name that never matched the
schema-prefixed physical table. The probe is now driver-aware, so the audit
trail is written on MySQL exactly as it already was on PostgreSQL.

<!-- more -->

## Fixed

- **`ActivityLog::record()` was a silent no-op on MySQL.** The internal probe
  called `Database::tableExists('user_activity_log')`, an exact-name lookup.
  On MySQL the `authserver.` schema is emulated as a table prefix, so the
  physical table is `authserver_user_activity_log` and the probe never matched
  — every `record()` short-circuited and no row was written. On PostgreSQL the
  real `authserver` schema meant `table_name = 'user_activity_log'` matched, so
  the bug was invisible there (and in the PostgreSQL reference suite).

  The probe now goes through the schema builder with the fully-qualified name,
  `Database::schema()->hasTable('authserver.user_activity_log')` — the same
  call the creating migration uses — which resolves correctly on both the real
  PostgreSQL schema and MySQL's table-prefix emulation.

## Why it matters

The authserver dashboard / security pages read back this table. On MySQL
installs the login/logout/passkey audit trail was simply empty; it now
populates as designed. The change is confined to the table probe — the insert
path, the feature gate, the missing-table no-op and the swallow-on-failure
guarantees are all unchanged.
