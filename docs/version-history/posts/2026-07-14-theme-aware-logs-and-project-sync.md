---
date: 2026-07-14
categories:
  - Changelog
  - Tooling
tags:
  - logs
  - console
  - scaffolding
  - themes
readtime: 5
---

# Theme-aware log viewer, dashboard landing & `project:` commands

The framework log viewer now renders correctly under every scaffold theme, `/Logs` opens on
an analytics dashboard, Chart.js is a mandatory bundled library, and a new family of
`project:` commands reconfigures an existing project without re-running `init`.

<!-- more -->

## Fixed

- **Log viewer is now theme-aware.** `LogController` no longer emits hard-coded Bootstrap
  markup. Every action gathers data and renders through per-theme view files under
  `scaffolding/themes/{tailwind,bootstrap,plain-css}/views/logs/`, so the log pages match
  the active UI theme instead of only looking right under Bootstrap.
- **Self-contained log-viewer chrome.** `Pramnos\Html\Logs\LogViewerView` (the file
  selector, pagination, search, export dropdown, and date-range modal around the log
  iframe) ships its own scoped CSS and vanilla JavaScript — no Bootstrap CSS, jQuery, or
  FontAwesome, and no CDN stylesheet. The `LogViewer` log-content iframe likewise drops its
  FontAwesome CDN link in favour of self-contained unicode glyphs, so nothing external is
  requested.
- **CSP: `Raw` documents nonce their inline scripts.** The log-viewer iframe is served as a
  `Raw` document, which previously did not receive the automatic CSP-nonce injection that
  `Html` documents get — so its inline scripts were blocked under a
  `script-src 'self' 'nonce-…'` policy (e.g. when switching log files). `Raw::render()` now
  injects the request nonce into inline `<script>`/`<style>` tags.
- **Analytics: structured JSON entries are parsed correctly.** `LogManager::getLogAnalytics()`
  did not trim the trailing newline before its JSON check, so every structured entry was
  misread as plain text — the level was lost (error rate read 0% even for all-error logs)
  and the timestamp fell back to *now* (wrong "last activity"). Now consistent with
  `getLogFileStats()`.

## Added

- **Dashboard is the `/Logs` landing.** `/Logs` now opens the analytics dashboard; the raw
  log viewer moved to `/Logs/viewer`. The toolbar links the full flow (Dashboard, Log
  Files, Statistics, Search, Filter, Export, Rotate, Archive, Clear) and appears on every
  log page.
- **Large-file guard for the dashboard.** `getLogAnalytics()` scans only the tail (~25 MB /
  500k lines) of very large logs and flags the result as truncated, so the dashboard never
  hangs on a multi-GB file. The dashboard shows a notice when analysis was truncated.
- **Chart.js is a mandatory library.** The dashboard renders charts from the locally-bundled
  Chart.js v4 asset (`assets/vendor/chartjs/…`, CSP-safe). Mandatory libraries are declared
  with `"mandatory": true` in `scaffolding/assets.json` — the single source of truth read by
  the new `Pramnos\Application\LibraryManager` and consumed by both `init` and the `project:`
  commands.
- **`project:reconfigure`** — enable framework features (recorded in `app/app.php`), install
  libraries, and report current configuration. Interactive on a TTY; scriptable via
  `--enable-feature=`, `--add-library=`, `--status`. Delegates library work to
  `project:install`.
- **`project:install [libraries…]`** — install front-end vendor libraries into an existing
  project (e.g. Chart.js after it became mandatory): downloads assets into
  `www/assets/vendor/` and registers them in `src/Application.php`. With no arguments it
  tops up the mandatory + already-registered set.
- **`project:publish-views`** — publish bundled view templates into `src/Views/` (renamed
  from `scaffold:views`).
- **`project:git-webhook`** — generate `www/webhook.php` (renamed from `create:webhook`).
- **`cache:clear`** — flush the application cache (all categories, or one with `--category=`).
- **More CLI commands.** `route:list` (list registered routes), `queue:failed` /
  `queue:retry` (inspect & re-queue failed tasks), `db:wipe` / `db:fresh` (drop / drop +
  migrate, `--force`-guarded), `user:create` (create a user/admin), `key:generate`
  (generate/rotate the app key in `.env`), `tinker` (interactive REPL — uses PsySH when
  installed; added to new apps' `require-dev`), and generators `create:command`,
  `create:task`, `create:provider`, `create:policy`, `create:test`.
- **Feature → libraries linkage.** `FeatureRegistry` feature definitions accept a `libraries`
  key; enabling a feature through `project:reconfigure` installs the libraries it declares.
- **Scheduled-task definitions.** `init` now scaffolds `app/schedule.php` as the official
  place to declare scheduled tasks (via the `Scheduler` API); `schedule:run` / `schedule:list`
  load it through the new `Scheduler::loadDefinitions()`. Tasks are code-defined (not stored
  in a database) and run by a once-a-minute `schedule:run` cron.
- **Scheduled-task logging.** `schedule:run` now records each task's outcome (ran + duration,
  skipped-overlap, or failed + error) to the `schedule` log channel, so there's a durable
  record even though cron discards the command's stdout. `ScheduledTask::run()` returns a bool
  (ran vs skipped) to drive it.

## Changed

- **Console command taxonomy tidied.** Project-reconfiguration commands are grouped under
  `project:`; `migratelogs` → `logs:convert`. Only genuine entry-point commands (`init`,
  `serve`) remain top-level.

## Documentation

- The [Console Commands Guide](../../Pramnos_Console_Guide.md) documents the full command
  taxonomy and the `project:` commands.
