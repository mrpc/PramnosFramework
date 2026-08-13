---
date: 2026-08-14
categories:
  - Changelog
  - Removed
  - Documentation
tags:
  - scaffolding
  - documentation
---

# Three dead stubs, and four corrections to older posts

Housekeeping from a review of the SPA scaffolding by a project building against it.
Small items, each of which cost somebody a look.

<!-- more -->

## Deleted: three orphaned stubs

`spa-index.html.stub`, `spa-index.php.stub` and `spa-app.js.stub` were referenced by
no code. A changelog post noted them as orphaned *before* the scaffolding landed, and
they stayed orphaned afterwards — so anybody following the old instructions would
copy a file the framework no longer knows about. Deleted rather than wired up: the
files they were superseded by (`spa-shell.php.stub`, `spa-svelte-main.js.stub`,
`spa-vanilla-main.js.stub`) are the ones `init` actually writes.

## Corrected: four claims that no longer matched the code

Each is annotated in the post it appeared in, rather than quietly edited — a
changelog that rewrites itself is not a changelog.

- **The admin screen's wrapper does not exist.**
  *2026-08-10 — the SPA admin screen* described a generated
  `src/Api/Controllers/Admin.php` making the endpoints overridable. No such file has
  ever been written: `scaffoldSpaAdmin()` writes only
  `frontend/screens/Admin.svelte`, and the routes instantiate
  `Pramnos\Auth\Controllers\ApiAdmin` directly. Overriding one means adding a route
  ahead of it.

- **The 403 message was quoted wrongly** in the same post. The stub says "This
  account does not have permission for this section."

- **`--spa-stack=svelte` is not a default.**
  *2026-08-10 — SPA scaffolding* presented it as one. The default applies only to an
  *invalid* value; with the flag absent, `init` asks. A non-interactive run must pass
  it explicitly — which is exactly the kind of thing a script discovers by hanging.

- **`create:crud` edits two of its outputs rather than writing them.**
  *2026-08-10 — create:crud for a SPA* listed `src/Api/routes.php` and
  `frontend/screens/registry.js` alongside the files it creates. Both are edited, and
  the routes edit is **skipped silently** when the file is missing or carries no
  version-group marker to insert into — so the routes have to be added by hand there.

## Why annotate rather than edit

A dated post is a record of what changed and when. Correcting one in place makes the
record disagree with itself for anybody who read it earlier; a dated correction inside
it says what was wrong and when that was found out, which is the more useful artefact
and the honest one.
