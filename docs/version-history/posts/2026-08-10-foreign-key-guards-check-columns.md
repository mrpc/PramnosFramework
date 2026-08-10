---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - migrations
  - database
---

# The FK migration stops assuming the schema of tables it does not own

`AddMissingForeignKeysToExistingTables` guarded its optional foreign keys by
asking whether a table existed. It never asked whether the **columns** did — so
an application with its own `locations` table saw the migration fail on every
`migrate`, having done nothing wrong.

<!-- more -->

## The failure

```
✗ add_missing_foreign_keys_to_existing_tables
  ERROR: column "locationid" referenced in foreign key constraint does not exist
  ALTER TABLE "public"."users" ADD CONSTRAINT fk_users_locationid
    FOREIGN KEY ("locationid") REFERENCES "locations" ("locationid")
```

The block's own comment said it: *"The framework does not define locations — it
is an app-level concept."* The guard, however, was only
`hasTable('locations')`, which assumes that **any** table by that name is keyed
on `locationid`, and that `users` has a `locationid` column. An application that
keys its `locations` on `id` — entirely ordinary — hit an impossible
`ALTER TABLE`, and the migration then showed as failed forever after.

## The fix

`constraintDoesNotExist()` already refused to touch a missing *child* table; the
logic simply stopped one level short. A new `canAddForeignKey()` beside it now
verifies every side before the `ALTER`:

```php
if ($this->canAddForeignKey('users', 'locationid', 'locations', 'locationid', 'fk_users_locationid')) {
```

- the child table exists and the constraint is not already there (unchanged, via
  the existing per-driver helper),
- the child **column** exists,
- the referenced table exists,
- the referenced **column** exists.

All **eleven** blocks in the file now go through it, not just `locations` — this
is a category of bug, and `tokenactions.urlid → urls.urlid` carried exactly the
same latent risk with an equally generic table name. Schema-qualified references
(`public.applications`) are reduced to the bare name, which is what
`information_schema` matches on.

## Skips are reported

A silent skip is indistinguishable from success, so each one writes a single line
naming the constraint and what was missing:

```
Skipping foreign key fk_users_locationid: users has no column 'locationid'.
The referenced schema belongs to the application, not the framework.
```

## Compatibility

Nothing is required of applications: an installation with its own `locations`
simply steps over that one FK. Where both sides exist with the expected schema,
the constraint is created exactly as before. The migration stays idempotent and
safe to re-run — it is already recorded as `Ran` on existing installations, and
running it again changes nothing.

## Tests

`tests/Integration/Database/ForeignKeyGuardMigrationTest.php`, against the real
database — the bug lives in the SQL, not in any PHP branch:

- `locations` keyed on `id` with no `users.locationid` → the migration completes
  and the FK is **not** created. Verified to fail with the exact reported error
  when the old guard is put back.
- `locations` keyed on `locationid` with `users.locationid` → the FK is created,
  as before.
- no `locations` at all → unchanged.
- running it twice → safe.
