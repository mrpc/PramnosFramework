---
date: 2026-08-10
categories:
  - Changelog
  - Features
  - Fixed
tags:
  - scaffolding
  - spa
  - api
  - console
---

# `create:crud` builds the SPA half too

Running a migration and getting a working feature was an MVC-only privilege: the
generator produced a model, a controller and views, while a SPA project got
nothing it could use. `create:crud` now reads how the application is built and
generates the matching halves — including the API controller, its routes and a
front-end screen that shows up in the navigation by itself.

<!-- more -->

## What it generates

`init` records the application style in `app.php` (`app_style`, `spa_stack`), and
the generator follows it. `--target=mvc|spa|both` overrides one run.

| Style | `create:crud thing` produces |
|---|---|
| `mvc` | model + controller + server-rendered views (unchanged) |
| `spa` | model + API controller + routes + front-end screen |
| `hybrid` | both, over **one** model: a single domain object, two controllers |

```
src/Models/Thing.php               the model, with getApiList()
src/Api/Controllers/Thing.php      list / read / create / update / delete
src/Api/routes.php                 the routes, inside the version group
frontend/screens/Thing.svelte      table + paging + search + form + delete
frontend/screens/registry.js       the entry that puts it in the navigation
```

The screen uses the model's `getApiList()` pipeline, so paging and search happen
on the server — it never loads a table into the browser to filter it there. The
columns come from the table itself, so they match the migration that created it.

## Three defects this uncovered

Generating the API half and then actually calling it exposed problems that had
been there all along:

**Every generated endpoint was unreachable.** `create:api` appended its routes
*after* the version group, registering them at `/thing` while requests arrive as
`/1.0/thing`. Nothing matched, the API fell through to legacy controller
resolution, and the caller got `Cannot find controller: 1.0`. Routes now go
inside the group.

**The routes called the wrong resolver.** They used
`$this->getController('Thing')`, which resolves against `src/Controllers` — the
MVC side — and cannot see `src/Api/Controllers`. They now instantiate the API
controller directly, the way the feature-scaffolded routes always did.

**Re-running the generator crashed.** With the model already present,
`createModel()` called `updateModel()` — a method that exists nowhere in the
framework — so `create:model` or `create:crud` on an existing entity died with a
fatal error. That is exactly what one does after adding a column. An existing
model is now left untouched (regenerating it would discard hand-written methods)
and the command says so.

## Screens are wired up, not just written

A generated component nobody imports is not even bundled. `create:crud` appends
its entry to `frontend/screens/registry.js`, which the application reads to build
its navigation — so a new CRUD appears by itself, the way a generated MVC
controller does. Both steps are idempotent: re-running leaves the screen and the
registry byte-identical.

## The target rides on a property

`createCrud()` is public and applications (and the framework's own tests) override
it. Giving it a `$target` parameter therefore broke them at load time — PHP
rejects an override that lacks the new parameter, and a broken public signature is
not an additive change. The target is set on `$crudTarget` before the call
instead, so every existing override keeps working. A test now pins that
signature.

## Tests

`MakeCrudSpaTest` — the target follows `app_style` (including an `app.php` that
predates the key), the screen is generated with server-side paging and
registered, both steps are idempotent, nothing is generated without a front-end
stack, routes land inside the version group and instantiate the API controller
directly, and registering twice changes nothing.

Verified end to end against a running project: `create:crud thing --table=things`
→ model + API controller + routes + screen, the endpoint answering through the
version prefix, and `vite build` including the new screen.
