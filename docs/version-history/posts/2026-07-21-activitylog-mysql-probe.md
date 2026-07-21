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
  - routing
  - seo
  - http
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

---

## SEO-friendly 404 for unknown controllers

A request that resolved to a controller which does not exist returned a
plain-text `There is no controller to run...` body with an HTTP **200** status —
useless to visitors and misleading to search engines. The front controller now
answers with a proper not-found response.

### Changed

- **`Application::notFound()` (new, public) emits a real HTTP 404** — a minimal
  styled page with a `noindex` robots directive and a link home; any
  caller-supplied message is HTML-escaped.
- **`Api::notFound()` overrides it with a JSON 404 envelope**
  (`{"status":404,"error":"NotFound"}`) so API clients get a machine-readable
  not-found instead of the old string.
- The three `close('There is no controller to run...')` call sites (two in
  `Application::exec()`, one in `Api::exec()`) now delegate to `notFound()`.

### Why a 404 and not a redirect

A genuine 404 (not a 301 to the home page) is the correct SEO signal: blanket
redirecting unknown URLs to `/` reads as a soft-404 and hurts indexing. The
method is public so app controllers can trigger it for their own missing
resources.
