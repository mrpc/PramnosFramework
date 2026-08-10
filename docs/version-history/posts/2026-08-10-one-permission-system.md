---
date: 2026-08-10
categories:
  - Changelog
  - Changed
tags:
  - permissions
  - auth
  - migrations
---

# One permission system, available to every project with users

Permissions used to arrive with the OAuth server. An application that only had
users — no OAuth, no clients, no tokens — got no permission tables at all, so
there was nowhere to record who may do what.

<!-- more -->

## Changed

The RBAC tables moved from the `authserver` feature to **`auth`**:
`authserver.schema`, `authserver.roles`, `authserver.permissions`,
`authserver.user_roles`, and the audience/conditions columns on permissions.
Anything that is genuinely about running an authorisation server — permission
and role templates, inheritance, the audit log, the effective-permissions view,
the RBAC functions, client capabilities — stayed where it was.

Nothing was renamed. The schema is still called `authserver`, the filenames and
migration slugs are unchanged, and every `up()` guards on the table already
existing, so an installation that has run these migrations sees no change and
re-runs nothing. A project that enables `authserver` without `auth` still
resolves the dependency, because the migration runner pulls a declared
dependency from the full framework pool regardless of which features are on.

`Pramnos\Auth\Permissions` now treats `authserver.permissions` as **the** store,
not as a fallback: it is what every installation with users has, and what the
rest of the framework reads and writes. The legacy `<prefix>permissions` table
is used only where the new store is absent. Where both exist — an installation
that hand-built the old table years ago — the new one is used and the fact is
logged, because rows left behind stop counting from that moment and finding out
through a permission that "stopped working" is worse than being told.

## Fixed

**`allow()`, `deny()` and `removePermission()` wrote to the store that is
actually there.** The read path had moved; the write path had not, so a grant
could be neither made nor revoked on any installation that had not hand-built
the legacy table — and the class would then report "no such permission" about a
grant it had just refused to store. Both sides now use the same store, with the
mapping the migration guide documents: `admin` becomes the `*` action, an empty
element becomes a NULL `object_id`, a group becomes a role, and a deny is stored
above allow so it wins a tie. A subject type the model cannot express is refused
and logged rather than written under a wrong `subject_type`.

**`removePermission()` did not clear the instance cache.** `setPermission()`
did. Within a single request, a revoked permission kept answering "allowed" from
memory: the query cache was flushed, but nothing ever asked the database again.

**`Auth::useraccess()`, `groupaccess()` and `setaccess()` reached the permission
system at all.** All three called `pramnos_factory::getPermissions()` — a class
that exists nowhere in the framework or its dependencies. Every call raised
`Class "pramnos_factory" not found` before consulting a single permission, so
the framework's own documented way of asking about access was unreachable
regardless of database or table. (`Factory::getPermissions()` does exist; only
the legacy `pramnos_factory` alias does not.)

**Store detection asked the schema builder instead of running a SELECT.** A
failed SELECT does not reliably raise — it can simply return false — so a
missing table could be mistaken for a present one. The builder also resolves
`authserver.permissions` to whatever the driver actually calls it: a schema on
PostgreSQL, a prefixed table on MySQL.

## Documentation

The [Authentication Guide](../../Pramnos_Authentication_Guide.md) permissions
section was rewritten. It documented `Permissions::allow()` and a
`Permissions::check()` as **static** methods — `check()` does not exist, and the
real methods are instance methods reached through `getInstance()`. It also
printed a `CREATE TABLE permissions` statement for a table no migration has ever
created, which is one way installations ended up with a hand-built legacy table
in the first place. It now describes one store, the two APIs that read it, and
when to use which.

[Legacy Permissions Migration](../../Pramnos_Legacy_Permissions_Migration.md)
was updated for the new precedence.
