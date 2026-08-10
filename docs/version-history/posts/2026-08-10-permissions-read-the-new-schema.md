---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - permissions
  - auth
  - api
---

# `Permissions` answers from whichever store the installation has

A brand-new project's own administrator was told "You do not have permission to
see this". Nothing had been denied — the permission lookup had failed, and a
failed lookup was indistinguishable from a refusal.

<!-- more -->

## Fixed

`Pramnos\Auth\Permissions` read one table, `<prefix>permissions`. No migration
creates it and nothing calls `setupDb()`, so on a stock installation it does not
exist — every query failed, every failure was reported as `false`, and any caller
that trusted the answer refused everything.

It now selects its store: the legacy table when an installation has one, and
otherwise `authserver.permissions`, the schema the framework actually maintains
and that `PermissionResolver` reads. With neither, `isAllowed(..., false)`
returns `null` — "no opinion", which is what a missing store always meant.

The API is unchanged, and the legacy table stays authoritative wherever it
exists, so an installation that has one sees no difference. Its 26
characterization tests pass unmodified on MySQL and PostgreSQL.

Reading the richer model through the older interface narrows it, deliberately:
`admin` maps to the `*` action, object scoping is respected in both directions,
and **grants carrying ABAC conditions are ignored** — the resolver hands
conditions to the application to evaluate against its own request context, and
this API cannot receive one. Treating a conditional grant as unconditional would
hand out access the rule did not give. Use `PermissionResolver` where conditions
matter.

Generated API CRUD controllers also consult the **application's own** permission
scheme first: when the User class implements `hasPermission()` and declares the
name in `getAllPermissions()`, that answer wins, so a generated endpoint is never
looser than the hand-written ones beside it. A permission the application does
not declare is no opinion rather than a denial — otherwise a new entity would
return 403 until somebody added a column.

The SPA admin screen now separates 401 (sign in again), 403 (this account lacks a
permission) and an unreachable endpoint, which are three problems with three
different fixes.

## Documentation

[Legacy Permissions Migration](../../Pramnos_Legacy_Permissions_Migration.md) —
how the two models line up, and re-runnable SQL for moving rows out of a legacy
table into `authserver.permissions`, for any installation that still has one.
