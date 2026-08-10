# Moving the legacy permissions table into the new schema

`Pramnos\Auth\Permissions` keeps its API. What changed is **where it reads
from**: the legacy `<prefix>permissions` table when an installation has one, and
otherwise `authserver.permissions`, the system the framework actually maintains.

This page is for installations that still have the legacy table and want their
rows in one place. Nothing here is required — an installation that keeps the old
table keeps working exactly as before.

## Why move

No migration ever created `<prefix>permissions`, and nothing in the framework
calls `Permissions::setupDb()` — so unless an application built the table by
hand, it does not exist. That was survivable until you notice the class reported
a failed lookup as `false`, indistinguishable from a deny: code that trusted the
answer refused everything. (`isAllowed(..., false)` now returns `null` when there
is no store, which is what "cannot answer" should always have looked like.)

`authserver.permissions` is created by the authserver migrations, read by
`Pramnos\Auth\PermissionResolver`, and understands roles, priorities,
deny-over-allow, expiry, audience scoping and ABAC conditions — none of which the
legacy table can express. Moving the rows there means one store, and the old API
still answers from it.

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

Compare what the two systems say for a user who has rules, before changing any
code that depends on them:

```php
$resolver = new \Pramnos\Auth\PermissionResolver($database);
print_r($resolver->resolve($userId, null)['permissions']);
```

Each entry is `object_type`, `object_id`, `action`, `grant` and `conditions`,
with deny-over-allow already applied — so what you read is the effective answer,
not the raw rows.

## Afterwards

The legacy table stays authoritative while it exists — that is deliberate, so
that moving is a decision rather than something that happens to you. Once the
rows are across and you are satisfied, drop it, and the same calls start
answering from the new store:

```sql
DROP TABLE permissions;   -- add your prefix
```

`Permissions::isAllowed()` keeps working either way. Reach for
`PermissionResolver::resolve()` directly when you need what the old API cannot
express — conditions, expiry, audience scoping, or the full grant list. Inside a
generated API controller you need neither: `ApiCrudController` already asks the
application's own scheme, then the resolver, then the legacy table.
