---
date: 2026-08-12
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - spa
  - debug
  - scaffolding
  - documentation
---

# The SPA debug panel now says it exists

The panel has shipped in every SPA project since 10 August, wired into
`lib/api.js` and fed by every response. It is also invisible until a request
carries debug data — which is a very good property for production and a very bad
one for being found.

<!-- more -->

## The failure

Read a scaffolded project's docs and you were told that "every response carries a
`_debug` key and the panel in the corner shows it". Nowhere was the panel's file
named. So the reasonable conclusion — reached by people and by coding assistants
alike — was that the framework provides the *data* for a SPA and that drawing it
is the application's job. The result was a second panel written next to a working
one, with the framework's version left dark because nobody knew to look at it.

Two supporting errors travelled with that conclusion, both worth correcting:

- **"The framework's toolbar would freeze on the shell."** It would not. Since
  10 August the toolbar has an `ajax` tab that wraps `fetch` and
  `XMLHttpRequest`; it keeps updating for as long as the page lives.
- **"So the framework only ships the payload."** The real reason the HTML
  toolbar cannot appear in a SPA is narrower and more fixable-sounding: the
  shell (`www/spa.php`) requires only the autoloader, never boots the
  framework, and so never passes through `DebugBarMiddleware`. There is nothing
  to inject into — not a rendering that would go stale.

## Fixed: the generated docs name the file

`CLAUDE.md` and `README.md` in every SPA project now list
`lib/debug.js` in the source tree as **framework-owned**, state plainly that no
second panel should be written, explain why the HTML toolbar is not an option
for the shell, and give the command to recover the file. Paths follow the
project's own stack — `frontend/lib/debug.js` for the Vite stacks,
`www/assets/js/lib/debug.js` for the build-less one — so the documented path is
one that exists.

## Added: `project:resync --debug-panel`

A project scaffolded before the panel existed had no way to obtain it. The
framework-owned front-end files are now a resync group:

```bash
./pramnos project:resync --debug-panel --all      # add the panel
./pramnos project:resync --debug-panel            # refresh an existing one
./pramnos project:resync --debug-panel --dry-run  # preview
```

The destination is read from `app_style`/`spa_stack` in `app/app.php` rather
than guessed, because a panel written to `frontend/` in a build-less project is
a file no page loads — indistinguishable, from the browser, from a panel that
does not work. An MVC project has no front-end sources, so the group yields
nothing there. With no scope flag the group syncs along with the others.

**`lib/api.js` is reported, not rewritten.** If the client never calls
`recordDebug`, the command says so and prints the two lines to add. That file is
the project's own and people edit it; regenerating it from the stub to fix one
import would discard those edits. A panel nothing feeds is silent in exactly the
way a missing panel is, which is the whole reason this entry exists — so the
silence is at least named.

## Also

`project:resync` loaded `app/app.php` with a bare `require` at its single call
site. A second caller would have received `true` rather than the configuration
array, since `require` of an already-included file returns a boolean. The
config is now loaded once per run and shared.

## Documentation

[Debugging](../../Pramnos_Debugging_Guide.md) gains an "In a single-page
application" section: why the shell cannot carry the HTML toolbar, where the
panel lives per stack, how it is wired, and the resync commands.

## Tests

`ProjectResyncTest` — the panel refreshed in place with the app name
substituted, created under `--all`, skipped without it, resolved to
`www/assets/js/` for the build-less stack, a no-op for an MVC project, scoped
away from the other groups, included in a default run, honoured under
`--dry-run`, and the wiring warning in all three states (unwired, wired, no
client at all). `InitSpaScaffoldingTest` — the generated `CLAUDE.md` and
`README.md` name the panel, mark it framework-owned, forbid a rewrite, carry the
recovery command, and use each stack's real path.
