---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - console
  - scaffolding
  - spa
---

# `scaffold:spa` — a front end for an application that already exists

Named the highest-value addition in a consumer's review of the scaffolding, and the
gap it closes is real: the SPA was reachable only through a full `init`, which refuses
to run where an application already is, and `project:resync` only refreshes files a
project already has. So the documented path for "I have an application and want a
Svelte front end" was to copy fifteen stubs by hand and do the token substitution
yourself. Somebody did exactly that.

<!-- more -->

```bash
php pramnos scaffold:spa --spa-stack=svelte     # at the site root
php pramnos scaffold:spa --app-style=hybrid     # mounted under /app
php pramnos scaffold:spa --dry-run              # report, write nothing
```

## It cannot damage the project

That is the property that makes it usable at all. Every scaffolded file passes through
one funnel in `Init`, and this command sets `skipExisting` — so a file the project
already has is left **byte-for-byte** and reported as `kept (yours)`. Enforced in the
funnel rather than at each call site, so a stub added later cannot forget it.

Consequences worth stating:

- **running it twice does nothing the second time**, which is what makes it safe to
  run when you are not sure whether you already did;
- your own `www/spa.php`, your own `lib/api.js`, your own `App.svelte` survive
  untouched — you can use it to fill in the pieces you are missing;
- `--force` overwrites, for when that is genuinely what you want.

## It writes what `init` writes

The same stubs, the same tokens, the same method. `scaffoldSpa()` became public; there
is no second implementation to drift from the first, which is the failure mode a
"scaffold this one thing" command usually has.

## It records the style

`app_style` and `spa_stack` go into `app/app.php`, because `spa:dev`, `spa:build` and
`project:resync` all read them. Without that the front end exists and every command
that should help with it reports that the project has none — a state a project that
adopted the layout by hand was already in. A project that already declares a style
keeps it, so re-running to add a missing file cannot silently turn a hybrid mounting
into a root-mounted SPA.

## And `project:resync` says where it looked

Two changes for a project whose sources are not where the framework assumes:

- **`spa_source_dir` in `app/app.php`** is honoured, so the front end can live in
  `admin-ui/` without a repo-wide rename — which is what a reviewer had to do to
  receive one file;
- when nothing is found, or when everything was skipped, it now prints **which
  directory it looked in**, whether that came from configuration or was derived, and
  how to change it. The message used to be one sentence for both "this project has no
  SPA" and "your sources are elsewhere", and the second reading is the one that sends
  somebody hunting in the wrong place.

## Documentation

- [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md) — "Adding a
  SPA to an application that already exists".
