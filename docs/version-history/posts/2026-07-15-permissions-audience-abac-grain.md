---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - permissions
  - rbac
  - abac
  - migrations
readtime: 1
---

# Permissions grain: audience and ABAC conditions

The `authserver.permissions` table gains two dimensions that turn plain RBAC into
per-application, attribute-aware authorization (feature 4, Hybrid RBAC + ABAC).

<!-- more -->

## Added

- **`app_id` (audience).** A nullable column recording which application a
  permission applies *within*. `NULL` means global (every app) — exactly the
  grain the table had before — so all existing rows keep their meaning.
- **`conditions` (ABAC).** A nullable JSON column holding an attribute predicate
  such as `{"location_id":[1,2]}`, evaluated at runtime by the consuming app.
  `NULL` means unconditional.
- A new non-unique lookup index
  `(subject_type, subject_id, app_id, object_type, action)`.

Added by the current-date migration
`2026_07_15_000003_add_audience_and_conditions_to_permissions` — strictly additive
and idempotent (`hasColumn` guards), **no foreign key** on `app_id`, and the
existing unique constraint and `effective_permissions` view are left untouched.
The runtime resolver that reads these dimensions (per-app permission fetch with
condition pass-through) lands with the internal permissions endpoint in the next
phase.
