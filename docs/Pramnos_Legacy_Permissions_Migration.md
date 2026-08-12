---
use_cases:
  - Moving a legacy permissions table into the current schema
  - Working out which permission store answers a check
  - Verifying a permissions migration
---

# Moving the legacy permissions table into the new schema

`Pramnos\Auth\Permissions` keeps its API. What changed is **where it reads and
writes**: `authserver.permissions`, the framework's one permission store.

This page is for installations that hand-built the legacy `<prefix>permissions`
table years ago and still have rows in it.

## Why move

No migration ever created `<prefix>permissions`, and nothing in the framework
calls `Permissions::setupDb()` — so unless an application built the table by
hand, it does not exist. That was survivable until you notice the class reported
a failed lookup as `false`, indistinguishable from a deny: code that trusted the
answer refused everything. (`isAllowed(..., false)` now returns `null` when there
is no store, which is what "cannot answer" should always have looked like.)

`authserver.permissions` is created by the **`auth`** feature's migrations — so
every installation with users has it, whether or not it runs an OAuth server. It
is read by `Pramnos\Auth\PermissionResolver`, written by `Permissions::allow()`
and `deny()`, and understands roles, priorities, deny-over-allow, expiry,
audience scoping and ABAC conditions, none of which the legacy table can express.

## Which store answers

The new store wins whenever it exists, which after running migrations is always.
The legacy table is read only where the new store is absent — an installation
whose migrations have not been run.

If **both** exist, the new store is used and a line is written to the
`permissions` log saying so. That is deliberate: rows left in the old table stop
counting from that moment, and finding out through a permission that
"stopped working" is worse than being told.

## What the old API can and cannot see in the new store

Reading the richer model through the older interface necessarily narrows it:

- `admin` maps to the `*` action, and `*` answers any privilege.
- A grant on one `object_id` answers only questions about that element; a grant
  covering all objects of a type answers only the unscoped question.
- **Grants carrying ABAC conditions are ignored.** The resolver hands conditions
  to the application to evaluate against its own request context, and this API
  has no way to receive one — treating a conditional grant as unconditional would
  hand out access the rule did not give. Use `PermissionResolver` directly where
  conditions matter.

## Do you have it?

```sql
-- PostgreSQL
SELECT to_regclass('public.permissions');           -- non-NULL means it exists

-- MySQL
SHOW TABLES LIKE '%permissions';
```

Substitute your table prefix. If the table is absent, nothing here applies —
you are already on the supported path.

## How the two models line up

| Legacy column | New column | Notes |
|---|---|---|
| `userid` | `subject_id` with `subject_type = 'user'` | The legacy table only ever addressed users |
| `subject` / `subjecttype` | `subject_type` | `'user'` in practice; `'group'` rows become roles — see below |
| `resource` | `object_type` | e.g. `invoice`, `device` |
| `resourceelement` | `object_id` | Empty string becomes `NULL` — "all objects of this type" |
| `privilege` | `action` | `admin` becomes the `*` wildcard |
| `value` (1/0) | `grant_type` (`allow`/`deny`) | The new model states the disposition instead of a flag |
| `resourcetype` | — | No equivalent; it only ever distinguished `module` from other kinds |
| — | `priority` | New: defaults to 100, and deny rules are stored above allow |
| — | `expires_at`, `is_active`, `granted_by` | New: no legacy equivalent |

Two things the legacy table cannot tell you, and which you should decide
deliberately rather than let a script guess:

- **Group rows** (`subjecttype = 'group'`) map onto **roles**
  (`authserver.roles` + `authserver.user_roles`), not onto a user grant. Move
  them by hand, after deciding which role each group becomes.
- **`resourcetype`** other than `module` meant something application-specific.
  Check what, before dropping it.

## The migration

Run it inside a transaction and read the result before committing. Both
statements are written to be re-runnable: the unique constraint on
`authserver.permissions` makes a repeat insert a no-op.

### PostgreSQL

```sql
BEGIN;

INSERT INTO authserver.permissions
    (subject_type, subject_id, object_type, object_id, action, grant_type,
     priority, granted_at, is_active, description)
SELECT
    'user',
    p.userid,
    p.resource,
    NULLIF(p.resourceelement, ''),
    CASE WHEN p.privilege = 'admin' THEN '*' ELSE p.privilege END,
    CASE WHEN p.value = 1 THEN 'allow' ELSE 'deny' END,
    -- Deny rules sit above allow, matching how the resolver breaks ties.
    CASE WHEN p.value = 1 THEN 100 ELSE 1100 END,
    NOW(),
    TRUE,
    'Migrated from the legacy permissions table'
FROM public.permissions AS p          -- add your prefix
WHERE p.subjecttype = 'user'
  AND p.userid IS NOT NULL
ON CONFLICT ON CONSTRAINT uq_authserver_perms_grant DO NOTHING;

-- Read this before committing: rows the script deliberately did not move.
SELECT subjecttype, resourcetype, COUNT(*)
FROM public.permissions
WHERE subjecttype <> 'user' OR userid IS NULL
GROUP BY subjecttype, resourcetype;

COMMIT;
```

### MySQL

```sql
START TRANSACTION;

INSERT IGNORE INTO authserver_permissions
    (subject_type, subject_id, object_type, object_id, action, grant_type,
     priority, granted_at, is_active, description)
SELECT
    'user',
    p.userid,
    p.resource,
    NULLIF(p.resourceelement, ''),
    CASE WHEN p.privilege = 'admin' THEN '*' ELSE p.privilege END,
    CASE WHEN p.value = 1 THEN 'allow' ELSE 'deny' END,
    CASE WHEN p.value = 1 THEN 100 ELSE 1100 END,
    NOW(),
    1,
    'Migrated from the legacy permissions table'
FROM permissions AS p                 -- add your prefix
WHERE p.subjecttype = 'user'
  AND p.userid IS NOT NULL;

SELECT subjecttype, resourcetype, COUNT(*)
FROM permissions
WHERE subjecttype <> 'user' OR userid IS NULL
GROUP BY subjecttype, resourcetype;

COMMIT;
```

MySQL installations may name the schema-qualified table `authserver_permissions`
or place it in a separate database; use whichever your migrations created.

## Verifying

Read back what the new store now says for a user whose rows you moved, before
changing any code that depends on them:

```php
$resolver = new \Pramnos\Auth\PermissionResolver($database);
print_r($resolver->resolve($userId, null)['permissions']);
```

Each entry is `object_type`, `object_id`, `action`, `grant` and `conditions`,
with deny-over-allow already applied — so what you read is the effective answer,
not the raw rows.

## Afterwards

Once the rows are across and you are satisfied, drop the old table — that also
stops the "both stores present" warning:

```sql
DROP TABLE permissions;   -- add your prefix
```

`Permissions::isAllowed()` keeps working either way. Reach for
`PermissionResolver::resolve()` directly when you need what the old API cannot
express — conditions, expiry, audience scoping, or the full grant list. Inside a
generated API controller you need neither: `ApiCrudController` already asks the
application's own permission scheme first, then the store.
