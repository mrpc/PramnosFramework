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
hand, it does not exist. With no store at all, `isAllowed(..., false)` returns
`null` — "cannot answer" — and the default `isAllowed()` falls back to the
privilege's default, which is a deny unless one was set with
`setDefaultPermission()`.

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
- A grant on one `object_id` answers only questions about that element. A grant
  covering all objects of a type (`object_id` `NULL`) answers the unscoped
  question and the question about every element.
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
statements are re-runnable: each row is inserted only when an identical grant is
not already there. The unique constraint on `authserver.permissions` cannot do
that alone, because it includes the nullable `object_id` and a unique key treats
`NULL`s as distinct — every "all objects of this type" row would be inserted a
second time.

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
  AND NOT EXISTS (
      SELECT 1 FROM authserver.permissions AS n
      WHERE n.subject_type = 'user'
        AND n.subject_id   = p.userid
        AND n.object_type  = p.resource
        AND n.object_id IS NOT DISTINCT FROM NULLIF(p.resourceelement, '')
        AND n.action       = CASE WHEN p.privilege = 'admin' THEN '*' ELSE p.privilege END
        AND n.grant_type   = CASE WHEN p.value = 1 THEN 'allow' ELSE 'deny' END
  );

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

INSERT INTO authserver_permissions          -- add your prefix
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
  AND p.userid IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM authserver_permissions AS n   -- add your prefix
      WHERE n.subject_type = 'user'
        AND n.subject_id   = p.userid
        AND n.object_type  = p.resource
        AND n.object_id <=> NULLIF(p.resourceelement, '')
        AND n.action       = CASE WHEN p.privilege = 'admin' THEN '*' ELSE p.privilege END
        AND n.grant_type   = CASE WHEN p.value = 1 THEN 'allow' ELSE 'deny' END
  );

SELECT subjecttype, resourcetype, COUNT(*)
FROM permissions
WHERE subjecttype <> 'user' OR userid IS NULL
GROUP BY subjecttype, resourcetype;

COMMIT;
```

On MySQL the query builder resolves `authserver.permissions` to
`<prefix>authserver_permissions` in the application's database; use the name
your migrations created.

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
application's own permission scheme first, then the store. If your users hold roles per
organisation, set an organisation resolver as well; see
[Checking permissions in an organisation](Pramnos_Authorization_Guide.md#checking-permissions-in-an-organisation).
