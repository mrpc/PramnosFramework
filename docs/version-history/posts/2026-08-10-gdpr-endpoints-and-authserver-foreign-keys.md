---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - gdpr
  - migrations
  - database
  - querybuilder
---

# The GDPR endpoints queried a table that does not exist

Every one of them — create a request, check its status, list requests — read
`oauth2_gdpr_requests`. No migration has ever created that table. The endpoints
failed at runtime, on a feature with legal weight.

<!-- more -->

## Fixed

**The GDPR controller now reads the table the framework actually creates.**
`authserver.gdpr_requests`, with the columns it actually has: `id` not
`request_id`, `userid` not `user_id`, `requested_at` not `created_at`. The
controller had been backported from an OAuth server whose schema — with
`apps_notified`, `apps_confirmed`, `data_export_url`, `expires_at` — was never
adopted here, and was never adapted.

The response keys are unchanged, read through SQL aliases, so what the endpoints
documented is what they still return. Nothing could have depended on them
regardless: they returned an error every time they were called.

Two smaller mismatches came with it. The table records the data subject and has
no column for a second person, so when an admin files a request on somebody's
behalf that now goes into the audit trail (`request_details`) instead of a
column that does not exist. And the request-type vocabulary is reconciled: the
column documents `access`, `erasure`, `portability`, `rectification`,
`restriction`, while the endpoint accepted `export`, `delete`, `portability`.
Both spellings are accepted; the GDPR vocabulary is what gets stored.

Every query in the controller now goes through the query builder. That is what
made the underlying problem invisible for so long: `Database::query()` translates
neither `authserver.gdpr_requests` (a schema on PostgreSQL, a prefixed table on
MySQL) nor anything else, so a hand-written table name was never checked by
anything.

**Five foreign keys were never created on any installation.**
`2020_01_01_000050` adds them with `schema('public')->table('gdpr_requests')`,
but these tables live in `authserver`: `user_privacy_settings`, `user_consents`,
`data_processing_records`, `gdpr_requests` and `user_activity_log`. The lookup
found no such table in `public`, the guard skipped the block, and the skip was
indistinguishable from success. The migration now addresses them by their real
names, and its constraint check splits the schema before asking
`information_schema` — `table_name = 'authserver.gdpr_requests'` matched nothing,
so an existing constraint read as missing.

**`PermissionResolver` no longer fails outright when the role-assignment table
is absent.** It queried `authserver.user_roles` unconditionally; on an
installation without it the driver error escaped and took the whole resolution
down. Callers that turn "cannot answer" into "denied" then refused every direct
user grant as well — the opposite of what the rows said. A missing role table now
means what it should: this installation grants nothing through roles.

## Added

**`2026_08_10_000001` repairs installations that already have the defects.**
Correcting the migrations above only helps fresh installs — the originals are
recorded as applied everywhere else and will never run again, which is exactly
the gap being closed. The repair renames `gdpr_requests.notes` to
`processing_notes` (the name the production schema this table was modelled on
uses) and adds the five missing foreign keys.

Every step is guarded: a correct database comes out unchanged, an installation
missing only part gets only that part, and running it twice does nothing.

It refuses to add a foreign key while orphaned rows exist, and says how many.
These tables went years without the constraint, so nothing stopped a request
from outliving its user; adding the key on top of one fails, and a failing
`ALTER` aborts the whole batch, taking unrelated migrations with it. A skip is
recoverable — clean up the rows, run migrations again, the key appears.

Verified by integration tests that build a broken installation and assert both
halves of the question: that the runner **selects** the repair when the baseline
is already recorded as applied, that it survives `migration_cutoff =
2020_01_02_000000`, and that the database is correct afterwards — including that
the rename keeps every row.
